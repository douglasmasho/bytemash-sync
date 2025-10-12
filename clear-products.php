<?php
/**
 * Temporary Script: Clear All WooCommerce Products
 * 
 * Usage: 
 * 1. Upload this file to your WordPress root directory
 * 2. Visit: http://yourdomain.com/clear-products.php
 * 3. DELETE THIS FILE after use for security!
 */

// Load WordPress
require_once('wp-load.php');

// Security check - only allow admin
if (!current_user_can('manage_woocommerce')) {
    die('Access denied. You must be logged in as admin.');
}

// Add confirmation parameter
if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Clear All Products</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 50px; text-align: center; }
            .warning { background: #ff9800; color: white; padding: 20px; margin: 20px auto; max-width: 600px; border-radius: 5px; }
            .button { display: inline-block; padding: 15px 30px; margin: 10px; text-decoration: none; border-radius: 5px; font-weight: bold; }
            .delete { background: #d32f2f; color: white; }
            .cancel { background: #1976d2; color: white; }
        </style>
    </head>
    <body>
        <h1>⚠️ Clear All Products</h1>
        <div class="warning">
            <h2>WARNING!</h2>
            <p>This will permanently delete ALL products and variations from your WooCommerce store.</p>
            <p>This action cannot be undone!</p>
        </div>
        <a href="?confirm=yes" class="button delete">Yes, Delete All Products</a>
        <a href="<?php echo admin_url(); ?>" class="button cancel">Cancel</a>
    </body>
    </html>
    <?php
    exit;
}

// Process deletion
echo '<!DOCTYPE html><html><head><title>Deleting Products...</title><style>body{font-family:Arial;padding:50px;}</style></head><body>';
echo '<h1>Deleting Products...</h1>';

// Get all products
$args = array(
    'post_type' => array('product', 'product_variation'),
    'posts_per_page' => -1,
    'post_status' => 'any',
);

$products = get_posts($args);
$total = count($products);

echo "<p>Found {$total} products to delete...</p>";

$deleted = 0;
foreach ($products as $product) {
    wp_delete_post($product->ID, true); // Force delete (skip trash)
    $deleted++;
    
    if ($deleted % 50 === 0) {
        echo "<p>Deleted {$deleted} of {$total}...</p>";
        flush();
    }
}

// Clear orphaned meta
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts})");

// Clear orphaned term relationships
$wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id NOT IN (SELECT ID FROM {$wpdb->posts})");

// Clear transients
delete_transient('wc_products_onsale');
delete_transient('wc_featured_products');

echo "<h2 style='color: green;'>✅ Successfully deleted {$deleted} products!</h2>";
echo "<p><a href='" . admin_url('edit.php?post_type=product') . "'>View Products</a> | <a href='" . admin_url() . "'>Dashboard</a></p>";
echo "<p style='color: red; font-weight: bold;'>⚠️ IMPORTANT: DELETE THIS FILE (clear-products.php) NOW FOR SECURITY!</p>";
echo '</body></html>';
?>

