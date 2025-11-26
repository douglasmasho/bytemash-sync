<?php
/**
 * Emergency script to disable test modes
 * Run this once to force disable test modes
 */

// Include WordPress
require_once('../../../wp-config.php');

// Force disable test modes
update_option('bytemash_cron_full_test_mode_enabled', false);
update_option('bytemash_cron_incremental_test_mode_enabled', false);

// Clear any scheduled test events
wp_clear_scheduled_hook('bytemash_full_sync_cron');
wp_clear_scheduled_hook('bytemash_incremental_sync_cron');

echo "Test modes disabled successfully!\n";
echo "Full test mode: " . (get_option('bytemash_cron_full_test_mode_enabled') ? 'ENABLED' : 'DISABLED') . "\n";
echo "Incremental test mode: " . (get_option('bytemash_cron_incremental_test_mode_enabled') ? 'ENABLED' : 'DISABLED') . "\n";
?>
