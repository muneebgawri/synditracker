<?php
require( 'wp-load.php' );

$post_id = 4; // The ID we created

$campaign_data = get_post_meta( $post_id, 'campaign_data', true );

// Fix missing keys causing Fatal Error
$campaign_data['campaign_orderbydate'] = false; // Kept for safety
$campaign_data['campaign_feed_order_date'] = false; // THE REAL KEY
$campaign_data['campaign_max'] = 0; // Default max items

// Add other potential missing keys
$campaign_data['campaign_feed_order'] = 'date';
$campaign_data['campaign_enableping'] = false;

update_post_meta( $post_id, 'campaign_data', $campaign_data );

echo "Updated Campaign ID: $post_id with campaign_feed_order_date = false\n";
