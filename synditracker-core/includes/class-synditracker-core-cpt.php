<?php

class Synditracker_Core_CPT {

    public function register_partner_site_cpt() {
        $labels = array(
            'name'                  => _x( 'Partner Sites', 'Post Type General Name', 'synditracker-core' ),
            'singular_name'         => _x( 'Partner Site', 'Post Type Singular Name', 'synditracker-core' ),
            'menu_name'             => __( 'Partner Sites', 'synditracker-core' ),
            'name_admin_bar'        => __( 'Partner Site', 'synditracker-core' ),
            'archives'              => __( 'Partner Site Archives', 'synditracker-core' ),
            'attributes'            => __( 'Partner Site Attributes', 'synditracker-core' ),
            'parent_item_colon'     => __( 'Parent Partner Site:', 'synditracker-core' ),
            'all_items'             => __( 'All Partner Sites', 'synditracker-core' ),
            'add_new_item'          => __( 'Add New Partner Site', 'synditracker-core' ),
            'add_new'               => __( 'Add New', 'synditracker-core' ),
            'new_item'              => __( 'New Partner Site', 'synditracker-core' ),
            'edit_item'             => __( 'Edit Partner Site', 'synditracker-core' ),
            'update_item'           => __( 'Update Partner Site', 'synditracker-core' ),
            'view_item'             => __( 'View Partner Site', 'synditracker-core' ),
            'view_items'            => __( 'View Partner Sites', 'synditracker-core' ),
            'search_items'          => __( 'Search Partner Site', 'synditracker-core' ),
            'not_found'             => __( 'Not found', 'synditracker-core' ),
            'not_found_in_trash'    => __( 'Not found in Trash', 'synditracker-core' ),
            'featured_image'        => __( 'Featured Image', 'synditracker-core' ),
            'set_featured_image'    => __( 'Set featured image', 'synditracker-core' ),
            'remove_featured_image' => __( 'Remove featured image', 'synditracker-core' ),
            'use_featured_image'    => __( 'Use as featured image', 'synditracker-core' ),
            'insert_into_item'      => __( 'Insert into partner site', 'synditracker-core' ),
            'uploaded_to_this_item' => __( 'Uploaded to this partner site', 'synditracker-core' ),
            'items_list'            => __( 'Partner Sites list', 'synditracker-core' ),
            'items_list_navigation' => __( 'Partner Sites list navigation', 'synditracker-core' ),
            'filter_items_list'     => __( 'Filter partner sites list', 'synditracker-core' ),
        );
        $args = array(
            'label'                 => __( 'Partner Site', 'synditracker-core' ),
            'description'           => __( 'Registered Partner Sites', 'synditracker-core' ),
            'labels'                => $labels,
            'supports'              => array( 'title' ),
            'hierarchical'          => false,
            'public'                => false,
            'show_ui'               => true,
            'show_in_menu'          => 'synditracker-core-dashboard', // Submenu of our main page? Or just top level
            'menu_position'         => 5,
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => false,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'capability_type'       => 'page',
        );
        register_post_type( 'partner_site', $args );
        
        // Add meta boxes
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_partner_site' ) );
    }

    public function add_meta_boxes() {
        add_meta_box(
            'synditracker_partner_credentials',
            __( 'OAuth Credentials', 'synditracker-core' ),
            array( $this, 'render_credentials_meta_box' ),
            'partner_site',
            'normal',
            'high'
        );
    }

    public function render_credentials_meta_box( $post ) {
        // Retrieve existing values
        $client_id = get_post_meta( $post->ID, '_synditracker_client_id', true );
        $client_secret = get_post_meta( $post->ID, '_synditracker_client_secret', true );
        
        if ( empty( $client_id ) ) {
            $client_id = wp_generate_password( 32, false );
            $client_secret = wp_generate_password( 64, false );
            
            // Save immediately so we can show them? No, better to let WP save flow handle it.
            // But we want to show them now. So let's just show generated values and let save hook persist them if user saves post.
            // Actually, for better UX, let's just generate and show.
            // But if we generate new ones every time, it changes. We need to save if not exists.
            
            // Since we are in render, we shouldn't update DB. 
            echo '<p><em>Credentials will be generated and shown here after you <strong>Publish</strong> or <strong>Update</strong> the partner site.</em></p>';
        } else {
             $hub_url = home_url();
             $connection_info = json_encode( array(
                 'url' => $hub_url,
                 'client_id' => $client_id,
                 'client_secret' => $client_secret
             ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
             
             echo '<p><label><strong>Connection Info (JSON):</strong></label><br>';
             echo '<textarea id="synditracker-connection-json" class="large-text code" rows="5" readonly>' . esc_textarea( $connection_info ) . '</textarea>';
             echo '<button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText(document.getElementById(\'synditracker-connection-json\').value); alert(\'Copied!\');">Copy Connection Info</button>';
             echo '</p>';
             
             echo '<hr>';
             
             echo '<p><label><strong>Client ID:</strong></label><br><input type="text" class="widefat" value="' . esc_attr( $client_id ) . '" readonly></p>';
             echo '<p><label><strong>Client Secret:</strong></label><br><input type="text" class="widefat" value="' . esc_attr( $client_secret ) . '" readonly></p>';
        }
    }

    public function save_partner_site( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( 'partner_site' !== get_post_type( $post_id ) ) return;
        
        // Check permissions
        if ( ! current_user_can( 'edit_page', $post_id ) ) return;

        // Generate credentials if missing
        $client_id = get_post_meta( $post_id, '_synditracker_client_id', true );
        if ( empty( $client_id ) ) {
            $client_id = wp_generate_password( 32, false );
            $client_secret = wp_generate_password( 64, false ); // Secret
            
            update_post_meta( $post_id, '_synditracker_client_id', $client_id );
            update_post_meta( $post_id, '_synditracker_client_secret', $client_secret );
        }
    }
}
