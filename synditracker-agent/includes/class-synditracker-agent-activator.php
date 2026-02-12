<?php

/**
 * Fired during plugin activation.
 */
class Synditracker_Agent_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
        // Create transient validation
        if (!get_option('synditracker_agent_hub_url')) {
            add_option('synditracker_agent_hub_url', '', '', 'no'); // 'no' for autoload
        }
        
        if (!wp_next_scheduled('synditracker_agent_retry_queue')) {
            wp_schedule_event(time(), 'hourly', 'synditracker_agent_retry_queue');
        }
	}

}
