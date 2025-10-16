<?php
/**
 * Cleanup Script: Convert Variable Products to Simple Products
 * 
 * This script finds products that should be simple but are incorrectly set as variable,
 * and converts them to simple products by removing variations and attributes.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    require_once('../../../wp-load.php');
}

// Check if user has admin permissions
if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to run this script.');
}

echo "<h1>Variable Product Cleanup Script</h1>";
echo "<p>This script will convert incorrectly set variable products to simple products.</p>";

// Get all variable products
$variable_products = wc_get_products(array(
    'type' => 'variable',
    'limit' => -1,
    'status' => 'publish'
));

echo "<h2>Found " . count($variable_products) . " variable products</h2>";

$converted_count = 0;
$errors = array();

foreach ($variable_products as $product) {
    $product_id = $product->get_id();
    $sku = $product->get_sku();
    $variations = $product->get_children();
    
    echo "<h3>Processing: {$sku} (ID: {$product_id})</h3>";
    echo "<p>Variations: " . count($variations) . "</p>";
    
    // Check if this product should be simple
    // Criteria: Has no variations OR has only 1 variation with same SKU as parent
    $should_be_simple = false;
    
    if (count($variations) === 0) {
        $should_be_simple = true;
        echo "<p style='color: orange;'>No variations found - should be simple</p>";
    } elseif (count($variations) === 1) {
        $variation = wc_get_product($variations[0]);
        if ($variation && $variation->get_sku() === $sku) {
            $should_be_simple = true;
            echo "<p style='color: orange;'>Only has 1 variation with same SKU - should be simple</p>";
        }
    }
    
    if ($should_be_simple) {
        echo "<p style='color: blue;'>Converting to simple product...</p>";
        
        try {
            // Delete all variations first
            foreach ($variations as $variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation) {
                    $variation_sku = $variation->get_sku();
                    echo "<p>Deleting variation: {$variation_sku}</p>";
                    wp_delete_post($variation_id, true);
                }
            }
            
            // Delete the variable product
            wp_delete_post($product_id, true);
            
            // Create new simple product with same data
            $new_product = new WC_Product_Simple();
            $new_product->set_sku($sku);
            $new_product->set_name($product->get_name());
            $new_product->set_description($product->get_description());
            $new_product->set_short_description($product->get_short_description());
            $new_product->set_regular_price($product->get_regular_price());
            $new_product->set_sale_price($product->get_sale_price());
            $new_product->set_manage_stock($product->get_manage_stock());
            $new_product->set_stock_quantity($product->get_stock_quantity());
            $new_product->set_stock_status($product->get_stock_status());
            $new_product->set_weight($product->get_weight());
            $new_product->set_length($product->get_length());
            $new_product->set_width($product->get_width());
            $new_product->set_height($product->get_height());
            $new_product->set_category_ids($product->get_category_ids());
            $new_product->set_tag_ids($product->get_tag_ids());
            $new_product->set_featured($product->get_featured());
            $new_product->set_catalog_visibility($product->get_catalog_visibility());
            $new_product->set_sold_individually($product->get_sold_individually());
            $new_product->set_purchase_note($product->get_purchase_note());
            $new_product->set_menu_order($product->get_menu_order());
            $new_product->set_status($product->get_status());
            
            // Save the new simple product
            $new_product_id = $new_product->save();
            
            if ($new_product_id) {
                // Copy images
                $image_ids = $product->get_gallery_image_ids();
                if (!empty($image_ids)) {
                    $new_product->set_gallery_image_ids($image_ids);
                    $new_product->save();
                }
                
                // Copy meta data (excluding variable product specific meta)
                $meta_to_copy = array(
                    '_thumbnail_id',
                    '_product_image_gallery',
                    '_visibility',
                    '_stock',
                    '_stock_status',
                    '_manage_stock',
                    '_backorders',
                    '_sold_individually',
                    '_weight',
                    '_length',
                    '_width',
                    '_height',
                    '_sku',
                    '_price',
                    '_regular_price',
                    '_sale_price',
                    '_sale_price_dates_from',
                    '_sale_price_dates_to',
                    '_featured',
                    '_downloadable',
                    '_virtual',
                    '_download_limit',
                    '_download_expiry',
                    '_purchase_note',
                    '_product_url',
                    '_button_text',
                    '_amrod_',
                    '_woocommerce_'
                );
                
                global $wpdb;
                $all_meta = $wpdb->get_results($wpdb->prepare(
                    "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
                    $product_id
                ));
                
                foreach ($all_meta as $meta) {
                    $should_copy = false;
                    foreach ($meta_to_copy as $prefix) {
                        if (strpos($meta->meta_key, $prefix) === 0) {
                            $should_copy = true;
                            break;
                        }
                    }
                    
                    if ($should_copy && !in_array($meta->meta_key, array('_product_attributes', '_default_attributes'))) {
                        update_post_meta($new_product_id, $meta->meta_key, $meta->meta_value);
                    }
                }
                
                echo "<p style='color: green;'>✅ Successfully converted to simple product (ID: {$new_product_id})</p>";
                $converted_count++;
            } else {
                echo "<p style='color: red;'>❌ Failed to create simple product</p>";
                $errors[] = "Failed to create simple product for SKU: {$sku}";
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
            $errors[] = "Error converting SKU {$sku}: " . $e->getMessage();
        }
    } else {
        echo "<p style='color: green;'>✅ Correctly set as variable product</p>";
    }
    
    echo "<hr>";
}

echo "<h2>Cleanup Complete</h2>";
echo "<p><strong>Converted:</strong> {$converted_count} products</p>";
echo "<p><strong>Errors:</strong> " . count($errors) . "</p>";

if (!empty($errors)) {
    echo "<h3>Errors:</h3>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li style='color: red;'>{$error}</li>";
    }
    echo "</ul>";
}

echo "<p><strong>Note:</strong> This script has completed. You can delete this file after running it.</p>";
?>
