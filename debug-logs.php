<?php
/**
 * Debug Logs Viewer
 * 
 * Usage: 
 * 1. Upload this file to your WordPress root directory
 * 2. Visit: http://yourdomain.com/debug-logs.php
 * 3. DELETE THIS FILE after debugging for security!
 */

// Load WordPress
require_once('wp-load.php');

// Security check - only allow admin
if (!current_user_can('manage_options')) {
    die('Access denied. You must be logged in as admin.');
}

// Get filter parameters
$filter_type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'all';
$filter_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

// Get logs from database
global $wpdb;
$table_name = $wpdb->prefix . 'bytemash_sync_logs';

$query = "SELECT * FROM {$table_name}";
$where_clauses = array();

if ($filter_type !== 'all') {
    $where_clauses[] = $wpdb->prepare("sync_type = %s", $filter_type);
}

if ($filter_status !== 'all') {
    $where_clauses[] = $wpdb->prepare("status = %s", $filter_status);
}

if (!empty($where_clauses)) {
    $query .= " WHERE " . implode(" AND ", $where_clauses);
}

$query .= " ORDER BY created_at DESC LIMIT " . $limit;

$logs = $wpdb->get_results($query, ARRAY_A);

// Get unique sync types and statuses for filters
$types = $wpdb->get_col("SELECT DISTINCT sync_type FROM {$table_name}");
$statuses = $wpdb->get_col("SELECT DISTINCT status FROM {$table_name}");

