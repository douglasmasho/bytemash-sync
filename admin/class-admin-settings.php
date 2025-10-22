<?php
/**
 * Admin Settings Page
 * 
 * Handles plugin settings and configuration
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_Admin_Settings {
    
    /**
     * Render settings page
     */
    public static function render_settings() {
        // Save settings if form submitted
        if (isset($_POST['bytemash_save_settings'])) {
            self::save_settings();
        }
        
        // Handle logout
        if (isset($_POST['bytemash_logout'])) {
            self::logout();
        }
        
        $api_url = get_option('bytemash_amrod_api_url', 'https://identity.amrod.co.za');
        $api_token = get_option('bytemash_amrod_api_token', '');
        $batch_size = get_option('bytemash_amrod_batch_size', 50);
        $full_sync_frequency = get_option('bytemash_full_sync_frequency', 'daily_at_0030');
        $incremental_frequency = get_option('bytemash_incremental_sync_frequency', 'every_5_hours');
        
        // Get sync status
        $scheduler = new ByteMash_Sync_Scheduler();
        $sync_status = $scheduler->get_sync_status();
        
        // Check if authenticated
        $api_client = new ByteMash_Amrod_API_Client();
        $is_authenticated = $api_client->is_authenticated();
        
        ?>
        <div class="wrap bytemash-admin-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Settings saved successfully!', 'bytemash-woo-sync'); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (!$is_authenticated) : ?>
                <!-- Authentication Form -->
                <div class="bytemash-auth-section">
                    <div class="bytemash-auth-card">
                        <div class="bytemash-auth-icon">
                            <span class="dashicons dashicons-lock"></span>
                        </div>
                        <h2><?php esc_html_e('Connect to Amrod', 'bytemash-woo-sync'); ?></h2>
                        <p><?php esc_html_e('Please authenticate with your Amrod account credentials to begin syncing products.', 'bytemash-woo-sync'); ?></p>
                        
                        <form id="bytemash_auth_form" class="bytemash-auth-form">
                            <?php wp_nonce_field('bytemash_auth_action', 'bytemash_auth_nonce'); ?>
                            
                            <div class="form-field">
                                <label for="amrod_username">
                                    <span class="dashicons dashicons-admin-users"></span>
                                    <?php esc_html_e('Amrod Username', 'bytemash-woo-sync'); ?>
                                </label>
                                <input type="text" 
                                       id="amrod_username" 
                                       name="amrod_username" 
                                       class="regular-text"
                                       placeholder="<?php esc_attr_e('Enter your Amrod username', 'bytemash-woo-sync'); ?>"
                                       required>
                            </div>
                            
                            <div class="form-field">
                                <label for="amrod_password">
                                    <span class="dashicons dashicons-admin-network"></span>
                                    <?php esc_html_e('Amrod Password', 'bytemash-woo-sync'); ?>
                                </label>
                                <input type="password" 
                                       id="amrod_password" 
                                       name="amrod_password" 
                                       class="regular-text"
                                       placeholder="<?php esc_attr_e('Enter your Amrod password', 'bytemash-woo-sync'); ?>"
                                       required>
                            </div>
                            
                            <div class="form-field">
                                <label for="customer_code">
                                    <span class="dashicons dashicons-businessman"></span>
                                    <?php esc_html_e('Customer Code', 'bytemash-woo-sync'); ?>
                                </label>
                                <input type="text" 
                                       id="customer_code" 
                                       name="customer_code" 
                                       class="regular-text"
                                       placeholder="<?php esc_attr_e('Enter your customer code (optional)', 'bytemash-woo-sync'); ?>">
                                <p class="description">
                                    <?php esc_html_e('Your Amrod customer code. Leave empty if not required.', 'bytemash-woo-sync'); ?>
                                </p>
                            </div>
                            
                            <div class="form-field">
                                <label for="api_url_auth">
                                    <span class="dashicons dashicons-admin-site"></span>
                                    <?php esc_html_e('API URL', 'bytemash-woo-sync'); ?>
                                </label>
                                <input type="url" 
                                       id="api_url_auth" 
                                       name="api_url" 
                                       value="<?php echo esc_attr($api_url); ?>" 
                                       class="regular-text">
                                <p class="description">
                                    <?php esc_html_e('Default: https://identity.amrod.co.za', 'bytemash-woo-sync'); ?>
                                </p>
                            </div>
                            
                            <div class="auth-status" id="auth_status"></div>
                            
                            <p class="submit">
                                <button type="submit" 
                                        id="btn_authenticate" 
                                        class="button button-primary button-hero">
                                    <span class="dashicons dashicons-admin-network"></span>
                                    <?php esc_html_e('Authenticate & Connect', 'bytemash-woo-sync'); ?>
                                </button>
                            </p>
                        </form>
                        
                        <div class="bytemash-help-text">
                            <p>
                                <span class="dashicons dashicons-info"></span>
                                <?php esc_html_e('Don\'t have an Amrod account?', 'bytemash-woo-sync'); ?>
                                <a href="https://www.amrod.co.za/contact-us/" target="_blank">
                                    <?php esc_html_e('Contact Amrod', 'bytemash-woo-sync'); ?>
                                </a>
                            </p>
                            <p>
                                <span class="dashicons dashicons-book"></span>
                                <a href="https://newapidocs.amrod.co.za/" target="_blank">
                                    <?php esc_html_e('View API Documentation', 'bytemash-woo-sync'); ?>
                                </a>
                            </p>
                        </div>
                    </div>
                </div>
            <?php else : ?>
                <!-- Authenticated - Show Settings -->
                
                <div class="bytemash-authenticated-header">
                    <div class="auth-success-badge">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e('Connected to Amrod', 'bytemash-woo-sync'); ?>
                    </div>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('bytemash_logout_action', 'bytemash_logout_nonce'); ?>
                        <button type="submit" name="bytemash_logout" class="button button-secondary">
                            <span class="dashicons dashicons-unlock"></span>
                            <?php esc_html_e('Disconnect', 'bytemash-woo-sync'); ?>
                        </button>
                    </form>
                </div>
            
            <form method="post" action="" class="bytemash-settings-form">
                <?php wp_nonce_field('bytemash_settings_action', 'bytemash_settings_nonce'); ?>
                
                <div class="bytemash-settings-section">
                    <h2><?php esc_html_e('Connection Info', 'bytemash-woo-sync'); ?></h2>
                    
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label><?php esc_html_e('API URL', 'bytemash-woo-sync'); ?></label>
                                </th>
                                <td>
                                    <code><?php echo esc_html($api_url); ?></code>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label><?php esc_html_e('Access Token', 'bytemash-woo-sync'); ?></label>
                                </th>
                                <td>
                                    <code><?php echo esc_html(substr($api_token, 0, 30) . '...'); ?></code>
                                    <p class="description">
                                        <?php 
                                        $expiry = get_option('bytemash_amrod_token_expiry');
                                        if ($expiry) {
                                            $time_left = $expiry - time();
                                            $hours_left = floor($time_left / 3600);
                                            echo sprintf(
                                                esc_html__('Expires in: %s hours', 'bytemash-woo-sync'),
                                                $hours_left
                                            );
                                        }
                                        ?>
                                    </p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label><?php esc_html_e('Connection Status', 'bytemash-woo-sync'); ?></label>
                                </th>
                                <td>
                                    <span class="bytemash-status-badge-small active">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                        <?php esc_html_e('Connected', 'bytemash-woo-sync'); ?>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="bytemash-settings-section">
                    <h2><?php esc_html_e('Sync Configuration', 'bytemash-woo-sync'); ?></h2>
                    
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="batch_size"><?php esc_html_e('Batch Size', 'bytemash-woo-sync'); ?></label>
                                </th>
                                <td>
                                    <input type="number" 
                                           id="batch_size" 
                                           name="batch_size" 
                                           value="<?php echo esc_attr($batch_size); ?>" 
                                           min="10" 
                                           max="200" 
                                           step="10"
                                           class="small-text">
                                    <p class="description">
                                        <?php esc_html_e('Number of products to sync per batch (10-200). Lower numbers use less memory but take longer.', 'bytemash-woo-sync'); ?>
                                    </p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="full_sync_frequency"><?php esc_html_e('Full Sync Schedule', 'bytemash-woo-sync'); ?></label>
                                </th>
                                <td>
                                    <select id="full_sync_frequency" name="full_sync_frequency" class="regular-text">
                                        <option value="daily_at_0030" <?php selected($full_sync_frequency, 'daily_at_0030'); ?>>
                                            <?php esc_html_e('Daily at 00:30 GMT+2 (Recommended)', 'bytemash-woo-sync'); ?>
                                        </option>
                                        <option value="daily" <?php selected($full_sync_frequency, 'daily'); ?>>
                                            <?php esc_html_e('Daily', 'bytemash-woo-sync'); ?>
                                        </option>
                                        <option value="twicedaily" <?php selected($full_sync_frequency, 'twicedaily'); ?>>
                                            <?php esc_html_e('Twice Daily', 'bytemash-woo-sync'); ?>
                                        </option>
                                        <option value="weekly" <?php selected($full_sync_frequency, 'weekly'); ?>>
                                            <?php esc_html_e('Weekly', 'bytemash-woo-sync'); ?>
                                        </option>
                                        <option value="manual" <?php selected($full_sync_frequency, 'manual'); ?>>
                                            <?php esc_html_e('Manual Only', 'bytemash-woo-sync'); ?>
                                        </option>
                                    </select>
                                    <p class="description">
                                        <?php esc_html_e('Full sync clears and repopulates all data. Recommended: Daily at 00:30 GMT+2 as per Amrod API documentation.', 'bytemash-woo-sync'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="incremental_frequency"><?php esc_html_e('Incremental Sync Schedule', 'bytemash-woo-sync'); ?></label>
                                </th>
                                <td>
                                    <select id="incremental_frequency" name="incremental_frequency" class="regular-text">
                                        <option value="every_5_hours" <?php selected($incremental_frequency, 'every_5_hours'); ?>>
                                            <?php esc_html_e('Every 5 Hours (Recommended)', 'bytemash-woo-sync'); ?>
                                        </option>
                                        <option value="hourly" <?php selected($incremental_frequency, 'hourly'); ?>>
                                            <?php esc_html_e('Every Hour', 'bytemash-woo-sync'); ?>
                                        </option>
                                        <option value="every_6_hours" <?php selected($incremental_frequency, 'every_6_hours'); ?>>
                                            <?php esc_html_e('Every 6 Hours', 'bytemash-woo-sync'); ?>
                                        </option>
                                        <option value="every_12_hours" <?php selected($incremental_frequency, 'every_12_hours'); ?>>
                                            <?php esc_html_e('Every 12 Hours', 'bytemash-woo-sync'); ?>
                                        </option>
                                        <option value="twicedaily" <?php selected($incremental_frequency, 'twicedaily'); ?>>
                                            <?php esc_html_e('Twice Daily', 'bytemash-woo-sync'); ?>
                                        </option>
                                        <option value="manual" <?php selected($incremental_frequency, 'manual'); ?>>
                                            <?php esc_html_e('Manual Only', 'bytemash-woo-sync'); ?>
                                        </option>
                                    </select>
                                    <p class="description">
                                        <?php esc_html_e('Incremental sync only processes changes since the last full sync. Only runs if full sync completed today.', 'bytemash-woo-sync'); ?>
                                    </p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row"><?php esc_html_e('Sync Status', 'bytemash-woo-sync'); ?></th>
                                <td>
                                    <div class="sync-status-info">
                                        <div class="sync-status-grid">
                                            <div class="sync-status-item">
                                                <strong><?php esc_html_e('Last Full Sync:', 'bytemash-woo-sync'); ?></strong>
                                                <span><?php echo esc_html($sync_status['last_sync_times']['last_full_sync']); ?></span>
                                                <?php if ($sync_status['full_sync_running']) : ?>
                                                    <span class="status-running">🔄 <?php esc_html_e('Running', 'bytemash-woo-sync'); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="sync-status-item">
                                                <strong><?php esc_html_e('Last Incremental Sync:', 'bytemash-woo-sync'); ?></strong>
                                                <span><?php echo esc_html($sync_status['last_sync_times']['last_incremental_sync']); ?></span>
                                                <?php if ($sync_status['incremental_sync_running']) : ?>
                                                    <span class="status-running">🔄 <?php esc_html_e('Running', 'bytemash-woo-sync'); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="sync-status-item">
                                                <strong><?php esc_html_e('Next Full Sync:', 'bytemash-woo-sync'); ?></strong>
                                                <span><?php echo esc_html($sync_status['next_full_sync']); ?></span>
                                            </div>
                                            
                                            <div class="sync-status-item">
                                                <strong><?php esc_html_e('Next Incremental Sync:', 'bytemash-woo-sync'); ?></strong>
                                                <span><?php echo esc_html($sync_status['next_incremental_sync']); ?></span>
                                            </div>
                                        </div>
                                        
                                        <div class="sync-status-actions">
                                            <button type="button" id="refresh_sync_status" class="button button-secondary">
                                                <span class="dashicons dashicons-update"></span>
                                                <?php esc_html_e('Refresh Status', 'bytemash-woo-sync'); ?>
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            
                        </tbody>
                    </table>
                </div>
                
                <div class="bytemash-settings-section">
                    <h2><?php esc_html_e('Advanced Settings', 'bytemash-woo-sync'); ?></h2>
                    
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="log_retention"><?php esc_html_e('Log Retention', 'bytemash-woo-sync'); ?></label>
                                </th>
                                <td>
                                    <input type="number" 
                                           id="log_retention" 
                                           name="log_retention" 
                                           value="<?php echo esc_attr(get_option('bytemash_log_retention_days', 30)); ?>" 
                                           min="7" 
                                           max="365"
                                           class="small-text">
                                    <span><?php esc_html_e('days', 'bytemash-woo-sync'); ?></span>
                                    <p class="description">
                                        <?php esc_html_e('Number of days to keep sync logs before automatic cleanup.', 'bytemash-woo-sync'); ?>
                                    </p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label><?php esc_html_e('Clear Logs', 'bytemash-woo-sync'); ?></label>
                                </th>
                                <td>
                                    <button type="button" class="button button-secondary" id="clear_logs">
                                        <?php esc_html_e('Clear All Logs', 'bytemash-woo-sync'); ?>
                                    </button>
                                    <p class="description">
                                        <?php esc_html_e('Permanently delete all sync logs. This cannot be undone.', 'bytemash-woo-sync'); ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <p class="submit">
                    <input type="submit" 
                           name="bytemash_save_settings" 
                           id="submit" 
                           class="button button-primary" 
                           value="<?php esc_attr_e('Save Settings', 'bytemash-woo-sync'); ?>">
                </p>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Logout / Disconnect
     */
    private static function logout() {
        // Verify nonce
        if (!isset($_POST['bytemash_logout_nonce']) || 
            !wp_verify_nonce($_POST['bytemash_logout_nonce'], 'bytemash_logout_action')) {
            wp_die(esc_html__('Security check failed', 'bytemash-woo-sync'));
        }
        
        // Clear token and related data
        delete_option('bytemash_amrod_api_token');
        delete_option('bytemash_amrod_refresh_token');
        delete_option('bytemash_amrod_token_expiry');
        
        // Clear stored credentials for automatic token refresh
        delete_option('bytemash_amrod_username');
        delete_option('bytemash_amrod_password');
        delete_option('bytemash_amrod_customer_code');
        
        // Log the logout
        $logger = new ByteMash_Logger();
        $logger->log('info', 'User disconnected from Amrod - credentials cleared', array('user' => get_current_user_id()), 'authentication');
        
        // Redirect
        wp_redirect(admin_url('admin.php?page=bytemash-amrod-settings'));
        exit;
    }
    
    /**
     * Save settings
     */
    private static function save_settings() {
        // Verify nonce
        if (!isset($_POST['bytemash_settings_nonce']) || 
            !wp_verify_nonce($_POST['bytemash_settings_nonce'], 'bytemash_settings_action')) {
            wp_die(esc_html__('Security check failed', 'bytemash-woo-sync'));
        }
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Insufficient permissions', 'bytemash-woo-sync'));
        }
        
        // Save API settings
        if (isset($_POST['api_url'])) {
            update_option('bytemash_amrod_api_url', sanitize_text_field($_POST['api_url']));
        }
        
        if (isset($_POST['api_token'])) {
            update_option('bytemash_amrod_api_token', sanitize_text_field($_POST['api_token']));
        }
        
        // Save sync settings
        if (isset($_POST['batch_size'])) {
            $batch_size = (int) $_POST['batch_size'];
            $batch_size = max(10, min(200, $batch_size));
            update_option('bytemash_amrod_batch_size', $batch_size);
        }
        
        // Save new sync schedule settings
        $full_sync_frequency = 'daily_at_0030';
        $incremental_frequency = 'every_5_hours';
        
        if (isset($_POST['full_sync_frequency'])) {
            $full_sync_frequency = sanitize_text_field($_POST['full_sync_frequency']);
            update_option('bytemash_full_sync_frequency', $full_sync_frequency);
        }
        
        if (isset($_POST['incremental_frequency'])) {
            $incremental_frequency = sanitize_text_field($_POST['incremental_frequency']);
            update_option('bytemash_incremental_sync_frequency', $incremental_frequency);
        }
        
        // Update cron schedules
        $scheduler = new ByteMash_Sync_Scheduler();
        $scheduler->update_schedule($full_sync_frequency, $incremental_frequency);
        
        // Save advanced settings
        if (isset($_POST['log_retention'])) {
            $retention = (int) $_POST['log_retention'];
            $retention = max(7, min(365, $retention));
            update_option('bytemash_log_retention_days', $retention);
        }
        
        // Log the settings update
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Settings updated', array('user' => get_current_user_id()), 'settings');
        
        // Redirect with success message
        wp_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
        exit;
    }
}

