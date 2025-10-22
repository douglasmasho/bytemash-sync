<?php
/**
 * Plugin Name: ByteMash WooCommerce Amrod Sync
 * Plugin URI: https://bytemash.com/woo-amrod-sync
 * Description: Memory-efficient WooCommerce plugin that syncs products, variations, stock, images, and all data from Amrod API with automatic scheduling and comprehensive dashboard. Features automatic memory management and token refresh!
 * Version: 1.1.2
 * Author: ByteMash
 * Author URI: https://bytemash.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bytemash-woo-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BYTEMASH_WOO_SYNC_VERSION', '1.1.2');
define('BYTEMASH_WOO_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BYTEMASH_WOO_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BYTEMASH_WOO_SYNC_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Require Composer autoloader if available
if (file_exists(BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Main plugin class
 */
class ByteMash_Woo_Sync {
    
    /**
     * Single instance of the class
     */
    private static $instance = null;
    
    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Load required dependencies
     */
    private function load_dependencies() {
        // Core classes
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-logger.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-amrod-api-client.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-image-handler.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-batch-processor.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-product-sync.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-sync-scheduler.php';
        
        // Admin classes
        if (is_admin()) {
            require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'admin/class-admin-settings.php';
            require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'admin/class-admin-dashboard.php';
        }
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'check_dependencies'));
        add_action('init', array($this, 'load_textdomain'));
        add_action('init', array($this, 'init_scheduler'));
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
        
        // Hook into WooCommerce product images
        add_filter('woocommerce_product_get_image_id', array($this, 'use_external_image_url'), 10, 2);
        add_filter('wp_get_attachment_image_src', array($this, 'replace_with_external_url'), 10, 4);
        add_filter('woocommerce_product_get_gallery_image_ids', array($this, 'use_external_gallery_urls'), 10, 2);
        
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'register_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            
            // AJAX handlers
            add_action('wp_ajax_bytemash_authenticate', array($this, 'ajax_authenticate'));
            add_action('wp_ajax_bytemash_save_api_url', array($this, 'ajax_save_api_url'));
            add_action('wp_ajax_bytemash_test_connection', array($this, 'ajax_test_connection'));
            add_action('wp_ajax_bytemash_clear_logs', array($this, 'ajax_clear_logs'));
            add_action('wp_ajax_bytemash_get_sync_progress', array($this, 'ajax_get_sync_progress'));
            add_action('wp_ajax_bytemash_stop_sync', array($this, 'ajax_stop_sync'));
            add_action('wp_ajax_bytemash_manual_sync', array($this, 'ajax_manual_sync'));
            add_action('wp_ajax_bytemash_sync_products_incremental', array($this, 'ajax_sync_products_incremental'));
            add_action('wp_ajax_bytemash_sync_stock', array($this, 'ajax_sync_stock'));
            add_action('wp_ajax_bytemash_stock_sync_incremental', array($this, 'ajax_sync_stock_incremental'));
            add_action('wp_ajax_bytemash_sync_prices', array($this, 'ajax_sync_prices'));
            add_action('wp_ajax_bytemash_price_sync_incremental', array($this, 'ajax_sync_prices_incremental'));
            add_action('wp_ajax_bytemash_sync_orphan_prices', array($this, 'ajax_sync_orphan_prices'));
            add_action('wp_ajax_bytemash_sync_categories', array($this, 'ajax_sync_categories'));
            add_action('wp_ajax_bytemash_sync_color_swatches', array($this, 'ajax_sync_color_swatches'));
            add_action('wp_ajax_bytemash_sync_brands', array($this, 'ajax_sync_brands'));
            add_action('wp_ajax_bytemash_sync_branding_departments', array($this, 'ajax_sync_branding_departments'));
            add_action('wp_ajax_bytemash_sync_branding_prices', array($this, 'ajax_sync_branding_prices'));
            add_action('wp_ajax_bytemash_sync_inclusive_brandings', array($this, 'ajax_sync_inclusive_brandings'));
            add_action('wp_ajax_bytemash_process_batch', array($this, 'ajax_process_batch'));
            add_action('wp_ajax_bytemash_get_batch', array($this, 'ajax_get_batch'));
        }
        
        // Activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Declare compatibility with WooCommerce HPOS
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }
    
    /**
     * Initialize scheduler after all dependencies are loaded
     */
    public function init_scheduler() {
        new ByteMash_Sync_Scheduler();
    }
    
    /**
     * Check if WooCommerce is active
     */
    public function check_dependencies() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            deactivate_plugins(BYTEMASH_WOO_SYNC_PLUGIN_BASENAME);
            return;
        }
    }
    
    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p><strong>' . esc_html__('ByteMash WooCommerce Amrod Sync', 'bytemash-woo-sync') . '</strong> ' . esc_html__('requires WooCommerce to be installed and active.', 'bytemash-woo-sync') . '</p></div>';
    }
    
    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('bytemash-woo-sync', false, dirname(BYTEMASH_WOO_SYNC_PLUGIN_BASENAME) . '/languages');
    }
    
    /**
     * Register admin menu
     */
    public function register_admin_menu() {
        add_menu_page(
            __('Amrod Sync', 'bytemash-woo-sync'),
            __('Amrod Sync', 'bytemash-woo-sync'),
            'manage_woocommerce',
            'bytemash-amrod-sync',
            array('ByteMash_Admin_Dashboard', 'render_dashboard'),
            'dashicons-update',
            56
        );
        
        add_submenu_page(
            'bytemash-amrod-sync',
            __('Dashboard', 'bytemash-woo-sync'),
            __('Dashboard', 'bytemash-woo-sync'),
            'manage_woocommerce',
            'bytemash-amrod-sync',
            array('ByteMash_Admin_Dashboard', 'render_dashboard')
        );
        
        add_submenu_page(
            'bytemash-amrod-sync',
            __('Settings', 'bytemash-woo-sync'),
            __('Settings', 'bytemash-woo-sync'),
            'manage_woocommerce',
            'bytemash-amrod-settings',
            array('ByteMash_Admin_Settings', 'render_settings')
        );
        
        add_submenu_page(
            'bytemash-amrod-sync',
            __('Sync Logs', 'bytemash-woo-sync'),
            __('Sync Logs', 'bytemash-woo-sync'),
            'manage_woocommerce',
            'bytemash-amrod-logs',
            array('ByteMash_Admin_Dashboard', 'render_logs')
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'bytemash-amrod') === false) {
            return;
        }
        
        wp_enqueue_style(
            'bytemash-woo-sync-admin',
            BYTEMASH_WOO_SYNC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            BYTEMASH_WOO_SYNC_VERSION
        );
        
        wp_enqueue_script(
            'bytemash-woo-sync-admin',
            BYTEMASH_WOO_SYNC_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            BYTEMASH_WOO_SYNC_VERSION,
            true
        );
        
        wp_localize_script('bytemash-woo-sync-admin', 'bytemashWooSync', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bytemash_woo_sync_nonce'),
            'strings' => array(
                'syncing' => __('Syncing...', 'bytemash-woo-sync'),
                'success' => __('Sync completed successfully!', 'bytemash-woo-sync'),
                'error' => __('Sync failed. Check logs for details.', 'bytemash-woo-sync'),
            ),
        ));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create logs table
        global $wpdb;
        $table_name = $wpdb->prefix . 'bytemash_sync_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sync_type varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            message longtext,
            data longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        // Set default options
        // Note: API URLs are now fixed in the code (identity.amrod.co.za for auth, vendorapi.amrod.co.za for data)
        add_option('bytemash_amrod_batch_size', 10); // Conservative batch size for products
        add_option('bytemash_amrod_sync_schedule', 'daily');
        
        // Initialize sync scheduler with default schedules
        $scheduler = new ByteMash_Sync_Scheduler();
        $scheduler->update_schedule('daily_at_0030', 'every_5_hours');
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear all sync schedules
        $scheduler = new ByteMash_Sync_Scheduler();
        $scheduler->clear_all_schedules();
    }
    
    /**
     * Use external image URL instead of attachment ID
     */
    public function use_external_image_url($image_id, $product) {
        // Ensure $product is an object
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return $image_id;
        }
        
        $external_url = get_post_meta($product->get_id(), '_thumbnail_external_url', true);
        
        if ($external_url) {
            // Return the URL as a fake attachment ID
            // This will be intercepted by replace_with_external_url filter
            return 'external_' . $product->get_id();
        }
        
        return $image_id;
    }
    
    /**
     * Replace attachment image src with external URL
     */
    public function replace_with_external_url($image, $attachment_id, $size, $icon) {
        // Check if this is our fake external featured image
        if (is_string($attachment_id) && strpos($attachment_id, 'external_') === 0) {
            $product_id = str_replace('external_', '', $attachment_id);
            $external_url = get_post_meta($product_id, '_thumbnail_external_url', true);
            
            if ($external_url) {
                return array($external_url, 1024, 1024, false);
            }
        }
        
        // Check if this is our fake gallery image
        if (is_string($attachment_id) && strpos($attachment_id, 'gallery_') === 0) {
            // Extract product_id and index from: gallery_123_0
            $parts = explode('_', $attachment_id);
            if (count($parts) >= 3) {
                $product_id = $parts[1];
                $index = (int) $parts[2];
                
                $gallery_urls = get_post_meta($product_id, '_amrod_gallery_images', true);
                
                if ($gallery_urls && is_array($gallery_urls) && isset($gallery_urls[$index])) {
                    return array($gallery_urls[$index], 1024, 1024, false);
                }
            }
        }
        
        return $image;
    }
    
    /**
     * Use external URLs for gallery images
     */
    public function use_external_gallery_urls($gallery_ids, $product) {
        // Ensure $product is an object
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return $gallery_ids;
        }
        
        // If product has external gallery images, return fake IDs
        $gallery_urls = get_post_meta($product->get_id(), '_amrod_gallery_images', true);
        
        if ($gallery_urls && is_array($gallery_urls)) {
            // Return fake IDs that will be replaced by URLs
            $fake_ids = array();
            foreach ($gallery_urls as $index => $url) {
                $fake_ids[] = 'gallery_' . $product->get_id() . '_' . $index;
            }
            return $fake_ids;
        }
        
        return $gallery_ids;
    }
    
    /**
     * AJAX: Authenticate with Amrod
     */
    public function ajax_authenticate() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Get credentials
        $username = isset($_POST['username']) ? sanitize_text_field($_POST['username']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : ''; // Don't sanitize password
        $customer_code = isset($_POST['customer_code']) ? sanitize_text_field($_POST['customer_code']) : '';
        
        if (empty($username) || empty($password)) {
            wp_send_json_error(array('message' => __('Username and password are required', 'bytemash-woo-sync')));
        }
        
        // Attempt authentication
        $api_client = new ByteMash_Amrod_API_Client();
        $result = $api_client->authenticate($username, $password, $customer_code);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
        }
        
        wp_send_json_success(array(
            'message' => __('Authentication successful! Credentials stored for automatic token refresh.', 'bytemash-woo-sync')
        ));
    }
    
    /**
     * AJAX: Save API URL
     */
    public function ajax_save_api_url() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Get API URL
        $api_url = isset($_POST['api_url']) ? esc_url_raw($_POST['api_url']) : '';
        
        if (empty($api_url)) {
            wp_send_json_error(array('message' => __('API URL is required', 'bytemash-woo-sync')));
        }
        
        // Save API URL
        update_option('bytemash_amrod_api_url', $api_url);
        
        wp_send_json_success(array(
            'message' => __('API URL saved', 'bytemash-woo-sync')
        ));
    }
    
    /**
     * AJAX: Test API connection
     */
    public function ajax_test_connection() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Test connection
        $api_client = new ByteMash_Amrod_API_Client();
        $is_connected = $api_client->test_connection();
        
        if ($is_connected) {
            wp_send_json_success(array(
                'message' => __('Connection successful!', 'bytemash-woo-sync')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Connection failed. Please check your credentials.', 'bytemash-woo-sync')
            ));
        }
    }
    
    /**
     * AJAX: Clear logs
     */
    public function ajax_clear_logs() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Clear logs from database
        global $wpdb;
        $table_name = $wpdb->prefix . 'bytemash_sync_logs';
        $wpdb->query("TRUNCATE TABLE $table_name");
        
        // Clear log files
        $log_dir = BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'logs/';
        if (is_dir($log_dir)) {
            $files = glob($log_dir . '*.log');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        
        wp_send_json_success(array(
            'message' => __('Logs cleared successfully', 'bytemash-woo-sync')
        ));
    }
    
    /**
     * AJAX: Get sync progress
     */
    public function ajax_get_sync_progress() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Get active syncs from batch processor
        $batch_processor = new ByteMash_Batch_Processor();
        $syncs = $batch_processor->get_active_syncs();
        
        // Add real-time details for each sync
        foreach ($syncs as $sync_id => &$sync) {
            // Calculate percentage
            if ($sync['total'] > 0) {
                $sync['percentage'] = round(($sync['processed'] / $sync['total']) * 100, 1);
            } else {
                $sync['percentage'] = 0;
            }
            
            // Add chunk/batch info
            if (isset($sync['chunk_count'])) {
                $sync['progress_text'] = sprintf(
                    'Chunk %d/%d - %d/%d products',
                    $sync['current_chunk'],
                    $sync['chunk_count'],
                    $sync['processed'],
                    $sync['total']
                );
            } elseif (isset($sync['batch_count'])) {
                $sync['progress_text'] = sprintf(
                    'Batch %d/%d - %d/%d items',
                    $sync['current_batch'],
                    $sync['batch_count'],
                    $sync['processed'],
                    $sync['total']
                );
            } else {
                $sync['progress_text'] = sprintf(
                    '%d/%d processed',
                    $sync['processed'],
                    $sync['total']
                );
            }
            
            // Time elapsed
            if (isset($sync['started'])) {
                $started = strtotime($sync['started']);
                $elapsed = time() - $started;
                $sync['elapsed'] = $elapsed;
                $sync['elapsed_text'] = sprintf('%dm %ds', floor($elapsed / 60), $elapsed % 60);
            }
            
            // Estimated time remaining
            if ($sync['processed'] > 0 && isset($elapsed)) {
                $rate = $sync['processed'] / $elapsed; // products per second
                $remaining = $sync['total'] - $sync['processed'];
                $eta = $remaining / $rate;
                $sync['eta'] = round($eta);
                $sync['eta_text'] = sprintf('%dm %ds', floor($eta / 60), $eta % 60);
            }
        }
        
        wp_send_json_success(array(
            'syncs' => $syncs,
            'timestamp' => current_time('mysql')
        ));
    }
    
    /**
     * AJAX: Stop active sync
     */
    public function ajax_stop_sync() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Get sync ID if provided
        $sync_id = isset($_POST['sync_id']) ? sanitize_text_field($_POST['sync_id']) : '';
        
        $batch_processor = new ByteMash_Batch_Processor();
        $logger = new ByteMash_Logger();
        
        if (!empty($sync_id)) {
            // Stop specific sync
            $sync_info = get_option("bytemash_sync_{$sync_id}");
            
            if ($sync_info) {
                $sync_info['status'] = 'stopped';
                $sync_info['message'] = 'Sync stopped by user';
                $sync_info['completed'] = current_time('mysql');
                
                update_option("bytemash_sync_{$sync_id}", $sync_info, false);
                
                // Clear sync running transient
                delete_transient('bytemash_sync_running');
                
                  // Clean up queue table
                global $wpdb;
                $table_name = $wpdb->prefix . 'bytemash_sync_queue';
                $wpdb->delete($table_name, array('sync_id' => $sync_id));
                
                $logger->log('warning', 'Sync stopped by user', array(
                    'sync_id' => $sync_id,
                    'processed' => $sync_info['processed'] ?? 0,
                    'total' => $sync_info['total'] ?? 0,
                ), 'manual_sync');
                
                wp_send_json_success(array(
                    'message' => sprintf(__('Sync stopped. %d/%d products were synced.', 'bytemash-woo-sync'), 
                        $sync_info['processed'] ?? 0, 
                        $sync_info['total'] ?? 0
                    )
                ));
            } else {
                wp_send_json_error(array('message' => __('Sync not found', 'bytemash-woo-sync')));
            }
        } else {
            // Stop all active syncs
            $syncs = $batch_processor->get_active_syncs();
            $stopped = 0;
            
            foreach ($syncs as $sync_id => $sync) {
                if (in_array($sync['status'], ['scheduled', 'processing'])) {
                    $sync['status'] = 'cancelled';
                    $sync['message'] = 'Sync cancelled by user';
                    $sync['completed'] = current_time('mysql');
                    
                    update_option("bytemash_sync_progress_{$sync_id}", $sync, false);
                    
                    // Clear scheduled events
                    wp_clear_scheduled_hook('bytemash_process_products_chunk', array($sync_id, $sync['current_chunk'] ?? 0));
                    wp_clear_scheduled_hook('bytemash_process_products_batch', array($sync_id, $sync['current_batch'] ?? 0));
                    
                    // Clean up transients
                    if (isset($sync['chunk_count'])) {
                        for ($i = $sync['current_chunk']; $i < $sync['chunk_count']; $i++) {
                            delete_transient("bytemash_sync_{$sync_id}_chunk_{$i}");
                        }
                    }
                    delete_transient("bytemash_sync_{$sync_id}_meta");
                    
                    $stopped++;
                }
            }
            
            $logger->log('warning', 'All syncs cancelled by user', array('stopped' => $stopped), 'manual_sync');
            
            wp_send_json_success(array(
                'message' => sprintf(_n('%d sync stopped.', '%d syncs stopped.', $stopped, 'bytemash-woo-sync'), $stopped)
            ));
        }
    }
    
    /**
     * AJAX: Manual sync (trigger product sync)
     */
    public function ajax_manual_sync() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Log the manual sync trigger
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Manual sync triggered', array('user' => get_current_user_id()), 'manual_sync');
        
        // Clear any stale sync status
        delete_transient('bytemash_sync_running');
        
        // Trigger product sync
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_all_products(false, true); // force=false, with_branding=true
        
        if ($result['success']) {
            // Store batches in queue table
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            $logger->log('info', 'Batches stored in queue table', array(
                'sync_id' => $result['sync_id'],
                'batch_count' => $result['batch_count'],
            ), 'manual_sync');
            
            // Return minimal response
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message']
            ));
        }
    }
    
    /**
     * AJAX: Sync stock levels
     */
    public function ajax_sync_stock() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Log the sync trigger
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Stock sync triggered', array('user' => get_current_user_id()), 'stock_sync');
        
        // Clear any stale sync status
        delete_transient('bytemash_sync_running');
        
        // Trigger stock sync
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_stock_levels();
        
        if ($result['success']) {
            // Store batches in queue table
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message']
            ));
        }
    }
    
    /**
     * AJAX: Sync prices
     */
    public function ajax_sync_prices() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Log the sync trigger
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Price sync triggered', array('user' => get_current_user_id()), 'price_sync');
        
        // Clear any stale sync status
        delete_transient('bytemash_sync_running');
        
        // Trigger price sync
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_prices();
        
        if ($result['success']) {
            // Store batches in queue table
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message']
            ));
        }
    }
    
    /**
     * AJAX: Sync updated products (incremental)
     */
    public function ajax_sync_products_incremental() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Incremental product sync triggered', array('user' => get_current_user_id()), 'product_sync');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_updated_products(true);
        
        if ($result['success'] && isset($result['batches'])) {
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Sync stock incremental
     */
    public function ajax_sync_stock_incremental() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Incremental stock sync triggered', array('user' => get_current_user_id()), 'stock_sync');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_stock_updated();
        
        if ($result['success'] && isset($result['batches'])) {
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Sync prices incremental
     */
    public function ajax_sync_prices_incremental() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Incremental price sync triggered', array('user' => get_current_user_id()), 'price_sync');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_prices_updated();
        
        if ($result['success'] && isset($result['batches'])) {
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Sync orphan prices (products without prices)
     */
    public function ajax_sync_orphan_prices() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Orphan price sync triggered', array('user' => get_current_user_id()), 'price_sync_orphan');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_orphan_product_prices();
        
        if ($result['success'] && isset($result['batches'])) {
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Sync categories
     */
    public function ajax_sync_categories() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Categories sync triggered', array('user' => get_current_user_id()), 'category_sync');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_categories();
        
        if ($result['success'] && isset($result['batches'])) {
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Sync color swatches
     */
    public function ajax_sync_color_swatches() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Color swatches sync triggered', array('user' => get_current_user_id()), 'color_swatches_sync');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_color_swatches();
        
        if ($result['success'] && isset($result['batches'])) {
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Sync brands
     */
    public function ajax_sync_brands() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Brands sync triggered', array('user' => get_current_user_id()), 'brands_sync');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_brands();
        
        if ($result['success'] && isset($result['batches'])) {
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Sync branding departments
     */
    public function ajax_sync_branding_departments() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Branding departments sync triggered', array('user' => get_current_user_id()), 'branding_sync');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_branding_departments();
        
        if ($result['success'] && isset($result['batches'])) {
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Sync branding prices
     */
    public function ajax_sync_branding_prices() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Branding prices sync triggered', array('user' => get_current_user_id()), 'branding_sync');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_branding_prices();
        
        if ($result['success'] && isset($result['batches'])) {
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Sync inclusive brandings
     */
    public function ajax_sync_inclusive_brandings() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Inclusive brandings sync triggered', array('user' => get_current_user_id()), 'branding_sync');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_inclusive_brandings();
        
        if ($result['success'] && isset($result['batches'])) {
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }
    
    /**
     * Helper: Store batches in queue table
     */
    private function store_batches_in_queue($sync_id, $batches) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bytemash_sync_queue';
        
        // Ensure we have enough memory for this operation
        $current_limit = ini_get('memory_limit');
        $current_bytes = wp_convert_hr_to_bytes($current_limit);
        $desired_bytes = 512 * 1024 * 1024; // 512MB
        
        if ($current_bytes < $desired_bytes) {
            @ini_set('memory_limit', '512M');
        }
        
        // Create table if not exists
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            sync_id VARCHAR(100),
            batch_index INT,
            batch_data LONGTEXT,
            status VARCHAR(20) DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX(sync_id),
            INDEX(status)
        ) {$wpdb->get_charset_collate()}");
        
        // Insert each batch as a separate row
        foreach ($batches as $index => $batch) {
            $wpdb->insert($table_name, array(
                'sync_id' => $sync_id,
                'batch_index' => $index,
                'batch_data' => json_encode($batch),
                'status' => 'pending'
            ));
            
            // Free memory after every 10 batches to prevent buildup
            if ($index % 10 === 0) {
                wp_cache_flush();
                gc_collect_cycles();
            }
        }
    }
    
    /**
     * AJAX: Process next pending batch from queue
     */
    public function ajax_process_batch() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Get parameters
        $sync_id = isset($_POST['sync_id']) ? sanitize_text_field($_POST['sync_id']) : '';
        
        if (empty($sync_id)) {
            wp_send_json_error(array('message' => __('Sync ID required', 'bytemash-woo-sync')));
        }
        
        // Get sync info
        $sync_info = get_option("bytemash_sync_{$sync_id}");
        
        if (!$sync_info) {
            wp_send_json_error(array('message' => __('Sync not found', 'bytemash-woo-sync')));
        }
        
        // Check if stopped
        if ($sync_info['status'] === 'stopped') {
            wp_send_json_success(array(
                'message' => 'Sync stopped',
                'stopped' => true
            ));
            return;
        }
        
        // Get next pending batch from queue
        global $wpdb;
        $table_name = $wpdb->prefix . 'bytemash_sync_queue';
        
        $batch_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE sync_id = %s AND status = 'pending' ORDER BY batch_index ASC LIMIT 1",
            $sync_id
        ));
        
        if (!$batch_row) {
            // No more batches - mark sync as complete
            $sync_info['status'] = 'completed';
            $sync_info['completed'] = current_time('mysql');
            update_option("bytemash_sync_{$sync_id}", $sync_info, false);
            delete_transient('bytemash_sync_running');
            
            // Clean up queue table
            $wpdb->delete($table_name, array('sync_id' => $sync_id));
            
            wp_send_json_success(array(
                'done' => true,
                'message' => 'All batches completed'
            ));
            return;
        }
        
        // Decode batch data
        $batch_data = json_decode($batch_row->batch_data, true);
        $batch_index = $batch_row->batch_index;
        
        // Mark batch as processing
        $wpdb->update($table_name, 
            array('status' => 'processing'),
            array('id' => $batch_row->id)
        );
        
        // Process this batch based on sync type
        $product_sync = new ByteMash_Product_Sync();
        $processed = 0;
        $errors = 0;
        
        $sync_type = $sync_info['type'] ?? 'products';
        
        foreach ($batch_data as $item_data) {
            if ($sync_type === 'stock') {
                $result = $product_sync->update_single_stock($item_data);
            } elseif ($sync_type === 'prices') {
                $result = $product_sync->update_single_price($item_data);
            } elseif ($sync_type === 'orphan_prices') {
                $prices_lookup = get_option("bytemash_sync_{$sync_id}_prices_lookup");
                $result = $product_sync->update_single_orphan_product($item_data, $prices_lookup);
            } elseif ($sync_type === 'categories') {
                $result = $product_sync->sync_single_category($item_data);
            } elseif ($sync_type === 'brands') {
                $result = $product_sync->sync_single_brand($item_data);
            } elseif ($sync_type === 'branding_departments') {
                $result = $product_sync->sync_single_branding_department($item_data);
            } elseif ($sync_type === 'branding_prices') {
                $result = $product_sync->sync_single_branding_price($item_data);
            } elseif ($sync_type === 'inclusive_brandings') {
                $result = $product_sync->sync_single_inclusive_branding($item_data);
            } elseif ($sync_type === 'color_swatches') {
                $result = $product_sync->sync_single_color_swatch($item_data);
            } else {
                // Default: product sync
                $result = $product_sync->sync_single_product($item_data);
            }
            
            if ($result['success']) {
                $processed++;
            } else {
                $errors++;
            }
        }
        
        // Mark batch as complete
        $wpdb->update($table_name,
            array('status' => 'completed'),
            array('id' => $batch_row->id)
        );
        
        // Update sync info
        $sync_info['current_batch'] = $batch_index + 1;
        $sync_info['processed'] += $processed;
        $sync_info['errors'] += $errors;
        $sync_info['status'] = 'processing';
        
        update_option("bytemash_sync_{$sync_id}", $sync_info, false);
        
        // Get current WooCommerce product counts for dashboard update
        $product_counts = wp_count_posts('product');
        
        wp_send_json_success(array(
            'batch' => $batch_index,
            'processed' => $processed,
            'errors' => $errors,
            'skipped' => 0, // Not tracking skipped in current version
            'total_processed' => $sync_info['processed'],
            'total_errors' => $sync_info['errors'],
            'total_skipped' => 0, // Not tracking skipped in current version
            'total_products' => $sync_info['total'],
            'woo_product_count' => $product_counts->publish,
            'done' => false
        ));
    }
    
    /**
     * AJAX: Get a single batch from transient
     */
    public function ajax_get_batch() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $sync_id = isset($_POST['sync_id']) ? sanitize_text_field($_POST['sync_id']) : '';
        $batch_index = isset($_POST['batch_index']) ? (int) $_POST['batch_index'] : 0;
        
        if (empty($sync_id)) {
            wp_send_json_error(array('message' => __('Sync ID required', 'bytemash-woo-sync')));
        }
        
        // Get batches from transient
        $batches = get_transient("bytemash_sync_{$sync_id}_batches");
        
        if (!$batches || !isset($batches[$batch_index])) {
            wp_send_json_error(array('message' => __('Batch not found', 'bytemash-woo-sync')));
        }
        
        wp_send_json_success(array(
            'batch' => $batches[$batch_index]
        ));
    }
}

/**
 * Initialize the plugin
 */
function bytemash_woo_sync_init() {
    return ByteMash_Woo_Sync::get_instance();
}

// Start the plugin
bytemash_woo_sync_init();

