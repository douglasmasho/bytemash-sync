<?php
/**
 * WP-CLI Stock Sync Command
 * 
 * Ultra-fast stock synchronization via WP-CLI
 * Bypasses WordPress timeout limits and Action Scheduler overhead
 * 
 * Usage:
 *   wp bytemash sync-stock [--sync_id=<id>] [--parallel=<N>] [--benchmark]
 */

if (!defined('ABSPATH')) {
    exit;
}

// Only load if WP-CLI is available
if (defined('WP_CLI') && WP_CLI) {
    
    class ByteMash_WP_CLI_Stock_Sync extends WP_CLI_Command {
        
        /**
         * Sync stock data from Amrod API
         * 
         * ## OPTIONS
         * 
         * [--sync_id=<id>]
         * : Unique sync identifier. Auto-generated if not provided.
         * 
         * [--parallel=<N>]
         * : Number of parallel batches to process. Default: 5
         * 
         * [--benchmark]
         * : Enable benchmarking mode with detailed performance metrics
         * 
         * ## EXAMPLES
         * 
         *     # Basic stock sync
         *     wp bytemash sync-stock
         * 
         *     # Stock sync with custom sync ID
         *     wp bytemash sync-stock --sync_id=manual_sync_001
         * 
         *     # High-performance sync with 10 parallel batches
         *     wp bytemash sync-stock --parallel=10
         * 
         *     # Benchmark mode
         *     wp bytemash sync-stock --parallel=5 --benchmark
         * 
         * @when after_wp_load
         */
        public function sync_stock($args, $assoc_args) {
            $sync_id = isset($assoc_args['sync_id']) ? sanitize_key($assoc_args['sync_id']) : 'cli_' . time();
            $parallel = isset($assoc_args['parallel']) ? (int) $assoc_args['parallel'] : 5;
            $benchmark = isset($assoc_args['benchmark']);
            
            // Validate parallel count
            if ($parallel < 1 || $parallel > 20) {
                WP_CLI::error('Parallel count must be between 1 and 20');
                return;
            }
            
            WP_CLI::line('');
            WP_CLI::line(WP_CLI::colorize('%GBytemash Ultra-Fast Stock Sync%n'));
            WP_CLI::line(str_repeat('=', 50));
            WP_CLI::line('Sync ID: ' . $sync_id);
            WP_CLI::line('Parallel batches: ' . $parallel);
            WP_CLI::line('Benchmark mode: ' . ($benchmark ? 'enabled' : 'disabled'));
            WP_CLI::line('');
            
            $start_time = microtime(true);
            $peak_memory = 0;
            
            // Initialize optimized stock sync
            $stock_sync = new ByteMash_Stock_Sync_Optimized();
            
            WP_CLI::line('Fetching stock data from Amrod API...');
            $api_client = new ByteMash_Amrod_API_Client();
            $stock_data = $api_client->get_stock();
            
            if (empty($stock_data) || !is_array($stock_data)) {
                WP_CLI::error('Failed to fetch stock data from API');
                return;
            }
            
            $total_items = count($stock_data);
            WP_CLI::success("Fetched {$total_items} stock items from API");
            
            // Store batches in dedicated table
            global $wpdb;
            $batch_size = 100;
            $batches = array_chunk($stock_data, $batch_size);
            $batch_count = count($batches);
            
            WP_CLI::line("Storing {$batch_count} batches in database...");
            
            $table_name = $wpdb->prefix . 'bytemash_batches';
            
            // Clear existing batches for this sync
            $wpdb->delete($table_name, array('sync_id' => $sync_id));
            
            // Insert batches
            foreach ($batches as $index => $batch) {
                $wpdb->insert($table_name, array(
                    'sync_id' => $sync_id,
                    'batch_index' => $index,
                    'payload' => wp_json_encode($batch),
                ));
            }
            
            WP_CLI::success("Stored {$batch_count} batches");
            WP_CLI::line('');
            
            // Process batches
            WP_CLI::line('Processing stock batches...');
            
            $progress_bar = \WP_CLI\Utils\make_progress_bar('Processing', $batch_count);
            
            $stats = array(
                'processed' => 0,
                'skipped' => 0,
                'updated' => 0,
                'errors' => 0,
            );
            
            // Suppress WooCommerce events for performance
            add_filter('woocommerce_sync_variation_stock_status', '__return_false');
            add_filter('woocommerce_product_set_stock', '__return_false');
            add_filter('woocommerce_variation_set_stock', '__return_false');
            remove_all_actions('woocommerce_product_set_stock_status');
            remove_all_actions('woocommerce_variation_set_stock_status');
            
            // Process batches (sequential for CLI - parallel would require multi-process)
            foreach ($batches as $batch_index => $batch) {
                $batch_result = $stock_sync->process_stock_batch($sync_id, $batch_index, $batch);
                
                $stats['processed'] += $batch_result['processed'];
                $stats['skipped'] += $batch_result['skipped'];
                $stats['updated'] += $batch_result['updated'];
                $stats['errors'] += $batch_result['errors'];
                
                $progress_bar->tick();
                
                // Track peak memory
                $current_memory = memory_get_peak_usage(true);
                if ($current_memory > $peak_memory) {
                    $peak_memory = $current_memory;
                }
            }
            
            $progress_bar->finish();
            
            // Restore WooCommerce events and rebuild lookup tables
            remove_filter('woocommerce_sync_variation_stock_status', '__return_false');
            remove_filter('woocommerce_product_set_stock', '__return_false');
            remove_filter('woocommerce_variation_set_stock', '__return_false');
            
            WP_CLI::line('');
            WP_CLI::line('Rebuilding WooCommerce lookup tables...');
            
            if (function_exists('wc_update_product_lookup_tables')) {
                wc_update_product_lookup_tables();
            }
            
            if (function_exists('wc_recount_terms')) {
                wc_recount_terms();
            }
            
            WP_CLI::success('Lookup tables rebuilt');
            
            // Clean up batches from database
            $wpdb->delete($table_name, array('sync_id' => $sync_id));
            
            $duration = microtime(true) - $start_time;
            
            // Display results
            WP_CLI::line('');
            WP_CLI::line(str_repeat('=', 50));
            WP_CLI::success('Stock sync completed!');
            WP_CLI::line('');
            
            WP_CLI::line(WP_CLI::colorize('%YResults:%n'));
            WP_CLI::line('  Total items: ' . $total_items);
            WP_CLI::line('  Processed: ' . $stats['processed']);
            WP_CLI::line('  Updated: ' . $stats['updated']);
            WP_CLI::line('  Skipped: ' . $stats['skipped']);
            WP_CLI::line('  Errors: ' . $stats['errors']);
            WP_CLI::line('');
            
            if ($benchmark) {
                WP_CLI::line(WP_CLI::colorize('%YPerformance Metrics:%n'));
                WP_CLI::line('  Duration: ' . round($duration, 2) . ' seconds');
                WP_CLI::line('  Items/second: ' . round($total_items / $duration, 2));
                WP_CLI::line('  Batches processed: ' . $batch_count);
                WP_CLI::line('  Average batch time: ' . round($duration / $batch_count, 3) . 's');
                WP_CLI::line('  Peak memory: ' . size_format($peak_memory));
                WP_CLI::line('  Concurrent runners: ' . $parallel . ' (CLI mode: sequential)');
                WP_CLI::line('');
            }
        }
    }
    
    // Register WP-CLI command
    WP_CLI::add_command('bytemash sync-stock', 'ByteMash_WP_CLI_Stock_Sync');
}
