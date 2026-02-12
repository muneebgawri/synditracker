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
        $partner_site_id = get_transient( 'synditracker_token_' . $token ); // Should exist

        // Deduplication
        require_once plugin_dir_path( dirname( __FILE__ ) ) . '../includes/class-synditracker-core-db.php';
        
        $source_post_id = isset($params['source_post_id']) ? $params['source_post_id'] : '';
        $source_title   = isset($params['source_title']) ? sanitize_text_field( $params['source_title'] ) : '';
        $content_hash   = isset($params['content_hash']) ? $params['content_hash'] : '';

        // Extended Deduplication
        $criteria = array(
            'partner_site_id' => $partner_site_id,
            'source_post_id'  => $source_post_id,
            'source_title'    => $source_title,
            'content_hash'    => $content_hash,
        );
        $duplicate_id = Synditracker_Core_DB::check_duplicate_extended( $criteria );
        
        $status = 'valid';
        if ( $duplicate_id ) {
            $status = 'duplicate';
            Synditracker_Logger::log( 'access', 'Duplicate report received', array( 'partner_site_id' => $partner_site_id, 'source_post_id' => $source_post_id, 'title' => $source_title ) );
        }

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
            'status'          => $status,
            'debug_log'       => isset( $params['debug_log'] ) ? json_encode( $params['debug_log'] ) : '',
        );

        $result = Synditracker_Core_DB::insert_report( $data );

        if ( $result ) {
            if ( 'valid' === $status ) {
                $log_data = array( 'report_id' => $result, 'partner_site_id' => $partner_site_id );
                Synditracker_Logger::audit( 'Report received', $log_data );
            }

            if ( 'duplicate' === $status ) {
                // Alert admin about duplicate syndication
                require_once plugin_dir_path( dirname( __FILE__ ) ) . '../includes/class-synditracker-discord.php';

                // Get partner site name for context
                $partner_name = get_the_title( $partner_site_id ) ?: 'Unknown Partner';

                $fields = array(
                    array( 'name' => 'Title',          'value' => $data['source_title'] ?: 'No Title',              'inline' => false ),
                    array( 'name' => 'Source Site',    'value' => $data['site_domain'] ?: 'Unknown',                'inline' => true ),
                    array( 'name' => 'Partner Site',   'value' => $partner_name . ' (#' . $partner_site_id . ')',   'inline' => true ),
                    array( 'name' => 'Aggregator',     'value' => $data['aggregator_name'] ?: 'Manual/Direct',      'inline' => true ),
                    array( 'name' => 'Source URL',     'value' => $data['source_url'] ?: 'N/A',                     'inline' => false ),
                    array( 'name' => 'Local Post ID',  'value' => (string) ( $data['local_post_id'] ?: 'N/A' ),     'inline' => true ),
                    array( 'name' => 'Source Post ID', 'value' => (string) ( $data['source_post_id'] ?: 'N/A' ),    'inline' => true ),
                    array( 'name' => 'Original Report','value' => '#' . $duplicate_id,                              'inline' => true ),
                    array( 'name' => 'Content Hash',   'value' => $data['content_hash'] ? substr($data['content_hash'], 0, 16) . '...' : 'N/A', 'inline' => true ),
                    array( 'name' => 'Source GUID',    'value' => $data['source_guid'] ?: 'N/A',                    'inline' => true ),
                );
                
                Synditracker_Discord::send_notification( 
                    '⚠️ Duplicate Syndication Detected', 
                    $fields, 
                    '#ff0000', 
                    $data['source_url']
                );
            }
            return new WP_REST_Response( array( 'success' => true, 'report_id' => $result, 'status' => $status ), 200 );
        }

        Synditracker_Logger::error( 'Failed to insert report', array( 'params' => $params ) );
        return new WP_Error( 'db_error', 'Failed to insert report', array( 'status' => 500 ) );
    }
    
    public function get_reports( $request ) {
        require_once plugin_dir_path( dirname( __FILE__ ) ) . '../includes/class-synditracker-core-db.php';
        $reports = Synditracker_Core_DB::get_reports();
        return new WP_REST_Response( $reports, 200 );
    }

}
