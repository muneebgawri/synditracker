<?php

/**
 * The admin settings for the plugin.
 */
class Synditracker_Core_Dashboard {

	private $plugin_name;
	private $version;
	private $db;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	public function add_plugin_admin_menu() {
		add_menu_page(
			'Synditracker Core',
			'Synditracker',
			'manage_options',
			'synditracker-core-dashboard',
			array( $this, 'display_reports_page' ),
            'dashicons-chart-pie',
            6
		);
        
        // Settings page under Synditracker
        add_submenu_page(
            'synditracker-core-dashboard',
            'Settings',
            'Settings',
            'manage_options',
            'synditracker-core-settings',
            array( $this, 'display_settings_page' )
        );

        // Logs page
        add_submenu_page(
            'synditracker-core-dashboard',
            'Logs',
            'Logs',
            'manage_options',
            'synditracker-core-logs',
            array( $this, 'display_logs_page' )
        );
	}

    public function display_settings_page() {
        if ( isset( $_POST['synditracker_discord_webhook_url'] ) && check_admin_referer( 'synditracker_save_settings' ) ) {
            update_option( 'synditracker_discord_webhook_url', esc_url_raw( $_POST['synditracker_discord_webhook_url'] ) );
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
            Synditracker_Logger::audit( 'Settings updated', array( 'user_id' => get_current_user_id() ) );
        }

        $webhook_url = get_option( 'synditracker_discord_webhook_url' );
        ?>
        <div class="wrap">
            <h1>Synditracker Core Settings</h1>
            <form method="post" action="">
                <?php wp_nonce_field( 'synditracker_save_settings' ); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Discord Webhook URL</th>
                        <td>
                            <input type="text" name="synditracker_discord_webhook_url" value="<?php echo esc_attr( $webhook_url ); ?>" class="regular-text" />
                            <p class="description">Enter the Discord Webhook URL to receive notifications on new reports.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function display_logs_page() {
        if ( ! class_exists( 'Synditracker_Logger' ) ) {
             require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-synditracker-logger.php';
        }
        $logs = Synditracker_Logger::get_logs( 100 );
        ?>
        <div class="wrap">
            <h1>Synditracker Logs</h1>
            <p>Showing latest 100 entries.</p>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 150px;">Date</th>
                        <th style="width: 100px;">Type</th>
                        <th>Message</th>
                        <th style="width: 100px;">User</th>
                        <th style="width: 120px;">IP</th>
                        <th>Context</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ) : ?>
                        <tr><td colspan="6">No logs found.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $logs as $log ) : ?>
                            <tr>
                                <td><?php echo esc_html( $log->created_at ); ?></td>
                                <td>
                                    <?php 
                                    $badge_class = 'info';
                                    if ( 'error' === $log->type ) $badge_class = 'error';
                                    if ( 'access' === $log->type ) $badge_class = 'warning';
                                    echo '<span class="dashicons dashicons-' . ( 'error' === $log->type ? 'warning' : 'info' ) . '"></span> ' . esc_html( ucfirst( $log->type ) ); 
                                    ?>
                                </td>
                                <td><?php echo esc_html( $log->message ); ?></td>
                                <td><?php echo esc_html( $log->user_id ); ?></td>
                                <td><?php echo esc_html( $log->ip_address ); ?></td>
                                <td><pre style="white-space: pre-wrap; font-size: 10px;"><?php echo esc_html( $log->context ); ?></pre></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

	public function display_reports_page() {
        if ( ! class_exists( 'Synditracker_Core_DB' ) ) {
            require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-synditracker-core-db.php';
        }
        $reports = Synditracker_Core_DB::get_reports();
        
        echo '<div class="wrap">';
        echo '<h1>Syndication Reports</h1>';
        echo '<p>Latest 50 reports.</p>';
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>ID</th>';
        echo '<th>Date</th>';
        echo '<th>Partner Site ID</th>';
        echo '<th>Source URL</th>';
        echo '<th>Aggregator</th>';
        echo '<th>Status</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        if ( empty( $reports ) ) {
            echo '<tr><td colspan="6">No reports found.</td></tr>';
        } else {
            foreach ( $reports as $report ) {
                echo '<tr>';
                echo '<td>' . esc_html( $report['id'] ) . '</td>';
                echo '<td>' . esc_html( $report['created_at'] ) . '</td>';
                echo '<td>' . esc_html( $report['partner_site_id'] ) . '</td>';
                echo '<td><a href="' . esc_url( $report['source_url'] ) . '" target="_blank">' . esc_html( mb_strimwidth($report['source_url'], 0, 50, '...') ) . '</a></td>';
                echo '<td>' . esc_html( $report['aggregator_name'] ) . '</td>';
                echo '<td>' . esc_html( $report['status'] ) . '</td>';
                echo '</tr>';
            }
        }
        
        echo '</tbody></table>';
        echo '</div>';
    }

}
