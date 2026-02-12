<?php

class Synditracker_API_Client {

    private $hub_url;
    private $client_id;
    private $client_secret;
    private $token_option_name = 'synditracker_agent_access_token';

    public function __construct() {
        $this->hub_url = get_option( 'synditracker_agent_hub_url' );
        $this->client_id = get_option( 'synditracker_agent_client_id' );
        $this->client_secret = get_option( 'synditracker_agent_client_secret' );
    }

    /**
     * Test connection to the Hub.
     */
    public function test_connection() {
        if ( empty( $this->hub_url ) ) {
            return new WP_Error( 'missing_url', 'Hub URL is missing.' );
        }

        $health_url = trailingslashit( $this->hub_url ) . 'wp-json/synditracker/v1/health';
        
        $response = wp_remote_get( $health_url, array(
            'timeout' => 30, // Increased timeout
            'sslverify' => apply_filters( 'synditracker_ssl_verify', true ), 
            'user-agent' => 'Synditracker-Agent/' . ( defined('SYNDITRACKER_AGENT_VERSION') ? SYNDITRACKER_AGENT_VERSION : '1.0' ) . '; ' . home_url(),
        ));

        if ( is_wp_error( $response ) ) {
            // Log full error details for debugging
            error_log( 'Synditracker Connection Error: ' . $response->get_error_message() );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
             return new WP_Error( 'api_error', 'Hub returned status: ' . $code . ' - ' . wp_remote_retrieve_response_message( $response ) );
        }
        
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset($body['status']) && $body['status'] === 'healthy' ) {
            return true;
        }

        return new WP_Error( 'invalid_response', 'Invalid response from Hub: ' . substr( wp_remote_retrieve_body( $response ), 0, 100 ) );
    }

    /**
     * Authenticate and get token.
     */
    public function get_token() {
        // Check if we have a valid token
        $token_data = get_option( $this->token_option_name );
        if ( $token_data && $token_data['expires'] > time() ) {
            return $token_data['token'];
        }

        // Request new token
        $token_url = trailingslashit( $this->hub_url ) . 'wp-json/synditracker/v1/oauth/token';
        
        $response = wp_remote_post( $token_url, array(
            'timeout' => 30,
            'user-agent' => 'Synditracker-Agent/' . ( defined('SYNDITRACKER_AGENT_VERSION') ? SYNDITRACKER_AGENT_VERSION : '1.0' ) . '; ' . home_url(),
            'body' => array(
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type' => 'client_credentials'
            )
        ));

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 && isset( $body['access_token'] ) ) {
            // Save token
            $expires_in = isset( $body['expires_in'] ) ? intval( $body['expires_in'] ) : 3600;
            update_option( $this->token_option_name, array(
                'token' => $body['access_token'],
                'expires' => time() + $expires_in - 60 // Buffer
            ), 'no' ); // Don't autoload

            return $body['access_token'];
        }

        return new WP_Error( 'auth_failed', 'Authentication failed: ' . ( isset($body['message']) ? $body['message'] : 'Unknown error' ) );
    }

    /**
     * Send report to Hub.
     */
    public function send_report( $report_data ) {
        $token = $this->get_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $log_url = trailingslashit( $this->hub_url ) . 'wp-json/synditracker/v1/log';
        
        // Base64 encode payload to bypass WAF (e.g. Cloudflare)
        // We wrap it in a 'payload' field to signify it's encoded
        $body = array(
            'payload' => base64_encode( json_encode( $report_data ) )
        );

        $response = wp_remote_post( $log_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode( $body ),
            'timeout' => 30,
            'user-agent' => 'Synditracker-Agent/' . ( defined('SYNDITRACKER_AGENT_VERSION') ? SYNDITRACKER_AGENT_VERSION : '1.0' ) . '; ' . home_url(),
        ));

        if ( is_wp_error( $response ) ) {
             error_log( 'Synditracker Report Error: ' . $response->get_error_message() );
        }

        return $response;
    }
}
