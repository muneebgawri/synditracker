<?php
require( 'wp-load.php' );

if ( ! class_exists( 'WPeMatico_functions' ) ) {
    die( "WPeMatico_functions class not found.\n" );
}

$id = 4;
echo "Running job for Campaign ID $id...\n";

// Force enable logging if possible?
// WPeMatico_functions::add_campaign_log($id, 'Manual run started');

// Spoof User Agent to bypass Cloudflare
add_filter( 'wpematico_simplepie_user_agent', function( $ua ) {
    return 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
});

$result = WPeMatico_functions::wpematico_dojob( $id );

echo "Result:\n";
print_r( $result );

// Check logs
$logs = get_post_meta( $id, 'campaign_log', true );
echo "\nLast Logs:\n";
print_r( $logs );
