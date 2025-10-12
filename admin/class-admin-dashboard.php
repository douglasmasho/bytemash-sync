<?php
/**
 * Admin Dashboard
 * 
 * Main dashboard interface for monitoring and manual syncs
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_Admin_Dashboard {
    
    /**
     * Render dashboard
     */
    public static function render_dashboard() {
        // Check if authenticated first
        $api_client = new ByteMash_Amrod_API_Client();
        $is_authenticated = $api_client->is_authenticated();
        
        if (!$is_authenticated) {
            ?>
            <div class="wrap bytemash-admin-wrap">
                <h1><?php esc_html_e('Amrod Sync Dashboard', 'bytemash-woo-sync'); ?></h1>
                
                <div class="bytemash-auth-required">
                    <div class="bytemash-notice-card">
                        <span class="dashicons dashicons-lock"></span>
                        <h2><?php esc_html_e('Authentication Required', 'bytemash-woo-sync'); ?></h2>
                        <p><?php esc_html_e('Please authenticate with your Amrod account to access the sync dashboard.', 'bytemash-woo-sync'); ?></p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=bytemash-amrod-settings')); ?>" class="button button-primary button-large">
                            <span class="dashicons dashicons-admin-network"></span>
                            <?php esc_html_e('Go to Settings & Authenticate', 'bytemash-woo-sync'); ?>
                        </a>
                    </div>
                </div>
            </div>
            <?php
            return;
        }
        
        // Clear stale sync status on dashboard load (in case it got stuck)
        $batch_processor = new ByteMash_Batch_Processor();
        $active_syncs = $batch_processor->get_active_syncs();
        $has_active_syncs = false;
        
        foreach ($active_syncs as $sync) {
            if (in_array($sync['status'], ['processing', 'scheduled'])) {
                $has_active_syncs = true;
                break;
            }
        }
        
        // If no active syncs but transient is set, clear it
        $is_syncing = get_transient('bytemash_sync_running');
        if ($is_syncing && !$has_active_syncs) {
            delete_transient('bytemash_sync_running');
            $is_syncing = false;
        }
        
        $logger = new ByteMash_Logger();
        $stats = $logger->get_sync_stats(7);
        $last_sync = $logger->get_last_sync_time('product_sync');
        
        // Get WooCommerce product counts
        $total_products = wp_count_posts('product');
        $amrod_products = self::get_amrod_product_count();
        
        ?>
        <div class="wrap bytemash-admin-wrap">
            <h1><?php esc_html_e('Amrod Sync Dashboard', 'bytemash-woo-sync'); ?></h1>
            
            <div class="bytemash-dashboard">
                <!-- Sync Status Card -->
                <div class="bytemash-card bytemash-status-card">
                    <h2><?php esc_html_e('Sync Status', 'bytemash-woo-sync'); ?></h2>
                    
                    <div class="bytemash-status-info">
                        <?php if ($is_syncing) : ?>
                            <div class="bytemash-status-badge syncing">
                                <span class="dashicons dashicons-update-alt"></span>
                                <?php esc_html_e('Sync in Progress', 'bytemash-woo-sync'); ?>
                            </div>
                        <?php else : ?>
                            <div class="bytemash-status-badge idle">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php esc_html_e('Idle', 'bytemash-woo-sync'); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="bytemash-last-sync">
                            <strong><?php esc_html_e('Last Sync:', 'bytemash-woo-sync'); ?></strong>
                            <?php if ($last_sync) : ?>
                                <?php echo esc_html(human_time_diff(strtotime($last_sync), current_time('timestamp')) . ' ago'); ?>
                            <?php else : ?>
                                <?php esc_html_e('Never', 'bytemash-woo-sync'); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="bytemash-sync-actions">
                        <div class="bytemash-sync-section">
                            <h3><?php esc_html_e('Products', 'bytemash-woo-sync'); ?></h3>
                            <div class="bytemash-button-group">
                                <button type="button" 
                                        class="button button-primary" 
                                        data-action="manual_sync"
                                        data-ajax-action="bytemash_manual_sync">
                                    <span class="dashicons dashicons-update"></span>
                                    <?php esc_html_e('Full Sync', 'bytemash-woo-sync'); ?>
                                </button>
                                <button type="button" 
                                        class="button button-secondary" 
                                        data-action="sync_products_incremental"
                                        data-ajax-action="bytemash_sync_products_incremental">
                                    <span class="dashicons dashicons-download"></span>
                                    <?php esc_html_e('Incremental', 'bytemash-woo-sync'); ?>
                                </button>
                            </div>
                        </div>
                        
                        <div class="bytemash-sync-section">
                            <h3><?php esc_html_e('Stock', 'bytemash-woo-sync'); ?></h3>
                            <div class="bytemash-button-group">
                                <button type="button" 
                                        class="button button-primary" 
                                        data-action="stock_sync"
                                        data-ajax-action="bytemash_sync_stock">
                                    <span class="dashicons dashicons-database"></span>
                                    <?php esc_html_e('Full Sync', 'bytemash-woo-sync'); ?>
                                </button>
                                <button type="button" 
                                        class="button button-secondary" 
                                        data-action="stock_sync_incremental"
                                        data-ajax-action="bytemash_stock_sync_incremental">
                                    <span class="dashicons dashicons-download"></span>
                                    <?php esc_html_e('Incremental', 'bytemash-woo-sync'); ?>
                                </button>
                            </div>
                        </div>
                        
                        <div class="bytemash-sync-section">
                            <h3><?php esc_html_e('Prices', 'bytemash-woo-sync'); ?></h3>
                            <div class="bytemash-button-group">
                                <button type="button" 
                                        class="button button-primary" 
                                        data-action="price_sync"
                                        data-ajax-action="bytemash_sync_prices">
                                    <span class="dashicons dashicons-tag"></span>
                                    <?php esc_html_e('Full Sync', 'bytemash-woo-sync'); ?>
                                </button>
                                <button type="button" 
                                        class="button button-secondary" 
                                        data-action="price_sync_incremental"
                                        data-ajax-action="bytemash_price_sync_incremental">
                                    <span class="dashicons dashicons-download"></span>
                                    <?php esc_html_e('Incremental', 'bytemash-woo-sync'); ?>
                                </button>
                            </div>
                        </div>
                        
                        <div class="bytemash-sync-section">
                            <h3><?php esc_html_e('Categories', 'bytemash-woo-sync'); ?></h3>
                            <div class="bytemash-button-group">
                                <button type="button" 
                                        class="button button-primary" 
                                        data-action="category_sync"
                                        data-ajax-action="bytemash_category_sync">
                                    <span class="dashicons dashicons-category"></span>
                                    <?php esc_html_e('Sync Categories', 'bytemash-woo-sync'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Active Syncs Progress -->
                    <div id="active_syncs" class="bytemash-active-syncs"></div>
                    
                    <!-- Stop Sync Button (shown when sync is active) -->
                    <div id="stop_sync_container" style="display: none; margin-top: 15px;">
                        <button type="button" 
                                id="stop_sync_button" 
                                class="button button-secondary button-large" 
                                style="width: 100%; background: #d63638; color: white; border-color: #a02323;">
                            <span class="dashicons dashicons-no"></span>
                            <?php esc_html_e('Stop Sync', 'bytemash-woo-sync'); ?>
                        </button>
                        <p style="text-align: center; margin-top: 10px; color: #646970; font-size: 12px;">
                            <?php esc_html_e('Note: Current chunk will complete before stopping', 'bytemash-woo-sync'); ?>
                        </p>
                    </div>
                    
                    <div id="sync_message" class="bytemash-sync-message" style="display: none;"></div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="bytemash-stats-grid">
                    <div class="bytemash-card bytemash-stat-card">
                        <div class="bytemash-stat-icon">
                            <span class="dashicons dashicons-products"></span>
                        </div>
                        <div class="bytemash-stat-content">
                            <div class="bytemash-stat-value"><?php echo number_format($total_products->publish); ?></div>
                            <div class="bytemash-stat-label"><?php esc_html_e('Total Products', 'bytemash-woo-sync'); ?></div>
                        </div>
                    </div>
                    
                    <div class="bytemash-card bytemash-stat-card">
                        <div class="bytemash-stat-icon amrod">
                            <span class="dashicons dashicons-cloud"></span>
                        </div>
                        <div class="bytemash-stat-content">
                            <div class="bytemash-stat-value"><?php echo number_format($amrod_products); ?></div>
                            <div class="bytemash-stat-label"><?php esc_html_e('Amrod Products', 'bytemash-woo-sync'); ?></div>
                        </div>
                    </div>
                    
                    <div class="bytemash-card bytemash-stat-card">
                        <div class="bytemash-stat-icon success">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </div>
                        <div class="bytemash-stat-content">
                            <div class="bytemash-stat-value"><?php echo number_format($stats['success']); ?></div>
                            <div class="bytemash-stat-label"><?php esc_html_e('Successful (7d)', 'bytemash-woo-sync'); ?></div>
                        </div>
                    </div>
                    
                    <div class="bytemash-card bytemash-stat-card">
                        <div class="bytemash-stat-icon error">
                            <span class="dashicons dashicons-warning"></span>
                        </div>
                        <div class="bytemash-stat-content">
                            <div class="bytemash-stat-value"><?php echo number_format($stats['error']); ?></div>
                            <div class="bytemash-stat-label"><?php esc_html_e('Errors (7d)', 'bytemash-woo-sync'); ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activity -->
                <div class="bytemash-card">
                    <h2><?php esc_html_e('Recent Activity', 'bytemash-woo-sync'); ?></h2>
                    
                    <?php
                    $recent_logs = $logger->get_logs(10);
                    
                    if (!empty($recent_logs)) :
                    ?>
                        <table class="widefat bytemash-logs-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Time', 'bytemash-woo-sync'); ?></th>
                                    <th><?php esc_html_e('Type', 'bytemash-woo-sync'); ?></th>
                                    <th><?php esc_html_e('Status', 'bytemash-woo-sync'); ?></th>
                                    <th><?php esc_html_e('Message', 'bytemash-woo-sync'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_logs as $log) : ?>
                                    <tr>
                                        <td>
                                            <?php echo esc_html(human_time_diff(strtotime($log['created_at']), current_time('timestamp')) . ' ago'); ?>
                                        </td>
                                        <td>
                                            <span class="bytemash-log-type"><?php echo esc_html($log['sync_type']); ?></span>
                                        </td>
                                        <td>
                                            <span class="bytemash-log-status status-<?php echo esc_attr($log['status']); ?>">
                                                <?php echo esc_html(ucfirst($log['status'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html($log['message']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <p class="bytemash-view-all">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=bytemash-amrod-logs')); ?>" class="button">
                                <?php esc_html_e('View All Logs', 'bytemash-woo-sync'); ?>
                            </a>
                        </p>
                    <?php else : ?>
                        <p><?php esc_html_e('No activity yet. Start your first sync!', 'bytemash-woo-sync'); ?></p>
                    <?php endif; ?>
                </div>
                
                <!-- Quick Actions -->
                <div class="bytemash-card">
                    <h2><?php esc_html_e('Quick Actions', 'bytemash-woo-sync'); ?></h2>
                    
                    <div class="bytemash-quick-actions">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=bytemash-amrod-settings')); ?>" class="button">
                            <span class="dashicons dashicons-admin-settings"></span>
                            <?php esc_html_e('Settings', 'bytemash-woo-sync'); ?>
                        </a>
                        
                        <a href="<?php echo esc_url(admin_url('admin.php?page=bytemash-amrod-logs')); ?>" class="button">
                            <span class="dashicons dashicons-list-view"></span>
                            <?php esc_html_e('View Logs', 'bytemash-woo-sync'); ?>
                        </a>
                        
                        <a href="https://newapidocs.amrod.co.za/" target="_blank" class="button">
                            <span class="dashicons dashicons-book"></span>
                            <?php esc_html_e('API Docs', 'bytemash-woo-sync'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render logs page
     */
    public static function render_logs() {
        $logger = new ByteMash_Logger();
        
        // Handle log clearing
        if (isset($_POST['clear_logs']) && check_admin_referer('bytemash_clear_logs', 'bytemash_logs_nonce')) {
            $logger->clear_all_logs();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Logs cleared successfully!', 'bytemash-woo-sync') . '</p></div>';
        }
        
        // Pagination
        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $per_page = 50;
        $offset = ($page - 1) * $per_page;
        
        // Filter
        $level = isset($_GET['level']) && $_GET['level'] !== 'all' ? sanitize_text_field($_GET['level']) : null;
        
        $logs = $logger->get_logs($per_page, $offset, $level);
        $total_logs = $logger->get_logs_count($level);
        $total_pages = ceil($total_logs / $per_page);
        
        ?>
        <div class="wrap bytemash-admin-wrap">
            <h1><?php esc_html_e('Sync Logs', 'bytemash-woo-sync'); ?></h1>
            
            <div class="bytemash-logs-filters">
                <form method="get">
                    <input type="hidden" name="page" value="bytemash-amrod-logs">
                    
                    <select name="level" onchange="this.form.submit()">
                        <option value="all"><?php esc_html_e('All Levels', 'bytemash-woo-sync'); ?></option>
                        <option value="success" <?php selected($level, 'success'); ?>><?php esc_html_e('Success', 'bytemash-woo-sync'); ?></option>
                        <option value="info" <?php selected($level, 'info'); ?>><?php esc_html_e('Info', 'bytemash-woo-sync'); ?></option>
                        <option value="warning" <?php selected($level, 'warning'); ?>><?php esc_html_e('Warning', 'bytemash-woo-sync'); ?></option>
                        <option value="error" <?php selected($level, 'error'); ?>><?php esc_html_e('Error', 'bytemash-woo-sync'); ?></option>
                    </select>
                </form>
                
                <form method="post">
                    <?php wp_nonce_field('bytemash_clear_logs', 'bytemash_logs_nonce'); ?>
                    <button type="submit" name="clear_logs" class="button button-secondary" 
                            onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear all logs?', 'bytemash-woo-sync'); ?>')">
                        <?php esc_html_e('Clear All Logs', 'bytemash-woo-sync'); ?>
                    </button>
                </form>
            </div>
            
            <?php if (!empty($logs)) : ?>
                <table class="widefat bytemash-logs-table-full">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('ID', 'bytemash-woo-sync'); ?></th>
                            <th><?php esc_html_e('Date/Time', 'bytemash-woo-sync'); ?></th>
                            <th><?php esc_html_e('Type', 'bytemash-woo-sync'); ?></th>
                            <th><?php esc_html_e('Status', 'bytemash-woo-sync'); ?></th>
                            <th><?php esc_html_e('Message', 'bytemash-woo-sync'); ?></th>
                            <th><?php esc_html_e('Details', 'bytemash-woo-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log) : ?>
                            <tr>
                                <td><?php echo esc_html($log['id']); ?></td>
                                <td><?php echo esc_html($log['created_at']); ?></td>
                                <td><?php echo esc_html($log['sync_type']); ?></td>
                                <td>
                                    <span class="bytemash-log-status status-<?php echo esc_attr($log['status']); ?>">
                                        <?php echo esc_html(ucfirst($log['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log['message']); ?></td>
                                <td>
                                    <?php if (!empty($log['data'])) : ?>
                                        <button type="button" class="button button-small bytemash-view-details" 
                                                data-details="<?php echo esc_attr(json_encode($log['data'])); ?>">
                                            <?php esc_html_e('View', 'bytemash-woo-sync'); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($total_pages > 1) : ?>
                    <div class="tablenav">
                        <div class="tablenav-pages">
                            <?php
                            echo paginate_links(array(
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => __('&laquo;'),
                                'next_text' => __('&raquo;'),
                                'total' => $total_pages,
                                'current' => $page,
                            ));
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else : ?>
                <p><?php esc_html_e('No logs found.', 'bytemash-woo-sync'); ?></p>
            <?php endif; ?>
        </div>
        
        <!-- Log Details Modal -->
        <div id="bytemash_log_modal" class="bytemash-modal" style="display: none;">
            <div class="bytemash-modal-content">
                <span class="bytemash-modal-close">&times;</span>
                <h2><?php esc_html_e('Log Details', 'bytemash-woo-sync'); ?></h2>
                <pre id="bytemash_log_details"></pre>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get count of products synced from Amrod
     */
    private static function get_amrod_product_count() {
        global $wpdb;
        
        $count = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND pm.meta_key = '_amrod_simple_code'
        ");
        
        return (int) $count;
    }
}


