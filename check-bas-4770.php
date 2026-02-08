<?php
/**
 * Diagnostic Script: Check if BAS-4770 exists in WooCommerce
 * Usage: wp eval-file check-bas-4770.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/');
    require_once(ABSPATH . 'wp-load.php');
}

global $wpdb;

echo "=== BAS-4770 Diagnostic ===\n\n";

// 1. Check if product exists with this SKU
echo "1. Checking for products with SKU 'BAS-4770':\n";
$results = $wpdb->get_results("
    SELECT p.ID, p.post_type, p.post_title, pm.meta_value as sku
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
    WHERE pm.meta_key IN ('_sku', '_amrod_full_code')
    AND pm.meta_value = 'BAS-4770'
");

if (empty($results)) {
    echo "   ❌ NO products found with SKU 'BAS-4770'\n\n";
} else {
    foreach ($results as $row) {
        echo "   ✓ Found: ID={$row->ID}, Type={$row->post_type}, Title={$row->post_title}, SKU={$row->sku}\n";
    }
    echo "\n";
}

// 2. Check for case-insensitive match
echo "2. Checking for case-insensitive matches:\n";
$results_ci = $wpdb->get_results("
    SELECT p.ID, p.post_type, p.post_title, pm.meta_value as sku
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
    WHERE pm.meta_key IN ('_sku', '_amrod_full_code')
    AND LOWER(pm.meta_value) = LOWER('BAS-4770')
");

if (empty($results_ci)) {
    echo "   ❌ NO products found (case-insensitive)\n\n";
} else {
    foreach ($results_ci as $row) {
        echo "   ✓ Found: ID={$row->ID}, Type={$row->post_type}, Title={$row->post_title}, SKU={$row->sku}\n";
    }
    echo "\n";
}

// 3. Search for similar SKUs
echo "3. Searching for similar SKUs (BAS-4770%):\n";
$similar = $wpdb->get_results("
    SELECT p.ID, p.post_type, p.post_title, pm.meta_value as sku
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
    WHERE pm.meta_key IN ('_sku', '_amrod_full_code')
   AND pm.meta_value LIKE 'BAS-4770%'
    LIMIT 10
");

if (empty($similar)) {
    echo "   ❌ NO similar products found\n\n";
} else {
    foreach ($similar as $row) {
        echo "   ✓ Found: ID={$row->ID}, Type={$row->post_type}, SKU={$row->sku}\n";
        
        // Get current stock for this product
        $stock = get_post_meta($row->ID, '_stock', true);
        $stock_status = get_post_meta($row->ID, '_stock_status', true);
        echo "      Current Stock: {$stock}, Status: {$stock_status}\n";
    }
    echo "\n";
}

// 4. Check API response for BAS-4770
echo "4. Checking API response file for BAS-4770:\n";
$response_file = __DIR__ . '/responses/stock/response.json';
if (file_exists($response_file)) {
    $content = file_get_contents($response_file);
    $count = substr_count($content, 'BAS-4770');
    echo "   Found 'BAS-4770' {$count} times in response.json\n\n";
    
    if ($count > 0) {
        // Extract one example
        $decoded = json_decode($content, true);
        foreach ($decoded as $item) {
            if (isset($item['fullCode']) && strpos($item['fullCode'], 'BAS-4770') !== false) {
                echo "   Example from API:\n";
                echo "   " . json_encode($item, JSON_PRETTY_PRINT) . "\n";
                break;
            }
        }
    }
} else {
    echo "   ❌ response.json not found\n\n";
}

echo "\n=== End Diagnostic ===\n";
