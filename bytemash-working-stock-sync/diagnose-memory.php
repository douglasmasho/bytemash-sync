<?php
/**
 * Memory Diagnostic Tool
 * 
 * Run this script to diagnose memory configuration and issues
 * Access via: /wp-content/plugins/bytemash-woo-sync/diagnose-memory.php
 */

// WordPress not loaded yet, so load it
$wp_load_path = dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';
if (file_exists($wp_load_path)) {
    require_once($wp_load_path);
} else {
    die('Error: Could not load WordPress. Please run this from the plugin directory.');
}

// Security check
if (!current_user_can('manage_options')) {
    wp_die('Unauthorized access');
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>ByteMash Amrod Sync - Memory Diagnostic</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, sans-serif;
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background: #f0f0f1;
        }
        h1 {
            color: #1d2327;
            border-bottom: 3px solid #2271b1;
            padding-bottom: 10px;
        }
        h2 {
            color: #2271b1;
            margin-top: 30px;
        }
        .section {
            background: white;
            padding: 20px;
            margin: 20px 0;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .status-good {
            color: #00a32a;
            font-weight: bold;
        }
        .status-warning {
            color: #dba617;
            font-weight: bold;
        }
        .status-critical {
            color: #d63638;
            font-weight: bold;
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
        .info-box {
            background: #f0f6fc;
            border-left: 4px solid #2271b1;
            padding: 15px;
            margin: 15px 0;
        }
        .warning-box {
            background: #fcf9e8;
            border-left: 4px solid #dba617;
            padding: 15px;
            margin: 15px 0;
        }
        .error-box {
            background: #fcf0f1;
            border-left: 4px solid #d63638;
            padding: 15px;
            margin: 15px 0;
        }
        code {
            background: #f0f0f1;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: Consolas, Monaco, monospace;
        }
        .recommendation {
            background: #f0f6fc;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
        }
        .test-button {
            background: #2271b1;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin: 10px 5px 10px 0;
        }
        .test-button:hover {
            background: #135e96;
        }
    </style>
</head>
<body>
    <h1>🔧 ByteMash Amrod Sync - Memory Diagnostic</h1>
    
    <?php
    // Get current memory information
    $memory_limit = ini_get('memory_limit');
    $memory_limit_bytes = convert_to_bytes($memory_limit);
    $memory_usage = memory_get_usage(true);
    $memory_peak = memory_get_peak_usage(true);
    $memory_available = $memory_limit_bytes - $memory_usage;
    
    // Get WordPress memory limits
    $wp_memory_limit = WP_MEMORY_LIMIT;
    $wp_max_memory_limit = WP_MAX_MEMORY_LIMIT;
    
    // Determine status
    $status = 'good';
    $status_message = 'Memory configuration is optimal';
    
    if ($memory_limit_bytes < 512 * 1024 * 1024) {
        $status = 'warning';
        $status_message = 'Memory limit is below recommended 512MB';
    }
    
    if ($memory_limit_bytes < 256 * 1024 * 1024) {
        $status = 'critical';
        $status_message = 'Memory limit is too low - will likely cause errors';
    }
    
    // Get plugin settings
    $api_token = get_option('bytemash_amrod_api_token');
    $batch_size = get_option('bytemash_amrod_batch_size', 10);
    $last_sync = get_option('bytemash_last_sync_time');
    
    // Check for recent errors in logs
    $log_file = plugin_dir_path(__FILE__) . 'logs/debug.log';
    $recent_errors = array();
    if (file_exists($log_file)) {
        $log_lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $log_lines = array_slice($log_lines, -100); // Last 100 lines
        
        foreach ($log_lines as $line) {
            if (stripos($line, 'memory') !== false || stripos($line, 'error') !== false) {
                $recent_errors[] = $line;
            }
        }
        $recent_errors = array_slice($recent_errors, -10); // Last 10 relevant lines
    }
    ?>
    
    <div class="section">
        <h2>Overall Status: <span class="status-<?php echo $status; ?>"><?php echo strtoupper($status); ?></span></h2>
        <p><?php echo $status_message; ?></p>
    </div>
    
    <div class="section">
        <h2>📊 Memory Configuration</h2>
        <table>
            <tr>
                <th>Setting</th>
                <th>Value</th>
                <th>Status</th>
            </tr>
            <tr>
                <td>PHP Memory Limit</td>
                <td><code><?php echo $memory_limit; ?></code></td>
                <td>
                    <?php
                    if ($memory_limit_bytes >= 512 * 1024 * 1024) {
                        echo '<span class="status-good">✓ Optimal</span>';
                    } elseif ($memory_limit_bytes >= 256 * 1024 * 1024) {
                        echo '<span class="status-warning">⚠ Below recommended</span>';
                    } else {
                        echo '<span class="status-critical">✗ Too low</span>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <td>Current Memory Usage</td>
                <td><code><?php echo format_bytes($memory_usage); ?></code></td>
                <td>
                    <?php
                    $usage_percent = ($memory_usage / $memory_limit_bytes) * 100;
                    if ($usage_percent < 50) {
                        echo '<span class="status-good">✓ Normal</span>';
                    } elseif ($usage_percent < 80) {
                        echo '<span class="status-warning">⚠ Moderate</span>';
                    } else {
                        echo '<span class="status-critical">✗ High</span>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <td>Peak Memory Usage</td>
                <td><code><?php echo format_bytes($memory_peak); ?></code></td>
                <td><?php echo round(($memory_peak / $memory_limit_bytes) * 100, 1); ?>% of limit</td>
            </tr>
            <tr>
                <td>Available Memory</td>
                <td><code><?php echo format_bytes($memory_available); ?></code></td>
                <td>
                    <?php
                    if ($memory_available > 256 * 1024 * 1024) {
                        echo '<span class="status-good">✓ Plenty</span>';
                    } elseif ($memory_available > 128 * 1024 * 1024) {
                        echo '<span class="status-warning">⚠ Limited</span>';
                    } else {
                        echo '<span class="status-critical">✗ Very low</span>';
                    }
                    ?>
                </td>
            </tr>
        </table>
        
        <table>
            <tr>
                <th>WordPress Setting</th>
                <th>Value</th>
            </tr>
            <tr>
                <td>WP_MEMORY_LIMIT</td>
                <td><code><?php echo $wp_memory_limit; ?></code></td>
            </tr>
            <tr>
                <td>WP_MAX_MEMORY_LIMIT</td>
                <td><code><?php echo $wp_max_memory_limit; ?></code></td>
            </tr>
        </table>
    </div>
    
    <div class="section">
        <h2>🔧 Plugin Configuration</h2>
        <table>
            <tr>
                <th>Setting</th>
                <th>Value</th>
                <th>Recommendation</th>
            </tr>
            <tr>
                <td>API Token Configured</td>
                <td><?php echo $api_token ? '<span class="status-good">✓ Yes</span>' : '<span class="status-critical">✗ No</span>'; ?></td>
                <td><?php echo $api_token ? 'Good' : 'Required for API access'; ?></td>
            </tr>
            <tr>
                <td>Batch Size</td>
                <td><code><?php echo $batch_size; ?></code> products per batch</td>
                <td>
                    <?php
                    if ($memory_limit_bytes < 256 * 1024 * 1024 && $batch_size > 5) {
                        echo '<span class="status-warning">Reduce to 5 for low memory</span>';
                    } elseif ($memory_limit_bytes >= 512 * 1024 * 1024 && $batch_size < 20) {
                        echo '<span class="status-good">Can increase to 20 for faster sync</span>';
                    } else {
                        echo '<span class="status-good">Optimal for current memory</span>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <td>Last Sync</td>
                <td><?php echo $last_sync ? date('Y-m-d H:i:s', $last_sync) : 'Never'; ?></td>
                <td><?php echo $last_sync && (time() - $last_sync) > 86400 ? 'Consider running sync' : 'Up to date'; ?></td>
            </tr>
        </table>
    </div>
    
    <?php if (!empty($recent_errors)): ?>
    <div class="section">
        <h2>⚠️ Recent Log Entries (Memory/Error Related)</h2>
        <div style="background: #f0f0f1; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 12px; overflow-x: auto;">
            <?php foreach ($recent_errors as $error): ?>
                <div style="margin: 5px 0;"><?php echo esc_html($error); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($status !== 'good'): ?>
    <div class="section">
        <h2>💡 Recommendations</h2>
        
        <?php if ($memory_limit_bytes < 512 * 1024 * 1024): ?>
        <div class="recommendation">
            <h3>1. Increase PHP Memory Limit</h3>
            <p><strong>Current:</strong> <?php echo $memory_limit; ?> | <strong>Recommended:</strong> 512M</p>
            
            <h4>For Local by Flywheel:</h4>
            <p>1. Right-click on your site in Local → Open Site Shell</p>
            <p>2. Run: <code>php --ini</code> to find php.ini location</p>
            <p>3. Edit php.ini and set: <code>memory_limit = 512M</code></p>
            <p>4. Restart site in Local</p>
            
            <h4>For Standard WordPress:</h4>
            <p>Add to <code>wp-config.php</code> (before "That's all, stop editing!"):</p>
            <code style="display: block; padding: 10px; background: #fff; margin: 10px 0;">
                define('WP_MEMORY_LIMIT', '512M');<br>
                define('WP_MAX_MEMORY_LIMIT', '512M');
            </code>
        </div>
        <?php endif; ?>
        
        <?php if ($batch_size > 10 && $memory_limit_bytes < 512 * 1024 * 1024): ?>
        <div class="recommendation">
            <h3>2. Reduce Batch Size</h3>
            <p>Your current batch size (<?php echo $batch_size; ?>) is too high for your memory limit.</p>
            <p><strong>Recommended:</strong> Reduce to 5 products per batch</p>
            <p>Go to: <a href="<?php echo admin_url('admin.php?page=bytemash-amrod-sync-settings'); ?>">Plugin Settings</a></p>
        </div>
        <?php endif; ?>
        
        <div class="recommendation">
            <h3>3. Use Incremental Syncs</h3>
            <p>Instead of syncing all products, use "Sync Updated Products" option to only sync recent changes.</p>
            <p>This significantly reduces memory usage.</p>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="section">
        <h2>🧪 Memory Test</h2>
        <p>Test memory allocation to see if large operations will succeed:</p>
        
        <button class="test-button" onclick="runMemoryTest(100)">Test 100MB Allocation</button>
        <button class="test-button" onclick="runMemoryTest(200)">Test 200MB Allocation</button>
        <button class="test-button" onclick="runMemoryTest(300)">Test 300MB Allocation</button>
        
        <div id="test-result" style="margin-top: 20px;"></div>
    </div>
    
    <div class="section">
        <h2>📚 Documentation</h2>
        <p>For detailed information about memory optimization, see:</p>
        <ul>
            <li><a href="<?php echo plugins_url('MEMORY-OPTIMIZATION.md', __FILE__); ?>" target="_blank">Memory Optimization Guide</a></li>
            <li><a href="<?php echo admin_url('admin.php?page=bytemash-amrod-sync'); ?>">Plugin Dashboard</a></li>
            <li><a href="<?php echo admin_url('admin.php?page=bytemash-amrod-sync-settings'); ?>">Plugin Settings</a></li>
        </ul>
    </div>
    
    <script>
    function runMemoryTest(sizeMB) {
        const resultDiv = document.getElementById('test-result');
        resultDiv.innerHTML = '<p>Testing allocation of ' + sizeMB + 'MB...</p>';
        
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=bytemash_test_memory&size=' + sizeMB + '&nonce=<?php echo wp_create_nonce('memory_test'); ?>'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                resultDiv.innerHTML = '<div class="info-box"><strong>✓ Success!</strong> Allocated ' + sizeMB + 'MB successfully.<br>Peak usage: ' + data.data.peak_mb + 'MB</div>';
            } else {
                resultDiv.innerHTML = '<div class="error-box"><strong>✗ Failed!</strong> ' + data.data.message + '</div>';
            }
        })
        .catch(error => {
            resultDiv.innerHTML = '<div class="error-box"><strong>✗ Error!</strong> ' + error.message + '</div>';
        });
    }
    </script>
    
    <p style="text-align: center; color: #666; margin-top: 40px;">
        Generated: <?php echo date('Y-m-d H:i:s'); ?> | 
        PHP Version: <?php echo phpversion(); ?> | 
        WordPress Version: <?php echo get_bloginfo('version'); ?>
    </p>
</body>
</html>

<?php
// Helper functions
function convert_to_bytes($value) {
    $value = trim($value);
    $last = strtolower($value[strlen($value) - 1]);
    $value = (int) $value;
    
    switch ($last) {
        case 'g':
            $value *= 1024;
        case 'm':
            $value *= 1024;
        case 'k':
            $value *= 1024;
    }
    
    return $value;
}

function format_bytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// AJAX handler for memory test
add_action('wp_ajax_bytemash_test_memory', function() {
    check_ajax_referer('memory_test', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }
    
    $size_mb = isset($_POST['size']) ? (int) $_POST['size'] : 100;
    $size_bytes = $size_mb * 1024 * 1024;
    
    try {
        // Attempt to allocate memory
        $test_array = array();
        for ($i = 0; $i < $size_bytes / 100; $i++) {
            $test_array[] = str_repeat('x', 100);
        }
        
        $peak = memory_get_peak_usage(true);
        unset($test_array);
        
        wp_send_json_success([
            'allocated_mb' => $size_mb,
            'peak_mb' => round($peak / 1024 / 1024, 2),
        ]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
});
?>

