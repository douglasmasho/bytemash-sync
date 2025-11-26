<?php
/**
 * ByteMash WooSync Diagnostics
 * 
 * Access this file directly to check if the plugin is working correctly
 * URL: https://your-site.com/wp-content/plugins/bytemash-woo-sync/diagnostics.php
 */

// Load WordPress
$wp_load_paths = array(
    __DIR__ . '/../../../../wp-load.php',
    __DIR__ . '/../../../wp-load.php',
    __DIR__ . '/../../wp-load.php',
    __DIR__ . '/../wp-load.php',
);

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $wp_loaded = true;
        break;
    }
}

if (!$wp_loaded) {
    die('Could not load WordPress. Please ensure this file is in the correct location.');
}

// Security check
if (!current_user_can('manage_options')) {
    die('Unauthorized access. You must be an administrator to view this page.');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>ByteMash WooSync Diagnostics</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            padding: 20px;
            background: #f0f0f1;
            color: #1d2327;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
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
            border-bottom: 1px solid #ddd;
            padding-bottom: 8px;
        }
        .check {
            padding: 10px;
            margin: 10px 0;
            border-left: 4px solid;
            background: #f9f9f9;
        }
        .check.pass {
            border-color: #00a32a;
            background: #edfaef;
        }
        .check.fail {
            border-color: #d63638;
            background: #fcf0f1;
        }
        .check.warning {
            border-color: #dba617;
            background: #fcf9e8;
        }
        .icon {
            font-weight: bold;
            margin-right: 10px;
        }
        .pass .icon { color: #00a32a; }
        .fail .icon { color: #d63638; }
        .warning .icon { color: #dba617; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        table th, table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        table th {
            background: #f6f7f7;
            font-weight: 600;
        }
        .code {
            background: #f6f7f7;
            padding: 15px;
            border-radius: 4px;
            font-family: monospace;
            overflow-x: auto;
            margin: 10px 0;
        }
        .actions {
            margin: 20px 0;
            padding: 15px;
            background: #f0f6fc;
            border-radius: 4px;
        }
        .button {
            display: inline-block;
            padding: 10px 20px;
            background: #2271b1;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-right: 10px;
        }
        .button:hover {
            background: #135e96;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 ByteMash WooSync Diagnostics</h1>
        <p><strong>Server Time:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        <p><strong>WordPress Version:</strong> <?php echo get_bloginfo('version'); ?></p>
        <p><strong>Site URL:</strong> <?php echo site_url(); ?></p>
        
        <?php
        // Check if plugin is active
        $plugin_active = is_plugin_active('bytemash-woo-sync/bytemash-woo-sync.php') || function_exists('bytemash_woo_sync_init');
        
        // Plugin Constants
        $constants_ok = defined('BYTEMASH_WOO_SYNC_VERSION') && 
                       defined('BYTEMASH_WOO_SYNC_PLUGIN_DIR') && 
                       defined('BYTEMASH_WOO_SYNC_PLUGIN_URL');
        
        // Check assets
        $css_file = defined('BYTEMASH_WOO_SYNC_PLUGIN_DIR') ? BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'assets/css/admin.css' : '';
        $js_file = defined('BYTEMASH_WOO_SYNC_PLUGIN_DIR') ? BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'assets/js/admin.js' : '';
        
        $css_exists = file_exists($css_file);
        $js_exists = file_exists($js_file);
        
        $css_readable = $css_exists && is_readable($css_file);
        $js_readable = $js_exists && is_readable($js_file);
        
        // Check database
        global $wpdb;
        $logs_table = $wpdb->prefix . 'bytemash_sync_logs';
        $queue_table = $wpdb->prefix . 'bytemash_sync_queue';
        
        $logs_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$logs_table'") === $logs_table;
        $queue_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'") === $queue_table;
        
        // Check WP Cron
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $full_sync_scheduled = wp_next_scheduled('bytemash_full_sync_cron');
        $incremental_sync_scheduled = wp_next_scheduled('bytemash_incremental_sync_cron');
        
        // Check authentication
        $api_client = class_exists('ByteMash_Amrod_API_Client') ? new ByteMash_Amrod_API_Client() : null;
        $is_authenticated = $api_client && $api_client->is_authenticated();
        
        // Check PHP functions
        $exec_available = function_exists('exec');
        $shell_exec_available = function_exists('shell_exec');
        $wp_remote_post_available = function_exists('wp_remote_post');
        
        // Check memory
        $memory_limit = ini_get('memory_limit');
        $max_execution_time = ini_get('max_execution_time');
        ?>
        
        <h2>✅ Plugin Status</h2>
        
        <div class="check <?php echo $plugin_active ? 'pass' : 'fail'; ?>">
            <span class="icon"><?php echo $plugin_active ? '✓' : '✗'; ?></span>
            <strong>Plugin Active:</strong> <?php echo $plugin_active ? 'Yes' : 'No - Plugin may not be activated'; ?>
        </div>
        
        <div class="check <?php echo $constants_ok ? 'pass' : 'fail'; ?>">
            <span class="icon"><?php echo $constants_ok ? '✓' : '✗'; ?></span>
            <strong>Plugin Constants:</strong> <?php echo $constants_ok ? 'All defined correctly' : 'Some constants missing'; ?>
            <?php if ($constants_ok): ?>
                <br>Version: <?php echo BYTEMASH_WOO_SYNC_VERSION; ?>
                <br>Plugin Dir: <?php echo BYTEMASH_WOO_SYNC_PLUGIN_DIR; ?>
                <br>Plugin URL: <?php echo BYTEMASH_WOO_SYNC_PLUGIN_URL; ?>
            <?php endif; ?>
        </div>
        
        <div class="check <?php echo $is_authenticated ? 'pass' : 'warning'; ?>">
            <span class="icon"><?php echo $is_authenticated ? '✓' : '⚠'; ?></span>
            <strong>API Authentication:</strong> <?php echo $is_authenticated ? 'Authenticated' : 'Not authenticated - Please authenticate in settings'; ?>
        </div>
        
        <h2>📁 Asset Files</h2>
        
        <div class="check <?php echo $css_exists && $css_readable ? 'pass' : 'fail'; ?>">
            <span class="icon"><?php echo $css_exists && $css_readable ? '✓' : '✗'; ?></span>
            <strong>CSS File:</strong> 
            <?php if ($css_exists && $css_readable): ?>
                Found and readable (<?php echo file_exists($css_file) ? number_format(filesize($css_file)) . ' bytes' : ''; ?>)
                <br>Path: <?php echo $css_file; ?>
                <br>URL: <?php echo BYTEMASH_WOO_SYNC_PLUGIN_URL . 'assets/css/admin.css'; ?>
            <?php elseif ($css_exists): ?>
                Found but not readable - Check file permissions
            <?php else: ?>
                Not found - File may be missing
            <?php endif; ?>
        </div>
        
        <div class="check <?php echo $js_exists && $js_readable ? 'pass' : 'fail'; ?>">
            <span class="icon"><?php echo $js_exists && $js_readable ? '✓' : '✗'; ?></span>
            <strong>JavaScript File:</strong>
            <?php if ($js_exists && $js_readable): ?>
                Found and readable (<?php echo file_exists($js_file) ? number_format(filesize($js_file)) . ' bytes' : ''; ?>)
                <br>Path: <?php echo $js_file; ?>
                <br>URL: <?php echo BYTEMASH_WOO_SYNC_PLUGIN_URL . 'assets/js/admin.js'; ?>
            <?php elseif ($js_exists): ?>
                Found but not readable - Check file permissions
            <?php else: ?>
                Not found - File may be missing
            <?php endif; ?>
        </div>
        
        <h2>🗄️ Database Tables</h2>
        
        <div class="check <?php echo $logs_table_exists ? 'pass' : 'fail'; ?>">
            <span class="icon"><?php echo $logs_table_exists ? '✓' : '✗'; ?></span>
            <strong>Logs Table:</strong> <?php echo $logs_table_exists ? 'Exists' : 'Missing - Try deactivating and reactivating the plugin'; ?>
            <?php if ($logs_table_exists): ?>
                <br>Table: <?php echo $logs_table; ?>
                <br>Records: <?php echo $wpdb->get_var("SELECT COUNT(*) FROM $logs_table"); ?>
            <?php endif; ?>
        </div>
        
        <div class="check <?php echo $queue_table_exists ? 'pass' : 'warning'; ?>">
            <span class="icon"><?php echo $queue_table_exists ? '✓' : '⚠'; ?></span>
            <strong>Queue Table:</strong> <?php echo $queue_table_exists ? 'Exists' : 'Will be created on first sync'; ?>
            <?php if ($queue_table_exists): ?>
                <br>Table: <?php echo $queue_table; ?>
                <br>Pending items: <?php echo $wpdb->get_var("SELECT COUNT(*) FROM $queue_table WHERE status = 'pending'"); ?>
            <?php endif; ?>
        </div>
        
        <h2>⏰ WordPress Cron</h2>
        
        <div class="check <?php echo !$cron_disabled ? 'pass' : 'warning'; ?>">
            <span class="icon"><?php echo !$cron_disabled ? '✓' : '⚠'; ?></span>
            <strong>WP Cron:</strong> <?php echo $cron_disabled ? 'DISABLED - You need to set up system cron' : 'Enabled'; ?>
        </div>
        
        <div class="check <?php echo $full_sync_scheduled ? 'pass' : 'warning'; ?>">
            <span class="icon"><?php echo $full_sync_scheduled ? '✓' : '⚠'; ?></span>
            <strong>Full Sync Schedule:</strong> 
            <?php if ($full_sync_scheduled): ?>
                Next run: <?php echo date('Y-m-d H:i:s', $full_sync_scheduled); ?>
                (<?php echo human_time_diff($full_sync_scheduled, current_time('timestamp')); ?> 
                <?php echo $full_sync_scheduled > time() ? 'from now' : 'ago'; ?>)
            <?php else: ?>
                Not scheduled
            <?php endif; ?>
        </div>
        
        <div class="check <?php echo $incremental_sync_scheduled ? 'pass' : 'warning'; ?>">
            <span class="icon"><?php echo $incremental_sync_scheduled ? '✓' : '⚠'; ?></span>
            <strong>Incremental Sync Schedule:</strong>
            <?php if ($incremental_sync_scheduled): ?>
                Next run: <?php echo date('Y-m-d H:i:s', $incremental_sync_scheduled); ?>
                (<?php echo human_time_diff($incremental_sync_scheduled, current_time('timestamp')); ?> 
                <?php echo $incremental_sync_scheduled > time() ? 'from now' : 'ago'; ?>)
            <?php else: ?>
                Not scheduled
            <?php endif; ?>
        </div>
        
        <h2>⚙️ Server Environment</h2>
        
        <table>
            <tr>
                <th>Setting</th>
                <th>Value</th>
                <th>Status</th>
            </tr>
            <tr>
                <td>PHP Version</td>
                <td><?php echo PHP_VERSION; ?></td>
                <td><?php echo version_compare(PHP_VERSION, '7.4', '>=') ? '✓ OK' : '✗ Too old (needs 7.4+)'; ?></td>
            </tr>
            <tr>
                <td>Memory Limit</td>
                <td><?php echo $memory_limit; ?></td>
                <td><?php 
                    $memory_bytes = wp_convert_hr_to_bytes($memory_limit);
                    echo $memory_bytes >= 256 * 1024 * 1024 ? '✓ OK' : '⚠ Low (recommend 256M+)';
                ?></td>
            </tr>
            <tr>
                <td>Max Execution Time</td>
                <td><?php echo $max_execution_time; ?> seconds</td>
                <td><?php echo $max_execution_time >= 60 ? '✓ OK' : '⚠ Low (recommend 60+)'; ?></td>
            </tr>
            <tr>
                <td>exec() Function</td>
                <td><?php echo $exec_available ? 'Available' : 'Not available'; ?></td>
                <td><?php echo $exec_available ? '✓ OK' : '⚠ System cron unavailable'; ?></td>
            </tr>
            <tr>
                <td>wp_remote_post()</td>
                <td><?php echo $wp_remote_post_available ? 'Available' : 'Not available'; ?></td>
                <td><?php echo $wp_remote_post_available ? '✓ OK' : '✗ Required for API calls'; ?></td>
            </tr>
        </table>
        
        <h2> Recommendations</h2>
        
        <div class="actions">
            <?php if (!$plugin_active): ?>
                <p><strong> Plugin is not active.</strong> Please activate the ByteMash WooSync plugin.</p>
            <?php endif; ?>
            
            <?php if (!$css_readable || !$js_readable): ?>
                <p><strong> Asset files have issues.</strong> Try reuploading the plugin files via FTP/SFTP.</p>
            <?php endif; ?>
            
            <?php if ($cron_disabled): ?>
                <p><strong> WordPress Cron is disabled.</strong> You should set up system cron or use the cron manager in the plugin to enable production cron.</p>
                <div class="code">
                    # Add this to your crontab (run: crontab -e)
                    */5 * * * * wget -q -O - "<?php echo site_url('/wp-cron.php?doing_wp_cron'); ?>" >/dev/null 2>&1
                </div>
            <?php endif; ?>
            
            <?php if (!$is_authenticated): ?>
                <p><strong> Not authenticated with Amrod API.</strong> Go to Settings and authenticate.</p>
            <?php endif; ?>
            
            <p><strong>Clear Cache:</strong> If buttons still don't work, clear your:</p>
            <ul>
                <li>Browser cache (Ctrl+Shift+R or Cmd+Shift+R)</li>
                <li>WordPress cache plugin (if using one)</li>
                <li>Server cache (via hosting control panel)</li>
                <li>CDN cache (if using Cloudflare, etc.)</li>
            </ul>
        </div>
        
        <div class="actions">
            <a href="<?php echo admin_url('admin.php?page=bytemash-amrod-sync'); ?>" class="button">Go to Dashboard</a>
            <a href="<?php echo admin_url('admin.php?page=bytemash-amrod-settings'); ?>" class="button">Go to Settings</a>
            <a href="<?php echo admin_url('plugins.php'); ?>" class="button">Manage Plugins</a>
        </div>
        
        <p style="margin-top: 30px; color: #666; font-size: 13px;">
            <strong>Note:</strong> This diagnostics page helps identify common issues. If problems persist, check your server error logs and WordPress debug log.
        </p>
    </div>
</body>
</html>

