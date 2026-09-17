<?php
/**
 * Update API URL Helper
 * 
 * Use this to quickly update the Amrod API base URL for testing
 * 
 * Usage:
 * 1. Edit the $new_api_url below
 * 2. Visit: http://yourdomain.com/update-api-url.php
 * 3. DELETE this file after use!
 */

// Load WordPress
require_once('wp-load.php');

// Security check
if (!current_user_can('manage_options')) {
    die('Access denied. You must be logged in as admin.');
}

// Set your new API URL here
$new_api_url = 'https://newapidocs.amrod.co.za'; // Change this to test different URLs

// Update the option
update_option('bytemash_amrod_api_url', $new_api_url);

?>
<!DOCTYPE html>
<html>
<head>
    <title>API URL Updated</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f0f0f1;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        h1 { color: #2271b1; margin-top: 0; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; border-left: 4px solid #28a745; }
        .warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; border-left: 4px solid #ffc107; margin-top: 20px; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 5px; margin-top: 20px; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
        .btn { display: inline-block; padding: 10px 20px; background: #2271b1; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
        .btn:hover { background: #135e96; }
        ul { line-height: 2; }
    </style>
</head>
<body>
    <div class="container">
        <h1>✅ API URL Updated Successfully!</h1>
        
        <div class="success">
            <strong>New API URL:</strong> <code><?php echo esc_html($new_api_url); ?></code>
        </div>
        
        <div class="info">
            <h3>Common Amrod API URLs to Try:</h3>
            <ul>
                <li><code>https://newapidocs.amrod.co.za</code> - Documentation site (current)</li>
                <li><code>https://api.amrod.co.za</code> - Standard API endpoint (has DNS issue)</li>
                <li><code>https://identity.amrod.co.za</code> - Identity/Auth server (working)</li>
                <li><code>https://vendor.amrod.co.za</code> - Vendor portal</li>
                <li><code>https://amrod.co.za/api</code> - Main site API</li>
            </ul>
            <p><strong>Note:</strong> Edit this file to test different URLs</p>
        </div>
        
        <div class="warning">
            <strong>⚠️ IMPORTANT:</strong><br>
            1. Test the sync now to see if this URL works<br>
            2. Check the debug logs to see the actual response<br>
            3. DELETE this file (update-api-url.php) after use for security!
        </div>
        
        <div style="margin-top: 30px;">
            <a href="<?php echo admin_url('admin.php?page=bytemash-amrod-settings'); ?>" class="btn">
                Go to Plugin Settings
            </a>
            <a href="<?php echo admin_url('admin.php?page=bytemash-amrod-sync'); ?>" class="btn" style="background: #28a745;">
                Go to Dashboard & Test Sync
            </a>
            <a href="debug-logs.php" class="btn" style="background: #6c757d;">
                View Debug Logs
            </a>
        </div>
        
        <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd;">
            <h3>How to Find the Correct API URL:</h3>
            <ol style="line-height: 2;">
                <li>Check your Amrod account dashboard for API documentation</li>
                <li>Look at the authentication response - it may include an API base URL</li>
                <li>Contact Amrod support for the correct endpoint</li>
                <li>Check the response headers from the authentication call</li>
            </ol>
        </div>
    </div>
</body>
</html>

