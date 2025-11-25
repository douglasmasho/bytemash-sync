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
                <div class="bytemash-brand" style="margin:10px 0;">
                    <img src="<?php echo esc_url( BYTEMASH_WOO_SYNC_PLUGIN_URL . 'assets/bmlogoblack.svg' ); ?>" alt="ByteMash" style="height:36px; width:auto; display:block;" />
                </div>
                <h1>
                    <a href="https://byte.mashdev.org" target="_blank" rel="noopener noreferrer" style="text-decoration: none; color: inherit;">
                        <?php esc_html_e('Amrod Sync Dashboard', 'bytemash-woo-sync'); ?>
                    </a>
                </h1>
                
                <div class="bytemash-auth-required">
                    <div class="bytemash-notice-card">
                        <span class="dashicons dashicons-lock"></span>
                        <h2><?php esc_html_e('Authentication Required', 'bytemash-woo-sync'); ?></h2>
                        <p><?php esc_html_e('Please authenticate with your Amrod account to access the sync dashboard.', 'bytemash-woo-sync'); ?></p>
                        <form action="<?php echo esc_url(admin_url('admin.php')); ?>" method="get" style="display:inline-block;">
                            <input type="hidden" name="page" value="bytemash-amrod-settings">
                            <button type="submit" class="button button-primary button-hero">
                               
                                <?php esc_html_e('Authenticate & Connect', 'bytemash-woo-sync'); ?>
                            </button>
                        </form>
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
            <div class="bytemash-brand" style="margin:10px 0;">
                <img src="<?php echo esc_url( BYTEMASH_WOO_SYNC_PLUGIN_URL . 'assets/bmlogoblack.svg' ); ?>" alt="ByteMash" style="height:36px; width:auto; display:block;" />
            </div>
            <h1>
                <a href="https://byte.mashdev.org" target="_blank" rel="noopener noreferrer" style="text-decoration: none; color: inherit;">
                    <?php esc_html_e('Amrod Sync Dashboard', 'bytemash-woo-sync'); ?>
                </a>
            </h1>
            
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
                                <a href="<?php echo admin_url('admin.php?page=bytemash-amrod-tools'); ?>" 
                                   class="button button-secondary"
                                   style="background: #d63638; border-color: #d63638; color: white;">
                                    <span class="dashicons dashicons-trash"></span>
                                    <?php esc_html_e('Clear All Products', 'bytemash-woo-sync'); ?>
                                </a>
                            </div>
                            <div class="bytemash-cleanup-controls" style="margin-top:8px; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                                <button type="button"
                                        id="bytemash_delete_excess_button"
                                        class="button button-secondary"
                                        data-action="delete_excess_products"
                                        data-ajax-action="bytemash_delete_excess_products">
                                    <span class="dashicons dashicons-trash"></span>
                                    <?php esc_html_e('Delete Excess Products', 'bytemash-woo-sync'); ?>
                                </button>
                                <label for="queue_cleanup_after_product_sync" style="display:flex; align-items:center; gap:6px; font-weight:500; cursor:pointer;">
                                    <input type="checkbox" id="queue_cleanup_after_product_sync" />
                                    <?php esc_html_e('Run deletion after product sync', 'bytemash-woo-sync'); ?>
                                </label>
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
                                <button type="button" 
                                        class="button button-secondary" 
                                        data-action="orphan_price_sync"
                                        data-ajax-action="bytemash_sync_orphan_prices"
                                        title="Match prices for products missing prices using SKU prefix">
                                    <span class="dashicons dashicons-search"></span>
                                    <?php esc_html_e('Fix Missing', 'bytemash-woo-sync'); ?>
                                </button>
                            </div>
                        </div>
                        
                        <div class="bytemash-sync-section">
                            <h3><?php esc_html_e('Categories', 'bytemash-woo-sync'); ?></h3>
                            <div class="bytemash-button-group">
                                <button type="button" 
                                        class="button button-primary" 
                                        data-action="category_sync"
                                        data-ajax-action="bytemash_sync_categories">
                                    <span class="dashicons dashicons-category"></span>
                                    <?php esc_html_e('Sync Categories', 'bytemash-woo-sync'); ?>
                                </button>
                            </div>
                        </div>
                        
                        <div class="bytemash-sync-section">
                            <h3><?php esc_html_e('Color Swatches', 'bytemash-woo-sync'); ?></h3>
                            <div class="bytemash-button-group">
                                <button type="button" 
                                        class="button button-primary" 
                                        data-action="color_swatches_sync"
                                        data-ajax-action="bytemash_sync_color_swatches">
                                    <span class="dashicons dashicons-art"></span>
                                    <?php esc_html_e('Sync Swatches', 'bytemash-woo-sync'); ?>
                                </button>
                            </div>
                        </div>
                        
                        <div class="bytemash-sync-section">
                            <h3><?php esc_html_e('Brands', 'bytemash-woo-sync'); ?></h3>
                            <div class="bytemash-button-group">
                                <button type="button" 
                                        class="button button-primary" 
                                        data-action="brands_sync"
                                        data-ajax-action="bytemash_sync_brands">
                                    <span class="dashicons dashicons-star-filled"></span>
                                    <?php esc_html_e('Sync Brands', 'bytemash-woo-sync'); ?>
                                </button>
                            </div>
                        </div>
                        
                        <div class="bytemash-sync-section">
                            <h3><?php esc_html_e('Branding Options', 'bytemash-woo-sync'); ?></h3>
                            <div class="bytemash-button-group">
                                <button type="button" 
                                        class="button button-primary" 
                                        data-action="branding_departments_sync"
                                        data-ajax-action="bytemash_sync_branding_departments">
                                    <span class="dashicons dashicons-admin-tools"></span>
                                    <?php esc_html_e('Departments', 'bytemash-woo-sync'); ?>
                                </button>
                                <button type="button" 
                                        class="button button-primary" 
                                        data-action="branding_prices_sync"
                                        data-ajax-action="bytemash_sync_branding_prices">
                                    <span class="dashicons dashicons-money-alt"></span>
                                    <?php esc_html_e('Prices', 'bytemash-woo-sync'); ?>
                                </button>
                                <button type="button" 
                                        class="button button-primary" 
                                        data-action="inclusive_brandings_sync"
                                        data-ajax-action="bytemash_sync_inclusive_brandings">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <?php esc_html_e('Inclusive', 'bytemash-woo-sync'); ?>
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
                        <div class="bytemash-stat-icon">
                            <span class="dashicons dashicons-warning" style="font-size:28px; line-height:48px; text-align:center; width:48px; height:48px; display:inline-block; color:#d63638;"></span>
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
                
                <!-- Scheduled Sync Monitoring -->
                <div class="bytemash-card bytemash-scheduled-sync-card">
                    <h2>
                        <span class="dashicons dashicons-clock"></span>
                        <?php esc_html_e('Scheduled Sync Monitoring', 'bytemash-woo-sync'); ?>
                        <span class="bytemash-auto-refresh-indicator" id="auto_refresh_indicator">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('Auto-refresh', 'bytemash-woo-sync'); ?>
                        </span>
                    </h2>
                    
                    <div class="bytemash-scheduled-sync-content">
                        <!-- Sync Status Overview -->
                        <div class="bytemash-sync-status-overview">
                            <div class="bytemash-sync-status-item">
                                <strong><?php esc_html_e('Full Sync:', 'bytemash-woo-sync'); ?></strong>
                                <span id="full_sync_status">
                                    <?php 
                                    $full_sync_next = wp_next_scheduled('bytemash_full_sync_cron');
                                    if ($full_sync_next) {
                                        echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $full_sync_next));
                                    } else {
                                        echo esc_html__('Not scheduled', 'bytemash-woo-sync');
                                    }
                                    ?>
                                </span>
                                <span id="full_sync_running" class="sync-running-indicator" style="display: none;">
                                    <span class="dashicons dashicons-update-alt"></span>
                                    <?php esc_html_e('Running', 'bytemash-woo-sync'); ?>
                                </span>
                            </div>
                            
                            <div class="bytemash-sync-status-item">
                                <strong><?php esc_html_e('Incremental Sync:', 'bytemash-woo-sync'); ?></strong>
                                <span id="incremental_sync_status">
                                    <?php 
                                    $incremental_sync_next = wp_next_scheduled('bytemash_incremental_sync_cron');
                                    if ($incremental_sync_next) {
                                        echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $incremental_sync_next));
                                    } else {
                                        echo esc_html__('Not scheduled', 'bytemash-woo-sync');
                                    }
                                    ?>
                                </span>
                                <span id="incremental_sync_running" class="sync-running-indicator" style="display: none;">
                                    <span class="dashicons dashicons-update-alt"></span>
                                    <?php esc_html_e('Running', 'bytemash-woo-sync'); ?>
                                </span>
                            </div>
                            
                            <div class="bytemash-sync-status-item">
                                <strong><?php esc_html_e('Test Modes:', 'bytemash-woo-sync'); ?></strong>
                                <span id="test_mode_status">
                                    <?php 
                                    $full_test_mode = get_option('bytemash_cron_full_test_mode_enabled', false);
                                    $incremental_test_mode = get_option('bytemash_cron_incremental_test_mode_enabled', false);
                                    
                                    if ($full_test_mode || $incremental_test_mode) {
                                        echo '<div style="color: #28a745; font-weight: bold;">';
                                        if ($full_test_mode) {
                                            echo esc_html__('Full Test Mode: Enabled', 'bytemash-woo-sync') . '<br>';
                                        }
                                        if ($incremental_test_mode) {
                                            echo esc_html__('Incremental Test Mode: Enabled', 'bytemash-woo-sync') . '<br>';
                                        }
                                        echo '</div>';
                                    } else {
                                        echo '<span style="color: #6c757d;">' . esc_html__('Disabled', 'bytemash-woo-sync') . '</span>';
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Real-time Sync Activity -->
                        <div class="bytemash-realtime-activity">
                            <h3><?php esc_html_e('Real-time Sync Activity', 'bytemash-woo-sync'); ?></h3>
                            <div id="realtime_sync_logs" class="bytemash-realtime-logs">
                                <div class="bytemash-log-entry">
                                    <span class="log-time"><?php echo esc_html(current_time('H:i:s')); ?></span>
                                    <span class="log-type">system</span>
                                    <span class="log-status info"><?php esc_html_e('Monitoring started', 'bytemash-woo-sync'); ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Scheduled Sync Batch Processing -->
                        <div id="scheduled_sync_progress" class="bytemash-scheduled-sync-progress" style="display: none;">
                            <h3><?php esc_html_e('Scheduled Sync Batch Processing', 'bytemash-woo-sync'); ?></h3>
                            <div id="scheduled_sync_batches" class="scheduled-batch-container"></div>
                        </div>
                        
                        <!-- Manual Controls -->
                        <div class="bytemash-scheduled-controls">
                            <button type="button" id="refresh_scheduled_status" class="button button-secondary">
                                <span class="dashicons dashicons-update"></span>
                                <?php esc_html_e('Refresh Status', 'bytemash-woo-sync'); ?>
                            </button>
                            <button type="button" id="toggle_auto_refresh" class="button button-secondary">
                                <span class="dashicons dashicons-controls-play"></span>
                                <span id="auto_refresh_text"><?php esc_html_e('Enable Auto-refresh', 'bytemash-woo-sync'); ?></span>
                            </button>
                        </div>
                    </div>
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
                        
                        <?php if (class_exists('WooCommerce')) : ?>
                            <a href="<?php echo esc_url(admin_url('edit.php?post_type=shop_order&post_status=wc-quote-request')); ?>" class="button">
                                <span class="dashicons dashicons-email-alt"></span>
                                <?php esc_html_e('Quote Requests', 'bytemash-woo-sync'); ?>
                                <?php
                                $quote_count = self::get_quote_request_count();
                                if ($quote_count > 0) {
                                    echo ' <span class="count">(' . esc_html($quote_count) . ')</span>';
                                }
                                ?>
                            </a>
                        <?php endif; ?>
                        
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
    
    /**
     * Get count of quote requests
     */
    private static function get_quote_request_count() {
        if (!class_exists('WooCommerce')) {
            return 0;
        }
        
        $args = array(
            'post_type' => 'shop_order',
            'post_status' => 'wc-quote-request',
            'posts_per_page' => -1,
            'fields' => 'ids',
        );
        
        $query = new WP_Query($args);
        return (int) $query->found_posts;
    }
}


