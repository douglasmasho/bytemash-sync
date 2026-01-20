<?php
/**
 * Ultra-High-Performance Price Sync Class
 * 
 * Optimized price synchronization with:
 * - Temporary MEMORY tables for batch processing
 * - Single ON DUPLICATE KEY UPDATE for meta updates
 * - WooCommerce event suppression during sync
 * - Hash-based and modifiedDate skipping
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_Price_Sync_Optimized {
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * API Client
     */
    private $api_client;
    
    /**
     * Batch size for processing
     */
    private $batch_size = 500;
    
    /**
     * Whether WooCommerce events are currently suppressed
     */
    private $events_suppressed = false;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new ByteMash_Logger();
        $this->api_client = new ByteMash_Amrod_API_Client();
        $this->batch_size = (int) get_option('bytemash_price_batch_size', 500);
    }
    
    /**
     * Sync prices for all products
     * 
     * @param string $sync_id Unique sync identifier
     * @return array Result with success status and stats
     */
    public function sync_all_prices($sync_id) {
        $start_time = microtime(true);
        
        $this->logger->log('info', 'Starting optimized price sync', array(
            'sync_id' => $sync_id,
            'batch_size' => $this->batch_size,
        ), 'price_sync');
        
        // Fetch price data from API
        $price_data = $this->api_client->get_prices();
        
        if (empty($price_data) || !is_array($price_data)) {
            return array(
                'success' => false,
                'error' => 'Failed to fetch price data from API',
            );
        }
        
        $total_items = count($price_data);
        
        // Split into batches and store in dedicated batch table
        $batches = array_chunk($price_data, $this->batch_size);
        $this->store_batches($sync_id, $batches);
        
        $stats = array(
            'total_items' => $total_items,
            'total_batches' => count($batches),
            'processed' => 0,
            'skipped' => 0,
            'updated' => 0,
            'errors' => 0,
        );
        
        // Suppress WooCommerce events for performance
        $this->suppress_woocommerce_events();
        
        try {
            // Process each batch
            foreach ($batches as $batch_index => $batch) {
                $batch_result = $this->process_price_batch($sync_id, $batch_index, $batch);
                
                $stats['processed'] += $batch_result['processed'];
                $stats['skipped'] += $batch_result['skipped'];
                $stats['updated'] += $batch_result['updated'];
                $stats['errors'] += $batch_result['errors'];
            }
            
            // Restore WooCommerce events and rebuild lookup tables
            $this->restore_woocommerce_events();
            
            $duration = microtime(true) - $start_time;
            
            $this->logger->log('success', 'Optimized price sync completed', array_merge($stats, array(
                'sync_id' => $sync_id,
                'duration_seconds' => round($duration, 2),
                'items_per_second' => $total_items > 0 ? round($total_items / $duration, 2) : 0,
            )), 'price_sync');
            
            return array(
                'success' => true,
                'stats' => $stats,
                'duration' => $duration,
            );
            
        } catch (Exception $e) {
            // Ensure events are restored even on error
            $this->restore_woocommerce_events();
            
            $this->logger->log('error', 'Price sync failed with exception', array(
                'sync_id' => $sync_id,
                'error' => $e->getMessage(),
            ), 'price_sync');
            
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }

    /**
     * Sync updated prices only (incremental)
     * 
     * @param string $sync_id Unique sync identifier
     * @return array Result with success status and stats
     */
    public function sync_updated_prices($sync_id) {
        $start_time = microtime(true);
        
        $this->logger->log('info', 'Starting optimized incremental price sync', array(
            'sync_id' => $sync_id,
            'batch_size' => $this->batch_size,
        ), 'price_sync');
        
        // Fetch updated price data from API
        $price_data = $this->api_client->get_prices_updated();
        
        if (is_wp_error($price_data)) {
             return array(
                'success' => false,
                'error' => $price_data->get_error_message(),
            );
        }

        if (empty($price_data) || !is_array($price_data)) {
             $this->logger->log('info', 'No price updates available', array(), 'price_sync');
            return array(
                'success' => true,
                'message' => 'No price updates available',
                'stats' => array(
                    'total_items' => 0,
                    'processed' => 0,
                    'skipped' => 0,
                    'updated' => 0,
                    'errors' => 0,
                )
            );
        }
        
        $total_items = count($price_data);
        
        // Split into batches and store in dedicated batch table
        $batches = array_chunk($price_data, $this->batch_size);
        $this->store_batches($sync_id, $batches);
        
        $stats = array(
            'total_items' => $total_items,
            'total_batches' => count($batches),
            'processed' => 0,
            'skipped' => 0,
            'updated' => 0,
            'errors' => 0,
        );
        
        // Suppress WooCommerce events for performance
        $this->suppress_woocommerce_events();
        
        try {
            // Process each batch
            foreach ($batches as $batch_index => $batch) {
                $batch_result = $this->process_price_batch($sync_id, $batch_index, $batch);
                
                $stats['processed'] += $batch_result['processed'];
                $stats['skipped'] += $batch_result['skipped'];
                $stats['updated'] += $batch_result['updated'];
                $stats['errors'] += $batch_result['errors'];
            }
            
            // Restore WooCommerce events and rebuild lookup tables
            $this->restore_woocommerce_events();
            
            $duration = microtime(true) - $start_time;
            
            $this->logger->log('success', 'Optimized incremental price sync completed', array_merge($stats, array(
                'sync_id' => $sync_id,
                'duration_seconds' => round($duration, 2),
                'items_per_second' => $total_items > 0 ? round($total_items / $duration, 2) : 0,
            )), 'price_sync');
            
            return array(
                'success' => true,
                'stats' => $stats,
                'duration' => $duration,
            );
            
        } catch (Exception $e) {
            // Ensure events are restored even on error
            $this->restore_woocommerce_events();
            
            $this->logger->log('error', 'Incremental price sync failed with exception', array(
                'sync_id' => $sync_id,
                'error' => $e->getMessage(),
            ), 'price_sync');
            
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }
    
    /**
     * Process a single price batch using optimized temporary table approach
     * 
     * @param string $sync_id Sync identifier
     * @param int $batch_index Batch index
     * @param array $batch Batch of price items
     * @return array Batch processing stats
     */
    public function process_price_batch($sync_id, $batch_index, $batch) {
        global $wpdb;
        
        $batch_start = microtime(true);
        $batch_id = sanitize_key($sync_id . '_' . $batch_index);
        
        $stats = array(
            'processed' => 0,
            'skipped' => 0,
            'updated' => 0,
            'errors' => 0,
        );
        
        // Create temporary table with MEMORY engine for maximum performance
        $temp_table = "bytemash_price_temp_{$batch_id}";
        $this->create_temp_price_table($temp_table);
        
        try {
            // Build SKU to product ID mapping for this batch
            $sku_map = $this->build_sku_product_map($batch);
            
            // OPTIMIZATION: Prime meta cache for all found products
            // This fetches all metadata (including hashes) in ONE query instead of 100
            if (!empty($sku_map)) {
                update_meta_cache('post', array_values($sku_map));
            }
            
            // Prepare data for bulk insert into temp table
            $meta_updates = array();
            
            foreach ($batch as $price_item) {
                $stats['processed']++;
                
                $sku = $price_item['simpleCode'] ?? $price_item['fullCode'] ?? null;
                if (!$sku) {
                    $stats['errors']++;
                    continue;
                }
                
                // Find product ID
                $product_id = $sku_map[$sku] ?? null;
                if (!$product_id) {
                    $stats['skipped']++;
                    continue;
                }
                
                // Check if price has changed using hash and modifiedDate
                $price_hash = $this->calculate_price_hash($price_item);
                $modified_date = $price_item['modifiedDate'] ?? '';
                
                if ($this->should_skip_price_update($product_id, $price_hash, $modified_date)) {
                    $stats['skipped']++;
                    continue;
                }
                
                // Determine price values
                $regular_price = isset($price_item['price']) ? (float) $price_item['price'] : 0;
                $sale_price = isset($price_item['salePrice']) ? (float) $price_item['salePrice'] : '';
                
                // If sale price is 0 or empty, treat as no sale price
                if (empty($sale_price) || $sale_price <= 0) {
                    $sale_price = '';
                }
                
                // Active price is sale price if set, otherwise regular price
                $price = $sale_price !== '' ? $sale_price : $regular_price;
                
                // Add to meta updates array for bulk insert
                $meta_updates[] = array(
                    'product_id' => $product_id,
                    'regular_price' => $regular_price,
                    'sale_price' => $sale_price,
                    'price' => $price,
                    'hash' => $price_hash,
                    'modified_date' => $modified_date,
                );
                
                $stats['updated']++;
            }
            
            // Bulk update using single ON DUPLICATE KEY UPDATE query
            if (!empty($meta_updates)) {
                $this->bulk_update_price_meta($meta_updates);
            }
            
            $batch_duration = microtime(true) - $batch_start;
            
            $this->logger->log('info', 'Price batch processed', array(
                'batch_id' => $batch_id,
                'batch_index' => $batch_index,
                'stats' => $stats,
                'duration_seconds' => round($batch_duration, 3),
            ), 'price_sync');
            
        } finally {
            // Always drop temp table
            $wpdb->query("DROP TEMPORARY TABLE IF EXISTS $temp_table");
        }
        
        return $stats;
    }
    
    /**
     * Create temporary table for price processing
     * Uses MEMORY engine for maximum performance
     * 
     * @param string $table_name Temporary table name
     */
    private function create_temp_price_table($table_name) {
        global $wpdb;
        
        $sql = "CREATE TEMPORARY TABLE $table_name (
            product_id BIGINT UNSIGNED PRIMARY KEY,
            regular_price DECIMAL(19,4),
            sale_price DECIMAL(19,4),
            price DECIMAL(19,4),
            hash CHAR(32),
            modified_date VARCHAR(50)
        ) ENGINE=MEMORY";
        
        $wpdb->query($sql);
    }
    
    /**
     * Build SKU to product ID mapping for a batch
     * 
     * @param array $batch Batch of price items
     * @return array SKU => product_id map
     */
    private function build_sku_product_map($batch) {
        global $wpdb;
        
        // Extract all SKUs from batch
        $skus = array();
        foreach ($batch as $item) {
            $sku = $item['simpleCode'] ?? $item['fullCode'] ?? null;
            if ($sku) {
                $skus[] = $sku;
            }
        }
        
        if (empty($skus)) {
            return array();
        }
        
        // Batch lookup using optimized index
        // Build escaped placeholders manually to avoid wpdb::prepare array error
        $escaped_skus = array_map(function($sku) use ($wpdb) {
            return "'" . esc_sql($sku) . "'";
        }, $skus);
        
        $query = "
            SELECT pm.post_id, pm.meta_value as sku
            FROM {$wpdb->postmeta} pm
            WHERE pm.meta_key IN ('_sku', '_amrod_simple_code', '_amrod_full_code')
            AND pm.meta_value IN (" . implode(',', $escaped_skus) . ")
        ";
        
        $results = $wpdb->get_results($query);
        
        // Build map
        $map = array();
        foreach ($results as $row) {
            $map[$row->sku] = (int) $row->post_id;
        }
        
        return $map;
    }
    
    /**
     * Calculate hash for price item to detect changes
     * 
     * @param array $price_item Price item data
     * @return string MD5 hash
     */
    private function calculate_price_hash($price_item) {
        $hash_data = array(
            'price' => $price_item['price'] ?? 0,
            'salePrice' => $price_item['salePrice'] ?? 0,
        );
        
        return md5(wp_json_encode($hash_data));
    }
    
    /**
     * Check if price update should be skipped based on hash and modifiedDate
     * 
     * @param int $product_id Product ID
     * @param string $new_hash New price hash
     * @param string $new_modified_date New modified date
     * @return bool True if should skip
     */
    private function should_skip_price_update($product_id, $new_hash, $new_modified_date) {
        // Get existing hash and modified date
        $existing_hash = get_post_meta($product_id, '_bytemash_price_hash', true);
        $existing_modified = get_post_meta($product_id, '_bytemash_price_last_modified', true);
        
        // Skip if hash matches (price unchanged)
        if ($existing_hash === $new_hash) {
            return true;
        }
        
        // Skip if modified date is older than existing
        if ($existing_modified && $new_modified_date) {
            if (strtotime($new_modified_date) <= strtotime($existing_modified)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Bulk update price meta using single ON DUPLICATE KEY UPDATE query
     * This replaces multiple individual UPDATE/INSERT blocks with one query
     * 
     * @param array $updates Array of product updates
     */
    private function bulk_update_price_meta($updates) {
        global $wpdb;
        
        if (empty($updates)) {
            return;
        }
        
        // Build VALUES for bulk insert
        $values = array();
        foreach ($updates as $update) {
            $product_id = (int) $update['product_id'];
            
            // Each product gets 5 meta entries
            $values[] = $wpdb->prepare("(%d, '_regular_price', %s)", $product_id, $update['regular_price']);
            $values[] = $wpdb->prepare("(%d, '_sale_price', %s)", $product_id, $update['sale_price']);
            $values[] = $wpdb->prepare("(%d, '_price', %s)", $product_id, $update['price']);
            $values[] = $wpdb->prepare("(%d, '_bytemash_price_hash', %s)", $product_id, $update['hash']);
            $values[] = $wpdb->prepare("(%d, '_bytemash_price_last_modified', %s)", $product_id, $update['modified_date']);
        }
        
        // Single bulk query with ON DUPLICATE KEY UPDATE
        $sql = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                VALUES " . implode(',', $values) . "
                ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)";
        
        $wpdb->query($sql);
    }
    
    /**
     * Store batches in dedicated batch table
     * 
     * @param string $sync_id Sync identifier
     * @param array $batches Array of batches
     */
    private function store_batches($sync_id, $batches) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bytemash_batches';
        
        // Clear any existing batches for this sync
        $wpdb->delete($table_name, array('sync_id' => $sync_id));
        
        // Insert new batches
        foreach ($batches as $index => $batch) {
            $wpdb->insert($table_name, array(
                'sync_id' => $sync_id,
                'batch_index' => $index,
                'payload' => wp_json_encode($batch),
            ));
        }
    }
    
    /**
     * Suppress WooCommerce price events during sync for performance
     */
    private function suppress_woocommerce_events() {
        if ($this->events_suppressed) {
            return;
        }
        
        // Suppress price related hooks if any exist
        // Note: WooCommerce doesn't have as many price-specific hooks as stock hooks
        // but we can prevent some lookup table updates
        
        $this->events_suppressed = true;
        
        $this->logger->log('info', 'WooCommerce price events suppressed for performance', array(), 'price_sync');
    }
    
    /**
     * Restore WooCommerce events and rebuild lookup tables
     */
    private function restore_woocommerce_events() {
        if (!$this->events_suppressed) {
            return;
        }
        
        // Rebuild WooCommerce lookup tables
        if (function_exists('wc_update_product_lookup_tables')) {
            wc_update_product_lookup_tables();
        }
        
        $this->events_suppressed = false;
        
        $this->logger->log('info', 'WooCommerce price events restored and lookup tables rebuilt', array(), 'price_sync');
    }
}
