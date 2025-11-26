<?php
/**
 * Stock Report Admin Page
 * 
 * Displays detailed stock level information for all products
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_Admin_Stock_Report {
    
    /**
     * Render stock report page
     */
    public static function render() {
        // Get all products
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        );
        
        $products = get_posts($args);
        
        // Count stats
        $total_products = count($products);
        $products_with_stock = 0;
        $products_in_stock = 0;
        $products_out_of_stock = 0;
        $products_no_stock_data = 0;
        
        $stock_details = array();
        
        foreach ($products as $post) {
            $product = wc_get_product($post->ID);
            
            if (!$product) {
                continue;
            }
            
            $sku = $product->get_sku();
            $manages_stock = $product->get_manage_stock();
            $stock_quantity = $product->get_stock_quantity();
            $stock_status = $product->get_stock_status();
            
            if ($manages_stock && $stock_quantity !== null) {
                $products_with_stock++;
                
                if ($stock_quantity > 0) {
                    $products_in_stock++;
                } else {
                    $products_out_of_stock++;
                }
                
                $stock_details[] = array(
                    'id' => $post->ID,
                    'name' => $product->get_name(),
                    'sku' => $sku,
                    'stock_qty' => $stock_quantity,
                    'stock_status' => $stock_status,
                );
            } else {
                $products_no_stock_data++;
            }
        }
        
        // Sort by stock quantity (descending)
        usort($stock_details, function($a, $b) {
            return $b['stock_qty'] - $a['stock_qty'];
        });
        ?>
        
        <div class="wrap bytemash-admin-wrap">
            <h1><?php esc_html_e('Stock Levels Report', 'bytemash-woo-sync'); ?></h1>
            
            <div class="bytemash-stats-grid" style="margin: 20px 0;">
                <div class="bytemash-stat-card">
                    <div class="bytemash-stat-icon">📦</div>
                    <div class="bytemash-stat-content">
                        <div class="bytemash-stat-value"><?php echo number_format($total_products); ?></div>
                        <div class="bytemash-stat-label"><?php esc_html_e('Total Products', 'bytemash-woo-sync'); ?></div>
                    </div>
                </div>
                
                <div class="bytemash-stat-card">
                    <div class="bytemash-stat-icon">✅</div>
                    <div class="bytemash-stat-content">
                        <div class="bytemash-stat-value"><?php echo number_format($products_with_stock); ?></div>
                        <div class="bytemash-stat-label"><?php esc_html_e('With Stock Data', 'bytemash-woo-sync'); ?></div>
                    </div>
                </div>
                
                <div class="bytemash-stat-card">
                    <div class="bytemash-stat-icon success">📈</div>
                    <div class="bytemash-stat-content">
                        <div class="bytemash-stat-value" style="color: #00a32a;"><?php echo number_format($products_in_stock); ?></div>
                        <div class="bytemash-stat-label"><?php esc_html_e('In Stock (> 0)', 'bytemash-woo-sync'); ?></div>
                    </div>
                </div>
                
                <div class="bytemash-stat-card">
                    <div class="bytemash-stat-icon error">📉</div>
                    <div class="bytemash-stat-content">
                        <div class="bytemash-stat-value" style="color: #d63638;"><?php echo number_format($products_out_of_stock); ?></div>
                        <div class="bytemash-stat-label"><?php esc_html_e('Out of Stock (0)', 'bytemash-woo-sync'); ?></div>
                    </div>
                </div>
                
                <div class="bytemash-stat-card">
                    <div class="bytemash-stat-icon">⚠️</div>
                    <div class="bytemash-stat-content">
                        <div class="bytemash-stat-value" style="color: #dba617;"><?php echo number_format($products_no_stock_data); ?></div>
                        <div class="bytemash-stat-label"><?php esc_html_e('No Stock Data', 'bytemash-woo-sync'); ?></div>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($stock_details)): ?>
            <div class="bytemash-card" style="margin-top: 30px;">
                <h2><?php printf(esc_html__('Products with Stock Levels (%s)', 'bytemash-woo-sync'), number_format(count($stock_details))); ?></h2>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 80px;"><?php esc_html_e('ID', 'bytemash-woo-sync'); ?></th>
                            <th style="width: 150px;"><?php esc_html_e('SKU', 'bytemash-woo-sync'); ?></th>
                            <th><?php esc_html_e('Product Name', 'bytemash-woo-sync'); ?></th>
                            <th style="width: 120px;"><?php esc_html_e('Stock Qty', 'bytemash-woo-sync'); ?></th>
                            <th style="width: 120px;"><?php esc_html_e('Status', 'bytemash-woo-sync'); ?></th>
                            <th style="width: 100px;"><?php esc_html_e('Actions', 'bytemash-woo-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stock_details as $item): ?>
                        <tr>
                            <td><strong>#<?php echo $item['id']; ?></strong></td>
                            <td><code style="background: #f0f0f1; padding: 3px 6px; border-radius: 3px;"><?php echo esc_html($item['sku']); ?></code></td>
                            <td><?php echo esc_html($item['name']); ?></td>
                            <td><strong style="font-size: 16px;"><?php echo number_format($item['stock_qty']); ?></strong></td>
                            <td>
                                <span class="<?php echo $item['stock_qty'] > 0 ? 'bytemash-badge-success' : 'bytemash-badge-error'; ?>" style="display: inline-block; padding: 3px 10px; border-radius: 3px; font-size: 12px; font-weight: 600;">
                                    <?php echo $item['stock_qty'] > 0 ? '✅ ' . esc_html__('In Stock', 'bytemash-woo-sync') : '❌ ' . esc_html__('Out of Stock', 'bytemash-woo-sync'); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo get_edit_post_link($item['id']); ?>" class="button button-small" target="_blank">
                                    <?php esc_html_e('Edit', 'bytemash-woo-sync'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="bytemash-card" style="margin-top: 30px; text-align: center; padding: 60px 20px;">
                <div style="font-size: 48px; margin-bottom: 20px;">📦</div>
                <h2 style="color: #d63638;"><?php esc_html_e('No Products with Stock Data Found', 'bytemash-woo-sync'); ?></h2>
                <p style="color: #646970; margin: 15px 0;">
                    <?php esc_html_e('Run a stock sync from the dashboard to populate stock levels.', 'bytemash-woo-sync'); ?>
                </p>
                <a href="<?php echo admin_url('admin.php?page=bytemash-amrod-sync'); ?>" class="button button-primary button-large" style="margin-top: 20px;">
                    <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
                    <?php esc_html_e('Go to Dashboard & Sync Stock', 'bytemash-woo-sync'); ?>
                </a>
            </div>
            <?php endif; ?>
            
            <div class="bytemash-card" style="margin-top: 20px; background: #f6f7f7; border-left: 4px solid #2271b1;">
                <p style="margin: 0; color: #1d2327;">
                    <strong><?php esc_html_e('Note:', 'bytemash-woo-sync'); ?></strong> 
                    <?php esc_html_e('This report shows products where "Manage stock" is enabled and stock quantity is set.', 'bytemash-woo-sync'); ?>
                </p>
            </div>
        </div>
        
        <style>
        .bytemash-badge-success {
            background: #d5f4e6;
            color: #00a32a;
        }
        .bytemash-badge-error {
            background: #f8d7da;
            color: #d63638;
        }
        </style>
        
        <?php
    }
}


