<?php
/**
 * Run Variable Product Cleanup
 * 
 * This script runs the cleanup process to convert variable products to simple products
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    require_once('../../../wp-load.php');
}

// Check if user has admin permissions
if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to run this script.');
}

echo "<h1>Running Variable Product Cleanup</h1>";

// Include the cleanup script
include 'cleanup-variable-products.php';
?>
