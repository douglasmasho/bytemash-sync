<?php
/**
 * Admin Tools Page
 * 
 * Provides utility tools like deleting all products
 */

if (!defined('ABSPATH')) {
    exit;
}
class ByteMash_Admin_Tools {
    
    /**
     * Render tools page
     */
    public static function render() {
        // Handle delete action
        if (isset($_POST['delete_all_products']) && check_admin_referer('bytemash_delete_products', 'delete_nonce')) {
            self::delete_all_products();
        }
        
        // Handle stock response download setting
        if (isset($_POST['bytemash_save_stock_download_setting']) && check_admin_referer('bytemash_stock_download_setting', 'stock_download_nonce')) {
            $enabled = isset($_POST['stock_sync_download_json']) && $_POST['stock_sync_download_json'] === '1';
            update_option('bytemash_stock_sync_download_json', $enabled);
            wp_redirect(add_query_arg(array('page' => 'bytemash-amrod-tools', 'settings-updated' => '1'), admin_url('admin.php')));
            exit;
        }
        
        // Get product count
        $product_count = wp_count_posts('product');
        $total_products = $product_count->publish + $product_count->draft + $product_count->pending;
        ?>
        
        <div class="wrap bytemash-admin-wrap">
            <h1><?php esc_html_e('Admin Tools', 'bytemash-woo-sync'); ?></h1>
            
            <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === '1') : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Setting saved.', 'bytemash-woo-sync'); ?></p></div>
            <?php endif; ?>
            
            <div class="bytemash-card" style="margin-top: 20px; max-width: 800px;">
                <h2><?php esc_html_e('Stock sync: Save API response', 'bytemash-woo-sync'); ?></h2>
                <p>
                    <?php esc_html_e('When enabled, the full stock API JSON response is written to a file each time a stock sync starts (dashboard or WP-CLI). Useful for debugging or inspecting the raw data.', 'bytemash-woo-sync'); ?>
                </p>
                <p>
                    <?php esc_html_e('Files are saved under the plugin folder: responses/stock/response_YYYY-MM-DD_HH-MM-SS.json', 'bytemash-woo-sync'); ?>
                </p>
                <?php
                $stock_download_enabled = (bool) get_option('bytemash_stock_sync_download_json', false);
                ?>
                <form method="post" style="margin-top: 12px;">
                    <?php wp_nonce_field('bytemash_stock_download_setting', 'stock_download_nonce'); ?>
                    <label>
                        <input type="checkbox" name="stock_sync_download_json" value="1" <?php checked($stock_download_enabled); ?>>
                        <?php esc_html_e('Save stock API response to file when stock sync starts', 'bytemash-woo-sync'); ?>
                    </label>
                    <p style="margin-top: 8px;">
                        <button type="submit" name="bytemash_save_stock_download_setting" class="button button-primary" value="1"><?php esc_html_e('Save', 'bytemash-woo-sync'); ?></button>
                    </p>
                </form>
            <div class="bytemash-card" style="margin-top: 20px; max-width: 800px;">
                <h2><?php esc_html_e('Debug Stock', 'bytemash-woo-sync'); ?></h2>
                <p><?php esc_html_e('Check stock values for a specific SKU directly from the database vs WooCommerce objects.', 'bytemash-woo-sync'); ?></p>
                
                <div style="display: flex; gap: 10px; align-items: flex-end; margin-top: 15px;">
                    <div>
                        <label for="debug_sku" style="display: block; margin-bottom: 5px; font-weight: 600;"><?php esc_html_e('Product SKU', 'bytemash-woo-sync'); ?></label>
                        <input type="text" id="debug_sku" class="regular-text" placeholder="e.g. BAS-7764-BL-S">
                    </div>
                    <div>
                        <button type="button" id="debug_stock_btn" class="button button-primary" data-nonce="<?php echo esc_attr(wp_create_nonce('bytemash_woo_sync_nonce')); ?>">
                            <?php esc_html_e('Check Stock', 'bytemash-woo-sync'); ?>
                        </button>
                    </div>
                </div>
                
                <div id="debug_stock_result" style="margin-top: 15px; display: none;"></div>
                
