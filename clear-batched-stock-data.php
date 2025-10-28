<?php
/**
 * Clear incorrectly batched stock data
 * 
 * This script clears stock data that was incorrectly batched due to pattern matching
 * in the stock sync process. Run this after fixing the pattern matching issue.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    // Load WordPress
    require_once('../../../wp-load.php');
}

// Only run for administrators
if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to run this script.');
}

echo "<h2>Clearing Incorrectly Batched Stock Data</h2>";

// Get all products with stock data
global $wpdb;
$products_with_stock = $wpdb->get_results("
    SELECT post_id, meta_value 
    FROM {$wpdb->postmeta} 
    WHERE meta_key = '_amrod_stock_data' 
    AND meta_value != '' 
    AND meta_value != 'a:0:{}'
");

$cleared_count = 0;
$total_products = count($products_with_stock);

echo "<p>Found {$total_products} products with stock data.</p>";

foreach ($products_with_stock as $product) {
    $product_id = $product->post_id;
    $stock_data = maybe_unserialize($product->meta_value);
    
    if (!is_array($stock_data)) {
        continue;
    }
    
    // Check if this product has multiple stock entries with the same color but different codes
    // This indicates incorrect batching
    $color_groups = array();
    foreach ($stock_data as $item) {
        $color = $item['color'] ?? '';
        if (!isset($color_groups[$color])) {
            $color_groups[$color] = array();
        }
        $color_groups[$color][] = $item;
    }
    
    $has_batching_issue = false;
    foreach ($color_groups as $color => $items) {
        if (count($items) > 1) {
            // Check if all items have the same stock quantity (indicating batching)
            $stock_quantities = array_column($items, 'stock_on_hand');
            if (count(array_unique($stock_quantities)) === 1 && $stock_quantities[0] > 0) {
                $has_batching_issue = true;
                break;
            }
        }
    }
    
    if ($has_batching_issue) {
        // Clear the stock data for this product
        delete_post_meta($product_id, '_amrod_stock_data');
        $cleared_count++;
        
        echo "<p>Cleared batched stock data for product ID: {$product_id}</p>";
    }
}

echo "<h3>Summary</h3>";
echo "<p>Cleared stock data for {$cleared_count} products that had incorrectly batched data.</p>";
echo "<p>You can now run a fresh stock sync to get correct individual stock data for each variation.</p>";

// Add a link to run stock sync
echo "<p><a href='" . admin_url('admin.php?page=bytemash-amrod-sync') . "' class='button button-primary'>Go to Sync Dashboard</a></p>";
?>

