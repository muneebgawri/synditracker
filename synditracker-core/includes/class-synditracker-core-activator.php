<?php

/**
 * Fired during plugin activation.
 */
class Synditracker_Core_Activator {

	/**
	 * Activate the plugin.
     * Creates custom database table.
	 */
	public static function activate() {
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-synditracker-core-db.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-synditracker-logger.php';
        
		Synditracker_Core_DB::create_table();
        Synditracker_Logger::create_table();
	}

}
