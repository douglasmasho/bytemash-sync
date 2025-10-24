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
        
        // Hook into cron events
        add_action('bytemash_full_sync_cron', array($this, 'run_full_sync'));
        add_action('bytemash_incremental_sync_cron', array($this, 'run_incremental_sync'));
        
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
        add_action('wp_ajax_bytemash_update_sync_schedule', array($this, 'ajax_update_sync_schedule'));
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
        $schedules['every_5_hours'] = array(
            'interval' => 5 * HOUR_IN_SECONDS,
            'display' => __('Every 5 Hours', 'bytemash-woo-sync'),
        );
        
        $schedules['every_6_hours'] = array(
            'interval' => 6 * HOUR_IN_SECONDS,
            'display' => __('Every 6 Hours', 'bytemash-woo-sync'),
        );
        
        $schedules['every_12_hours'] = array(
            'interval' => 12 * HOUR_IN_SECONDS,
            'display' => __('Every 12 Hours', 'bytemash-woo-sync'),
        );
        
        $schedules['daily_at_0030'] = array(
            'interval' => DAY_IN_SECONDS,
            'display' => __('Daily at 00:30 GMT+2', 'bytemash-woo-sync'),
        );
        
        $schedules['every_5_minutes'] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every 5 Minutes', 'bytemash-woo-sync'),
        );
        
        return $schedules;
    }
    
    /**
     * Run full sync (daily at 00:30 GMT+2)
     * According to API docs: Full stock list is cleared and repopulated at 00:30 GMT+2
     */
    public function run_full_sync() {
        $this->logger->log('info', 'Running full sync (daily reset)', array(), 'full_sync');
        
        // Check if sync is already running
        if (get_transient('bytemash_full_sync_running')) {
            $this->logger->log('warning', 'Full sync already running, skipping', array(), 'full_sync');
            return;
        }
        
        // Set sync running flag
        set_transient('bytemash_full_sync_running', true, 7200); // 2 hours timeout
        
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
        $api_client = new ByteMash_Amrod_API_Client();
        if ($with_branding) {
            $products = $api_client->get_products_with_branding();
        } else {
            $products = $api_client->get_products_without_branding();
        }
        
        if (is_wp_error($products)) {
            $this->logger->log('error', 'Failed to fetch products for cron sync', array(
                'error' => $products->get_error_message(),
            ), 'cron_sync');
            return array('success' => false, 'message' => $products->get_error_message());
        }
        
        if (!is_array($products) || empty($products)) {
            $this->logger->log('warning', 'No products found for cron sync', array(), 'cron_sync');
            return array('success' => false, 'message' => 'No products found');
        }
        
        $total = count($products);
        $batch_size = (int) get_option('bytemash_amrod_batch_size', 10);
        $batches = array_chunk($products, $batch_size);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Processing {$total} products in {$batch_count} batches for cron", array(
            'total' => $total,
            'batch_size' => $batch_size,
            'batch_count' => $batch_count,
        ), 'cron_sync');
        
        $processed = 0;
        $errors = 0;
        $skipped = 0;
        
        // Process each batch directly
        foreach ($batches as $batch_index => $batch) {
            $this->logger->log('info', "Processing batch " . ($batch_index + 1) . "/{$batch_count}", array(
                'batch_index' => $batch_index,
                'batch_size' => count($batch),
            ), 'cron_sync');
            
            foreach ($batch as $product_data) {
                try {
                    $result = $this->product_sync->sync_single_product($product_data, false);
                    if ($result['success']) {
                        $processed++;
                    } else {
                        $skipped++;
                        $this->logger->log('warning', 'Product sync skipped', array(
                            'sku' => $product_data['sku'] ?? 'unknown',
                            'reason' => $result['message'] ?? 'Unknown',
                        ), 'cron_sync');
                    }
                } catch (Exception $e) {
                    $errors++;
                    $this->logger->log('error', 'Product sync failed', array(
                        'sku' => $product_data['sku'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ), 'cron_sync');
                }
            }
            
            // Clear memory after each batch
            unset($batch);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        $this->logger->log('success', 'Cron product sync completed', array(
            'total' => $total,
            'processed' => $processed,
            'errors' => $errors,
            'skipped' => $skipped,
        ), 'cron_sync');
        
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
        $this->logger->log('info', 'Starting cron-based incremental product sync', array(
            'with_branding' => $with_branding,
        ), 'cron_sync');
        
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
        
        $this->logger->log('info', "Processing {$total} updated products in {$batch_count} batches for cron", array(
            'total' => $total,
            'batch_size' => $batch_size,
            'batch_count' => $batch_count,
        ), 'cron_sync');
        
        $processed = 0;
        $errors = 0;
        $skipped = 0;
        
        // Process each batch directly
        foreach ($batches as $batch_index => $batch) {
            $this->logger->log('info', "Processing updated batch " . ($batch_index + 1) . "/{$batch_count}", array(
                'batch_index' => $batch_index,
                'batch_size' => count($batch),
            ), 'cron_sync');
            
            foreach ($batch as $product_data) {
                try {
                    $result = $this->product_sync->sync_single_product($product_data, false);
                    if ($result['success']) {
                        $processed++;
                    } else {
                        $skipped++;
                        $this->logger->log('warning', 'Updated product sync skipped', array(
                            'sku' => $product_data['sku'] ?? 'unknown',
                            'reason' => $result['message'] ?? 'Unknown',
                        ), 'cron_sync');
                    }
                } catch (Exception $e) {
                    $errors++;
                    $this->logger->log('error', 'Updated product sync failed', array(
                        'sku' => $product_data['sku'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ), 'cron_sync');
                }
            }
            
            // Clear memory after each batch
            unset($batch);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        $this->logger->log('success', 'Cron incremental product sync completed', array(
            'total' => $total,
            'processed' => $processed,
            'errors' => $errors,
            'skipped' => $skipped,
        ), 'cron_sync');
        
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
            $this->logger->log('warning', 'Incremental sync already running, skipping', array(), 'incremental_sync');
            return;
        }
        
        // Set sync running flag
        set_transient('bytemash_incremental_sync_running', true, 3600); // 1 hour timeout
        
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
                $this->logger->log('info', 'Starting incremental product sync', array(
                    'since' => $last_incremental
                ), 'incremental_sync');
                $results['products'] = $this->sync_updated_products_for_cron(true);
            }
            
            if ($sync_stock) {
                $this->logger->log('info', 'Starting incremental stock sync', array(
                    'since' => $last_incremental
                ), 'incremental_sync');
                $results['stock'] = $this->product_sync->sync_stock_updated();
            }
            
            if ($sync_prices) {
                $this->logger->log('info', 'Starting incremental price sync', array(
                    'since' => $last_incremental
                ), 'incremental_sync');
                $results['prices'] = $this->product_sync->sync_prices_updated();
            }
            
            if ($sync_categories) {
                $this->logger->log('info', 'Starting incremental category sync', array(
                    'since' => $last_incremental
                ), 'incremental_sync');
                $results['categories'] = $this->product_sync->sync_categories_updated();
            }
            
            if ($sync_brands) {
                $this->logger->log('info', 'Starting incremental brand sync', array(
                    'since' => $last_incremental
                ), 'incremental_sync');
                $results['brands'] = $this->product_sync->sync_brands_updated();
            }
            
            // Store incremental sync completion timestamp
            update_option('bytemash_last_incremental_sync', current_time('mysql'));
            
            $this->logger->log('success', 'Incremental sync completed', array(
                'results' => $results,
                'enabled_attributes' => array(
                    'products' => $sync_products,
                    'stock' => $sync_stock,
                    'prices' => $sync_prices,
                    'categories' => $sync_categories,
                    'brands' => $sync_brands,
                )
            ), 'incremental_sync');
            
        } catch (Exception $e) {
            $this->logger->log('error', 'Incremental sync failed', array(
                'error' => $e->getMessage(),
            ), 'incremental_sync');
        }
        
        // Clear sync running flag
        delete_transient('bytemash_incremental_sync_running');
    }
    
    /**
     * Update sync schedules
     */
    public function update_schedule($full_sync_frequency = 'daily_at_0030', $incremental_frequency = 'every_5_hours') {
        // Clear existing schedules
        $this->clear_all_schedules();
        
        // Schedule full sync (daily at 00:30 GMT+2)
        if ($full_sync_frequency && $full_sync_frequency !== 'manual') {
            $this->schedule_full_sync();
            $this->logger->log('info', "Full sync schedule updated to: {$full_sync_frequency}", array(), 'scheduler');
        }
        
        // Schedule incremental sync
        if ($incremental_frequency && $incremental_frequency !== 'manual') {
            wp_schedule_event(time(), $incremental_frequency, 'bytemash_incremental_sync_cron');
            $this->logger->log('info', "Incremental sync schedule updated to: {$incremental_frequency}", array(), 'scheduler');
        }
    }
    
    /**
     * Restore only the full sync schedule (don't touch incremental)
     */
    public function restore_full_sync_schedule($full_sync_frequency = 'daily_at_0030') {
        // Clear only the full sync schedule
        $timestamp = wp_next_scheduled('bytemash_full_sync_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'bytemash_full_sync_cron');
        }
        
        // Schedule only the full sync (don't touch incremental)
        if ($full_sync_frequency && $full_sync_frequency !== 'manual') {
            $this->schedule_full_sync();
            $this->logger->log('info', "Full sync schedule restored to: {$full_sync_frequency}", array(), 'scheduler');
        }
    }
    
    /**
     * Schedule full sync at 00:30 GMT+2 daily
     */
    private function schedule_full_sync() {
        // Calculate next 00:30 GMT+2 (South Africa time)
        $timezone = new DateTimeZone('Africa/Johannesburg');
        $now = new DateTime('now', $timezone);
        
        // Set to 00:30 today
        $next_sync = clone $now;
        $next_sync->setTime(0, 30, 0);
        
        // If it's already past 00:30 today, schedule for tomorrow
        if ($next_sync <= $now) {
            $next_sync->add(new DateInterval('P1D'));
        }
        
        // Convert to WordPress timezone
        $wp_timestamp = $next_sync->getTimestamp() - (get_option('gmt_offset') * HOUR_IN_SECONDS);
        
        wp_schedule_event($wp_timestamp, 'daily_at_0030', 'bytemash_full_sync_cron');
    }
    
    /**
     * Clear all sync schedules
     */
    public function clear_all_schedules() {
        // Clear full sync
        $timestamp = wp_next_scheduled('bytemash_full_sync_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'bytemash_full_sync_cron');
        }
        
        // Clear incremental sync
        $timestamp = wp_next_scheduled('bytemash_incremental_sync_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'bytemash_incremental_sync_cron');
        }
        
        // Clear old schedule for backward compatibility
        $timestamp = wp_next_scheduled('bytemash_amrod_sync_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'bytemash_amrod_sync_cron');
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
    
    /**
     * Get next scheduled full sync time
     */
    public function get_next_full_sync_time() {
        $timestamp = wp_next_scheduled('bytemash_full_sync_cron');
        
        if (!$timestamp) {
            return __('Not scheduled', 'bytemash-woo-sync');
        }
        
        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }
    
    /**
     * Get next scheduled incremental sync time
     */
    public function get_next_incremental_sync_time() {
        $timestamp = wp_next_scheduled('bytemash_incremental_sync_cron');
        
        if (!$timestamp) {
            return __('Not scheduled', 'bytemash-woo-sync');
        }
        
        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }
    
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
        return array(
            'full_sync_running' => (bool) get_transient('bytemash_full_sync_running'),
            'incremental_sync_running' => (bool) get_transient('bytemash_incremental_sync_running'),
            'next_full_sync' => $this->get_next_full_sync_time(),
            'next_incremental_sync' => $this->get_next_incremental_sync_time(),
            'last_sync_times' => $this->get_last_sync_times(),
        );
    }
}

