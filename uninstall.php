<?php
/**
 * Plugin Uninstall Handler
 * 
 * Fired when the plugin is uninstalled
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('bytemash_amrod_api_url');
delete_option('bytemash_amrod_api_token');
delete_option('bytemash_amrod_batch_size');
delete_option('bytemash_amrod_sync_schedule');
delete_option('bytemash_log_retention_days');

// Clear scheduled cron
$timestamp = wp_next_scheduled('bytemash_amrod_sync_cron');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'bytemash_amrod_sync_cron');
}

// Clear transients
delete_transient('bytemash_sync_running');

// Drop database table (optional - uncomment if you want to remove logs on uninstall)
// global $wpdb;
// $table_name = $wpdb->prefix . 'bytemash_sync_logs';
// $wpdb->query("DROP TABLE IF EXISTS {$table_name}");

// Delete product meta (optional - uncomment if you want to remove Amrod meta on uninstall)
// global $wpdb;
// $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_amrod_%'");

