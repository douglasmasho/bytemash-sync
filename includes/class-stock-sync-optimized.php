<?php
/**
 * Ultra-High-Performance Stock Sync Class
 * 
 * Optimized stock synchronization with:
 * - Temporary MEMORY tables for batch processing
 * - Single ON DUPLICATE KEY UPDATE for meta updates
 * - WooCommerce event suppression during sync
 * - Hash-based and modifiedDate skipping
 * - Support for Amrod stock types (0, 1, 2)
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_Stock_Sync_Optimized {
    
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
    private $batch_size = 100;
    
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
        $this->batch_size = (int) get_option('bytemash_stock_batch_size', 100);
    }
    
    /**
     * Sync stock for all products
     * 
     * @param string $sync_id Unique sync identifier
     * @return array Result with success status and stats
     */
    public function sync_all_stock($sync_id) {
        $start_time = microtime(true);
        
        $this->logger->log('info', 'Starting optimized stock sync', array(
            'sync_id' => $sync_id,
            'batch_size' => $this->batch_size,
        ), 'stock_sync');
        
        // Fetch stock data from API
        $stock_data = $this->api_client->get_stock();
        
        if (empty($stock_data) || !is_array($stock_data)) {
            return array(
                'success' => false,
                'error' => 'Failed to fetch stock data from API',
            );
        }
        
        if (function_exists('bytemash_maybe_save_stock_response_json')) {
            bytemash_maybe_save_stock_response_json($stock_data);
        }
        
        $total_items = count($stock_data);
        
        // Split into batches and store in dedicated batch table
        $batches = array_chunk($stock_data, $this->batch_size);
        $this->store_batches($sync_id, $batches);
        
        $stats = array(
            'total_items' => $total_items,
            'total_batches' => count($batches),
            'processed' => 0,
            'skipped' => 0,
            'updated' => 0,
            'errors' => 0,
        );
        
        // Do not suppress WooCommerce events: each product is updated via WC API (set_stock_quantity + save)
        // so lookup tables and caches stay in sync as each product is processed in the batch.
        try {
            // Process each batch; each product is updated via WooCommerce API as it is processed
            foreach ($batches as $batch_index => $batch) {
                $batch_result = $this->process_stock_batch($sync_id, $batch_index, $batch);
                
                $stats['processed'] += $batch_result['processed'];
                $stats['skipped'] += $batch_result['skipped'];
                $stats['updated'] += $batch_result['updated'];
                $stats['errors'] += $batch_result['errors'];
            }
            
            // Lookup tables are updated per product on save(); optional full rebuild if needed
            if (function_exists('wc_update_product_lookup_tables')) {
                wc_update_product_lookup_tables();
            }
            
            $duration = microtime(true) - $start_time;
            
            $this->logger->log('success', 'Optimized stock sync completed', array_merge($stats, array(
                'sync_id' => $sync_id,
                'duration_seconds' => round($duration, 2),
                'items_per_second' => $total_items > 0 ? round($total_items / $duration, 2) : 0,
            )), 'stock_sync');
            
            return array(
                'success' => true,
                'stats' => $stats,
                'duration' => $duration,
            );
            
        } catch (Exception $e) {
            $this->logger->log('error', 'Stock sync failed with exception', array(
                'sync_id' => $sync_id,
                'error' => $e->getMessage(),
            ), 'stock_sync');
            
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }
    
    /**
     * Process a single stock batch using optimized temporary table approach
     * 
     * @param string $sync_id Sync identifier
     * @param int $batch_index Batch index
     * @param array $batch Batch of stock items
     * @return array Batch processing stats
     */
    public function process_stock_batch($sync_id, $batch_index, $batch) {
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
        $temp_table = "bytemash_stock_temp_{$batch_id}";
        $this->create_temp_stock_table($temp_table);
        
        try {
            // Build SKU to product ID mapping for this batch
            $sku_map = $this->build_sku_product_map($batch);
            $sku_map_size = count($sku_map);
            $this->logger->log('info', 'Stock batch SKU map built', array(
                'batch_index' => $batch_index,
                'batch_items' => count($batch),
                'sku_map_entries' => $sku_map_size,
                'sample_skus_matched' => array_slice(array_keys($sku_map), 0, 5),
            ), 'stock_sync');
            
            // OPTIMIZATION: Prime meta cache for all found products
            if (!empty($sku_map)) {
                update_meta_cache('post', array_values($sku_map));
            }
            
            $meta_updates = array();
            $skipped_codes_sample = array();
            
            foreach ($batch as $stock_item) {
                $stats['processed']++;
                
                // Match by fullCode only (so each variant gets its own API stock; no simpleCode lookup)
                $sku = $stock_item['fullCode'] ?? null;
                if (!$sku) {
                    $stats['skipped']++;
                    continue;
                }
                
                $entry = $sku_map[$sku] ?? null;
                $product_id = is_array($entry) ? ($entry['product_id'] ?? null) : $entry;
                $post_type = is_array($entry) ? ($entry['post_type'] ?? null) : null;
                // For variations: only sync stockType 2 (sellable colour+size). Skip stockType 0/1 so shop total matches API.
                // For simple products: allow any stockType (they often only have 0 or 1).
                if ($post_type === 'product_variation') {
                    $stock_type = isset($stock_item['stockType']) ? (int) $stock_item['stockType'] : 2;
                    if ($stock_type !== 2) {
                        $stats['skipped']++;
                        continue;
                    }
                }
                
                if (!$product_id) {
                    $stats['skipped']++;
                    if (count($skipped_codes_sample) < 10) {
                        $skipped_codes_sample[] = $sku;
                    }
                    continue;
                }
                
                // Always apply API stock when we have a matching product (no hash skip).
                $stock_hash = $this->calculate_stock_hash($stock_item);
                $modified_date = $stock_item['modifiedDate'] ?? '';
                
                // Determine stock values from API (support 'stock' or 'Stock')
                $stock_qty = (int) ($stock_item['stock'] ?? $stock_item['Stock'] ?? 0);
                $stock_status = $stock_qty > 0 ? 'instock' : 'outofstock';
                $manage_stock = 'yes';
                $backorders = 'no';
                
                // Store API payload for this product so modal/display use correct fullCode and values
                $incoming = isset($stock_item['incomingStock']) && is_array($stock_item['incomingStock']) ? $stock_item['incomingStock'] : array();
                $reserved = (int) ($stock_item['reservedStock'] ?? 0);
                $full_code = $stock_item['fullCode'] ?? '';
                $simple_code = $stock_item['simpleCode'] ?? $stock_item['simplecode'] ?? '';
                $stock_detail = array(
                    'fullCode' => $full_code,
                    'simpleCode' => $simple_code,
                    'stock' => $stock_qty,
                    'reservedStock' => $reserved,
                    'reserved' => $reserved,
                    'incomingStock' => $incoming,
                    'incoming' => $incoming,
                    'modifiedDate' => $modified_date,
                );
                
                // Add to meta updates array for bulk insert (include code for logging)
                $meta_updates[] = array(
                    'product_id' => $product_id,
                    'stock' => $stock_qty,
                    'stock_status' => $stock_status,
                    'manage_stock' => $manage_stock,
                    'backorders' => $backorders,
                    'hash' => $stock_hash,
                    'modified_date' => $modified_date,
                    'stock_detail' => $stock_detail,
                    'fullCode' => $full_code,
                    'simpleCode' => $simple_code,
                );
                
                $stats['updated']++;
            }
            
            if (!empty($skipped_codes_sample)) {
                $this->logger->log('info', 'Stock batch: items skipped (no WooCommerce product match for SKU)', array(
                    'batch_index' => $batch_index,
                    'skipped_count' => $stats['skipped'],
                    'sample_skus_not_found' => $skipped_codes_sample,
                ), 'stock_sync');
            }
            
            // One update per product: when multiple fullCodes map to same product_id (e.g. duplicate meta), keep the update whose fullCode matches the product's _sku so we write the correct API value
            $by_product = array();
            foreach ($meta_updates as $u) {
                $pid = (int) $u['product_id'];
                if (!isset($by_product[$pid])) {
                    $by_product[$pid] = array();
                }
                $by_product[$pid][] = $u;
            }
            $product_ids = array_keys($by_product);
            $product_skus = array();
            if (!empty($product_ids)) {
                $ids_placeholder = implode(',', array_map('absint', $product_ids));
                $rows = $wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$ids_placeholder}) AND meta_key = '_sku'");
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        $product_skus[(int) $row->post_id] = $row->meta_value !== null ? (string) $row->meta_value : '';
                    }
                }
            }
            $meta_updates = array();
            foreach ($by_product as $pid => $updates) {
                $product_sku = isset($product_skus[$pid]) ? (string) $product_skus[$pid] : '';
                $product_sku_lower = strtolower(trim($product_sku));
                $chosen = null;
                foreach ($updates as $u) {
                    $code = (string) ($u['fullCode'] ?? '');
                    if (strtolower(trim($code)) === $product_sku_lower) {
                        $chosen = $u;
                        break;
                    }
                }
                if ($chosen === null) {
                    $chosen = $updates[0];
                }
                if (count($updates) > 1) {
                    $this->logger->log('info', 'Stock batch: multiple API rows mapped to same product_id, kept row matching product _sku', array(
                        'batch_index' => $batch_index,
                        'product_id' => $pid,
                        'product_sku' => $product_sku,
                        'chosen_fullCode' => $chosen['fullCode'] ?? '',
                        'chosen_stock_from_api' => (int) ($chosen['stock'] ?? 0),
                    ), 'stock_sync');
                }
                $meta_updates[] = $chosen;
            }
            
            // Fast bulk update: postmeta + WooCommerce lookup table (no full product save per item)
            if (!empty($meta_updates)) {
                $this->bulk_update_stock_meta($meta_updates, $batch_index);
            }

            $batch_duration = microtime(true) - $batch_start;
            
            $this->logger->log('info', 'Stock batch processed', array(
                'batch_id' => $batch_id,
                'batch_index' => $batch_index,
                'stats' => $stats,
                'duration_seconds' => round($batch_duration, 3),
            ), 'stock_sync');
            
        } finally {
            // Always drop temp table
            $wpdb->query("DROP TEMPORARY TABLE IF EXISTS $temp_table");
        }
        
        return $stats;
    }
    
    /**
     * Create temporary table for stock processing
     * Uses MEMORY engine for maximum performance
     * 
     * @param string $table_name Temporary table name
     */
    private function create_temp_stock_table($table_name) {
        global $wpdb;
        
        $sql = "CREATE TEMPORARY TABLE $table_name (
            product_id BIGINT UNSIGNED PRIMARY KEY,
            stock INT,
            stock_status VARCHAR(20),
            manage_stock VARCHAR(10),
            backorders VARCHAR(10),
            hash CHAR(32),
            modified_date VARCHAR(50)
        ) ENGINE=MEMORY";
        
        $wpdb->query($sql);
    }
    
    /**
     * Build SKU to product ID mapping for a batch using fullCode only.
     * Case-insensitive match so API and DB casing differences don't miss updates.
     * Prefers variations over parents; never updates variable parent stock (only variations and simple products).
     *
     * @param array $batch Batch of stock items
     * @return array fullCode (as in API) => array('product_id' => int, 'post_type' => string) or product_id (legacy)
     */
    private function build_sku_product_map($batch) {
        global $wpdb;
        
        $skus = array();
        foreach ($batch as $item) {
            $full = $item['fullCode'] ?? null;
            if ($full !== null && $full !== '') {
                $skus[] = $full;
            }
        }
        $skus = array_unique(array_filter($skus));
        if (empty($skus)) {
            return array();
        }
        
        // Case-insensitive: match DB meta_value to batch fullCodes via LOWER()
        $skus_lower = array_map('strtolower', $skus);
        $escaped = array_map(function ($s) use ($wpdb) {
            return "'" . esc_sql(strtolower($s)) . "'";
        }, $skus_lower);
        $in_list = implode(',', array_unique($escaped));
        
        $query = "
            SELECT pm.post_id, pm.meta_value as sku, p.post_type
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type IN ('product', 'product_variation')
            WHERE pm.meta_key IN ('_sku', '_amrod_full_code')
            AND LOWER(TRIM(pm.meta_value)) IN ({$in_list})
        ";
        $results = $wpdb->get_results($query);
        
        // Key by lowercase SKU so we can look up by batch fullCode (any case)
        $by_lower = array();
        foreach ($results as $row) {
            $key = strtolower(trim($row->sku));
            if (!isset($by_lower[$key])) {
                $by_lower[$key] = array();
            }
            $by_lower[$key][] = array('post_id' => (int) $row->post_id, 'post_type' => $row->post_type);
        }
        
        // Variable product parent IDs: we never write stock to these (only variations/simple)
        $variable_parent_ids = array();
        $parents = $wpdb->get_col("SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_parent > 0");
        if (is_array($parents)) {
            $variable_parent_ids = array_map('intval', $parents);
        }
        
        $map = array();
        foreach ($skus as $api_full_code) {
            $key = strtolower(trim($api_full_code));
            $candidates = isset($by_lower[$key]) ? $by_lower[$key] : array();
            $variation = null;
            $product = null;
            foreach ($candidates as $c) {
                if ($c['post_type'] === 'product_variation') {
                    $variation = $c['post_id'];
                } else {
                    $product = $c['post_id'];
                }
            }
            $chosen_id = null;
            $chosen_type = null;
            if ($variation !== null) {
                $chosen_id = $variation;
                $chosen_type = 'product_variation';
            } elseif ($product !== null && !in_array($product, $variable_parent_ids, true)) {
                $chosen_id = $product;
                $chosen_type = 'product';
            }
            $map[$api_full_code] = $chosen_id !== null ? array('product_id' => $chosen_id, 'post_type' => $chosen_type) : null;
        }
        
        return $map;
    }
    
    /**
     * Calculate hash for stock item to detect changes
     * 
     * @param array $stock_item Stock item data
     * @return string MD5 hash
     */
    private function calculate_stock_hash($stock_item) {
        $hash_data = array(
            'stock' => $stock_item['stock'] ?? 0,
            'stockType' => $stock_item['stockType'] ?? 0,
            'reservedStock' => $stock_item['reservedStock'] ?? 0,
        );
        
        return md5(wp_json_encode($hash_data));
    }
    
    /**
     * Check if stock update should be skipped based on hash and modifiedDate
     * 
     * @param int $product_id Product ID
     * @param string $new_hash New stock hash
     * @param string $new_modified_date New modified date
     * @return bool True if should skip
     */
    private function should_skip_stock_update($product_id, $new_hash, $new_modified_date) {
        // Get existing hash and modified date
        $existing_hash = get_post_meta($product_id, '_bytemash_stock_hash', true);
        $existing_modified = get_post_meta($product_id, '_bytemash_stock_last_modified', true);
        
        // Skip if hash matches (stock unchanged)
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
     * Bulk update stock: postmeta (fast SQL) + WooCommerce lookup table so catalog/frontend show correct stock.
     * No full product save per item — fast and keeps WC in sync.
     *
     * @param array $updates Array of product updates (each has product_id, stock, stock_status, stock_detail, etc.)
     * @param int   $batch_index Batch index for logging
     */
    private function bulk_update_stock_meta($updates, $batch_index = 0) {
        global $wpdb;

        if (empty($updates)) {
            return;
        }

        $product_ids = array_unique(array_map(function ($u) { return (int) $u['product_id']; }, $updates));
        $ids_placeholder = implode(',', array_map('absint', $product_ids));

        // 1. Bulk replace postmeta (fast)
        $meta_keys = array('_stock', '_stock_status', '_manage_stock', '_backorders', '_amrod_stock_detail', '_bytemash_stock_hash', '_bytemash_stock_last_modified');
        $keys_escaped = array_map(function ($k) use ($wpdb) { return "'" . esc_sql($k) . "'"; }, $meta_keys);
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$ids_placeholder}) AND meta_key IN (" . implode(',', $keys_escaped) . ")");

        $values = array();
        foreach ($updates as $update) {
            $product_id = (int) $update['product_id'];
            $values[] = $wpdb->prepare("(%d, '_stock', %s)", $product_id, (string) (int) $update['stock']);
            $values[] = $wpdb->prepare("(%d, '_stock_status', %s)", $product_id, $update['stock_status']);
            $values[] = $wpdb->prepare("(%d, '_manage_stock', %s)", $product_id, $update['manage_stock']);
            $values[] = $wpdb->prepare("(%d, '_backorders', %s)", $product_id, $update['backorders']);
            $stock_detail_serialized = isset($update['stock_detail']) && is_array($update['stock_detail']) ? maybe_serialize($update['stock_detail']) : '';
            $values[] = $wpdb->prepare("(%d, '_amrod_stock_detail', %s)", $product_id, $stock_detail_serialized);
            $values[] = $wpdb->prepare("(%d, '_bytemash_stock_hash', %s)", $product_id, $update['hash']);
            $values[] = $wpdb->prepare("(%d, '_bytemash_stock_last_modified', %s)", $product_id, $update['modified_date']);
        }
        $sql = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode(',', $values);
        $wpdb->query($sql);

        // 2. Update WooCommerce lookup table so catalog/frontend show correct stock (lightweight UPDATEs, no product load)
        $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
        if ($wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($lookup_table) . "'") === $lookup_table) {
            $by_id = array();
            foreach ($updates as $u) {
                $by_id[(int) $u['product_id']] = $u;
            }
            foreach ($product_ids as $pid) {
                $u = isset($by_id[$pid]) ? $by_id[$pid] : null;
                if (!$u) {
                    continue;
                }
                $qty = (int) ($u['stock'] ?? 0);
                $status = $qty > 0 ? 'instock' : 'outofstock';
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$lookup_table} SET stock_quantity = %d, stock_status = %s WHERE product_id = %d",
                    $qty,
                    $status,
                    $pid
                ));
            }
        }

        // 3. Clear product transients (and parent transients for variations so variable product stock status reflects)
        foreach ($product_ids as $pid) {
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($pid);
            }
            $post = get_post($pid);
            if ($post && $post->post_type === 'product_variation' && $post->post_parent > 0) {
                if (function_exists('wc_delete_product_transients')) {
                    wc_delete_product_transients((int) $post->post_parent);
                }
                delete_transient('wc_product_children_' . (int) $post->post_parent);
            }
        }
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
     * Suppress WooCommerce stock events during sync for performance
     */
    private function suppress_woocommerce_events() {
        if ($this->events_suppressed) {
            return;
        }
        
        add_filter('woocommerce_sync_variation_stock_status', '__return_false');
        add_filter('woocommerce_product_set_stock', '__return_false');
        add_filter('woocommerce_variation_set_stock', '__return_false');
        remove_all_actions('woocommerce_product_set_stock_status');
        remove_all_actions('woocommerce_variation_set_stock_status');
        
        $this->events_suppressed = true;
        
        $this->logger->log('info', 'WooCommerce stock events suppressed for performance', array(), 'stock_sync');
    }
    
    /**
     * Restore WooCommerce events and rebuild lookup tables
     */
    private function restore_woocommerce_events() {
        if (!$this->events_suppressed) {
            return;
        }
        
        remove_filter('woocommerce_sync_variation_stock_status', '__return_false');
        remove_filter('woocommerce_product_set_stock', '__return_false');
        remove_filter('woocommerce_variation_set_stock', '__return_false');
        
        // Rebuild WooCommerce lookup tables
        if (function_exists('wc_update_product_lookup_tables')) {
            wc_update_product_lookup_tables();
        }
        
        if (function_exists('wc_recount_terms')) {
            wc_recount_terms();
        }
        
        $this->events_suppressed = false;
        
        $this->logger->log('info', 'WooCommerce stock events restored and lookup tables rebuilt', array(), 'stock_sync');
    }
}
