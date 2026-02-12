<?php

/**
 * The admin settings for the plugin.
 */
class Synditracker_Agent_Admin {

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	public function add_plugin_admin_menu() {
		add_options_page(
			'Synditracker Agent',
			'Synditracker Agent',
			'manage_options',
			'synditracker-agent',
			array( $this, 'display_plugin_setup_page' )
		);
	}

    public function register_settings() {
        register_setting( 'synditracker_agent_options', 'synditracker_agent_hub_url' );
        register_setting( 'synditracker_agent_options', 'synditracker_agent_client_id' );
        register_setting( 'synditracker_agent_options', 'synditracker_agent_client_secret' );
        register_setting( 'synditracker_agent_options', 'synditracker_agent_compatibility_mode' );
    }

	public function display_plugin_setup_page() {
		include_once 'partials/synditracker-agent-admin-display.php';
	}
    
    public function ajax_test_connection() {
        check_ajax_referer( 'synditracker_test_connection', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-synditracker-api-client.php';
        $client = new Synditracker_API_Client();
        
        // Temporarily override with posted values for testing before save
        // Note: In a real scenario you might want to test the SAVED values, 
        // but testing input values is friendly.
        // For now, let's assume the user has saved the settings. 
        // Or we could pass the values to the client constructor if we modified it.
        
        $result = $client->test_connection();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        } else {
            wp_send_json_success( 'Connection successful! Hub is reachable.' );
        }
    }

}