                <script>
                jQuery(function($) {
                    $('#debug_stock_btn').on('click', function() {
                        var $btn = $(this), $result = $('#debug_stock_result'), sku = $('#debug_sku').val();
                        if (!sku) { alert('Please enter a SKU'); return; }
                        
                        $btn.prop('disabled', true).text('Checking...');
                        $result.hide().html('');
                        
                        $.post(ajaxurl, {
                            action: 'bytemash_debug_stock_check',
                            sku: sku,
                            nonce: $btn.data('nonce')
                        }).done(function(res) {
                            $btn.text('Check Stock');
                            if (res.success) {
                                var d = res.data;
                                var html = '<table class="widefat striped" style="margin-top:10px;">';
                                html += '<thead><tr><th colspan="2">Product: ' + d.name + ' (ID: ' + d.product_id + ')</th></tr></thead>';
                                html += '<tbody>';
                                html += '<tr><td><strong>Type</strong></td><td>' + d.type + '</td></tr>';
                                
                                html += '<tr><td colspan="2"><strong>Database Values (postmeta)</strong></td></tr>';
                                html += '<tr><td>_stock</td><td>' + d.db_values._stock + '</td></tr>';
                                html += '<tr><td>_stock_status</td><td>' + d.db_values._stock_status + '</td></tr>';
                                html += '<tr><td>_manage_stock</td><td>' + d.db_values._manage_stock + '</td></tr>';
                                html += '<tr><td>_sku</td><td>' + d.db_values._sku + '</td></tr>';
                                html += '<tr><td>_amrod_full_code</td><td>' + d.db_values._amrod_full_code + '</td></tr>';
                                html += '<tr><td>Last Modified</td><td>' + d.db_values._bytemash_stock_last_modified + '</td></tr>';
                                
                                html += '<tr><td colspan="2"><strong>WooCommerce Object (Cache)</strong></td></tr>';
                                html += '<tr><td>Stock Quantity</td><td>' + d.wc_object_values.stock_quantity + '</td></tr>';
                                html += '<tr><td>Stock Status</td><td>' + d.wc_object_values.stock_status + '</td></tr>';
                                
                                html += '<tr><td colspan="2"><strong>Lookup Table (Catalog)</strong></td></tr>';
                                if (typeof d.lookup_table === 'object') {
                                    html += '<tr><td>Stock Quantity</td><td>' + d.lookup_table.stock_quantity + '</td></tr>';
                                    html += '<tr><td>Stock Status</td><td>' + d.lookup_table.stock_status + '</td></tr>';
                                } else {
                                    html += '<tr><td>Status</td><td>' + d.lookup_table + '</td></tr>';
                                }
                                
                                html += '<tr><td colspan="2"><strong>API Source File (JSON)</strong></td></tr>';
                                if (typeof d.api_file_values === 'object') {
                                    html += '<tr><td>Source File</td><td>' + d.api_file_values.file + ' (' + d.api_file_values.date + ')</td></tr>';
                                    html += '<tr><td>Stock in File</td><td><strong>' + d.api_file_values.stock + '</strong></td></tr>';
                                    html += '<tr><td>Full Code</td><td>' + d.api_file_values.fullCode + '</td></tr>';
                                } else {
                                    html += '<tr><td>Status</td><td>' + d.api_file_values + '</td></tr>';
                                }
                                
                                html += '</tbody></table>';
                                $result.html(html).show();
                            } else {
                                var msg = res.data.message || 'Error occurred';
                                if (res.data.similar && res.data.similar.length > 0) {
                                    msg += '<br><strong>Did you mean?</strong><ul>';
                                    $.each(res.data.similar, function(i, item) {
                                        msg += '<li>' + item.sku + ' (ID: ' + item.id + ')</li>';
                                    });
                                    msg += '</ul>';
                                }
                                $result.html('<div class="notice notice-error inline"><p>' + msg + '</p></div>').show();
                            }
                        }).fail(function() {
                            $btn.text('Check Stock');
                            $result.html('<div class="notice notice-error inline"><p>Request failed</p></div>').show();
                        }).always(function() {
                            $btn.prop('disabled', false);
                        });
                    });
                });
                </script>
            </div>
            
