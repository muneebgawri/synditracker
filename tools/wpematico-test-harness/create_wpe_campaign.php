<?php
require( 'wp-load.php' );

// 1. Create Post
$campaign_post = array(
    'post_title'    => 'Pinion Test Campaign',
    'post_content'  => '',
    'post_status'   => 'publish',
    'post_type'     => 'wpematico',
    'post_author'   => 1,
);

$post_id = wp_insert_post( $campaign_post );

if ( is_wp_error( $post_id ) ) {
    echo "Error: " . $post_id->get_error_message() . "\n";
    exit;
}

echo "Created Campaign ID: $post_id\n";

// 2. Prepare Data
$campaign_data = array(
    'campaign_feeds' => array( 'https://pinionnewswire.com/feed/' ),
    'campaign_feed_date' => 'item',
    'campaign_author' => 1,
    'campaign_linktosource' => 0,
    'campaign_allowduplicates' => 0, // Critical for testing deduplication if WPeMatico supports it internally
    'campaign_wrd2cat' => array(
        'w2ccateg' => array( 1 ) // Default category
    ),
    'activated' => 1,
    'campaign_type' => 'feed'
);

// 3. Save Meta
update_post_meta( $post_id, 'campaign_data', $campaign_data );

// WPeMatico internal state meta
update_post_meta( $post_id, 'cronnextrun', time() - 60 );
update_post_meta( $post_id, 'postscount', 0 );
update_post_meta( $post_id, 'lastrun', 0 );

echo "Campaign Configured.\n";
