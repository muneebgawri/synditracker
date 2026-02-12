<?php

/**
 * Fired during plugin deactivation.
 */
class Synditracker_Agent_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {
        $timestamp = wp_next_scheduled('synditracker_agent_retry_queue');
        wp_unschedule_event($timestamp, 'synditracker_agent_retry_queue');
	}

}
