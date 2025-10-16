<?php
/**
 * Quick diagnostic script to check product stock in database
 * 
 * Usage: Access via browser: /wp-content/plugins/bytemash-woo-sync/check-product-stock.php?sku=YOUR-SKU
 */

// Load WordPress
require_once(__DIR__ . '/../../../../../wp-load.php');

// Security check
if (!current_user_can('manage_woocommerce')) {
    die('Access denied');
}

// Get SKU from URL
$sku = isset($_GET['sku']) ? sanitize_text_field($_GET['sku']) : '';

if (empty($sku)) {
    ?>
    <h2>Check Product Stock</h2>
    <form method="get">
        <label>Enter Product SKU: <input type="text" name="sku" required></label>
        <button type="submit">Check Stock</button>
    </form>
    <?php
    exit;
}

// Find product by SKU
$product_id = wc_get_product_id_by_sku($sku);

if (!$product_id) {
    echo "<h2>Product not found</h2>";
    echo "<p>SKU: <strong>" . esc_html($sku) . "</strong></p>";
    echo '<p><a href="?">Try another SKU</a></p>';
    exit;
}

// Get product
$product = wc_get_product($product_id);

// Get database values directly
global $wpdb;
$db_meta = $wpdb->get_results($wpdb->prepare(
    "SELECT meta_key, meta_value FROM {$wpdb->postmeta} 
    WHERE post_id = %d 
    AND meta_key IN ('_sku', '_stock', '_stock_status', '_manage_stock', '_backorders')
    ORDER BY meta_key",
    $product_id
), ARRAY_A);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Stock Check: <?php echo esc_html($sku); ?></title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; max-width: 800px; margin: 0 auto; }
        h2 { color: #333; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #f4f4f4; font-weight: bold; }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .warning { color: #ffc107; font-weight: bold; }
        .info-box { background: #e7f3ff; border-left: 4px solid #2196F3; padding: 15px; margin: 20px 0; }
        .back-link { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; }
        .back-link:hover { background: #0056b3; }
    </style>
</head>
<body>
    <h2>Product Stock Check</h2>
    
    <div class="info-box">
        <strong>Product ID:</strong> <?php echo esc_html($product_id); ?><br>
        <strong>SKU:</strong> <?php echo esc_html($sku); ?><br>
        <strong>Product Type:</strong> <?php echo esc_html($product->get_type()); ?><br>
        <strong>Product Name:</strong> <?php echo esc_html($product->get_name()); ?>
    </div>
    
    <h3>WooCommerce Object Values</h3>
    <table>
        <tr>
            <th>Property</th>
            <th>Value</th>
            <th>Status</th>
        </tr>
        <tr>
            <td>Managing Stock</td>
            <td><?php echo $product->managing_stock() ? 'Yes' : 'No'; ?></td>
            <td><?php echo $product->managing_stock() ? '<span class="success">✓</span>' : '<span class="warning">!</span>'; ?></td>
        </tr>
        <tr>
            <td>Stock Quantity</td>
            <td><?php echo $product->get_stock_quantity() ?? 'NULL'; ?></td>
            <td><?php echo $product->get_stock_quantity() > 0 ? '<span class="success">✓</span>' : '<span class="error">✗</span>'; ?></td>
        </tr>
        <tr>
            <td>Stock Status</td>
            <td><?php echo esc_html($product->get_stock_status()); ?></td>
            <td><?php echo $product->get_stock_status() === 'instock' ? '<span class="success">✓</span>' : '<span class="error">✗</span>'; ?></td>
        </tr>
        <tr>
            <td>Is In Stock?</td>
            <td><?php echo $product->is_in_stock() ? 'Yes' : 'No'; ?></td>
            <td><?php echo $product->is_in_stock() ? '<span class="success">✓</span>' : '<span class="error">✗</span>'; ?></td>
        </tr>
        <tr>
            <td>Backorders Allowed</td>
            <td><?php echo esc_html($product->get_backorders()); ?></td>
            <td>-</td>
        </tr>
    </table>
    
    <h3>Database Meta Values (Direct)</h3>
    <table>
        <tr>
            <th>Meta Key</th>
            <th>Meta Value</th>
        </tr>
        <?php foreach ($db_meta as $meta): ?>
        <tr>
            <td><code><?php echo esc_html($meta['meta_key']); ?></code></td>
            <td><code><?php echo esc_html($meta['meta_value']); ?></code></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <?php if ($product->is_type('variable')): ?>
        <h3>Variations</h3>
        <table>
            <tr>
                <th>Variation ID</th>
                <th>SKU</th>
                <th>Stock Qty</th>
                <th>Stock Status</th>
            </tr>
            <?php
            $variations = $product->get_children();
            foreach ($variations as $variation_id):
                $variation = wc_get_product($variation_id);
                ?>
                <tr>
                    <td><?php echo esc_html($variation_id); ?></td>
                    <td><?php echo esc_html($variation->get_sku()); ?></td>
                    <td><?php echo esc_html($variation->get_stock_quantity() ?? 'NULL'); ?></td>
                    <td><?php echo esc_html($variation->get_stock_status()); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
    
    <h3>Recommendation</h3>
    <?php if ($product->is_in_stock() && $product->get_stock_quantity() > 0): ?>
        <div class="info-box" style="border-color: #28a745; background: #d4edda;">
            <span class="success">✓ Product stock is correctly configured!</span>
        </div>
    <?php else: ?>
        <div class="info-box" style="border-color: #dc3545; background: #f8d7da;">
            <span class="error">✗ Stock configuration issue detected!</span><br><br>
            <strong>Fix:</strong>
            <ol>
                <li>Go to Amrod Sync → Dashboard</li>
                <li>Click "Sync Stock" button</li>
                <li>Wait for sync to complete</li>
                <li>Refresh this page to verify</li>
            </ol>
        </div>
    <?php endif; ?>
    
    <a href="?" class="back-link">Check Another Product</a>
    <a href="<?php echo admin_url('admin.php?page=bytemash-amrod-sync'); ?>" class="back-link">Go to Dashboard</a>
</body>
</html>

