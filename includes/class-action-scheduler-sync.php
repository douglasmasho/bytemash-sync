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
     * Initialize Action Scheduler hooks
     */
    private function init_hooks() {
        // Hook our sync actions
        add_action('bytemash_action_scheduler_full_sync', array($this, 'run_full_sync_action'));
        add_action('bytemash_action_scheduler_incremental_sync', array($this, 'run_incremental_sync_action'));
        add_action('bytemash_action_scheduler_batch_sync', array($this, 'run_batch_sync_action'), 10, 3);
        
        // Hook for cleanup
        add_action('bytemash_action_scheduler_cleanup', array($this, 'cleanup_old_syncs'));
    }
    
    /**
     * Schedule full sync using Action Scheduler
     */
    public function schedule_full_sync($interval = 'daily') {
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
        $this->logger->log('info', 'Action Scheduler full sync started', array(
            'with_branding' => $with_branding,
        ), 'action_scheduler');
        
        try {
            // Fetch products from Amrod API
            $api_client = new ByteMash_Amrod_API_Client();
            if ($with_branding) {
                $products = $api_client->get_products_with_branding();
            } else {
                $products = $api_client->get_products_without_branding();
            }
            
            if (is_wp_error($products)) {
                $this->logger->log('error', 'Failed to fetch products for Action Scheduler full sync', array(
                    'error' => $products->get_error_message(),
                ), 'action_scheduler');
                return;
            }
            
            if (!is_array($products) || empty($products)) {
                $this->logger->log('warning', 'No products found for Action Scheduler full sync', array(), 'action_scheduler');
                return;
            }
            
            $total = count($products);
            $batch_size = (int) get_option('bytemash_amrod_batch_size', 10);
            
            $this->logger->log('info', "Processing {$total} products in batches of {$batch_size}", array(), 'action_scheduler');
            
            // Process products in batches using Action Scheduler
            $this->schedule_batch_processing($products, 'full', $with_branding);
            
        } catch (Exception $e) {
            $this->logger->log('error', 'Action Scheduler full sync failed', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ), 'action_scheduler');
        }
    }
    
    /**
     * Run incremental sync action
     */
    public function run_incremental_sync_action($with_branding = true) {
        $this->logger->log('info', 'Action Scheduler incremental sync started', array(
            'with_branding' => $with_branding,
        ), 'action_scheduler');
        
        try {
            // Fetch updated products from Amrod API
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
                return;
            }
            
            if (!is_array($products) || empty($products)) {
                $this->logger->log('info', 'No updated products found for Action Scheduler incremental sync', array(), 'action_scheduler');
                return;
            }
            
            $total = count($products);
            $batch_size = (int) get_option('bytemash_amrod_batch_size', 10);
            
            $this->logger->log('info', "Processing {$total} updated products in batches of {$batch_size}", array(), 'action_scheduler');
            
            // Process products in batches using Action Scheduler
            $this->schedule_batch_processing($products, 'incremental', $with_branding);
            
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
    private function schedule_batch_processing($products, $sync_type, $with_branding) {
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
        set_transient("bytemash_action_scheduler_{$sync_id}_products", $products, HOUR_IN_SECONDS);
        
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
        
        // Schedule cleanup after all batches are processed
        $cleanup_delay = ($batch_count * 30) + 300; // 5 minutes after last batch
        as_schedule_single_action(
            time() + $cleanup_delay,
            'bytemash_action_scheduler_cleanup',
            array($sync_id),
            'bytemash-sync'
        );
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
        
        try {
            // Get the stored products
            $products = get_transient("bytemash_action_scheduler_{$sync_id}_products");
            if (!$products) {
                $this->logger->log('error', "Products not found for sync {$sync_id}", array(
                    'sync_id' => $sync_id,
                ), 'action_scheduler');
                return;
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
        
        return array(
            'full_sync_scheduled' => count($full_sync_actions) > 0,
            'incremental_sync_scheduled' => count($incremental_sync_actions) > 0,
            'pending_batches' => count($batch_actions),
            'next_full_sync' => !empty($full_sync_actions) ? $full_sync_actions[0]->get_schedule()->get_date() : null,
            'next_incremental_sync' => !empty($incremental_sync_actions) ? $incremental_sync_actions[0]->get_schedule()->get_date() : null,
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
     */
    public function enable_production_sync() {
        $this->clear_schedules();
        
        // Schedule full sync daily at 00:30 (South Africa time)
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
        
        // Schedule full sync daily at 00:30
        as_schedule_recurring_action(
            $wp_timestamp,
            DAY_IN_SECONDS, // Daily
            'bytemash_action_scheduler_full_sync',
            array('with_branding' => true),
            'bytemash-sync'
        );
        
        // Schedule incremental sync every 5 hours (starts after first full sync)
        as_schedule_recurring_action(
            $wp_timestamp + (5 * HOUR_IN_SECONDS), // Start 5 hours after full sync
            5 * HOUR_IN_SECONDS, // Then every 5 hours
            'bytemash_action_scheduler_incremental_sync',
            array('with_branding' => true),
            'bytemash-sync'
        );
        
        $this->logger->log('info', "Production sync enabled - Full sync daily at 00:30, Incremental every 5 hours", array(), 'action_scheduler');
        
        return array(
            'success' => true,
            'message' => 'Production sync enabled - Full sync daily at 00:30, Incremental every 5 hours',
            'next_full_sync' => $next_sync->format('Y-m-d H:i:s'),
            'next_incremental_sync' => date('Y-m-d H:i:s', $wp_timestamp + (5 * HOUR_IN_SECONDS)),
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
        
        $progress_percentage = $total_scheduled > 0 ? round(($total_completed / $total_scheduled) * 100, 2) : 0;
        
        // Get next scheduled times
        $next_full_sync = !empty($full_sync_actions) ? $full_sync_actions[0]->get_schedule()->get_date()->format('Y-m-d H:i:s') : null;
        $next_incremental_sync = !empty($incremental_sync_actions) ? $incremental_sync_actions[0]->get_schedule()->get_date()->format('Y-m-d H:i:s') : null;
        
        // Get last sync times from options
        $last_full_sync = get_option('bytemash_last_full_sync', null);
        $last_incremental_sync = get_option('bytemash_last_incremental_sync', null);
        
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
                'pending' => $total_scheduled - $total_completed - $total_failed - $total_running,
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
                'completed' => array_slice($completed_actions, 0, 10),
                'failed' => array_slice($failed_actions, 0, 10),
                'running' => $running_actions,
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