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
     * Constructor
     */
    public function __construct() {
        $this->logger = new ByteMash_Logger();
        $this->batch_size = (int) get_option('bytemash_amrod_batch_size', 10);
        
        // Register hooks for batch processing
        $this->init_hooks();
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
        
        $this->logger->log('info', "Scheduling {$chunk_count} chunks for {$total} products", array(
            'sync_id' => $sync_id,
            'total_products' => $total,
            'chunk_count' => $chunk_count,
        ), 'batch_processor');
        
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
        $this->logger->log('info', "Starting immediate processing of first chunk", array(
            'sync_id' => $sync_id,
        ), 'batch_processor');
        
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
        
        $this->logger->log('info', "Scheduling {$batch_count} batches for {$total} products", array(
            'sync_id' => $sync_id,
            'total_products' => $total,
            'batch_count' => $batch_count,
            'batch_size' => $this->batch_size,
        ), 'batch_processor');
        
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
        
        $this->logger->log('info', "Processing products batch {$batch_index}", array(
            'sync_id' => $sync_id,
            'batch_index' => $batch_index,
            'memory_limit' => ini_get('memory_limit'),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ), 'batch_processor');
        
        // Get sync progress
        $progress = $this->get_sync_progress($sync_id);
        
        if (!$progress) {
            $this->logger->log('error', 'Sync progress not found', array('sync_id' => $sync_id), 'batch_processor');
            @ini_set('memory_limit', $original_memory);
            return;
        }
        
        // Get cached products for this sync
        $products = get_transient("bytemash_sync_{$sync_id}_products");
        
        if (!$products) {
            $this->logger->log('error', 'Cached products not found', array('sync_id' => $sync_id), 'batch_processor');
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
            $this->logger->log('warning', 'Batch index out of range', array(
                'sync_id' => $sync_id,
                'batch_index' => $batch_index,
            ), 'batch_processor');
            @ini_set('memory_limit', $original_memory);
            return;
        }
        
        // Suspend cache to reduce memory usage
        wp_suspend_cache_addition(true);
        
        // Process each product in batch
        $product_sync = new ByteMash_Product_Sync();
        $processed = 0;
        $errors = 0;
        
        foreach ($batch as $product_data) {
            $result = $product_sync->sync_single_product($product_data);
            
            if ($result['success']) {
                $processed++;
            } else {
                $errors++;
            }
            
            // Clear memory after each product
            unset($product_data, $result);
        }
        
        // Resume cache
        wp_suspend_cache_addition(false);
        
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
        
        $this->logger->log('info', "Batch {$batch_index} completed", array(
            'sync_id' => $sync_id,
            'processed' => $processed,
            'errors' => $errors,
            'total_progress' => $progress['processed'] . '/' . $progress['total'],
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ), 'batch_processor');
        
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
            
            $this->logger->log('success', 'All product batches completed', array(
                'sync_id' => $sync_id,
                'total_processed' => $progress['processed'],
                'total_errors' => $progress['errors'],
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ), 'batch_processor');
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
        // Increase memory limit for processing
        $original_memory = ini_get('memory_limit');
        @ini_set('memory_limit', '512M');
        
        $this->logger->log('info', "Processing products chunk {$chunk_index}", array(
            'sync_id' => $sync_id,
            'chunk_index' => $chunk_index,
            'memory_limit' => ini_get('memory_limit'),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ), 'batch_processor');
        
        // Get sync progress
        $progress = $this->get_sync_progress($sync_id);
        
        if (!$progress) {
            $this->logger->log('error', 'Sync progress not found', array('sync_id' => $sync_id), 'batch_processor');
            @ini_set('memory_limit', $original_memory);
            return;
        }
        
        // Load ONLY this chunk from transient
        $chunk = get_transient("bytemash_sync_{$sync_id}_chunk_{$chunk_index}");
        
        if (!$chunk || !is_array($chunk)) {
            $this->logger->log('error', 'Chunk data not found', array(
                'sync_id' => $sync_id,
                'chunk_index' => $chunk_index,
            ), 'batch_processor');
            @ini_set('memory_limit', $original_memory);
            return;
        }
        
        $chunk_size = count($chunk);
        $this->logger->log('info', "Chunk loaded: {$chunk_size} products", array(
            'sync_id' => $sync_id,
            'chunk_index' => $chunk_index,
            'chunk_size' => $chunk_size,
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ), 'batch_processor');
        
        // Suspend cache to reduce memory usage
        wp_suspend_cache_addition(true);
        
        // Process each product in chunk
        $product_sync = new ByteMash_Product_Sync();
        $processed = 0;
        $errors = 0;
        
        foreach ($chunk as $product_data) {
            $result = $product_sync->sync_single_product($product_data);
            
            if ($result['success']) {
                $processed++;
            } else {
                $errors++;
            }
            
            // Clear memory after each product
            unset($product_data, $result);
        }
        
        // Resume cache
        wp_suspend_cache_addition(false);
        
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
        
        $this->save_sync_progress($sync_id, $progress);
        
        $this->logger->log('info', "Chunk {$chunk_index} completed", array(
            'sync_id' => $sync_id,
            'processed' => $processed,
            'errors' => $errors,
            'total_progress' => $progress['processed'] . '/' . $progress['total'],
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ), 'batch_processor');
        
        // Schedule next chunk
        $next_chunk = $chunk_index + 1;
        
        if ($next_chunk < $progress['chunk_count']) {
            // Mark as ready for next chunk (will be picked up by AJAX polling)
            $progress['status'] = 'processing';
            $progress['current_chunk'] = $next_chunk;
            $this->save_sync_progress($sync_id, $progress);
            
            $this->logger->log('info', "Ready for next chunk: {$next_chunk}", array(
                'sync_id' => $sync_id,
                'next_chunk' => $next_chunk,
            ), 'batch_processor');
            
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
            
            $this->logger->log('success', 'All product chunks completed', array(
                'sync_id' => $sync_id,
                'total_processed' => $progress['processed'],
                'total_errors' => $progress['errors'],
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ), 'batch_processor');
        }
        
        // Restore original memory limit
        @ini_set('memory_limit', $original_memory);
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
        
        $this->logger->log('info', "Scheduling {$batch_count} batches for {$total} stock items", array(
            'sync_id' => $sync_id,
            'total' => $total,
        ), 'batch_processor');
        
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
     * Process stock batch
     */
    public function process_stock_batch($sync_id, $batch_index) {
        $progress = $this->get_sync_progress($sync_id);
        if (!$progress) return;
        
        $stock_data = get_transient("bytemash_sync_{$sync_id}_stock");
        if (!$stock_data) {
            $this->update_sync_status($sync_id, 'error', 'Cached data expired');
            return;
        }
        
        $batches = array_chunk($stock_data, 50);
        if (!isset($batches[$batch_index])) return;
        
        $batch = $batches[$batch_index];
        
        global $wpdb;
        $processed = 0;
        $errors = 0;
        
        foreach ($batch as $stock_item) {
            $simple_code = $stock_item['simpleCode'] ?? $stock_item['fullCode'] ?? null;
            
            if (!$simple_code) {
                $errors++;
                continue;
            }
            
            // Find product by SKU
            $product_id = wc_get_product_id_by_sku($simple_code);
            
            if (!$product_id) {
                $errors++;
                continue;
            }
            
            $product = wc_get_product($product_id);
            
            if ($product && isset($stock_item['stock'])) {
                $product->set_stock_quantity((int) $stock_item['stock']);
                $product->set_stock_status($stock_item['stock'] > 0 ? 'instock' : 'outofstock');
                $product->save();
                $processed++;
            } else {
                $errors++;
            }
        }
        
        // Update progress
        $progress['processed'] += $processed;
        $progress['current_batch'] = $batch_index + 1;
        $progress['errors'] = ($progress['errors'] ?? 0) + $errors;
        $this->save_sync_progress($sync_id, $progress);
        
        // Schedule next batch
        $next_batch = $batch_index + 1;
        
        if ($next_batch < $progress['batch_count']) {
            wp_schedule_single_event(time() + 3, 'bytemash_process_stock_batch', array($sync_id, $next_batch));
        } else {
            $progress['status'] = 'completed';
            $progress['completed'] = current_time('mysql');
            $this->save_sync_progress($sync_id, $progress);
            delete_transient("bytemash_sync_{$sync_id}_stock");
            
            $this->logger->log('success', 'Stock sync completed', array(
                'processed' => $progress['processed'],
                'errors' => $progress['errors'],
            ), 'batch_processor');
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
        
        $this->logger->log('info', "Scheduling {$batch_count} batches for {$total} price items", array(
            'sync_id' => $sync_id,
        ), 'batch_processor');
        
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
     * Process prices batch
     */
    public function process_prices_batch($sync_id, $batch_index) {
        $progress = $this->get_sync_progress($sync_id);
        if (!$progress) return;
        
        $prices_data = get_transient("bytemash_sync_{$sync_id}_prices");
        if (!$prices_data) {
            $this->update_sync_status($sync_id, 'error', 'Cached data expired');
            return;
        }
        
        $batches = array_chunk($prices_data, 100);
        if (!isset($batches[$batch_index])) return;
        
        $batch = $batches[$batch_index];
        $processed = 0;
        $errors = 0;
        
        foreach ($batch as $price_item) {
            $simple_code = $price_item['simplecode'] ?? $price_item['simpleCode'] ?? null;
            
            if (!$simple_code || !isset($price_item['price'])) {
                $errors++;
                continue;
            }
            
            $product_id = wc_get_product_id_by_sku($simple_code);
            
            if (!$product_id) {
                $errors++;
                continue;
            }
            
            $product = wc_get_product($product_id);
            
            if ($product) {
                $product->set_regular_price($price_item['price']);
                $product->save();
                $processed++;
            } else {
                $errors++;
            }
        }
        
        // Update progress
        $progress['processed'] += $processed;
        $progress['current_batch'] = $batch_index + 1;
        $progress['errors'] = ($progress['errors'] ?? 0) + $errors;
        $this->save_sync_progress($sync_id, $progress);
        
        // Schedule next batch
        $next_batch = $batch_index + 1;
        
        if ($next_batch < $progress['batch_count']) {
            wp_schedule_single_event(time() + 2, 'bytemash_process_prices_batch', array($sync_id, $next_batch));
        } else {
            $progress['status'] = 'completed';
            $progress['completed'] = current_time('mysql');
            $this->save_sync_progress($sync_id, $progress);
            delete_transient("bytemash_sync_{$sync_id}_prices");
            
            $this->logger->log('success', 'Prices sync completed', array(
                'processed' => $progress['processed'],
                'errors' => $progress['errors'],
            ), 'batch_processor');
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
    private function save_sync_progress($sync_id, $progress) {
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


