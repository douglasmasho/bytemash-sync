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
        
        // Delete Amrod categories if requested
        if (isset($_POST['bytemash_delete_categories'])) {
            self::delete_amrod_categories();
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
            
            <?php if (isset($_GET['categories-deleted']) && $_GET['categories-deleted'] === 'true') : ?>
                <div class="notice notice-warning is-dismissible">
                    <p><?php esc_html_e('All Amrod-synced categories have been deleted.', 'bytemash-woo-sync'); ?></p>
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
                                    <code style="background: #f0f0f1; padding: 5px 10px; border-radius: 3px; font-size: 14px;">[amrod_category_filter]</code>
                                </th>
                                <td>
                                    <p style="margin: 5px 0;"><strong><?php esc_html_e('Category Filter:', 'bytemash-woo-sync'); ?></strong> <?php esc_html_e('Displays a filter widget showing relevant subcategories based on the current page context. On category pages, shows child categories. On product pages, shows sibling categories. On shop pages, shows top-level categories.', 'bytemash-woo-sync'); ?></p>
                                    <p style="margin: 5px 0; color: #646970;"><em><?php esc_html_e('Perfect for sidebars and category archive pages. Attributes: title (filter title), show_count (show product counts), hide_empty (hide empty categories).', 'bytemash-woo-sync'); ?></em></p>
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
                                    <code style="background: #f0f0f1; padding: 5px 10px; border-radius: 3px; font-size: 14px;">[amrod_category_filter]</code>
                                </th>
                                <td>
                                    <p style="margin: 5px 0;"><strong><?php esc_html_e('Category Filter:', 'bytemash-woo-sync'); ?></strong> <?php esc_html_e('Displays a filter widget showing relevant subcategories based on the current page context. On category pages, shows child categories. On product pages, shows sibling categories. On shop pages, shows top-level categories.', 'bytemash-woo-sync'); ?></p>
                                    <p style="margin: 5px 0; color: #646970;"><em><?php esc_html_e('Perfect for sidebars and category archive pages. Attributes: title (filter title), show_count (show product counts), hide_empty (hide empty categories).', 'bytemash-woo-sync'); ?></em></p>
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
                    
                  
                </div>
            
                <div class="bytemash-settings-section" style="margin-bottom: 30px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2 style="margin-top: 0;"><?php esc_html_e('🗂 Category Maintenance', 'bytemash-woo-sync'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Use this tool to remove all WooCommerce categories that were created by the Amrod sync (identified by Amrod category metadata). This is helpful when you need to clear duplicates before running a fresh sync.', 'bytemash-woo-sync'); ?>
                    </p>
                    <form method="post" action="" onsubmit="return confirm('<?php echo esc_js(__('This will delete all synced Amrod categories and detach them from products. Continue?', 'bytemash-woo-sync')); ?>');">
                        <?php wp_nonce_field('bytemash_delete_categories_action', 'bytemash_delete_categories_nonce'); ?>
                        <p class="submit">
                            <button type="submit" name="bytemash_delete_categories" class="button button-secondary" style="background: #dc3545; border-color: #dc3545; color: #fff;">
                                <span class="dashicons dashicons-trash"></span>
                                <?php esc_html_e('Delete Synced Categories', 'bytemash-woo-sync'); ?>
                            </button>
                        </p>
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
                    <h2><?php esc_html_e('Product Behaviour & Promotion Flags', 'bytemash-woo-sync'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Each sync updates two dedicated taxonomies so you can target Amrod flags without touching categories.', 'bytemash-woo-sync'); ?>
                    </p>
                    <ul>
                        <li><?php esc_html_e('Go to Products -> Product Behaviour to review the automatically synced flags (Normal, Featured, Hidden).', 'bytemash-woo-sync'); ?></li>
                        <li><?php esc_html_e('Visit Products -> Product Promotion to see which products are tagged as Normal, On Promotion, New, or Clearance.', 'bytemash-woo-sync'); ?></li>
                        <li><?php esc_html_e('Use the Behaviour and Promotion columns on the Products -> All Products screen to quickly filter or bulk edit store merchandising.', 'bytemash-woo-sync'); ?></li>
                        <li>
                            <?php
                            echo wp_kses(
                                sprintf(
                                    /* translators: %s is a WooCommerce shortcode example. */
                                    __('Build storefront sections with the WooCommerce [products] shortcode, for example %s to list items currently on promotion.', 'bytemash-woo-sync'),
                                    '<code>[products taxonomy="amrod_product_promotion" terms="promotion" limit="8"]</code>'
                                ),
                                array(
                                    'code' => array(),
                                )
                            );
                            ?>
                        </li>
                    </ul>
                    <p class="description">
                        <?php esc_html_e('These taxonomies support menus, widgets, REST API queries, and theme builders. Any changes you make in WordPress will be respected until the next Amrod update rewrites the flags.', 'bytemash-woo-sync'); ?>
                    </p>
                </div>
                
                <div class="bytemash-settings-section">
                    <h2><?php esc_html_e('Product Meta Reference (Size, Gender, Colour)', 'bytemash-woo-sync'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Use these keys and helpers to surface Amrod product details anywhere in your store or theme builder.', 'bytemash-woo-sync'); ?>
                    </p>
                    <ul>
                        <li><?php esc_html_e('Sizes: stored as the standard WooCommerce product attribute named "size". Inspect via Product Data -> Attributes or call $product->get_attribute(\'size\') inside templates.', 'bytemash-woo-sync'); ?></li>
                        <li><?php esc_html_e('Gender: saved in post meta "_amrod_gender". Retrieve with get_post_meta( $product_id, \'_amrod_gender\', true ) or drop the [amrod_gender] shortcode into any content block.', 'bytemash-woo-sync'); ?></li>
                        <li><?php esc_html_e('Colour options: stored in "_amrod_color_mapping" (name to code map) and "_amrod_color_swatches" (full payload). Use get_post_meta to pull raw data, or display swatches instantly with the [amrod_color_swatches] shortcode.', 'bytemash-woo-sync'); ?></li>
                        <li><?php esc_html_e('Variant matrix & dimensions: the "_amrod_dimension_details" meta contains per-variant size/colour and measurement data for custom templates or developer integrations.', 'bytemash-woo-sync'); ?></li>
                        <li><?php esc_html_e('Prefer taxonomy filters? We mirror gender, colour, and size into the amrod_product_gender, amrod_product_color, and amrod_product_size taxonomies for easy use in menus, archives, and page builders.', 'bytemash-woo-sync'); ?></li>
                    </ul>
                    <p class="description">
                        <?php esc_html_e('All meta values are refreshed on every sync. If you customise output in code, cache results per request so they stay in step with incoming Amrod updates.', 'bytemash-woo-sync'); ?>
                    </p>
                    <div class="notice notice-info" style="margin-top: 20px;">
                        <p style="margin: 0;">
                            <strong><?php esc_html_e('Bricks Builder tips:', 'bytemash-woo-sync'); ?></strong>
                            <?php esc_html_e('Use the Shortcode element for [amrod_color_swatches] or [amrod_gender], add {post_meta:_amrod_gender} / {post_meta:_amrod_dimension_details} as dynamic tags, and configure Query Loop filters with amrod_product_behaviour, amrod_product_promotion, amrod_product_gender, amrod_product_color, or amrod_product_size to build curated product grids.', 'bytemash-woo-sync'); ?>
                        </p>
                        <ul style="margin-left: 20px; list-style: disc;">
                            <li><?php esc_html_e('To expose gender filters, either add a Query Loop Condition -> Meta Query targeting "_amrod_gender" or bind the Bricks Filter element to the amrod_product_gender taxonomy.', 'bytemash-woo-sync'); ?></li>
                            <li><?php esc_html_e('Colour filtering works via both the WooCommerce "color" attribute and the amrod_product_color taxonomy—choose whichever fits your layout or filter stack.', 'bytemash-woo-sync'); ?></li>
                            <li><?php esc_html_e('Sizes surface through the "size" attribute and the amrod_product_size taxonomy, so you can drive Query Loops, archive widgets, and filter elements without custom code.', 'bytemash-woo-sync'); ?></li>
                        </ul>
                    </div>
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
                                        <?php
                                        // Check if production sync is enabled
                                        $production_full_sync_enabled = get_option('bytemash_cron_production_full_sync_enabled', false);
                                        
                                        if ($production_full_sync_enabled && class_exists('ByteMash_Action_Scheduler_Sync') && function_exists('as_get_scheduled_actions')) {
                                            // Display Action Scheduler status
                                            $last_full_sync_time = get_option('bytemash_last_full_sync', '');
                                            $last_incremental_sync_time = get_option('bytemash_last_incremental_sync', '');
                                            $last_full_display = $last_full_sync_time ? esc_html($last_full_sync_time) : '<em>' . esc_html__('Never', 'bytemash-woo-sync') . '</em>';
                                            $last_incremental_display = $last_incremental_sync_time ? esc_html($last_incremental_sync_time) : '<em>' . esc_html__('Never', 'bytemash-woo-sync') . '</em>';
                                            
                                            // Fetch scheduled actions
                                            $full_sync_actions = as_get_scheduled_actions(array(
                                                'hook' => 'bytemash_action_scheduler_full_sync',
                                                'status' => 'pending',
                                                'per_page' => 1,
                                            ));
                                            $incremental_sync_actions = as_get_scheduled_actions(array(
                                                'hook' => 'bytemash_action_scheduler_incremental_sync',
                                                'status' => 'pending',
                                                'per_page' => 1,
                                            ));
                                            
                                            $next_full = null;
                                            $next_incremental = null;
                                            
                                            if (!empty($full_sync_actions) && isset($full_sync_actions[0])) {
                                                $schedule = $full_sync_actions[0]->get_schedule();
                                                if ($schedule) {
                                                    $date = $schedule->get_date();
                                                    if ($date) {
                                                        $next_full = $date->format('Y-m-d H:i:s');
                                                    }
                                                }
                                            }
                                            
                                            if (!empty($incremental_sync_actions) && isset($incremental_sync_actions[0])) {
                                                $schedule = $incremental_sync_actions[0]->get_schedule();
                                                if ($schedule) {
                                                    $date = $schedule->get_date();
                                                    if ($date) {
                                                        $next_incremental = $date->format('Y-m-d H:i:s');
                                                    }
                                                }
                                            }
                                            
                                            // Display production sync status
                                            echo '<div class="notice notice-info inline" style="margin-bottom: 15px;"><p>';
                                            echo '<strong>' . esc_html__('Production Sync Active', 'bytemash-woo-sync') . '</strong><br>';
                                            echo esc_html__('Full sync daily at 01:30, Incremental every 5 hours', 'bytemash-woo-sync');
                                            echo '</p></div>';
                                            
                                            echo '<div class="sync-status-grid">';
                                            
                                            echo '<div class="sync-status-item">';
                                            echo '<strong>' . esc_html__('Next Full Sync:', 'bytemash-woo-sync') . '</strong> ';
                                            if ($next_full) {
                                                echo '<span>' . esc_html($next_full) . '</span>';
                                            } else {
                                                echo '<em>' . esc_html__('Daily at 01:30 (schedule pending)', 'bytemash-woo-sync') . '</em>';
                                            }
                                            echo '</div>';
                                            
                                            echo '<div class="sync-status-item">';
                                            echo '<strong>' . esc_html__('Next Incremental Sync:', 'bytemash-woo-sync') . '</strong> ';
                                            if ($next_incremental) {
                                                echo '<span>' . esc_html($next_incremental) . '</span>';
                                            } else {
                                                echo '<em>' . esc_html__('Every 5 hours (schedule pending)', 'bytemash-woo-sync') . '</em>';
                                            }
                                            echo '</div>';
                                            
                                            echo '<div class="sync-status-item">';
                                            echo '<strong>' . esc_html__('Last Full Sync:', 'bytemash-woo-sync') . '</strong> ';
                                            echo '<span>' . $last_full_display . '</span>';
                                            if ($sync_status['full_sync_running']) {
                                                echo ' <span class="status-running">🔄 ' . esc_html__('Running', 'bytemash-woo-sync') . '</span>';
                                            }
                                            echo '</div>';
                                            
                                            echo '<div class="sync-status-item">';
                                            echo '<strong>' . esc_html__('Last Incremental Sync:', 'bytemash-woo-sync') . '</strong> ';
                                            echo '<span>' . $last_incremental_display . '</span>';
                                            if ($sync_status['incremental_sync_running']) {
                                                echo ' <span class="status-running">🔄 ' . esc_html__('Running', 'bytemash-woo-sync') . '</span>';
                                            }
                                            echo '</div>';
                                            
                                            echo '</div>';
                                        } else {
                                            // Display WordPress cron status
                                            ?>
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
                                            <?php
                                        }
                                        ?>
                                        
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
                            <!-- Production Full Sync -->
                            <tr>
                                <th scope="row"><?php esc_html_e('Production Full Sync', 'bytemash-woo-sync'); ?></th>
                                <td>
                                    <div class="production-full-sync-controls">
                                        <div class="production-full-sync-section">
                                            <div class="test-mode-item">
                                                <?php
                                                $production_full_sync_enabled = get_option('bytemash_cron_production_full_sync_enabled', false);
                                                ?>
                                                <span class="test-mode-badge <?php echo $production_full_sync_enabled ? 'enabled' : 'disabled'; ?>">
                                                    <?php echo $production_full_sync_enabled ? __('Enabled', 'bytemash-woo-sync') : __('Disabled', 'bytemash-woo-sync'); ?>
                                                </span>
                                                <button type="button" id="toggle-production-full-sync" class="button <?php echo $production_full_sync_enabled ? 'button-secondary' : 'button-primary'; ?>">
                                                    <?php echo $production_full_sync_enabled ? __('Disable Production Full Sync', 'bytemash-woo-sync') : __('Enable Production Full Sync', 'bytemash-woo-sync'); ?>
                                                </button>
                                            </div>
                                            <div id="production-full-sync-status">
                                                <?php
                                                if ($production_full_sync_enabled && class_exists('ByteMash_Action_Scheduler_Sync') && function_exists('as_get_scheduled_actions')) {
                                                    $last_full_sync_time = get_option('bytemash_last_full_sync', '');
                                                    $last_incremental_sync_time = get_option('bytemash_last_incremental_sync', '');
                                                    $last_full_display = $last_full_sync_time ? esc_html($last_full_sync_time) : '<em>' . esc_html__('Never', 'bytemash-woo-sync') . '</em>';
                                                    $last_incremental_display = $last_incremental_sync_time ? esc_html($last_incremental_sync_time) : '<em>' . esc_html__('Never', 'bytemash-woo-sync') . '</em>';
                                                    // Always fetch scheduled actions when production sync is enabled
                                                    $full_sync_actions = as_get_scheduled_actions(array(
                                                        'hook' => 'bytemash_action_scheduler_full_sync',
                                                        'status' => 'pending',
                                                        'per_page' => 1,
                                                    ));
                                                    $incremental_sync_actions = as_get_scheduled_actions(array(
                                                        'hook' => 'bytemash_action_scheduler_incremental_sync',
                                                        'status' => 'pending',
                                                        'per_page' => 1,
                                                    ));
                                                    
                                                    $next_full = null;
                                                    $next_incremental = null;
                                                    
                                                    if (!empty($full_sync_actions) && isset($full_sync_actions[0])) {
                                                        $schedule = $full_sync_actions[0]->get_schedule();
                                                        if ($schedule) {
                                                            $date = $schedule->get_date();
                                                            if ($date) {
                                                                $next_full = $date->format('Y-m-d H:i:s');
                                                            }
                                                        }
                                                    }
                                                    
                                                    if (!empty($incremental_sync_actions) && isset($incremental_sync_actions[0])) {
                                                        $schedule = $incremental_sync_actions[0]->get_schedule();
                                                        if ($schedule) {
                                                            $date = $schedule->get_date();
                                                            if ($date) {
                                                                $next_incremental = $date->format('Y-m-d H:i:s');
                                                            }
                                                        }
                                                    }
                                                    
                                                    // Always show the schedule info when production sync is enabled
                                                    echo '<div class="notice notice-info inline" style="margin-top: 10px;"><p>';
                                                    echo '<strong>' . esc_html__('Production Sync Schedule:', 'bytemash-woo-sync') . '</strong><br>';
                                                    
                                                    if ($next_full) {
                                                        echo '<strong>' . esc_html__('Next full sync:', 'bytemash-woo-sync') . '</strong> ' . esc_html($next_full);
                                                    } else {
                                                        echo '<strong>' . esc_html__('Next full sync:', 'bytemash-woo-sync') . '</strong> <em>' . esc_html__('Daily at 01:30 (schedule pending)', 'bytemash-woo-sync') . '</em>';
                                                    }
                                                    
                                                    echo '<br>';
                                                    
                                                    if ($next_incremental) {
                                                        echo '<strong>' . esc_html__('Next incremental sync:', 'bytemash-woo-sync') . '</strong> ' . esc_html($next_incremental);
                                                    } else {
                                                        echo '<strong>' . esc_html__('Next incremental sync:', 'bytemash-woo-sync') . '</strong> <em>' . esc_html__('Every 5 hours (schedule pending)', 'bytemash-woo-sync') . '</em>';
                                                    }
                                                    
                                                    echo '<br>';
                                                    
                                                    echo '<strong>' . esc_html__('Last full sync:', 'bytemash-woo-sync') . '</strong> ' . $last_full_display;
                                                    echo '<br>';
                                                    echo '<strong>' . esc_html__('Last incremental sync:', 'bytemash-woo-sync') . '</strong> ' . $last_incremental_display;
                                                    
                                                    echo '</p></div>';
                                                }
                                                ?>
                                            </div>
                                            <p class="description">
                                                <?php esc_html_e('Enables production sync schedules: Full sync daily at 01:30, Incremental sync every 5 hours. Syncs only the attributes selected above. Uses Action Scheduler, same as test mode but with production schedule.', 'bytemash-woo-sync'); ?>
                                            </p>
                                            <div id="production-full-sync-progress" class="production-full-sync-progress">
                                                <?php if ($production_full_sync_enabled) : ?>
                                                    <p class="description">
                                                        <?php esc_html_e('Live progress from Action Scheduler will appear here during the scheduled full sync window. This updates even if nobody is browsing the site.', 'bytemash-woo-sync'); ?>
                                                    </p>
                                                <?php else : ?>
                                                    <p class="description">
                                                        <?php esc_html_e('Enable production full sync to start receiving background progress updates.', 'bytemash-woo-sync'); ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
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
                                    $purchasability_mode = get_option('bytemash_allow_orders_without_price', 'default');
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
                            
                            <tr>
                                <th scope="row">
                                    <label><?php esc_html_e('YITH Compatibility', 'bytemash-woo-sync'); ?></label>
                                </th>
                                <td>
                                    <button type="button" class="button button-secondary" id="cleanup_zero_prices">
                                        <?php esc_html_e('Remove Fake Zero Prices', 'bytemash-woo-sync'); ?>
                                    </button>
                                    <p class="description">
                                        <?php esc_html_e('Removes fake \'0\' prices that interfere with YITH Request a Quote. Run this if YITH quote button is not showing.', 'bytemash-woo-sync'); ?>
                                    </p>
                                    <div id="cleanup_zero_prices_result" style="margin-top: 10px;"></div>
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
     * Delete all categories synced from Amrod
     */
    private static function delete_amrod_categories() {
        if (!isset($_POST['bytemash_delete_categories_nonce']) ||
            !wp_verify_nonce($_POST['bytemash_delete_categories_nonce'], 'bytemash_delete_categories_action')) {
            wp_die(esc_html__('Security check failed', 'bytemash-woo-sync'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Insufficient permissions', 'bytemash-woo-sync'));
        }

        $terms = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => '_amrod_category_path',
                    'compare' => 'EXISTS',
                ),
            ),
        ));

        $deleted = 0;

        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term_id) {
                $result = wp_delete_term((int) $term_id, 'product_cat');
                if (!is_wp_error($result)) {
                    $deleted++;
                }
            }
        }

        $logger = new ByteMash_Logger();
        $logger->log('warning', 'Amrod categories deleted via settings action', array(
            'deleted' => $deleted,
            'user' => get_current_user_id(),
        ), 'settings');

        wp_redirect(add_query_arg('categories-deleted', 'true', admin_url('admin.php?page=bytemash-amrod-settings')));
        exit;
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

