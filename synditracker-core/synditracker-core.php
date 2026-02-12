<?php
/**
 * Plugin Name:       Synditracker Core
 * Plugin URI:        https://muneebgawri.com/synditracker-core
 * Description:       The Hub for receiving, verifying, and storing syndicated content reports.
 * Version:           1.0.0
 * Author:            Muneeb Gawri
 * Author URI:        https://muneebgawri.com
 * License:           GPL-2.0+
 * Text Domain:       synditracker-core
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 */
define( 'SYNDITRACKER_CORE_VERSION', '1.0.0' );
define( 'SYNDITRACKER_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'SYNDITRACKER_CORE_URL', plugin_dir_url( __FILE__ ) );

/**
 * The code that runs during plugin activation.
 * This includes creating the custom database table.
 */
function activate_synditracker_core() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-synditracker-core-activator.php';
	Synditracker_Core_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_synditracker_core() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-synditracker-core-deactivator.php';
	Synditracker_Core_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_synditracker_core' );
register_deactivation_hook( __FILE__, 'deactivate_synditracker_core' );

/**
 * The core plugin class.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-synditracker-core.php';

/**
 * Begins execution of the plugin.
 */
function run_synditracker_core() {
	$plugin = new Synditracker_Core();
	$plugin->run();
}

run_synditracker_core();
