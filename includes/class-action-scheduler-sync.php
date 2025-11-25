<?php
/**
 * Action Scheduler Integration for ByteMash WooCommerce Sync
 * 
 * This class provides reliable background processing using Action Scheduler
 * to handle long-running sync operations without timeout issues.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_Action_Scheduler_Sync {
    
    private $logger;
    private $product_sync;
    
    public function __construct() {
        $this->logger = new ByteMash_Logger();
        $this->product_sync = new ByteMash_Product_Sync();
        
        $this->init_hooks();
    }

    /**
     * Check if background scheduling is enabled (production or any test mode)
     */
    private function is_scheduling_enabled() {
        $prod = (bool) get_option('bytemash_cron_production_full_sync_enabled', false);
        $test = (bool) get_option('bytemash_cron_test_mode_enabled', false)
            || (bool) get_option('bytemash_cron_full_test_mode_enabled', false)
            || (bool) get_option('bytemash_cron_incremental_test_mode_enabled', false);
        return ($prod || $test);
    }
    
    /**
     * Initialize Action Scheduler hooks
     */
    private function init_hooks() {
        // Hook our sync actions
        add_action('bytemash_action_scheduler_full_sync', array($this, 'run_full_sync_action'));
        add_action('bytemash_action_scheduler_incremental_sync', array($this, 'run_incremental_sync_action'));
        add_action('bytemash_action_scheduler_batch_sync', array($this, 'run_batch_sync_action'), 10, 3);
        
        // Hook for stock/prices/brands batch processing via Action Scheduler
        add_action('bytemash_action_scheduler_stock_batch', array($this, 'run_stock_batch_action'), 10, 2);
        add_action('bytemash_action_scheduler_prices_batch', array($this, 'run_prices_batch_action'), 10, 2);
        add_action('bytemash_action_scheduler_brands_batch', array($this, 'run_brands_batch_action'), 10, 2);
        
        // Hook for phase completion to trigger next phase
        add_action('bytemash_action_scheduler_phase_complete', array($this, 'run_next_phase_action'), 10, 2);
        
        // Hook for cleanup
        add_action('bytemash_action_scheduler_cleanup', array($this, 'cleanup_old_syncs'));

        // If scheduling is disabled, proactively clear any pending schedules and log once
        if (!$this->is_scheduling_enabled()) {
            $this->clear_schedules();
            $this->logger->log('info', 'Scheduling disabled: cleared Action Scheduler queues (full, incremental, batch, cleanup)', array(), 'action_scheduler');
        }
    }
    
    /**
     * Schedule full sync using Action Scheduler
     */
    public function schedule_full_sync($interval = 'daily') {
        if (!$this->is_scheduling_enabled()) {
            $this->logger->log('warning', 'Schedule request ignored: scheduling disabled', array('type' => 'full'), 'action_scheduler');
            return;
        }
        // Clear existing schedules first
        $this->clear_full_sync_schedules();
        
        // Schedule the recurring action
        as_schedule_recurring_action(
            time(),
            $this->get_interval_seconds($interval),
            'bytemash_action_scheduler_full_sync',
            array('with_branding' => true),
            'bytemash-sync'
        );
        
        $this->logger->log('info', "Full sync scheduled with Action Scheduler", array(
            'interval' => $interval,
        ), 'action_scheduler');
    }
    
    /**
     * Schedule incremental sync using Action Scheduler
     */
    public function schedule_incremental_sync($interval = 'hourly') {
        if (!$this->is_scheduling_enabled()) {
            $this->logger->log('warning', 'Schedule request ignored: scheduling disabled', array('type' => 'incremental'), 'action_scheduler');
            return;
        }
        // Clear existing schedules first
        $this->clear_incremental_sync_schedules();
        
        // Schedule the recurring action
        as_schedule_recurring_action(
            time(),
            $this->get_interval_seconds($interval),
            'bytemash_action_scheduler_incremental_sync',
            array('with_branding' => true),
            'bytemash-sync'
        );
        
        $this->logger->log('info', "Incremental sync scheduled with Action Scheduler", array(
            'interval' => $interval,
        ), 'action_scheduler');
    }
    
    /**
     * Clear all sync schedules
     */
    public function clear_schedules() {
        $this->clear_full_sync_schedules();
        $this->clear_incremental_sync_schedules();
        
        // Also clear stock/prices/brands batch schedules
        as_unschedule_all_actions('bytemash_action_scheduler_stock_batch', null, 'bytemash-sync');
        as_unschedule_all_actions('bytemash_action_scheduler_prices_batch', null, 'bytemash-sync');
        as_unschedule_all_actions('bytemash_action_scheduler_brands_batch', null, 'bytemash-sync');
        
        $this->logger->log('info', "All sync schedules cleared", array(), 'action_scheduler');
    }
    
    /**
     * Clear full sync schedules
     */
    private function clear_full_sync_schedules() {
        as_unschedule_all_actions('bytemash_action_scheduler_full_sync', array('with_branding' => true), 'bytemash-sync');
    }
    
    /**
     * Clear incremental sync schedules
     */
    private function clear_incremental_sync_schedules() {
        as_unschedule_all_actions('bytemash_action_scheduler_incremental_sync', array('with_branding' => true), 'bytemash-sync');
    }
    
    /**
     * Reschedule from settings
     */
    public function reschedule_from_settings() {
        if (!$this->is_scheduling_enabled()) {
            $this->clear_schedules();
            $this->logger->log('info', 'Reschedule skipped: scheduling disabled. Cleared any existing schedules.', array(), 'action_scheduler');
            return;
        }
        $full_sync_interval = get_option('bytemash_amrod_full_sync_interval', 'daily');
        $incremental_sync_interval = get_option('bytemash_amrod_incremental_sync_interval', 'hourly');
        
        $this->schedule_full_sync($full_sync_interval);
        $this->schedule_incremental_sync($incremental_sync_interval);
        
        $this->logger->log('info', "Schedules rescheduled from settings", array(
            'full_sync_interval' => $full_sync_interval,
            'incremental_sync_interval' => $incremental_sync_interval,
        ), 'action_scheduler');
    }
    
    /**
     * Run full sync action
     */
    public function run_full_sync_action($with_branding = true) {
        if (!$this->is_scheduling_enabled()) {
            $this->logger->log('warning', 'Full sync run aborted: scheduling disabled', array(), 'action_scheduler');
            return;
        }
        $this->logger->log('info', 'Action Scheduler full sync started', array(
            'with_branding' => $with_branding,
        ), 'action_scheduler');
        
        $this->logger->log('info', 'Production full sync triggered via Action Scheduler', array(
            'phases' => $phases,
            'with_branding' => $with_branding,
        ), 'full_sync');
        
        try {
            // Get enabled sync attributes
            $sync_products = get_option('bytemash_sync_products', true);
            $sync_stock = get_option('bytemash_sync_stock', true);
            $sync_prices = get_option('bytemash_sync_prices', true);
            $sync_categories = get_option('bytemash_sync_categories', true);
            $sync_brands = get_option('bytemash_sync_brands', true);
            
            // Build phase queue
            $phases = array();
            if ($sync_products) $phases[] = 'products';
            if ($sync_stock) $phases[] = 'stock';
            if ($sync_prices) $phases[] = 'prices';
            if ($sync_categories) $phases[] = 'categories';
            if ($sync_brands) $phases[] = 'brands';
            
            $this->logger->log('info', "Action Scheduler full sync phases enabled (queue order)", array(
                'phases' => $phases,
                'with_branding' => $with_branding,
            ), 'action_scheduler');
            
            // Store phase queue for sequential processing
            $sync_id = 'full_' . time() . '_' . wp_generate_password(8, false);
            set_transient("bytemash_as_sync_{$sync_id}_phases", $phases, 24 * HOUR_IN_SECONDS);
            set_transient("bytemash_as_sync_{$sync_id}_with_branding", $with_branding, 24 * HOUR_IN_SECONDS);
            set_transient("bytemash_as_sync_{$sync_id}_current_phase", 0, 24 * HOUR_IN_SECONDS);
            
            // Start first phase
            if (!empty($phases)) {
                $this->run_phase_action($sync_id, $phases[0], $with_branding);
            }
            
        } catch (Exception $e) {
            $this->logger->log('error', 'Action Scheduler full sync failed', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ), 'action_scheduler');
        }
    }
    
    /**
     * Run a specific phase
     */
    private function run_phase_action($sync_id, $phase, $with_branding) {
        $this->logger->log('info', "Starting phase: {$phase}", array('sync_id' => $sync_id, 'phase' => $phase), 'action_scheduler');
        
        switch ($phase) {
            case 'products':
                $api_client = new ByteMash_Amrod_API_Client();
                if ($with_branding) {
                    $products = $api_client->get_products_with_branding();
                } else {
                    $products = $api_client->get_products_without_branding();
                }
                
                if (is_wp_error($products)) {
                    $this->logger->log('error', 'Failed to fetch products', array('error' => $products->get_error_message()), 'action_scheduler');
                    $this->schedule_next_phase($sync_id);
                } elseif (is_array($products) && !empty($products)) {
                    $total = count($products);
                    $batch_size = (int) get_option('bytemash_amrod_batch_size', 10);
                    $this->logger->log('info', "Processing {$total} products in batches of {$batch_size}", array(), 'action_scheduler');
                    $this->schedule_batch_processing($products, 'full', $with_branding, $sync_id, 'products');
                } else {
                    $this->logger->log('warning', 'No products found', array(), 'action_scheduler');
                    $this->schedule_next_phase($sync_id);
                }
                break;
                
            case 'stock':
                $this->logger->log('info', "Fetching stock data for stock sync phase", array('sync_id' => $sync_id), 'action_scheduler');
                try {
                    $stock_result = $this->product_sync->sync_stock_levels();
                    
                    if (!$stock_result['success']) {
                        $this->logger->log('error', "Stock sync phase failed: API fetch unsuccessful", array(
                            'sync_id' => $sync_id,
                            'error_message' => $stock_result['message'] ?? 'Unknown error',
                            'result' => $stock_result,
                        ), 'action_scheduler');
                        $this->schedule_next_phase($sync_id);
                    } elseif (empty($stock_result['data'])) {
                        $this->logger->log('warning', "Stock sync phase: No stock data returned", array(
                            'sync_id' => $sync_id,
                            'result' => $stock_result,
                        ), 'action_scheduler');
                        $this->schedule_next_phase($sync_id);
                    } else {
                        $this->logger->log('info', "Stock data fetched successfully, scheduling batches", array(
                            'sync_id' => $sync_id,
                            'stock_sync_id' => $stock_result['sync_id'],
                            'total_items' => count($stock_result['data']),
                        ), 'action_scheduler');
                        $this->schedule_stock_sync_action($stock_result['data'], $stock_result['sync_id'], $sync_id, 'stock');
                    }
                } catch (Exception $e) {
                    $this->logger->log('error', "Stock sync phase exception", array(
                        'sync_id' => $sync_id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ), 'action_scheduler');
                    $this->schedule_next_phase($sync_id);
                }
                break;
                
            case 'prices':
                $this->logger->log('info', "Fetching prices data for prices sync phase", array('sync_id' => $sync_id), 'action_scheduler');
                try {
                    $prices_result = $this->product_sync->sync_prices();
                    
                    if (!$prices_result['success']) {
                        $this->logger->log('error', "Prices sync phase failed: API fetch unsuccessful", array(
                            'sync_id' => $sync_id,
                            'error_message' => $prices_result['message'] ?? 'Unknown error',
                            'result' => $prices_result,
                        ), 'action_scheduler');
                        $this->schedule_next_phase($sync_id);
                    } elseif (empty($prices_result['data'])) {
                        $this->logger->log('warning', "Prices sync phase: No prices data returned", array(
                            'sync_id' => $sync_id,
                            'result' => $prices_result,
                        ), 'action_scheduler');
                        $this->schedule_next_phase($sync_id);
                    } else {
                        $this->logger->log('info', "Prices data fetched successfully, scheduling batches", array(
                            'sync_id' => $sync_id,
                            'prices_sync_id' => $prices_result['sync_id'],
                            'total_items' => count($prices_result['data']),
                        ), 'action_scheduler');
                        $this->schedule_prices_sync_action($prices_result['data'], $prices_result['sync_id'], $sync_id, 'prices');
                    }
                } catch (Exception $e) {
                    $this->logger->log('error', "Prices sync phase exception", array(
                        'sync_id' => $sync_id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ), 'action_scheduler');
                    $this->schedule_next_phase($sync_id);
                }
                break;
                
            case 'categories':
                $categories_result = $this->product_sync->sync_categories();
                if ($categories_result['success'] && !empty($categories_result['tree'])) {
                    $batch_processor = new ByteMash_Batch_Processor();
                    $batch_processor->process_categories_batch($categories_result['tree']);
                    $this->logger->log('info', "Categories processed", array(), 'action_scheduler');
                }
                $this->schedule_next_phase($sync_id);
                break;
                
            case 'brands':
                $this->logger->log('info', "Fetching brands data for brands sync phase", array('sync_id' => $sync_id), 'action_scheduler');
                try {
                    $brands_result = $this->product_sync->sync_brands();
                    
                    if (!$brands_result['success']) {
                        $this->logger->log('error', "Brands sync phase failed: API fetch unsuccessful", array(
                            'sync_id' => $sync_id,
                            'error_message' => $brands_result['message'] ?? 'Unknown error',
                            'result' => $brands_result,
                        ), 'action_scheduler');
                        $this->schedule_next_phase($sync_id);
                    } elseif (empty($brands_result['data'])) {
                        $this->logger->log('warning', "Brands sync phase: No brands data returned", array(
                            'sync_id' => $sync_id,
                            'result' => $brands_result,
                        ), 'action_scheduler');
                        $this->schedule_next_phase($sync_id);
                    } else {
                        $this->logger->log('info', "Brands data fetched successfully, scheduling batches", array(
                            'sync_id' => $sync_id,
                            'brands_sync_id' => $brands_result['sync_id'],
                            'total_items' => count($brands_result['data']),
                        ), 'action_scheduler');
                        $this->schedule_brands_sync_action($brands_result['data'], $brands_result['sync_id'], $sync_id, 'brands');
                    }
                } catch (Exception $e) {
                    $this->logger->log('error', "Brands sync phase exception", array(
                        'sync_id' => $sync_id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ), 'action_scheduler');
                    $this->schedule_next_phase($sync_id);
                }
                break;
        }
    }
    
    /**
     * Schedule next phase after current phase completes
     */
    private function schedule_next_phase($sync_id) {
        try {
            $phases = get_transient("bytemash_as_sync_{$sync_id}_phases");
            $current_index = (int) get_transient("bytemash_as_sync_{$sync_id}_current_phase");
            
            if (!$phases || !is_array($phases)) {
                $this->logger->log('info', "All phases completed for sync {$sync_id}", array(), 'action_scheduler');
                return;
            }
            
            $next_index = $current_index + 1;
            
            if ($next_index >= count($phases)) {
                $completed_at = current_time('mysql');
                
                $this->logger->log('success', "All phases completed for sync {$sync_id}", array(
                    'sync_id' => $sync_id,
                    'total_phases' => count($phases),
                    'completed_at' => $completed_at,
                ), 'action_scheduler');
                
                update_option('bytemash_last_full_sync', $completed_at);
                
                $this->logger->log('success', 'Daily production full sync completed via Action Scheduler', array(
                    'sync_id' => $sync_id,
                    'completed_at' => $completed_at,
                ), 'full_sync');
                
                delete_transient("bytemash_as_sync_{$sync_id}_phases");
                delete_transient("bytemash_as_sync_{$sync_id}_with_branding");
                delete_transient("bytemash_as_sync_{$sync_id}_current_phase");
                return;
            }
            
            $with_branding = get_transient("bytemash_as_sync_{$sync_id}_with_branding");
            if ($with_branding === false) {
                $this->logger->log('warning', "Could not retrieve with_branding setting for next phase", array(
                    'sync_id' => $sync_id,
                ), 'action_scheduler');
                $with_branding = true; // Default fallback
            }
            
            set_transient("bytemash_as_sync_{$sync_id}_current_phase", $next_index, 24 * HOUR_IN_SECONDS);
            
            $next_phase = $phases[$next_index];
            if (!isset($next_phase)) {
                $this->logger->log('error', "Next phase not found in phases array", array(
                    'sync_id' => $sync_id,
                    'next_index' => $next_index,
                    'total_phases' => count($phases),
                    'phases' => $phases,
                ), 'action_scheduler');
                return;
            }
            
            $this->logger->log('info', "Scheduling next phase: {$next_phase}", array(
                'sync_id' => $sync_id,
                'phase_index' => $next_index,
                'current_phase_index' => $current_index,
                'total_phases' => count($phases),
            ), 'action_scheduler');
            
            // Schedule next phase immediately (or with small delay)
            $scheduled = as_schedule_single_action(
                time(),
                'bytemash_action_scheduler_phase_complete',
                array($sync_id, $next_phase),
                'bytemash-sync'
            );
            
            if (!$scheduled) {
                $this->logger->log('error', "Failed to schedule next phase", array(
                    'sync_id' => $sync_id,
                    'next_phase' => $next_phase,
                ), 'action_scheduler');
            }
            
        } catch (Exception $e) {
            $this->logger->log('error', "Exception while scheduling next phase", array(
                'sync_id' => $sync_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ), 'action_scheduler');
        }
    }
    
    /**
     * Run next phase after previous completes
     */
    public function run_next_phase_action($sync_id, $phase) {
        $this->logger->log('info', "Running next phase action", array(
            'sync_id' => $sync_id,
            'phase' => $phase,
        ), 'action_scheduler');
        
        try {
            $with_branding = get_transient("bytemash_as_sync_{$sync_id}_with_branding");
            if ($with_branding === false) {
                $this->logger->log('warning', "Could not retrieve with_branding setting, using default", array(
                    'sync_id' => $sync_id,
                    'phase' => $phase,
                ), 'action_scheduler');
                $with_branding = true; // Default fallback
            }
            
            $this->run_phase_action($sync_id, $phase, $with_branding);
            
        } catch (Exception $e) {
            $this->logger->log('error', "Exception while running next phase", array(
                'sync_id' => $sync_id,
                'phase' => $phase,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ), 'action_scheduler');
        }
    }
    
    /**
     * Run incremental sync action
     */
    public function run_incremental_sync_action($with_branding = true) {
        if (!$this->is_scheduling_enabled()) {
            $this->logger->log('warning', 'Incremental sync run aborted: scheduling disabled', array(), 'action_scheduler');
            return;
        }
        $this->logger->log('info', 'Action Scheduler incremental sync started', array(
            'with_branding' => $with_branding,
        ), 'action_scheduler');
        
        try {
            // Get enabled sync attributes (incremental only syncs products, stock, and prices)
            $sync_products = get_option('bytemash_sync_products', true);
            $sync_stock = get_option('bytemash_sync_stock', true);
            $sync_prices = get_option('bytemash_sync_prices', true);
            
            $this->logger->log('info', "Action Scheduler incremental sync phases enabled", array(
                'products' => $sync_products,
                'stock' => $sync_stock,
                'prices' => $sync_prices,
            ), 'action_scheduler');
            
            // Products sync
            if ($sync_products) {
                $api_client = new ByteMash_Amrod_API_Client();
                if ($with_branding) {
                    $products = $api_client->get_products_with_branding_updated();
                } else {
                    $products = $api_client->get_products_without_branding_updated();
                }
                
                if (is_wp_error($products)) {
                    $this->logger->log('error', 'Failed to fetch updated products for Action Scheduler incremental sync', array(
                        'error' => $products->get_error_message(),
                    ), 'action_scheduler');
                } elseif (is_array($products) && !empty($products)) {
                    $total = count($products);
                    $batch_size = (int) get_option('bytemash_amrod_batch_size', 10);
                    $this->logger->log('info', "Processing {$total} updated products in batches of {$batch_size}", array(), 'action_scheduler');
                    $this->schedule_batch_processing($products, 'incremental', $with_branding);
                } else {
                    $this->logger->log('info', 'No updated products found for Action Scheduler incremental sync', array(), 'action_scheduler');
                }
            }
            
            // Stock sync (incremental, via Action Scheduler)
            if ($sync_stock) {
                $this->logger->log('info', "Starting incremental stock sync", array(), 'action_scheduler');
                $stock_result = $this->product_sync->sync_stock_updated();
                
                if ($stock_result['success'] && !empty($stock_result['data'])) {
                    $this->schedule_stock_sync_action($stock_result['data'], $stock_result['sync_id']);
                    $this->logger->log('info', "Stock sync scheduled with Action Scheduler", array('sync_id' => $stock_result['sync_id']), 'action_scheduler');
                }
            }
            
            // Prices sync (incremental, via Action Scheduler)
            if ($sync_prices) {
                $this->logger->log('info', "Starting incremental prices sync", array(), 'action_scheduler');
                $prices_result = $this->product_sync->sync_prices_updated();
                
                if ($prices_result['success'] && !empty($prices_result['data'])) {
                    $this->schedule_prices_sync_action($prices_result['data'], $prices_result['sync_id']);
                    $this->logger->log('info', "Prices sync scheduled with Action Scheduler", array('sync_id' => $prices_result['sync_id']), 'action_scheduler');
                }
            }
            
            $this->logger->log('success', 'Action Scheduler incremental sync completed', array(), 'action_scheduler');
            
            $completed_at = current_time('mysql');
            update_option('bytemash_last_incremental_sync', $completed_at);
            $this->logger->log('success', 'Incremental sync timestamp updated (Action Scheduler)', array(
                'completed_at' => $completed_at,
            ), 'incremental_sync');
            
        } catch (Exception $e) {
            $this->logger->log('error', 'Action Scheduler incremental sync failed', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ), 'action_scheduler');
        }
    }
    
    /**
     * Schedule batch processing using Action Scheduler
     */
    private function schedule_batch_processing($products, $sync_type, $with_branding, $phase_sync_id = null, $phase_name = null) {
        $batch_size = (int) get_option('bytemash_amrod_batch_size', 10);
        $batches = array_chunk($products, $batch_size);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Scheduling {$batch_count} batches for {$sync_type} sync", array(
            'sync_type' => $sync_type,
            'batch_count' => $batch_count,
            'batch_size' => $batch_size,
        ), 'action_scheduler');
        
        // Store products temporarily for batch processing
        $sync_id = $sync_type . '_' . uniqid();
        set_transient("bytemash_action_scheduler_{$sync_id}_products", $products, 12 * HOUR_IN_SECONDS);
        
        // Store phase sync info if part of a queue
        if ($phase_sync_id) {
            set_transient("bytemash_as_products_{$sync_id}_phase_sync", $phase_sync_id, 24 * HOUR_IN_SECONDS);
        }
        
        // Schedule each batch with a small delay to prevent overwhelming the system
        foreach ($batches as $batch_index => $batch) {
            $delay = $batch_index * 30; // 30 seconds between batches
            
            as_schedule_single_action(
                time() + $delay,
                'bytemash_action_scheduler_batch_sync',
                array($sync_id, $batch_index, $with_branding),
                'bytemash-sync'
            );
        }
        
        // Schedule cleanup after all batches are processed (only if not in phase queue)
        if (!$phase_sync_id) {
            $cleanup_delay = ($batch_count * 30) + 300; // 5 minutes after last batch
            as_schedule_single_action(
                time() + $cleanup_delay,
                'bytemash_action_scheduler_cleanup',
                array($sync_id),
                'bytemash-sync'
            );
        }
    }
    
    /**
     * Schedule stock sync using Action Scheduler
     */
    private function schedule_stock_sync_action($stock_data, $sync_id, $phase_sync_id = null, $phase_name = null) {
        if (!is_array($stock_data)) {
            $this->logger->log('error', "Stock sync scheduling failed: Invalid data type", array(
                'sync_id' => $sync_id,
                'phase_sync_id' => $phase_sync_id,
                'data_type' => gettype($stock_data),
            ), 'action_scheduler');
            if ($phase_sync_id) {
                $this->schedule_next_phase($phase_sync_id);
            }
            return false;
        }
        
        if (empty($stock_data)) {
            $this->logger->log('warning', "Stock sync scheduling skipped: Empty stock data", array(
                'sync_id' => $sync_id,
                'phase_sync_id' => $phase_sync_id,
            ), 'action_scheduler');
            if ($phase_sync_id) {
                $this->schedule_next_phase($phase_sync_id);
            }
            return false;
        }
        
        $total = count($stock_data);
        $batches = array_chunk($stock_data, 100);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Scheduling {$batch_count} stock batches with Action Scheduler", array(
            'sync_id' => $sync_id,
            'total' => $total,
            'batch_count' => $batch_count,
        ), 'action_scheduler');
        
        // Cache stock data temporarily
        set_transient("bytemash_sync_{$sync_id}_stock", $stock_data, 12 * HOUR_IN_SECONDS);
        
        // Set up progress tracking
        $batch_processor = new ByteMash_Batch_Processor();
        $batch_processor->save_sync_progress($sync_id, array(
            'type' => 'stock',
            'total' => $total,
            'processed' => 0,
            'batch_count' => $batch_count,
            'batch_size' => 100,
            'current_batch' => 0,
            'status' => 'scheduled',
            'started' => current_time('mysql'),
        ));
        
        // Store phase sync info if part of a queue
        if ($phase_sync_id) {
            set_transient("bytemash_as_stock_{$sync_id}_phase_sync", $phase_sync_id, 24 * HOUR_IN_SECONDS);
        }
        
        // Schedule each batch with Action Scheduler
        $scheduled_count = 0;
        foreach ($batches as $batch_index => $batch) {
            $delay = $batch_index * 3; // 3 seconds between batches
            
            $scheduled = as_schedule_single_action(
                time() + $delay,
                'bytemash_action_scheduler_stock_batch',
                array($sync_id, $batch_index),
                'bytemash-sync'
            );
            
            if ($scheduled) {
                $scheduled_count++;
            } else {
                $this->logger->log('error', "Failed to schedule stock batch", array(
                    'sync_id' => $sync_id,
                    'batch_index' => $batch_index,
                ), 'action_scheduler');
            }
        }
        
        $this->logger->log('info', "Stock batches scheduled", array(
            'sync_id' => $sync_id,
            'total_batches' => $batch_count,
            'scheduled_count' => $scheduled_count,
        ), 'action_scheduler');
        
        return true;
    }
    
    /**
     * Schedule prices sync using Action Scheduler
     */
    private function schedule_prices_sync_action($prices_data, $sync_id, $phase_sync_id = null, $phase_name = null) {
        if (!is_array($prices_data)) {
            $this->logger->log('error', "Prices sync scheduling failed: Invalid data type", array(
                'sync_id' => $sync_id,
                'phase_sync_id' => $phase_sync_id,
                'data_type' => gettype($prices_data),
            ), 'action_scheduler');
            if ($phase_sync_id) {
                $this->schedule_next_phase($phase_sync_id);
            }
            return false;
        }
        
        if (empty($prices_data)) {
            $this->logger->log('warning', "Prices sync scheduling skipped: Empty prices data", array(
                'sync_id' => $sync_id,
                'phase_sync_id' => $phase_sync_id,
            ), 'action_scheduler');
            if ($phase_sync_id) {
                $this->schedule_next_phase($phase_sync_id);
            }
            return false;
        }
        
        $total = count($prices_data);
        $batches = array_chunk($prices_data, 500);
        $batch_count = count($batches);
        
        $this->logger->log('info', "⚡ Scheduling {$batch_count} OPTIMIZED price batches with Action Scheduler", array(
            'sync_id' => $sync_id,
            'total' => $total,
            'batch_count' => $batch_count,
        ), 'action_scheduler');
        
        // Cache prices data temporarily
        set_transient("bytemash_sync_{$sync_id}_prices", $prices_data, 12 * HOUR_IN_SECONDS);
        
        // Set up progress tracking
        $batch_processor = new ByteMash_Batch_Processor();
        $batch_processor->save_sync_progress($sync_id, array(
            'type' => 'prices',
            'total' => $total,
            'processed' => 0,
            'batch_count' => $batch_count,
            'current_batch' => 0,
            'status' => 'scheduled',
            'started' => current_time('mysql'),
        ));
        
        // Store phase sync info if part of a queue
        if ($phase_sync_id) {
            set_transient("bytemash_as_prices_{$sync_id}_phase_sync", $phase_sync_id, 24 * HOUR_IN_SECONDS);
        }
        
        // Schedule each batch with Action Scheduler
        foreach ($batches as $batch_index => $batch) {
            $delay = $batch_index * 2; // 2 seconds between batches
            
            as_schedule_single_action(
                time() + $delay,
                'bytemash_action_scheduler_prices_batch',
                array($sync_id, $batch_index),
                'bytemash-sync'
            );
        }
        
        return true;
    }
    
    /**
     * Schedule brands sync using Action Scheduler
     */
    private function schedule_brands_sync_action($brands_data, $sync_id, $phase_sync_id = null, $phase_name = null) {
        if (!is_array($brands_data)) {
            $this->logger->log('error', "Brands sync scheduling failed: Invalid data type", array(
                'sync_id' => $sync_id,
                'phase_sync_id' => $phase_sync_id,
                'data_type' => gettype($brands_data),
            ), 'action_scheduler');
            if ($phase_sync_id) {
                $this->schedule_next_phase($phase_sync_id);
            }
            return false;
        }
        
        if (empty($brands_data)) {
            $this->logger->log('warning', "Brands sync scheduling skipped: Empty brands data", array(
                'sync_id' => $sync_id,
                'phase_sync_id' => $phase_sync_id,
            ), 'action_scheduler');
            if ($phase_sync_id) {
                $this->schedule_next_phase($phase_sync_id);
            }
            return false;
        }
        
        $total = count($brands_data);
        $batches = array_chunk($brands_data, 50);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Scheduling {$batch_count} brand batches with Action Scheduler", array(
            'sync_id' => $sync_id,
            'total' => $total,
            'batch_count' => $batch_count,
        ), 'action_scheduler');
        
        // Cache brands data temporarily
        set_transient("bytemash_sync_{$sync_id}_brands", $brands_data, 12 * HOUR_IN_SECONDS);
        
        // Set up progress tracking
        $batch_processor = new ByteMash_Batch_Processor();
        $batch_processor->save_sync_progress($sync_id, array(
            'type' => 'brands',
            'total' => $total,
            'processed' => 0,
            'batch_count' => $batch_count,
            'current_batch' => 0,
            'status' => 'scheduled',
            'started' => current_time('mysql'),
        ));
        
        // Store phase sync info if part of a queue
        if ($phase_sync_id) {
            set_transient("bytemash_as_brands_{$sync_id}_phase_sync", $phase_sync_id, 24 * HOUR_IN_SECONDS);
        }
        
        // Schedule each batch with Action Scheduler
        foreach ($batches as $batch_index => $batch) {
            $delay = $batch_index * 2; // 2 seconds between batches
            
            as_schedule_single_action(
                time() + $delay,
                'bytemash_action_scheduler_brands_batch',
                array($sync_id, $batch_index),
                'bytemash-sync'
            );
        }
        
        return true;
    }
    
    /**
     * Run stock batch action
     */
    public function run_stock_batch_action($sync_id, $batch_index) {
        $this->logger->log('info', "Processing stock batch", array(
            'sync_id' => $sync_id,
            'batch_index' => $batch_index,
        ), 'action_scheduler');
        
        try {
            $batch_processor = new ByteMash_Batch_Processor();
            $progress_before = $batch_processor->get_sync_progress($sync_id);
            
            if (!$progress_before) {
                $this->logger->log('error', "Stock batch processing failed: No progress data found", array(
                    'sync_id' => $sync_id,
                    'batch_index' => $batch_index,
                ), 'action_scheduler');
                return;
            }
            
            $batch_processor->process_stock_batch($sync_id, $batch_index);
            
            // Check if this is part of a phase queue
            $phase_sync_id = get_transient("bytemash_as_stock_{$sync_id}_phase_sync");
            
            // Get updated progress after processing
            $progress = $batch_processor->get_sync_progress($sync_id);
            
            if (!$progress) {
                $this->logger->log('error', "Stock batch processing failed: Progress lost after processing", array(
                    'sync_id' => $sync_id,
                    'batch_index' => $batch_index,
                ), 'action_scheduler');
                return;
            }
            
            if (!isset($progress['batch_count'])) {
                $this->logger->log('error', "Stock batch processing failed: Missing batch_count in progress", array(
                    'sync_id' => $sync_id,
                    'batch_index' => $batch_index,
                    'progress' => $progress,
                ), 'action_scheduler');
                return;
            }
            
            $this->logger->log('info', "Stock batch processed", array(
                'sync_id' => $sync_id,
                'batch_index' => $batch_index,
                'processed' => $progress['processed'] ?? 0,
                'errors' => $progress['errors'] ?? 0,
                'batch_count' => $progress['batch_count'],
            ), 'action_scheduler');
            
            // Schedule next batch via Action Scheduler if needed
            if (($batch_index + 1) < $progress['batch_count']) {
                $next_batch = $batch_index + 1;
                $this->logger->log('info', "Scheduling next stock batch", array(
                    'sync_id' => $sync_id,
                    'next_batch' => $next_batch,
                ), 'action_scheduler');
                
                as_schedule_single_action(
                    time() + 3,
                    'bytemash_action_scheduler_stock_batch',
                    array($sync_id, $next_batch),
                    'bytemash-sync'
                );
            } else {
                // Last batch - schedule next phase if in queue
                $this->logger->log('success', "Stock sync phase completed", array(
                    'sync_id' => $sync_id,
                    'total_processed' => $progress['processed'] ?? 0,
                    'total_errors' => $progress['errors'] ?? 0,
                ), 'action_scheduler');
                
                if ($phase_sync_id) {
                    $this->schedule_next_phase($phase_sync_id);
                    delete_transient("bytemash_as_stock_{$sync_id}_phase_sync");
                }
            }
            
        } catch (Exception $e) {
            $this->logger->log('error', "Stock batch processing exception", array(
                'sync_id' => $sync_id,
                'batch_index' => $batch_index,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ), 'action_scheduler');
        }
    }
    
    /**
     * Run prices batch action
     */
    public function run_prices_batch_action($sync_id, $batch_index) {
        $this->logger->log('info', "Processing prices batch", array(
            'sync_id' => $sync_id,
            'batch_index' => $batch_index,
        ), 'action_scheduler');
        
        try {
            $batch_processor = new ByteMash_Batch_Processor();
            $progress_before = $batch_processor->get_sync_progress($sync_id);
            
            if (!$progress_before) {
                $this->logger->log('error', "Prices batch processing failed: No progress data found", array(
                    'sync_id' => $sync_id,
                    'batch_index' => $batch_index,
                ), 'action_scheduler');
                return;
            }
            
            $batch_processor->process_prices_batch($sync_id, $batch_index);
            
            // Check if this is part of a phase queue
            $phase_sync_id = get_transient("bytemash_as_prices_{$sync_id}_phase_sync");
            
            // Get updated progress after processing
            $progress = $batch_processor->get_sync_progress($sync_id);
            
            if (!$progress || !isset($progress['batch_count'])) {
                $this->logger->log('error', "Prices batch processing failed: Progress lost or invalid", array(
                    'sync_id' => $sync_id,
                    'batch_index' => $batch_index,
                    'progress' => $progress,
                ), 'action_scheduler');
                return;
            }
            
            // Schedule next batch via Action Scheduler if needed
            if (($batch_index + 1) < $progress['batch_count']) {
                $next_batch = $batch_index + 1;
                as_schedule_single_action(
                    time() + 2,
                    'bytemash_action_scheduler_prices_batch',
                    array($sync_id, $next_batch),
                    'bytemash-sync'
                );
            } else {
                // Last batch - schedule next phase if in queue
                $this->logger->log('success', "Prices sync phase completed", array(
                    'sync_id' => $sync_id,
                    'total_processed' => $progress['processed'] ?? 0,
                    'total_errors' => $progress['errors'] ?? 0,
                ), 'action_scheduler');
                
                if ($phase_sync_id) {
                    $this->schedule_next_phase($phase_sync_id);
                    delete_transient("bytemash_as_prices_{$sync_id}_phase_sync");
                }
            }
            
        } catch (Exception $e) {
            $this->logger->log('error', "Prices batch processing exception", array(
                'sync_id' => $sync_id,
                'batch_index' => $batch_index,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ), 'action_scheduler');
        }
    }
    
    /**
     * Run brands batch action
     */
    public function run_brands_batch_action($sync_id, $batch_index) {
        $this->logger->log('info', "Processing brands batch", array(
            'sync_id' => $sync_id,
            'batch_index' => $batch_index,
        ), 'action_scheduler');
        
        try {
            $batch_processor = new ByteMash_Batch_Processor();
            $progress_before = $batch_processor->get_sync_progress($sync_id);
            
            if (!$progress_before) {
                $this->logger->log('error', "Brands batch processing failed: No progress data found", array(
                    'sync_id' => $sync_id,
                    'batch_index' => $batch_index,
                ), 'action_scheduler');
                return;
            }
            
            $batch_processor->process_brands_batch($sync_id, $batch_index);
            
            // Check if this is part of a phase queue
            $phase_sync_id = get_transient("bytemash_as_brands_{$sync_id}_phase_sync");
            
            // Get updated progress after processing
            $progress = $batch_processor->get_sync_progress($sync_id);
            
            if (!$progress || !isset($progress['batch_count'])) {
                $this->logger->log('error', "Brands batch processing failed: Progress lost or invalid", array(
                    'sync_id' => $sync_id,
                    'batch_index' => $batch_index,
                    'progress' => $progress,
                ), 'action_scheduler');
                return;
            }
            
            // Schedule next batch via Action Scheduler if needed
            if (($batch_index + 1) < $progress['batch_count']) {
                $next_batch = $batch_index + 1;
                as_schedule_single_action(
                    time() + 2,
                    'bytemash_action_scheduler_brands_batch',
                    array($sync_id, $next_batch),
                    'bytemash-sync'
                );
            } else {
                // Last batch - schedule next phase if in queue
                $this->logger->log('success', "Brands sync phase completed", array(
                    'sync_id' => $sync_id,
                    'total_processed' => $progress['processed'] ?? 0,
                    'total_errors' => $progress['errors'] ?? 0,
                ), 'action_scheduler');
                
                if ($phase_sync_id) {
                    $this->schedule_next_phase($phase_sync_id);
                    delete_transient("bytemash_as_brands_{$sync_id}_phase_sync");
                }
            }
            
        } catch (Exception $e) {
            $this->logger->log('error', "Brands batch processing exception", array(
                'sync_id' => $sync_id,
                'batch_index' => $batch_index,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ), 'action_scheduler');
        }
    }
    
    /**
     * Run batch sync action
     */
    public function run_batch_sync_action($sync_id, $batch_index, $with_branding) {
        $this->logger->log('info', "Processing batch {$batch_index} for sync {$sync_id}", array(
            'sync_id' => $sync_id,
            'batch_index' => $batch_index,
            'with_branding' => $with_branding,
        ), 'action_scheduler');
        
		// Allow mid-run stop by honoring disabled option
		$products_sync_enabled = get_option('bytemash_sync_products', true);
		if (!$products_sync_enabled) {
			$this->logger->log('warning', 'Products sync is disabled. Aborting Action Scheduler batch.', array(
				'sync_id' => $sync_id,
				'batch_index' => $batch_index,
			), 'action_scheduler');
			return;
		}
		
        try {
            // Get the stored products
            $products = get_transient("bytemash_action_scheduler_{$sync_id}_products");
            if (!$products) {
                $this->logger->log('warning', "Products not found for sync {$sync_id} - possible transient expiration, attempting fallback", array(
                    'sync_id' => $sync_id,
                ), 'action_scheduler');
                
                // FALLBACK: Try to re-fetch products from API
                $products = $this->refetch_products_for_sync($sync_id);
                if (!$products) {
                    $this->logger->log('error', "Failed to refetch products for sync {$sync_id}", array(
                        'sync_id' => $sync_id,
                    ), 'action_scheduler');
                    return;
                }
                
                // Re-store the products with extended lifetime
                set_transient("bytemash_action_scheduler_{$sync_id}_products", $products, 12 * HOUR_IN_SECONDS);
                $this->logger->log('info', "Successfully refetched and restored products for sync {$sync_id}", array(
                    'sync_id' => $sync_id,
                    'product_count' => count($products),
                ), 'action_scheduler');
            }
            
            $batch_size = (int) get_option('bytemash_amrod_batch_size', 10);
            $start_index = $batch_index * $batch_size;
            $batch = array_slice($products, $start_index, $batch_size);
            
            if (empty($batch)) {
                $this->logger->log('warning', "Empty batch {$batch_index} for sync {$sync_id}", array(
                    'sync_id' => $sync_id,
                    'batch_index' => $batch_index,
                ), 'action_scheduler');
                return;
            }
            
            $processed = 0;
            $errors = 0;
            
            // Process each product in the batch
			foreach ($batch as $product_data) {
				// Re-check flag to stop mid-batch if disabled
				if (!get_option('bytemash_sync_products', true)) {
					$this->logger->log('warning', 'Products sync disabled during batch. Stopping further processing.', array(
						'sync_id' => $sync_id,
						'batch_index' => $batch_index,
					), 'action_scheduler');
					break;
				}
                try {
                    $result = $this->product_sync->sync_single_product($product_data, false);
                    
                    if ($result['success']) {
                        $processed++;
                        $this->logger->log('info', 'Product synced successfully in Action Scheduler batch', array(
                            'sku' => $product_data['fullCode'] ?? 'unknown',
                            'product_name' => $product_data['productName'] ?? 'unknown',
                        ), 'action_scheduler');
                    } else {
                        $errors++;
                        $this->logger->log('warning', 'Product sync failed in Action Scheduler batch', array(
                            'sku' => $product_data['fullCode'] ?? 'unknown',
                            'product_name' => $product_data['productName'] ?? 'unknown',
                            'reason' => $result['message'] ?? 'Unknown reason',
                        ), 'action_scheduler');
                    }
                } catch (Exception $e) {
                    $errors++;
                    $this->logger->log('error', 'Product sync exception in Action Scheduler batch', array(
                        'sku' => $product_data['fullCode'] ?? 'unknown',
                        'product_name' => $product_data['productName'] ?? 'unknown',
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ), 'action_scheduler');
                }
            }
            
            $this->logger->log('info', "Batch {$batch_index} completed for sync {$sync_id}", array(
                'processed' => $processed,
                'errors' => $errors,
                'batch_size' => count($batch),
            ), 'action_scheduler');
            
            // Check if this is the last batch and part of a phase queue
            $batch_size = (int) get_option('bytemash_amrod_batch_size', 10);
            $total_batches = ceil(count($products) / $batch_size);
            $is_last_batch = ($batch_index + 1) >= $total_batches;
            
            if ($is_last_batch) {
                $phase_sync_id = get_transient("bytemash_as_products_{$sync_id}_phase_sync");
                if ($phase_sync_id) {
                    $this->logger->log('info', "Products phase completed, scheduling next phase", array('phase_sync_id' => $phase_sync_id), 'action_scheduler');
                    $this->schedule_next_phase($phase_sync_id);
                    delete_transient("bytemash_as_products_{$sync_id}_phase_sync");
                }
            }

        } catch (Exception $e) {
            $this->logger->log('error', "Batch {$batch_index} failed for sync {$sync_id}", array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ), 'action_scheduler');
        }
    }
    
    /**
     * Cleanup old syncs
     */
    public function cleanup_old_syncs($sync_id = null) {
        if ($sync_id) {
            // Cleanup specific sync
            delete_transient("bytemash_action_scheduler_{$sync_id}_products");
            $this->logger->log('info', "Cleaned up sync {$sync_id}", array(), 'action_scheduler');
        } else {
            // Cleanup all old syncs
            global $wpdb;
            $transients = $wpdb->get_results(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_bytemash_action_scheduler_%_products'"
            );
            
            foreach ($transients as $transient) {
                $option_name = str_replace('_transient_', '', $transient->option_name);
                delete_transient($option_name);
            }
            
            $this->logger->log('info', "Cleaned up old sync transients", array(
                'count' => count($transients),
            ), 'action_scheduler');
        }
    }
    
    /**
     * Get interval in seconds
     */
    private function get_interval_seconds($interval) {
        $intervals = array(
            'hourly' => HOUR_IN_SECONDS,
            'twicedaily' => 12 * HOUR_IN_SECONDS,
            'daily' => DAY_IN_SECONDS,
            'weekly' => WEEK_IN_SECONDS,
        );
        
        return isset($intervals[$interval]) ? $intervals[$interval] : DAY_IN_SECONDS;
    }
    
    /**
     * Get scheduled actions status
     */
    public function get_scheduled_actions_status() {
        $full_sync_actions = as_get_scheduled_actions(array(
            'hook' => 'bytemash_action_scheduler_full_sync',
            'status' => 'pending',
        ));
        
        $incremental_sync_actions = as_get_scheduled_actions(array(
            'hook' => 'bytemash_action_scheduler_incremental_sync',
            'status' => 'pending',
        ));
        
        $batch_actions = as_get_scheduled_actions(array(
            'hook' => 'bytemash_action_scheduler_batch_sync',
            'status' => 'pending',
        ));
        
        $next_full_sync = null;
        $next_incremental_sync = null;
        
        if (!empty($full_sync_actions) && isset($full_sync_actions[0])) {
            $schedule = $full_sync_actions[0]->get_schedule();
            if ($schedule) {
                $next_full_sync = $schedule->get_date();
            }
        }
        
        if (!empty($incremental_sync_actions) && isset($incremental_sync_actions[0])) {
            $schedule = $incremental_sync_actions[0]->get_schedule();
            if ($schedule) {
                $next_incremental_sync = $schedule->get_date();
            }
        }
        
        return array(
            'full_sync_scheduled' => count($full_sync_actions) > 0,
            'incremental_sync_scheduled' => count($incremental_sync_actions) > 0,
            'pending_batches' => count($batch_actions),
            'next_full_sync' => $next_full_sync,
            'next_incremental_sync' => $next_incremental_sync,
        );
    }
    
    /**
     * Check if Action Scheduler is available
     */
    public function is_action_scheduler_available() {
        return function_exists('as_schedule_recurring_action') && 
               function_exists('as_unschedule_all_actions') && 
               function_exists('as_get_scheduled_actions');
    }
    
    /**
     * Get Action Scheduler status
     */
    public function get_action_scheduler_status() {
        if (!$this->is_action_scheduler_available()) {
            return array(
                'available' => false,
                'message' => 'Action Scheduler not available',
            );
        }
        
        $status = $this->get_scheduled_actions_status();
        
        return array(
            'available' => true,
            'message' => 'Action Scheduler is available and working',
            'status' => $status,
        );
    }
    
    /**
     * Enable test mode for full sync (runs once after 2 minutes, then stops)
     */
    public function enable_test_mode_full_sync() {
        $this->clear_full_sync_schedules();
        
        // Disable production full sync if enabled
        update_option('bytemash_cron_production_full_sync_enabled', false);
        
        // Schedule full sync once after 2 minutes for testing (no repeating)
        as_schedule_single_action(
            time() + 120, // 2 minutes from now
            'bytemash_action_scheduler_full_sync',
            array('with_branding' => true),
            'bytemash-sync'
        );
        
        $this->logger->log('info', "Test mode full sync enabled - runs once after 2 minutes", array(), 'action_scheduler');
        
        return array(
            'success' => true,
            'message' => 'Test mode full sync enabled - runs once after 2 minutes',
            'next_run' => date('Y-m-d H:i:s', time() + 120),
        );
    }
    
    /**
     * Enable test mode for incremental sync (starts after 2 minutes, then repeats every 5 minutes)
     */
    public function enable_test_mode_incremental_sync() {
        $this->clear_incremental_sync_schedules();
        
        // Schedule first incremental sync after 2 minutes
        as_schedule_single_action(
            time() + 120, // 2 minutes from now
            'bytemash_action_scheduler_incremental_sync',
            array('with_branding' => true),
            'bytemash-sync'
        );
        
        // Then schedule recurring every 5 minutes
        as_schedule_recurring_action(
            time() + 120, // Start in 2 minutes
            300, // Then every 5 minutes
            'bytemash_action_scheduler_incremental_sync',
            array('with_branding' => true),
            'bytemash-sync'
        );
        
        $this->logger->log('info', "Test mode incremental sync enabled - starts after 2 minutes, then every 5 minutes", array(), 'action_scheduler');
        
        return array(
            'success' => true,
            'message' => 'Test mode incremental sync enabled - starts after 2 minutes, then every 5 minutes',
            'next_run' => date('Y-m-d H:i:s', time() + 120),
        );
    }
    
    /**
     * Enable production sync schedules
     * Full sync: Daily at 00:30
     * Incremental sync: Every 5 hours after first full sync
     * @deprecated Use enable_production_full_sync() instead
     */
    public function enable_production_sync() {
        // Backward compatibility: older endpoints still call this method.
        // Delegate to the new implementation so schedules and options stay in sync.
        return $this->enable_production_full_sync();
    }
    
    /**
     * Enable production full sync schedule (daily full sync only)
     * Works the same way as test mode but with production schedule
     * Only syncs attributes selected by the user
     * Uses Action Scheduler
     */
    public function enable_production_full_sync() {
        $this->clear_full_sync_schedules();
        $this->clear_incremental_sync_schedules();
        
        // Disable test mode if enabled
        update_option('bytemash_cron_full_test_mode_enabled', false);
        
        // Schedule full sync daily at 01:30 (South Africa time) - avoiding API downtime 00:00-01:00
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
        
        // Schedule full sync daily - same as test mode but recurring daily
        as_schedule_recurring_action(
            $wp_timestamp,
            DAY_IN_SECONDS, // Daily
            'bytemash_action_scheduler_full_sync',
            array('with_branding' => true),
            'bytemash-sync'
        );
        
        // Schedule incremental sync every 5 hours (starts after first full sync)
        $next_incremental = clone $next_sync;
        $next_incremental->add(new DateInterval('PT5H')); // Add 5 hours
        $incremental_wp_timestamp = $next_incremental->getTimestamp() - (get_option('gmt_offset') * HOUR_IN_SECONDS);
        
        as_schedule_recurring_action(
            $incremental_wp_timestamp,
            5 * HOUR_IN_SECONDS, // Every 5 hours
            'bytemash_action_scheduler_incremental_sync',
            array('with_branding' => true),
            'bytemash-sync'
        );
        
        // Enable the option
        update_option('bytemash_cron_production_full_sync_enabled', true);
        
        $this->logger->log('info', "Production full sync enabled - Full sync daily at 01:30, Incremental every 5 hours", array(
            'next_full_sync' => $next_sync->format('Y-m-d H:i:s'),
            'next_incremental_sync' => $next_incremental->format('Y-m-d H:i:s'),
        ), 'action_scheduler');
        
        return array(
            'success' => true,
            'message' => 'Production full sync enabled - Full sync daily at 01:30, Incremental every 5 hours',
            'next_full_sync' => $next_sync->format('Y-m-d H:i:s'),
            'next_incremental_sync' => $next_incremental->format('Y-m-d H:i:s'),
        );
    }
    
    /**
     * Disable production full sync
     */
    public function disable_production_full_sync() {
        $this->clear_full_sync_schedules();
        $this->clear_incremental_sync_schedules();
        
        // Disable the option
        update_option('bytemash_cron_production_full_sync_enabled', false);
        
        $this->logger->log('info', "Production full sync disabled (full and incremental schedules cleared)", array(), 'action_scheduler');
        
        return array(
            'success' => true,
            'message' => 'Production full sync disabled (full and incremental schedules cleared)',
        );
    }
    
    /**
     * Disable test mode and restore normal schedules
     */
    public function disable_test_mode() {
        $this->clear_schedules();
        
        $this->logger->log('info', "Test mode disabled - all schedules cleared", array(), 'action_scheduler');
        
        return array(
            'success' => true,
            'message' => 'Test mode disabled - all schedules cleared',
        );
    }
    
    /**
     * Get test mode status
     */
    public function get_test_mode_status() {
        $full_sync_actions = as_get_scheduled_actions(array(
            'hook' => 'bytemash_action_scheduler_full_sync',
            'status' => 'pending',
        ));
        
        $incremental_sync_actions = as_get_scheduled_actions(array(
            'hook' => 'bytemash_action_scheduler_incremental_sync',
            'status' => 'pending',
        ));
        
        $is_test_mode = false;
        $test_mode_details = array();
        
        if (!empty($full_sync_actions)) {
            $schedule = $full_sync_actions[0]->get_schedule();
            $interval = $schedule->get_interval();
            
            if ($interval <= 300) { // 5 minutes or less
                $is_test_mode = true;
                $test_mode_details['full_sync'] = array(
                    'interval_seconds' => $interval,
                    'interval_minutes' => round($interval / 60, 1),
                    'next_run' => $schedule->get_date()->format('Y-m-d H:i:s'),
                );
            }
        }
        
        if (!empty($incremental_sync_actions)) {
            $schedule = $incremental_sync_actions[0]->get_schedule();
            $interval = $schedule->get_interval();
            
            if ($interval <= 300) { // 5 minutes or less
                $is_test_mode = true;
                $test_mode_details['incremental_sync'] = array(
                    'interval_seconds' => $interval,
                    'interval_minutes' => round($interval / 60, 1),
                    'next_run' => $schedule->get_date()->format('Y-m-d H:i:s'),
                );
            }
        }
        
        return array(
            'is_test_mode' => $is_test_mode,
            'details' => $test_mode_details,
        );
    }
    
    /**
     * Get comprehensive sync status and progress
     */
    public function get_sync_status_and_progress() {
        // Get all scheduled actions
        $full_sync_actions = as_get_scheduled_actions(array(
            'hook' => 'bytemash_action_scheduler_full_sync',
            'status' => 'pending',
        ));
        
        $incremental_sync_actions = as_get_scheduled_actions(array(
            'hook' => 'bytemash_action_scheduler_incremental_sync',
            'status' => 'pending',
        ));
        
        $batch_actions = as_get_scheduled_actions(array(
            'hook' => 'bytemash_action_scheduler_batch_sync',
            'status' => 'pending',
        ));
        
        // Get completed actions
        $completed_actions = as_get_scheduled_actions(array(
            'hook' => array(
                'bytemash_action_scheduler_full_sync',
                'bytemash_action_scheduler_incremental_sync',
                'bytemash_action_scheduler_batch_sync',
            ),
            'status' => 'complete',
            'per_page' => 50,
        ));
        
        // Get failed actions
        $failed_actions = as_get_scheduled_actions(array(
            'hook' => array(
                'bytemash_action_scheduler_full_sync',
                'bytemash_action_scheduler_incremental_sync',
                'bytemash_action_scheduler_batch_sync',
            ),
            'status' => 'failed',
            'per_page' => 20,
        ));
        
        // Get running actions
        $running_actions = as_get_scheduled_actions(array(
            'hook' => array(
                'bytemash_action_scheduler_full_sync',
                'bytemash_action_scheduler_incremental_sync',
                'bytemash_action_scheduler_batch_sync',
            ),
            'status' => 'in-progress',
        ));
        
        // Calculate progress
        $total_scheduled = count($full_sync_actions) + count($incremental_sync_actions) + count($batch_actions);
        $total_completed = count($completed_actions);
        $total_failed = count($failed_actions);
        $total_running = count($running_actions);
        
        $progress_percentage = $total_scheduled > 0 ? min(100, round(($total_completed / $total_scheduled) * 100, 2)) : 0;
        $pending_count = max(0, $total_scheduled - $total_completed - $total_failed - $total_running);
        
        // Get next scheduled times
        $next_full_sync = null;
        if (!empty($full_sync_actions)) {
            $summary = $this->format_action_for_output($full_sync_actions[0]);
            $next_full_sync = $summary ? $summary['scheduled_at'] : null;
        }
        
        $next_incremental_sync = null;
        if (!empty($incremental_sync_actions)) {
            $summary = $this->format_action_for_output($incremental_sync_actions[0]);
            $next_incremental_sync = $summary ? $summary['scheduled_at'] : null;
        }
        
        // Get last sync times from options
        $last_full_sync = get_option('bytemash_last_full_sync', null);
        $last_incremental_sync = get_option('bytemash_last_incremental_sync', null);
        
        $recent_completed = $this->map_actions_for_output(array_slice($completed_actions, 0, 10));
        $recent_failed = $this->map_actions_for_output(array_slice($failed_actions, 0, 10));
        $recent_running = $this->map_actions_for_output($running_actions);
        
        return array(
            'scheduled_actions' => array(
                'full_sync' => count($full_sync_actions),
                'incremental_sync' => count($incremental_sync_actions),
                'batch_actions' => count($batch_actions),
                'total_scheduled' => $total_scheduled,
            ),
            'progress' => array(
                'completed' => $total_completed,
                'failed' => $total_failed,
                'running' => $total_running,
                'pending' => $pending_count,
                'percentage' => $progress_percentage,
            ),
            'next_scheduled' => array(
                'full_sync' => $next_full_sync,
                'incremental_sync' => $next_incremental_sync,
            ),
            'last_completed' => array(
                'full_sync' => $last_full_sync,
                'incremental_sync' => $last_incremental_sync,
            ),
            'recent_activity' => array(
                'completed' => $recent_completed,
                'failed' => $recent_failed,
                'running' => $recent_running,
            ),
            'action_scheduler_available' => $this->is_action_scheduler_available(),
        );
    }
    
    /**
     * Get detailed batch progress for a specific sync
     */
    public function get_batch_progress($sync_id) {
        // Get all batch actions for this sync
        $batch_actions = as_get_scheduled_actions(array(
            'hook' => 'bytemash_action_scheduler_batch_sync',
            'status' => 'pending',
            'args' => array($sync_id),
        ));
        
        $completed_batches = as_get_scheduled_actions(array(
            'hook' => 'bytemash_action_scheduler_batch_sync',
            'status' => 'complete',
            'args' => array($sync_id),
        ));
        
        $failed_batches = as_get_scheduled_actions(array(
            'hook' => 'bytemash_action_scheduler_batch_sync',
            'status' => 'failed',
            'args' => array($sync_id),
        ));
        
        $total_batches = count($batch_actions) + count($completed_batches) + count($failed_batches);
        $completed_count = count($completed_batches);
        $failed_count = count($failed_batches);
        $pending_count = count($batch_actions);
        
        $progress_percentage = $total_batches > 0 ? round(($completed_count / $total_batches) * 100, 2) : 0;
        
        return array(
            'sync_id' => $sync_id,
            'total_batches' => $total_batches,
            'completed_batches' => $completed_count,
            'failed_batches' => $failed_count,
            'pending_batches' => $pending_count,
            'progress_percentage' => $progress_percentage,
            'next_batch' => !empty($batch_actions) ? $batch_actions[0]->get_schedule()->get_date()->format('Y-m-d H:i:s') : null,
        );
    }
    
    /**
     * Normalize an Action Scheduler action for output
     */
    private function format_action_for_output($action) {
        if (!is_object($action)) {
            return null;
        }
        
        $schedule = method_exists($action, 'get_schedule') ? $action->get_schedule() : null;
        $date = ($schedule && method_exists($schedule, 'get_date')) ? $schedule->get_date() : null;
        
        return array(
            'id' => method_exists($action, 'get_id') ? $action->get_id() : null,
            'hook' => method_exists($action, 'get_hook') ? $action->get_hook() : null,
            'status' => method_exists($action, 'get_status') ? $action->get_status() : null,
            'group' => method_exists($action, 'get_group') ? $action->get_group() : null,
            'scheduled_at' => $date ? $date->format('Y-m-d H:i:s') : null,
            'args' => method_exists($action, 'get_args') ? $action->get_args() : array(),
        );
    }
    
    /**
     * Convert a list of Action Scheduler actions to normalized arrays
     */
    private function map_actions_for_output($actions) {
        if (empty($actions) || !is_array($actions)) {
            return array();
        }
        
        $formatted = array();
        
        foreach ($actions as $action) {
            $normalized = $this->format_action_for_output($action);
            if ($normalized) {
                $formatted[] = $normalized;
            }
        }
        
        return $formatted;
    }
    
    /**
     * Fallback method to refetch products when transients expire
     * 
     * @param string $sync_id Sync identifier
     * @return array|false Products array or false on failure
     */
    private function refetch_products_for_sync($sync_id) {
        try {
            // Determine sync type from sync_id
            $sync_type = strpos($sync_id, 'full_') === 0 ? 'full' : 'incremental';
            $with_branding = true; // Default to with branding
            
            $this->logger->log('info', "Attempting to refetch products for {$sync_type} sync", array(
                'sync_id' => $sync_id,
                'sync_type' => $sync_type,
            ), 'action_scheduler');
            
            // Fetch products from Amrod API
            $api_client = new ByteMash_Amrod_API_Client();
            
            if ($sync_type === 'full') {
                $products = $api_client->get_products_with_branding();
            } else {
                $products = $api_client->get_products_with_branding_updated();
            }
            
            if (is_wp_error($products)) {
                $this->logger->log('error', 'Failed to refetch products from API', array(
                    'error' => $products->get_error_message(),
                    'sync_id' => $sync_id,
                ), 'action_scheduler');
                return false;
            }
            
            if (!is_array($products) || empty($products)) {
                $this->logger->log('warning', 'No products returned from API during refetch', array(
                    'sync_id' => $sync_id,
                ), 'action_scheduler');
                return false;
            }
            
            $this->logger->log('info', "Successfully refetched products from API", array(
                'sync_id' => $sync_id,
                'product_count' => count($products),
            ), 'action_scheduler');
            
            return $products;
            
        } catch (Exception $e) {
            $this->logger->log('error', 'Exception during product refetch', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'sync_id' => $sync_id,
            ), 'action_scheduler');
            return false;
        }
    }
    
    /**
     * Get all scheduled times in a readable format
     */
    public function get_scheduled_times() {
        $full_sync_actions = as_get_scheduled_actions(array(
            'hook' => 'bytemash_action_scheduler_full_sync',
            'status' => 'pending',
        ));
        
        $incremental_sync_actions = as_get_scheduled_actions(array(
            'hook' => 'bytemash_action_scheduler_incremental_sync',
            'status' => 'pending',
        ));
        
        $batch_actions = as_get_scheduled_actions(array(
            'hook' => 'bytemash_action_scheduler_batch_sync',
            'status' => 'pending',
        ));
        
        $scheduled_times = array();
        
        // Full sync times
        foreach ($full_sync_actions as $action) {
            $schedule = $action->get_schedule();
            $scheduled_times[] = array(
                'type' => 'full_sync',
                'scheduled_time' => $schedule->get_date()->format('Y-m-d H:i:s'),
                'interval' => $schedule->get_interval(),
                'is_recurring' => $schedule->is_recurring(),
                'action_id' => $action->get_id(),
            );
        }
        
        // Incremental sync times
        foreach ($incremental_sync_actions as $action) {
            $schedule = $action->get_schedule();
            $scheduled_times[] = array(
                'type' => 'incremental_sync',
                'scheduled_time' => $schedule->get_date()->format('Y-m-d H:i:s'),
                'interval' => $schedule->get_interval(),
                'is_recurring' => $schedule->is_recurring(),
                'action_id' => $action->get_id(),
            );
        }
        
        // Batch action times
        foreach ($batch_actions as $action) {
            $schedule = $action->get_schedule();
            $args = $action->get_args();
            $scheduled_times[] = array(
                'type' => 'batch_sync',
                'scheduled_time' => $schedule->get_date()->format('Y-m-d H:i:s'),
                'sync_id' => $args[0] ?? 'unknown',
                'batch_index' => $args[1] ?? 'unknown',
                'action_id' => $action->get_id(),
            );
        }
        
        // Sort by scheduled time
        usort($scheduled_times, function($a, $b) {
            return strtotime($a['scheduled_time']) - strtotime($b['scheduled_time']);
        });
        
        return $scheduled_times;
    }
}