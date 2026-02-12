<?php
/**
 * Plugin Name:       Synditracker Agent
 * Plugin URI:        https://muneebgawri.com/synditracker-agent
 * Description:       Detects and reports syndicated content to the central Synditracker Hub.
 * Version:           1.0.0
 * Author:            Muneeb Gawri
 * Author URI:        https://muneebgawri.com
 * License:           GPL-2.0+
 * Text Domain:       synditracker-agent
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 */
define( 'SYNDITRACKER_AGENT_VERSION', '1.0.0' );
define( 'SYNDITRACKER_AGENT_PATH', plugin_dir_path( __FILE__ ) );
define( 'SYNDITRACKER_AGENT_URL', plugin_dir_url( __FILE__ ) );

/**
 * The code that runs during plugin activation.
 */
function activate_synditracker_agent() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-synditracker-agent-activator.php';
	Synditracker_Agent_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_synditracker_agent() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-synditracker-agent-deactivator.php';
	Synditracker_Agent_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_synditracker_agent' );
register_deactivation_hook( __FILE__, 'deactivate_synditracker_agent' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-synditracker-agent.php';

/**
 * Begins execution of the plugin.
 */
function run_synditracker_agent() {
	$plugin = new Synditracker_Agent();
	$plugin->run();
}

run_synditracker_agent();