            <div class="bytemash-card" style="margin-top: 20px; max-width: 800px;">
                <h2><?php esc_html_e('Cleanup duplicate stock & price meta', 'bytemash-woo-sync'); ?></h2>
                <p>
                    <?php esc_html_e('If stock or prices show wrong or compounded values (e.g. millions), you may have duplicate meta rows from older syncs. This removes duplicates and keeps one value per product (the most recent).', 'bytemash-woo-sync'); ?>
                </p>
                <p>
                    <strong><?php esc_html_e('Meta cleaned:', 'bytemash-woo-sync'); ?></strong>
                    <?php esc_html_e('Stock, stock status, manage stock, backorders, price, regular price, sale price, and Bytemash hash/modified meta.', 'bytemash-woo-sync'); ?>
                </p>
                <p>
                    <button type="button" 
                            id="bytemash_cleanup_duplicate_meta_btn" 
                            class="button button-primary"
                            data-nonce="<?php echo esc_attr(wp_create_nonce('bytemash_woo_sync_nonce')); ?>">
                        <span class="dashicons dashicons-database-export" style="margin-top: 3px;"></span>
                        <?php esc_html_e('Cleanup duplicate meta', 'bytemash-woo-sync'); ?>
                    </button>
                    <span id="bytemash_cleanup_duplicate_meta_result" style="margin-left: 12px;"></span>
                </p>
                <script>
                jQuery(function($) {
                    $('#bytemash_cleanup_duplicate_meta_btn').on('click', function() {
                        var $btn = $(this), $result = $('#bytemash_cleanup_duplicate_meta_result');
                        $btn.prop('disabled', true);
                        $result.html('').removeClass('notice-success notice-error');
                        $.post(ajaxurl, {
                            action: 'bytemash_cleanup_duplicate_meta',
                            nonce: $btn.data('nonce')
                        }).done(function(res) {
                            if (res.success) {
                                $result.html(res.data.message || '').addClass('notice notice-success inline').css({ padding: '8px 12px', display: 'inline-block' });
                            } else {
                                $result.html(res.data && res.data.message ? res.data.message : 'Error').addClass('notice notice-error inline').css({ padding: '8px 12px', display: 'inline-block' });
                            }
                        }).fail(function() {
                            $result.html('Request failed').addClass('notice notice-error inline').css({ padding: '8px 12px', display: 'inline-block' });
                        }).always(function() {
                            $btn.prop('disabled', false);
                        });
                    });
                });
                </script>
            </div>
            
            <div class="bytemash-card" style="margin-top: 20px; max-width: 800px;">
                <h2><?php esc_html_e('Delete All Products', 'bytemash-woo-sync'); ?></h2>
                
                <p>
                    <strong><?php esc_html_e('Current Products:', 'bytemash-woo-sync'); ?></strong> 
                    <?php echo number_format($total_products); ?> products
                </p>
                
                <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
                    <h3 style="margin-top: 0; color: #856404;">⚠️ <?php esc_html_e('Warning', 'bytemash-woo-sync'); ?></h3>
                    <p style="margin: 0; color: #856404;">
                        <?php esc_html_e('This will permanently delete ALL products from your WooCommerce store. This action cannot be undone!', 'bytemash-woo-sync'); ?>
                    </p>
                    <ul style="color: #856404; margin-bottom: 0;">
                        <li><?php esc_html_e('All product data will be lost', 'bytemash-woo-sync'); ?></li>
                        <li><?php esc_html_e('All product variations will be deleted', 'bytemash-woo-sync'); ?></li>
                        <li><?php esc_html_e('All product images, categories, and meta will be removed', 'bytemash-woo-sync'); ?></li>
                        <li><?php esc_html_e('You will need to re-sync from Amrod to restore products', 'bytemash-woo-sync'); ?></li>
                    </ul>
                </div>
                
                <div style="background: #d1ecf1; border-left: 4px solid #0c5460; padding: 15px; margin: 20px 0;">
                    <h3 style="margin-top: 0; color: #0c5460;">ℹ️ <?php esc_html_e('When to Use This', 'bytemash-woo-sync'); ?></h3>
                    <ul style="color: #0c5460; margin-bottom: 0;">
                        <li><?php esc_html_e('To re-sync products with new Variable Product structure (v2.0)', 'bytemash-woo-sync'); ?></li>
                        <li><?php esc_html_e('To start fresh after testing', 'bytemash-woo-sync'); ?></li>
                        <li><?php esc_html_e('To fix corrupted product data', 'bytemash-woo-sync'); ?></li>
                        <li><?php esc_html_e('Before major sync changes', 'bytemash-woo-sync'); ?></li>
                    </ul>
                </div>
                
