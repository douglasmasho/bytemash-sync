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
        $force_buttons = get_option('bytemash_force_product_buttons', false);
        $show_dimensions = get_option('bytemash_show_dimension_details', true);
        $full_sync_frequency = get_option('bytemash_full_sync_frequency', 'daily_at_0130');
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
            
                <!-- Shortcodes Section - Only visible when authenticated -->
                <div class="bytemash-settings-section" style="margin-bottom: 30px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2 style="margin-top: 0;"><?php esc_html_e('📋 Available Shortcodes', 'bytemash-woo-sync'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Use these shortcodes in your product pages, widgets, or any content area to display Amrod product information.', 'bytemash-woo-sync'); ?>
                    </p>
                    
                    <table class="form-table" style="margin-top: 15px;">
                        <tbody>
                            <tr>
                                <th scope="row" style="width: 200px;">
                                    <code style="background: #f0f0f1; padding: 5px 10px; border-radius: 3px; font-size: 14px;">[amrod_brand_logo]</code>
                                </th>
                                <td>
                                    <p style="margin: 5px 0;"><strong><?php esc_html_e('Brand Logo:', 'bytemash-woo-sync'); ?></strong> <?php esc_html_e('Displays the brand logo for the current product. The logo is fetched from the brand sync data.', 'bytemash-woo-sync'); ?></p>
                                    <p style="margin: 5px 0; color: #646970;"><em><?php esc_html_e('Auto-displayed on product pages by default.', 'bytemash-woo-sync'); ?></em></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <code style="background: #f0f0f1; padding: 5px 10px; border-radius: 3px; font-size: 14px;">[amrod_color_swatches]</code>
                                </th>
                                <td>
                                    <p style="margin: 5px 0;"><strong><?php esc_html_e('Color Swatches:', 'bytemash-woo-sync'); ?></strong> <?php esc_html_e('Displays a row of color swatch circles showing all available colors for the product. Colors are displayed using hex values from the color swatches sync.', 'bytemash-woo-sync'); ?></p>
                                    <p style="margin: 5px 0; color: #646970;"><em><?php esc_html_e('Auto-displayed on product pages by default.', 'bytemash-woo-sync'); ?></em></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <code style="background: #f0f0f1; padding: 5px 10px; border-radius: 3px; font-size: 14px;">[amrod_gender]</code>
                                </th>
                                <td>
                                    <p style="margin: 5px 0;"><strong><?php esc_html_e('Product Gender:', 'bytemash-woo-sync'); ?></strong> <?php esc_html_e('Displays the product gender (e.g., Men, Women, Unisex).', 'bytemash-woo-sync'); ?></p>
                                    <p style="margin: 5px 0; color: #646970;"><em><?php esc_html_e('Auto-displayed on product pages by default.', 'bytemash-woo-sync'); ?></em></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <code style="background: #f0f0f1; padding: 5px 10px; border-radius: 3px; font-size: 14px;">[amrod_total_stock]</code>
                                </th>
                                <td>
                                    <p style="margin: 5px 0;"><strong><?php esc_html_e('Total Stock:', 'bytemash-woo-sync'); ?></strong> <?php esc_html_e('Displays the total stock quantity (sum of all variations) and total incoming stock for the product. Only shows if stock data is available.', 'bytemash-woo-sync'); ?></p>
                                    <p style="margin: 5px 0; color: #646970;"><em><?php esc_html_e('Auto-displayed on product pages by default. For variable products, calculates the sum of stock from all variations.', 'bytemash-woo-sync'); ?></em></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <code style="background: #f0f0f1; padding: 5px 10px; border-radius: 3px; font-size: 14px;">[amrod_before_title]</code>
                                </th>
                                <td>
                                    <p style="margin: 5px 0;"><strong><?php esc_html_e('WooCommerce Hook:', 'bytemash-woo-sync'); ?></strong> <?php esc_html_e('Outputs WooCommerce hook content for use in page builders like Bricks.', 'bytemash-woo-sync'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div class="notice notice-info" style="margin-top: 20px; margin-bottom: 0;">
                        <p style="margin: 0;">
                            <strong>ℹ️ <?php esc_html_e('Note:', 'bytemash-woo-sync'); ?></strong>
                            <?php esc_html_e('Most shortcodes are automatically displayed on product pages by default. Use the shortcodes in custom locations (widgets, custom templates, page builders) if you need more control over placement.', 'bytemash-woo-sync'); ?>
                        </p>
                    </div>
                </div>
            
            <form method="post" action="" class="bytemash-settings-form">
                <?php wp_nonce_field('bytemash_settings_action', 'bytemash_settings_nonce'); ?>
                
                <div class="bytemash-settings-section">
                    <h2><?php esc_html_e('Connection Info', 'bytemash-woo-sync'); ?></h2>
                    
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="force_buttons"><?php esc_html_e('Force Product Buttons', 'bytemash-woo-sync'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="force_buttons" id="force_buttons" <?php checked($force_buttons, true); ?> />
                                        <?php esc_html_e('Always add Branding Guides and Stock buttons on product pages (bypass theme templates).', 'bytemash-woo-sync'); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e('If your theme/page builder does not render standard WooCommerce hooks, enable this to append the buttons regardless of theme.', 'bytemash-woo-sync'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="show_dimension_details"><?php esc_html_e('Show Dimension Details', 'bytemash-woo-sync'); ?></label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="show_dimension_details" id="show_dimension_details" value="1" <?php checked($show_dimensions, true); ?> />
                                        <?php esc_html_e('Display Amrod dimension and packaging details within the product tab area.', 'bytemash-woo-sync'); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e('When enabled, the Branding Guide tab will include product and packaging dimensions sourced from the latest sync response.', 'bytemash-woo-sync'); ?>
                                    </p>
                                </td>
                            </tr>
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
                                        <option value="daily_at_0130" <?php selected($full_sync_frequency, 'daily_at_0130'); ?>>
                                            <?php esc_html_e('Daily at 01:30 GMT+2 (Recommended)', 'bytemash-woo-sync'); ?>
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
                                        <?php esc_html_e('Full sync clears and repopulates all data. Recommended: Daily at 01:30 GMT+2 (avoids API downtime 00:00-01:00 GMT+2).', 'bytemash-woo-sync'); ?>
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
                                <th scope="row">
                                    <label><?php esc_html_e('Sync Attributes', 'bytemash-woo-sync'); ?></label>
                                </th>
                                <td>
                                    <?php
                                    $sync_products = get_option('bytemash_sync_products', true);
                                    $sync_stock = get_option('bytemash_sync_stock', true);
                                    $sync_prices = get_option('bytemash_sync_prices', true);
                                    $sync_categories = get_option('bytemash_sync_categories', true);
                                    $sync_brands = get_option('bytemash_sync_brands', true);
                                    ?>
                                    <div class="sync-attributes-grid">
                                        <div class="sync-attribute-item">
                                            <label>
                                                <input type="checkbox" 
                                                       name="sync_products" 
                                                       value="1" 
                                                       <?php checked($sync_products, true); ?>>
                                                <strong><?php esc_html_e('Products', 'bytemash-woo-sync'); ?></strong>
                                                <span class="description"><?php esc_html_e('Product data, images, descriptions', 'bytemash-woo-sync'); ?></span>
                                            </label>
                                        </div>
                                        
                                        <div class="sync-attribute-item">
                                            <label>
                                                <input type="checkbox" 
                                                       name="sync_stock" 
                                                       value="1" 
                                                       <?php checked($sync_stock, true); ?>>
                                                <strong><?php esc_html_e('Stock Levels', 'bytemash-woo-sync'); ?></strong>
                                                <span class="description"><?php esc_html_e('Inventory quantities and availability', 'bytemash-woo-sync'); ?></span>
                                            </label>
                                        </div>
                                        
                                        <div class="sync-attribute-item">
                                            <label>
                                                <input type="checkbox" 
                                                       name="sync_prices" 
                                                       value="1" 
                                                       <?php checked($sync_prices, true); ?>>
                                                <strong><?php esc_html_e('Prices', 'bytemash-woo-sync'); ?></strong>
                                                <span class="description"><?php esc_html_e('Product pricing and discounts', 'bytemash-woo-sync'); ?></span>
                                            </label>
                                        </div>
                                        
                                        <div class="sync-attribute-item">
                                            <label>
                                                <input type="checkbox" 
                                                       name="sync_categories" 
                                                       value="1" 
                                                       <?php checked($sync_categories, true); ?>>
                                                <strong><?php esc_html_e('Categories', 'bytemash-woo-sync'); ?></strong>
                                                <span class="description"><?php esc_html_e('Product categories and hierarchy', 'bytemash-woo-sync'); ?></span>
                                            </label>
                                        </div>
                                        
                                        <div class="sync-attribute-item">
                                            <label>
                                                <input type="checkbox" 
                                                       name="sync_brands" 
                                                       value="1" 
                                                       <?php checked($sync_brands, true); ?>>
                                                <strong><?php esc_html_e('Brands', 'bytemash-woo-sync'); ?></strong>
                                                <span class="description"><?php esc_html_e('Brand information and attributes', 'bytemash-woo-sync'); ?></span>
                                            </label>
                                        </div>
                                    </div>
                                    <p class="description">
                                        <?php esc_html_e('Select which attributes to sync during scheduled syncs. Unchecking an attribute will skip it during both full and incremental syncs.', 'bytemash-woo-sync'); ?>
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
                            
                            <tr>
                                <th scope="row"><?php esc_html_e('Test Mode Controls', 'bytemash-woo-sync'); ?></th>
                                <td>
                                    <div class="test-mode-controls">
                                        <?php 
                                        $full_test_mode = get_option('bytemash_cron_full_test_mode_enabled', false);
                                        $incremental_test_mode = get_option('bytemash_cron_incremental_test_mode_enabled', false);
                                        $active_method = self::get_active_cron_method();
                                        ?>
                                        
                                        <div class="test-mode-status">
                                            <span class="cron-method-badge cron-method-<?php echo esc_attr($active_method); ?>">
                                                <?php echo esc_html(self::get_method_display_name($active_method)); ?>
                                            </span>
                                        </div>
                                        
                                        <!-- Full Sync Test Mode -->
                                        <div class="test-mode-section">
                                            <h4><?php esc_html_e('Full Sync Test Mode', 'bytemash-woo-sync'); ?></h4>
                                            <div class="test-mode-item">
                                                <span class="test-mode-badge <?php echo $full_test_mode ? 'enabled' : 'disabled'; ?>">
                                                    <?php echo $full_test_mode ? __('Enabled', 'bytemash-woo-sync') : __('Disabled', 'bytemash-woo-sync'); ?>
                                                </span>
                                                <button type="button" id="toggle-full-test-mode" class="button <?php echo $full_test_mode ? 'button-secondary' : 'button-primary'; ?>">
                                                    <?php echo $full_test_mode ? __('Disable Full Test Mode', 'bytemash-woo-sync') : __('Enable Full Test Mode', 'bytemash-woo-sync'); ?>
                                                </button>
                                            </div>
                                            <div id="full-test-mode-status"></div>
                                            <p class="description">
                                                <?php esc_html_e('Runs full sync in 2 minutes when enabled using system cron (not dependent on website traffic). Disables production full sync schedule.', 'bytemash-woo-sync'); ?>
                                            </p>
                                        </div>
                                        
                                        <!-- Incremental Sync Test Mode -->
                                        <div class="test-mode-section">
                                            <h4><?php esc_html_e('Incremental Sync Test Mode', 'bytemash-woo-sync'); ?></h4>
                                            <div class="test-mode-item">
                                                <span class="test-mode-badge <?php echo $incremental_test_mode ? 'enabled' : 'disabled'; ?>">
                                                    <?php echo $incremental_test_mode ? __('Enabled', 'bytemash-woo-sync') : __('Disabled', 'bytemash-woo-sync'); ?>
                                                </span>
                                                <button type="button" id="toggle-incremental-test-mode" class="button <?php echo $incremental_test_mode ? 'button-secondary' : 'button-primary'; ?>">
                                                    <?php echo $incremental_test_mode ? __('Disable Incremental Test Mode', 'bytemash-woo-sync') : __('Enable Incremental Test Mode', 'bytemash-woo-sync'); ?>
                                                </button>
                                            </div>
                                            <div id="incremental-test-mode-status"></div>
                                            <p class="description">
                                                <?php esc_html_e('Runs incremental sync every 5 minutes when enabled using system cron (not dependent on website traffic). Disables production incremental sync schedule.', 'bytemash-woo-sync'); ?>
                                            </p>
                                        </div>
                                        
                                        <!-- System Cron (Individual) -->
                                        <div class="test-mode-section">
                                            <h4><?php esc_html_e('System Cron Only', 'bytemash-woo-sync'); ?></h4>
                                            <div class="test-mode-item">
                                                <span class="test-mode-badge <?php echo get_option('bytemash_cron_system_cron_enabled', false) ? 'enabled' : 'disabled'; ?>">
                                                    <?php echo get_option('bytemash_cron_system_cron_enabled', false) ? __('Enabled', 'bytemash-woo-sync') : __('Disabled', 'bytemash-woo-sync'); ?>
                                                </span>
                                                <button type="button" id="enable-system-cron" class="button" <?php echo get_option('bytemash_cron_system_cron_enabled', false) ? 'disabled' : ''; ?>>
                                                    <?php esc_html_e('Enable System Cron', 'bytemash-woo-sync'); ?>
                                                </button>
                                            </div>
                                            <div id="system-cron-status"></div>
                                            <p class="description">
                                                <?php esc_html_e('Only enables system cron script (requires manual crontab setup).', 'bytemash-woo-sync'); ?>
                                            </p>
                                        </div>
                                        
                                        <!-- Emergency Stop -->
                                        <div class="test-mode-section emergency-section">
                                            <h4><?php esc_html_e('Emergency Stop', 'bytemash-woo-sync'); ?></h4>
                                            <div class="test-mode-item">
                                                <button type="button" id="emergency-stop-syncs" class="button button-secondary" style="background: #dc3545; color: white; border-color: #dc3545;">
                                                    <span class="dashicons dashicons-no"></span>
                                                    <?php esc_html_e('Stop All Running Syncs', 'bytemash-woo-sync'); ?>
                                                </button>
                                            </div>
                                            <div id="emergency-stop-status"></div>
                                            <p class="description">
                                                <?php esc_html_e('Immediately stops all running sync operations as a failsafe measure.', 'bytemash-woo-sync'); ?>
                                            </p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            
                            <!-- Production Cron Section -->
                            <tr>
                                <th scope="row"><?php esc_html_e('Production Cron', 'bytemash-woo-sync'); ?></th>
                                <td>
                                    <div class="production-system-controls">
                                        <div class="production-system-section">
                                            <h4><?php esc_html_e('Production Sync Schedules', 'bytemash-woo-sync'); ?></h4>
                                            <div class="test-mode-item">
                                                <span class="test-mode-badge <?php echo (wp_next_scheduled('bytemash_full_sync_cron') || wp_next_scheduled('bytemash_incremental_sync_cron')) ? 'enabled' : 'disabled'; ?>">
                                                    <?php echo (wp_next_scheduled('bytemash_full_sync_cron') || wp_next_scheduled('bytemash_incremental_sync_cron')) ? __('Enabled', 'bytemash-woo-sync') : __('Disabled', 'bytemash-woo-sync'); ?>
                                                </span>
                                                <button type="button" id="enable-production-cron" class="button button-primary">
                                                    <?php esc_html_e('Enable Production Cron', 'bytemash-woo-sync'); ?>
                                                </button>
                                            </div>
                                            <div id="production-cron-status"></div>
                                            <p class="description">
                                                <?php esc_html_e('Enables production sync schedules (daily full sync, every 5 hours incremental).', 'bytemash-woo-sync'); ?>
                                            </p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            
                            <!-- Production System Cron Section -->
                            <tr>
                                <th scope="row"><?php esc_html_e('System Cron', 'bytemash-woo-sync'); ?></th>
                                <td>
                                    <div class="production-system-controls">
                                        <div class="production-system-section">
                                            <h4><?php esc_html_e('Reliable System Cron', 'bytemash-woo-sync'); ?></h4>
                                            <div class="test-mode-item">
                                                <span class="test-mode-badge <?php echo (get_option('bytemash_cron_system_cron_enabled', false) && (wp_next_scheduled('bytemash_full_sync_cron') || wp_next_scheduled('bytemash_incremental_sync_cron'))) ? 'enabled' : 'disabled'; ?>">
                                                    <?php echo (get_option('bytemash_cron_system_cron_enabled', false) && (wp_next_scheduled('bytemash_full_sync_cron') || wp_next_scheduled('bytemash_incremental_sync_cron'))) ? __('Enabled', 'bytemash-woo-sync') : __('Disabled', 'bytemash-woo-sync'); ?>
                                                </span>
                                                <button type="button" id="enable-production-system-cron" class="button button-primary" <?php echo (get_option('bytemash_cron_system_cron_enabled', false) && (wp_next_scheduled('bytemash_full_sync_cron') || wp_next_scheduled('bytemash_incremental_sync_cron'))) ? 'disabled' : ''; ?>>
                                                    <?php esc_html_e('Enable Reliable System Cron', 'bytemash-woo-sync'); ?>
                                                </button>
                                            </div>
                                            <div id="production-system-cron-status"></div>
                                            <p class="description">
                                                <?php esc_html_e('Enables production schedules with system cron for maximum reliability. Does NOT depend on website traffic.', 'bytemash-woo-sync'); ?>
                                            </p>
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
                                    <label for="quote_mode_enabled"><?php esc_html_e('Quote Mode', 'bytemash-woo-sync'); ?></label>
                                </th>
                                <td>
                                    <?php 
                                    $quote_mode_enabled = get_option('bytemash_quote_mode_enabled', false);
                                    ?>
                                    <label>
                                        <input type="checkbox" name="quote_mode_enabled" id="quote_mode_enabled" value="1" <?php checked($quote_mode_enabled, true); ?>>
                                        <?php esc_html_e('Enable Quote Mode', 'bytemash-woo-sync'); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e('When enabled, replaces the normal ordering flow with a custom quote request form. The form includes color, size, branding options, and quantity selection. All products will use quote requests instead of regular orders.', 'bytemash-woo-sync'); ?>
                                    </p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="allow_orders_without_price"><?php esc_html_e('Allow Orders Without Price', 'bytemash-woo-sync'); ?></label>
                                </th>
                                <td>
                                    <?php 
                                    $purchasability_mode = get_option('bytemash_allow_orders_without_price', 'force_with_stock');
                                    ?>
                                    <select name="allow_orders_without_price" id="allow_orders_without_price" style="width: 100%; max-width: 400px;">
                                        <option value="default" <?php selected($purchasability_mode, 'default'); ?>>
                                            <?php esc_html_e('Default: Only allow orders if price exists', 'bytemash-woo-sync'); ?>
                                        </option>
                                        <option value="force_with_stock" <?php selected($purchasability_mode, 'force_with_stock'); ?>>
                                            <?php esc_html_e('Force allow orders for products with stock quantity > 0', 'bytemash-woo-sync'); ?>
                                        </option>
                                        <option value="force_all" <?php selected($purchasability_mode, 'force_all'); ?>>
                                            <?php esc_html_e('Force allow orders for ALL products (even without price or stock)', 'bytemash-woo-sync'); ?>
                                        </option>
                                    </select>
                                    <p class="description">
                                        <?php esc_html_e('Control whether products can be ordered without prices. "Force all" will allow ordering for ALL products regardless of price or stock. "Force with stock" only allows orders for products that have stock quantity > 0. "Default" follows standard WooCommerce behavior (requires price).', 'bytemash-woo-sync'); ?>
                                    </p>
                                </td>
                            </tr>
                            
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
        
        // Save sync attributes
        $sync_attributes = array(
            'bytemash_sync_products' => isset($_POST['sync_products']),
            'bytemash_sync_stock' => isset($_POST['sync_stock']),
            'bytemash_sync_prices' => isset($_POST['sync_prices']),
            'bytemash_sync_categories' => isset($_POST['sync_categories']),
            'bytemash_sync_brands' => isset($_POST['sync_brands']),
        );
        
        foreach ($sync_attributes as $option_name => $value) {
            update_option($option_name, $value);
        }
        
        // Save quote mode setting
        $quote_mode_enabled = isset($_POST['quote_mode_enabled']) && $_POST['quote_mode_enabled'] === '1';
        update_option('bytemash_quote_mode_enabled', $quote_mode_enabled);
        
        // Save purchasability setting
        if (isset($_POST['allow_orders_without_price'])) {
            $purchasability_mode = sanitize_text_field($_POST['allow_orders_without_price']);
            // Validate mode
            if (!in_array($purchasability_mode, array('default', 'force_with_stock', 'force_all'))) {
                $purchasability_mode = 'default';
            }
            update_option('bytemash_allow_orders_without_price', $purchasability_mode);
        }
        
        // Save new sync schedule settings
        $full_sync_frequency = 'daily_at_0130';
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

        // Save UI options
        update_option('bytemash_force_product_buttons', isset($_POST['force_buttons']));
        update_option('bytemash_show_dimension_details', isset($_POST['show_dimension_details']));
        
        // Log the settings update
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Settings updated', array('user' => get_current_user_id()), 'settings');
        
        // Redirect with success message
        wp_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
        exit;
    }
    
    /**
     * Get active cron method
     */
    private static function get_active_cron_method() {
        if (get_option('bytemash_cron_system_cron_enabled', false)) {
            return 'system_cron';
        }
        
        if (get_option('bytemash_cron_hosted_pinger_enabled', false)) {
            return 'hosted_pinger';
        }
        
        if (get_option('bytemash_cron_self_ping_enabled', true)) {
            return 'self_ping';
        }
        
        return 'none';
    }
    
    /**
     * Get method display name
     */
    private static function get_method_display_name($method) {
        $names = array(
            'system_cron' => __('System Cron', 'bytemash-woo-sync'),
            'hosted_pinger' => __('Hosted Pinger', 'bytemash-woo-sync'),
            'self_ping' => __('Self-Ping', 'bytemash-woo-sync'),
            'none' => __('None', 'bytemash-woo-sync'),
        );
        
        return isset($names[$method]) ? $names[$method] : $method;
    }
}

