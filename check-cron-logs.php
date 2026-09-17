<?php
/**
 * Check Cron Logs
 * 
 * Run this to see recent cron activity and logs
 */

// Load WordPress
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

// Check permissions
if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to run this.');
}

echo "<h1>🕐 Cron Logs Checker</h1>";

// 1. Check scheduled events
echo "<h2>1. Scheduled Cron Events</h2>";
$full_sync = wp_next_scheduled('bytemash_full_sync_cron');
$incremental_sync = wp_next_scheduled('bytemash_incremental_sync_cron');

echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Event</th><th>Next Run</th><th>Time Until</th><th>Status</th></tr>";

if ($full_sync) {
    $time_until = human_time_diff(time(), $full_sync);
    echo "<tr>";
    echo "<td><strong>Full Sync</strong></td>";
    echo "<td>" . date('Y-m-d H:i:s', $full_sync) . "</td>";
    echo "<td>in " . $time_until . "</td>";
    echo "<td style='color:green'>✅ Scheduled</td>";
    echo "</tr>";
} else {
    echo "<tr>";
    echo "<td><strong>Full Sync</strong></td>";
    echo "<td>-</td>";
    echo "<td>-</td>";
    echo "<td style='color:red'>❌ Not Scheduled</td>";
    echo "</tr>";
}

if ($incremental_sync) {
    $time_until = human_time_diff(time(), $incremental_sync);
    echo "<tr>";
    echo "<td><strong>Incremental Sync</strong></td>";
    echo "<td>" . date('Y-m-d H:i:s', $incremental_sync) . "</td>";
    echo "<td>in " . $time_until . "</td>";
    echo "<td style='color:green'>✅ Scheduled</td>";
    echo "</tr>";
} else {
    echo "<tr>";
    echo "<td><strong>Incremental Sync</strong></td>";
    echo "<td>-</td>";
    echo "<td>-</td>";
    echo "<td style='color:red'>❌ Not Scheduled</td>";
    echo "</tr>";
}

echo "</table>";

// 2. Check recent logs
echo "<h2>2. Recent Sync Logs</h2>";
global $wpdb;
$logs = $wpdb->get_results("
    SELECT * FROM {$wpdb->prefix}bytemash_sync_logs 
    WHERE sync_type IN ('full_sync', 'incremental_sync', 'scheduled_sync')
    ORDER BY created_at DESC 
    LIMIT 10
");

if ($logs) {
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>Time</th><th>Type</th><th>Status</th><th>Message</th></tr>";
    
    foreach ($logs as $log) {
        $status_color = $log->status === 'success' ? 'green' : ($log->status === 'error' ? 'red' : 'orange');
        echo "<tr>";
        echo "<td>" . $log->created_at . "</td>";
        echo "<td>" . $log->sync_type . "</td>";
        echo "<td style='color:$status_color'>" . strtoupper($log->status) . "</td>";
        echo "<td>" . esc_html($log->message) . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
} else {
    echo "<p style='color:orange'>⚠️ No sync logs found. Run a sync to generate logs.</p>";
}

// 3. Check sync timestamps
echo "<h2>3. Sync Timestamps</h2>";
$timestamps = array(
    'Last Full Sync' => get_option('bytemash_last_full_sync'),
    'Last Incremental Sync' => get_option('bytemash_last_incremental_sync'),
    'API Stock Timestamp' => get_option('bytemash_api_last_stock_update'),
    'API Price Timestamp' => get_option('bytemash_api_last_price_update'),
);

echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Timestamp Type</th><th>Value</th><th>Status</th></tr>";

foreach ($timestamps as $type => $value) {
    $status = $value ? '✅ Set' : '❌ Not Set';
    $color = $value ? 'green' : 'red';
    echo "<tr>";
    echo "<td><strong>$type</strong></td>";
    echo "<td>" . ($value ?: 'Never') . "</td>";
    echo "<td style='color:$color'>$status</td>";
    echo "</tr>";
}

echo "</table>";

// 4. Check if WP-Cron is working
echo "<h2>4. WP-Cron Status</h2>";
$wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

if ($wp_cron_disabled) {
    echo "<p style='color:red'>❌ <strong>WP-Cron is DISABLED!</strong> You need server cron.</p>";
    echo "<p>Add this to your server crontab:</p>";
    echo "<pre>*/5 * * * * wget -q -O - " . site_url('/wp-cron.php?doing_wp_cron') . " &>/dev/null</pre>";
} else {
    echo "<p style='color:green'>✅ <strong>WP-Cron is ENABLED</strong> - Cron will run when site is visited</p>";
}

// 5. Manual trigger buttons
echo "<h2>5. Manual Testing</h2>";
echo "<form method='post'>";
echo "<button type='submit' name='action' value='trigger_full' style='background:green;color:white;padding:10px;margin:5px;'>Trigger Full Sync Now</button>";
echo "<button type='submit' name='action' value='trigger_incremental' style='background:blue;color:white;padding:10px;margin:5px;'>Trigger Incremental Sync Now</button>";
echo "<button type='submit' name='action' value='clear_schedules' style='background:red;color:white;padding:10px;margin:5px;'>Clear All Schedules</button>";
echo "</form>";

// Handle manual triggers
if (isset($_POST['action'])) {
    $scheduler = new ByteMash_Sync_Scheduler();
    
    switch ($_POST['action']) {
        case 'trigger_full':
            echo "<p style='color:blue'>🔄 Triggering full sync...</p>";
            $scheduler->run_full_sync();
            echo "<p style='color:green'>✅ Full sync completed! Check logs above.</p>";
            break;
            
        case 'trigger_incremental':
            echo "<p style='color:blue'>🔄 Triggering incremental sync...</p>";
            $scheduler->run_incremental_sync();
            echo "<p style='color:green'>✅ Incremental sync completed! Check logs above.</p>";
            break;
            
        case 'clear_schedules':
            echo "<p style='color:blue'>🔄 Clearing all schedules...</p>";
            $scheduler->clear_all_schedules();
            echo "<p style='color:green'>✅ Schedules cleared! Go to Settings to reinitialize.</p>";
            break;
    }
    
    echo "<script>setTimeout(function() { window.location.reload(); }, 2000);</script>";
}

echo "<hr>";
echo "<p><a href='" . admin_url('admin.php?page=bytemash-amrod-sync') . "'>Go to Dashboard</a> | ";
echo "<a href='" . admin_url('admin.php?page=bytemash-amrod-settings') . "'>Go to Settings</a></p>";
?>

