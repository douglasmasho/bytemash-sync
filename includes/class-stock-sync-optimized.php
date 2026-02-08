<?php
/**
 * Ultra-High-Performance Stock Sync Class
 * 
 * Optimized stock synchronization with:
 * - Database buffer table for streaming large JSON responses
 * - Bulk SQL JOIN for SKU-to-Product ID mapping
 * - Smart skipping based on exact stock quantity matches
 * - Support for Amrod stock types (0, 1, 2) - filters out aggregate type 0
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
     * Constructor
     */
    public function __construct() {
        $this->logger = new ByteMash_Logger();
        $this->api_client = new ByteMash_Amrod_API_Client();
    }
    
    /**
     * Sync stock for all products (Overhauled Streaming Version)
     * 
     * @param string $sync_id Unique sync identifier
     * @return array Result with success status and stats
     */
    public function sync_all_stock($sync_id) {
        $start_time = microtime(true);
        global $wpdb;
        
        $log_file = WP_CONTENT_DIR . '/uploads/bytemash_stock_debug_batches.log';
        
        // Increase time limit
        if (!ini_get('safe_mode')) {
            @set_time_limit(600);
        }

        $this->logger->log('info', 'Starting Overhauled Streaming Stock Sync', array(
            'sync_id' => $sync_id,
        ), 'stock_sync');
        
        // 1. Setup Buffer Table
        $this->logger->log('info', 'Cleaning up previous sync data...', array(), 'stock_sync');
        $buffer_table = $wpdb->prefix . 'bytemash_stock_buffer';
        $this->create_stock_buffer_table($buffer_table);
        
        // Clear buffer for this sync
        $wpdb->query("TRUNCATE TABLE $buffer_table");
        $this->logger->log('info', 'Buffer table ready. Fetching data from Amrod API...', array(), 'stock_sync');

        // 2. Download Stock Data to File
        $uploads_dir = wp_upload_dir();
        $file_path = $uploads_dir['basedir'] . '/stock_sync_' . $sync_id . '.json';
        
        $download_result = $this->api_client->download_to_file('api/v1/Stock/', $file_path);
    
    // Log initialization start
    $init_log = sprintf("[%s] INITIALIZING STOCK SYNC | Endpoint: api/v1/Stock/ | File: %s\n", date('Y-m-d H:i:s'), basename($file_path));
    @error_log($init_log, 3, $log_file);
        
        if (is_wp_error($download_result)) {
            $this->logger->log('error', 'Stock sync failed: API download error', array('error' => $download_result->get_error_message()), 'stock_sync');
            return array(
                'success' => false,
                'error' => 'Failed to download stock data: ' . $download_result->get_error_message(),
            );
        }

        // 3. Stream JSON to Buffer Table
        $streaming_stats = $this->stream_json_to_buffer($file_path, $buffer_table);
        
        // Delete temp file
        @unlink($file_path);
        
        if (!$streaming_stats['success']) {
            $this->logger->log('error', 'Stock sync failed: Streaming error', array('error' => $streaming_stats['error']), 'stock_sync');
            return array(
                'success' => false,
                'error' => 'Failed to stream JSON to buffer: ' . $streaming_stats['error'],
            );
        }

        $total_items = $streaming_stats['inserted'];

        // 4. Map Buffer to Products (The Bulk SQL Join)
        $mapping_stats = $this->map_buffer_to_products($buffer_table);

        // 5. Initialize Sync Info for AJAX processing
        $batch_size = (int) get_option('bytemash_stock_batch_size', 500);
        $total_batches = (int) ceil($total_items / $batch_size);
        
        // Populate the standard sync queue with placeholders (keeps AJAX polling alive)
        $this->populate_sync_queue_placeholders($sync_id, $total_batches);
        
        // Update sync option for the AJAX processor
        update_option("bytemash_sync_{$sync_id}", array(
            'type' => 'stock',
            'total' => $total_items,
            'batch_count' => $total_batches,
            'batch_size' => $batch_size,
            'current_batch' => 0,
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'status' => 'processing',
            'started' => current_time('mysql'),
            'buffer_table' => $buffer_table,
        ), false);

        $duration = microtime(true) - $start_time;
        
        $this->logger->log('success', 'Stock Sync Initialized (Buffer Ready)', array(
            'sync_id' => $sync_id,
            'total_items' => $total_items,
            'duration_seconds' => round($duration, 2),
            'matched_products' => $mapping_stats['matched'],
        ), 'stock_sync');
        
        return array(
            'success' => true,
            'stats' => array(
                'total_items' => $total_items,
                'batch_count' => $total_batches,
            ),
            'duration' => $duration,
            'ready_for_batches' => true,
        );
    }
    
    /**
     * Process a single stock batch using buffer-based streaming architecture
     * 
     * @param string $sync_id Sync identifier
     * @param int $batch_index Batch index
     * @param array $batch Legacy batch data (ignored in overhaul as we pull from buffer)
     * @return array Batch processing stats
     */
    public function process_stock_batch($sync_id, $batch_index, $batch = array()) {
        global $wpdb;
        
        $batch_start = microtime(true);
        $log_file = WP_CONTENT_DIR . '/uploads/bytemash_stock_debug_batches.log';
        $buffer_table = $wpdb->prefix . 'bytemash_stock_buffer';
        
        // Always log batch execution for diagnostics
        $total_in_buffer = $wpdb->get_var("SELECT COUNT(*) FROM $buffer_table");
        $log_entry = sprintf(
            "[%s] EXEC BATCH #%d | Sync: %s | Buffer Total: %d | Mem: %s\n",
            date('Y-m-d H:i:s'),
            $batch_index,
            $sync_id,
            $total_in_buffer,
            round(memory_get_usage(true) / 1024 / 1024, 2) . 'MB'
        );
        @error_log($log_entry, 3, $log_file);

        $stats = array(
            'processed' => 0,
            'skipped' => 0,
            'updated' => 0,
            'errors' => 0,
        );
        
        // 1. Retrieve Data from Buffer Table
        $sync_info = get_option("bytemash_sync_{$sync_id}");
        $buffer_table = $sync_info['buffer_table'] ?? ($wpdb->prefix . 'bytemash_stock_buffer');
        $batch_size = (int) ($sync_info['batch_size'] ?? 500);
        $offset = $batch_index * $batch_size;
        
        $buffer_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $buffer_table ORDER BY id ASC LIMIT %d, %d",
            $offset,
            $batch_size
        ), ARRAY_A);
        
        $row_count = is_array($buffer_rows) ? count($buffer_rows) : 0;
        
        if ($row_count === 0) {
            $err = $wpdb->last_error;
            $log_entry = sprintf("[%s] BATCH #%d | Result: EMPTY | Offset: %d | DB Error: %s\n", date('Y-m-d H:i:s'), $batch_index, $offset, $err ?: 'None');
            @error_log($log_entry, 3, $log_file);

            $this->logger->log('warning', "Batch #{$batch_index}: No rows found in buffer table.", array(
                'table' => $buffer_table,
                'offset' => $offset,
                'limit' => $batch_size,
                'sync_id' => $sync_id,
                'last_error' => $err
            ), 'stock_sync');
            return $stats;
        }

        try {
            $this->logger->log('info', "Batch #{$batch_index}: Starting processing from buffer...", array(
                'offset' => $offset,
                'limit' => $batch_size
            ), 'stock_sync');
            
            $updates_to_process = array();
            
            foreach ($buffer_rows as $row) {
                $product_id = (int) $row['product_id'];
                $sku = $row['full_code'];
                $stock_qty = (int) $row['stock'];

                // Explicit Trace Logging for BAS-4770
                $this->trace_log_sku($sku, "Processing in Batch #{$batch_index}", array(
                    'product_id' => $product_id,
                    'api_stock' => $stock_qty
                ));
                
                if (!$product_id) {
                    $stats['skipped']++;
                    continue;
                }
                
                // Smart Skipping - only updated if quantity changes
                if ($this->should_skip_stock_update($product_id, $stock_qty)) {
                    $this->trace_log_sku($sku, "Skipping update: Stock matches exactly", array('qty' => $stock_qty));
                    $stats['skipped']++;
                    continue;
                }
                
                $stats['processed']++;
                $this->trace_log_sku($sku, "Queuing update", array('new_qty' => $stock_qty));
                
                // Prepare stock detail format (compatible with shortcodes)
                $stock_detail = array(
                    'fullCode' => $sku,
                    'stock' => $stock_qty,
                    'reserved' => (int) $row['reserved_stock'],
                    'incoming' => array(), // Simplified for now
                    'modifiedDate' => $row['modified_date'],
                );

                $updates_to_process[] = array(
                    'product_id' => $product_id,
                    'stock' => $stock_qty,
                    'stock_status' => ($stock_qty > 0) ? 'instock' : 'outofstock',
                    'manage_stock' => 'yes',
                    'backorders' => 'no',
                    'modified_date' => $row['modified_date'],
                    'stock_detail' => $stock_detail
                );
                
                $stats['updated']++;
            }
            
            // Execute Bulk updates
            if (!empty($updates_to_process)) {
                $this->bulk_update_stock_meta($updates_to_process, $batch_index);
            }
            
            $batch_duration = microtime(true) - $batch_start;
            
            $this->logger->log('info', "Batch #{$batch_index}: Processing complete.", array(
                'updated' => $stats['updated'],
                'skipped' => $stats['skipped'],
                'duration' => round($batch_duration, 3) . 's'
            ), 'stock_sync');
            
        } catch (Exception $e) {
            $this->logger->log('error', 'Batch processing failed', array('error' => $e->getMessage()), 'stock_sync');
        }
        
        return $stats;
    }

    /**
     * Check if stock update should be skipped - only skip if stock quantity matches exactly
     */
    private function should_skip_stock_update($product_id, $new_stock_qty) {
        $existing_stock = get_post_meta($product_id, '_stock', true);
        if ($existing_stock !== '' && $existing_stock !== null && (int)$existing_stock === (int)$new_stock_qty) {
            return true;
        }
        return false;
    }
    
    /**
     * Bulk update stock meta and WooCommerce lookup tables
     */
    private function bulk_update_stock_meta($updates, $batch_index = 0) {
        global $wpdb;

        if (empty($updates)) {
            return;
        }

        $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
        $has_lookup_table = ($wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($lookup_table) . "'") === $lookup_table);

        foreach ($updates as $update) {
            $product_id = (int) $update['product_id'];
            $stock_qty = (int) $update['stock'];
            $stock_status = $update['stock_status'];
            
            update_post_meta($product_id, '_stock', $stock_qty);
            update_post_meta($product_id, '_stock_status', $stock_status);
            update_post_meta($product_id, '_manage_stock', 'yes');
            update_post_meta($product_id, '_backorders', 'no');
            
            if (isset($update['stock_detail'])) {
                update_post_meta($product_id, '_amrod_stock_detail', $update['stock_detail']);
            }
            
            if (isset($update['modified_date'])) {
                update_post_meta($product_id, '_bytemash_stock_last_modified', $update['modified_date']);
            }
            
            // Sync WC Lookup Table
            if ($has_lookup_table) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$lookup_table} SET stock_quantity = %d, stock_status = %s WHERE product_id = %d",
                    $stock_qty,
                    $stock_status,
                    $product_id
                ));
            }
            
            // Handle variation-to-parent status sync
            $post = get_post($product_id);
            if ($post && $post->post_type === 'product_variation' && $post->post_parent > 0 && $stock_qty > 0) {
                update_post_meta($post->post_parent, '_stock_status', 'instock');
                if ($has_lookup_table) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$lookup_table} SET stock_status = 'instock' WHERE product_id = %d",
                        $post->post_parent
                    ));
                }
            }
            
            wp_cache_delete($product_id, 'post_meta');
            clean_post_cache($product_id);
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product_id);
            }
        }
    }

    /**
     * Create the stock buffer table
     */
    private function create_stock_buffer_table($table_name) {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            full_code VARCHAR(255),
            simple_code VARCHAR(100),
            stock INT,
            stock_type TINYINT,
            reserved_stock INT,
            modified_date VARCHAR(100),
            product_id BIGINT UNSIGNED DEFAULT 0,
            status VARCHAR(20) DEFAULT 'pending',
            INDEX (full_code),
            INDEX (product_id),
            INDEX (status)
        ) $charset_collate";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Stream JSON file to the buffer table item by item
     */
    private function stream_json_to_buffer($file_path, $table_name) {
        global $wpdb;
        $stats = array('success' => true, 'inserted' => 0, 'error' => '');
        
        $handle = @fopen($file_path, 'r');
        if (!$handle) {
            $stats['success'] = false;
            $stats['error'] = 'Could not open file: ' . $file_path;
            return $stats;
        }

        $buffer = '';
        $depth = 0;
        $in_string = false;
        $escape = false;
        $item_count = 0;
        $batch_inserts = array();
        
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            $length = strlen($chunk);
            
            for ($i = 0; $i < $length; $i++) {
                $char = $chunk[$i];
                
                if ($in_string) {
                    if ($escape) { $escape = false; } 
                    elseif ($char === '\\') { $escape = true; } 
                    elseif ($char === '"') { $in_string = false; }
                    $buffer .= $char;
                    continue;
                }
                
                if ($char === '"') { $in_string = true; $buffer .= $char; continue; }
                
                if ($char === '{') {
                    if ($depth === 1) { $buffer = '{'; } else { $buffer .= '{'; }
                    $depth++;
                    continue;
                }
                
                if ($char === '}') {
                    $depth--;
                    $buffer .= '}';
                    if ($depth === 1) { 
                        $item = json_decode($buffer, true);
                        if ($item) {
                            $stock_type = (int) ($item['stockType'] ?? 0);
                            // Only process simple products and variations (stockType > 0)
                            if ($stock_type > 0) {
                                $sku = $item['fullCode'] ?? '';
                                $this->trace_log_sku($sku, "Parsed from JSON and injecting into buffer", array(
                                    'stock' => $item['stock'] ?? 0,
                                    'type' => $stock_type
                                ));

                                $batch_inserts[] = $wpdb->prepare(
                                    "(%s, %s, %d, %d, %d, %s)",
                                    $item['fullCode'] ?? '',
                                    $item['simpleCode'] ?? '',
                                    (int) ($item['stock'] ?? 0),
                                    $stock_type,
                                    (int) ($item['reservedStock'] ?? 0),
                                    $item['modifiedDate'] ?? ''
                                );
                                $item_count++;
                                if (count($batch_inserts) >= 100) {
                                    $this->execute_buffer_insert($table_name, $batch_inserts);
                                    $batch_inserts = array();
                                }
                                
                                if ($item_count % 5000 === 0) {
                                    $this->logger->log('info', "Streaming data: {$item_count} items injected into buffer...", array(), 'stock_sync');
                                }
                            }
                        }
                        $buffer = '';
                    }
                    continue;
                }
                
                if ($depth > 0) { $buffer .= $char; }
                if ($char === '[') $depth++;
                if ($char === ']') $depth--;
            }
        }
        
        if (!empty($batch_inserts)) {
            $this->execute_buffer_insert($table_name, $batch_inserts);
        }
        
        fclose($handle);
        $stats['inserted'] = $item_count;
        return $stats;
    }

    private function execute_buffer_insert($table_name, $values) {
        global $wpdb;
        $count = count($values);
        $query = "INSERT INTO $table_name (full_code, simple_code, stock, stock_type, reserved_stock, modified_date) VALUES " . implode(',', $values);
        $result = $wpdb->query($query);
        
        if ($result === false) {
            $this->logger->log('error', "Buffer insert failed", array(
                'error' => $wpdb->last_error,
                'count' => $count
            ), 'stock_sync');
        }
    }

    /**
     * Map buffer entries to product IDs using SQL JOIN
     */
    private function map_buffer_to_products($table_name) {
        global $wpdb;
        
        $this->logger->log('info', 'Starting High-Performance Mapping (Phase 1: Amrod Full Code)...', array(), 'stock_sync');
        $wpdb->query("UPDATE $table_name b INNER JOIN {$wpdb->postmeta} pm ON (pm.meta_key = '_amrod_full_code' AND pm.meta_value = b.full_code) SET b.product_id = pm.post_id WHERE b.product_id = 0");
        $this->trace_bas_mapping($table_name, "After Phase 1 (Amrod Full Code)");
        
        $this->logger->log('info', 'Starting High-Performance Mapping (Phase 2: Product SKU)...', array(), 'stock_sync');
        $wpdb->query("UPDATE $table_name b INNER JOIN {$wpdb->postmeta} pm ON (pm.meta_key = '_sku' AND pm.meta_value = b.full_code) SET b.product_id = pm.post_id WHERE b.product_id = 0");
        $this->trace_bas_mapping($table_name, "After Phase 2 (Product SKU)");
        
        $this->logger->log('info', 'Starting High-Performance Mapping (Phase 3: Fallback Simple Code)...', array(), 'stock_sync');
        $wpdb->query("UPDATE $table_name b INNER JOIN {$wpdb->postmeta} pm ON (pm.meta_key = '_amrod_simple_code' AND pm.meta_value = b.simple_code) SET b.product_id = pm.post_id WHERE b.product_id = 0");
        $this->trace_bas_mapping($table_name, "After Phase 3 (Simple Code)");
        
        $matched = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE product_id > 0");
        $total_in_buffer = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        
        // Log final mapping summary to batch log
        $log_file = WP_CONTENT_DIR . '/uploads/bytemash_stock_debug_batches.log';
        $log_entry = sprintf("[%s] INITIALIZATION COMPLETE | Total in Buffer: %d | Matched: %d\n", date('Y-m-d H:i:s'), $total_in_buffer, $matched);
        @error_log($log_entry, 3, $log_file);

        $this->logger->log('success', "Mapping complete. Matched {$matched} products in buffer.", array(), 'stock_sync');
        return array('matched' => (int) $matched);
    }

    /**
     * Helper to trace BAS-4770 mapping status
     */
    private function trace_bas_mapping($table_name, $context) {
        global $wpdb;
        $bas_mapping = $wpdb->get_results("SELECT full_code, product_id FROM $table_name WHERE full_code LIKE '%BAS-4770%'", ARRAY_A);
        if ($bas_mapping) {
            foreach ($bas_mapping as $m) {
                $this->trace_log_sku($m['full_code'], $context, array('product_id' => $m['product_id']));
            }
        }
    }

    /**
     * Trace logging for specific SKUs
     */
    private function trace_log_sku($sku, $message, $data = array()) {
        if (stripos($sku, 'BAS-4770') === false) {
            return;
        }
        
        $log_file = WP_CONTENT_DIR . '/uploads/bytemash_stock_debug_BAS-4770.log';
        $timestamp = date('Y-m-d H:i:s');
        $data_str = !empty($data) ? ' | Data: ' . json_encode($data) : '';
        $log_entry = "[{$timestamp}] [TRACE] SKU: {$sku} | {$message}{$data_str}\n";
        
        @error_log($log_entry, 3, $log_file);
    }

    /**
     * Populate the standard sync queue with placeholders for AJAX progress
     */
    private function populate_sync_queue_placeholders($sync_id, $total_batches) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bytemash_sync_queue';
        $wpdb->delete($table_name, array('sync_id' => $sync_id));
        $values = array();
        for ($i = 0; $i < $total_batches; $i++) {
            $values[] = $wpdb->prepare("(%s, %d, '[]', 'pending')", $sync_id, $i);
            if (count($values) >= 100) {
                $wpdb->query("INSERT INTO $table_name (sync_id, batch_index, batch_data, status) VALUES " . implode(',', $values));
                $values = array();
            }
        }
        if (!empty($values)) {
            $wpdb->query("INSERT INTO $table_name (sync_id, batch_index, batch_data, status) VALUES " . implode(',', $values));
        }
    }
}
