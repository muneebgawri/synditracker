<?php

class Synditracker_API_Controller {

    public function register_routes() {
        $namespace = 'synditracker/v1';

        // Health Check
        register_rest_route( $namespace, '/health', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'get_health' ),
            'permission_callback' => '__return_true',
        ));

        // OAuth Token
        register_rest_route( $namespace, '/oauth/token', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'generate_token' ),
            'permission_callback' => '__return_true', // Public endpoint, validated by params
        ));

        // Report Ingestion
        register_rest_route( $namespace, '/log', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'ingest_report' ),
            'permission_callback' => array( $this, 'validate_token' ),
        ));
        
        // Reports Retrieval (Optional/Authenticated)
        register_rest_route( $namespace, '/reports', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'get_reports' ),
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
        ));
    }

    public function get_health() {
        return new WP_REST_Response( array( 'status' => 'healthy', 'timestamp' => time() ), 200 );
    }

    public function generate_token( $request ) {
        $client_id = $request->get_param( 'client_id' );
        $client_secret = $request->get_param( 'client_secret' );
        $grant_type = $request->get_param( 'grant_type' );

        if ( 'client_credentials' !== $grant_type ) {
            return new WP_Error( 'invalid_grant', 'Invalid grant type', array( 'status' => 400 ) );
        }

        // Find partner site by client_id
        $args = array(
            'post_type'  => 'partner_site',
            'meta_key'   => '_synditracker_client_id',
            'meta_value' => $client_id,
            'posts_per_page' => 1,
            'post_status' => 'publish' // Only active sites
        );
        $query = new WP_Query( $args );

        if ( ! $query->have_posts() ) {
            return new WP_Error( 'invalid_client', 'Invalid Client ID', array( 'status' => 401 ) );
        }

        $partner_site_id = $query->posts[0]->ID;
        $stored_secret = get_post_meta( $partner_site_id, '_synditracker_client_secret', true );

        if ( $stored_secret !== $client_secret ) {
             return new WP_Error( 'invalid_client', 'Invalid Client Secret', array( 'status' => 401 ) );
        }

        // Generate Token
        $token = wp_generate_password( 64, false );
        $expires_in = 3600; // 1 hour

        // Store token (transient)
        // Key: synditracker_token_{token}, Value: partner_site_id
        set_transient( 'synditracker_token_' . $token, $partner_site_id, $expires_in );

        return new WP_REST_Response( array(
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'expires_in'   => $expires_in
        ), 200 );
    }

    public function validate_token( $request ) {
        $auth_header = $request->get_header( 'authorization' );
        if ( ! $auth_header ) return false;

        $token = str_replace( 'Bearer ', '', $auth_header );
        $partner_site_id = get_transient( 'synditracker_token_' . $token );

        if ( ! $partner_site_id ) {
            return false;
        }
        
        // Pass partner_site_id to the callback via request object? 
        // WP REST API doesn't easily allow mutating request attributes in permission callback strictly 
        // ensuring they persist, but we can access it again in callback.
        return true;
    }

    public function ingest_report( $request ) {
        $params = $request->get_json_params();
        
        // Check for Base64 encoded payload (WAF bypass)
        if ( isset( $params['payload'] ) ) {
            $decoded = json_decode( base64_decode( $params['payload'] ), true );
            if ( $decoded ) {
                $params = $decoded;
            } else {
                 return new WP_Error( 'invalid_payload', 'Failed to decode payload', array( 'status' => 400 ) );
            }
        }
        
        // Validate required fields
        $required = array( 'local_post_id', 'source_url', 'site_domain' );
        foreach ( $required as $field ) {
            if ( empty( $params[ $field ] ) ) {
                return new WP_Error( 'missing_param', 'Missing parameter: ' . $field, array( 'status' => 400 ) );
            }
        }

        // Identify Partner
        $auth_header = $request->get_header( 'authorization' );
        $token = str_replace( 'Bearer ', '', $auth_header );
        $partner_site_id = get_transient( 'synditracker_token_' . $token );

        // The permission callback already validated the token, but the transient
        // can expire between the two. Fail loudly rather than filing the report
        // against partner_site_id 0.
        if ( ! $partner_site_id ) {
            return new WP_Error( 'invalid_token', 'Token expired', array( 'status' => 401 ) );
        }

        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'class-synditracker-core-db.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'class-synditracker-alerts.php';

        $source_post_id = isset($params['source_post_id']) ? $params['source_post_id'] : '';
        $source_title   = isset($params['source_title']) ? sanitize_text_field( $params['source_title'] ) : '';
        $content_hash   = isset($params['content_hash']) ? $params['content_hash'] : '';

        $data = array(
            'partner_site_id' => $partner_site_id,
            'local_post_id'   => $params['local_post_id'],
            'source_post_id'  => $source_post_id,
            'source_url'      => esc_url_raw( $params['source_url'] ),
            'source_guid'     => sanitize_text_field( isset($params['source_guid']) ? $params['source_guid'] : '' ),
            'source_title'    => $source_title,
            'aggregator_name' => sanitize_text_field( isset($params['aggregator_name']) ? $params['aggregator_name'] : '' ),
            'site_domain'     => sanitize_text_field( $params['site_domain'] ),
            'content_hash'    => sanitize_text_field( $content_hash ),
            'status'          => 'valid',
            'debug_log'       => isset( $params['debug_log'] ) ? json_encode( $params['debug_log'] ) : '',
        );

        // Re-observations update the existing row instead of inserting a new
        // one, so a partner re-importing the same article no longer inflates
        // the table or trips an alert on its own.
        $result = Synditracker_Core_DB::record_observation( $data );

        if ( ! $result ) {
            Synditracker_Logger::error( 'Failed to record report', array( 'params' => $params ) );
            return new WP_Error( 'db_error', 'Failed to insert report', array( 'status' => 500 ) );
        }

        if ( $result['is_repeat'] ) {
            Synditracker_Logger::log( 'access', 'Repeat observation', array(
                'report_id'       => $result['id'],
                'partner_site_id' => $partner_site_id,
                'seen_count'      => $result['seen_count'],
                'title'           => $source_title,
            ) );

            // Spike detection decides whether this is worth a human's attention.
            Synditracker_Alerts::maybe_alert( $partner_site_id, array(
                'aggregator_name' => $data['aggregator_name'],
                'latest_title'    => $source_title,
                'source_url'      => $data['source_url'],
            ) );
        } else {
            Synditracker_Logger::audit( 'Report received', array(
                'report_id'       => $result['id'],
                'partner_site_id' => $partner_site_id,
            ) );
        }

        return new WP_REST_Response( array(
            'success'    => true,
            'report_id'  => $result['id'],
            'status'     => $result['is_repeat'] ? 'duplicate' : 'valid',
            'seen_count' => $result['seen_count'],
        ), 200 );
    }
    
    public function get_reports( $request ) {
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'class-synditracker-core-db.php';
        $reports = Synditracker_Core_DB::get_reports();
        return new WP_REST_Response( $reports, 200 );
    }

}
