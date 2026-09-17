<?php
/**
 * Temporary Script: Delete ALL WooCommerce Product Categories
 *
 * Unlike the plugin's own "Delete Synced Categories" tool (which only
 * removes categories created by the Amrod sync), this deletes every
 * product_cat term on the site - including manually-created ones. Products
 * are NOT deleted; they simply lose their category assignment.
 *
 * Usage:
 * 1. Upload this file ANYWHERE inside your WordPress install (the WP root,
 *    or inside a plugin/theme folder like wp-content/plugins/your-plugin/ -
 *    it locates wp-load.php automatically either way).
 * 2. Visit it in your browser, e.g.:
 *      http://yourdomain.com/delete-all-categories.php
 *      http://yourdomain.com/wp-content/plugins/your-plugin/delete-all-categories.php
 * 3. DELETE THIS FILE after use for security!
 *
 * Runs in small batches (with output flushed after each one) so it can't
 * silently hang the page even on a store with a very large category tree.
 */

// Locate wp-load.php whether this file sits in the WP root or several
// levels deep inside wp-content/plugins/... - a hardcoded relative path
// only works from the root and otherwise fatals with a 500 before WP even
// loads (no error-log entry from WordPress itself, since WP never started).
$wp_load = null;
$dir = __DIR__;
for ($i = 0; $i < 10; $i++) {
    if (file_exists($dir . '/wp-load.php')) {
        $wp_load = $dir . '/wp-load.php';
        break;
    }
    $parent = dirname($dir);
    if ($parent === $dir) {
        break; // reached filesystem root
    }
    $dir = $parent;
}

if ($wp_load === null) {
    die('Could not locate wp-load.php by walking up from ' . __DIR__ . ' - move this file somewhere inside your WordPress install.');
}

require_once $wp_load;

// Security check - only allow a logged-in admin.
if (!current_user_can('manage_woocommerce')) {
    die('Access denied. You must be logged in as an administrator.');
}

if (!taxonomy_exists('product_cat')) {
    die('The product_cat taxonomy is not registered - is WooCommerce active?');
}

// The default "Uncategorized" category can't be deleted (WooCommerce always
// needs one to fall back to), so it's kept regardless of the request.
$default_cat_id = (int) get_option('default_product_cat');

if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
    $term_count = wp_count_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
    if (is_wp_error($term_count)) {
        $term_count = 0;
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Delete All Product Categories</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 50px; text-align: center; }
            .warning { background: #d32f2f; color: white; padding: 20px; margin: 20px auto; max-width: 600px; border-radius: 5px; text-align: left; }
            .warning ul { margin: 10px 0 0; padding-left: 20px; }
            .button { display: inline-block; padding: 15px 30px; margin: 10px; text-decoration: none; border-radius: 5px; font-weight: bold; }
            .delete { background: #d32f2f; color: white; }
            .cancel { background: #1976d2; color: white; }
        </style>
    </head>
    <body>
        <h1>Delete All Product Categories</h1>
        <div class="warning">
            <h2>⚠️ WARNING</h2>
            <p>This will permanently delete <strong><?php echo (int) $term_count; ?></strong> product categories from your WooCommerce store.</p>
            <ul>
                <li>Products are <strong>not</strong> deleted - they just lose their category assignment.</li>
                <li>The default "Uncategorized" category is kept (WooCommerce requires it).</li>
                <li>This action cannot be undone.</li>
            </ul>
        </div>
        <a href="?confirm=yes" class="button delete">Yes, Delete All Categories</a>
        <a href="<?php echo esc_url(admin_url()); ?>" class="button cancel">Cancel</a>
    </body>
    </html>
    <?php
    exit;
}

echo '<!DOCTYPE html><html><head><title>Deleting Categories...</title><style>body{font-family:Arial;padding:50px;}</style></head><body>';
echo '<h1>Deleting Categories...</h1>';
flush();

$term_ids = get_terms(array(
    'taxonomy' => 'product_cat',
    'hide_empty' => false,
    'fields' => 'ids',
));

if (is_wp_error($term_ids)) {
    echo '<p style="color:red;">Failed to load categories: ' . esc_html($term_ids->get_error_message()) . '</p></body></html>';
    exit;
}

$term_ids = array_map('intval', $term_ids);
$term_ids = array_filter($term_ids, function ($id) use ($default_cat_id) {
    return $id !== $default_cat_id;
});

$total = count($term_ids);
echo "<p>Found {$total} categories to delete (keeping the default \"Uncategorized\" category)...</p>";
flush();

// Avoid recalculating term counts after every single deletion - this is
// what makes deleting hundreds/thousands of categories in one request slow
// enough to look like a hang.
wp_defer_term_counting(true);

$deleted = 0;
$failed = 0;
$batch_size = 25;
$chunks = array_chunk($term_ids, $batch_size);

foreach ($chunks as $chunk) {
    foreach ($chunk as $term_id) {
        $result = wp_delete_term($term_id, 'product_cat');
        if (is_wp_error($result) || !$result) {
            $failed++;
        } else {
            $deleted++;
        }
    }
    echo "<p>Deleted {$deleted} of {$total}" . ($failed ? " ({$failed} failed)" : '') . "...</p>";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

wp_defer_term_counting(false);

// Clear caches that hold stale category data.
delete_transient('bf_mega_menu_standard');
delete_transient('bf_mega_menu_accordion');
if (function_exists('wc_delete_product_transients')) {
    delete_transient('wc_term_counts');
}

echo "<h2 style='color: green;'>✅ Done - deleted {$deleted} of {$total} categories" . ($failed ? ", {$failed} could not be deleted" : '') . ".</h2>";
echo "<p><a href='" . esc_url(admin_url('edit.php?post_type=product&page=product_cat')) . "'>View Categories</a> | <a href='" . esc_url(admin_url()) . "'>Dashboard</a></p>";
echo "<p style='color: red; font-weight: bold;'>⚠️ IMPORTANT: DELETE THIS FILE (delete-all-categories.php) NOW FOR SECURITY!</p>";
echo '</body></html>';
