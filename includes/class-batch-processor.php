<?php
/**
 * Batch Processor Class
 * 
 * Handles memory-efficient processing of large datasets from Amrod API
 * Uses WordPress background processing to avoid timeouts
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_Batch_Processor {
    
    /**
     * Logger
     */
    private $logger;
    
    /**
     * Batch size
     */
    private $batch_size = 10;
    
    /**
     * Find matching variation for stock update
     */
    private function find_matching_variation($variable_product, $full_code, $simple_code, $colour_code) {
        $variations = $variable_product->get_children();
        
        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) continue;
            
            $variation_sku = $variation->get_sku();
            
            // Direct SKU match (fullCode is primary for variations)
            if ($variation_sku === $full_code) {
                return $variation_id;
            }
            
            // Also try simpleCode match
            if ($variation_sku === $simple_code) {
                return $variation_id;
            }
            
            // Try to match by simple code + colour code pattern
            if ($colour_code && strpos($variation_sku, $simple_code) === 0) {
                // Check if the variation SKU contains the colour code
                if (strpos($variation_sku, $colour_code) !== false) {
                    return $variation_id;
                }
            }
            
            // Fallback: try matching by simple_code prefix if full_code matches pattern
            if ($full_code && strpos($variation_sku, $simple_code) === 0) {
                // Additional check: if full_code has a suffix, try to match it
                if ($colour_code && strpos($variation_sku, $colour_code) !== false) {
                    return $variation_id;
                }
                // Or if no colour code, match any variation starting with simple_code
                if (!$colour_code) {
                    return $variation_id;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new ByteMash_Logger();
        $this->batch_size = (int) get_option('bytemash_amrod_batch_size', 10);
        
        // Register hooks for batch processing
        $this->init_hooks();
    }

    /**
     * Mirror important logs to debug.log for easier diagnosis
     */
    private function log_to_debug($level, $message, $context = array()) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $entry = '[bytemash ' . $level . '] ' . $message;
            if (!empty($context)) {
                $entry .= ' ' . wp_json_encode($context);
            }
            error_log($entry);
        }
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Register batch processing actions
        add_action('bytemash_process_products_batch', array($this, 'process_products_batch'), 10, 2);
        add_action('bytemash_process_products_chunk', array($this, 'process_products_chunk'), 10, 2); // NEW: Chunk-based processing
        add_action('bytemash_process_stock_batch', array($this, 'process_stock_batch'), 10, 2);
        add_action('bytemash_process_prices_batch', array($this, 'process_prices_batch'), 10, 2);
        add_action('bytemash_process_categories_batch', array($this, 'process_categories_batch'), 10, 1);
        add_action('bytemash_process_brands_batch', array($this, 'process_brands_batch'), 10, 2);
    }
    
    /**
     * Schedule batch processing for products using chunk-based approach
     * 
     * @param string $sync_id Unique sync identifier
     * @param int $chunk_count Number of chunks already stored in transients
     * @param int $total Total number of products
     * @return bool Success
     */
    public function schedule_products_sync_chunked($sync_id, $chunk_count, $total) {
        if ($chunk_count === 0 || $total === 0) {
            $this->logger->log('warning', 'No chunks to schedule', array(), 'batch_processor');
            return false;
        }
        
        $this->logger->log('info', "Scheduling {$chunk_count} chunks for {$total} products", array(), 'batch_processor');
        
        // Store sync metadata
        $this->save_sync_progress($sync_id, array(
            'type' => 'products',
            'total' => $total,
            'processed' => 0,
            'chunk_count' => $chunk_count,
            'current_chunk' => 0,
            'status' => 'processing',
            'started' => current_time('mysql'),
        ));
        
        // PROCESS FIRST CHUNK IMMEDIATELY (don't rely on WP-Cron)
        $this->logger->log('info', "Starting immediate processing of first chunk", array(), 'batch_processor');
        
        $this->process_products_chunk($sync_id, 0);
        
        return true;
    }
    
    /**
     * OLD METHOD - Kept for backward compatibility but not recommended for large datasets
     * 
     * @param array $products Full array of products from API
     * @param string $sync_id Unique sync identifier
     * @return bool Success
     */
    public function schedule_products_sync($products, $sync_id) {
        if (!is_array($products) || empty($products)) {
            $this->logger->log('warning', 'No products to schedule', array(), 'batch_processor');
            return false;
        }
        
        $total = count($products);
        $batches = array_chunk($products, $this->batch_size);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Scheduling {$batch_count} batches for {$total} products", array(), 'batch_processor');
        
        // Store sync metadata
        $this->save_sync_progress($sync_id, array(
            'type' => 'products',
            'total' => $total,
            'processed' => 0,
            'batch_count' => $batch_count,
            'current_batch' => 0,
            'status' => 'scheduled',
            'started' => current_time('mysql'),
        ));
        
        // Schedule first batch immediately
        wp_schedule_single_event(time(), 'bytemash_process_products_batch', array($sync_id, 0));
        
        return true;
    }
    
    /**
     * Process a batch of products
     * 
     * @param string $sync_id Sync identifier
     * @param int $batch_index Current batch index
     */
    public function process_products_batch($sync_id, $batch_index) {
        // Increase memory limit for processing
        $original_memory = ini_get('memory_limit');
        @ini_set('memory_limit', '512M');
        
        $this->logger->log('info', "Processing products batch {$batch_index}", array(), 'batch_processor');
        
        // Get sync progress
        $progress = $this->get_sync_progress($sync_id);
        
        if (!$progress) {
            $this->logger->log('error', 'Sync progress not found', array(), 'batch_processor');
            @ini_set('memory_limit', $original_memory);
            return;
        }
        
        // Get cached products for this sync
        $products = get_transient("bytemash_sync_{$sync_id}_products");
        
        if (!$products) {
            $this->logger->log('error', 'Cached products not found', array(), 'batch_processor');
            $this->update_sync_status($sync_id, 'error', 'Cached data expired');
            @ini_set('memory_limit', $original_memory);
            return;
        }
        
        // Get current batch - process directly from chunk to avoid keeping full array in memory
        $start_index = $batch_index * $this->batch_size;
        $batch = array_slice($products, $start_index, $this->batch_size);
        
        // Free the full products array immediately
        unset($products);
        
        if (empty($batch)) {
            $this->logger->log('warning', 'Batch index out of range', array(), 'batch_processor');
            @ini_set('memory_limit', $original_memory);
            return;
        }
        
        // Set up proper bulk operation handling
        $this->handle_bulk_database_operations();
        
        // Suspend cache to reduce memory usage
        wp_suspend_cache_addition(true);
        
        // Process each product in batch
        $product_sync = new ByteMash_Product_Sync();
        $processed = 0;
        $errors = 0;
        
        foreach ($batch as $product_data) {
            try {
                $result = $product_sync->sync_single_product($product_data);
                
                if ($result['success']) {
                    $processed++;
                    $this->logger->log('info', 'Product synced successfully', array(
                        'sku' => $product_data['fullCode'] ?? 'unknown',
                        'product_name' => $product_data['productName'] ?? 'unknown',
                    ), 'batch_processor');
                } else {
                    $errors++;
                    $this->logger->log('error', 'Product sync failed', array(
                        'sku' => $product_data['fullCode'] ?? 'unknown',
                        'product_name' => $product_data['productName'] ?? 'unknown',
                        'error_message' => $result['message'] ?? 'Unknown error',
                    ), 'batch_processor');
                }
            } catch (Exception $e) {
                $errors++;
                $this->logger->log('error', 'Product sync exception', array(
                    'sku' => $product_data['fullCode'] ?? 'unknown',
                    'product_name' => $product_data['productName'] ?? 'unknown',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ), 'batch_processor');
            }
            
            // Clear memory after each product
            unset($product_data, $result);
        }
        
        // Resume cache
        wp_suspend_cache_addition(false);
        
        // Clean up after bulk operations
        $this->cleanup_after_bulk_operations();
        
        // Clear memory aggressively
        unset($batch, $product_sync);
        wp_cache_flush();
        
        // Force garbage collection if available
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        // Update progress
        $progress['processed'] += $processed;
        $progress['current_batch'] = $batch_index + 1;
        $progress['errors'] = ($progress['errors'] ?? 0) + $errors;
        
        $this->save_sync_progress($sync_id, $progress);
        
        $this->logger->log('info', "Batch {$batch_index} completed", array(), 'batch_processor');
        
        // Schedule next batch
        $next_batch = $batch_index + 1;
        
        if ($next_batch < $progress['batch_count']) {
            // Schedule next batch in 5 seconds
            wp_schedule_single_event(time() + 5, 'bytemash_process_products_batch', array($sync_id, $next_batch));
        } else {
            // All batches completed
            $progress['status'] = 'completed';
            $progress['completed'] = current_time('mysql');
            $this->save_sync_progress($sync_id, $progress);
            
            // Delete cached data
            delete_transient("bytemash_sync_{$sync_id}_products");
            
            $this->logger->log('success', 'All product batches completed', array(), 'batch_processor');
        }
        
        // Restore original memory limit
        @ini_set('memory_limit', $original_memory);
    }
    
    /**
     * Process a chunk of products (NEW memory-efficient approach)
     * 
     * @param string $sync_id Sync identifier
     * @param int $chunk_index Current chunk index
     */
    public function process_products_chunk($sync_id, $chunk_index) {
        // Add error handling to prevent plugin deactivation
        try {
            // Increase memory limit for processing
            $original_memory = ini_get('memory_limit');
            @ini_set('memory_limit', '512M');
        
        $this->logger->log('info', "Processing products chunk {$chunk_index}", array(), 'batch_processor');
        
        // Get sync progress
        $progress = $this->get_sync_progress($sync_id);
        
        if (!$progress) {
            $this->logger->log('error', 'Sync progress not found', array(), 'batch_processor');
            @ini_set('memory_limit', $original_memory);
            return;
        }
        
        // CHECK: Skip if this chunk was already processed
        if (isset($progress['processed_chunks']) && in_array($chunk_index, $progress['processed_chunks'])) {
            $this->logger->log('info', "Chunk {$chunk_index} already processed, skipping", array(
                'sync_id' => $sync_id,
                'chunk_index' => $chunk_index,
                'processed_chunks' => $progress['processed_chunks'],
            ), 'batch_processor');
            
            // Still schedule next chunk if needed
            $next_chunk = $chunk_index + 1;
            if ($next_chunk < $progress['chunk_count']) {
                $progress['current_chunk'] = $next_chunk;
                $this->save_sync_progress($sync_id, $progress);
                $this->logger->log('info', "Skipping to next chunk: {$next_chunk}", array(), 'batch_processor');
            }
            
            @ini_set('memory_limit', $original_memory);
            return;
        }
        
        // Load ONLY this chunk from transient
        $chunk = get_transient("bytemash_sync_{$sync_id}_chunk_{$chunk_index}");
        
        if (!$chunk || !is_array($chunk)) {
            $this->logger->log('warning', "Chunk data not found for chunk {$chunk_index} - possible transient expiration, attempting fallback", array(
                'sync_id' => $sync_id,
                'chunk_index' => $chunk_index,
            ), 'batch_processor');
            
            // FALLBACK: Try to regenerate chunk from API
            $chunk = $this->regenerate_chunk_from_api($sync_id, $chunk_index);
            if (!$chunk) {
                $this->logger->log('error', "Failed to regenerate chunk {$chunk_index} from API", array(
                    'sync_id' => $sync_id,
                    'chunk_index' => $chunk_index,
                ), 'batch_processor');
                @ini_set('memory_limit', $original_memory);
                return;
            }
            
            $this->logger->log('info', "Successfully regenerated chunk {$chunk_index} from API", array(
                'sync_id' => $sync_id,
                'chunk_index' => $chunk_index,
                'chunk_size' => count($chunk),
            ), 'batch_processor');
        }
        
        $chunk_size = count($chunk);
        $this->logger->log('info', "Chunk loaded: {$chunk_size} products", array(), 'batch_processor');
        
        // Set up proper bulk operation handling
        $this->handle_bulk_database_operations();
        
        // Suspend cache to reduce memory usage
        wp_suspend_cache_addition(true);
        
        // Process each product in chunk
        $product_sync = new ByteMash_Product_Sync();
        $processed = 0;
        $errors = 0;
        $skipped = 0;
        
        foreach ($chunk as $product_data) {
            try {
                $result = $product_sync->sync_single_product($product_data);
                
                if ($result['success']) {
                    if (isset($result['skipped']) && $result['skipped']) {
                        $skipped++;
                        $this->logger->log('info', 'Product skipped (unchanged)', array(
                            'sku' => $product_data['fullCode'] ?? 'unknown',
                            'product_name' => $product_data['productName'] ?? 'unknown',
                        ), 'batch_processor');
                    } else {
                        $processed++;
                        $this->logger->log('info', 'Product synced successfully', array(
                            'sku' => $product_data['fullCode'] ?? 'unknown',
                            'product_name' => $product_data['productName'] ?? 'unknown',
                        ), 'batch_processor');
                    }
                } else {
                    $errors++;
                    $this->logger->log('error', 'Product sync failed', array(
                        'sku' => $product_data['fullCode'] ?? 'unknown',
                        'product_name' => $product_data['productName'] ?? 'unknown',
                        'error_message' => $result['message'] ?? 'Unknown error',
                    ), 'batch_processor');
                }
            } catch (Exception $e) {
                $errors++;
                $this->logger->log('error', 'Product sync exception', array(
                    'sku' => $product_data['fullCode'] ?? 'unknown',
                    'product_name' => $product_data['productName'] ?? 'unknown',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ), 'batch_processor');
            }
            
            // Clear memory after each product
            unset($product_data, $result);
        }
        
        // Resume cache
        wp_suspend_cache_addition(false);
        
        // Clean up after bulk operations
        $this->cleanup_after_bulk_operations();
        
        // Delete this chunk transient immediately to free memory
        delete_transient("bytemash_sync_{$sync_id}_chunk_{$chunk_index}");
        
        // Clear memory aggressively
        unset($chunk, $product_sync);
        wp_cache_flush();
        
        // Force garbage collection if available
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        // Update progress
        $progress['processed'] += $processed;
        $progress['current_chunk'] = $chunk_index + 1;
        $progress['errors'] = ($progress['errors'] ?? 0) + $errors;
        $progress['skipped'] = ($progress['skipped'] ?? 0) + $skipped;
        
        // Track this chunk as processed
        if (!isset($progress['processed_chunks'])) {
            $progress['processed_chunks'] = array();
        }
        $progress['processed_chunks'][] = $chunk_index;
        
        $this->save_sync_progress($sync_id, $progress);
        
        $this->logger->log('info', "Chunk {$chunk_index} completed", array(), 'batch_processor');
        
        // Schedule next chunk
        $next_chunk = $chunk_index + 1;
        
        if ($next_chunk < $progress['chunk_count']) {
            // Mark as ready for next chunk (will be picked up by AJAX polling)
            $progress['status'] = 'processing';
            $progress['current_chunk'] = $next_chunk;
            $this->save_sync_progress($sync_id, $progress);
            
            $this->logger->log('info', "Ready for next chunk: {$next_chunk}", array(), 'batch_processor');
            
            // DON'T schedule via WP-Cron - AJAX will call process_next_chunk endpoint
        } else {
            // All chunks completed
            $progress['status'] = 'completed';
            $progress['completed'] = current_time('mysql');
            $this->save_sync_progress($sync_id, $progress);
            
            // Delete metadata transient
            delete_transient("bytemash_sync_{$sync_id}_meta");
            
            // Clean up any remaining chunk transients (just in case)
            for ($i = 0; $i < $progress['chunk_count']; $i++) {
                delete_transient("bytemash_sync_{$sync_id}_chunk_{$i}");
            }
            
            $this->logger->log('success', 'All product chunks completed', array(), 'batch_processor');
        }
        
        // Restore original memory limit
        @ini_set('memory_limit', $original_memory);
        
        } catch (Exception $e) {
            // Log the error but don't crash the plugin
            $this->logger->log('error', 'Critical error in chunk processing - preventing plugin deactivation', array(
                'sync_id' => $sync_id,
                'chunk_index' => $chunk_index,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ), 'batch_processor');
            
            // Mark this chunk as failed but continue
            $progress = $this->get_sync_progress($sync_id);
            if ($progress) {
                $progress['status'] = 'error';
                $progress['error_message'] = 'Chunk processing failed: ' . $e->getMessage();
                $this->save_sync_progress($sync_id, $progress);
            }
            
            // Restore memory limit
            if (isset($original_memory)) {
                @ini_set('memory_limit', $original_memory);
            }
        }
    }
    
    /**
     * Schedule stock sync
     */
    public function schedule_stock_sync($stock_data, $sync_id) {
        if (!is_array($stock_data) || empty($stock_data)) {
            return false;
        }
        
        $total = count($stock_data);
        $batches = array_chunk($stock_data, 50); // Larger batches for stock (simpler data)
        $batch_count = count($batches);
        
        $this->logger->log('info', "Scheduling {$batch_count} batches for {$total} stock items", array(), 'batch_processor');
        
        // Cache stock data temporarily (1 hour)
        set_transient("bytemash_sync_{$sync_id}_stock", $stock_data, HOUR_IN_SECONDS);
        
        $this->save_sync_progress($sync_id, array(
            'type' => 'stock',
            'total' => $total,
            'processed' => 0,
            'batch_count' => $batch_count,
            'current_batch' => 0,
            'status' => 'scheduled',
            'started' => current_time('mysql'),
        ));
        
        wp_schedule_single_event(time(), 'bytemash_process_stock_batch', array($sync_id, 0));
        
        return true;
    }
    
    /**
     * Process all stock batches synchronously (for manual syncs)
     */
    public function process_stock_sync_immediately($stock_data, $sync_id) {
        if (!is_array($stock_data) || empty($stock_data)) {
            return array('success' => false, 'message' => 'No stock data to process');
        }
        
        $total = count($stock_data);
        $batches = array_chunk($stock_data, 50);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Processing {$batch_count} stock batches immediately for {$total} items", array(), 'batch_processor');
        
        // Store stock data in transient (required by process_stock_batch)
        set_transient("bytemash_sync_{$sync_id}_stock", $stock_data, 24 * HOUR_IN_SECONDS);
        
        // Set up progress tracking
        $progress = array(
            'type' => 'stock',
            'total' => $total,
            'processed' => 0,
            'batch_count' => $batch_count,
            'current_batch' => 0,
            'errors' => 0,
            'status' => 'processing',
            'started' => current_time('mysql'),
        );
        $this->save_sync_progress($sync_id, $progress);
        
        // Process all batches immediately with optimized approach
        foreach ($batches as $batch_index => $batch) {
            $this->process_stock_batch($sync_id, $batch_index);
        }
        
        // Get final progress
        $final_progress = $this->get_sync_progress($sync_id);
        if ($final_progress) {
            $total_processed = $final_progress['processed'] ?? 0;
            $total_errors = $final_progress['errors'] ?? 0;
            
            // Mark as completed
            $final_progress['status'] = 'completed';
            $final_progress['completed'] = current_time('mysql');
            $this->save_sync_progress($sync_id, $final_progress);
        } else {
            $total_processed = 0;
            $total_errors = 0;
        }
        
        $this->logger->log('success', "Stock sync completed: {$total_processed} processed, {$total_errors} errors", array(), 'batch_processor');
        $this->log_to_debug('success', 'Stock sync completed', array('processed' => $total_processed, 'errors' => $total_errors, 'total' => $total));
        
        return array(
            'success' => true,
            'processed' => $total_processed,
            'errors' => $total_errors,
            'total' => $total,
        );
    }
    
    /**
     * Process stock batch
     */
    public function process_stock_batch($sync_id, $batch_index) {
        $progress = $this->get_sync_progress($sync_id);
        if (!$progress) {
            $ctx = array('sync_id' => $sync_id, 'batch_index' => $batch_index);
            $this->logger->log('error', "Stock batch processing failed: No progress data", $ctx, 'batch_processor');
            $this->log_to_debug('error', 'Stock batch processing failed: No progress data', $ctx);
            // Cannot continue without progress metadata
            return;
        }
        
        $stock_data = get_transient("bytemash_sync_{$sync_id}_stock");
        if (!$stock_data) {
            $ctx = array('sync_id' => $sync_id, 'batch_index' => $batch_index, 'transient_key' => "bytemash_sync_{$sync_id}_stock");
            $this->logger->log('error', "Stock batch missing cached data; skipping batch", $ctx, 'batch_processor');
            $this->log_to_debug('error', 'Stock batch missing cached data; skipping batch', $ctx);
            // Advance progress and continue with next batch
            $progress['current_batch'] = $batch_index + 1;
            $this->save_sync_progress($sync_id, $progress);
            $next_batch = $batch_index + 1;
            if ($next_batch < ($progress['batch_count'] ?? 0)) {
                $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
                $called_from_action_scheduler = false;
                foreach ($backtrace as $frame) {
                    if (isset($frame['function']) && strpos($frame['function'], 'run_stock_batch_action') !== false) {
                        $called_from_action_scheduler = true;
                        break;
                    }
                }
                if (!$called_from_action_scheduler) {
                    wp_schedule_single_event(time() + 3, 'bytemash_process_stock_batch', array($sync_id, $next_batch));
                }
            }
            return;
        }
        
        if (!is_array($stock_data)) {
            $ctx = array('sync_id' => $sync_id, 'batch_index' => $batch_index, 'data_type' => gettype($stock_data));
            $this->logger->log('error', "Stock batch invalid data type; skipping batch", $ctx, 'batch_processor');
            $this->log_to_debug('error', 'Stock batch invalid data type; skipping batch', $ctx);
            // Advance progress and continue with next batch
            $progress['current_batch'] = $batch_index + 1;
            $this->save_sync_progress($sync_id, $progress);
            $next_batch = $batch_index + 1;
            if ($next_batch < ($progress['batch_count'] ?? 0)) {
                $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
                $called_from_action_scheduler = false;
                foreach ($backtrace as $frame) {
                    if (isset($frame['function']) && strpos($frame['function'], 'run_stock_batch_action') !== false) {
                        $called_from_action_scheduler = true;
                        break;
                    }
                }
                if (!$called_from_action_scheduler) {
                    wp_schedule_single_event(time() + 3, 'bytemash_process_stock_batch', array($sync_id, $next_batch));
                }
            }
            return;
        }
        
        $batches = array_chunk($stock_data, 50);
        if (!isset($batches[$batch_index])) {
            $ctx = array('sync_id' => $sync_id, 'batch_index' => $batch_index, 'total_batches' => count($batches));
            $this->logger->log('error', "Stock batch index out of range; skipping batch", $ctx, 'batch_processor');
            $this->log_to_debug('error', 'Stock batch index out of range; skipping batch', $ctx);
            // Advance progress and continue with next batch
            $progress['current_batch'] = $batch_index + 1;
            $this->save_sync_progress($sync_id, $progress);
            $next_batch = $batch_index + 1;
            if ($next_batch < ($progress['batch_count'] ?? 0)) {
                $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
                $called_from_action_scheduler = false;
                foreach ($backtrace as $frame) {
                    if (isset($frame['function']) && strpos($frame['function'], 'run_stock_batch_action') !== false) {
                        $called_from_action_scheduler = true;
                        break;
                    }
                }
                if (!$called_from_action_scheduler) {
                    wp_schedule_single_event(time() + 3, 'bytemash_process_stock_batch', array($sync_id, $next_batch));
                }
            }
            return;
        }
        
        $batch = $batches[$batch_index];
        
        if (empty($batch)) {
            $this->logger->log('warning', "Stock batch is empty", array(
                'sync_id' => $sync_id,
                'batch_index' => $batch_index,
            ), 'batch_processor');
            return;
        }
        
        global $wpdb;
        $processed = 0;
        $errors = 0;
        $error_details = array();
        
        // Disable hooks and cache for faster bulk operations
        wp_suspend_cache_addition(true);
        
        // Extract all SKUs first (both simpleCode and fullCode for variations)
        $skus_to_update = array();
        $valid_items = array();
        
        foreach ($batch as $item_index => $stock_item) {
            $simple_code = $stock_item['simpleCode'] ?? null;
            $full_code = $stock_item['fullCode'] ?? null;
            $stock_type = isset($stock_item['stockType']) ? (int) $stock_item['stockType'] : 0;
            
            if (!$simple_code && !$full_code) {
                $errors++;
                if (count($error_details) < 10) {
                    $error_details[] = "Item {$item_index}: Missing SKU/code";
                }
                continue;
            }
            
            if (!isset($stock_item['stock'])) {
                $errors++;
                if (count($error_details) < 10) {
                    $error_details[] = "Item {$item_index}: Missing stock value";
                }
                continue;
            }
            
            // For variations (stockType 1 or 2), try both simpleCode (parent) and fullCode (variation)
            // For base stock (stockType 0), use simpleCode
            $primary_sku = ($stock_type >= 1 && $full_code) ? $full_code : ($simple_code ?? $full_code);
            
            $skus_to_update[$primary_sku] = $item_index;
            
            // Also add simpleCode if it's different (for finding parent variable product)
            if ($simple_code && $simple_code !== $primary_sku) {
                $skus_to_update[$simple_code] = $item_index;
            }
            
            $valid_items[$item_index] = array(
                'sku' => $primary_sku,
                'simple_code' => $simple_code,
                'full_code' => $full_code,
                'stock' => (int) $stock_item['stock'],
                'stock_type' => $stock_type,
                'stock_item' => $stock_item,
            );
        }
        
        // Batch lookup all product IDs in one query (much faster than individual lookups)
        $product_ids_map = array();
        if (!empty($skus_to_update)) {
            $skus = array_keys($skus_to_update);
            $placeholders = implode(',', array_fill(0, count($skus), '%s'));
            $query = $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} 
                WHERE meta_key = '_sku' AND meta_value IN ($placeholders)",
                ...$skus
            );
            $results = $wpdb->get_results($query, ARRAY_A);
            
            foreach ($results as $row) {
                $sku = $row['meta_value'];
                // Store mapping for both SKU variations
                if (!isset($product_ids_map[$sku])) {
                    $product_ids_map[$sku] = array();
                }
                $product_ids_map[$sku][] = (int) $row['post_id'];
            }
        }
        
        // Batch load all products once (avoid repeated wc_get_product calls)
        $products_cache = array();
        $product_ids_to_load = array();
        foreach ($product_ids_map as $sku => $ids) {
            foreach ($ids as $id) {
                $product_ids_to_load[] = $id;
            }
        }
        $product_ids_to_load = array_unique($product_ids_to_load);
        if (!empty($product_ids_to_load)) {
            foreach ($product_ids_to_load as $product_id) {
                $products_cache[$product_id] = wc_get_product($product_id);
            }
        }
        
        // Process updates using WooCommerce objects but with optimized approach
        foreach ($valid_items as $item_index => $item_data) {
            try {
                $stock_value = $item_data['stock'];
                $target_status = $stock_value > 0 ? 'instock' : 'outofstock';
                $stock_type = $item_data['stock_type'];
                $simple_code = $item_data['simple_code'];
                $full_code = $item_data['full_code'];
                $stock_item = $item_data['stock_item'];
                
                // Try to find product by fullCode first (for variations)
                $product_id = null;
                $product = null;
                
                if ($full_code && isset($product_ids_map[$full_code])) {
                    $candidate_ids = $product_ids_map[$full_code];
                    foreach ($candidate_ids as $pid) {
                        $candidate = wc_get_product($pid);
                        if ($candidate && !$candidate->is_type('variable')) {
                            // Found a simple product or variation with this SKU
                            $product_id = $pid;
                            $product = $candidate;
                            break;
                        }
                    }
                }
                
                // If not found and stockType >= 1 (variation), try finding parent variable product
                if (!$product && $stock_type >= 1 && $simple_code && isset($product_ids_map[$simple_code])) {
                    $parent_ids = $product_ids_map[$simple_code];
                    foreach ($parent_ids as $pid) {
                        $candidate = wc_get_product($pid);
                        if ($candidate && $candidate->is_type('variable')) {
                            // Found parent variable product - will find variation below
                            $product_id = $pid;
                            $product = $candidate;
                            break;
                        }
                    }
                }
                
                // Fallback: try primary SKU
                if (!$product && isset($product_ids_map[$item_data['sku']])) {
                    $candidate_ids = $product_ids_map[$item_data['sku']];
                    if (!empty($candidate_ids)) {
                        $product_id = $candidate_ids[0];
                        $product = $products_cache[$product_id] ?? wc_get_product($product_id);
                    }
                }
                
                if (!$product) {
                $errors++;
                    if (count($error_details) < 10) {
                        $error_details[] = "Item {$item_index}: Product not found for SKU '{$item_data['sku']}' (simpleCode: {$simple_code}, fullCode: {$full_code})";
                    }
                continue;
            }
            
                // Handle variable products and variations
                if ($product->is_type('variable')) {
                    $colour_code = $stock_item['colourCode'] ?? null;
                    $product_sku = $product->get_sku();
                    
                    // Determine if this is base product stock or variation stock
                    // Base product stock indicators (in priority order):
                    // 1. fullCode === simpleCode AND no colourCode (definitely base product, not a variation)
                    // 2. stockType === 0 (explicit base stock)
                    // 3. fullCode === simpleCode AND product SKU matches simpleCode
                    $is_base_stock = false;
                    
                    // First check: if fullCode === simpleCode and no colourCode, it's ALWAYS base product stock
                    if ($full_code === $simple_code && empty($colour_code)) {
                        // This is definitely base product stock, not a variation
                        $is_base_stock = true;
                    } elseif ($stock_type === 0) {
                        // StockType 0 is always base product stock
                        $is_base_stock = true;
                    } elseif ($full_code === $simple_code && $product_sku === $simple_code) {
                        // Product SKU matches = base product stock
                        $is_base_stock = true;
                    }
                    
                    if ($is_base_stock) {
                        // Update base variable product stock
                        $current_qty = (int) $product->get_stock_quantity();
                        $current_status = $product->get_stock_status();
                        
                        if ($current_qty !== (int) $stock_value || $current_status !== $target_status) {
                            $product->set_manage_stock(true);
                            $product->set_backorders('no');
                            $product->set_stock_quantity($stock_value);
                            $product->set_stock_status($target_status);
                $product->save();
                        }
                        $processed++;
                    } else {
                        // Try to find and update matching variation by fullCode
                        $variation_id = $this->find_matching_variation($product, $full_code, $simple_code, $colour_code);
                        
                        if ($variation_id) {
                            $variation = wc_get_product($variation_id);
                            if ($variation) {
                                $current_qty = (int) $variation->get_stock_quantity();
                                $current_status = $variation->get_stock_status();
                                
                                if ($current_qty !== (int) $stock_value || $current_status !== $target_status) {
                                    $variation->set_manage_stock(true);
                                    $variation->set_backorders('no');
                                    $variation->set_stock_quantity($stock_value);
                                    $variation->set_stock_status($target_status);
                                    $variation->save();
                                }
                $processed++;
            } else {
                $errors++;
                                if (count($error_details) < 10) {
                                    $error_details[] = "Item {$item_index}: Variation {$variation_id} not found";
                                }
                            }
                        } else {
                            $errors++;
                            if (count($error_details) < 10) {
                                $error_details[] = "Item {$item_index}: Could not find matching variation for fullCode '{$full_code}' (simpleCode: {$simple_code})";
                            }
                        }
                    }
                } else if ($product->is_type('variation')) {
                    // Direct variation update (found by fullCode)
                    $current_qty = (int) $product->get_stock_quantity();
                    $current_status = $product->get_stock_status();
                    
                    if ($current_qty !== (int) $stock_value || $current_status !== $target_status) {
                        $product->set_manage_stock(true);
                        $product->set_backorders('no');
                        $product->set_stock_quantity($stock_value);
                        $product->set_stock_status($target_status);
                        $product->save();
                    }
                    $processed++;
                } else {
                    // Simple product - update directly
                    // Skip unchanged to keep sync fast
                    $current_qty = (int) $product->get_stock_quantity();
                    $current_status = $product->get_stock_status();
                    $current_manage = (bool) $product->get_manage_stock();
                    $current_backorders = method_exists($product, 'get_backorders') ? $product->get_backorders() : 'no';

                    if ($current_qty === (int) $stock_value
                        && $current_status === $target_status
                        && $current_manage === true
                        && ($current_backorders === 'no' || $current_backorders === 'no_backorders')) {
                        $processed++;
                        continue;
                    }

                    // Ensure WooCommerce treats quantity as authoritative
                    $product->set_manage_stock(true);
                    $product->set_backorders('no');
                    $product->set_stock_quantity($stock_value);
                    $product->set_stock_status($target_status);
                    
                    // Save product (WooCommerce handles this efficiently)
                    $product->save();
                    $processed++;
                }
                
            } catch (Exception $e) {
                $errors++;
                if (count($error_details) < 10) {
                    $error_details[] = "Item {$item_index}: Exception - " . $e->getMessage();
                }
                $ctx = array('sync_id' => $sync_id, 'batch_index' => $batch_index, 'item_index' => $item_index, 'error' => $e->getMessage());
                $this->logger->log('error', "Stock item processing exception", $ctx, 'batch_processor');
                $this->log_to_debug('error', 'Stock item processing exception', $ctx);
            }
        }
        
        // Re-enable cache
        wp_suspend_cache_addition(false);
        
        // Clear object cache for updated products in batch
        if ($processed > 0 && !empty($product_ids_map)) {
            foreach (array_unique(array_values($product_ids_map)) as $product_id) {
                clean_post_cache($product_id);
            }
        }
        
        if ($errors > 0 && count($error_details) <= 10) {
            $ctx = array('sync_id' => $sync_id, 'batch_index' => $batch_index, 'processed' => $processed, 'errors' => $errors, 'error_details' => $error_details);
            $this->logger->log('warning', "Stock batch processing completed with errors", $ctx, 'batch_processor');
            $this->log_to_debug('warning', 'Stock batch processing completed with errors', $ctx);
        } elseif ($errors > 0) {
            $ctx = array('sync_id' => $sync_id, 'batch_index' => $batch_index, 'processed' => $processed, 'errors' => $errors, 'error_sample' => array_slice($error_details, 0, 5));
            $this->logger->log('warning', "Stock batch processing completed with errors", $ctx, 'batch_processor');
            $this->log_to_debug('warning', 'Stock batch processing completed with errors', $ctx);
        }
        
        // Update progress
        $progress['processed'] += $processed;
        $progress['current_batch'] = $batch_index + 1;
        $progress['errors'] = ($progress['errors'] ?? 0) + $errors;
        $this->save_sync_progress($sync_id, $progress);
        
        // Schedule next batch (only if not using Action Scheduler)
        // Action Scheduler handles scheduling when it calls this method
        $next_batch = $batch_index + 1;
        
        if ($next_batch < $progress['batch_count']) {
            // Check if we're being called from Action Scheduler by checking the call stack
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            $called_from_action_scheduler = false;
            foreach ($backtrace as $frame) {
                if (isset($frame['function']) && strpos($frame['function'], 'run_stock_batch_action') !== false) {
                    $called_from_action_scheduler = true;
                    break;
                }
            }
            
            // Only schedule via WP-Cron if not called from Action Scheduler
            if (!$called_from_action_scheduler) {
            wp_schedule_single_event(time() + 3, 'bytemash_process_stock_batch', array($sync_id, $next_batch));
            }
        } else {
            $progress['status'] = 'completed';
            $progress['completed'] = current_time('mysql');
            $this->save_sync_progress($sync_id, $progress);
            delete_transient("bytemash_sync_{$sync_id}_stock");
            
            $this->logger->log('success', 'Stock sync completed', array(), 'batch_processor');
        }
    }
    
    /**
     * Schedule prices sync
     */
    public function schedule_prices_sync($prices_data, $sync_id) {
        if (!is_array($prices_data) || empty($prices_data)) {
            return false;
        }
        
        $total = count($prices_data);
        $batches = array_chunk($prices_data, 100); // Even larger batches for prices
        $batch_count = count($batches);
        
        $this->logger->log('info', "Scheduling {$batch_count} batches for {$total} price items", array(), 'batch_processor');
        
        set_transient("bytemash_sync_{$sync_id}_prices", $prices_data, HOUR_IN_SECONDS);
        
        $this->save_sync_progress($sync_id, array(
            'type' => 'prices',
            'total' => $total,
            'processed' => 0,
            'batch_count' => $batch_count,
            'current_batch' => 0,
            'status' => 'scheduled',
            'started' => current_time('mysql'),
        ));
        
        wp_schedule_single_event(time(), 'bytemash_process_prices_batch', array($sync_id, 0));
        
        return true;
    }
    
    /**
     * Process all price batches synchronously (for manual syncs)
     */
    public function process_prices_sync_immediately($prices_data, $sync_id) {
        if (!is_array($prices_data) || empty($prices_data)) {
            return array('success' => false, 'message' => 'No price data to process');
        }
        
        $total = count($prices_data);
        $batches = array_chunk($prices_data, 500);
        $batch_count = count($batches);
        
        $this->logger->log('info', "⚡ Processing {$batch_count} OPTIMIZED price batches immediately for {$total} items", array(), 'batch_processor');
        
        // Set up progress tracking
        $progress = array(
            'type' => 'prices',
            'total' => $total,
            'processed' => 0,
            'batch_count' => $batch_count,
            'current_batch' => 0,
            'errors' => 0,
            'status' => 'processing',
            'started' => current_time('mysql'),
        );
        $this->save_sync_progress($sync_id, $progress);
        
        $total_processed = 0;
        $total_errors = 0;
        
        // Process all batches immediately using OPTIMIZED bulk method
        foreach ($batches as $batch_index => $batch) {
            
            // Use lightning-fast bulk update
            $result = $this->bulk_update_prices($batch);
            
            $processed = $result['processed'] ?? 0;
            $errors = $result['errors'] ?? 0;
            
            $total_processed += $processed;
            $total_errors += $errors;
            
            // Update progress
            $progress['processed'] = $total_processed;
            $progress['current_batch'] = $batch_index + 1;
            $progress['errors'] = $total_errors;
            $this->save_sync_progress($sync_id, $progress);
            
            $this->logger->log('info', "Price batch " . ($batch_index + 1) . "/{$batch_count} processed", array(
                'processed' => $processed,
                'errors' => $errors,
            ), 'batch_processor');
        }
        
        // Mark as completed
        $progress['status'] = 'completed';
        $progress['completed'] = current_time('mysql');
        $this->save_sync_progress($sync_id, $progress);
        
        $this->logger->log('success', "Prices sync completed: {$total_processed} processed, {$total_errors} errors", array(), 'batch_processor');
        
        return array(
            'success' => true,
            'processed' => $total_processed,
            'errors' => $total_errors,
            'total' => $total,
        );
    }
    
    /**
     * OPTIMIZED: Bulk process prices batch using direct SQL
     * This is 10-50x faster than the old method
     */
    public function process_prices_batch($sync_id, $batch_index) {
        $progress = $this->get_sync_progress($sync_id);
        if (!$progress) return;
        
        $prices_data = get_transient("bytemash_sync_{$sync_id}_prices");
        if (!$prices_data) {
            $this->update_sync_status($sync_id, 'error', 'Cached data expired');
            return;
        }
        
        // Increased batch size to 500 for better performance
        $batches = array_chunk($prices_data, 500);
        if (!isset($batches[$batch_index])) return;
        
        $batch = $batches[$batch_index];
        
        // Use optimized bulk update method
        $result = $this->bulk_update_prices($batch);
        
        $processed = $result['processed'] ?? 0;
        $errors = $result['errors'] ?? 0;
        
        // Update progress
        $progress['processed'] += $processed;
        $progress['current_batch'] = $batch_index + 1;
        $progress['errors'] = ($progress['errors'] ?? 0) + $errors;
        $this->save_sync_progress($sync_id, $progress);
        
        // Schedule next batch (only if not using Action Scheduler)
        $next_batch = $batch_index + 1;
        
        if ($next_batch < $progress['batch_count']) {
            // Check if we're being called from Action Scheduler
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            $called_from_action_scheduler = false;
            foreach ($backtrace as $frame) {
                if (isset($frame['function']) && strpos($frame['function'], 'run_prices_batch_action') !== false) {
                    $called_from_action_scheduler = true;
                    break;
                }
            }
            
            // Only schedule via WP-Cron if not called from Action Scheduler
            if (!$called_from_action_scheduler) {
            wp_schedule_single_event(time() + 2, 'bytemash_process_prices_batch', array($sync_id, $next_batch));
            }
        } else {
            $progress['status'] = 'completed';
            $progress['completed'] = current_time('mysql');
            $this->save_sync_progress($sync_id, $progress);
            delete_transient("bytemash_sync_{$sync_id}_prices");
            
            $this->logger->log('success', 'Prices sync completed', array(), 'batch_processor');
        }
    }
    
    /**
     * LIGHTNING FAST: Bulk update prices using direct SQL
     * 
     * Strategy:
     * 1. Build complete SKU-to-ProductID map in ONE query
     * 2. Group updates by product_id
     * 3. Execute bulk UPDATE using CASE statements
     * 4. No WooCommerce object overhead, no hooks
     * 
     * Performance: 10-50x faster than individual product->save()
     * 
     * @param array $price_items Array of price items from API
     * @return array Result with processed and error counts
     */
    private function bulk_update_prices($price_items) {
        global $wpdb;
        
        if (empty($price_items)) {
            return array('processed' => 0, 'errors' => 0);
        }
        
        $start_time = microtime(true);
        
        // Step 1: Extract all SKUs from price items
        $skus = array();
        foreach ($price_items as $item) {
            $simple_code = $item['simplecode'] ?? $item['simpleCode'] ?? '';
            $full_code = $item['fullCode'] ?? '';
            
            if ($simple_code) $skus[] = $simple_code;
            if ($full_code && $full_code !== $simple_code) $skus[] = $full_code;
        }
        
        $skus = array_unique(array_filter($skus));
        
        if (empty($skus)) {
            return array('processed' => 0, 'errors' => count($price_items));
        }
        
        // Step 2: Build SKU-to-ProductID map in ONE query (LIGHTNING FAST!)
        $placeholders = implode(',', array_fill(0, count($skus), '%s'));
        $query = "SELECT post_id, meta_value as sku 
                  FROM {$wpdb->postmeta} 
                  WHERE meta_key = '_sku' 
                  AND meta_value IN ($placeholders)";
        
        $sku_map = array();
        $results = $wpdb->get_results($wpdb->prepare($query, $skus));
        
        foreach ($results as $row) {
            $sku_map[$row->sku] = $row->post_id;
        }
        
        // Step 3: Also build pattern matches for variants (e.g., ALT-1603 matches ALT-1603-R, ALT-1603-Y)
        foreach ($price_items as $item) {
            $simple_code = $item['simplecode'] ?? $item['simpleCode'] ?? '';
            
            if ($simple_code && !isset($sku_map[$simple_code])) {
                // Find all products where SKU starts with simple_code
                $like_pattern = $wpdb->esc_like($simple_code) . '%';
                $variants = $wpdb->get_results($wpdb->prepare(
                    "SELECT post_id, meta_value as sku 
                    FROM {$wpdb->postmeta} 
                    WHERE meta_key = '_sku' 
                    AND meta_value LIKE %s",
                    $like_pattern
                ));
                
                foreach ($variants as $variant) {
                    // Map variant SKU to the simple_code price
                    if (!isset($sku_map[$variant->sku])) {
                        $sku_map[$variant->sku] = $variant->post_id;
                    }
                }
            }
        }
        
        // Step 4: Group price updates by product_id
        $price_updates = array(); // product_id => price
        $sale_price_updates = array(); // product_id => sale_price
        $processed_items = array();
        
        foreach ($price_items as $item) {
            $simple_code = $item['simplecode'] ?? $item['simpleCode'] ?? '';
            $full_code = $item['fullCode'] ?? '';
            $price = $item['price'] ?? null;
            $sale_price = $item['salePrice'] ?? null;
            
            if ($price === null) continue;
            
            // Find product ID(s) for this price item
            $product_ids = array();
            
            // Try full code first
            if ($full_code && isset($sku_map[$full_code])) {
                $product_ids[] = $sku_map[$full_code];
            }
            
            // Try simple code
            if ($simple_code && isset($sku_map[$simple_code])) {
                $product_ids[] = $sku_map[$simple_code];
            }
            
            // Try all variant SKUs that start with simple_code
            if ($simple_code) {
                foreach ($sku_map as $sku => $pid) {
                    if (strpos($sku, $simple_code) === 0 && !in_array($pid, $product_ids)) {
                        $product_ids[] = $pid;
                    }
                }
            }
            
            // Update all matched products
            foreach ($product_ids as $pid) {
                $price_updates[$pid] = $price;
                if ($sale_price && $sale_price > 0) {
                    $sale_price_updates[$pid] = $sale_price;
                }
                $processed_items[$pid] = true;
            }
        }
        
        $processed_count = count($processed_items);
        $error_count = count($price_items) - $processed_count;
        
        if (empty($price_updates)) {
            return array('processed' => 0, 'errors' => count($price_items));
        }
        
        // Step 5: Bulk UPDATE using CASE statements (LIGHTNING FAST!)
        // Update _regular_price
        $this->bulk_update_post_meta_with_case('_regular_price', $price_updates);
        
        // Update _price (displayed price)
        $this->bulk_update_post_meta_with_case('_price', $price_updates);
        
        // Update _sale_price if applicable
        if (!empty($sale_price_updates)) {
            $this->bulk_update_post_meta_with_case('_sale_price', $sale_price_updates);
        }
        
        $elapsed = round((microtime(true) - $start_time) * 1000, 2);
        $this->logger->log('info', "⚡ Bulk updated {$processed_count} prices in {$elapsed}ms", array(), 'batch_processor');
        
        return array(
            'processed' => $processed_count,
            'errors' => $error_count,
            'time_ms' => $elapsed
        );
    }
    
    /**
     * ULTRA FAST: Bulk update post meta using CASE statement
     * Updates multiple products in a single query
     * 
     * @param string $meta_key Meta key to update (_price, _regular_price, etc)
     * @param array $updates Array of product_id => value
     */
    private function bulk_update_post_meta_with_case($meta_key, $updates) {
        global $wpdb;
        
        if (empty($updates)) return;
        
        $post_ids = array_keys($updates);
        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        
        // Build CASE statement
        $case_parts = array();
        $values = array();
        
        foreach ($updates as $post_id => $value) {
            $case_parts[] = "WHEN post_id = %d THEN %s";
            $values[] = $post_id;
            $values[] = $value;
        }
        
        $case_sql = "CASE " . implode(' ', $case_parts) . " END";
        
        // Merge values with post_ids for the WHERE clause
        $all_values = array_merge($values, $post_ids);
        
        // Update existing meta
        $query = "UPDATE {$wpdb->postmeta} 
                  SET meta_value = $case_sql
                  WHERE meta_key = %s 
                  AND post_id IN ($placeholders)";
        
        array_unshift($all_values, $meta_key);
        
        $wpdb->query($wpdb->prepare($query, $all_values));
        
        // Insert missing meta (for products without this meta key)
        $existing_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = %s AND post_id IN ($placeholders)",
            array_merge(array($meta_key), $post_ids)
        ));
        
        $missing_ids = array_diff($post_ids, $existing_ids);
        
        if (!empty($missing_ids)) {
            $insert_values = array();
            foreach ($missing_ids as $pid) {
                $insert_values[] = $wpdb->prepare("(%d, %s, %s)", $pid, $meta_key, $updates[$pid]);
            }
            
            $wpdb->query(
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) 
                 VALUES " . implode(', ', $insert_values)
            );
        }
    }

    /**
     * Schedule brands sync
     */
    public function schedule_brands_sync($brands_data, $sync_id) {
        if (!is_array($brands_data) || empty($brands_data)) {
            return false;
        }
        $total = count($brands_data);
        $batches = array_chunk($brands_data, 50);
        $batch_count = count($batches);
        $this->logger->log('info', "Scheduling {$batch_count} batches for {$total} brands", array(), 'batch_processor');
        set_transient("bytemash_sync_{$sync_id}_brands", $brands_data, HOUR_IN_SECONDS);
        $this->save_sync_progress($sync_id, array(
            'type' => 'brands',
            'total' => $total,
            'processed' => 0,
            'batch_count' => $batch_count,
            'current_batch' => 0,
            'status' => 'scheduled',
            'started' => current_time('mysql'),
        ));
        wp_schedule_single_event(time(), 'bytemash_process_brands_batch', array($sync_id, 0));
        return true;
    }

    /**
     * Process all brand batches synchronously (for manual syncs)
     */
    public function process_brands_sync_immediately($brands_data, $sync_id) {
        if (!is_array($brands_data) || empty($brands_data)) {
            return array('success' => false, 'message' => 'No brand data to process');
        }
        
        $total = count($brands_data);
        $batches = array_chunk($brands_data, 50);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Processing {$batch_count} brand batches immediately for {$total} items", array(), 'batch_processor');
        
        // Set up progress tracking
        $progress = array(
            'type' => 'brands',
            'total' => $total,
            'processed' => 0,
            'batch_count' => $batch_count,
            'current_batch' => 0,
            'errors' => 0,
            'status' => 'processing',
            'started' => current_time('mysql'),
        );
        $this->save_sync_progress($sync_id, $progress);
        
        $total_processed = 0;
        $total_errors = 0;
        $product_sync = new ByteMash_Product_Sync();
        
        // Process all batches immediately
        foreach ($batches as $batch_index => $batch) {
            $processed = 0;
            $errors = 0;
            
            foreach ($batch as $brand_data) {
                try {
                    $result = $product_sync->sync_single_brand($brand_data);
                    if (!empty($result['success'])) {
                        $processed++;
                    } else {
                        $errors++;
                    }
                } catch (Exception $e) {
                    $errors++;
                }
            }
            
            $total_processed += $processed;
            $total_errors += $errors;
            
            // Update progress
            $progress['processed'] = $total_processed;
            $progress['current_batch'] = $batch_index + 1;
            $progress['errors'] = $total_errors;
            $this->save_sync_progress($sync_id, $progress);
            
            $this->logger->log('info', "Brand batch " . ($batch_index + 1) . "/{$batch_count} processed", array(
                'processed' => $processed,
                'errors' => $errors,
            ), 'batch_processor');
        }
        
        // Mark as completed
        $progress['status'] = 'completed';
        $progress['completed'] = current_time('mysql');
        $this->save_sync_progress($sync_id, $progress);
        
        $this->logger->log('success', "Brands sync completed: {$total_processed} processed, {$total_errors} errors", array(), 'batch_processor');
        
        return array(
            'success' => true,
            'processed' => $total_processed,
            'errors' => $total_errors,
            'total' => $total,
        );
    }
    
    /**
     * Process brands batch
     */
    public function process_brands_batch($sync_id, $batch_index) {
        $progress = $this->get_sync_progress($sync_id);
        if (!$progress) return;
        $brands_data = get_transient("bytemash_sync_{$sync_id}_brands");
        if (!$brands_data) {
            $this->update_sync_status($sync_id, 'error', 'Cached data expired');
            return;
        }
        $batches = array_chunk($brands_data, 50);
        if (!isset($batches[$batch_index])) return;
        $batch = $batches[$batch_index];
        $processed = 0;
        $errors = 0;
        foreach ($batch as $brand_data) {
            try {
                $result = (new ByteMash_Product_Sync())->sync_single_brand($brand_data);
                if (!empty($result['success'])) {
                    $processed++;
                } else {
                    $errors++;
                }
            } catch (Exception $e) {
                $errors++;
            }
        }
        $progress['processed'] += $processed;
        $progress['current_batch'] = $batch_index + 1;
        $progress['errors'] = ($progress['errors'] ?? 0) + $errors;
        $this->save_sync_progress($sync_id, $progress);
        $next_batch = $batch_index + 1;
        if ($next_batch < $progress['batch_count']) {
            // Check if we're being called from Action Scheduler
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            $called_from_action_scheduler = false;
            foreach ($backtrace as $frame) {
                if (isset($frame['function']) && strpos($frame['function'], 'run_brands_batch_action') !== false) {
                    $called_from_action_scheduler = true;
                    break;
                }
            }
            
            // Only schedule via WP-Cron if not called from Action Scheduler
            if (!$called_from_action_scheduler) {
                wp_schedule_single_event(time() + 2, 'bytemash_process_brands_batch', array($sync_id, $next_batch));
            }
        } else {
            $progress['status'] = 'completed';
            $progress['completed'] = current_time('mysql');
            $this->save_sync_progress($sync_id, $progress);
            delete_transient("bytemash_sync_{$sync_id}_brands");
            $this->logger->log('success', 'Brands sync completed', array(), 'batch_processor');
        }
    }
    
    /**
     * Process categories (no batching needed, tree structure)
     */
    public function process_categories_batch($categories) {
        $this->logger->log('info', 'Processing categories', array(), 'batch_processor');
        
        $processed = $this->process_categories_recursive($categories);
        
        $this->logger->log('success', "Categories processed: {$processed}", array(), 'batch_processor');
    }
    
    /**
     * Recursively process categories
     */
    private function process_categories_recursive($categories, $parent_id = 0) {
        $count = 0;
        
        foreach ($categories as $category) {
            $cat_name = sanitize_text_field($category['categoryName'] ?? '');
            
            if (empty($cat_name)) {
                continue;
            }
            
            // Check if category exists
            $term = get_term_by('name', $cat_name, 'product_cat');
            
            if (!$term) {
                $result = wp_insert_term($cat_name, 'product_cat', array(
                    'parent' => $parent_id,
                    'description' => sanitize_text_field($category['categoryPath'] ?? ''),
                ));
                
                if (!is_wp_error($result)) {
                    $term_id = $result['term_id'];
                    
                    // Store Amrod category metadata
                    update_term_meta($term_id, 'amrod_category_id', $category['id']);
                    update_term_meta($term_id, 'amrod_category_code', $category['categoryCode'] ?? '');
                    
                    if (!empty($category['categoryImage'])) {
                        update_term_meta($term_id, 'thumbnail_id', $this->import_category_image($category['categoryImage'], $term_id));
                    }
                    
                    $count++;
                }
            } else {
                $term_id = $term->term_id;
            }
            
            // Process children
            if (!empty($category['children']) && is_array($category['children'])) {
                $count += $this->process_categories_recursive($category['children'], $term_id);
            }
        }
        
        return $count;
    }
    
    /**
     * Import category image
     */
    private function import_category_image($image_url, $term_id) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $tmp = download_url($image_url);
        
        if (is_wp_error($tmp)) {
            return 0;
        }
        
        $file_array = array(
            'name' => basename($image_url),
            'tmp_name' => $tmp,
        );
        
        $id = media_handle_sideload($file_array, 0);
        
        if (is_wp_error($id)) {
            @unlink($tmp);
            return 0;
        }
        
        return $id;
    }
    
    /**
     * Save sync progress to database
     */
    public function save_sync_progress($sync_id, $progress) {
        update_option("bytemash_sync_progress_{$sync_id}", $progress, false);
    }
    
    /**
     * Get sync progress
     */
    public function get_sync_progress($sync_id) {
        return get_option("bytemash_sync_progress_{$sync_id}", false);
    }
    
    /**
     * Update sync status
     */
    private function update_sync_status($sync_id, $status, $message = '') {
        $progress = $this->get_sync_progress($sync_id);
        
        if ($progress) {
            $progress['status'] = $status;
            $progress['message'] = $message;
            $this->save_sync_progress($sync_id, $progress);
        }
    }
    
    /**
     * Get active syncs
     */
    public function get_active_syncs() {
        global $wpdb;
        
        $results = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} 
            WHERE option_name LIKE 'bytemash_sync_progress_%'",
            ARRAY_A
        );
        
        $syncs = array();
        
        foreach ($results as $row) {
            $sync_id = str_replace('bytemash_sync_progress_', '', $row['option_name']);
            $syncs[$sync_id] = maybe_unserialize($row['option_value']);
        }
        
        return $syncs;
    }
    
    /**
     * Properly handle database operations for bulk processing
     */
    private function handle_bulk_database_operations() {
        global $wpdb;
        
        try {
            // Use WordPress's built-in bulk operation handling
            wp_defer_term_counting(true);
            wp_defer_comment_counting(true);
            
            // Clear any pending queries
            if (method_exists($wpdb, 'flush')) {
                $wpdb->flush();
            }
            
            // Ensure we have a clean connection state
            $wpdb->check_connection();
            
            $this->logger->log('info', 'Bulk database operations initialized', array(), 'batch_processor');
        } catch (Exception $e) {
            $this->logger->log('error', 'Failed to initialize bulk database operations', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ), 'batch_processor');
            throw $e;
        }
    }
    
    /**
     * Clean up after bulk operations
     */
    private function cleanup_after_bulk_operations() {
        try {
            // Re-enable counting
            wp_defer_term_counting(false);
            wp_defer_comment_counting(false);
            
            // Clear object cache
            wp_cache_flush();
            
            // Force garbage collection
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            
            $this->logger->log('info', 'Bulk operations cleanup completed', array(), 'batch_processor');
        } catch (Exception $e) {
            $this->logger->log('error', 'Error during bulk operations cleanup', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ), 'batch_processor');
        }
    }
    
    /**
     * Fallback method to regenerate chunk from API when transients expire
     * 
     * @param string $sync_id Sync identifier
     * @param int $chunk_index Chunk index to regenerate
     * @return array|false Chunk array or false on failure
     */
    private function regenerate_chunk_from_api($sync_id, $chunk_index) {
        try {
            // Get sync progress to determine sync type
            $progress = $this->get_sync_progress($sync_id);
            if (!$progress) {
                return false;
            }
            
            $this->logger->log('info', "Regenerating chunk {$chunk_index} from API", array(
                'sync_id' => $sync_id,
                'chunk_index' => $chunk_index,
            ), 'batch_processor');
            
            // Determine sync type and fetch products
            $api_client = new ByteMash_Amrod_API_Client();
            $products = null;
            
            if (strpos($sync_id, 'full_') === 0) {
                $products = $api_client->get_products_with_branding();
            } else {
                $products = $api_client->get_products_with_branding_updated();
            }
            
            if (is_wp_error($products)) {
                $this->logger->log('error', 'Failed to fetch products from API for chunk regeneration', array(
                    'error' => $products->get_error_message(),
                    'sync_id' => $sync_id,
                    'chunk_index' => $chunk_index,
                ), 'batch_processor');
                return false;
            }
            
            if (!is_array($products) || empty($products)) {
                $this->logger->log('warning', 'No products returned from API for chunk regeneration', array(
                    'sync_id' => $sync_id,
                    'chunk_index' => $chunk_index,
                ), 'batch_processor');
                return false;
            }
            
            // Split into chunks and get the specific chunk
            $batch_size = (int) get_option('bytemash_amrod_batch_size', 10);
            $chunks = array_chunk($products, $batch_size);
            
            if (!isset($chunks[$chunk_index])) {
                $this->logger->log('error', 'Chunk index out of range during regeneration', array(
                    'sync_id' => $sync_id,
                    'chunk_index' => $chunk_index,
                    'total_chunks' => count($chunks),
                ), 'batch_processor');
                return false;
            }
            
            $chunk = $chunks[$chunk_index];
            
            // Re-store the chunk with extended lifetime
            set_transient("bytemash_sync_{$sync_id}_chunk_{$chunk_index}", $chunk, 12 * HOUR_IN_SECONDS);
            
            $this->logger->log('info', "Successfully regenerated and stored chunk {$chunk_index}", array(
                'sync_id' => $sync_id,
                'chunk_index' => $chunk_index,
                'chunk_size' => count($chunk),
            ), 'batch_processor');
            
            return $chunk;
            
        } catch (Exception $e) {
            $this->logger->log('error', 'Exception during chunk regeneration', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'sync_id' => $sync_id,
                'chunk_index' => $chunk_index,
            ), 'batch_processor');
            return false;
        }
    }
    
    /**
     * Resume a sync from where it left off
     * 
     * @param string $sync_id Sync identifier
     * @return bool Success
     */
    public function resume_sync($sync_id) {
        $progress = $this->get_sync_progress($sync_id);
        
        if (!$progress) {
            $this->logger->log('error', 'Cannot resume sync - progress not found', array(
                'sync_id' => $sync_id,
            ), 'batch_processor');
            return false;
        }
        
        if ($progress['status'] === 'completed') {
            $this->logger->log('info', 'Sync already completed, no need to resume', array(
                'sync_id' => $sync_id,
            ), 'batch_processor');
            return true;
        }
        
        // Find the next unprocessed chunk
        $next_chunk = $this->find_next_unprocessed_chunk($progress);
        
        if ($next_chunk === false) {
            $this->logger->log('info', 'All chunks processed, marking sync as completed', array(
                'sync_id' => $sync_id,
            ), 'batch_processor');
            
            $progress['status'] = 'completed';
            $progress['completed'] = current_time('mysql');
            $this->save_sync_progress($sync_id, $progress);
            return true;
        }
        
        $this->logger->log('info', "Resuming sync from chunk {$next_chunk}", array(
            'sync_id' => $sync_id,
            'next_chunk' => $next_chunk,
            'total_chunks' => $progress['chunk_count'],
        ), 'batch_processor');
        
        // Process the next chunk
        $this->process_products_chunk($sync_id, $next_chunk);
        
        return true;
    }
    
    /**
     * Find the next unprocessed chunk
     * 
     * @param array $progress Sync progress data
     * @return int|false Next chunk index or false if all processed
     */
    private function find_next_unprocessed_chunk($progress) {
        $processed_chunks = $progress['processed_chunks'] ?? array();
        $total_chunks = $progress['chunk_count'] ?? 0;
        
        for ($i = 0; $i < $total_chunks; $i++) {
            if (!in_array($i, $processed_chunks)) {
                return $i;
            }
        }
        
        return false;
    }
    
    /**
     * Clean up completed syncs older than 24 hours
     */
    public function cleanup_old_syncs() {
        $syncs = $this->get_active_syncs();
        
        foreach ($syncs as $sync_id => $progress) {
            if ($progress['status'] === 'completed') {
                $completed_time = strtotime($progress['completed'] ?? $progress['started']);
                
                if (time() - $completed_time > DAY_IN_SECONDS) {
                    delete_option("bytemash_sync_progress_{$sync_id}");
                    delete_transient("bytemash_sync_{$sync_id}_products");
                    delete_transient("bytemash_sync_{$sync_id}_stock");
                    delete_transient("bytemash_sync_{$sync_id}_prices");
                }
            }
        }
    }
}



