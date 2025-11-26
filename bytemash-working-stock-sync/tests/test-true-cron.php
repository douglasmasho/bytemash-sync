<?php
/**
 * Test True Cron System
 * 
 * Simple test script to verify the true cron system is working
 */

// Load WordPress
require_once('../../../../../wp-load.php');

if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to run this.');
}

echo "<h1>🕐 True Cron System Test</h1>";

// Initialize cron manager
$cron_manager = new ByteMash_True_Cron_Manager();

echo "<h2>System Status</h2>";

// Check if cron manager is loaded
if (class_exists('ByteMash_True_Cron_Manager')) {
    echo "<p style='color:green'>✅ True Cron Manager loaded successfully</p>";
} else {
    echo "<p style='color:red'>❌ True Cron Manager not found</p>";
}

// Check scheduled events
$full_sync = wp_next_scheduled('bytemash_full_sync_cron');
$incremental_sync = wp_next_scheduled('bytemash_incremental_sync_cron');
$health_check = wp_next_scheduled('bytemash_cron_health_check');

echo "<h2>Scheduled Events</h2>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Event</th><th>Next Run</th><th>Status</th></tr>";

if ($full_sync) {
    echo "<tr><td>Full Sync</td><td>" . date('Y-m-d H:i:s', $full_sync) . "</td><td style='color:green'>✅ Scheduled</td></tr>";
} else {
    echo "<tr><td>Full Sync</td><td>-</td><td style='color:red'>❌ Not Scheduled</td></tr>";
}

if ($incremental_sync) {
    echo "<tr><td>Incremental Sync</td><td>" . date('Y-m-d H:i:s', $incremental_sync) . "</td><td style='color:green'>✅ Scheduled</td></tr>";
} else {
    echo "<tr><td>Incremental Sync</td><td>-</td><td style='color:red'>❌ Not Scheduled</td></tr>";
}

if ($health_check) {
    echo "<tr><td>Health Check</td><td>" . date('Y-m-d H:i:s', $health_check) . "</td><td style='color:green'>✅ Scheduled</td></tr>";
} else {
    echo "<tr><td>Health Check</td><td>-</td><td style='color:red'>❌ Not Scheduled</td></tr>";
}

echo "</table>";

// Check cron options
echo "<h2>Cron Options</h2>";
$test_mode = get_option('bytemash_cron_test_mode_enabled', false);
$system_cron = get_option('bytemash_cron_system_cron_enabled', false);
$hosted_pinger = get_option('bytemash_cron_hosted_pinger_enabled', false);

echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Option</th><th>Value</th><th>Status</th></tr>";
echo "<tr><td>Test Mode</td><td>" . ($test_mode ? 'Enabled' : 'Disabled') . "</td><td style='color:" . ($test_mode ? 'green' : 'orange') . "'>" . ($test_mode ? '✅' : '⚪') . "</td></tr>";
echo "<tr><td>System Cron</td><td>" . ($system_cron ? 'Enabled' : 'Disabled') . "</td><td style='color:" . ($system_cron ? 'green' : 'orange') . "'>" . ($system_cron ? '✅' : '⚪') . "</td></tr>";
echo "<tr><td>Hosted Pinger</td><td>" . ($hosted_pinger ? 'Enabled' : 'Disabled') . "</td><td style='color:" . ($hosted_pinger ? 'green' : 'orange') . "'>" . ($hosted_pinger ? '✅' : '⚪') . "</td></tr>";
echo "</table>";

// Test cron methods
echo "<h2>Cron Method Test</h2>";

// Test system cron prerequisites
$exec_available = function_exists('exec');
$shell_exec_available = function_exists('shell_exec');
$crontab_available = false;

if ($exec_available) {
    $output = array();
    $return_var = 0;
    exec('which crontab 2>/dev/null', $output, $return_var);
    $crontab_available = ($return_var === 0);
}

echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Prerequisite</th><th>Status</th></tr>";
echo "<tr><td>exec() function</td><td style='color:" . ($exec_available ? 'green' : 'red') . "'>" . ($exec_available ? '✅ Available' : '❌ Not Available') . "</td></tr>";
echo "<tr><td>shell_exec() function</td><td style='color:" . ($shell_exec_available ? 'green' : 'red') . "'>" . ($shell_exec_available ? '✅ Available' : '❌ Not Available') . "</td></tr>";
echo "<tr><td>crontab command</td><td style='color:" . ($crontab_available ? 'green' : 'red') . "'>" . ($crontab_available ? '✅ Available' : '❌ Not Available') . "</td></tr>";
echo "</table>";

// Test self-ping
echo "<h2>Self-Ping Test</h2>";
$cron_url = site_url('/wp-cron.php?doing_wp_cron');
echo "<p><strong>Cron URL:</strong> <a href='$cron_url' target='_blank'>$cron_url</a></p>";

echo "<form method='post'>";
echo "<button type='submit' name='test_self_ping' value='1' style='background:blue;color:white;padding:10px;margin:5px;'>Test Self-Ping</button>";
echo "</form>";

if (isset($_POST['test_self_ping'])) {
    echo "<h3>Self-Ping Result:</h3>";
    
    $response = wp_remote_post($cron_url, array(
        'blocking' => true,
        'timeout' => 10,
        'headers' => array(
            'User-Agent' => 'ByteMash-Woo-Sync-Test/1.0',
        ),
    ));
    
    if (is_wp_error($response)) {
        echo "<p style='color:red'>❌ Self-ping failed: " . $response->get_error_message() . "</p>";
    } else {
        $code = wp_remote_retrieve_response_code($response);
        echo "<p style='color:green'>✅ Self-ping successful (HTTP $code)</p>";
    }
}

// Manual triggers
echo "<h2>Manual Testing</h2>";
echo "<form method='post'>";
echo "<button type='submit' name='action' value='test_full_sync' style='background:green;color:white;padding:10px;margin:5px;'>Test Full Sync</button>";
echo "<button type='submit' name='action' value='test_incremental_sync' style='background:blue;color:white;padding:10px;margin:5px;'>Test Incremental Sync</button>";
echo "<button type='submit' name='action' value='test_health_check' style='background:orange;color:white;padding:10px;margin:5px;'>Test Health Check</button>";
echo "</form>";

if (isset($_POST['action'])) {
    echo "<h3>Test Result:</h3>";
    
    switch ($_POST['action']) {
        case 'test_full_sync':
            if (class_exists('ByteMash_Sync_Scheduler')) {
                $scheduler = new ByteMash_Sync_Scheduler();
                $scheduler->run_full_sync();
                echo "<p style='color:green'>✅ Full sync triggered!</p>";
            } else {
                echo "<p style='color:red'>❌ Sync scheduler not found</p>";
            }
            break;
            
        case 'test_incremental_sync':
            if (class_exists('ByteMash_Sync_Scheduler')) {
                $scheduler = new ByteMash_Sync_Scheduler();
                $scheduler->run_incremental_sync();
                echo "<p style='color:green'>✅ Incremental sync triggered!</p>";
            } else {
                echo "<p style='color:red'>❌ Sync scheduler not found</p>";
            }
            break;
            
        case 'test_health_check':
            if (method_exists($cron_manager, 'health_check')) {
                $cron_manager->health_check();
                echo "<p style='color:green'>✅ Health check triggered!</p>";
            } else {
                echo "<p style='color:red'>❌ Health check method not found</p>";
            }
            break;
    }
    
    echo "<script>setTimeout(function() { window.location.reload(); }, 2000);</script>";
}

// Show recent logs
echo "<h2>Recent Logs</h2>";
global $wpdb;
$logs = $wpdb->get_results("
    SELECT * FROM {$wpdb->prefix}bytemash_sync_logs 
    ORDER BY created_at DESC 
    LIMIT 10
");

if ($logs) {
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>Time</th><th>Type</th><th>Status</th><th>Message</th></tr>";
    foreach ($logs as $log) {
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

echo "<h2>Quick Links</h2>";
echo "<p><a href='" . admin_url('admin.php?page=bytemash-cron-manager') . "' target='_blank'>Open Cron Manager</a></p>";
echo "<p><a href='" . admin_url('admin.php?page=bytemash-amrod-sync') . "' target='_blank'>Open Main Settings</a></p>";
?>
