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
        
        // Get product count
        $product_count = wp_count_posts('product');
        $total_products = $product_count->publish + $product_count->draft + $product_count->pending;
        ?>
        
        <div class="wrap bytemash-admin-wrap">
            <h1><?php esc_html_e('Admin Tools', 'bytemash-woo-sync'); ?></h1>
            
            <div class="bytemash-card" style="margin-top: 20px; max-width: 800px;">
                <h2>🗑️ <?php esc_html_e('Delete All Products', 'bytemash-woo-sync'); ?></h2>
                
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


