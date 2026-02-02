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


