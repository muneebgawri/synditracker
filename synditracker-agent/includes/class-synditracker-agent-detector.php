<?php

/**
 * Handles detection of syndicated content.
 */
class Synditracker_Agent_Detector {

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

    /**
     * Callback for WPeMatico hook.
     */
    public function detect_wpematico( $post_id, $campaign, $item ) {
        error_log( "Synditracker: detect_wpematico called for Post ID $post_id" );
        $post = get_post( $post_id );
        if ( $post ) {
            $this->schedule_detection( $post_id, $post, true );
        } else {
            error_log( "Synditracker: detect_wpematico Post not found for ID $post_id" );
        }
    }

    /**
     * Callback for WP RSS Aggregator hook.
     */
    public function detect_wprss( $post_id ) {
        $post = get_post( $post_id );
        if ( $post ) {
            $this->schedule_detection( $post_id, $post, true );
        }
    }

	/**
	 * Static guard: track post IDs already scheduled within this PHP request.
	 * Prevents race conditions when save_post fires multiple times.
	 */
	private static $scheduled_ids = array();

	/**
	 * Called on save_post. Schedules deferred detection to allow
	 * aggregator plugins (Feedzy, WPeMatico, etc.) time to save their meta.
	 */
	public function schedule_detection( $post_id, $post, $update ) {
        error_log( "Synditracker: schedule_detection called for Post ID $post_id" );
		// Avoid auto-drafts, revisions, and non-post types
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            error_log( "Synditracker: schedule_detection skipped (autosave)" );
            return;
        }
		if ( wp_is_post_revision( $post_id ) ) {
            error_log( "Synditracker: schedule_detection skipped (revision)" );
            return;
        }
		if ( 'post' !== $post->post_type ) {
            error_log( "Synditracker: schedule_detection skipped (not post type: " . $post->post_type . ")" );
            return;
        }
		if ( 'publish' !== $post->post_status ) {
            error_log( "Synditracker: schedule_detection skipped (not publish status: " . $post->post_status . ")" );
            return;
        }

		// In-memory guard: skip if already scheduled in this PHP request
		if ( in_array( $post_id, self::$scheduled_ids, true ) ) {
            error_log( "Synditracker: schedule_detection skipped (already scheduled in memory)" );
			return;
		}

		// Persistent guard: skip if already reported to Hub
		if ( get_post_meta( $post_id, '_synditracker_reported', true ) ) {
            error_log( "Synditracker: schedule_detection skipped (already reported meta)" );
			return;
		}

		// Mark as scheduled in memory immediately to prevent re-entry
		self::$scheduled_ids[] = $post_id;

		// Also set the post meta flag NOW to prevent any other detection path
		update_post_meta( $post_id, '_synditracker_scheduled', current_time( 'mysql', 1 ) );

		// Unschedule any existing event for this post (defensive)
		wp_clear_scheduled_hook( 'synditracker_deferred_detect', array( $post_id ) );

