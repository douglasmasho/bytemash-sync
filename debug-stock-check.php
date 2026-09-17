<?php
/**
 * Verification Script: Check Stock Mapping and Values
 * 
 * Usage: wp eval-file debug-stock-check.php <sku>
 */

if (!defined('ABSPATH')) {
    require_once dirname(__FILE__) . '/wp-load.php';
}

$sku = isset($args[0]) ? $args[0] : '';
if (empty($sku)) {
    // Try to get from GET parameter if running via browser
    $sku = isset($_GET['sku']) ? $_GET['sku'] : '';
}

if (empty($sku)) {
    echo "Please provide a SKU to check.\n";
    echo "Usage: wp eval-file debug-stock-check.php <sku>\n";
    echo "Or via browser: /debug-stock-check.php?sku=<sku>\n";
    exit;
}

echo "Checking SKU: " . $sku . "\n";
echo "----------------------------------------\n";

global $wpdb;

// 1. Check if SKU exists in DB
$product_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s", $sku));

if (!$product_id) {
    // Try finding by _amrod_full_code
    $product_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_amrod_full_code' AND meta_value = %s", $sku));
}

if (!$product_id) {
    echo "❌ Product NOT FOUND in database via _sku or _amrod_full_code.\n";
    
    // Check if it exists with different casing
    $like_sku = '%' . $wpdb->esc_like($sku) . '%';
    $similar = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_sku', '_amrod_full_code') AND meta_value LIKE %s LIMIT 5", $like_sku));
    
    if (!empty($similar)) {
        echo "Found similar SKUs:\n";
        foreach ($similar as $s) {
            echo " - ID: {$s->post_id}, SKU: {$s->meta_value}\n";
        }
    }
    exit;
}

echo "✅ Product FOUND: ID $product_id\n";
$product = wc_get_product($product_id);
if (!$product) {
    echo "❌ Could not load WC_Product object.\n";
    exit;
}

echo "Type: " . $product->get_type() . "\n";
echo "Name: " . $product->get_name() . "\n";
echo "----------------------------------------\n";
echo "Stock Status (DB _stock_status): " . get_post_meta($product_id, '_stock_status', true) . "\n";
echo "Stock Quantity (DB _stock): " . get_post_meta($product_id, '_stock', true) . "\n";
echo "Stock Managed (DB _manage_stock): " . get_post_meta($product_id, '_manage_stock', true) . "\n";
echo "----------------------------------------\n";
echo "WC Product Object Status: " . $product->get_stock_status() . "\n";
echo "WC Product Object Quantity: " . $product->get_stock_quantity() . "\n";
echo "----------------------------------------\n";
echo "Amrod Hash (DB _bytemash_stock_hash): " . get_post_meta($product_id, '_bytemash_stock_hash', true) . "\n";
echo "Amrod Modified (DB _bytemash_stock_last_modified): " . get_post_meta($product_id, '_bytemash_stock_last_modified', true) . "\n";

// Check Lookup Table
$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
$lookup_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$lookup_table} WHERE product_id = %d", $product_id));
if ($lookup_row) {
    echo "Lookup Table Quantity: " . $lookup_row->stock_quantity . "\n";
    echo "Lookup Table Status: " . $lookup_row->stock_status . "\n";
} else {
    echo "❌ Not found in wc_product_meta_lookup!\n";
}

echo "----------------------------------------\n";
echo "Done.\n";
