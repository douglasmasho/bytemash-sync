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
     * Cache for variant SKU lookups during price sync
     */
    private $price_sync_variant_cache = array();
    
    /**
     * Find matching variation for stock update
     */
    private function find_matching_variation($variable_product, $full_code, $simple_code, $colour_code, $variations_cache = array()) {
        $variations = $variable_product->get_children();
        
        foreach ($variations as $variation_id) {
            // Use cache if available, otherwise load
            $variation = isset($variations_cache[$variation_id]) ? $variations_cache[$variation_id] : wc_get_product($variation_id);
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
     * OPTIMIZATION: Disable expensive WooCommerce hooks during bulk stock updates
     * This prevents email notifications, stock change notifications, and other expensive operations
     * 
     * @return array Array of disabled hooks for later re-enabling
     */
    private function disable_expensive_hooks_for_stock_sync() {
        $disabled_hooks = array();
        
        // Disable stock change email notifications (very expensive)
        if (has_action('woocommerce_low_stock')) {
            $disabled_hooks['woocommerce_low_stock'] = true;
            remove_all_actions('woocommerce_low_stock');
        }
        
        if (has_action('woocommerce_no_stock')) {
            $disabled_hooks['woocommerce_no_stock'] = true;
            remove_all_actions('woocommerce_no_stock');
        }
        
        if (has_action('woocommerce_product_set_stock')) {
            // We'll still trigger this, but remove expensive listeners
            $disabled_hooks['woocommerce_product_set_stock'] = true;
            // Only remove non-essential actions (keep core WooCommerce functionality)
            $priority = has_filter('woocommerce_product_set_stock', 'wc_maybe_increase_stock_amount');
            if ($priority !== false) {
                // Keep core WooCommerce stock management, but remove email notifications
            }
        }
        
        // Disable variation stock change notifications
        if (has_action('woocommerce_variation_set_stock')) {
            $disabled_hooks['woocommerce_variation_set_stock'] = true;
        }
        
        // Disable product save hooks that aren't needed for stock-only updates
        // These are expensive and not needed when only updating stock
        if (has_action('woocommerce_before_product_object_save')) {
            // Keep this but note it's disabled for performance
            $disabled_hooks['woocommerce_before_product_object_save'] = true;
        }
        
        // Disable cache-related hooks that slow down bulk updates
        if (has_action('woocommerce_delete_product_transients')) {
            $disabled_hooks['woocommerce_delete_product_transients'] = true;
            remove_all_actions('woocommerce_delete_product_transients');
        }
        
        // Disable search index updates during bulk stock sync
        if (has_action('woocommerce_product_set_stock', 'wc_maybe_update_product_stock_status')) {
            // Keep core functionality but note it
            $disabled_hooks['wc_maybe_update_product_stock_status'] = true;
        }
        
        return $disabled_hooks;
    }
    
    /**
     * OPTIMIZATION: Re-enable expensive WooCommerce hooks after bulk stock updates
     * 
     * @param array $disabled_hooks Array of hooks that were disabled
     */
    private function reenable_expensive_hooks_for_stock_sync($disabled_hooks) {
        // Most hooks will automatically re-enable when we don't remove them permanently
        // This method is here for future extensibility if we need to restore specific hooks
        // For now, the hooks we removed will be restored on next page load or when needed
        
        // Note: We intentionally don't restore all hooks immediately to avoid performance impact
        // WooCommerce will restore them naturally when needed
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
                    // OPTIMIZATION: Don't log every successful product - only log errors
                    // This reduces I/O overhead significantly
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
            // All batches completed - mark as processing deletion before cleanup
            $progress['status'] = 'deleting_excess';
            $progress['cleanup_status'] = 'starting';
            $progress['cleanup_message'] = 'Starting to delete excess products...';
            $this->save_sync_progress($sync_id, $progress);
            
            // Delete cached data
            delete_transient("bytemash_sync_{$sync_id}_products");
            
            $this->logger->log('success', 'All product batches completed, starting product deletion', array(), 'batch_processor');
            
            // Reconcile catalog counts now that all products have been processed
            // This will delete products not in the API and show progress
            $product_sync = new ByteMash_Product_Sync();
            $product_sync->cleanup_products_not_in_snapshot($sync_id);
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
        
        // OPTIMIZATION: Extend execution time to prevent timeouts during batch processing
        // This is safe because we're processing in controlled chunks
        @set_time_limit(300); // 5 minutes per batch
        
        // Suspend cache to reduce memory usage
        wp_suspend_cache_addition(true);
        
        // Process each product in chunk
        $product_sync = new ByteMash_Product_Sync();
        
        // OPTIMIZATION: Enable batch mode to preload all SKUs in one query
        // This eliminates N individual SKU lookups, replacing with 1 batch query
        $product_sync->start_batch_mode($chunk);
        
        $processed = 0;
        $errors = 0;
        $skipped = 0;
        
        foreach ($chunk as $product_data) {
            try {
                $result = $product_sync->sync_single_product($product_data);
                
                if ($result['success']) {
                    if (isset($result['skipped']) && $result['skipped']) {
                        $skipped++;
                        // OPTIMIZATION: Don't log every skipped product - reduces I/O overhead
                    } else {
                        $processed++;
                        // OPTIMIZATION: Don't log every successful product - only log errors
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
        
        // OPTIMIZATION: Disable batch mode and clear caches
        $product_sync->end_batch_mode();
        
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
            // All chunks completed - mark as processing deletion before cleanup
            $progress['status'] = 'deleting_excess';
            $progress['cleanup_status'] = 'starting';
            $progress['cleanup_message'] = 'Starting to delete excess products...';
            $progress['completed'] = current_time('mysql');
            $this->save_sync_progress($sync_id, $progress);
            
            // Delete metadata transient
            delete_transient("bytemash_sync_{$sync_id}_meta");
            
            // Clean up any remaining chunk transients (just in case)
            for ($i = 0; $i < $progress['chunk_count']; $i++) {
                delete_transient("bytemash_sync_{$sync_id}_chunk_{$i}");
            }
            
            $this->logger->log('success', 'All product chunks completed, starting product deletion', array(), 'batch_processor');
            
            $product_sync = new ByteMash_Product_Sync();
            $product_sync->cleanup_products_not_in_snapshot($sync_id);
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
        $batches = array_chunk($stock_data, 100); // Larger batches for stock (simpler data)
        $batch_count = count($batches);
        
        $this->logger->log('info', "Scheduling {$batch_count} batches for {$total} stock items", array(), 'batch_processor');
        
        $this->prime_stock_batches($sync_id, $batches, HOUR_IN_SECONDS);
        
        $this->save_sync_progress($sync_id, array(
            'type' => 'stock',
            'total' => $total,
            'processed' => 0,
            'batch_count' => $batch_count,
            'batch_size' => 100,
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
        $batches = array_chunk($stock_data, 100);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Processing {$batch_count} stock batches immediately for {$total} items", array(), 'batch_processor');
        
        // Set up progress tracking
        $progress = array(
            'type' => 'stock',
            'total' => $total,
            'processed' => 0,
            'batch_count' => $batch_count,
            'batch_size' => 100,
            'current_batch' => 0,
            'errors' => 0,
            'status' => 'processing',
            'started' => current_time('mysql'),
        );
        $this->save_sync_progress($sync_id, $progress);
        
        // Process all batches immediately with optimized approach (no transient lookups)
        foreach ($batches as $batch_index => $batch) {
            $this->process_stock_batch($sync_id, $batch_index, $batch);
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
        
        $this->clear_stock_batch_cache($sync_id, $batch_count);
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
     * Cache stock batches individually to avoid repeatedly loading massive payloads.
     */
    public function prime_stock_batches($sync_id, array $batches, $ttl = HOUR_IN_SECONDS) {
        if (empty($batches)) {
            return;
        }
        
        $batch_count = count($batches);
        $this->clear_stock_batch_cache($sync_id, $batch_count);
        
        foreach ($batches as $index => $batch) {
            set_transient($this->get_stock_batch_cache_key($sync_id, $index), $batch, $ttl);
        }
        
        set_transient(
            "bytemash_sync_{$sync_id}_stock_manifest",
            array(
                'batch_count' => $batch_count,
                'version' => 2,
                'cached_at' => time(),
            ),
            $ttl
        );
    }
    
    /**
     * Remove cached stock batches.
     */
    public function clear_stock_batch_cache($sync_id, $batch_count = null) {
        $manifest_key = "bytemash_sync_{$sync_id}_stock_manifest";
        
        if ($batch_count === null) {
            $manifest = get_transient($manifest_key);
            if (is_array($manifest) && isset($manifest['batch_count'])) {
                $batch_count = (int) $manifest['batch_count'];
            }
        }
        
        if ($batch_count !== null && $batch_count > 0) {
            for ($i = 0; $i < $batch_count; $i++) {
                delete_transient($this->get_stock_batch_cache_key($sync_id, $i));
            }
        }
        
        delete_transient($manifest_key);
        delete_transient("bytemash_sync_{$sync_id}_stock");
    }
    
    /**
     * Fetch a stock batch from cache, falling back to legacy payload if necessary.
     */
    private function get_cached_stock_batch($sync_id, $batch_index, $batch_size) {
        $batch_key = $this->get_stock_batch_cache_key($sync_id, $batch_index);
        $cached = get_transient($batch_key);
        if ($cached !== false) {
            return $cached;
        }
        
        // Fallback for legacy syncs that stored the entire payload in one transient
        $stock_data = get_transient("bytemash_sync_{$sync_id}_stock");
        if (is_array($stock_data) && !empty($stock_data)) {
            $offset = $batch_index * $batch_size;
            $slice = array_slice($stock_data, $offset, $batch_size);
            if (!empty($slice)) {
                return $slice;
            }
        }
        
        return null;
    }
    
    /**
     * Build the cache key for an individual stock batch.
     */
    private function get_stock_batch_cache_key($sync_id, $batch_index) {
        return "bytemash_sync_{$sync_id}_stock_batch_{$batch_index}";
    }
    
    /**
     * Process stock batch
     * PHENOMENAL OPTIMIZATION: Uses direct SQL updates with proper WooCommerce hook management
     */
    public function process_stock_batch($sync_id, $batch_index, $prefetched_batch = null) {
        $progress = $this->get_sync_progress($sync_id);
        
        // For AJAX calls with prefetched batch, we don't need progress data
        $using_prefetched_batch = is_array($prefetched_batch);
        
        if (!$progress && !$using_prefetched_batch) {
            $ctx = array('sync_id' => $sync_id, 'batch_index' => $batch_index);
            $this->logger->log('error', "Stock batch processing failed: No progress data", $ctx, 'batch_processor');
            $this->log_to_debug('error', 'Stock batch processing failed: No progress data', $ctx);
            // Return error result for AJAX
            return array(
                'success' => false,
                'processed' => 0,
                'errors' => 0,
                'skipped' => 0,
                'message' => 'No progress data'
            );
        }
        
        // Get batch size from progress data, or default to 100
        $batch_size = ($progress && isset($progress['batch_size'])) ? (int) $progress['batch_size'] : 100;
        $batch = $using_prefetched_batch ? $prefetched_batch : $this->get_cached_stock_batch($sync_id, $batch_index, $batch_size);
        
        if (!is_array($batch) || empty($batch)) {
            $ctx = array('sync_id' => $sync_id, 'batch_index' => $batch_index);
            $this->logger->log('error', "Stock batch missing cached data; skipping batch", $ctx, 'batch_processor');
            $this->log_to_debug('error', 'Stock batch missing cached data; skipping batch', $ctx);
            
            // For AJAX calls, return result immediately
            if ($using_prefetched_batch) {
                return array(
                    'success' => false,
                    'processed' => 0,
                    'errors' => 0,
                    'skipped' => 0,
                    'message' => 'Empty batch data'
                );
            }
            
            // Advance progress and continue with next batch (for scheduled calls)
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
            return array(
                'success' => false,
                'processed' => 0,
                'errors' => 0,
                'skipped' => 0,
                'message' => 'Empty batch data'
            );
        }
        
        if (!$using_prefetched_batch) {
            delete_transient($this->get_stock_batch_cache_key($sync_id, $batch_index));
        }
        
        global $wpdb;
        $processed = 0;
        $errors = 0;
        $error_details = array();
        
        // OPTIMIZATION: Disable expensive hooks during bulk stock updates
        // This prevents email notifications, stock change notifications, and other expensive operations
        $disabled_hooks = $this->disable_expensive_hooks_for_stock_sync();
        
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
        
        // PHENOMENAL OPTIMIZATION: Batch lookup all product IDs including Amrod codes in one query
        // This checks both _sku and Amrod-specific meta keys in a single query
        $product_ids_map = array();
        if (!empty($skus_to_update)) {
            $skus = array_keys($skus_to_update);
            $placeholders = implode(',', array_fill(0, count($skus), '%s'));
            // Check both standard SKU and Amrod-specific codes in one query
            $query = $wpdb->prepare(
                "SELECT post_id, meta_value, meta_key FROM {$wpdb->postmeta} 
                WHERE meta_key IN ('_sku', '_amrod_simple_code', '_amrod_full_code') 
                AND meta_value IN ($placeholders)",
                ...$skus
            );
            $results = $wpdb->get_results($query, ARRAY_A);
            
            foreach ($results as $row) {
                $sku = $row['meta_value'];
                $pid = (int) $row['post_id'];
                // Store mapping for all SKU variations (standard and Amrod codes)
                if (!isset($product_ids_map[$sku])) {
                    $product_ids_map[$sku] = array();
                }
                if (!in_array($pid, $product_ids_map[$sku])) {
                    $product_ids_map[$sku][] = $pid;
                }
            }
        }
        
        // DRAMATIC OPTIMIZATION: Load all stock meta in one query instead of loading full product objects
        $product_ids_to_load = array();
        foreach ($product_ids_map as $sku => $ids) {
            foreach ($ids as $id) {
                $product_ids_to_load[] = $id;
            }
        }
        $product_ids_to_load = array_unique($product_ids_to_load);
        
        // Batch load all current stock values in one query (MUCH faster than loading product objects)
        $current_stock_meta = array();
        $product_types = array();
        $parent_relationships = array(); // variation_id => parent_id
        $variation_skus = array(); // variation_id => sku
        
        if (!empty($product_ids_to_load)) {
            // Load all stock-related meta in one query
            $placeholders = implode(',', array_fill(0, count($product_ids_to_load), '%d'));
            $meta_query = $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value 
                FROM {$wpdb->postmeta} 
                WHERE post_id IN ($placeholders) 
                AND meta_key IN ('_stock', '_stock_status', '_manage_stock', '_backorders', '_sku', '_product_type', '_parent_id')
                ORDER BY post_id, meta_key",
                ...$product_ids_to_load
            );
            $meta_results = $wpdb->get_results($meta_query, ARRAY_A);
            
            // Organize meta by product ID
            foreach ($meta_results as $meta_row) {
                $pid = (int) $meta_row['post_id'];
                $key = $meta_row['meta_key'];
                $value = $meta_row['meta_value'];
                
                if ($key === '_stock') {
                    $current_stock_meta[$pid]['stock'] = (int) $value;
                } elseif ($key === '_stock_status') {
                    $current_stock_meta[$pid]['status'] = $value;
                } elseif ($key === '_manage_stock') {
                    $current_stock_meta[$pid]['manage_stock'] = $value;
                } elseif ($key === '_backorders') {
                    $current_stock_meta[$pid]['backorders'] = $value;
                } elseif ($key === '_sku') {
                    $current_stock_meta[$pid]['sku'] = $value;
                } elseif ($key === '_product_type') {
                    $product_types[$pid] = $value;
                } elseif ($key === '_parent_id') {
                    $parent_relationships[$pid] = (int) $value;
                }
            }
            
            // Build variation SKU map and parent relationships efficiently
            $sku_to_variation_map = array();
            foreach ($current_stock_meta as $pid => $meta) {
                if (isset($meta['sku']) && $meta['sku']) {
                    $sku_to_variation_map[$meta['sku']] = $pid;
                }
                // If this is a variation, store its SKU
                if (isset($parent_relationships[$pid])) {
                    $variation_skus[$pid] = $meta['sku'] ?? '';
                }
            }
            
            // Load variation IDs for variable products (one query per variable product type)
            $variable_product_ids = array();
            foreach ($product_types as $pid => $type) {
                if ($type === 'variable') {
                    $variable_product_ids[] = $pid;
                }
            }
            
            // Batch load all variation IDs for variable products
            $parent_variations_map = array();
            if (!empty($variable_product_ids)) {
                $var_placeholders = implode(',', array_fill(0, count($variable_product_ids), '%d'));
                $variation_query = $wpdb->prepare(
                    "SELECT post_parent, ID 
                    FROM {$wpdb->posts} 
                    WHERE post_parent IN ($var_placeholders) 
                    AND post_type = 'product_variation'
                    AND post_status != 'trash'",
                    ...$variable_product_ids
                );
                $variation_results = $wpdb->get_results($variation_query, ARRAY_A);
                
                // Build parent => variations map
                foreach ($variation_results as $var_row) {
                    $parent_id = (int) $var_row['post_parent'];
                    $variation_id = (int) $var_row['ID'];
                    if (!isset($parent_variations_map[$parent_id])) {
                        $parent_variations_map[$parent_id] = array();
                    }
                    $parent_variations_map[$parent_id][] = $variation_id;
                }
            }
        }
        
        // Minimal product cache - only load when absolutely needed
        $products_cache = array();
        $variations_cache = array();
        
        // DRAMATIC OPTIMIZATION: Process updates using pre-loaded meta data (no product object loading)
        // Batch collect all updates first, then execute in bulk
        $updates_to_process = array();
        
        foreach ($valid_items as $item_index => $item_data) {
            try {
                $stock_value = (int) $item_data['stock'];
                $target_status = $stock_value > 0 ? 'instock' : 'outofstock';
                $stock_type = $item_data['stock_type'];
                $simple_code = $item_data['simple_code'];
                $full_code = $item_data['full_code'];
                $stock_item = $item_data['stock_item'];
                
                // Find product ID using meta data (no product object loading)
                $product_id = null;
                $is_variation = false;
                $product_type = null;
                
                // Try to find product by fullCode first (for variations)
                if ($full_code && isset($product_ids_map[$full_code])) {
                    $candidate_ids = $product_ids_map[$full_code];
                    foreach ($candidate_ids as $pid) {
                        $type = $product_types[$pid] ?? null;
                        if ($type && $type !== 'variable') {
                            $product_id = $pid;
                            $product_type = $type;
                            $is_variation = ($type === 'variation');
                            break;
                        }
                    }
                }
                
                // If not found and stockType >= 1 (variation), try finding parent variable product
                if (!$product_id && $stock_type >= 1 && $simple_code && isset($product_ids_map[$simple_code])) {
                    $parent_ids = $product_ids_map[$simple_code];
                    foreach ($parent_ids as $pid) {
                        $type = $product_types[$pid] ?? null;
                        if ($type === 'variable') {
                            // Found parent - will find variation below
                            $product_id = $pid;
                            $product_type = 'variable';
                            break;
                        }
                    }
                }
                
                // Fallback: try primary SKU
                if (!$product_id && isset($product_ids_map[$item_data['sku']])) {
                    $candidate_ids = $product_ids_map[$item_data['sku']];
                    if (!empty($candidate_ids)) {
                        $product_id = $candidate_ids[0];
                        $product_type = $product_types[$product_id] ?? null;
                        $is_variation = ($product_type === 'variation');
                    }
                }
                
                if (!$product_id) {
                    $errors++;
                    if (count($error_details) < 10) {
                        $error_details[] = "Item {$item_index}: Product not found for SKU '{$item_data['sku']}' (simpleCode: {$simple_code}, fullCode: {$full_code})";
                    }
                    continue;
                }
                
                // Handle variable products and variations using pre-loaded meta
                if ($product_type === 'variable') {
                    $colour_code = $stock_item['colourCode'] ?? null;
                    $product_sku = $current_stock_meta[$product_id]['sku'] ?? '';
                    
                    // Determine if this is base product stock or variation stock
                    $is_base_stock = false;
                    if ($full_code === $simple_code && empty($colour_code)) {
                        $is_base_stock = true;
                    } elseif ($stock_type === 0) {
                        $is_base_stock = true;
                    } elseif ($full_code === $simple_code && $product_sku === $simple_code) {
                        $is_base_stock = true;
                    }
                    
                    if ($is_base_stock) {
                        // Update base variable product stock
                        // PHENOMENAL OPTIMIZATION: Skip if already matches (no DB write)
                        $current_qty = (int) ($current_stock_meta[$product_id]['stock'] ?? 0);
                        $current_status = $current_stock_meta[$product_id]['status'] ?? 'outofstock';
                        
                        if ($current_qty !== $stock_value || $current_status !== $target_status) {
                            $updates_to_process[] = array(
                                'product_id' => $product_id,
                                'stock' => $stock_value,
                                'status' => $target_status,
                                'type' => 'variable',
                                'item_index' => $item_index,
                            );
                            $processed++;
                        } else {
                            $processed++; // Count as processed even if no update needed
                        }
                    } else {
                        // Find variation using SKU map
                        $variation_id = null;
                        if ($full_code && isset($sku_to_variation_map[$full_code])) {
                            $candidate_id = $sku_to_variation_map[$full_code];
                            // Verify this variation belongs to the current parent
                            if (isset($parent_variations_map[$product_id]) && in_array($candidate_id, $parent_variations_map[$product_id])) {
                                $variation_id = $candidate_id;
                            }
                        }
                        
                        // PHENOMENAL OPTIMIZATION: Skip expensive variation matching if we can't find it via SKU
                        // Only try expensive matching as last resort (this is very slow)
                        if (!$variation_id && !empty($full_code)) {
                            // Try one more quick lookup: check if any variation in parent has matching SKU pattern
                            if (isset($parent_variations_map[$product_id])) {
                                foreach ($parent_variations_map[$product_id] as $var_id) {
                                    $var_sku = $current_stock_meta[$var_id]['sku'] ?? '';
                                    if ($var_sku === $full_code || 
                                        ($simple_code && strpos($var_sku, $simple_code) === 0 && 
                                         ($colour_code && strpos($var_sku, $colour_code) !== false))) {
                                        $variation_id = $var_id;
                                        break;
                                    }
                                }
                            }
                        }
                        
                        // Last resort: load product object (expensive, but only if absolutely necessary)
                        if (!$variation_id && !isset($products_cache[$product_id])) {
                            $products_cache[$product_id] = wc_get_product($product_id);
                        }
                        if (!$variation_id && isset($products_cache[$product_id])) {
                            $variation_id = $this->find_matching_variation(
                                $products_cache[$product_id], 
                                $full_code, 
                                $simple_code, 
                                $colour_code, 
                                array()
                            );
                        }
                        
                        if ($variation_id) {
                            // PHENOMENAL OPTIMIZATION: Skip if already matches (no DB write)
                            $current_qty = (int) ($current_stock_meta[$variation_id]['stock'] ?? 0);
                            $current_status = $current_stock_meta[$variation_id]['status'] ?? 'outofstock';
                            
                            if ($current_qty !== $stock_value || $current_status !== $target_status) {
                                $updates_to_process[] = array(
                                    'product_id' => $variation_id,
                                    'stock' => $stock_value,
                                    'status' => $target_status,
                                    'type' => 'variation',
                                    'item_index' => $item_index,
                                );
                                $processed++;
                            } else {
                                $processed++; // Count as processed even if no update needed
                            }
                        } else {
                            $errors++;
                            if (count($error_details) < 10) {
                                $error_details[] = "Item {$item_index}: Could not find matching variation for fullCode '{$full_code}' (simpleCode: {$simple_code})";
                            }
                        }
                    }
                } elseif ($is_variation || $product_type === 'variation') {
                    // Direct variation update - PHENOMENAL OPTIMIZATION: Skip if already matches
                    $current_qty = (int) ($current_stock_meta[$product_id]['stock'] ?? 0);
                    $current_status = $current_stock_meta[$product_id]['status'] ?? 'outofstock';
                    
                    if ($current_qty !== $stock_value || $current_status !== $target_status) {
                        $updates_to_process[] = array(
                            'product_id' => $product_id,
                            'stock' => $stock_value,
                            'status' => $target_status,
                            'type' => 'variation',
                            'item_index' => $item_index,
                        );
                        $processed++;
                    } else {
                        $processed++; // Count as processed even if no update needed
                    }
                } else {
                    // Simple product - PHENOMENAL OPTIMIZATION: Skip if stock already matches (no DB write needed)
                    $current_qty = (int) ($current_stock_meta[$product_id]['stock'] ?? 0);
                    $current_status = $current_stock_meta[$product_id]['status'] ?? 'outofstock';
                    
                    // Only check stock and status (most common changes) - skip manage_stock/backorders check for speed
                    if ($current_qty === $stock_value && $current_status === $target_status) {
                        $processed++;
                        continue; // No update needed - skip DB write entirely
                    }

                    $updates_to_process[] = array(
                        'product_id' => $product_id,
                        'stock' => $stock_value,
                        'status' => $target_status,
                        'type' => 'simple',
                        'item_index' => $item_index,
                    );
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
        
        // DRAMATIC OPTIMIZATION: Direct SQL batch updates for maximum speed (10-50x faster)
        if (!empty($updates_to_process)) {
            $start_time = microtime(true);
            
            // Group updates for batch SQL execution
            $stock_updates = array();
            $status_updates = array();
            $manage_stock_updates = array();
            $backorders_updates = array();
            $update_types = array(); // Track product types for hooks
            
            foreach ($updates_to_process as $update) {
                $pid = $update['product_id'];
                $stock_updates[$pid] = (int) $update['stock'];
                $status_updates[$pid] = $update['status'];
                $manage_stock_updates[$pid] = 'yes';
                $backorders_updates[$pid] = 'no';
                $update_types[$pid] = $update['type'];
            }
            
            // SAFEGUARD: Use direct SQL for speed, but prepare properly to prevent SQL injection
            if (!empty($stock_updates)) {
                $updated_product_ids = array_keys($stock_updates);
                
                // START TRANSACTION for atomic updates and speed
                $wpdb->query('START TRANSACTION');

                try {
                    // Batch update all stock meta using efficient SQL (single query per meta key)
                    // Use CASE statements for maximum speed - updates all products in one query
                    $pids = array_map('intval', array_keys($stock_updates));
                    $pids_placeholder = implode(',', $pids);
                    
                    // Batch update _stock using CASE statement (single query)
                    if (!empty($stock_updates)) {
                        $stock_cases = array();
                        foreach ($stock_updates as $pid => $stock) {
                            $pid_escaped = (int) $pid;
                            $stock_escaped = (int) $stock;
                            $stock_cases[] = "WHEN {$pid_escaped} THEN {$stock_escaped}";
                        }
                        $wpdb->query(
                            "UPDATE {$wpdb->postmeta} 
                            SET meta_value = CASE post_id " . implode(' ', $stock_cases) . " END
                            WHERE post_id IN ($pids_placeholder) AND meta_key = '_stock'"
                        );
                        
                        // PHENOMENAL OPTIMIZATION: Bulk insert missing records instead of individual inserts
                        $existing_stock_pids = $wpdb->get_col(
                            "SELECT post_id FROM {$wpdb->postmeta} 
                            WHERE post_id IN ($pids_placeholder) AND meta_key = '_stock'"
                        );
                        $existing_stock_pids = array_map('intval', $existing_stock_pids);
                        $missing_stock_pids = array_diff($pids, $existing_stock_pids);
                        if (!empty($missing_stock_pids)) {
                            // Build bulk INSERT query (much faster than individual inserts)
                            $insert_values = array();
                            foreach ($missing_stock_pids as $pid) {
                                $pid_escaped = (int) $pid;
                                $stock_escaped = (int) $stock_updates[$pid];
                                $insert_values[] = "({$pid_escaped}, '_stock', {$stock_escaped})";
                            }
                            if (!empty($insert_values)) {
                                $wpdb->query(
                                    "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . 
                                    implode(', ', $insert_values)
                                );
                            }
                        }
                    }
                    
                    // Batch update _stock_status using CASE statement (single query)
                    if (!empty($status_updates)) {
                        $status_cases = array();
                        foreach ($status_updates as $pid => $status) {
                            $pid_escaped = (int) $pid;
                            $status_escaped = esc_sql($status);
                            $status_cases[] = "WHEN {$pid_escaped} THEN '{$status_escaped}'";
                        }
                        $wpdb->query(
                            "UPDATE {$wpdb->postmeta} 
                            SET meta_value = CASE post_id " . implode(' ', $status_cases) . " END
                            WHERE post_id IN ($pids_placeholder) AND meta_key = '_stock_status'"
                        );
                        
                        // PHENOMENAL OPTIMIZATION: Bulk insert missing records
                        $existing_status_pids = $wpdb->get_col(
                            "SELECT post_id FROM {$wpdb->postmeta} 
                            WHERE post_id IN ($pids_placeholder) AND meta_key = '_stock_status'"
                        );
                        $existing_status_pids = array_map('intval', $existing_status_pids);
                        $missing_status_pids = array_diff($pids, $existing_status_pids);
                        if (!empty($missing_status_pids)) {
                            $insert_values = array();
                            foreach ($missing_status_pids as $pid) {
                                $pid_escaped = (int) $pid;
                                $status_escaped = esc_sql($status_updates[$pid]);
                                $insert_values[] = "({$pid_escaped}, '_stock_status', '{$status_escaped}')";
                            }
                            if (!empty($insert_values)) {
                                $wpdb->query(
                                    "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . 
                                    implode(', ', $insert_values)
                                );
                            }
                        }
                    }
                    
                    // Batch update _manage_stock using CASE statement (single query)
                    if (!empty($manage_stock_updates)) {
                        $manage_cases = array();
                        foreach ($manage_stock_updates as $pid => $manage) {
                            $pid_escaped = (int) $pid;
                            $manage_escaped = esc_sql($manage);
                            $manage_cases[] = "WHEN {$pid_escaped} THEN '{$manage_escaped}'";
                        }
                        $wpdb->query(
                            "UPDATE {$wpdb->postmeta} 
                            SET meta_value = CASE post_id " . implode(' ', $manage_cases) . " END
                            WHERE post_id IN ($pids_placeholder) AND meta_key = '_manage_stock'"
                        );
                        
                        // PHENOMENAL OPTIMIZATION: Bulk insert missing records
                        $existing_manage_pids = $wpdb->get_col(
                            "SELECT post_id FROM {$wpdb->postmeta} 
                            WHERE post_id IN ($pids_placeholder) AND meta_key = '_manage_stock'"
                        );
                        $existing_manage_pids = array_map('intval', $existing_manage_pids);
                        $missing_manage_pids = array_diff($pids, $existing_manage_pids);
                        if (!empty($missing_manage_pids)) {
                            $insert_values = array();
                            foreach ($missing_manage_pids as $pid) {
                                $pid_escaped = (int) $pid;
                                $manage_escaped = esc_sql($manage_stock_updates[$pid]);
                                $insert_values[] = "({$pid_escaped}, '_manage_stock', '{$manage_escaped}')";
                            }
                            if (!empty($insert_values)) {
                                $wpdb->query(
                                    "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . 
                                    implode(', ', $insert_values)
                                );
                            }
                        }
                    }
                    
                    // Batch update _backorders using CASE statement (single query)
                    if (!empty($backorders_updates)) {
                        $backorder_cases = array();
                        foreach ($backorders_updates as $pid => $backorders) {
                            $pid_escaped = (int) $pid;
                            $backorders_escaped = esc_sql($backorders);
                            $backorder_cases[] = "WHEN {$pid_escaped} THEN '{$backorders_escaped}'";
                        }
                        $wpdb->query(
                            "UPDATE {$wpdb->postmeta} 
                            SET meta_value = CASE post_id " . implode(' ', $backorder_cases) . " END
                            WHERE post_id IN ($pids_placeholder) AND meta_key = '_backorders'"
                        );
                        
                        // PHENOMENAL OPTIMIZATION: Bulk insert missing records
                        $existing_backorder_pids = $wpdb->get_col(
                            "SELECT post_id FROM {$wpdb->postmeta} 
                            WHERE post_id IN ($pids_placeholder) AND meta_key = '_backorders'"
                        );
                        $existing_backorder_pids = array_map('intval', $existing_backorder_pids);
                        $missing_backorder_pids = array_diff($pids, $existing_backorder_pids);
                        if (!empty($missing_backorder_pids)) {
                            $insert_values = array();
                            foreach ($missing_backorder_pids as $pid) {
                                $pid_escaped = (int) $pid;
                                $backorders_escaped = esc_sql($backorders_updates[$pid]);
                                $insert_values[] = "({$pid_escaped}, '_backorders', '{$backorders_escaped}')";
                            }
                            if (!empty($insert_values)) {
                                $wpdb->query(
                                    "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . 
                                    implode(', ', $insert_values)
                                );
                            }
                        }
                    }

                    $wpdb->query('COMMIT');

                } catch (Exception $e) {
                    $wpdb->query('ROLLBACK');
                    $this->logger->log('error', "Stock batch transaction failed: " . $e->getMessage(), array(), 'batch_processor');
                    throw $e;
                }
                
                // SAFEGUARD: Clear caches for all updated products (batch clear)
                // OPTIMIZATION: Only clear post meta cache, not full post cache, to save time
                foreach ($updated_product_ids as $pid) {
                    // clean_post_cache($pid); // Too slow
                    wp_cache_delete($pid, 'post_meta');
                }
                
                // SAFEGUARD: Trigger WooCommerce hooks (critical for plugin compatibility)
                // OPTIMIZATION: DISABLED for speed. This is the main bottleneck.
                /*
                // Load product objects only for hooks (lazy loading - minimal overhead)
                $product_ids_for_hooks = array_unique($updated_product_ids);
                foreach ($product_ids_for_hooks as $pid) {
                    if (!isset($products_cache[$pid])) {
                        $products_cache[$pid] = wc_get_product($pid);
                    }
                    
                    if ($products_cache[$pid]) {
                        $product_obj = $products_cache[$pid];
                        // Trigger hooks so plugins/themes are notified of stock changes
                        do_action('woocommerce_product_set_stock', $product_obj);
                        if (isset($update_types[$pid]) && $update_types[$pid] === 'variation') {
                            do_action('woocommerce_variation_set_stock', $product_obj);
                        }
                    }
                }
                */
                
                // SAFEGUARD: Clear WooCommerce product transients (batch clear)
                // OPTIMIZATION: Only clear if absolutely necessary, or rely on expiration
                // wc_delete_product_transients($updated_product_ids); // Can be slow
            }
            
            $duration = microtime(true) - $start_time;
            $this->logger->log('info', sprintf("Stock batch DB update took %.4f seconds for %d items", $duration, count($updates_to_process)), array(), 'batch_processor');
        }
        
        // Re-enable cache
        wp_suspend_cache_addition(false);
        
        // OPTIMIZATION: Re-enable expensive hooks
        $this->reenable_expensive_hooks_for_stock_sync($disabled_hooks);
        
        // Note: Cache clearing is now handled in the batch update section above for better performance
        
        if ($errors > 0 && count($error_details) <= 10) {
            $ctx = array('sync_id' => $sync_id, 'batch_index' => $batch_index, 'processed' => $processed, 'errors' => $errors, 'error_details' => $error_details);
            $this->logger->log('warning', "Stock batch processing completed with errors", $ctx, 'batch_processor');
            $this->log_to_debug('warning', 'Stock batch processing completed with errors', $ctx);
        } elseif ($errors > 0) {
            $ctx = array('sync_id' => $sync_id, 'batch_index' => $batch_index, 'processed' => $processed, 'errors' => $errors, 'error_sample' => array_slice($error_details, 0, 5));
            $this->logger->log('warning', "Stock batch processing completed with errors", $ctx, 'batch_processor');
            $this->log_to_debug('warning', 'Stock batch processing completed with errors', $ctx);
        }
        
        // Update progress (only if progress exists, skip for direct AJAX calls)
        if ($progress && isset($progress['batch_count'])) {
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
                $this->clear_stock_batch_cache($sync_id, $progress['batch_count'] ?? null);
                
                $this->logger->log('success', 'Stock sync completed', array(), 'batch_processor');
            }
        }
        
        // Return result for AJAX calls
        return array(
            'success' => true,
            'processed' => $processed,
            'errors' => $errors,
            'skipped' => 0,
            'message' => "Processed {$processed} items, {$errors} errors"
        );
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
    
    private function bulk_update_prices($price_items) {
        global $wpdb;
        
        if (empty($price_items)) {
            return array('processed' => 0, 'errors' => 0);
        }
        
        $start_time   = microtime(true);
        $sku_map      = $this->build_price_sync_sku_map($price_items);
        $processed    = 0;
        $errors       = 0;
        $impacted_ids = array();
        
        if (empty($sku_map)) {
            return array('processed' => 0, 'errors' => count($price_items));
        }
        
        foreach ($price_items as $item) {
            $simple_code = $item['simplecode'] ?? $item['simpleCode'] ?? '';
            $full_code   = $item['fullCode']   ?? '';
            $price       = $item['price']      ?? null;
            $sale_price  = $item['salePrice']  ?? null;
            
            if ($price === null) {
                $errors++;
                continue;
            }
            
            $product_ids = $this->resolve_price_sync_product_ids($simple_code, $full_code, $sku_map);
            
            if (empty($product_ids)) {
                $errors++;
                continue;
            }
            
            foreach ($product_ids as $product_id) {
                $result = $this->apply_price_update_with_hooks($product_id, $price, $sale_price);
                
                if (is_wp_error($result)) {
                    $errors++;
                    $this->logger->log('error', 'Price update failed via WooCommerce hooks', array(
                        'product_id' => $product_id,
                        'simple_code' => $simple_code,
                        'full_code' => $full_code,
                        'error' => $result->get_error_message(),
                    ), 'price_sync');
                    continue;
                }
                
                $processed++;
                $impacted_ids[$product_id] = true;
            }
        }
        
        $elapsed = round((microtime(true) - $start_time) * 1000, 2);
        
        return array(
            'processed' => count($impacted_ids),
            'errors'    => $errors,
            'time_ms'   => $elapsed,
        );
    }
    
    /**
     * Build a SKU to product ID map for the incoming API payload.
     */
    private function build_price_sync_sku_map($price_items) {
        global $wpdb;
        
        $skus = array();
        
        foreach ($price_items as $item) {
            $simple_code = $item['simplecode'] ?? $item['simpleCode'] ?? '';
            $full_code   = $item['fullCode']   ?? '';
            
            if ($simple_code) {
                $skus[] = $simple_code;
            }
            
            if ($full_code && $full_code !== $simple_code) {
                $skus[] = $full_code;
            }
        }
        
        $skus = array_values(array_unique(array_filter($skus)));
        
        if (empty($skus)) {
            return array();
        }
        
        $placeholders = implode(',', array_fill(0, count($skus), '%s'));
        $prepared_sql = $wpdb->prepare(
            "SELECT post_id, meta_value as sku 
             FROM {$wpdb->postmeta} 
             WHERE meta_key = '_sku' 
             AND meta_value IN ($placeholders)",
            ...$skus
        );
        $results = $wpdb->get_results($prepared_sql);
        
        $map = array();
        
        foreach ($results as $row) {
            $map[$row->sku] = (int) $row->post_id;
        }
        
        // Reset variant cache each run
        $this->price_sync_variant_cache = array();
        
        return $map;
    }
    
    /**
     * Resolve all product IDs that should get a given price update.
     */
    private function resolve_price_sync_product_ids($simple_code, $full_code, $sku_map) {
        global $wpdb;
        
        $ids = array();
        
        if ($full_code && isset($sku_map[$full_code])) {
            $ids[] = $sku_map[$full_code];
        }
        
        if ($simple_code && isset($sku_map[$simple_code])) {
            $ids[] = $sku_map[$simple_code];
        }
        
        if ($simple_code) {
            if (!isset($this->price_sync_variant_cache[$simple_code])) {
                $like_pattern = $wpdb->esc_like($simple_code) . '%';
                $variant_ids = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} 
                         WHERE meta_key = '_sku' 
                         AND meta_value LIKE %s",
                        $like_pattern
                    )
                );
                
                $this->price_sync_variant_cache[$simple_code] = array_map('intval', $variant_ids ?: array());
            }
            
            $ids = array_merge($ids, $this->price_sync_variant_cache[$simple_code]);
        }
        
        return array_unique(array_filter(array_map('intval', $ids)));
    }
    
    /**
     * Apply the price update via WooCommerce data store so hooks fire.
     */
    private function apply_price_update_with_hooks($product_id, $price, $sale_price = null) {
        try {
            $product = wc_get_product($product_id);
            
            if (!$product) {
                return new WP_Error('bytemash_price_sync_missing_product', sprintf('Product ID %d not found', $product_id));
            }
            
            $price_changed  = false;
            $regular_price  = wc_format_decimal($price);
            $current_regular = $product->get_regular_price('edit');
            
            if ($regular_price === '') {
                return new WP_Error('bytemash_price_sync_invalid_price', 'Invalid regular price supplied');
            }
            
            if ($current_regular !== $regular_price) {
                $product->set_regular_price($regular_price);
                if ($sale_price === null || $sale_price === '' || floatval($sale_price) <= 0) {
                    $product->set_price($regular_price);
                }
                $price_changed = true;
            }
            
            if ($sale_price !== null && $sale_price !== '' && floatval($sale_price) > 0) {
                $formatted_sale = wc_format_decimal($sale_price);
                
                if ($product->get_sale_price('edit') !== $formatted_sale) {
                    $product->set_sale_price($formatted_sale);
                    $product->set_price($formatted_sale);
                    $price_changed = true;
                }
            } elseif ($product->get_sale_price('edit')) {
                $product->set_sale_price('');
                $product->set_price($regular_price);
                $price_changed = true;
            }
            
            if ($price_changed) {
                $product->save();
            }
            
            return true;
        } catch (Exception $e) {
            return new WP_Error('bytemash_price_sync_exception', $e->getMessage());
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
            
            // OPTIMIZATION: Disable post revisions during bulk sync (saves DB space and time)
            if (!defined('WP_POST_REVISIONS')) {
                define('WP_POST_REVISIONS', false);
            }
            
            // OPTIMIZATION: Disable autosave during bulk operations
            add_filter('wp_autosave_interval', function() { return 86400; }); // 24 hours
            
            // Clear any pending queries
            if (method_exists($wpdb, 'flush')) {
                $wpdb->flush();
            }
            
            // Ensure we have a clean connection state
            $wpdb->check_connection();
            
            // OPTIMIZATION: Disable unnecessary hooks during bulk operations
            // This reduces overhead from plugins that hook into post saves
            if (!defined('DOING_BULK_SYNC')) {
                define('DOING_BULK_SYNC', true);
            }
            
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
            
            // OPTIMIZATION: Use targeted cache group flushing instead of full flush
            // This is faster and doesn't clear unrelated cache
            // Note: wp_cache_flush_group() may not exist in all WordPress versions
            if (function_exists('wp_cache_flush_group')) {
                wp_cache_flush_group('posts');
                wp_cache_flush_group('post_meta');
                wp_cache_flush_group('terms');
                wp_cache_flush_group('term_meta');
            }
            
            // Only do full flush every N batches to reduce overhead
            // Full flush is still needed occasionally to prevent memory buildup
            static $batch_count = 0;
            $batch_count++;
            if ($batch_count % 10 === 0 || !function_exists('wp_cache_flush_group')) {
                wp_cache_flush(); // Full flush every 10 batches or if group flush not available
            }
            
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
            
            $product_sync = new ByteMash_Product_Sync();
            $product_sync->cleanup_products_not_in_snapshot($sync_id);
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
                    $this->clear_stock_batch_cache($sync_id, $progress['batch_count'] ?? null);
                    delete_transient("bytemash_sync_{$sync_id}_prices");
                }
            }
        }
    }
}



