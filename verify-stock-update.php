<?php
/**
 * Verify Stock Update Script
 * Usage: wp eval-file verify-stock-update.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/');
    require_once(ABSPATH . 'wp-load.php');
}

// Check a few products from the log
$test_products = array(
    103692 => 'GIFT-9800-BL (expected: 10340)',
    104277 => 'BD-AL-27-A-N-M (expected: 257)',
    108755 => 'BAS-4764-BL-S (expected: 118)',
    109416 => 'BAS-809-BU-M (expected: 312)',
);

echo "=== Stock Update Verification ===\n\n";

foreach ($test_products as $product_id => $description) {
    $stock = get_post_meta($product_id, '_stock', true);
    $stock_status = get_post_meta($product_id, '_stock_status', true);
    $amrod_detail = get_post_meta($product_id, '_amrod_stock_detail', true);
    
    echo "Product ID: {$product_id}\n";
    echo "Description: {$description}\n";
    echo "_stock meta: {$stock}\n";
    echo "_stock_status: {$stock_status}\n";
    
    if (is_array($amrod_detail) && isset($amrod_detail['stock'])) {
        echo "_amrod_stock_detail['stock']: {$amrod_detail['stock']}\n";
    } else {
        echo "_amrod_stock_detail: " . (is_array($amrod_detail) ? 'array (no stock key)' : 'not set') . "\n";
    }
    
    // Check WooCommerce product object
    $product = wc_get_product($product_id);
    if ($product) {
        echo "WC get_stock_quantity(): " . $product->get_stock_quantity() . "\n";
        echo "WC get_stock_status(): " . $product->get_stock_status() . "\n";
    }
    
    echo "\n";
}

echo "=== End Verification ===\n";
