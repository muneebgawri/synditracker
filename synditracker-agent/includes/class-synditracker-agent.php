<?php

/**
 * The file that defines the core plugin class.
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 */
class Synditracker_Agent {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Synditracker_Agent_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 */
	public function __construct() {
		if ( defined( 'SYNDITRACKER_AGENT_VERSION' ) ) {
			$this->version = SYNDITRACKER_AGENT_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'synditracker-agent';

		$this->load_dependencies();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 */
	private function load_dependencies() {
		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-synditracker-agent-loader.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-synditracker-agent-admin.php';

        /**
		 * The class responsible for detection logic.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-synditracker-agent-detector.php';
        
        /**
         * Compatibility Mode.
         */
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/compatibility/class-synditracker-compatibility-mode.php';

		$this->loader = new Synditracker_Agent_Loader();
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 */
	private function define_admin_hooks() {
		$plugin_admin = new Synditracker_Agent_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_menu', $plugin_admin, 'add_plugin_admin_menu' );
        $this->loader->add_action( 'admin_init', $plugin_admin, 'register_settings' );
        // AJAX for connection test
        $this->loader->add_action( 'wp_ajax_synditracker_test_connection', $plugin_admin, 'ajax_test_connection' );
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 */
	private function define_public_hooks() {
        $detector = new Synditracker_Agent_Detector( $this->get_plugin_name(), $this->get_version() );
        
        // Schedule deferred detection on save_post (gives aggregator plugins time to save meta)
        $this->loader->add_action( 'save_post', $detector, 'schedule_detection', 10, 3 );
        
        // Deferred detection event (fires 15 seconds after post is saved)
        $this->loader->add_action( 'synditracker_deferred_detect', $detector, 'run_deferred_detection', 10, 1 );
        
        // Retry Queue Cron
        $this->loader->add_action( 'synditracker_agent_retry_queue', $detector, 'process_retry_queue' );
        
        // Native Aggregator Hooks (for reliability)
        // WPeMatico
        $this->loader->add_action( 'wpematico_inserted_post', $detector, 'detect_wpematico', 10, 3 );
        // WP RSS Aggregator
        $this->loader->add_action( 'wprss_fetch_single_feed_hook', $detector, 'detect_wprss', 10, 1 );
        
        // Compatibility Mode
        $compatibility = new Synditracker_Compatibility_Mode();
        $compatibility->init();
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
