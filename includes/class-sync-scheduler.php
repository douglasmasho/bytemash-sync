<?php
/**
 * Sync Scheduler Class
 * 
 * Handles scheduled syncs using WordPress cron
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_Sync_Scheduler {
    
    /**
     * Logger
     */
    private $logger;
    
    /**
     * Product Sync
     */
    private $product_sync;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new ByteMash_Logger();
        $this->product_sync = new ByteMash_Product_Sync();
        
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Register cron schedules
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        
        // Hook into cron event
        add_action('bytemash_amrod_sync_cron', array($this, 'run_scheduled_sync'));
        
        // AJAX handlers for manual sync
        add_action('wp_ajax_bytemash_save_api_url', array($this, 'ajax_save_api_url'));
        add_action('wp_ajax_bytemash_authenticate', array($this, 'ajax_authenticate'));
        add_action('wp_ajax_bytemash_manual_sync', array($this, 'ajax_manual_sync'));
        add_action('wp_ajax_bytemash_sync_products_incremental', array($this, 'ajax_sync_products_incremental'));
        add_action('wp_ajax_bytemash_stock_sync', array($this, 'ajax_stock_sync'));
        add_action('wp_ajax_bytemash_stock_sync_incremental', array($this, 'ajax_stock_sync_incremental'));
        add_action('wp_ajax_bytemash_price_sync', array($this, 'ajax_price_sync'));
        add_action('wp_ajax_bytemash_price_sync_incremental', array($this, 'ajax_price_sync_incremental'));
        add_action('wp_ajax_bytemash_category_sync', array($this, 'ajax_category_sync'));
        add_action('wp_ajax_bytemash_get_sync_progress', array($this, 'ajax_get_sync_progress'));
        add_action('wp_ajax_bytemash_test_connection', array($this, 'ajax_test_connection'));
    }
    
    /**
     * AJAX: Save API URL
     */
    public function ajax_save_api_url() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $api_url = isset($_POST['api_url']) ? esc_url_raw($_POST['api_url']) : '';
        
        if (empty($api_url)) {
            wp_send_json_error(array('message' => 'API URL is required'));
        }
        
        update_option('bytemash_amrod_api_url', $api_url);
        
        wp_send_json_success(array('message' => 'API URL saved'));
    }
    
    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules($schedules) {
        $schedules['every_6_hours'] = array(
            'interval' => 6 * HOUR_IN_SECONDS,
            'display' => __('Every 6 Hours', 'bytemash-woo-sync'),
        );
        
        $schedules['every_12_hours'] = array(
            'interval' => 12 * HOUR_IN_SECONDS,
            'display' => __('Every 12 Hours', 'bytemash-woo-sync'),
        );
        
        return $schedules;
    }
    
    /**
     * Run scheduled sync
     */
    public function run_scheduled_sync() {
        $this->logger->log('info', 'Running scheduled sync', array(), 'scheduled_sync');
        
        // Check if sync is already running
        if (get_transient('bytemash_sync_running')) {
            $this->logger->log('warning', 'Sync already running, skipping', array(), 'scheduled_sync');
            return;
        }
        
        // Set sync running flag
        set_transient('bytemash_sync_running', true, 3600);
        
        try {
            // Run full product sync
            $result = $this->product_sync->sync_all_products();
            
            // Also sync stock levels
            $this->product_sync->sync_stock_levels();
            
            $this->logger->log('success', 'Scheduled sync completed', array(
                'result' => $result,
            ), 'scheduled_sync');
            
        } catch (Exception $e) {
            $this->logger->log('error', 'Scheduled sync failed', array(
                'error' => $e->getMessage(),
            ), 'scheduled_sync');
        }
        
        // Clear sync running flag
        delete_transient('bytemash_sync_running');
        
        // Clean old logs
        $this->logger->clear_old_logs(30);
    }
    
    /**
     * Update sync schedule
     */
    public function update_schedule($frequency) {
        // Clear existing schedule
        $timestamp = wp_next_scheduled('bytemash_amrod_sync_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'bytemash_amrod_sync_cron');
        }
        
        // Schedule new event
        if ($frequency && $frequency !== 'manual') {
            wp_schedule_event(time(), $frequency, 'bytemash_amrod_sync_cron');
            $this->logger->log('info', "Sync schedule updated to: {$frequency}", array(), 'scheduler');
        }
    }
    
    /**
     * AJAX: Manual sync
     */
    public function ajax_manual_sync() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $this->logger->log('info', 'Manual sync triggered', array('user' => get_current_user_id()), 'manual_sync');
        
        // Check if sync is already running
        if (get_transient('bytemash_sync_running')) {
            wp_send_json_error(array('message' => 'Sync is already running. Please wait.'));
        }
        
        // Set sync running flag
        set_transient('bytemash_sync_running', true, 3600);
        
        try {
            $result = $this->product_sync->sync_all_products(true);
            
            delete_transient('bytemash_sync_running');
            
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
            
        } catch (Exception $e) {
            delete_transient('bytemash_sync_running');
            
            wp_send_json_error(array(
                'message' => $e->getMessage(),
            ));
        }
    }
    
    /**
     * AJAX: Sync products incrementally (updated only)
     */
    public function ajax_sync_products_incremental() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        try {
            $result = $this->product_sync->sync_updated_products(true);
            
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Stock sync (full)
     */
    public function ajax_stock_sync() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $this->logger->log('info', 'Manual stock sync triggered', array('user' => get_current_user_id()), 'stock_sync');
        
        try {
            $result = $this->product_sync->sync_stock_levels();
            
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Stock sync incremental
     */
    public function ajax_stock_sync_incremental() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        try {
            $result = $this->product_sync->sync_stock_updated();
            
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Price sync (full)
     */
    public function ajax_price_sync() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        try {
            $result = $this->product_sync->sync_prices();
            
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Price sync incremental
     */
    public function ajax_price_sync_incremental() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        try {
            $result = $this->product_sync->sync_prices_updated();
            
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Category sync
     */
    public function ajax_category_sync() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        try {
            $result = $this->product_sync->sync_categories();
            
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Get sync progress
     */
    public function ajax_get_sync_progress() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $batch_processor = new ByteMash_Batch_Processor();
        $active_syncs = $batch_processor->get_active_syncs();
        
        wp_send_json_success(array(
            'syncs' => $active_syncs,
        ));
    }
    
    /**
     * AJAX: Authenticate with Amrod
     */
    public function ajax_authenticate() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $username = isset($_POST['username']) ? sanitize_text_field($_POST['username']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $customer_code = isset($_POST['customer_code']) ? sanitize_text_field($_POST['customer_code']) : '';
        
        if (empty($username) || empty($password)) {
            wp_send_json_error(array('message' => 'Username and password are required'));
        }
        
        $api_client = new ByteMash_Amrod_API_Client();
        $result = $api_client->authenticate($username, $password, $customer_code);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Get token from result
        $token = '';
        if (isset($result['token'])) {
            $token = $result['token'];
        } elseif (isset($result['access_token'])) {
            $token = $result['access_token'];
        } elseif (isset($result['Token'])) {
            $token = $result['Token'];
        }
        
        wp_send_json_success(array(
            'message' => 'Authentication successful!',
            'token' => !empty($token) ? substr($token, 0, 20) . '...' : '',
        ));
    }
    
    /**
     * AJAX: Test API connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $api_client = new ByteMash_Amrod_API_Client();
        
        if ($api_client->test_connection()) {
            wp_send_json_success(array(
                'message' => 'Connection successful!',
            ));
        } else {
            wp_send_json_error(array(
                'message' => 'Connection failed. Please check your API credentials.',
            ));
        }
    }
    
    /**
     * Get next scheduled sync time
     */
    public function get_next_sync_time() {
        $timestamp = wp_next_scheduled('bytemash_amrod_sync_cron');
        
        if (!$timestamp) {
            return __('Not scheduled', 'bytemash-woo-sync');
        }
        
        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }
}

