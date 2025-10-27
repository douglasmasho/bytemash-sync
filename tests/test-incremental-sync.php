<?php
/**
 * Test Incremental Sync Implementation
 * 
 * This script tests the new incremental sync functionality according to Amrod API docs
 * 
 * Usage: Load this file in your browser: /wp-content/plugins/bytemash-woo-sync/test-incremental-sync.php
 */

// Load WordPress
require_once('../../../../../wp-load.php');

// Check permissions
if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to run this test.');
}

// Initialize classes
$scheduler = new ByteMash_Sync_Scheduler();
$product_sync = new ByteMash_Product_Sync();
$api_client = new ByteMash_Amrod_API_Client();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Incremental Sync Testing</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            margin: 20px;
            background: #f0f0f1;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2271b1;
            border-bottom: 2px solid #2271b1;
            padding-bottom: 10px;
        }
        h2 {
            color: #1d2327;
            margin-top: 30px;
        }
        .test-section {
            margin: 20px 0;
            padding: 15px;
            background: #f6f7f7;
            border-left: 4px solid #2271b1;
            border-radius: 4px;
        }
        .status {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .status.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .status.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .status.warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .status.info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #f6f7f7;
            font-weight: 600;
        }
        .button {
            display: inline-block;
            padding: 10px 20px;
            margin: 5px;
            background: #2271b1;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        .button:hover {
            background: #135e96;
        }
        .button.secondary {
            background: #6c757d;
        }
        .button.success {
            background: #28a745;
        }
        .button.danger {
            background: #dc3545;
        }
        code {
            background: #f6f7f7;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
        pre {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Incremental Sync Testing Dashboard</h1>
        <p>Test the new incremental sync functionality that follows Amrod API documentation.</p>

        <!-- Test 1: Check Authentication -->
        <div class="test-section">
            <h2>1. Authentication Status</h2>
            <?php
            $is_authenticated = $api_client->is_authenticated();
            if ($is_authenticated) {
                echo '<div class="status success">✅ <strong>Authentication:</strong> Connected to Amrod API</div>';
            } else {
                echo '<div class="status error">❌ <strong>Authentication:</strong> Not connected. Please authenticate first.</div>';
                echo '<p><a href="' . admin_url('admin.php?page=bytemash-amrod-settings') . '" class="button">Go to Settings</a></p>';
            }
            ?>
        </div>

        <!-- Test 2: Check Cron Schedules -->
        <div class="test-section">
            <h2>2. Cron Schedule Status</h2>
            <?php
            $next_full_sync = wp_next_scheduled('bytemash_full_sync_cron');
            $next_incremental = wp_next_scheduled('bytemash_incremental_sync_cron');
            
            echo '<table>';
            echo '<tr><th>Schedule Type</th><th>Status</th><th>Next Run</th><th>Time Until</th></tr>';
            
            // Full Sync
            if ($next_full_sync) {
                $time_until = human_time_diff(time(), $next_full_sync);
                echo '<tr>';
                echo '<td><strong>Full Sync (00:30 GMT+2)</strong></td>';
                echo '<td><span class="status success" style="display:inline-block;padding:5px 10px;">✅ Scheduled</span></td>';
                echo '<td>' . date_i18n('Y-m-d H:i:s', $next_full_sync) . '</td>';
                echo '<td>in ' . $time_until . '</td>';
                echo '</tr>';
            } else {
                echo '<tr>';
                echo '<td><strong>Full Sync</strong></td>';
                echo '<td><span class="status error" style="display:inline-block;padding:5px 10px;">❌ Not Scheduled</span></td>';
                echo '<td colspan="2">-</td>';
                echo '</tr>';
            }
            
            // Incremental Sync
            if ($next_incremental) {
                $time_until = human_time_diff(time(), $next_incremental);
                echo '<tr>';
                echo '<td><strong>Incremental Sync (Every 5 hours)</strong></td>';
                echo '<td><span class="status success" style="display:inline-block;padding:5px 10px;">✅ Scheduled</span></td>';
                echo '<td>' . date_i18n('Y-m-d H:i:s', $next_incremental) . '</td>';
                echo '<td>in ' . $time_until . '</td>';
                echo '</tr>';
            } else {
                echo '<tr>';
                echo '<td><strong>Incremental Sync</strong></td>';
                echo '<td><span class="status error" style="display:inline-block;padding:5px 10px;">❌ Not Scheduled</span></td>';
                echo '<td colspan="2">-</td>';
                echo '</tr>';
            }
            
            echo '</table>';
            
            if (!$next_full_sync || !$next_incremental) {
                echo '<div class="status warning">⚠️ Schedules not set. Click the button below to initialize.</div>';
                echo '<form method="post" style="display:inline;">';
                echo '<input type="hidden" name="action" value="init_schedules">';
                echo '<button type="submit" class="button success">Initialize Schedules</button>';
                echo '</form>';
            }
            
            // Handle schedule initialization
            if (isset($_POST['action']) && $_POST['action'] === 'init_schedules') {
                $scheduler->update_schedule('daily_at_0030', 'every_5_hours');
                echo '<div class="status success">✅ Schedules initialized! Refresh the page to see changes.</div>';
            }
            ?>
        </div>

        <!-- Test 3: Last Sync Times -->
        <div class="test-section">
            <h2>3. Last Sync Timestamps</h2>
            <?php
            $last_full = get_option('bytemash_last_full_sync');
            $last_incremental = get_option('bytemash_last_incremental_sync');
            
            echo '<table>';
            echo '<tr><th>Sync Type</th><th>Last Run</th><th>Time Ago</th><th>Status</th></tr>';
            
            // Full Sync
            if ($last_full) {
                $time_ago = human_time_diff(strtotime($last_full), time());
                $today = date('Y-m-d', strtotime($last_full)) === date('Y-m-d');
                echo '<tr>';
                echo '<td><strong>Full Sync</strong></td>';
                echo '<td>' . $last_full . '</td>';
                echo '<td>' . $time_ago . ' ago</td>';
                echo '<td>';
                if ($today) {
                    echo '<span class="status success" style="display:inline-block;padding:5px 10px;">✅ Completed Today</span>';
                } else {
                    echo '<span class="status warning" style="display:inline-block;padding:5px 10px;">⚠️ Not Today</span>';
                }
                echo '</td>';
                echo '</tr>';
            } else {
                echo '<tr><td><strong>Full Sync</strong></td><td colspan="3"><span class="status info" style="display:inline-block;padding:5px 10px;">Never run</span></td></tr>';
            }
            
            // Incremental Sync
            if ($last_incremental) {
                $time_ago = human_time_diff(strtotime($last_incremental), time());
                echo '<tr>';
                echo '<td><strong>Incremental Sync</strong></td>';
                echo '<td>' . $last_incremental . '</td>';
                echo '<td>' . $time_ago . ' ago</td>';
                echo '<td><span class="status success" style="display:inline-block;padding:5px 10px;">✅ Completed</span></td>';
                echo '</tr>';
            } else {
                echo '<tr><td><strong>Incremental Sync</strong></td><td colspan="3"><span class="status info" style="display:inline-block;padding:5px 10px;">Never run</span></td></tr>';
            }
            
            echo '</table>';
            ?>
        </div>

        <!-- Test 4: Prerequisite Check -->
        <div class="test-section">
            <h2>4. Incremental Sync Prerequisites</h2>
            <?php
            $can_run_incremental = (bool) get_option('bytemash_last_full_sync');
            
            if ($can_run_incremental) {
                echo '<div class="status success">✅ <strong>Prerequisites Met:</strong> Full sync has been completed. Incremental sync can run.</div>';
            } else {
                echo '<div class="status warning">⚠️ <strong>Prerequisites Not Met:</strong> Full sync must be completed before incremental sync can run.</div>';
                echo '<p>Run a full sync first:</p>';
                echo '<form method="post" style="display:inline;">';
                echo '<input type="hidden" name="action" value="trigger_full_sync">';
                echo '<button type="submit" class="button success">Trigger Full Sync Now</button>';
                echo '</form>';
            }
            
            // Handle full sync trigger
            if (isset($_POST['action']) && $_POST['action'] === 'trigger_full_sync') {
                echo '<div class="status info">🔄 Triggering full sync... This may take a while.</div>';
                echo '<script>setTimeout(function() { window.location.href = "' . admin_url('admin.php?page=bytemash-amrod-sync') . '"; }, 2000);</script>';
            }
            ?>
        </div>

        <!-- Test 5: API Response Timestamps -->
        <div class="test-section">
            <h2>5. API Response Timestamps (for duplicate prevention)</h2>
            <?php
            $api_timestamps = array(
                'Products' => get_option('bytemash_api_last_product_update'),
                'Stock' => get_option('bytemash_api_last_stock_update'),
                'Prices' => get_option('bytemash_api_last_price_update'),
                'Brands' => get_option('bytemash_api_last_brand_update'),
            );
            
            echo '<table>';
            echo '<tr><th>Endpoint</th><th>Last API Timestamp</th><th>Status</th></tr>';
            
            foreach ($api_timestamps as $endpoint => $timestamp) {
                echo '<tr>';
                echo '<td><strong>' . $endpoint . '</strong></td>';
                if ($timestamp) {
                    echo '<td>' . $timestamp . '</td>';
                    echo '<td><span class="status success" style="display:inline-block;padding:5px 10px;">✅ Tracked</span></td>';
                } else {
                    echo '<td>-</td>';
                    echo '<td><span class="status info" style="display:inline-block;padding:5px 10px;">Not yet tracked</span></td>';
                }
                echo '</tr>';
            }
            
            echo '</table>';
            
            echo '<div class="status info">ℹ️ These timestamps are extracted from API responses to prevent processing the same data twice.</div>';
            ?>
        </div>

        <!-- Test 6: Manual Test Triggers -->
        <div class="test-section">
            <h2>6. Manual Test Triggers</h2>
            <p>Use these buttons to manually trigger sync operations for testing:</p>
            
            <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="test_full_sync">
                <button type="submit" class="button success">Test Full Sync</button>
            </form>
            
            <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="test_incremental_sync">
                <button type="submit" class="button">Test Incremental Sync</button>
            </form>
            
            <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="clear_timestamps">
                <button type="submit" class="button danger">Clear All Timestamps</button>
            </form>
            
            <?php
            // Handle test actions
            if (isset($_POST['action'])) {
                switch ($_POST['action']) {
                    case 'test_full_sync':
                        echo '<div class="status info">🔄 Triggering full sync test...</div>';
                        $scheduler->run_full_sync();
                        echo '<div class="status success">✅ Full sync triggered! Check the dashboard for results.</div>';
                        break;
                        
                    case 'test_incremental_sync':
                        echo '<div class="status info">🔄 Triggering incremental sync test...</div>';
                        $scheduler->run_incremental_sync();
                        echo '<div class="status success">✅ Incremental sync triggered! Check the dashboard for results.</div>';
                        break;
                        
                    case 'clear_timestamps':
                        delete_option('bytemash_last_full_sync');
                        delete_option('bytemash_last_incremental_sync');
                        delete_option('bytemash_api_last_product_update');
                        delete_option('bytemash_api_last_stock_update');
                        delete_option('bytemash_api_last_price_update');
                        delete_option('bytemash_api_last_brand_update');
                        echo '<div class="status success">✅ All timestamps cleared! Refresh the page to see changes.</div>';
                        break;
                }
            }
            ?>
        </div>

        <!-- Test 7: WordPress Cron Info -->
        <div class="test-section">
            <h2>7. WordPress Cron Configuration</h2>
            <div class="status info">
                <p><strong>ℹ️ Important Notes:</strong></p>
                <ul>
                    <li>WordPress cron runs when someone visits your site (not a real cron)</li>
                    <li>For production, consider using real server cron for reliability</li>
                    <li>Check if <code>DISABLE_WP_CRON</code> is defined in <code>wp-config.php</code></li>
                </ul>
            </div>
            
            <?php
            $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
            
            if ($wp_cron_disabled) {
                echo '<div class="status warning">⚠️ <strong>WP-Cron is disabled.</strong> You need to set up server cron.</div>';
                echo '<pre>*/5 * * * * wget -q -O - ' . site_url('/wp-cron.php?doing_wp_cron') . ' &>/dev/null</pre>';
            } else {
                echo '<div class="status success">✅ <strong>WP-Cron is enabled.</strong> Automatic syncs will run when site is visited.</div>';
            }
            ?>
            
            <h3>Server Cron Setup (Recommended for Production)</h3>
            <p>Add these lines to your server crontab for more reliable scheduling:</p>
            <pre># Full sync at 00:30 daily (GMT+2 = 22:30 UTC)
30 22 * * * wget -q -O - <?php echo site_url('/wp-cron.php?doing_wp_cron'); ?> &>/dev/null

# Incremental sync every 5 hours
0 */5 * * * wget -q -O - <?php echo site_url('/wp-cron.php?doing_wp_cron'); ?> &>/dev/null</pre>
        </div>

        <!-- Footer -->
        <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center; color: #666;">
            <p><a href="<?php echo admin_url('admin.php?page=bytemash-amrod-sync'); ?>" class="button">Go to Dashboard</a>
            <a href="<?php echo admin_url('admin.php?page=bytemash-amrod-settings'); ?>" class="button secondary">Go to Settings</a></p>
            <p>Testing complete! Close this window when done.</p>
        </div>
    </div>
</body>
</html>


