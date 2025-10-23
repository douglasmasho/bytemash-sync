<?php
/**
 * Test Admin Interface
 * 
 * Simple test to verify the admin interface is working correctly
 */

// Load WordPress
require_once('../../../../../wp-load.php');

if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to run this.');
}

echo "<h1>🔧 Admin Interface Test</h1>";

// Check if classes are loaded
echo "<h2>Class Loading Test</h2>";
$classes = array(
    'ByteMash_Sync_Scheduler',
    'ByteMash_True_Cron_Manager',
    'ByteMash_Admin_Settings'
);

foreach ($classes as $class) {
    if (class_exists($class)) {
        echo "<p style='color:green'>✅ $class loaded</p>";
    } else {
        echo "<p style='color:red'>❌ $class not found</p>";
    }
}

// Check AJAX handlers
echo "<h2>AJAX Handlers Test</h2>";
$ajax_actions = array(
    'bytemash_toggle_test_mode',
    'bytemash_enable_system_cron'
);

foreach ($ajax_actions as $action) {
    if (has_action("wp_ajax_$action")) {
        echo "<p style='color:green'>✅ $action registered</p>";
    } else {
        echo "<p style='color:red'>❌ $action not registered</p>";
    }
}

// Check options
echo "<h2>Options Test</h2>";
$options = array(
    'bytemash_cron_test_mode_enabled',
    'bytemash_cron_system_cron_enabled',
    'bytemash_cron_hosted_pinger_enabled'
);

foreach ($options as $option) {
    $value = get_option($option, 'not_set');
    echo "<p><strong>$option:</strong> $value</p>";
}

// Check scheduled events
echo "<h2>Scheduled Events Test</h2>";
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

// Test admin interface
echo "<h2>Admin Interface Test</h2>";
echo "<p><a href='" . admin_url('admin.php?page=bytemash-amrod-settings') . "' target='_blank'>Open Settings Page</a></p>";
echo "<p><a href='" . admin_url('admin.php?page=bytemash-cron-manager') . "' target='_blank'>Open Cron Manager</a></p>";

// Test JavaScript
echo "<h2>JavaScript Test</h2>";
echo "<p>Check browser console for JavaScript errors when using the admin interface.</p>";

// Test CSS
echo "<h2>CSS Test</h2>";
echo "<p>Check if styles are loading correctly in the admin interface.</p>";

echo "<h2>Quick Actions</h2>";
echo "<form method='post'>";
echo "<button type='submit' name='test_toggle' value='1' style='background:blue;color:white;padding:10px;margin:5px;'>Test Toggle Test Mode</button>";
echo "<button type='submit' name='test_system_cron' value='1' style='background:green;color:white;padding:10px;margin:5px;'>Test System Cron</button>";
echo "</form>";

if (isset($_POST['test_toggle'])) {
    echo "<h3>Toggle Test Mode Result:</h3>";
    
    $test_mode = get_option('bytemash_cron_test_mode_enabled', false);
    $new_mode = !$test_mode;
    
    update_option('bytemash_cron_test_mode_enabled', $new_mode);
    
    echo "<p style='color:green'>✅ Test mode " . ($new_mode ? 'enabled' : 'disabled') . "</p>";
    
    if ($new_mode) {
        // Schedule test events
        wp_schedule_single_event(time() + 120, 'bytemash_full_sync_cron');
        wp_schedule_event(time(), 'every_5_minutes', 'bytemash_incremental_sync_cron');
        echo "<p style='color:green'>✅ Test schedules created</p>";
    } else {
        // Clear test events
        wp_clear_scheduled_hook('bytemash_full_sync_cron');
        wp_clear_scheduled_hook('bytemash_incremental_sync_cron');
        echo "<p style='color:green'>✅ Test schedules cleared</p>";
    }
}

if (isset($_POST['test_system_cron'])) {
    echo "<h3>System Cron Test Result:</h3>";
    
    if (function_exists('exec')) {
        echo "<p style='color:green'>✅ exec() function available</p>";
        
        // Create test script
        $upload_dir = wp_upload_dir();
        $cron_dir = $upload_dir['basedir'] . '/bytemash-cron';
        
        if (wp_mkdir_p($cron_dir)) {
            $script_path = $cron_dir . '/test-cron.sh';
            $cron_url = site_url('/wp-cron.php?doing_wp_cron');
            
            $script_content = "#!/bin/bash\n";
            $script_content .= "# Test Cron Script\n";
            $script_content .= "wget -q -O - \"$cron_url\" >/dev/null 2>&1\n";
            
            if (file_put_contents($script_path, $script_content)) {
                chmod($script_path, 0755);
                echo "<p style='color:green'>✅ Test script created: $script_path</p>";
                echo "<p><strong>Add this to your crontab:</strong> */5 * * * * $script_path</p>";
            } else {
                echo "<p style='color:red'>❌ Cannot write test script</p>";
            }
        } else {
            echo "<p style='color:red'>❌ Cannot create cron directory</p>";
        }
    } else {
        echo "<p style='color:red'>❌ exec() function not available</p>";
    }
}

echo "<h2>Status Summary</h2>";
echo "<p><strong>Test Mode:</strong> " . (get_option('bytemash_cron_test_mode_enabled', false) ? 'Enabled' : 'Disabled') . "</p>";
echo "<p><strong>System Cron:</strong> " . (get_option('bytemash_cron_system_cron_enabled', false) ? 'Enabled' : 'Disabled') . "</p>";
echo "<p><strong>Hosted Pinger:</strong> " . (get_option('bytemash_cron_hosted_pinger_enabled', false) ? 'Enabled' : 'Disabled') . "</p>";

echo "<script>setTimeout(function() { window.location.reload(); }, 5000);</script>";
?>
