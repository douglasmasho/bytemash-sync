<?php
/**
 * Diagnostic Script: Dump recent stock sync logs
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/');
    require_once(ABSPATH . 'wp-load.php');
}

$logger = new ByteMash_Logger();
$logs = $logger->get_logs(50, 0, 'info'); // Get info logs
$error_logs = $logger->get_logs(20, 0, 'error'); // Get error logs

echo "=== Recent Stock Sync Info Logs ===\n";
foreach ($logs as $log) {
    if ($log['sync_type'] === 'stock_sync') {
        echo "[{$log['created_at']}] [{$log['status']}] {$log['message']}\n";
        if (!empty($log['data'])) {
            echo "   Data: " . json_encode($log['data']) . "\n";
        }
    }
}

echo "\n=== Recent Stock Sync Error Logs ===\n";
foreach ($error_logs as $log) {
    if ($log['sync_type'] === 'stock_sync') {
        echo "[{$log['created_at']}] [{$log['status']}] {$log['message']}\n";
        if (!empty($log['data'])) {
            echo "   Data: " . json_encode($log['data']) . "\n";
        }
    }
}

echo "\n=== Sync Option Status ===\n";
global $wpdb;
$options = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'bytemash_sync_stock_%' ORDER BY option_name DESC LIMIT 5");
foreach ($options as $opt) {
    echo "{$opt->option_name}: " . print_r(maybe_unserialize($opt->option_value), true) . "\n";
}
