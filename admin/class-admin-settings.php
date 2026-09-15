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
        // Sentinel distinguishes "never configured yet" (seed once from
        // whatever top-level categories exist today, so upgrading doesn't
        // blank an existing site's menu) from "admin explicitly emptied it".
        $mega_menu_top_order_sentinel = '__bytemash_not_set__';
        $mega_menu_top_order = get_option('bytemash_mega_menu_top_order', $mega_menu_top_order_sentinel);
        if ($mega_menu_top_order === $mega_menu_top_order_sentinel) {
            $seed_terms = get_terms(array(
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'parent' => 0,
                'fields' => 'ids',
            ));
            $mega_menu_top_order = is_wp_error($seed_terms) ? array() : array_map('intval', (array) $seed_terms);
            update_option('bytemash_mega_menu_top_order', $mega_menu_top_order, false);
        }
        
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
                <?php wp_enqueue_script('jquery-ui-sortable'); ?>
                
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
                    <form id="bytemash-delete-categories-form" method="post" action="">
                        <?php wp_nonce_field('bytemash_delete_categories_action', 'bytemash_delete_categories_nonce'); ?>
                        <p class="submit">
                            <button type="button" id="bytemash-delete-categories-btn" class="button button-secondary" data-nonce="<?php echo esc_attr(wp_create_nonce('bytemash_woo_sync_nonce')); ?>" style="background: #dc3545; border-color: #dc3545; color: #fff;">
                                <span class="dashicons dashicons-trash"></span>
                                <?php esc_html_e('Delete Synced Categories', 'bytemash-woo-sync'); ?>
                            </button>
                            <noscript>
                                <button type="submit" name="bytemash_delete_categories" class="button button-secondary" style="background: #dc3545; border-color: #dc3545; color: #fff;" onclick="return confirm('<?php echo esc_js(__('This will delete all synced Amrod categories and detach them from products. Continue?', 'bytemash-woo-sync')); ?>');">
                                    <?php esc_html_e('Delete Synced Categories (no JS)', 'bytemash-woo-sync'); ?>
                                </button>
                            </noscript>
                        </p>
                        <div id="bytemash-delete-categories-progress" style="display:none;">
                            <div style="background:#f0f0f1;border-radius:4px;overflow:hidden;max-width:400px;height:18px;">
                                <div id="bytemash-delete-categories-bar" style="background:#dc3545;height:100%;width:0%;transition:width .2s;"></div>
                            </div>
                            <p id="bytemash-delete-categories-status" class="description" style="margin-top:6px;"></p>
                        </div>
                    </form>
                    <script>
                    (function($){
                        $(function(){
                            $('#bytemash-delete-categories-btn').on('click', function(){
                                if (!window.confirm('<?php echo esc_js(__('This will delete all synced Amrod categories and detach them from products. This runs in small batches and cannot be undone. Continue?', 'bytemash-woo-sync')); ?>')) {
                                    return;
                                }

                                var $btn = $(this);
                                var nonce = $btn.data('nonce');
                                var ajaxUrl = (typeof bytemashWooSync !== 'undefined' ? bytemashWooSync.ajax_url : ajaxurl);
                                var $progress = $('#bytemash-delete-categories-progress');
                                var $bar = $('#bytemash-delete-categories-bar');
                                var $status = $('#bytemash-delete-categories-status');

                                $btn.prop('disabled', true);
                                $progress.show();
                                $bar.css('width', '0%');
                                $status.text('<?php echo esc_js(__('Finding synced categories...', 'bytemash-woo-sync')); ?>');

                                $.post(ajaxUrl, {
                                    action: 'bytemash_get_synced_category_ids',
                                    nonce: nonce
                                }).done(function(res){
                                    if (!res.success || !res.data || !res.data.term_ids) {
                                        $status.text((res.data && res.data.message) || '<?php echo esc_js(__('Could not load categories.', 'bytemash-woo-sync')); ?>');
                                        $btn.prop('disabled', false);
                                        return;
                                    }

                                    var ids = res.data.term_ids;
                                    var total = ids.length;

                                    if (total === 0) {
                                        $status.text('<?php echo esc_js(__('No synced categories found - nothing to delete.', 'bytemash-woo-sync')); ?>');
                                        $btn.prop('disabled', false);
                                        return;
                                    }

                                    var batchSize = 10;
                                    var processed = 0;
                                    var deleted = 0;
                                    var totalFailed = 0;
                                    var retriesLeft = 3;

                                    function nextBatch(){
                                        var chunk = ids.slice(processed, processed + batchSize);

                                        if (chunk.length === 0) {
                                            $bar.css('width', '100%');
                                            var doneMsg = '<?php echo esc_js(__('Done -', 'bytemash-woo-sync')); ?> ' + deleted + ' <?php echo esc_js(__('categories deleted.', 'bytemash-woo-sync')); ?>';
                                            if (totalFailed > 0) {
                                                doneMsg += ' ' + totalFailed + ' <?php echo esc_js(__('could not be deleted - check the sync logs for details.', 'bytemash-woo-sync')); ?>';
                                            }
                                            $status.text(doneMsg);
                                            setTimeout(function(){
                                                window.location.href = <?php echo wp_json_encode(add_query_arg('categories-deleted', 'true', admin_url('admin.php?page=bytemash-amrod-settings'))); ?>;
                                            }, 800);
                                            return;
                                        }

                                        $.post(ajaxUrl, {
                                            action: 'bytemash_delete_categories_batch',
                                            nonce: nonce,
                                            term_ids: chunk
                                        }).done(function(res){
                                            retriesLeft = 3;
                                            if (res.success && res.data) {
                                                deleted += res.data.deleted || 0;
                                                if (res.data.failed && res.data.failed.length) {
                                                    totalFailed += res.data.failed.length;
                                                }
                                            }
                                            processed += chunk.length;
                                            var pct = Math.round((processed / total) * 100);
                                            $bar.css('width', pct + '%');
                                            $status.text(processed + ' / ' + total + ' <?php echo esc_js(__('checked,', 'bytemash-woo-sync')); ?> ' + deleted + ' <?php echo esc_js(__('deleted', 'bytemash-woo-sync')); ?>');
                                            nextBatch();
                                        }).fail(function(xhr){
                                            // Transient failures (timeout, brief server hiccup) get a
                                            // few automatic retries of the SAME chunk before giving up,
                                            // instead of stopping on the first blip.
                                            if (retriesLeft > 0) {
                                                retriesLeft--;
                                                $status.text(processed + ' / ' + total + ' <?php echo esc_js(__('checked - a batch had trouble, retrying...', 'bytemash-woo-sync')); ?>');
                                                setTimeout(nextBatch, 1000);
                                                return;
                                            }
                                            var detail = xhr && xhr.status ? (' (HTTP ' + xhr.status + (xhr.statusText ? ' ' + xhr.statusText : '') + ')') : '';
                                            $status.text('<?php echo esc_js(__('A batch failed after retries', 'bytemash-woo-sync')); ?>' + detail + '. <?php echo esc_js(__('Deleted', 'bytemash-woo-sync')); ?> ' + deleted + ' <?php echo esc_js(__('so far. Click the button again to retry the remaining categories.', 'bytemash-woo-sync')); ?>');
                                            $btn.prop('disabled', false);
                                        });
                                    }

                                    nextBatch();
                                }).fail(function(){
                                    $status.text('<?php echo esc_js(__('Request failed. Please try again.', 'bytemash-woo-sync')); ?>');
                                    $btn.prop('disabled', false);
                                });
                            });
                        });
                    })(jQuery);
                    </script>
                </div>

                <div class="bytemash-settings-section" style="margin-bottom: 30px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2 style="margin-top: 0;"><?php esc_html_e('🔀 Merge Duplicate Categories', 'bytemash-woo-sync'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Scan for categories that share the same name under the same parent (e.g. multiple "Writing Instruments" categories). For each group, the category with the most products is kept; the others are merged into it - their products are moved over and the duplicate categories are deleted.', 'bytemash-woo-sync'); ?>
                    </p>

                    <p>
                        <button type="button" id="bytemash-scan-duplicates-btn" class="button button-primary" data-nonce="<?php echo esc_attr(wp_create_nonce('bytemash_woo_sync_nonce')); ?>">
                            <span class="dashicons dashicons-search"></span>
                            <?php esc_html_e('Scan for Duplicate Categories', 'bytemash-woo-sync'); ?>
                        </button>
                    </p>

                    <div id="bytemash-duplicates-results" style="display:none;">
                        <div style="margin-bottom:10px;display:flex;align-items:center;gap:14px;">
                            <label style="display:flex;align-items:center;gap:6px;font-weight:600;">
                                <input type="checkbox" id="bytemash-duplicates-select-all">
                                <?php esc_html_e('Select all groups', 'bytemash-woo-sync'); ?>
                            </label>
                            <button type="button" id="bytemash-merge-duplicates-btn" class="button button-secondary" style="background:#d63638;border-color:#d63638;color:#fff;margin-left:auto;" disabled>
                                <span class="dashicons dashicons-randomize"></span>
                                <?php esc_html_e('Merge Selected', 'bytemash-woo-sync'); ?>
                                (<span id="bytemash-duplicates-selected-count">0</span>)
                            </button>
                        </div>
                        <div id="bytemash-duplicates-list"></div>
                        <div id="bytemash-merge-progress" style="display:none;max-width:600px;margin:10px 0;">
                            <div style="background:#f0f0f1;border-radius:4px;overflow:hidden;height:18px;">
                                <div id="bytemash-merge-bar" style="background:#d63638;height:100%;width:0%;transition:width .2s;"></div>
                            </div>
                        </div>
                        <p class="description" id="bytemash-merge-status"></p>
                    </div>
                    <p class="description" id="bytemash-scan-status"></p>

                    <script>
                    (function($){
                        function esc(str){
                            return $('<div>').text(str == null ? '' : String(str)).html();
                        }

                        $(function(){
                            var ajaxUrl = (typeof bytemashWooSync !== 'undefined' ? bytemashWooSync.ajax_url : ajaxurl);
                            var $scanBtn = $('#bytemash-scan-duplicates-btn');
                            var $scanStatus = $('#bytemash-scan-status');
                            var $results = $('#bytemash-duplicates-results');
                            var $list = $('#bytemash-duplicates-list');
                            var $selectAll = $('#bytemash-duplicates-select-all');
                            var $mergeBtn = $('#bytemash-merge-duplicates-btn');
                            var $selectedCount = $('#bytemash-duplicates-selected-count');
                            var $mergeProgress = $('#bytemash-merge-progress');
                            var $mergeBar = $('#bytemash-merge-bar');
                            var $mergeStatus = $('#bytemash-merge-status');
                            var groups = [];

                            function renderGroups(){
                                if (!groups.length) {
                                    $results.hide();
                                    $scanStatus.text('<?php echo esc_js(__('No duplicate categories found.', 'bytemash-woo-sync')); ?>');
                                    return;
                                }

                                $scanStatus.text('');
                                var html = '';
                                groups.forEach(function(group, idx){
                                    var canonical = null;
                                    group.terms.forEach(function(t){ if (t.id === group.canonical_id) canonical = t; });
                                    html += '<div class="bytemash-dup-group" data-group-index="' + idx + '" style="border:1px solid #ccd0d4;border-radius:6px;padding:10px 12px;margin-bottom:8px;background:#f6f7f7;">';
                                    html += '<label style="display:flex;align-items:center;gap:8px;font-weight:600;">';
                                    html += '<input type="checkbox" class="bytemash-dup-group-select" data-group-index="' + idx + '" checked>';
                                    html += esc(group.name) + (group.parent ? ' <span style="font-weight:400;opacity:.7;">(' + '<?php echo esc_js(__('subcategory', 'bytemash-woo-sync')); ?>' + ')</span>' : '');
                                    html += ' <span style="font-weight:400;opacity:.7;">- ' + group.terms.length + ' <?php echo esc_js(__('categories found', 'bytemash-woo-sync')); ?></span>';
                                    html += '</label>';
                                    html += '<ul style="margin:8px 0 0 26px;">';
                                    group.terms.forEach(function(t){
                                        var isCanonical = (t.id === group.canonical_id);
                                        html += '<li>';
                                        html += isCanonical
                                            ? '<strong>' + '<?php echo esc_js(__('Keep', 'bytemash-woo-sync')); ?>' + ':</strong> '
                                            : '<span style="color:#d63638;">' + '<?php echo esc_js(__('Merge away', 'bytemash-woo-sync')); ?>' + ':</span> ';
                                        html += 'ID ' + t.id + ' (' + t.count + ' <?php echo esc_js(__('products', 'bytemash-woo-sync')); ?>)';
                                        html += '</li>';
                                    });
                                    html += '</ul>';
                                    html += '</div>';
                                });
                                $list.html(html);
                                $results.show();
                                updateSelectionState();
                            }

                            function updateSelectionState(){
                                var count = $list.find('.bytemash-dup-group-select:checked').length;
                                var total = $list.find('.bytemash-dup-group-select').length;
                                $selectedCount.text(count);
                                $mergeBtn.prop('disabled', count === 0);
                                $selectAll.prop('checked', total > 0 && count === total);
                                $selectAll.prop('indeterminate', count > 0 && count < total);
                            }

                            $scanBtn.on('click', function(){
                                $scanBtn.prop('disabled', true);
                                $scanStatus.text('<?php echo esc_js(__('Scanning...', 'bytemash-woo-sync')); ?>');
                                $results.hide();
                                $mergeStatus.text('').removeClass('notice notice-error notice-success inline');

                                $.post(ajaxUrl, {
                                    action: 'bytemash_scan_duplicate_categories',
                                    nonce: $scanBtn.data('nonce')
                                }).done(function(res){
                                    $scanBtn.prop('disabled', false);
                                    if (!res.success || !res.data) {
                                        $scanStatus.text((res.data && res.data.message) || '<?php echo esc_js(__('Scan failed.', 'bytemash-woo-sync')); ?>');
                                        return;
                                    }
                                    groups = res.data.groups || [];
                                    renderGroups();
                                }).fail(function(){
                                    $scanBtn.prop('disabled', false);
                                    $scanStatus.text('<?php echo esc_js(__('Request failed. Please try again.', 'bytemash-woo-sync')); ?>');
                                });
                            });

                            $selectAll.on('change', function(){
                                $list.find('.bytemash-dup-group-select').prop('checked', $(this).is(':checked'));
                                updateSelectionState();
                            });

                            $list.on('change', '.bytemash-dup-group-select', updateSelectionState);

                            $mergeBtn.on('click', function(){
                                var selectedIndexes = $list.find('.bytemash-dup-group-select:checked').map(function(){
                                    return parseInt($(this).data('group-index'), 10);
                                }).get();

                                if (!selectedIndexes.length) { return; }

                                var totalDupCategories = 0;
                                selectedIndexes.forEach(function(idx){ totalDupCategories += groups[idx].duplicate_ids.length; });

                                if (!window.confirm('<?php echo esc_js(__('Merge', 'bytemash-woo-sync')); ?> ' + selectedIndexes.length + ' <?php echo esc_js(__('group(s),', 'bytemash-woo-sync')); ?> ' + totalDupCategories + ' <?php echo esc_js(__('duplicate categories in total. Products move to the kept category; the duplicates are deleted. This cannot be undone. Continue?', 'bytemash-woo-sync')); ?>')) {
                                    return;
                                }

                                var nonce = $scanBtn.data('nonce');
                                $mergeBtn.prop('disabled', true);
                                $selectAll.prop('disabled', true);
                                $mergeProgress.show();
                                $mergeBar.css('width', '0%');
                                $mergeStatus.text('').removeClass('notice notice-error notice-success inline');

                                // Flatten every selected group into individual [canonical_id, duplicate_id]
                                // pairs and send only a FEW per request, regardless of how many
                                // duplicates any single group has - keeps each request small and fast
                                // instead of asking the server to merge dozens of categories (each
                                // touching the product/category relationships table) in one go.
                                var pairs = [];
                                selectedIndexes.forEach(function(idx){
                                    groups[idx].duplicate_ids.forEach(function(dupId){
                                        pairs.push([groups[idx].canonical_id, dupId]);
                                    });
                                });

                                var chunkSize = 3;
                                var processedPairs = 0;
                                var totalPairs = pairs.length;
                                var totalMerged = 0;
                                var totalFailed = 0;
                                var retriesLeft = 3;

                                function nextChunk(){
                                    if (processedPairs >= totalPairs) {
                                        var doneMsg = totalMerged + ' <?php echo esc_js(__('categories merged.', 'bytemash-woo-sync')); ?>';
                                        if (totalFailed > 0) {
                                            doneMsg += ' ' + totalFailed + ' <?php echo esc_js(__('could not be merged - check the sync logs for details.', 'bytemash-woo-sync')); ?>';
                                        }
                                        $mergeStatus.text(doneMsg).addClass('notice notice-success inline').css({padding:'6px 10px', display:'inline-block'});
                                        setTimeout(function(){ window.location.reload(); }, 1200);
                                        return;
                                    }

                                    var chunk = pairs.slice(processedPairs, processedPairs + chunkSize);

                                    $.post(ajaxUrl, {
                                        action: 'bytemash_merge_categories_batch',
                                        nonce: nonce,
                                        pairs: JSON.stringify(chunk)
                                    }).done(function(res){
                                        retriesLeft = 3;
                                        if (res.success && res.data) {
                                            totalMerged += res.data.merged || 0;
                                            if (res.data.failed && res.data.failed.length) {
                                                totalFailed += res.data.failed.length;
                                            }
                                        }
                                        processedPairs += chunk.length;
                                        $mergeBar.css('width', Math.round((processedPairs / totalPairs) * 100) + '%');
                                        $mergeStatus.text(processedPairs + ' / ' + totalPairs + ' <?php echo esc_js(__('checked,', 'bytemash-woo-sync')); ?> ' + totalMerged + ' <?php echo esc_js(__('merged', 'bytemash-woo-sync')); ?>');
                                        nextChunk();
                                    }).fail(function(xhr){
                                        if (retriesLeft > 0) {
                                            retriesLeft--;
                                            $mergeStatus.text(processedPairs + ' / ' + totalPairs + ' <?php echo esc_js(__('checked - a batch had trouble, retrying...', 'bytemash-woo-sync')); ?>');
                                            setTimeout(nextChunk, 1500);
                                            return;
                                        }
                                        var detail = xhr && xhr.status ? (' (HTTP ' + xhr.status + ')') : '';
                                        $mergeStatus.text('<?php echo esc_js(__('A batch failed after retries', 'bytemash-woo-sync')); ?>' + detail + '. <?php echo esc_js(__('Merged', 'bytemash-woo-sync')); ?> ' + totalMerged + ' <?php echo esc_js(__('so far. Scan again to retry the rest.', 'bytemash-woo-sync')); ?>').addClass('notice notice-error inline').css({padding:'6px 10px', display:'inline-block'});
                                        $mergeBtn.prop('disabled', false);
                                        $selectAll.prop('disabled', false);
                                    });
                                }

                                nextChunk();
                            });
                        });
                    })(jQuery);
                    </script>
                </div>

            <form method="post" action="" class="bytemash-settings-form">
                <?php wp_nonce_field('bytemash_settings_action', 'bytemash_settings_nonce'); ?>
                
                <div class="bytemash-settings-section">
                    <h2><?php esc_html_e('Mega Menu: Category Order', 'bytemash-woo-sync'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('This menu only shows categories explicitly added here - a sync creating or updating categories never changes this list. Drag and drop to reorder. Use the pencil icon to rename a category (the new name is protected from being overwritten by future syncs). The trash icon and "Delete Selected" only remove a category from THIS MENU - they do NOT delete the category, its products, or its subcategories from WooCommerce, and removed categories can be added back below at any time.', 'bytemash-woo-sync'); ?>
                    </p>
                    <?php
                    $top_terms = get_terms(array(
                        'taxonomy' => 'product_cat',
                        'hide_empty' => false,
                        'parent' => 0,
                        'orderby' => 'name',
                        'order' => 'ASC',
                    ));
                    if (!is_wp_error($top_terms) && !empty($top_terms)) {
                        $term_by_id = array();
                        foreach ($top_terms as $t) {
                            if (!$t instanceof WP_Term || $t->slug === 'uncategorized') {
                                continue;
                            }
                            $term_by_id[(int) $t->term_id] = $t;
                        }

                        // Strict inclusion: the menu list is ONLY what's in
                        // the saved order, in that order. Anything else
                        // (including brand new categories from a sync) is
                        // "available to add" instead of being auto-included.
                        $ordered_terms = array();
                        if (is_array($mega_menu_top_order)) {
                            foreach ($mega_menu_top_order as $tid) {
                                $tid = (int) $tid;
                                if ($tid && isset($term_by_id[$tid])) {
                                    $ordered_terms[] = $term_by_id[$tid];
                                    unset($term_by_id[$tid]);
                                }
                            }
                        }
                        // Whatever's left in $term_by_id was never added to
                        // the menu (or was removed from it) - offer it below.
                        $available_terms = array_values($term_by_id);
                        ?>
                        <input type="hidden" id="bytemash_mega_menu_top_order" name="mega_menu_top_order" value="<?php echo esc_attr(wp_json_encode(wp_list_pluck($ordered_terms, 'term_id'))); ?>">
                        <div style="margin:12px 0 8px;display:flex;align-items:center;gap:14px;max-width:520px;">
                            <label style="display:flex;align-items:center;gap:6px;font-weight:600;">
                                <input type="checkbox" id="bytemash-mega-menu-select-all">
                                <?php esc_html_e('Select all', 'bytemash-woo-sync'); ?>
                            </label>
                            <button type="button"
                                    id="bytemash-mega-menu-bulk-delete-btn"
                                    class="button button-secondary"
                                    data-nonce="<?php echo esc_attr(wp_create_nonce('bytemash_woo_sync_nonce')); ?>"
                                    disabled
                                    style="background:#d63638;border-color:#d63638;color:#fff;margin-left:auto;">
                                <span class="dashicons dashicons-hidden" aria-hidden="true"></span>
                                <?php esc_html_e('Remove Selected from Menu', 'bytemash-woo-sync'); ?>
                                (<span id="bytemash-mega-menu-selected-count">0</span>)
                            </button>
                        </div>
                        <ul id="bytemash-mega-menu-sortable" style="margin: 12px 0; max-width: 520px;">
                            <?php foreach ($ordered_terms as $t) : ?>
                                <li data-term-id="<?php echo esc_attr((int) $t->term_id); ?>" style="background:#f6f7f7;border:1px solid #ccd0d4;padding:10px 12px;margin:0 0 8px;border-radius:6px;display:flex;align-items:center;gap:10px;">
                                    <input type="checkbox" class="bytemash-cat-select" data-term-id="<?php echo esc_attr((int) $t->term_id); ?>">
                                    <span class="dashicons dashicons-move" aria-hidden="true" style="cursor:move;"></span>
                                    <strong class="bytemash-cat-name-display"><?php echo esc_html($t->name); ?></strong>
                                    <input type="text"
                                           class="bytemash-cat-name-input regular-text"
                                           value="<?php echo esc_attr($t->name); ?>"
                                           style="display:none;max-width:220px;">
                                    <code style="margin-left:auto;opacity:.7;"><?php echo esc_html($t->term_id); ?></code>
                                    <button type="button"
                                            class="button-link bytemash-cat-rename-btn"
                                            data-term-id="<?php echo esc_attr((int) $t->term_id); ?>"
                                            title="<?php esc_attr_e('Rename this category', 'bytemash-woo-sync'); ?>"
                                            style="color:#2563eb;padding:0;">
                                        <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                                        <span class="screen-reader-text"><?php esc_html_e('Rename category', 'bytemash-woo-sync'); ?></span>
                                    </button>
                                    <button type="button"
                                            class="button-link bytemash-cat-rename-save-btn"
                                            data-term-id="<?php echo esc_attr((int) $t->term_id); ?>"
                                            title="<?php esc_attr_e('Save name', 'bytemash-woo-sync'); ?>"
                                            style="display:none;color:#00a32a;padding:0;">
                                        <span class="dashicons dashicons-yes" aria-hidden="true"></span>
                                        <span class="screen-reader-text"><?php esc_html_e('Save name', 'bytemash-woo-sync'); ?></span>
                                    </button>
                                    <button type="button"
                                            class="button-link bytemash-cat-rename-cancel-btn"
                                            title="<?php esc_attr_e('Cancel', 'bytemash-woo-sync'); ?>"
                                            style="display:none;color:#646970;padding:0;">
                                        <span class="dashicons dashicons-no" aria-hidden="true"></span>
                                        <span class="screen-reader-text"><?php esc_html_e('Cancel', 'bytemash-woo-sync'); ?></span>
                                    </button>
                                    <button type="button"
                                            class="button-link bytemash-cat-delete-btn"
                                            data-term-id="<?php echo esc_attr((int) $t->term_id); ?>"
                                            data-term-name="<?php echo esc_attr($t->name); ?>"
                                            title="<?php esc_attr_e('Remove from mega menu (does not delete the category)', 'bytemash-woo-sync'); ?>"
                                            style="color:#d63638;padding:0;">
                                        <span class="dashicons dashicons-hidden" aria-hidden="true"></span>
                                        <span class="screen-reader-text"><?php esc_html_e('Remove from mega menu', 'bytemash-woo-sync'); ?></span>
                                    </button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div id="bytemash-mega-menu-bulk-progress" style="display:none;max-width:520px;margin-bottom:10px;">
                            <div style="background:#f0f0f1;border-radius:4px;overflow:hidden;height:18px;">
                                <div id="bytemash-mega-menu-bulk-bar" style="background:#d63638;height:100%;width:0%;transition:width .2s;"></div>
                            </div>
                        </div>
                        <p class="description" id="bytemash-mega-menu-delete-status"></p>

                        <?php if (!empty($available_terms)) : ?>
                        <div style="margin-top:18px;max-width:520px;">
                            <h3 style="margin-bottom:6px;"><?php esc_html_e('Available Categories (not in menu)', 'bytemash-woo-sync'); ?></h3>
                            <p class="description" style="margin-top:0;">
                                <?php esc_html_e('These exist in WooCommerce (including any new ones from a recent sync) but are not shown in the mega menu. Add one to make it appear.', 'bytemash-woo-sync'); ?>
                            </p>
                            <ul id="bytemash-mega-menu-hidden-list">
                                <?php foreach ($available_terms as $t) : ?>
                                    <li data-term-id="<?php echo esc_attr((int) $t->term_id); ?>" style="background:#f6f7f7;border:1px dashed #ccd0d4;padding:8px 12px;margin:0 0 6px;border-radius:6px;display:flex;align-items:center;gap:10px;opacity:.85;">
                                        <span><?php echo esc_html($t->name); ?></span>
                                        <code style="margin-left:auto;opacity:.7;"><?php echo esc_html($t->term_id); ?></code>
                                        <button type="button"
                                                class="button-link bytemash-cat-unhide-btn"
                                                data-term-id="<?php echo esc_attr((int) $t->term_id); ?>"
                                                title="<?php esc_attr_e('Add to mega menu', 'bytemash-woo-sync'); ?>"
                                                style="color:#2563eb;padding:0;">
                                            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                                            <?php esc_html_e('Add to Menu', 'bytemash-woo-sync'); ?>
                                        </button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>

                        <script>
                        (function($){
                            function syncOrder(){
                                var ids = [];
                                $('#bytemash-mega-menu-sortable li').each(function(){
                                    var id = parseInt($(this).data('term-id'), 10);
                                    if (!isNaN(id)) ids.push(id);
                                });
                                $('#bytemash_mega_menu_top_order').val(JSON.stringify(ids));
                            }
                            $(function(){
                                $('#bytemash-mega-menu-sortable').sortable({
                                    axis: 'y',
                                    handle: '.dashicons-move',
                                    update: syncOrder
                                });
                                syncOrder();

                                // Inline rename
                                var $renameStatus = $('#bytemash-mega-menu-delete-status');

                                function enterEditMode($li){
                                    $li.find('.bytemash-cat-name-display').hide();
                                    $li.find('.bytemash-cat-rename-btn, .bytemash-cat-delete-btn').hide();
                                    $li.find('.bytemash-cat-name-input').show().trigger('focus').select();
                                    $li.find('.bytemash-cat-rename-save-btn, .bytemash-cat-rename-cancel-btn').show();
                                }

                                function exitEditMode($li, newName){
                                    var $display = $li.find('.bytemash-cat-name-display');
                                    var $input = $li.find('.bytemash-cat-name-input');
                                    if (typeof newName === 'string') {
                                        $display.text(newName);
                                        $input.val(newName);
                                        $li.find('.bytemash-cat-delete-btn').data('term-name', newName);
                                    } else {
                                        $input.val($display.text());
                                    }
                                    $display.show();
                                    $li.find('.bytemash-cat-rename-btn, .bytemash-cat-delete-btn').show();
                                    $input.hide();
                                    $li.find('.bytemash-cat-rename-save-btn, .bytemash-cat-rename-cancel-btn').hide();
                                }

                                $('#bytemash-mega-menu-sortable').on('click', '.bytemash-cat-rename-btn', function(){
                                    enterEditMode($(this).closest('li'));
                                });

                                $('#bytemash-mega-menu-sortable').on('click', '.bytemash-cat-rename-cancel-btn', function(){
                                    exitEditMode($(this).closest('li'));
                                });

                                $('#bytemash-mega-menu-sortable').on('keydown', '.bytemash-cat-name-input', function(e){
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        $(this).closest('li').find('.bytemash-cat-rename-save-btn').trigger('click');
                                    } else if (e.key === 'Escape') {
                                        e.preventDefault();
                                        exitEditMode($(this).closest('li'));
                                    }
                                });

                                $('#bytemash-mega-menu-sortable').on('click', '.bytemash-cat-rename-save-btn', function(){
                                    var $btn = $(this);
                                    var $li = $btn.closest('li');
                                    var termId = parseInt($btn.data('term-id'), 10);
                                    var newName = $.trim($li.find('.bytemash-cat-name-input').val());
                                    var oldName = $li.find('.bytemash-cat-name-display').text();

                                    if (!termId || newName === '') { return; }

                                    if (newName === oldName) {
                                        exitEditMode($li, newName);
                                        return;
                                    }

                                    $li.find('.bytemash-cat-name-input').prop('disabled', true);
                                    $li.find('.bytemash-cat-rename-save-btn, .bytemash-cat-rename-cancel-btn').prop('disabled', true);
                                    $renameStatus.text('').removeClass('notice notice-error notice-success inline');

                                    $.post((typeof bytemashWooSync !== 'undefined' ? bytemashWooSync.ajax_url : ajaxurl), {
                                        action: 'bytemash_rename_category',
                                        nonce: (typeof bytemashWooSync !== 'undefined' ? bytemashWooSync.nonce : ''),
                                        term_id: termId,
                                        name: newName
                                    }).done(function(res){
                                        $li.find('.bytemash-cat-name-input, .bytemash-cat-rename-save-btn, .bytemash-cat-rename-cancel-btn').prop('disabled', false);
                                        if (res.success) {
                                            exitEditMode($li, res.data.name || newName);
                                            $renameStatus.text(res.data.message || '<?php echo esc_js(__('Category renamed.', 'bytemash-woo-sync')); ?>').addClass('notice notice-success inline').css({padding:'6px 10px', display:'inline-block'});
                                        } else {
                                            $renameStatus.text((res.data && res.data.message) || '<?php echo esc_js(__('Could not rename category.', 'bytemash-woo-sync')); ?>').addClass('notice notice-error inline').css({padding:'6px 10px', display:'inline-block'});
                                        }
                                    }).fail(function(){
                                        $li.find('.bytemash-cat-name-input, .bytemash-cat-rename-save-btn, .bytemash-cat-rename-cancel-btn').prop('disabled', false);
                                        $renameStatus.text('<?php echo esc_js(__('Request failed. Please try again.', 'bytemash-woo-sync')); ?>').addClass('notice notice-error inline').css({padding:'6px 10px', display:'inline-block'});
                                    });
                                });

                                $('#bytemash-mega-menu-sortable').on('click', '.bytemash-cat-delete-btn', function(){
                                    var $btn = $(this);
                                    var $li = $btn.closest('li');
                                    var termId = parseInt($btn.data('term-id'), 10);
                                    var termName = $btn.data('term-name');
                                    var $status = $('#bytemash-mega-menu-delete-status');

                                    if (!termId) { return; }
                                    if (!window.confirm('<?php echo esc_js(__('Remove', 'bytemash-woo-sync')); ?> "' + termName + '" <?php echo esc_js(__('from the mega menu? The category, its products and subcategories are NOT deleted - you can add it back to the menu later.', 'bytemash-woo-sync')); ?>')) {
                                        return;
                                    }

                                    $btn.prop('disabled', true);
                                    $li.css('opacity', 0.5);
                                    $status.text('').removeClass('notice notice-error notice-success inline');

                                    $.post((typeof bytemashWooSync !== 'undefined' ? bytemashWooSync.ajax_url : ajaxurl), {
                                        action: 'bytemash_hide_category_from_menu',
                                        nonce: (typeof bytemashWooSync !== 'undefined' ? bytemashWooSync.nonce : ''),
                                        term_id: termId
                                    }).done(function(res){
                                        if (res.success) {
                                            $li.remove();
                                            syncOrder();
                                            $status.text(res.data.message || '<?php echo esc_js(__('Removed from the mega menu.', 'bytemash-woo-sync')); ?>').addClass('notice notice-success inline').css({padding:'6px 10px', display:'inline-block'});
                                        } else {
                                            $btn.prop('disabled', false);
                                            $li.css('opacity', 1);
                                            $status.text((res.data && res.data.message) || '<?php echo esc_js(__('Could not remove category from menu.', 'bytemash-woo-sync')); ?>').addClass('notice notice-error inline').css({padding:'6px 10px', display:'inline-block'});
                                        }
                                    }).fail(function(){
                                        $btn.prop('disabled', false);
                                        $li.css('opacity', 1);
                                        $status.text('<?php echo esc_js(__('Request failed. Please try again.', 'bytemash-woo-sync')); ?>').addClass('notice notice-error inline').css({padding:'6px 10px', display:'inline-block'});
                                    });
                                });

                                $('#bytemash-mega-menu-hidden-list').on('click', '.bytemash-cat-unhide-btn', function(){
                                    var $btn = $(this);
                                    var $li = $btn.closest('li');
                                    var termId = parseInt($btn.data('term-id'), 10);
                                    var $status = $('#bytemash-mega-menu-delete-status');

                                    if (!termId) { return; }

                                    $btn.prop('disabled', true);
                                    $status.text('').removeClass('notice notice-error notice-success inline');

                                    $.post((typeof bytemashWooSync !== 'undefined' ? bytemashWooSync.ajax_url : ajaxurl), {
                                        action: 'bytemash_unhide_category_from_menu',
                                        nonce: (typeof bytemashWooSync !== 'undefined' ? bytemashWooSync.nonce : ''),
                                        term_id: termId
                                    }).done(function(res){
                                        if (res.success) {
                                            $status.text(res.data.message || '<?php echo esc_js(__('Added back to the mega menu.', 'bytemash-woo-sync')); ?>').addClass('notice notice-success inline').css({padding:'6px 10px', display:'inline-block'});
                                            setTimeout(function(){ window.location.reload(); }, 700);
                                        } else {
                                            $btn.prop('disabled', false);
                                            $status.text((res.data && res.data.message) || '<?php echo esc_js(__('Could not update.', 'bytemash-woo-sync')); ?>').addClass('notice notice-error inline').css({padding:'6px 10px', display:'inline-block'});
                                        }
                                    }).fail(function(){
                                        $btn.prop('disabled', false);
                                        $status.text('<?php echo esc_js(__('Request failed. Please try again.', 'bytemash-woo-sync')); ?>').addClass('notice notice-error inline').css({padding:'6px 10px', display:'inline-block'});
                                    });
                                });

                                // Bulk selection + remove from menu
                                var $selectAll = $('#bytemash-mega-menu-select-all');
                                var $bulkBtn = $('#bytemash-mega-menu-bulk-delete-btn');
                                var $selectedCount = $('#bytemash-mega-menu-selected-count');
                                var $bulkProgress = $('#bytemash-mega-menu-bulk-progress');
                                var $bulkBar = $('#bytemash-mega-menu-bulk-bar');
                                var $status = $('#bytemash-mega-menu-delete-status');

                                function updateSelectionState(){
                                    var count = $('#bytemash-mega-menu-sortable .bytemash-cat-select:checked').length;
                                    var totalBoxes = $('#bytemash-mega-menu-sortable .bytemash-cat-select').length;
                                    $selectedCount.text(count);
                                    $bulkBtn.prop('disabled', count === 0);
                                    $selectAll.prop('checked', totalBoxes > 0 && count === totalBoxes);
                                    $selectAll.prop('indeterminate', count > 0 && count < totalBoxes);
                                }

                                $selectAll.on('change', function(){
                                    $('#bytemash-mega-menu-sortable .bytemash-cat-select').prop('checked', $(this).is(':checked'));
                                    updateSelectionState();
                                });

                                $('#bytemash-mega-menu-sortable').on('change', '.bytemash-cat-select', updateSelectionState);

                                $bulkBtn.on('click', function(){
                                    var $btn = $(this);
                                    var $checked = $('#bytemash-mega-menu-sortable .bytemash-cat-select:checked');
                                    var ids = $checked.map(function(){ return parseInt($(this).data('term-id'), 10); }).get();

                                    if (!ids.length) { return; }
                                    if (!window.confirm('<?php echo esc_js(__('Remove', 'bytemash-woo-sync')); ?> ' + ids.length + ' <?php echo esc_js(__('selected categories from the mega menu? They are NOT deleted - products and subcategories are untouched, and you can add them back later.', 'bytemash-woo-sync')); ?>')) {
                                        return;
                                    }

                                    var ajaxUrl = (typeof bytemashWooSync !== 'undefined' ? bytemashWooSync.ajax_url : ajaxurl);
                                    var nonce = (typeof bytemashWooSync !== 'undefined' ? bytemashWooSync.nonce : $btn.data('nonce'));

                                    $btn.prop('disabled', true);
                                    $selectAll.prop('disabled', true);
                                    $('#bytemash-mega-menu-sortable .bytemash-cat-select, #bytemash-mega-menu-sortable .bytemash-cat-delete-btn').prop('disabled', true);
                                    $status.text('').removeClass('notice notice-error notice-success inline');

                                    $.post(ajaxUrl, {
                                        action: 'bytemash_hide_categories_from_menu_batch',
                                        nonce: nonce,
                                        term_ids: ids
                                    }).done(function(res){
                                        if (res.success) {
                                            ids.forEach(function(id){
                                                $('#bytemash-mega-menu-sortable li[data-term-id="' + id + '"]').remove();
                                            });
                                            syncOrder();
                                            $status.text(ids.length + ' <?php echo esc_js(__('categories removed from the mega menu.', 'bytemash-woo-sync')); ?>').addClass('notice notice-success inline').css({padding:'6px 10px', display:'inline-block'});
                                            $selectAll.prop('disabled', false).prop('checked', false);
                                            $('#bytemash-mega-menu-sortable .bytemash-cat-delete-btn').prop('disabled', false);
                                            updateSelectionState();
                                        } else {
                                            $status.text((res.data && res.data.message) || '<?php echo esc_js(__('Could not update menu.', 'bytemash-woo-sync')); ?>').addClass('notice notice-error inline').css({padding:'6px 10px', display:'inline-block'});
                                            $btn.prop('disabled', false);
                                            $selectAll.prop('disabled', false);
                                            $('#bytemash-mega-menu-sortable .bytemash-cat-delete-btn').prop('disabled', false);
                                        }
                                    }).fail(function(){
                                        $status.text('<?php echo esc_js(__('Request failed. Please try again.', 'bytemash-woo-sync')); ?>').addClass('notice notice-error inline').css({padding:'6px 10px', display:'inline-block'});
                                        $btn.prop('disabled', false);
                                        $selectAll.prop('disabled', false);
                                        $('#bytemash-mega-menu-sortable .bytemash-cat-delete-btn').prop('disabled', false);
                                    });
                                });
                            });
                        })(jQuery);
                        </script>
                        <?php
                    } else {
                        echo '<p class="description"><em>' . esc_html__('No top-level categories found.', 'bytemash-woo-sync') . '</em></p>';
                    }
                    ?>
                </div>

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

        // Save mega menu category order (top-level)
        if (isset($_POST['mega_menu_top_order'])) {
            $decoded = json_decode(wp_unslash($_POST['mega_menu_top_order']), true);
            if (!is_array($decoded)) {
                $decoded = array();
            }
            $decoded = array_values(array_filter(array_map('intval', $decoded)));
            update_option('bytemash_mega_menu_top_order', $decoded, false);
            delete_transient('bf_mega_menu_standard');
            delete_transient('bf_mega_menu_accordion');
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

