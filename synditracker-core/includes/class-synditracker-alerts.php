<?php

/**
 * Alert policy for Synditracker Core.
 *
 * Previously the REST controller fired a Discord message for every duplicate
 * observation, unthrottled. Because partner sites routinely re-import the same
 * article many times, that produced storms — one article generated 112 alerts
 * in a single afternoon, and a third of all alerts were repeats of content
 * already alerted on.
 *
 * The `synditracker_spike_threshold` option and the `wp_synditracker_alerts`
 * table were designed to gate exactly this but were never implemented. This
 * class implements them: repeats are counted per partner over a rolling window,
 * an alert fires only when the count reaches the threshold, and the alerts
 * table acts as the throttle ledger so at most one alert is sent per window
 * even when the threshold is 1.
 */
class Synditracker_Alerts {

    const DEFAULT_THRESHOLD    = 5;
    const DEFAULT_WINDOW_HOURS = 1;

    /**
     * Number of repeated items required within the window before alerting.
     */
    public static function get_threshold() {
        $threshold = (int) get_option( 'synditracker_spike_threshold', self::DEFAULT_THRESHOLD );

        if ( $threshold < 1 ) {
            $threshold = self::DEFAULT_THRESHOLD;
        }

        return (int) apply_filters( 'synditracker_spike_threshold', $threshold );
    }

    /**
     * Rolling window, in hours.
     */
    public static function get_window_hours() {
        $settings = get_option( 'synditracker_alert_settings', array() );
        $window   = isset( $settings['scanning_window'] ) ? (int) $settings['scanning_window'] : self::DEFAULT_WINDOW_HOURS;

        if ( $window < 1 ) {
            $window = self::DEFAULT_WINDOW_HOURS;
        }

        return (int) apply_filters( 'synditracker_alert_window_hours', $window );
    }

    /**
     * Whether Discord delivery is switched on.
     */
    public static function discord_enabled() {
        $settings = get_option( 'synditracker_alert_settings', array() );

        // Absent setting means "not configured off" — default to enabled to
        // preserve existing behaviour on sites that never saved the settings.
        $enabled = ! isset( $settings['discord_enabled'] ) || (bool) $settings['discord_enabled'];

        return (bool) apply_filters( 'synditracker_discord_enabled', $enabled );
    }

    /**
     * Evaluate whether a repeat observation warrants an alert, and send one if so.
     *
     * Called once per repeat observation. Cheap in the common case: a single
     * indexed COUNT plus, at most, one throttle lookup.
     *
     * @param int   $partner_site_id Partner site post ID.
     * @param array $context         Optional context for the message.
     * @return bool True when an alert was sent.
     */
    public static function maybe_alert( $partner_site_id, $context = array() ) {
        $partner_site_id = (int) $partner_site_id;
        $threshold       = self::get_threshold();
        $window_hours    = self::get_window_hours();

        require_once plugin_dir_path( __FILE__ ) . 'class-synditracker-core-db.php';

        $count = Synditracker_Core_DB::count_repeats_in_window( $partner_site_id, $window_hours );

        if ( $count < $threshold ) {
            return false;
        }

        $alert_type = 'duplicate_spike_' . $partner_site_id;

        // Throttle ledger: one alert per type per window, regardless of how many
        // repeats arrive. This is what stops the storms even at threshold = 1.
        if ( self::alerted_recently( $alert_type, $window_hours ) ) {
            return false;
        }

        $partner_name = get_the_title( $partner_site_id );
        if ( ! $partner_name ) {
            $partner_name = 'Unknown partner';
        }

        $message = sprintf(
            '%d repeated items detected for %s in the last %d hour(s) (threshold %d).',
            $count,
            $partner_name,
            $window_hours,
            $threshold
        );

        self::record( $alert_type, $message, $count, $threshold, $window_hours );

        if ( ! self::discord_enabled() ) {
            return false;
        }

        require_once plugin_dir_path( __FILE__ ) . 'class-synditracker-discord.php';

        $fields = array(
            array( 'name' => 'Partner Site',    'value' => $partner_name . ' (#' . $partner_site_id . ')', 'inline' => true ),
            array( 'name' => 'Repeated Items',  'value' => (string) $count,                                'inline' => true ),
            array( 'name' => 'Window',          'value' => $window_hours . 'h',                            'inline' => true ),
            array( 'name' => 'Threshold',       'value' => (string) $threshold,                            'inline' => true ),
        );

        if ( ! empty( $context['aggregator_name'] ) ) {
            $fields[] = array( 'name' => 'Aggregator', 'value' => $context['aggregator_name'], 'inline' => true );
        }

        if ( ! empty( $context['latest_title'] ) ) {
            $fields[] = array( 'name' => 'Most Recent', 'value' => $context['latest_title'], 'inline' => false );
        }

        Synditracker_Discord::send_notification(
            '⚠️ Duplicate syndication spike',
            $fields,
            '#ff9900',
            ! empty( $context['source_url'] ) ? $context['source_url'] : ''
        );

        return true;
    }

    /**
     * Has an alert of this type already been sent inside the window?
     */
    protected static function alerted_recently( $alert_type, $window_hours ) {
        global $wpdb;

        require_once plugin_dir_path( __FILE__ ) . 'class-synditracker-core-db.php';
        $table = Synditracker_Core_DB::get_alerts_table_name();

        // Window arithmetic in SQL, against the same clock used to write
        // created_at, so no PHP timezone conversion can skew the throttle.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table
             WHERE alert_type = %s
               AND created_at >= DATE_SUB(%s, INTERVAL %d HOUR)
             LIMIT 1",
            $alert_type,
            current_time( 'mysql' ),
            absint( $window_hours )
        ) );

        return ! empty( $existing );
    }

    /**
     * Write the alert to the ledger.
     */
    protected static function record( $alert_type, $message, $count, $threshold, $window_hours ) {
        global $wpdb;

        require_once plugin_dir_path( __FILE__ ) . 'class-synditracker-core-db.php';

        $wpdb->insert(
            Synditracker_Core_DB::get_alerts_table_name(),
            array(
                'alert_type'      => $alert_type,
                'message'         => $message,
                'duplicate_count' => (int) $count,
                'threshold'       => (int) $threshold,
                'window_hours'    => (int) $window_hours,
                'created_at'      => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%d', '%d', '%d', '%s' )
        );

        return (int) $wpdb->insert_id;
    }
}