		// Schedule detection to run 15 seconds from now
		wp_schedule_single_event( time() + 15, 'synditracker_deferred_detect', array( $post_id ) );
        error_log( "Synditracker: content scheduled for deferred detection (Post ID $post_id)" );
	}

	/**
	 * Runs the actual detection logic after a short delay.
	 * By this point, all aggregator plugins have saved their post meta.
	 */
	public function run_deferred_detection( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}

		// Skip if already fully reported (another deferred event beat us)
		if ( get_post_meta( $post_id, '_synditracker_reported', true ) ) {
			return;
		}

		$this->detect_syndication( $post_id, $post );
	}

	/**
	 * Core detection logic. Checks all aggregator meta keys and falls back
	 * to domain scan. Called from run_deferred_detection after a delay.
	 */
	private function detect_syndication( $post_id, $post ) {
        $is_syndicated = false;
        $source_data = array();

        // 1. Detect Feedzy
        $feedzy_url = get_post_meta( $post_id, 'feedzy_item_url', true );
        if ( ! empty( $feedzy_url ) ) {
            $is_syndicated = true;
            $source_data = array(
                'aggregator_name' => 'Feedzy',
                'source_url'      => $feedzy_url,
                'source_guid'     => get_post_meta( $post_id, 'feedzy_item_guid', true ) ?: $feedzy_url,
                'source_post_id'  => '',
            );
        }

        // 2. Detect WPeMatico
        if ( ! $is_syndicated ) {
            $wpe_campaign = get_post_meta( $post_id, 'wpe_campaignid', true );
            if ( ! empty( $wpe_campaign ) ) {
                $is_syndicated = true;
                $source_data = array(
                    'aggregator_name' => 'WPeMatico',
                    'source_url'      => get_post_meta( $post_id, 'wpe_sourcepermalink', true ) ?: '', // Fixed key name
                    'source_guid'     => '',
                    'source_post_id'  => '',
                );
            }
        }

        // 3. Detect WP RSS Aggregator
        if ( ! $is_syndicated ) {
            $wprss_permalink = get_post_meta( $post_id, 'wprss_item_permalink', true );
            if ( ! empty( $wprss_permalink ) ) {
                $is_syndicated = true;
                $source_data = array(
                    'aggregator_name' => 'WP RSS Aggregator',
                    'source_url'      => $wprss_permalink,
                    'source_guid'     => get_post_meta( $post_id, 'wprss_item_guid', true ) ?: $wprss_permalink,
                    'source_post_id'  => '',
                );
            }
        }
        
        // 4. Generic Fallback (Manual Meta or RSS Importer)
        if ( ! $is_syndicated ) {
            $syndication_permalink = get_post_meta( $post_id, 'syndication_permalink', true );
            if ( ! empty( $syndication_permalink ) ) {
                $is_syndicated = true;
                $source_data = array(
                    'aggregator_name' => 'Generic RSS Importer',
                    'source_url'      => $syndication_permalink,
                    'source_guid'     => get_post_meta( $post_id, 'syndication_feed_id', true ) ?: '',
                    'source_post_id'  => '',
                );  
            }
        }

        // 5. Robust Fallback: Content/Domain Scan
        if ( ! $is_syndicated ) {
            $hub_url = get_option( 'synditracker_agent_hub_url' );
            if ( ! empty( $hub_url ) ) {
                $hub_domain = parse_url( $hub_url, PHP_URL_HOST );
                $hub_domain = preg_replace( '/^www\./', '', $hub_domain );
                
                if ( $hub_domain && ( 
                     strpos( $post->post_content, $hub_domain ) !== false || 
                     strpos( get_permalink( $post_id ), $hub_domain ) !== false
                   ) ) {
                    
                    $is_syndicated = true;
                    $source_url = '';
                    preg_match_all( '/href="([^"]*)"/i', $post->post_content, $matches );
                    if ( ! empty( $matches[1] ) ) {
                        foreach ( $matches[1] as $url ) {
                            if ( strpos( $url, $hub_domain ) !== false ) {
                                $source_url = $url;
                                break;
                            }
                        }
                    }

                    $source_data = array(
                        'aggregator_name' => 'Domain Scan (Content Match)',
                        'source_url'      => $source_url ?: $hub_url,
                        'source_guid'     => '',
                        'source_post_id'  => '',
                    );
                }
            }
        }

        if ( $is_syndicated ) {
             $this->report_syndication( $post_id, $post, $source_data );
        }
	}

    private function report_syndication( $post_id, $post, $source_data ) {
        $report = array(
            'local_post_id'   => $post_id,
            'local_post_url'  => get_permalink( $post_id ),
            'local_post_date' => $post->post_date_gmt,
            'source_post_id'  => isset( $source_data['source_post_id'] ) ? $source_data['source_post_id'] : '',
            'source_url'      => isset( $source_data['source_url'] ) ? $source_data['source_url'] : '',
            'source_guid'     => isset( $source_data['source_guid'] ) ? $source_data['source_guid'] : '',
            'source_title'    => $post->post_title,
            'source_name'     => $post->post_name,
            'aggregator_name' => isset( $source_data['aggregator_name'] ) ? $source_data['aggregator_name'] : 'Unknown',
            'site_domain'     => parse_url( site_url(), PHP_URL_HOST ),
            'content_hash'    => md5( $post->post_content ),
            'first_seen_at'   => current_time( 'mysql', 1 ),
            'debug_log'       => array(
                'post_date'         => $post->post_date,
                'post_date_gmt'     => $post->post_date_gmt,
                'post_modified'     => $post->post_modified,
                'post_modified_gmt' => $post->post_modified_gmt,
                'meta'              => get_post_meta( $post_id ),
            ),
        );

        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-synditracker-api-client.php';
        $client = new Synditracker_API_Client();
        
        $result = $client->send_report( $report );

        if ( is_wp_error( $result ) || wp_remote_retrieve_response_code( $result ) !== 200 ) {
             error_log( 'Synditracker Report Failed: ' . ( is_wp_error($result) ? $result->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($result) ) );
             $this->queue_retry( $report );
        } else {
             // Mark this post as reported to prevent duplicate reports
             update_post_meta( $post_id, '_synditracker_reported', current_time( 'mysql', 1 ) );
        }
    }

    private function queue_retry( $report ) {
        $queue = get_option( 'synditracker_retry_queue', array() );
        $queue[] = array(
            'report'   => $report,
            'attempts' => 1,
            'time'     => time(),
        );
        update_option( 'synditracker_retry_queue', $queue, 'no' );
    }

    public function process_retry_queue() {
        $queue = get_option( 'synditracker_retry_queue', array() );
        
        if ( empty( $queue ) ) return;

        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-synditracker-api-client.php';
        $client = new Synditracker_API_Client();
        
        $new_queue = array();
        $has_changes = false;

        foreach ( $queue as $item ) {
            $attempts = isset( $item['attempts'] ) ? $item['attempts'] : 0;
            $last_time = isset( $item['time'] ) ? $item['time'] : 0;
            
            $delay = 15 * MINUTE_IN_SECONDS * pow( 2, $attempts );
            
            if ( time() < ( $last_time + $delay ) ) {
                $new_queue[] = $item;
                continue;
            }

            $result = $client->send_report( $item['report'] );
            
            if ( is_wp_error( $result ) || wp_remote_retrieve_response_code( $result ) !== 200 ) {
                $item['attempts']++;
                $item['time'] = time();
                
                if ( $item['attempts'] < 5 ) {
                     $new_queue[] = $item;
                } else {
                     error_log( 'Synditracker: Dropped report after 5 attempts. Post ID: ' . $item['report']['local_post_id'] );
                }
                $has_changes = true;
            } else {
                $has_changes = true;
            }
        }

        if ( $has_changes ) {
            update_option( 'synditracker_retry_queue', $new_queue, 'no' );
        }
    }

}
