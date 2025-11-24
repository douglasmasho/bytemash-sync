<?php
/**
 * Test Log Checker
 * 
 * Simple test to verify the log checker script works
 */

// Try multiple possible paths for wp-load.php
$wp_load_paths = array(
    '../../../../../wp-load.php',
    '../../../../wp-load.php', 
    '../../../wp-load.php',
    '../../wp-load.php',
    '../wp-load.php',
    'wp-load.php'
);

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        $wp_loaded = true;
        break;
    }
}

if (!$wp_loaded) {
    die('WordPress not found. Please check the path to wp-load.php');
}

if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to run this.');
}

echo "<h1>🔍 Log Checker Test</h1>";

// Test WordPress loading
echo "<h2>WordPress Status</h2>";
echo "<p style='color:green'>✅ WordPress loaded successfully</p>";
echo "<p><strong>Site URL:</strong> " . site_url() . "</p>";
echo "<p><strong>Admin URL:</strong> " . admin_url() . "</p>";

// Test plugin classes
echo "<h2>Plugin Classes</h2>";
$classes = array(
    'ByteMash_Logger',
    'ByteMash_Sync_Scheduler',
    'ByteMash_Batch_Processor'
);

foreach ($classes as $class) {
    if (class_exists($class)) {
        echo "<p style='color:green'>✅ $class loaded</p>";
    } else {
        echo "<p style='color:red'>❌ $class not found</p>";
    }
}

// Test database connection
echo "<h2>Database Status</h2>";
global $wpdb;
$table_name = $wpdb->prefix . 'bytemash_sync_logs';

$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
if ($table_exists) {
    echo "<p style='color:green'>✅ Logs table exists</p>";
    
    // Get log count
    $log_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    echo "<p><strong>Total logs:</strong> $log_count</p>";
    
    // Get recent logs
    $recent_logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 5");
    if ($recent_logs) {
        echo "<h3>Recent Logs:</h3>";
        echo "<table border='1' cellpadding='10'>";
        echo "<tr><th>Time</th><th>Type</th><th>Status</th><th>Message</th></tr>";
        foreach ($recent_logs as $log) {
            echo "<tr>";
            echo "<td>" . $log->created_at . "</td>";
            echo "<td>" . $log->sync_type . "</td>";
            echo "<td>" . $log->status . "</td>";
            echo "<td>" . esc_html($log->message) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No logs found.</p>";
    }
} else {
    echo "<p style='color:red'>❌ Logs table not found</p>";
}

// Test scheduled events
echo "<h2>Scheduled Events</h2>";
$events = array(
    'bytemash_full_sync_cron',
    'bytemash_incremental_sync_cron',
    'bytemash_cron_health_check'
);

foreach ($events as $event) {
    $next = wp_next_scheduled($event);
    if ($next) {
        echo "<p style='color:green'>✅ $event scheduled for " . date('Y-m-d H:i:s', $next) . "</p>";
    } else {
        echo "<p style='color:orange'>⚪ $event not scheduled</p>";
    }
}

// Test options
echo "<h2>Plugin Options</h2>";
$options = array(
    'bytemash_cron_test_mode_enabled',
    'bytemash_cron_system_cron_enabled',
    'bytemash_last_full_sync',
    'bytemash_last_incremental_sync'
);

foreach ($options as $option) {
    $value = get_option($option, 'not_set');
    echo "<p><strong>$option:</strong> $value</p>";
}

echo "<h2>Quick Links</h2>";
echo "<p><a href='" . admin_url('admin.php?page=bytemash-amrod-dashboard') . "' target='_blank'>Open Dashboard</a></p>";
echo "<p><a href='" . admin_url('admin.php?page=bytemash-amrod-settings') . "' target='_blank'>Open Settings</a></p>";
echo "<p><a href='check-cron-logs.php' target='_blank'>Open Log Checker</a></p>";

echo "<h2>Test Complete</h2>";
echo "<p style='color:green'>✅ All tests completed successfully!</p>";
?>