?>
<!DOCTYPE html>
<html>
<head>
    <title>ByteMash Sync - Debug Logs</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            background: #f0f0f1;
            padding: 20px;
            font-size: 13px;
            line-height: 1.5;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .warning {
            background: #fff3cd;
            border: 2px solid #ffc107;
            color: #856404;
            padding: 15px;
            margin: 20px;
            border-radius: 5px;
            font-weight: bold;
        }
        
        .filters {
            padding: 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #ddd;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .filter-group label {
            font-weight: 600;
            color: #555;
        }
        
        select, .btn {
            padding: 8px 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
            background: white;
        }
        
        .btn {
            background: #2271b1;
            color: white;
            border-color: #2271b1;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            background: #135e96;
        }
        
        .btn-secondary {
            background: #6c757d;
            border-color: #6c757d;
        }
        
        .btn-danger {
            background: #dc3545;
            border-color: #dc3545;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            padding: 20px;
            background: #f8f9fa;
        }
        
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 5px;
            border-left: 4px solid #2271b1;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        
        .stat-card.success { border-left-color: #28a745; }
        .stat-card.error { border-left-color: #dc3545; }
        .stat-card.warning { border-left-color: #ffc107; }
        .stat-card.info { border-left-color: #17a2b8; }
        
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }
        
        .stat-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            margin-top: 5px;
        }
        
        .logs-container {
            padding: 20px;
        }
        
        .log-entry {
            background: white;
            border: 1px solid #e1e1e1;
            border-radius: 5px;
            margin-bottom: 15px;
            overflow: hidden;
        }
        
        .log-header {
            background: #f8f9fa;
            padding: 12px 15px;
            border-bottom: 1px solid #e1e1e1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }
        
        .log-header:hover {
            background: #e9ecef;
        }
        
        .log-meta {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .badge {
            padding: 4px 10px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge.success { background: #d4edda; color: #155724; }
        .badge.error { background: #f8d7da; color: #721c24; }
        .badge.warning { background: #fff3cd; color: #856404; }
        .badge.info { background: #d1ecf1; color: #0c5460; }
        
        .log-type {
            font-family: monospace;
            background: #e9ecef;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 11px;
        }
        
        .log-time {
            color: #666;
            font-size: 12px;
        }
        
        .log-id {
            color: #999;
            font-size: 11px;
        }
        
        .log-body {
            padding: 15px;
            display: none;
        }
        
        .log-body.active {
            display: block;
        }
        
        .log-message {
            font-size: 14px;
            margin-bottom: 15px;
            color: #333;
        }
        
        .log-data {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            border: 1px solid #e1e1e1;
        }
        
        .log-data h4 {
            margin-bottom: 10px;
            color: #555;
            font-size: 13px;
        }
        
        pre {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 12px;
            line-height: 1.5;
            font-family: 'Courier New', monospace;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        
        .empty-state svg {
            width: 80px;
            height: 80px;
            opacity: 0.3;
            margin-bottom: 20px;
        }
        
        .toggle-icon {
            transition: transform 0.2s;
        }
        
        .toggle-icon.rotated {
            transform: rotate(180deg);
        }
        
        @media (max-width: 768px) {
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                width: 100%;
            }
            
            select, .btn {
                width: 100%;
            }
            
            .log-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🔍 ByteMash Sync - Debug Logs</h1>
            <p>Real-time debugging and monitoring for Amrod product synchronization</p>
        </header>
        
        <div class="warning">
            ⚠️ <strong>SECURITY WARNING:</strong> This file shows sensitive debugging information. 
            DELETE THIS FILE immediately after debugging! (debug-logs.php)
        </div>
        
        <?php
        // Calculate stats
        $total_logs = count($logs);
        $success_count = 0;
        $error_count = 0;
        $warning_count = 0;
        $info_count = 0;
        
        foreach ($logs as $log) {
            switch ($log['status']) {
                case 'success': $success_count++; break;
                case 'error': $error_count++; break;
                case 'warning': $warning_count++; break;
                case 'info': $info_count++; break;
            }
        }
        ?>
        
        <div class="stats">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_logs; ?></div>
                <div class="stat-label">Total Logs</div>
            </div>
            <div class="stat-card success">
                <div class="stat-value"><?php echo $success_count; ?></div>
                <div class="stat-label">Success</div>
            </div>
            <div class="stat-card error">
                <div class="stat-value"><?php echo $error_count; ?></div>
                <div class="stat-label">Errors</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-value"><?php echo $warning_count; ?></div>
                <div class="stat-label">Warnings</div>
            </div>
            <div class="stat-card info">
                <div class="stat-value"><?php echo $info_count; ?></div>
                <div class="stat-label">Info</div>
            </div>
        </div>
        
        <form method="get" class="filters">
            <div class="filter-group">
                <label>Sync Type:</label>
                <select name="type" onchange="this.form.submit()">
                    <option value="all" <?php selected($filter_type, 'all'); ?>>All Types</option>
                    <?php foreach ($types as $type): ?>
                        <option value="<?php echo esc_attr($type); ?>" <?php selected($filter_type, $type); ?>>
                            <?php echo esc_html($type); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label>Status:</label>
                <select name="status" onchange="this.form.submit()">
                    <option value="all" <?php selected($filter_status, 'all'); ?>>All Status</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?php echo esc_attr($status); ?>" <?php selected($filter_status, $status); ?>>
                            <?php echo esc_html(ucfirst($status)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label>Limit:</label>
                <select name="limit" onchange="this.form.submit()">
                    <option value="50" <?php selected($limit, 50); ?>>50</option>
                    <option value="100" <?php selected($limit, 100); ?>>100</option>
                    <option value="250" <?php selected($limit, 250); ?>>250</option>
                    <option value="500" <?php selected($limit, 500); ?>>500</option>
                    <option value="1000" <?php selected($limit, 1000); ?>>1000</option>
                </select>
            </div>
            
            <a href="?" class="btn btn-secondary">Reset Filters</a>
            <a href="<?php echo admin_url('admin.php?page=bytemash-amrod-sync'); ?>" class="btn">
                Go to Dashboard
            </a>
        </form>
        
        <div class="logs-container">
            <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                    </svg>
                    <h3>No logs found</h3>
                    <p>Try adjusting your filters or run a sync to generate logs.</p>
                </div>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <div class="log-entry">
                        <div class="log-header" onclick="toggleLog(<?php echo $log['id']; ?>)">
                            <div class="log-meta">
                                <span class="log-id">#<?php echo $log['id']; ?></span>
                                <span class="badge <?php echo esc_attr($log['status']); ?>">
                                    <?php echo esc_html($log['status']); ?>
                                </span>
                                <span class="log-type"><?php echo esc_html($log['sync_type']); ?></span>
                                <span class="log-time"><?php echo esc_html($log['created_at']); ?></span>
                            </div>
                            <span class="toggle-icon" id="icon-<?php echo $log['id']; ?>">▼</span>
                        </div>
                        <div class="log-body" id="log-<?php echo $log['id']; ?>">
                            <div class="log-message">
                                <strong>Message:</strong> <?php echo esc_html($log['message']); ?>
                            </div>
                            
                            <?php if (!empty($log['data'])): ?>
                                <div class="log-data">
                                    <h4>Additional Data:</h4>
                                    <pre><?php 
                                        $data = maybe_unserialize($log['data']);
                                        echo esc_html(print_r($data, true)); 
                                    ?></pre>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function toggleLog(id) {
            const body = document.getElementById('log-' + id);
            const icon = document.getElementById('icon-' + id);
            
            if (body.classList.contains('active')) {
                body.classList.remove('active');
                icon.classList.remove('rotated');
            } else {
                body.classList.add('active');
                icon.classList.add('rotated');
            }
        }
        
        // Auto-expand error logs
        document.addEventListener('DOMContentLoaded', function() {
            const errorLogs = document.querySelectorAll('.badge.error');
            errorLogs.forEach(function(badge) {
                const logEntry = badge.closest('.log-entry');
                const logId = logEntry.querySelector('.log-header').getAttribute('onclick').match(/\d+/)[0];
                const body = document.getElementById('log-' + logId);
                const icon = document.getElementById('icon-' + logId);
                body.classList.add('active');
                icon.classList.add('rotated');
            });
        });
    </script>
</body>
</html>

