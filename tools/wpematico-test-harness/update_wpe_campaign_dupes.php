<?php
require( 'wp-load.php' );

$post_id = 4; // The ID we created

$campaign_data = get_post_meta( $post_id, 'campaign_data', true );

// Enable Duplicates
$campaign_data['campaign_allowduplicates'] = true; // FORCE IMPORT
// Ensure keys are set
$campaign_data['campaign_orderbydate'] = false; 
$campaign_data['campaign_feed_order_date'] = false; 
$campaign_data['campaign_max'] = 0; 
$campaign_data['campaign_posttype'] = 'publish'; // CORRECT KEY
$campaign_data['campaign_type'] = 'feed'; // Ensure type is feed 

update_post_meta( $post_id, 'campaign_data', $campaign_data );

echo "Updated Campaign ID: $post_id with allowduplicates = true\n";
