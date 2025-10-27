<?php
/**
 * Sync Scheduler Class
 * 
 * Handles scheduled syncs using WordPress cron with proper incremental updates
 * according to Amrod API documentation
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_Sync_Scheduler {
    
    /**
     * Single instance of the class
     */
    private static $instance = null;
    
    /**
     * Logger
     */
    private $logger;
    
    /**
     * Product Sync
     */
    private $product_sync;
    
    /**
     * Action Scheduler instance
     */
    private $action_scheduler;
    
    /**
     * Whether to use Action Scheduler (always true now)
     */
    private $use_action_scheduler = true;
    
    /**
     * Whether hooks have been initialized
     */
    private $hooks_initialized = false;
    
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
        $this->logger = new ByteMash_Logger();
        $this->product_sync = new ByteMash_Product_Sync();
        
        $this->init_hooks();
        $this->init_action_scheduler();
    }
    
    /**
     * Initialize Action Scheduler integration
     */
    private function init_action_scheduler() {
        // Always use Action Scheduler - WordPress cron is unreliable
        if (class_exists('ByteMash_Action_Scheduler_Sync')) {
            $this->action_scheduler = new ByteMash_Action_Scheduler_Sync();
            
            if ($this->action_scheduler->is_action_scheduler_available()) {
                $this->logger->log('info', 'Action Scheduler integration enabled', array(), 'sync_scheduler');
            } else {
                $this->logger->log('error', 'Action Scheduler not available - automatic syncs will not work', array(), 'sync_scheduler');
            }
        } else {
            $this->logger->log('error', 'Action Scheduler class not found - automatic syncs will not work', array(), 'sync_scheduler');
        }
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Prevent duplicate hook registrations
        if ($this->hooks_initialized) {
            return;
        }
        
        // No WordPress cron hooks - we only use Action Scheduler
        
        // AJAX handlers for manual sync
        add_action('wp_ajax_bytemash_save_api_url', array($this, 'ajax_save_api_url'));
        add_action('wp_ajax_bytemash_authenticate', array($this, 'ajax_authenticate'));
        add_action('wp_ajax_bytemash_manual_sync', array($this, 'ajax_manual_sync'));
        add_action('wp_ajax_bytemash_sync_all', array($this, 'ajax_sync_all'));
        add_action('wp_ajax_bytemash_sync_products_incremental', array($this, 'ajax_sync_products_incremental'));
        add_action('wp_ajax_bytemash_stock_sync', array($this, 'ajax_stock_sync'));
        add_action('wp_ajax_bytemash_stock_sync_incremental', array($this, 'ajax_stock_sync_incremental'));
        add_action('wp_ajax_bytemash_price_sync', array($this, 'ajax_price_sync'));
        add_action('wp_ajax_bytemash_price_sync_incremental', array($this, 'ajax_price_sync_incremental'));
        add_action('wp_ajax_bytemash_category_sync', array($this, 'ajax_category_sync'));
        add_action('wp_ajax_bytemash_get_sync_progress', array($this, 'ajax_get_sync_progress'));
        add_action('wp_ajax_bytemash_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_bytemash_update_sync_schedule', array($this, 'ajax_update_sync_schedule'));
        
        // Action Scheduler test mode endpoints
        add_action('wp_ajax_bytemash_enable_test_mode_full_sync', array($this, 'ajax_enable_test_mode_full_sync'));
        add_action('wp_ajax_bytemash_enable_test_mode_incremental_sync', array($this, 'ajax_enable_test_mode_incremental_sync'));
        add_action('wp_ajax_bytemash_enable_production_sync', array($this, 'ajax_enable_production_sync'));
        
        add_action('wp_ajax_bytemash_disable_test_mode', array($this, 'ajax_disable_test_mode'));
        add_action('wp_ajax_bytemash_get_test_mode_status', array($this, 'ajax_get_test_mode_status'));
        
        // Action Scheduler monitoring endpoints
        add_action('wp_ajax_bytemash_get_sync_status_progress', array($this, 'ajax_get_sync_status_progress'));
        add_action('wp_ajax_bytemash_get_scheduled_times', array($this, 'ajax_get_scheduled_times'));
        add_action('wp_ajax_bytemash_get_batch_progress', array($this, 'ajax_get_batch_progress'));
        
        // Mark hooks as initialized
        $this->hooks_initialized = true;
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
    
    // WordPress cron schedules removed - using Action Scheduler only
    
    /**
     * Run full sync (daily at 00:30 GMT+2)
     * According to API docs: Full stock list is cleared and repopulated at 00:30 GMT+2
     */
    public function run_full_sync() {
        $this->logger->log('info', 'Running full sync (daily reset)', array(), 'full_sync');
        
        // Check if sync is already running
        if (get_transient('bytemash_full_sync_running')) {
            // Check if the sync has been running too long (safety mechanism)
            $sync_start_time = get_option('bytemash_full_sync_start_time', 0);
            $max_runtime = 7200; // 2 hours in seconds
            
            if (time() - $sync_start_time > $max_runtime) {
                $this->logger->log('warning', 'Full sync has been running too long, forcing cleanup', array(
                    'runtime' => time() - $sync_start_time,
                ), 'full_sync');
                
                // Force clear the stuck sync
                delete_transient('bytemash_full_sync_running');
                delete_option('bytemash_full_sync_start_time');
            } else {
                $this->logger->log('warning', 'Full sync already running, skipping', array(), 'full_sync');
                return;
            }
        }
        
        // Set sync running flag
        set_transient('bytemash_full_sync_running', true, 7200); // 2 hours timeout
        update_option('bytemash_full_sync_start_time', time()); // Track start time
        
        try {
            // Get enabled sync attributes
            $sync_products = get_option('bytemash_sync_products', true);
            $sync_stock = get_option('bytemash_sync_stock', true);
            $sync_prices = get_option('bytemash_sync_prices', true);
            $sync_categories = get_option('bytemash_sync_categories', true);
            $sync_brands = get_option('bytemash_sync_brands', true);
            
            $results = array();
            
            // Run full sync for enabled endpoints in sequence (queue-like behavior)
            if ($sync_products) {
                $this->logger->log('info', 'Starting full product sync', array(), 'full_sync');
                $results['products'] = $this->sync_products_for_cron(true);
            }
            
            if ($sync_stock) {
                $this->logger->log('info', 'Starting full stock sync', array(), 'full_sync');
                $results['stock'] = $this->product_sync->sync_stock_levels();
            }
            
            if ($sync_prices) {
                $this->logger->log('info', 'Starting full price sync', array(), 'full_sync');
                $results['prices'] = $this->product_sync->sync_prices();
            }
            
            if ($sync_categories) {
                $this->logger->log('info', 'Starting full category sync', array(), 'full_sync');
                $results['categories'] = $this->product_sync->sync_categories();
            }
            
            if ($sync_brands) {
                $this->logger->log('info', 'Starting full brand sync', array(), 'full_sync');
                $results['brands'] = $this->product_sync->sync_brands();
            }
            
            // Store full sync completion timestamp
            update_option('bytemash_last_full_sync', current_time('mysql'));
            
            $this->logger->log('success', 'Full sync completed', array(
                'results' => $results,
                'enabled_attributes' => array(
                    'products' => $sync_products,
                    'stock' => $sync_stock,
                    'prices' => $sync_prices,
                    'categories' => $sync_categories,
                    'brands' => $sync_brands,
                )
            ), 'full_sync');
            
        } catch (Exception $e) {
            $this->logger->log('error', 'Full sync failed', array(
                'error' => $e->getMessage(),
            ), 'full_sync');
        }
        
        // Clear sync running flag
        delete_transient('bytemash_full_sync_running');
        delete_option('bytemash_full_sync_start_time');
        
        // Clean old logs
        $this->logger->clear_old_logs(30);
    }
    
    /**
     * Sync products for cron (processes directly without JavaScript)
     */
    private function sync_products_for_cron($with_branding = true) {
        $this->logger->log('info', 'Starting cron-based product sync', array(
            'with_branding' => $with_branding,
        ), 'cron_sync');
        
        // Fetch products from Amrod API
        try {
            $api_client = new ByteMash_Amrod_API_Client();
            if ($with_branding) {
                $products = $api_client->get_products_with_branding();
            } else {
                $products = $api_client->get_products_without_branding();
            }
            
            if (is_wp_error($products)) {
                $this->logger->log('error', 'Failed to fetch products for cron sync', array(
                    'error' => $products->get_error_message(),
                    'with_branding' => $with_branding,
                ), 'cron_sync');
                return array('success' => false, 'message' => $products->get_error_message());
            }
        } catch (Exception $e) {
            $this->logger->log('error', 'API client exception during cron sync', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'with_branding' => $with_branding,
            ), 'cron_sync');
            return array('success' => false, 'message' => 'API client error: ' . $e->getMessage());
        }
        
        if (!is_array($products) || empty($products)) {
            $this->logger->log('warning', 'No products found for cron sync', array(), 'cron_sync');
            return array('success' => false, 'message' => 'No products found');
        }
        
        $total = count($products);
        $batch_size = (int) get_option('bytemash_amrod_batch_size', 10);
        $batches = array_chunk($products, $batch_size);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Processing {$total} products in {$batch_count} batches for cron", array(), 'cron_sync');
        
        $processed = 0;
        $errors = 0;
        $skipped = 0;
        
        // Process each batch directly
        foreach ($batches as $batch_index => $batch) {
            $this->logger->log('info', "Processing batch " . ($batch_index + 1) . "/{$batch_count}", array(), 'cron_sync');
            
            foreach ($batch as $product_data) {
                try {
                    $result = $this->product_sync->sync_single_product($product_data, false);
                    if ($result['success']) {
                        $processed++;
                        $this->logger->log('info', 'Product synced successfully in cron', array(
                            'sku' => $product_data['fullCode'] ?? 'unknown',
                            'product_name' => $product_data['productName'] ?? 'unknown',
                        ), 'cron_sync');
                    } else {
                        $skipped++;
                        $this->logger->log('warning', 'Product sync skipped in cron', array(
                            'sku' => $product_data['fullCode'] ?? 'unknown',
                            'product_name' => $product_data['productName'] ?? 'unknown',
                            'reason' => $result['message'] ?? 'Unknown reason',
                        ), 'cron_sync');
                    }
                } catch (Exception $e) {
                    $errors++;
                    $this->logger->log('error', 'Product sync failed in cron', array(
                        'sku' => $product_data['fullCode'] ?? 'unknown',
                        'product_name' => $product_data['productName'] ?? 'unknown',
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ), 'cron_sync');
                }
            }
            
            // Clear memory after each batch
            unset($batch);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        $this->logger->log('success', 'Cron product sync completed', array(), 'cron_sync');
        
        return array(
            'success' => true,
            'message' => "Processed {$processed} products, {$errors} errors, {$skipped} skipped",
            'processed' => $processed,
            'errors' => $errors,
            'skipped' => $skipped,
            'total' => $total,
        );
    }
    
    /**
     * Sync updated products for cron (processes directly without JavaScript)
     */
    private function sync_updated_products_for_cron($with_branding = true) {
        $this->logger->log('info', 'Starting cron-based incremental product sync', array(), 'cron_sync');
        
        // Fetch updated products from Amrod API
        $api_client = new ByteMash_Amrod_API_Client();
        if ($with_branding) {
            $products = $api_client->get_products_with_branding_updated();
        } else {
            $products = $api_client->get_products_without_branding_updated();
        }
        
        if (is_wp_error($products)) {
            $this->logger->log('error', 'Failed to fetch updated products for cron sync', array(
                'error' => $products->get_error_message(),
            ), 'cron_sync');
            return array('success' => false, 'message' => $products->get_error_message());
        }
        
        if (!is_array($products) || empty($products)) {
            $this->logger->log('info', 'No updated products found for cron sync', array(), 'cron_sync');
            return array('success' => true, 'message' => 'No updates available', 'total' => 0);
        }
        
        $total = count($products);
        $batch_size = (int) get_option('bytemash_amrod_batch_size', 10);
        $batches = array_chunk($products, $batch_size);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Processing {$total} updated products in {$batch_count} batches for cron", array(), 'cron_sync');
        
        $processed = 0;
        $errors = 0;
        $skipped = 0;
        
        // Process each batch directly
        foreach ($batches as $batch_index => $batch) {
            $this->logger->log('info', "Processing updated batch " . ($batch_index + 1) . "/{$batch_count}", array(), 'cron_sync');
            
            foreach ($batch as $product_data) {
                try {
                    $result = $this->product_sync->sync_single_product($product_data, false);
                    if ($result['success']) {
                        $processed++;
                    } else {
                        $skipped++;
                        $this->logger->log('warning', 'Updated product sync skipped', array(), 'cron_sync');
                    }
                } catch (Exception $e) {
                    $errors++;
                    $this->logger->log('error', 'Updated product sync failed', array(), 'cron_sync');
                }
            }
            
            // Clear memory after each batch
            unset($batch);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        $this->logger->log('success', 'Cron incremental product sync completed', array(), 'cron_sync');
        
        return array(
            'success' => true,
            'message' => "Processed {$processed} updated products, {$errors} errors, {$skipped} skipped",
            'processed' => $processed,
            'errors' => $errors,
            'skipped' => $skipped,
            'total' => $total,
        );
    }
    
    /**
     * Run incremental sync (every 5 hours by default)
     * Only runs if full sync has been completed
     */
    public function run_incremental_sync() {
        $this->logger->log('info', 'Running incremental sync', array(), 'incremental_sync');
        
        // Check if full sync has been completed
        $last_full_sync = get_option('bytemash_last_full_sync');
        if (!$last_full_sync) {
            $this->logger->log('warning', 'Incremental sync skipped: No full sync completed yet', array(), 'incremental_sync');
            return;
        }
        
        // Check if sync is already running
        if (get_transient('bytemash_incremental_sync_running')) {
            // Check if the sync has been running too long (safety mechanism)
            $sync_start_time = get_option('bytemash_incremental_sync_start_time', 0);
            $max_runtime = 3600; // 1 hour in seconds
            
            if (time() - $sync_start_time > $max_runtime) {
                $this->logger->log('warning', 'Incremental sync has been running too long, forcing cleanup', array(
                    'runtime' => time() - $sync_start_time,
                ), 'incremental_sync');
                
                // Force clear the stuck sync
                delete_transient('bytemash_incremental_sync_running');
                delete_option('bytemash_incremental_sync_start_time');
            } else {
                $this->logger->log('warning', 'Incremental sync already running, skipping', array(), 'incremental_sync');
                return;
            }
        }
        
        // Set sync running flag
        set_transient('bytemash_incremental_sync_running', true, 3600); // 1 hour timeout
        update_option('bytemash_incremental_sync_start_time', time()); // Track start time
        
        try {
            // Get enabled sync attributes
            $sync_products = get_option('bytemash_sync_products', true);
            $sync_stock = get_option('bytemash_sync_stock', true);
            $sync_prices = get_option('bytemash_sync_prices', true);
            $sync_categories = get_option('bytemash_sync_categories', true);
            $sync_brands = get_option('bytemash_sync_brands', true);
            
            // Get last incremental sync timestamp
            $last_incremental = get_option('bytemash_last_incremental_sync', $last_full_sync);
            
            $results = array();
            
            // Run incremental sync for enabled endpoints in sequence (queue-like behavior)
            if ($sync_products) {
            $this->logger->log('info', 'Starting incremental product sync', array(), 'incremental_sync');
                $results['products'] = $this->sync_updated_products_for_cron(true);
            }
            
            if ($sync_stock) {
                $this->logger->log('info', 'Starting incremental stock sync', array(), 'incremental_sync');
                $results['stock'] = $this->product_sync->sync_stock_updated();
            }
            
            if ($sync_prices) {
                $this->logger->log('info', 'Starting incremental price sync', array(), 'incremental_sync');
                $results['prices'] = $this->product_sync->sync_prices_updated();
            }
            
            if ($sync_categories) {
                $this->logger->log('info', 'Starting incremental category sync', array(), 'incremental_sync');
                $results['categories'] = $this->product_sync->sync_categories_updated();
            }
            
            if ($sync_brands) {
                $this->logger->log('info', 'Starting incremental brand sync', array(), 'incremental_sync');
                $results['brands'] = $this->product_sync->sync_brands_updated();
            }
            
            // Store incremental sync completion timestamp
            update_option('bytemash_last_incremental_sync', current_time('mysql'));
            
            $this->logger->log('success', 'Incremental sync completed', array(), 'incremental_sync');
            
        } catch (Exception $e) {
            $this->logger->log('error', 'Incremental sync failed', array(
                'error' => $e->getMessage(),
            ), 'incremental_sync');
        }
        
        // Clear sync running flag
        delete_transient('bytemash_incremental_sync_running');
        delete_option('bytemash_incremental_sync_start_time');
    }
    
    /**
     * Update sync schedules
     */
    public function update_schedule($full_sync_frequency = 'daily', $incremental_frequency = 'every_5_hours') {
        // Clear existing schedules
        $this->clear_all_schedules();
        
        // Schedule full sync with Action Scheduler
        if ($full_sync_frequency && $full_sync_frequency !== 'manual') {
            if ($this->action_scheduler) {
                $this->action_scheduler->schedule_full_sync($full_sync_frequency);
                $this->logger->log('info', "Full sync scheduled with Action Scheduler: {$full_sync_frequency}", array(), 'scheduler');
            } else {
                $this->logger->log('error', "Cannot schedule full sync - Action Scheduler not available", array(), 'scheduler');
            }
        }
        
        // Schedule incremental sync with Action Scheduler
        if ($incremental_frequency && $incremental_frequency !== 'manual') {
            if ($this->action_scheduler) {
                $this->action_scheduler->schedule_incremental_sync($incremental_frequency);
                $this->logger->log('info', "Incremental sync scheduled with Action Scheduler: {$incremental_frequency}", array(), 'scheduler');
            } else {
                $this->logger->log('error', "Cannot schedule incremental sync - Action Scheduler not available", array(), 'scheduler');
            }
        }
    }
    
    /**
     * Restore only the full sync schedule (don't touch incremental)
     */
    public function restore_full_sync_schedule($full_sync_frequency = 'daily') {
        // Schedule full sync with Action Scheduler
        if ($full_sync_frequency && $full_sync_frequency !== 'manual') {
            if ($this->action_scheduler) {
                $this->action_scheduler->schedule_full_sync($full_sync_frequency);
                $this->logger->log('info', "Full sync schedule restored with Action Scheduler: {$full_sync_frequency}", array(), 'scheduler');
            } else {
                $this->logger->log('error', "Cannot restore full sync - Action Scheduler not available", array(), 'scheduler');
            }
        }
    }
    
    // WordPress cron scheduling removed - using Action Scheduler only
    
    /**
     * Clear all sync schedules
     */
    public function clear_all_schedules() {
        // Clear Action Scheduler schedules
        if ($this->action_scheduler) {
            $this->action_scheduler->clear_schedules();
            $this->logger->log('info', "All Action Scheduler schedules cleared", array(), 'scheduler');
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
        
        $this->logger->log('info', 'Manual sync triggered', array(), 'manual_sync');
        
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
     * AJAX: Sync all (comprehensive sync)
     */
    public function ajax_sync_all() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $this->logger->log('info', 'Comprehensive sync triggered', array(), 'comprehensive_sync');
        
        // Check if sync is already running
        if (get_transient('bytemash_sync_running')) {
            wp_send_json_error(array('message' => 'Sync is already running. Please wait.'));
        }
        
        // Set sync running flag
        set_transient('bytemash_sync_running', true, 3600);
        
        try {
            $result = $this->product_sync->sync_comprehensive(true);
            
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
        
        $this->logger->log('info', 'Manual stock sync triggered', array(), 'stock_sync');
        
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
     * AJAX: Update sync schedule
     */
    public function ajax_update_sync_schedule() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $full_sync_frequency = isset($_POST['full_sync_frequency']) ? sanitize_text_field($_POST['full_sync_frequency']) : 'daily_at_0030';
        $incremental_frequency = isset($_POST['incremental_frequency']) ? sanitize_text_field($_POST['incremental_frequency']) : 'every_5_hours';
        
        // Save settings
        update_option('bytemash_full_sync_frequency', $full_sync_frequency);
        update_option('bytemash_incremental_sync_frequency', $incremental_frequency);
        
        // Update schedules
        $this->update_schedule($full_sync_frequency, $incremental_frequency);
        
        wp_send_json_success(array(
            'message' => 'Sync schedule updated successfully',
            'next_full_sync' => $this->get_next_full_sync_time(),
            'next_incremental_sync' => $this->get_next_incremental_sync_time(),
        ));
    }
    
    // WordPress cron time checking removed - using Action Scheduler only
    
    /**
     * Get last sync times
     */
    public function get_last_sync_times() {
        return array(
            'last_full_sync' => get_option('bytemash_last_full_sync', __('Never', 'bytemash-woo-sync')),
            'last_incremental_sync' => get_option('bytemash_last_incremental_sync', __('Never', 'bytemash-woo-sync')),
        );
    }
    
    /**
     * Get sync status
     */
    public function get_sync_status() {
        $status = array(
            'last_sync_times' => $this->get_last_sync_times(),
        );
        
        // Get running status and scheduled times based on which system is being used
        if ($this->use_action_scheduler && $this->action_scheduler) {
            // Use Action Scheduler status
            $action_scheduler_status = $this->action_scheduler->get_sync_status_and_progress();
            
            
            // Extract running status from Action Scheduler
            $status['full_sync_running'] = isset($action_scheduler_status['progress']['running']) && $action_scheduler_status['progress']['running'] > 0;
            $status['incremental_sync_running'] = isset($action_scheduler_status['progress']['running']) && $action_scheduler_status['progress']['running'] > 0;
            
            // Extract next scheduled times from Action Scheduler
            if (isset($action_scheduler_status['next_scheduled']['full_sync']) && $action_scheduler_status['next_scheduled']['full_sync']) {
                $next_full_sync_timestamp = strtotime($action_scheduler_status['next_scheduled']['full_sync']);
                $status['next_full_sync'] = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_full_sync_timestamp);
            } else {
                $status['next_full_sync'] = __('Not scheduled', 'bytemash-woo-sync');
            }
                
            if (isset($action_scheduler_status['next_scheduled']['incremental_sync']) && $action_scheduler_status['next_scheduled']['incremental_sync']) {
                $next_incremental_sync_timestamp = strtotime($action_scheduler_status['next_scheduled']['incremental_sync']);
                $status['next_incremental_sync'] = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_incremental_sync_timestamp);
            } else {
                $status['next_incremental_sync'] = __('Not scheduled', 'bytemash-woo-sync');
            }
        } else {
            // Use WordPress cron status
            $status['full_sync_running'] = (bool) get_transient('bytemash_full_sync_running');
            $status['incremental_sync_running'] = (bool) get_transient('bytemash_incremental_sync_running');
            $status['next_full_sync'] = $this->get_next_full_sync_time();
            $status['next_incremental_sync'] = $this->get_next_incremental_sync_time();
        }
        
        return $status;
    }
    
    /**
     * AJAX: Enable test mode for full sync (runs every 2 minutes)
     */
    public function ajax_enable_test_mode_full_sync() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        if ($this->use_action_scheduler && $this->action_scheduler) {
            $result = $this->action_scheduler->enable_test_mode_full_sync();
            wp_send_json_success($result);
        } else {
            wp_send_json_error(array('message' => 'Action Scheduler not available'));
        }
    }
    
    /**
     * AJAX: Enable test mode for incremental sync (runs every 5 minutes)
     */
    public function ajax_enable_test_mode_incremental_sync() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        if ($this->use_action_scheduler && $this->action_scheduler) {
            $result = $this->action_scheduler->enable_test_mode_incremental_sync();
            wp_send_json_success($result);
        } else {
            wp_send_json_error(array('message' => 'Action Scheduler not available'));
        }
    }
    
    /**
     * AJAX: Disable test mode and restore normal schedules
     */
    public function ajax_disable_test_mode() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        if ($this->use_action_scheduler && $this->action_scheduler) {
            $result = $this->action_scheduler->disable_test_mode();
            wp_send_json_success($result);
        } else {
            wp_send_json_error(array('message' => 'Action Scheduler not available'));
        }
    }
    
    /**
     * AJAX: Enable production sync
     */
    public function ajax_enable_production_sync() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        if ($this->use_action_scheduler && $this->action_scheduler) {
            $result = $this->action_scheduler->enable_production_sync();
            wp_send_json_success($result);
        } else {
            wp_send_json_error(array('message' => 'Action Scheduler not available'));
        }
    }
    
    /**
     * AJAX: Get test mode status
     */
    public function ajax_get_test_mode_status() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        if ($this->use_action_scheduler && $this->action_scheduler) {
            $result = $this->action_scheduler->get_test_mode_status();
            wp_send_json_success($result);
        } else {
            wp_send_json_error(array('message' => 'Action Scheduler not available'));
        }
    }
    
    /**
     * AJAX: Get comprehensive sync status and progress
     */
    public function ajax_get_sync_status_progress() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $result = array();
        
        // Get Action Scheduler status if available
        if ($this->use_action_scheduler && $this->action_scheduler) {
            $action_scheduler_status = $this->action_scheduler->get_sync_status_and_progress();
            $result = array_merge($result, $action_scheduler_status);
        }
        
        // Get batch processor status (for manual syncs and batch processing)
        $batch_processor = new ByteMash_Batch_Processor();
        $active_syncs = $batch_processor->get_active_syncs();
        $result['active_syncs'] = $active_syncs;
        $result['has_active_syncs'] = !empty($active_syncs);
        
        // Get recent logs
        $logger = new ByteMash_Logger();
        $recent_logs = $logger->get_logs(10);
        $result['recent_logs'] = $recent_logs;
        
        // Check if any sync is currently running
        $is_syncing = get_transient('bytemash_sync_running');
        $result['sync_running'] = (bool) $is_syncing;
        
        // Get test mode status
        $result['full_test_mode'] = get_option('bytemash_cron_full_test_mode_enabled', false);
        $result['incremental_test_mode'] = get_option('bytemash_cron_incremental_test_mode_enabled', false);
        
        // WordPress cron fallback removed - using Action Scheduler only
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Get all scheduled times
     */
    public function ajax_get_scheduled_times() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        if ($this->use_action_scheduler && $this->action_scheduler) {
            $result = $this->action_scheduler->get_scheduled_times();
            wp_send_json_success($result);
        } else {
            wp_send_json_error(array('message' => 'Action Scheduler not available'));
        }
    }
    
    /**
     * AJAX: Get batch progress for specific sync
     */
    public function ajax_get_batch_progress() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $sync_id = sanitize_text_field($_POST['sync_id'] ?? '');
        if (empty($sync_id)) {
            wp_send_json_error(array('message' => 'Sync ID is required'));
        }
        
        if ($this->use_action_scheduler && $this->action_scheduler) {
            $result = $this->action_scheduler->get_batch_progress($sync_id);
            wp_send_json_success($result);
        } else {
            wp_send_json_error(array('message' => 'Action Scheduler not available'));
        }
    }
}

