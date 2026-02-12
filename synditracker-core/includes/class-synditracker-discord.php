<?php

/**
 * Discord Notification Service.
 *
 * Handles sending notifications to a Discord Webhook.
 *
 * @since      1.0.0
 * @package    Synditracker_Core
 * @subpackage Synditracker_Core/includes
 * @author     Muneeb Gawri <muneeb@muneebgawri.com>
 */
class Synditracker_Discord {

    /**
     * Send a notification to Discord.
     *
     * @since    1.0.0
     * @param    string    $title      The title of the notification.
     * @param    array     $fields     Array of fields (name, value, inline).
     * @param    string    $color      Hex color code (default green).
     * @param    string    $url        Optional URL for the title.
     * @return   bool|WP_Error         True on success, WP_Error on failure.
     */
    public static function send_notification( $title, $fields = array(), $color = '#00ff00', $url = '' ) {
        $webhook_url = get_option( 'synditracker_discord_webhook_url' );

        if ( empty( $webhook_url ) ) {
            Synditracker_Logger::log( 'error', 'Discord Webhook URL is missing', array() );
            return false;
        }

        // Convert hex color to integer
        $color_dec = hexdec( ltrim( $color, '#' ) );

        // Validate and Sanitize Fields for Discord
        // Discord API requires 'name' and 'value' to be non-empty strings.
        $valid_fields = array();
        foreach ( $fields as $field ) {
            if ( ! empty( $field['name'] ) && ! empty( $field['value'] ) ) {
                $valid_fields[] = $field;
            }
        }

        $embed = array(
            'title'       => $title,
            'color'       => $color_dec,
            'timestamp'   => date( 'c' ),
            'footer'      => array(
                'text' => 'Synditracker Core',
            ),
            'fields'      => $valid_fields,
        );

        if ( ! empty( $url ) ) {
            $embed['url'] = $url;
        }
        
        // Ensure at least description or fields exist if title is present, but title alone is fine.
        $payload = array(
            'embeds' => array( $embed ),
        );
        
        $body_json = json_encode( $payload );

        // Log the attempt
        Synditracker_Logger::log( 'audit', 'Sending Discord Notification', array( 'url_masked' => substr($webhook_url, 0, 40) . '...', 'payload' => $payload ) );

        $response = wp_remote_post( $webhook_url, array(
            'body'        => $body_json,
            'headers'     => array( 'Content-Type' => 'application/json' ),
            'blocking'    => true, // Block to catch errors
            'timeout'     => 10, // Increased timeout
        ));

        if ( is_wp_error( $response ) ) {
            Synditracker_Logger::log( 'error', 'Discord Notification Failed (Network)', array( 'error' => $response->get_error_message() ) );
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            Synditracker_Logger::log( 'error', 'Discord Notification Failed (HTTP ' . $code . ')', array( 'response' => $body, 'payload' => $payload ) );
            return new WP_Error( 'discord_error', 'Discord API Error: ' . $code, array( 'status' => $code ) );
        }

        Synditracker_Logger::log( 'audit', 'Discord Notification Sent Successfully', array( 'code' => $code ) );

        return true;
    }
}