                <?php if ($total_products > 0): ?>
                <form method="post" onsubmit="return confirm('⚠️ ARE YOU ABSOLUTELY SURE?\n\nThis will DELETE ALL <?php echo $total_products; ?> PRODUCTS!\n\nType DELETE in the box below to confirm.');">
                    <?php wp_nonce_field('bytemash_delete_products', 'delete_nonce'); ?>
                    
                    <div style="margin: 20px 0;">
                        <label for="confirm_text" style="display: block; margin-bottom: 10px; font-weight: 600;">
                            <?php esc_html_e('Type DELETE to confirm:', 'bytemash-woo-sync'); ?>
                        </label>
                        <input type="text" 
                               id="confirm_text" 
                               name="confirm_text"
                               required
                               pattern="DELETE"
                               placeholder="Type DELETE"
                               style="padding: 8px 12px; width: 200px; font-size: 14px; border: 2px solid #d63638;"
                               oninput="this.value === 'DELETE' ? this.style.borderColor='#00a32a' : this.style.borderColor='#d63638'">
                    </div>
                    
                    <button type="submit" 
                            name="delete_all_products" 
                            class="button button-large"
                            style="background: #d63638; border-color: #d63638; color: white; font-weight: 600;"
                            onmouseover="this.style.background='#b32d2e'"
                            onmouseout="this.style.background='#d63638'">
                        <span class="dashicons dashicons-trash" style="margin-top: 4px;"></span>
                        <?php esc_html_e('Delete All Products', 'bytemash-woo-sync'); ?>
                    </button>
                    
                    <p style="color: #646970; margin-top: 15px;">
                        <em><?php printf(esc_html__('This will delete %s products. After deletion, run a full product sync to restore with new Variable Product structure.', 'bytemash-woo-sync'), number_format($total_products)); ?></em>
                    </p>
                </form>
                <?php else: ?>
                <div style="text-align: center; padding: 40px; color: #646970;">
                    <div style="font-size: 48px; margin-bottom: 20px;">✅</div>
                    <h3><?php esc_html_e('No Products Found', 'bytemash-woo-sync'); ?></h3>
                    <p><?php esc_html_e('Your store has no products. Run a full sync to import products from Amrod.', 'bytemash-woo-sync'); ?></p>
                    <a href="<?php echo admin_url('admin.php?page=bytemash-amrod-sync'); ?>" class="button button-primary button-large" style="margin-top: 15px;">
                        <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
                        <?php esc_html_e('Go to Dashboard & Sync Products', 'bytemash-woo-sync'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php
    }
    
    /**
     * Delete all products
     */
    private static function delete_all_products() {
        // Verify confirmation text
        if (!isset($_POST['confirm_text']) || $_POST['confirm_text'] !== 'DELETE') {
            add_settings_error(
                'bytemash_tools',
                'invalid_confirmation',
                __('Invalid confirmation text. Products not deleted.', 'bytemash-woo-sync'),
                'error'
            );
            return;
        }
        
        global $wpdb;
        
        // Get all product IDs (including variations)
        $product_ids = $wpdb->get_col("
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_type IN ('product', 'product_variation')
        ");
        
        $deleted_count = 0;
        
        // Delete in batches to avoid timeout
        foreach ($product_ids as $product_id) {
            wp_delete_post($product_id, true); // true = force delete (bypass trash)
            $deleted_count++;
        }
        
        // Clean up orphaned meta
        $wpdb->query("
            DELETE pm FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE p.ID IS NULL
        ");
        
        // Clean up orphaned term relationships
        $wpdb->query("
            DELETE tr FROM {$wpdb->term_relationships} tr
            LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID
            WHERE p.ID IS NULL
        ");
        
        // Log the deletion
        $logger = new ByteMash_Logger();
        $logger->log('warning', 'All products deleted via Admin Tools', array(
            'deleted_count' => $deleted_count,
            'user' => get_current_user_id(),
        ), 'admin_tools');
        
        add_settings_error(
            'bytemash_tools',
            'products_deleted',
            sprintf(__('✅ Successfully deleted %s products. You can now run a full sync to import products with the new Variable Product structure.', 'bytemash-woo-sync'), number_format($deleted_count)),
            'success'
        );
    }
}


