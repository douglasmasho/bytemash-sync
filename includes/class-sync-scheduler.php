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
     * Action Scheduler instance
     */
    private $action_scheduler;
    
    /**
     * Whether to use Action Scheduler
     */
    private $use_action_scheduler = false;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new ByteMash_Logger();
        $this->product_sync = new ByteMash_Product_Sync();
        
        $this->init_hooks();
        $this->init_action_scheduler();
    }
    
    /**
     * Initialize Action Scheduler integration
     */
    private function init_action_scheduler() {
        // Check if Action Scheduler is available
        if (class_exists('ByteMash_Action_Scheduler_Sync')) {
            $this->action_scheduler = new ByteMash_Action_Scheduler_Sync();
            
            // Use Action Scheduler for scheduling if available
            $this->use_action_scheduler = $this->action_scheduler->is_action_scheduler_available();
            
            // Only log once per session to avoid spam
            if (!get_transient('bytemash_action_scheduler_logged')) {
                if ($this->use_action_scheduler) {
                    $this->logger->log('info', 'Action Scheduler integration enabled', array(), 'sync_scheduler');
                } else {
                    $this->logger->log('warning', 'Action Scheduler not available, falling back to WordPress cron', array(), 'sync_scheduler');
                }
                set_transient('bytemash_action_scheduler_logged', true, HOUR_IN_SECONDS); // reduce log spam
            }
        } else {
            $this->use_action_scheduler = false;
            if (!get_transient('bytemash_action_scheduler_logged')) {
                $this->logger->log('warning', 'Action Scheduler class not found, using WordPress cron', array(), 'sync_scheduler');
                set_transient('bytemash_action_scheduler_logged', true, HOUR_IN_SECONDS); // reduce log spam
            }
        }
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
        
        $schedules['daily_at_0130'] = array(
            'interval' => DAY_IN_SECONDS,
            'display' => __('Daily at 01:30 GMT+2', 'bytemash-woo-sync'),
        );
        
        $schedules['every_5_minutes'] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every 5 Minutes', 'bytemash-woo-sync'),
        );
        
        return $schedules;
    }
    
    /**
     * Run full sync (daily at 01:30 GMT+2)
     * API maintenance window: 00:00-01:00 GMT+2 daily
     */
    public function run_full_sync() {
        $this->logger->log('info', '🚀 Starting full sync (daily reset)', array(), 'full_sync');
        
        // Check if sync is already running
        if (get_transient('bytemash_full_sync_running')) {
            $this->logger->log('warning', 'Full sync already running, skipping', array(), 'full_sync');
            return;
        }
        
        // Set sync running flag
        set_transient('bytemash_full_sync_running', true, 7200); // 2 hours timeout
        
        // Initialize overall progress tracking
        $overall_progress = array(
            'status' => 'running',
            'started' => current_time('mysql'),
            'phases' => array(),
            'current_phase' => 'initializing',
            'total_phases' => 0,
            'completed_phases' => 0
        );
        
        try {
            // Get enabled sync attributes
            $sync_products = get_option('bytemash_sync_products', true);
            $sync_stock = get_option('bytemash_sync_stock', true);
            $sync_prices = get_option('bytemash_sync_prices', true);
            $sync_categories = get_option('bytemash_sync_categories', true);
            $sync_brands = get_option('bytemash_sync_brands', true);
            
            // Count enabled phases
            $enabled_phases = array_filter(array(
                'products' => $sync_products,
                'stock' => $sync_stock,
                'prices' => $sync_prices,
                'categories' => $sync_categories,
                'brands' => $sync_brands
            ));
            
            $overall_progress['total_phases'] = count($enabled_phases);
            $overall_progress['enabled_phases'] = array_keys($enabled_phases);
            
            $this->logger->log('info', "📋 Full sync phases enabled: " . implode(', ', array_keys($enabled_phases)), array(
                'enabled_phases' => array_keys($enabled_phases),
                'total_phases' => count($enabled_phases)
            ), 'full_sync');
            
            $results = array();
            $phase_number = 1;
            
            // Run full sync for enabled endpoints in sequence (queue-like behavior)
            if ($sync_products) {
                $overall_progress['current_phase'] = 'products';
                $this->update_overall_progress($overall_progress);
                
                $this->logger->log('info', "🛍️ Phase {$phase_number}/{$overall_progress['total_phases']}: Starting full product sync", array(), 'full_sync');
                $results['products'] = $this->sync_products_for_cron(true);
                
                $overall_progress['phases']['products'] = array(
                    'status' => 'completed',
                    'completed_at' => current_time('mysql'),
                    'result' => $results['products']
                );
                $overall_progress['completed_phases']++;
                $this->update_overall_progress($overall_progress);
                
                $this->logger->log('success', "✅ Phase {$phase_number}/{$overall_progress['total_phases']}: Product sync completed", array(
                    'result' => $results['products']
                ), 'full_sync');
                $phase_number++;
            }
            
            if ($sync_stock) {
                $overall_progress['current_phase'] = 'stock';
                $this->update_overall_progress($overall_progress);
                
                $this->logger->log('info', "📦 Phase {$phase_number}/{$overall_progress['total_phases']}: Starting full stock sync", array(), 'full_sync');
                $stock_result = $this->product_sync->sync_stock_levels();
                
                if ($stock_result['success'] && !empty($stock_result['data'])) {
                    $batch_processor = new ByteMash_Batch_Processor();
                    $batch_processor->schedule_stock_sync($stock_result['data'], $stock_result['sync_id']);
                    $this->logger->log('info', "Stock sync scheduled with batch processor", array('sync_id' => $stock_result['sync_id']), 'full_sync');
                }
                
                $results['stock'] = $stock_result;
                
                $overall_progress['phases']['stock'] = array(
                    'status' => 'scheduled',
                    'scheduled_at' => current_time('mysql'),
                    'result' => $results['stock']
                );
                $overall_progress['completed_phases']++;
                $this->update_overall_progress($overall_progress);
                
                $this->logger->log('success', "✅ Phase {$phase_number}/{$overall_progress['total_phases']}: Stock sync scheduled", array(
                    'result' => $results['stock']
                ), 'full_sync');
                $phase_number++;
            }
            
            if ($sync_prices) {
                $overall_progress['current_phase'] = 'prices';
                $this->update_overall_progress($overall_progress);
                
                $this->logger->log('info', "💰 Phase {$phase_number}/{$overall_progress['total_phases']}: Starting full price sync", array(), 'full_sync');
                $prices_result = $this->product_sync->sync_prices();
                
                if ($prices_result['success'] && !empty($prices_result['data'])) {
                    $batch_processor = new ByteMash_Batch_Processor();
                    $batch_processor->schedule_prices_sync($prices_result['data'], $prices_result['sync_id']);
                    $this->logger->log('info', "Prices sync scheduled with batch processor", array('sync_id' => $prices_result['sync_id']), 'full_sync');
                }
                
                $results['prices'] = $prices_result;
                
                $overall_progress['phases']['prices'] = array(
                    'status' => 'scheduled',
                    'scheduled_at' => current_time('mysql'),
                    'result' => $results['prices']
                );
                $overall_progress['completed_phases']++;
                $this->update_overall_progress($overall_progress);
                
                $this->logger->log('success', "✅ Phase {$phase_number}/{$overall_progress['total_phases']}: Prices sync scheduled", array(
                    'result' => $results['prices']
                ), 'full_sync');
                $phase_number++;
            }
            
            if ($sync_categories) {
                $overall_progress['current_phase'] = 'categories';
                $this->update_overall_progress($overall_progress);
                
                $this->logger->log('info', "📂 Phase {$phase_number}/{$overall_progress['total_phases']}: Starting full category sync", array(), 'full_sync');
                $categories_result = $this->product_sync->sync_categories();
                
                if ($categories_result['success'] && !empty($categories_result['tree'])) {
                    $batch_processor = new ByteMash_Batch_Processor();
                    $batch_processor->process_categories_batch($categories_result['tree']);
                    $this->logger->log('info', "Categories processed with batch processor", array(), 'full_sync');
                }
                
                $results['categories'] = $categories_result;
                
                $overall_progress['phases']['categories'] = array(
                    'status' => 'completed',
                    'completed_at' => current_time('mysql'),
                    'result' => $results['categories']
                );
                $overall_progress['completed_phases']++;
                $this->update_overall_progress($overall_progress);
                
                $this->logger->log('success', "✅ Phase {$phase_number}/{$overall_progress['total_phases']}: Category sync completed", array(
                    'result' => $results['categories']
                ), 'full_sync');
                $phase_number++;
            }
            
            if ($sync_brands) {
                $overall_progress['current_phase'] = 'brands';
                $this->update_overall_progress($overall_progress);
                
                $this->logger->log('info', "🏷️ Phase {$phase_number}/{$overall_progress['total_phases']}: Starting full brand sync", array(), 'full_sync');
                $brands_result = $this->product_sync->sync_brands();
                
                if ($brands_result['success'] && !empty($brands_result['data'])) {
                    $batch_processor = new ByteMash_Batch_Processor();
                    $batch_processor->schedule_brands_sync($brands_result['data'], $brands_result['sync_id']);
                    $this->logger->log('info', "Brands sync scheduled with batch processor", array('sync_id' => $brands_result['sync_id']), 'full_sync');
                }
                
                $results['brands'] = $brands_result;
                
                $overall_progress['phases']['brands'] = array(
                    'status' => 'scheduled',
                    'scheduled_at' => current_time('mysql'),
                    'result' => $results['brands']
                );
                $overall_progress['completed_phases']++;
                $this->update_overall_progress($overall_progress);
                
                $this->logger->log('success', "✅ Phase {$phase_number}/{$overall_progress['total_phases']}: Brands sync scheduled", array(
                    'result' => $results['brands']
                ), 'full_sync');
            }
            
            // Mark overall sync as completed
            $overall_progress['status'] = 'completed';
            $overall_progress['completed'] = current_time('mysql');
            $this->update_overall_progress($overall_progress);
            
            // Store full sync completion timestamp
            update_option('bytemash_last_full_sync', current_time('mysql'));
            
            $this->logger->log('success', '🎉 Full sync completed successfully!', array(
                'results' => $results,
                'enabled_attributes' => array(
                    'products' => $sync_products,
                    'stock' => $sync_stock,
                    'prices' => $sync_prices,
                    'categories' => $sync_categories,
                    'brands' => $sync_brands,
                ),
                'total_phases' => $overall_progress['total_phases'],
                'completed_phases' => $overall_progress['completed_phases']
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
     * Update overall sync progress
     */
    private function update_overall_progress($progress) {
        update_option('bytemash_overall_sync_progress', $progress, false);
    }
    
    /**
     * Get overall sync progress
     */
    public function get_overall_progress() {
        return get_option('bytemash_overall_sync_progress', array());
    }
    
    /**
     * Sync products for cron (processes directly without JavaScript)
     */
    private function sync_products_for_cron($with_branding = true) {
        $sync_id = 'cron_products_' . time();
        
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
        
        // Capture snapshot so we can reconcile catalog counts after processing completes
        $this->product_sync->prepare_sku_snapshot($sync_id, $products, array(
            'context' => 'cron_full_sync',
            'fetch_full_catalog' => false,
            'with_branding' => $with_branding,
        ));
        
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
        
        // Clean up WooCommerce catalog to match API snapshot
        $this->product_sync->cleanup_products_not_in_snapshot($sync_id);
        
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
        $sync_id = 'cron_products_incremental_' . time();
        
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
        
        // Always capture the latest catalog snapshot so we can reconcile product counts
        $this->product_sync->prepare_sku_snapshot($sync_id, null, array(
            'context' => 'cron_incremental_sync',
            'fetch_full_catalog' => true,
            'with_branding' => false,
        ));
        
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
        
        $this->product_sync->cleanup_products_not_in_snapshot($sync_id);
        
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
            $this->logger->log('info', 'Starting incremental product sync', array(), 'incremental_sync');
                $results['products'] = $this->sync_updated_products_for_cron(true);
            }
            
            if ($sync_stock) {
                $this->logger->log('info', 'Starting incremental stock sync', array(), 'incremental_sync');
                $stock_result = $this->product_sync->sync_stock_updated();
                if ($stock_result['success'] && !empty($stock_result['data'])) {
                    $batch_processor = new ByteMash_Batch_Processor();
                    $batch_processor->schedule_stock_sync($stock_result['data'], $stock_result['sync_id']);
                    $this->logger->log('info', "Incremental stock sync scheduled with batch processor", array('sync_id' => $stock_result['sync_id']), 'incremental_sync');
                }
                $results['stock'] = $stock_result;
            }
            
            if ($sync_prices) {
                $this->logger->log('info', 'Starting incremental price sync', array(), 'incremental_sync');
                $prices_result = $this->product_sync->sync_prices_updated();
                if ($prices_result['success'] && !empty($prices_result['data'])) {
                    $batch_processor = new ByteMash_Batch_Processor();
                    $batch_processor->schedule_prices_sync($prices_result['data'], $prices_result['sync_id']);
                    $this->logger->log('info', "Incremental prices sync scheduled with batch processor", array('sync_id' => $prices_result['sync_id']), 'incremental_sync');
                }
                $results['prices'] = $prices_result;
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
    }
    
    /**
     * Update sync schedules
     */
    public function update_schedule($full_sync_frequency = 'daily_at_0130', $incremental_frequency = 'every_5_hours') {
        // Clear existing schedules
        $this->clear_all_schedules();
        
        // Schedule full sync (daily at 01:30 GMT+2, avoiding API downtime 00:00-01:00)
        if ($full_sync_frequency && $full_sync_frequency !== 'manual') {
            $this->schedule_full_sync();
            $this->logger->log('info', "Full sync schedule updated to: {$full_sync_frequency}", array(), 'scheduler');
        }
        
        // Schedule incremental sync
        if ($incremental_frequency && $incremental_frequency !== 'manual') {
            if ($this->use_action_scheduler && $this->action_scheduler) {
                // Use Action Scheduler for more reliable processing
                $this->action_scheduler->schedule_incremental_sync($incremental_frequency);
                $this->logger->log('info', "Incremental sync scheduled with Action Scheduler: {$incremental_frequency}", array(), 'scheduler');
            } else {
                // Fall back to WordPress cron
                wp_schedule_event(time(), $incremental_frequency, 'bytemash_incremental_sync_cron');
                $this->logger->log('info', "Incremental sync schedule updated to: {$incremental_frequency}", array(), 'scheduler');
            }
        }
    }
    
    /**
     * Restore only the full sync schedule (don't touch incremental)
     */
    public function restore_full_sync_schedule($full_sync_frequency = 'daily_at_0130') {
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
        if ($this->use_action_scheduler && $this->action_scheduler) {
            // Use Action Scheduler for more reliable processing
            $this->action_scheduler->schedule_full_sync('daily');
            $this->logger->log('info', "Full sync scheduled with Action Scheduler", array(), 'sync_scheduler');
            return;
        }
        // Calculate next 01:30 GMT+2 (South Africa time) - avoiding API downtime 00:00-01:00
        $timezone = new DateTimeZone('Africa/Johannesburg');
        $now = new DateTime('now', $timezone);
        
        // Set to 01:30 today
        $next_sync = clone $now;
        $next_sync->setTime(1, 30, 0);
        
        // If it's already past 01:30 today, schedule for tomorrow
        if ($next_sync <= $now) {
            $next_sync->add(new DateInterval('P1D'));
        }
        
        // Convert to WordPress timezone
        $wp_timestamp = $next_sync->getTimestamp() - (get_option('gmt_offset') * HOUR_IN_SECONDS);
        
        wp_schedule_event($wp_timestamp, 'daily_at_0130', 'bytemash_full_sync_cron');
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
            // Fetch stock data
            $result = $this->product_sync->sync_stock_levels();
            
            if (!$result['success'] || empty($result['data'])) {
                wp_send_json_error($result);
                return;
            }
            
            // Process all batches immediately
            $batch_processor = new ByteMash_Batch_Processor();
            $process_result = $batch_processor->process_stock_sync_immediately($result['data'], $result['sync_id']);
            
            if ($process_result['success']) {
                wp_send_json_success(array(
                    'message' => "Stock sync completed: {$process_result['processed']} processed, {$process_result['errors']} errors",
                    'sync_id' => $result['sync_id'],
                    'processed' => $process_result['processed'],
                    'errors' => $process_result['errors'],
                    'total' => $process_result['total'],
                ));
            } else {
                wp_send_json_error($process_result);
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
            // Fetch updated stock data
            $result = $this->product_sync->sync_stock_updated();
            
            if (!$result['success'] || empty($result['data'])) {
                wp_send_json_error($result);
                return;
            }
            
            // Process all batches immediately
            $batch_processor = new ByteMash_Batch_Processor();
            $process_result = $batch_processor->process_stock_sync_immediately($result['data'], $result['sync_id']);
            
            if ($process_result['success']) {
                wp_send_json_success(array(
                    'message' => "Stock sync completed: {$process_result['processed']} processed, {$process_result['errors']} errors",
                    'sync_id' => $result['sync_id'],
                    'processed' => $process_result['processed'],
                    'errors' => $process_result['errors'],
                    'total' => $process_result['total'],
                ));
            } else {
                wp_send_json_error($process_result);
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
            // Fetch price data
            $result = $this->product_sync->sync_prices();
            
            if (!$result['success'] || empty($result['data'])) {
                wp_send_json_error($result);
                return;
            }
            
            // Process all batches immediately
            $batch_processor = new ByteMash_Batch_Processor();
            $process_result = $batch_processor->process_prices_sync_immediately($result['data'], $result['sync_id']);
            
            if ($process_result['success']) {
                wp_send_json_success(array(
                    'message' => "Price sync completed: {$process_result['processed']} processed, {$process_result['errors']} errors",
                    'sync_id' => $result['sync_id'],
                    'processed' => $process_result['processed'],
                    'errors' => $process_result['errors'],
                    'total' => $process_result['total'],
                ));
            } else {
                wp_send_json_error($process_result);
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
            // Fetch updated price data
            $result = $this->product_sync->sync_prices_updated();
            
            if (!$result['success'] || empty($result['data'])) {
                wp_send_json_error($result);
                return;
            }
            
            // Process all batches immediately
            $batch_processor = new ByteMash_Batch_Processor();
            $process_result = $batch_processor->process_prices_sync_immediately($result['data'], $result['sync_id']);
            
            if ($process_result['success']) {
                wp_send_json_success(array(
                    'message' => "Price sync completed: {$process_result['processed']} processed, {$process_result['errors']} errors",
                    'sync_id' => $result['sync_id'],
                    'processed' => $process_result['processed'],
                    'errors' => $process_result['errors'],
                    'total' => $process_result['total'],
                ));
            } else {
                wp_send_json_error($process_result);
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
            // Fetch category data
            $result = $this->product_sync->sync_categories();
            
            if (!$result['success'] || empty($result['tree'])) {
                wp_send_json_error($result);
                return;
            }
            
            // Process categories immediately (they process synchronously)
            $batch_processor = new ByteMash_Batch_Processor();
            $batch_processor->process_categories_batch($result['tree']);
            
            wp_send_json_success(array(
                'message' => "Category sync completed",
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
            ));
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
            $result = $this->action_scheduler->enable_production_full_sync();
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
        
        if ($this->use_action_scheduler && $this->action_scheduler) {
            $result = $this->action_scheduler->get_sync_status_and_progress();
            wp_send_json_success($result);
        } else {
            wp_send_json_error(array('message' => 'Action Scheduler not available'));
        }
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

