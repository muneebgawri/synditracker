<?php

/**
 * The logger class.
 *
 * Handles writing logs to the custom database table.
 *
 * @since      1.0.0
 * @package    Synditracker_Core
 * @subpackage Synditracker_Core/includes
 * @author     Muneeb Gawri <muneeb@muneebgawri.com>
 */
class Synditracker_Logger {

	/**
	 * The table name for logs.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $table_name    The table name.
	 */
	private static $table_name;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		global $wpdb;
		self::$table_name = $wpdb->prefix . 'synditracker_logs';
	}

	/**
	 * Log an event.
	 *
	 * @since    1.0.0
	 * @param    string    $type       The type of log (audit, error, access).
	 * @param    string    $message    The log message.
	 * @param    array     $context    Optional. Additional context data.
	 * @param    int       $user_id    Optional. User ID associated with the event.
	 * @return   int|false             The insert ID on success, false on failure.
	 */
	public static function log( $type, $message, $context = array(), $user_id = 0 ) {
		global $wpdb;

		if ( empty( self::$table_name ) ) {
			self::$table_name = $wpdb->prefix . 'synditracker_logs';
		}

		// Auto-detect user ID if not provided and user is logged in
		if ( 0 === $user_id && is_user_logged_in() ) {
			$user_id = get_current_user_id();
		}

		// Get IP address
		$ip_address = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip_address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$data = array(
			'type'       => sanitize_text_field( $type ),
			'message'    => sanitize_text_field( $message ),
			'context'    => json_encode( $context ),
			'ip_address' => $ip_address,
			'user_id'    => intval( $user_id ),
			'created_at' => current_time( 'mysql' ),
		);

		$format = array( '%s', '%s', '%s', '%s', '%d', '%s' );

		return $wpdb->insert( self::$table_name, $data, $format );
	}

	/**
	 * Log an audit event.
	 *
	 * @since    1.0.0
	 * @param    string    $message    The log message.
	 * @param    array     $context    Optional. Additional context data.
	 */
	public static function audit( $message, $context = array() ) {
		return self::log( 'audit', $message, $context );
	}

	/**
	 * Log an error event.
	 *
	 * @since    1.0.0
	 * @param    string    $message    The log message.
	 * @param    array     $context    Optional. Additional context data.
	 */
	public static function error( $message, $context = array() ) {
		return self::log( 'error', $message, $context );
	}

	/**
	 * Log an access event.
	 *
	 * @since    1.0.0
	 * @param    string    $message    The log message.
	 * @param    array     $context    Optional. Additional context data.
	 */
	public static function access( $message, $context = array() ) {
		return self::log( 'access', $message, $context );
	}
    
    /**
     * Get recent logs.
     * 
     * @since 1.0.0
     * @param int $limit Number of logs to retrieve.
     * @param string $type Optional. Filter by log type.
     * @return array List of log objects.
     */
    public static function get_logs( $limit = 50, $type = '' ) {
        global $wpdb;
        $table_name = self::$table_name ?: $wpdb->prefix . 'synditracker_logs'; // Ensure table name is set
        
        $sql = "SELECT * FROM $table_name";
        
        if ( ! empty( $type ) ) {
            $sql .= $wpdb->prepare( " WHERE type = %s", $type );
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT %d";
        
        return $wpdb->get_results( $wpdb->prepare( $sql, $limit ) );
    }

	/**
	 * Create the logs table.
	 *
	 * @since    1.0.0
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'synditracker_logs';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			type varchar(50) NOT NULL,
			message text NOT NULL,
			context longtext,
			ip_address varchar(100),
			user_id bigint(20) DEFAULT 0,
			created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			KEY type (type),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}
}
