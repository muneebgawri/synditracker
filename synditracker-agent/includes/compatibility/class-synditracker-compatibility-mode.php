<?php

/**
 * Compatibility Mode.
 *
 * Handles environment optimizations to prevent conflicts and ensure reliability.
 *
 * @since      1.0.0
 * @package    Synditracker_Agent
 * @subpackage Synditracker_Agent/includes/compatibility
 * @author     Muneeb Gawri <muneeb@muneebgawri.com>
 */
class Synditracker_Compatibility_Mode {

    /**
     * Initialize compatibility mode.
     *
     * @since    1.0.0
     */
    public function init() {
        if ( $this->is_compatibility_mode_enabled() ) {
            add_action( 'init', array( $this, 'optimize_environment' ) );
        }
    }

    /**
     * Check if compatibility mode is enabled.
     *
     * @since    1.0.0
     * @return   bool    True if enabled, false otherwise.
     */
    public function is_compatibility_mode_enabled() {
        return get_option( 'synditracker_agent_compatibility_mode', false );
    }

    /**
     * Optimize the environment.
     *
     * @since    1.0.0
     */
    public function optimize_environment() {
        // Only run during our processes (e.g., AJAX or cron? mostly during cron or specific requests)
        // For now, we apply it generally if enabled, or maybe restrict to when we are doing our work.
        // Since we don't have a specific persistent request state, we might just apply it sparingly.
        
        // Actually, better to apply it when expected.
        // But for "Synditracker", we mainly care about the API Client making requests.
        // So this might be called right before a request is sent?
        
        // Let's just define constants if they aren't defined.
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        // Increase limits
        @ini_set( 'memory_limit', apply_filters( 'synditracker_memory_limit', '512M' ) );
        @ini_set( 'max_execution_time', apply_filters( 'synditracker_time_limit', 300 ) );
        @set_time_limit( 300 );

        // Add idn_to_ascii polyfill for hosts without php-intl (e.g. cPanel/NameHero)
        // This prevents Feedzy 5.x and other plugins from crashing mid-import.
        if ( ! function_exists( 'idn_to_ascii' ) ) {
            /** 
             * Minimal polyfill for idn_to_ascii to prevent fatal errors when php-intl is missing.
             * Returns the original domain as-is.
             */
            function idn_to_ascii( $domain, $options = 0, $variant = 1, &$idna_info = null ) {
                return $domain;
            }
        }
    }
}
