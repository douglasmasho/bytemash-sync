<?php
/**
 * Test Stock Modal Functionality
 * 
 * This script tests if the stock modal is working correctly
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    require_once('../../../wp-load.php');
}

// Check if user has admin permissions
if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to run this script.');
}

echo "<h1>Stock Modal Test</h1>";

// Get a product with stock
$products = wc_get_products(array(
    'limit' => 1,
    'status' => 'publish',
    'meta_query' => array(
        array(
            'key' => '_stock',
            'value' => 0,
            'compare' => '>'
        )
    )
));

if (empty($products)) {
    echo "<p style='color: red;'>No products with stock found. Please sync some products first.</p>";
    exit;
}

$product = $products[0];
$product_id = $product->get_id();

echo "<h2>Testing Product: " . $product->get_name() . "</h2>";
echo "<p>SKU: " . $product->get_sku() . "</p>";
echo "<p>Stock: " . $product->get_stock_quantity() . "</p>";

// Check if stock display is enabled
$show_stock = get_option('bytemash_show_stock_display', '1');
echo "<p>Stock Display Enabled: " . ($show_stock ? 'Yes' : 'No') . "</p>";

// Check if we have stock data
$reserved_stock = get_post_meta($product_id, '_amrod_reserved_stock', true);
$incoming_stock_json = get_post_meta($product_id, '_amrod_incoming_stock', true);
$incoming_stock = !empty($incoming_stock_json) ? json_decode($incoming_stock_json, true) : null;

echo "<p>Reserved Stock: " . $reserved_stock . "</p>";
echo "<p>Incoming Stock: " . print_r($incoming_stock, true) . "</p>";

// Test the display function
echo "<h3>Testing Stock Display Function</h3>";

// Set global product for the function
global $product;
$product = $products[0];

// Include the plugin class
if (class_exists('ByteMash_Woo_Sync')) {
    $plugin = new ByteMash_Woo_Sync();
    
    echo "<div style='border: 1px solid #ccc; padding: 20px; margin: 20px 0;'>";
    echo "<h4>Stock Display Output:</h4>";
    $plugin->display_enhanced_stock();
    echo "</div>";
    
    echo "<h4>HTML Source:</h4>";
    echo "<pre>";
    ob_start();
    $plugin->display_enhanced_stock();
    $output = ob_get_clean();
    echo htmlspecialchars($output);
    echo "</pre>";
    
} else {
    echo "<p style='color: red;'>ByteMash_Woo_Sync class not found!</p>";
}

echo "<h3>JavaScript Test</h3>";
echo "<p>Open browser console and check for:</p>";
echo "<ul>";
echo "<li>'Check Stock button clicked' message when clicking the button</li>";
echo "<li>Stock data object in console</li>";
echo "<li>Any JavaScript errors</li>";
echo "</ul>";

echo "<h3>Modal HTML Test</h3>";
echo "<p>Check if modal HTML is present in page source:</p>";
echo "<pre>";
if (class_exists('ByteMash_Woo_Sync')) {
    $plugin = new ByteMash_Woo_Sync();
    ob_start();
    $plugin->render_stock_modal_template();
    $modal_html = ob_get_clean();
    echo htmlspecialchars($modal_html);
}
echo "</pre>";

echo "<h3>CSS Test</h3>";
echo "<p>Check if CSS is loaded:</p>";
echo "<p>Look for: <code>.bytemash-check-stock-btn</code> styles in page source</p>";

echo "<h3>Next Steps</h3>";
echo "<ol>";
echo "<li>Visit a product page with stock</li>";
echo "<li>Look for the 'Check Stock' button</li>";
echo "<li>Click the button and check browser console</li>";
echo "<li>Check if modal appears</li>";
echo "</ol>";
?>
