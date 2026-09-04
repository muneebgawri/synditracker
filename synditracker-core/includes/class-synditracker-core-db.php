<?php

/**
 * Database layer for Synditracker Core.
 *
 * Reports are stored one row per distinct piece of syndicated content per
 * partner site. Re-observations of content already recorded bump `seen_count`
 * and `updated_at` on the existing row rather than inserting a new one.
 * Previously every re-observation inserted a fresh row flagged 'duplicate' and
 * fired a Discord alert, which produced storms — one article generated 112
 * alerts in a single afternoon.
 */
class Synditracker_Core_DB {

    /**
     * Schema version. Bump when the DDL below changes and add the matching
     * step to maybe_upgrade().
     */
    const DB_VERSION = '1.1.0';

    const VERSION_OPTION = 'synditracker_db_version';

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'synditracker_reports';
    }

    public static function get_alerts_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'synditracker_alerts';
    }

    /**
     * Fresh-install DDL for the reports table.
     *
     * Mirrors the live production schema so that a new install and a migrated
     * one converge. debug_log and source_title were live in production long
     * before they appeared here — an install from the older copy of this file
     * produced a table that insert_report() could not write to.
     */
    public static function create_table() {
        global $wpdb;
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            partner_site_id bigint(20) NOT NULL,
            local_post_id bigint(20) NOT NULL,
            source_post_id varchar(255) DEFAULT '',
            source_url text,
            source_guid text,
            aggregator_name varchar(100),
            site_domain varchar(255),
            content_hash varchar(32),
            status varchar(20) DEFAULT 'valid',
            seen_count int(10) unsigned NOT NULL DEFAULT 1,
            debug_log longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            source_title text,
            PRIMARY KEY  (id),
            KEY partner_site_id (partner_site_id),
            KEY source_post_id (source_post_id),
            KEY content_hash (content_hash),
            KEY updated_at (updated_at),
            KEY partner_seen (partner_site_id,seen_count)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    /**
     * Alerts table. Doubles as the throttle ledger: an alert only sends when no
     * row of the same type exists inside the current window.
     */
    public static function create_alerts_table() {
        global $wpdb;
        $table_name = self::get_alerts_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            alert_type varchar(50) NOT NULL,
            message text NOT NULL,
            duplicate_count int(10) unsigned DEFAULT 0,
            threshold int(10) unsigned DEFAULT 0,
            window_hours int(10) unsigned DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY alert_type (alert_type),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    /**
     * Idempotent schema upgrade. Safe to call on every request; no-ops once the
     * stored version matches.
     *
     * Deliberately does NOT run dbDelta against an existing reports table.
     * Production's schema was created by a version of this plugin that no
     * longer exists (it sat at db_version 1.0.7 with columns this code never
     * created), so dbDelta would try to "correct" columns it did not author.
     * Only additive, existence-checked changes are applied to live tables.
     */
    public static function maybe_upgrade() {
        if ( get_option( self::VERSION_OPTION ) === self::DB_VERSION ) {
            return;
        }

        global $wpdb;
        $table_name = self::get_table_name();

        if ( ! self::table_exists( $table_name ) ) {
            self::create_table();
        } else {
            $columns = $wpdb->get_col( "SHOW COLUMNS FROM `$table_name`" );

            if ( is_array( $columns ) ) {
                if ( ! in_array( 'seen_count', $columns, true ) ) {
                    $wpdb->query( "ALTER TABLE `$table_name` ADD COLUMN seen_count int(10) unsigned NOT NULL DEFAULT 1" );
                }
                if ( ! in_array( 'debug_log', $columns, true ) ) {
                    $wpdb->query( "ALTER TABLE `$table_name` ADD COLUMN debug_log longtext" );
                }
                if ( ! in_array( 'source_title', $columns, true ) ) {
                    $wpdb->query( "ALTER TABLE `$table_name` ADD COLUMN source_title text" );
                }
            }

            $indexes = $wpdb->get_col( "SHOW INDEX FROM `$table_name`", 2 );

            if ( is_array( $indexes ) ) {
                if ( ! in_array( 'updated_at', $indexes, true ) ) {
                    $wpdb->query( "ALTER TABLE `$table_name` ADD INDEX updated_at (updated_at)" );
                }
                if ( ! in_array( 'partner_seen', $indexes, true ) ) {
                    $wpdb->query( "ALTER TABLE `$table_name` ADD INDEX partner_seen (partner_site_id, seen_count)" );
                }
            }
        }

        if ( ! self::table_exists( self::get_alerts_table_name() ) ) {
            self::create_alerts_table();
        }

        update_option( self::VERSION_OPTION, self::DB_VERSION, false );
    }

    /**
     * @param string $table Fully-prefixed table name.
     * @return bool
     */
    public static function table_exists( $table ) {
        global $wpdb;

        return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    /**
     * Record an observation of syndicated content.
     *
     * If this content has been seen from this partner before, the existing row
     * is updated rather than inserting a duplicate. This is the fix for the
     * alert storms: repeats no longer create rows, and no longer alert on their
     * own — Synditracker_Alerts decides that from the rate.
     *
     * @param array $data Report fields.
     * @return array|false {
     *     @type int  $id         Row ID.
     *     @type bool $is_repeat  True when an existing row was updated.
     *     @type int  $seen_count Times this content has now been observed.
     * }
     */
    public static function record_observation( $data ) {
        global $wpdb;
        $table_name = self::get_table_name();
        $now = current_time( 'mysql' );

        $existing = self::find_existing( array(
            'partner_site_id' => isset( $data['partner_site_id'] ) ? $data['partner_site_id'] : 0,
            'source_post_id'  => isset( $data['source_post_id'] ) ? $data['source_post_id'] : '',
            'content_hash'    => isset( $data['content_hash'] ) ? $data['content_hash'] : '',
            'source_guid'     => isset( $data['source_guid'] ) ? $data['source_guid'] : '',
            'source_title'    => isset( $data['source_title'] ) ? $data['source_title'] : '',
        ) );

        if ( $existing ) {
            $updated = $wpdb->query( $wpdb->prepare(
                "UPDATE $table_name SET seen_count = seen_count + 1, updated_at = %s WHERE id = %d",
                $now,
                $existing->id
            ) );

            if ( false === $updated ) {
                return false;
            }

            return array(
                'id'         => (int) $existing->id,
                'is_repeat'  => true,
                'seen_count' => (int) $existing->seen_count + 1,
            );
        }

        $row = wp_parse_args( $data, array(
            'status'     => 'valid',
            'debug_log'  => '',
            'seen_count' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ) );

        if ( ! $wpdb->insert( $table_name, $row ) ) {
            return false;
        }

        return array(
            'id'         => (int) $wpdb->insert_id,
            'is_repeat'  => false,
            'seen_count' => 1,
        );
    }

    /**
     * Insert a report row.
     *
     * @return int|false Insert ID on success, false on failure. This previously
     *                   returned $wpdb->insert()'s affected-row count, so the
     *                   REST API reported report_id = 1 for every report.
     */
    public static function insert_report( $data ) {
        global $wpdb;
        $now = current_time( 'mysql' );

        $data = wp_parse_args( $data, array(
            'status'     => 'valid',
            'debug_log'  => '',
            'seen_count' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ) );

        if ( ! $wpdb->insert( self::get_table_name(), $data ) ) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Find an already-recorded row matching this content for this partner.
     *
     * Matchers run strongest-first. Title is deliberately last: it is the
     * weakest signal, and checking it before the content hash meant unrelated
     * posts sharing a headline were treated as the same content.
     *
     * @return object|null Row with id and seen_count, or null.
     */
    public static function find_existing( $criteria ) {
        global $wpdb;
        $table_name = self::get_table_name();
        $partner_site_id = isset( $criteria['partner_site_id'] ) ? (int) $criteria['partner_site_id'] : 0;

        $allowed = array( 'source_post_id', 'content_hash', 'source_guid', 'source_title' );

        /**
         * Filter which fields identify "the same content", strongest first.
         *
         * @param array $matchers Column names.
         */
        $matchers = apply_filters( 'synditracker_duplicate_matchers', $allowed );

        foreach ( $matchers as $field ) {
            // Whitelist guard: $field is interpolated into SQL.
            if ( ! in_array( $field, $allowed, true ) || empty( $criteria[ $field ] ) ) {
                continue;
            }

            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, seen_count FROM $table_name WHERE $field = %s AND partner_site_id = %d ORDER BY id ASC LIMIT 1",
                $criteria[ $field ],
                $partner_site_id
            ) );

            if ( $row ) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Count distinct content items re-observed for a partner inside a window.
     * This is the spike signal.
     *
     * The window arithmetic is done in SQL against the same clock used to write
     * updated_at, so no PHP timezone conversion can skew it.
     *
     * @param int $partner_site_id Partner site post ID.
     * @param int $window_hours    Lookback window.
     * @return int
     */
    public static function count_repeats_in_window( $partner_site_id, $window_hours ) {
        global $wpdb;
        $table_name = self::get_table_name();

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name
             WHERE partner_site_id = %d
               AND seen_count > 1
               AND updated_at >= DATE_SUB(%s, INTERVAL %d HOUR)",
            $partner_site_id,
            current_time( 'mysql' ),
            absint( $window_hours )
        ) );
    }

    public static function get_reports( $args = array() ) {
        global $wpdb;
        $table_name = self::get_table_name();

        $limit = isset( $args['limit'] ) ? absint( $args['limit'] ) : 50;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY updated_at DESC LIMIT %d",
            $limit
        ), ARRAY_A );
    }
}
