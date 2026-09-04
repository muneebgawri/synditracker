<?php

/**
 * The file that defines the core plugin class.
 */
class Synditracker_Core {

	protected $loader;
	protected $plugin_name;
	protected $version;

	public function __construct() {
		if ( defined( 'SYNDITRACKER_CORE_VERSION' ) ) {
			$this->version = SYNDITRACKER_CORE_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'synditracker-core';

		$this->load_dependencies();
		$this->define_admin_hooks();
		$this->define_public_hooks();
        $this->define_api_hooks();
	}

	private function load_dependencies() {
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-synditracker-core-loader.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-synditracker-core-dashboard.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/api/class-synditracker-api-controller.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-synditracker-core-cpt.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-synditracker-logger.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-synditracker-core-db.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-synditracker-discord.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-synditracker-alerts.php';

		$this->loader = new Synditracker_Core_Loader();
	}

	/**
	 * Bring the schema up to date.
	 *
	 * Runs on init because this plugin has been upgraded in place without ever
	 * being reactivated, so the activation hook alone does not guarantee the
	 * tables and columns the code expects exist. No-ops once versions match.
	 */
	public function maybe_upgrade_db() {
		Synditracker_Core_DB::maybe_upgrade();
	}

	private function define_admin_hooks() {
		$plugin_admin = new Synditracker_Core_Dashboard( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_menu', $plugin_admin, 'add_plugin_admin_menu' );
        
        // Partner Site CPT
        $cpt = new Synditracker_Core_CPT();
        $this->loader->add_action( 'init', $cpt, 'register_partner_site_cpt' );

        $this->loader->add_action( 'init', $this, 'maybe_upgrade_db' );
	}

	private function define_public_hooks() {
        // No public hooks for now
	}

    private function define_api_hooks() {
        $api = new Synditracker_API_Controller();
        $this->loader->add_action( 'rest_api_init', $api, 'register_routes' );
    }

	public function run() {
		$this->loader->run();
	}

	public function get_plugin_name() {
		return $this->plugin_name;
	}

	public function get_loader() {
		return $this->loader;
	}

	public function get_version() {
		return $this->version;
	}

}
