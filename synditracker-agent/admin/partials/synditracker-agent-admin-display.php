<?php

/**
 * Provide a view for the plugin settings.
 */
?>
<div class="wrap">
    <h1>Synditracker Agent Settings</h1>
    <form method="post" action="options.php">
        <?php
            settings_fields( 'synditracker_agent_options' );
            do_settings_sections( 'synditracker_agent_options' );
        ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Hub URL</th>
                <td><input type="text" name="synditracker_agent_hub_url" value="<?php echo esc_attr( get_option('synditracker_agent_hub_url') ); ?>" class="regular-text" /></td>
            </tr>
             <tr valign="top">
                <th scope="row">Client ID</th>
                <td><input type="text" name="synditracker_agent_client_id" value="<?php echo esc_attr( get_option('synditracker_agent_client_id') ); ?>" class="regular-text" /></td>
            </tr>
             <tr valign="top">
                <th scope="row">Client Secret</th>
                <td><input type="password" name="synditracker_agent_client_secret" value="<?php echo esc_attr( get_option('synditracker_agent_client_secret') ); ?>" class="regular-text" /></td>
            </tr>
            <tr valign="top">
                <th scope="row">Compatibility Mode</th>
                <td>
                    <label>
                        <input type="checkbox" name="synditracker_agent_compatibility_mode" value="1" <?php checked( 1, get_option( 'synditracker_agent_compatibility_mode' ), true ); ?> />
                        Enable environment optimizations (increases memory limit, execution time, and disables caching) during plugin operations.
                    </label>
                </td>
            </tr>
        </table>
        
        <?php submit_button(); ?>
    </form>
    
    <hr>
    
    <h2>Connection Setup</h2>
    <p>Paste the <strong>Connection Info</strong> JSON provided by the Hub administrator below to auto-fill the settings.</p>
    <textarea id="synditracker-connection-info" rows="5" class="large-text code" placeholder='{"url": "...", "client_id": "...", "client_secret": "..."}'></textarea>
    <button type="button" id="synditracker-parse-info" class="button button-secondary" style="margin-top: 10px;">Auto-Fill Settings</button>
    <span id="synditracker-parse-result" style="margin-left: 10px;"></span>

    <hr>

    <h2>Diagnostics</h2>
    <p>
        <button type="button" id="synditracker-test-connection" class="button button-secondary">Test Connection</button>
        <span id="synditracker-connection-result" style="margin-left: 10px;"></span>
    </p>

    <hr>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Auto-fill Logic
        $('#synditracker-parse-info').click(function() {
            var info = $('#synditracker-connection-info').val();
            try {
                // Handle potential extra whitespace or common copy-paste issues
                var data = JSON.parse(info);
                
                if (data.url) $('input[name="synditracker_agent_hub_url"]').val(data.url);
                if (data.client_id) $('input[name="synditracker_agent_client_id"]').val(data.client_id);
                if (data.client_secret) $('input[name="synditracker_agent_client_secret"]').val(data.client_secret);
                
                $('#synditracker-parse-result').html('<span style="color:green; font-weight:bold;">Settings filled! Don\'t forget to Save Changes.</span>');
            } catch (e) {
                $('#synditracker-parse-result').html('<span style="color:red; font-weight:bold;">Invalid JSON format.</span>');
            }
        });

        // Test Connection Logic
        $('#synditracker-test-connection').click(function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $result = $('#synditracker-connection-result');
            
            $btn.prop('disabled', true).text('Testing...');
            $result.empty();
            
            var data = {
                'action': 'synditracker_test_connection',
                'nonce': '<?php echo wp_create_nonce( "synditracker_test_connection" ); ?>'
            };

            $.post(ajaxurl, data, function(response) {
                $btn.prop('disabled', false).text('Test Connection');
                
                if(response.success) {
                     $result.html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>');
                } else {
                     $result.html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>');
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('Test Connection');
                $result.html('<div class="notice notice-error inline"><p>Request failed. Please check your network connection.</p></div>');
            });
        });
    });
    </script>
</div>
