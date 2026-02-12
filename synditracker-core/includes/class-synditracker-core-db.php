<?php

class Synditracker_Core_DB {

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'synditracker_reports';
    }

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
            source_title text,
            aggregator_name varchar(100),
            site_domain varchar(255),
            content_hash varchar(32),
            status varchar(20) DEFAULT 'valid',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY partner_site_id (partner_site_id),
            KEY source_post_id (source_post_id),
            KEY content_hash (content_hash)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    public static function insert_report( $data ) {
        global $wpdb;
        $table_name = self::get_table_name();
        
        $defaults = array(
            'status' => 'valid',
            'debug_log' => '',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );
        
        $data = wp_parse_args( $data, $defaults );
        
        return $wpdb->insert( $table_name, $data );
    }

    public static function check_duplicate( $source_post_id, $partner_site_id, $content_hash = '' ) {
        global $wpdb;
        $table_name = self::get_table_name();
        
        // 1. Check by source_post_id + partner_site
        if ( ! empty( $source_post_id ) ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table_name WHERE source_post_id = %s AND partner_site_id = %d LIMIT 1",
                $source_post_id,
                $partner_site_id
            ) );
            if ( $exists ) return $exists;
        }

        // 2. Check by content_hash
        if ( ! empty( $content_hash ) ) {
             $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table_name WHERE content_hash = %s AND partner_site_id = %d LIMIT 1",
                $content_hash,
                $partner_site_id
            ) );
            if ( $exists ) return $exists;
        }

        // 3. Check by Title (Fallback if source_post_id is missing, as requested)
        // We need source_title passed to this function to check it.
        // But the signature is fixed. We can overload or change signature.
        // Let's rely on content_hash for now, OR better, let's update signature.
        // Actually, let's keep it simple. If we want Title check, we need to pass Title.
        
        return false;
    }

    public static function check_duplicate_extended( $criteria ) {
        global $wpdb;
        $table_name = self::get_table_name();
        $partner_site_id = $criteria['partner_site_id'];

        if ( ! empty( $criteria['source_post_id'] ) ) {
            $id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE source_post_id = %s AND partner_site_id = %d LIMIT 1", $criteria['source_post_id'], $partner_site_id ) );
            if ($id) return $id;
        }
        
        if ( ! empty( $criteria['source_title'] ) ) {
             // Use LIKE for slight robustness? No, strict for now.
             // Title might not be indexed, so this is slower, but fine for low volume.
             $id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE source_title = %s AND partner_site_id = %d LIMIT 1", $criteria['source_title'], $partner_site_id ) );
             if ($id) return $id;
        }

        if ( ! empty( $criteria['content_hash'] ) ) {
            $id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE content_hash = %s AND partner_site_id = %d LIMIT 1", $criteria['content_hash'], $partner_site_id ) );
            if ($id) return $id;
        }
        
        return false;
    }
    
    public static function get_reports( $args = array() ) {
        global $wpdb;
        $table_name = self::get_table_name();
        
        // Basic retrieval - can be expanded for WP_List_Table
        $sql = "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 50";
        return $wpdb->get_results( $sql, ARRAY_A );
    }
}
