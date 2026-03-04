<?php
/**
 * Plugin Name: ByteMash WooCommerce Amrod Sync
 * Plugin URI: https://bytemash.com/woo-amrod-sync
 * Description: Memory-efficient WooCommerce plugin that syncs products, variations, stock, images, and all data from Amrod API with automatic scheduling and comprehensive dashboard. Features automatic memory management and token refresh!
 * Version: 1.1.2
 * Author: ByteMash
 * Author URI: https://bytemash.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bytemash-woo-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BYTEMASH_WOO_SYNC_VERSION', '1.1.2');
define('BYTEMASH_WOO_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BYTEMASH_WOO_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BYTEMASH_WOO_SYNC_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Require Composer autoloader if available
if (file_exists(BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Save stock API response to a JSON file when the option is enabled.
 * Called at the start of a stock sync so the raw response can be inspected.
 *
 * @param array $data Decoded stock API response (array of items).
 */
function bytemash_maybe_save_stock_response_json($data) {
    if (!is_array($data) || !get_option('bytemash_stock_sync_download_json', false)) {
        return;
    }
    $dir = BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'responses/stock/';
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    $filename = 'response_' . gmdate('Y-m-d_H-i-s') . '.json';
    $path = $dir . $filename;
    $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json !== false && @file_put_contents($path, $json) !== false) {
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return;
        }
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Stock API response saved to file', array('file' => $filename, 'items' => count($data)), 'stock_sync');
    }
}

/**
 * Main plugin class
 */
class ByteMash_Woo_Sync {
    
    /**
     * Single instance of the class
     */
    private static $instance = null;
    
    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Load required dependencies
     */
    private function load_dependencies() {
        // Core classes
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-logger.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-db-migration.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-amrod-api-client.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-image-handler.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-batch-processor.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-product-sync.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-stock-sync-optimized.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-price-sync-optimized.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-sync-scheduler.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-true-cron-manager.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-action-scheduler-sync.php';
        // Instantiate Action Scheduler sync early so hooks are registered at plugin load
        // (before init). This ensures callbacks run when AS runs the queue in cron/async requests.
        $this->action_scheduler = new ByteMash_Action_Scheduler_Sync();
        
        // Enable Action Scheduler high-concurrency mode for parallel batch processing
        // Default: 5 concurrent runners (conservative for resource-limited servers)
        // Can be increased to 10-20 on powerful servers via filter
        if (!defined('ACTION_SCHEDULER_QUEUE_RUNNER_CONCURRENCY')) {
            $concurrency = apply_filters('bytemash_action_scheduler_concurrency', 5);
            define('ACTION_SCHEDULER_QUEUE_RUNNER_CONCURRENCY', $concurrency);
        }
        
        // Load WP-CLI commands if WP-CLI is available
        if (defined('WP_CLI') && WP_CLI) {
            require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-wp-cli-stock-sync.php';
        }
        
        if (is_admin()) {
            require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'admin/class-admin-settings.php';
            require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'admin/class-admin-dashboard.php';
            require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'admin/class-admin-tools.php';
            require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-quote-admin.php';
        }

        // Frontend hooks for stock modal
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('woocommerce_single_product_summary', array($this, 'render_stock_modal_trigger'), 25);

        // Public AJAX for stock modal data
        add_action('wp_ajax_bytemash_get_product_stock_table', array($this, 'ajax_get_product_stock_table'));
        
        // Frontend hooks for branding modal
        add_action('woocommerce_single_product_summary', array($this, 'render_brandings_modal_trigger'), 26);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_brandings_assets'));
        
        // Public AJAX for brandings modal
        add_action('wp_ajax_bytemash_get_product_brandings', array($this, 'ajax_get_product_brandings'));
        
        // AJAX for product count test page
        add_action('wp_ajax_bytemash_get_product_counts', array($this, 'ajax_get_product_counts'));
        add_action('wp_ajax_nopriv_bytemash_get_product_brandings', array($this, 'ajax_get_product_brandings'));

        // Branding selection as product options
        // Add to both hooks to ensure it shows in normal purchase flow AND quote requests
        add_action('woocommerce_before_add_to_cart_button', array($this, 'render_branding_options_fields'), 15);
        // Also add to summary as fallback for when add to cart button is hidden
        add_action('woocommerce_single_product_summary', array($this, 'render_branding_options_fields'), 20);
        // Also ensure it shows in quote mode (after variation form but before submit button)
        add_action('woocommerce_after_add_to_cart_form', array($this, 'render_branding_options_fields_quote_mode'), 8);
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_branding_options'), 10, 3);
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_branding_to_cart_item'), 10, 3);
        add_filter('woocommerce_get_item_data', array($this, 'display_branding_in_cart'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'add_branding_to_order_items'), 10, 4);
        add_action('wp_ajax_nopriv_bytemash_get_product_stock_table', array($this, 'ajax_get_product_stock_table'));
        
        // Quote Mode - ONLY activate when quote mode is enabled
        // When quote mode is OFF, use normal WooCommerce ordering without any quote interference
        if ($this->is_quote_mode_enabled()) {
            // Quote mode is ON - activate full quote system
            add_action('wp_enqueue_scripts', array($this, 'enqueue_quote_request_assets'));
            add_action('wp_ajax_bytemash_submit_quote_request', array($this, 'ajax_submit_quote_request'));
            add_action('wp_ajax_nopriv_bytemash_submit_quote_request', array($this, 'ajax_submit_quote_request'));
            add_action('init', array($this, 'register_quote_request_order_status'));
            add_filter('wc_order_statuses', array($this, 'add_quote_request_order_status'));
            
            // Render quote button as fallback after form
            add_action('woocommerce_single_product_summary', array($this, 'render_quote_request_button_fallback'), 35);
            
            // Replace normal ordering flow with custom quote form
            add_action('woocommerce_before_add_to_cart_form', array($this, 'maybe_add_quote_mode_wrapper'), 5);
            add_action('woocommerce_before_add_to_cart_button', array($this, 'maybe_hide_add_to_cart_in_quote_mode'), 5);
            add_action('woocommerce_after_add_to_cart_form', array($this, 'maybe_replace_add_to_cart_with_quote_button'), 15);
            add_action('woocommerce_after_add_to_cart_form', array($this, 'maybe_close_quote_mode_wrapper'), 20);
            
            // In quote mode, ensure all variations are visible (even without prices)
            add_filter('woocommerce_hide_invisible_variations', array($this, 'show_all_variations_in_quote_mode'), 10, 3);
            add_filter('woocommerce_product_get_children', array($this, 'include_all_variations_in_quote_mode'), 10, 2);
            add_filter('woocommerce_product_variation_get_visibility', array($this, 'make_all_variations_visible_in_quote_mode'), 10, 2);
            add_filter('woocommerce_available_variation', array($this, 'include_all_variations_in_available_data'), 10, 3);
            add_filter('woocommerce_variation_is_visible', array($this, 'make_all_variations_visible_in_quote_mode_visibility'), 10, 4);
        }
        // When quote mode is OFF: No quote buttons, no quote hooks - just normal WooCommerce
        
        // CRITICAL: Always inject external variation images for color swatches to work
        // This is needed for normal product display, not just quote mode
        add_filter('woocommerce_available_variation', array($this, 'inject_external_variation_images'), 20, 3);
    }

    /**
     * Register activation hook to clear any existing schedules once on install
     */
    public static function register_activation() {
        // Prevent "unexpected output during activation" / "headers already sent" by discarding any stray output
        if (!headers_sent()) {
            ob_start();
        }

        // Create logs table first so Logger and migrations can write without causing "headers already sent"
        global $wpdb;
        $table_name = $wpdb->prefix . 'bytemash_sync_logs';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sync_type varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            message longtext,
            data longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Run database migrations
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-logger.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-db-migration.php';
        
        $migration = new ByteMash_DB_Migration();
        $result = $migration->run_migrations();
        
        if (!$result['success']) {
            // Log migration errors but don't prevent activation
            $logger = new ByteMash_Logger();
            $logger->log('error', 'Database migration failed during activation', array(
                'errors' => $result['errors'],
            ), 'db_migration');
        }
        
        // Clear WP-Cron schedules
        wp_clear_scheduled_hook('bytemash_full_sync_cron');
        wp_clear_scheduled_hook('bytemash_incremental_sync_cron');
        wp_clear_scheduled_hook('bytemash_cron_health_check');

        // Clear Action Scheduler queues if available
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('bytemash_action_scheduler_full_sync', array('with_branding' => true), 'bytemash-sync');
            as_unschedule_all_actions('bytemash_action_scheduler_incremental_sync', array('with_branding' => true), 'bytemash-sync');
            as_unschedule_all_actions('bytemash_action_scheduler_batch_sync', null, 'bytemash-sync');
            as_unschedule_all_actions('bytemash_action_scheduler_cleanup', null, 'bytemash-sync');
        }

        if (ob_get_level()) {
            ob_end_clean();
        }
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'check_dependencies'));
        add_action('init', array($this, 'load_textdomain'));
        add_action('init', array($this, 'init_scheduler'));
        add_action('init', array($this, 'register_product_flag_taxonomies'));
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
        
        // Include child category products when viewing parent categories
        add_action('pre_get_posts', array($this, 'include_child_category_products'));
        
        // Bust category filter caches whenever product categories change
        add_action('created_product_cat', array($this, 'bust_category_filter_cache'));
        add_action('edited_product_cat', array($this, 'bust_category_filter_cache'));
        add_action('delete_product_cat', array($this, 'bust_category_filter_cache'));
        
        // Filter products based on category filter parameter
        add_action('woocommerce_product_query', array($this, 'filter_products_by_category_parameter'));
        
        // Filter Bricks product queries
        add_filter('bricks/posts/query_vars', array($this, 'filter_bricks_product_query'), 10, 2);
        
        // PHENOMENAL OPTIMIZATION: Optimize all WooCommerce product queries for large categories
        add_action('woocommerce_product_query', array($this, 'optimize_product_query'), 1);
        
        // Hook into WooCommerce product images
        add_filter('woocommerce_product_get_image_id', array($this, 'use_external_image_url'), 10, 2);
        add_filter('wp_get_attachment_image_src', array($this, 'replace_with_external_url'), 10, 4);
        add_filter('woocommerce_product_get_gallery_image_ids', array($this, 'use_external_gallery_urls'), 10, 2);
		// Shop/archive thumbnails (broad theme coverage)
		add_filter('woocommerce_get_product_thumbnail', array($this, 'archive_product_thumbnail_html'), 10, 3);
		add_filter('post_thumbnail_html', array($this, 'archive_post_thumbnail_html'), 10, 5);
        
        // Frontend product page hooks
        add_action('woocommerce_product_meta_end', array($this, 'display_branding_guides'), 10);
        // Fallback: some themes don't call woocommerce_product_meta_end; also render in summary
        add_action('woocommerce_single_product_summary', array($this, 'display_branding_guides'), 35);
        add_action('woocommerce_single_product_summary', array($this, 'display_brand_info'), 15);
        
        // Display product data by default on product page
        add_action('woocommerce_single_product_summary', array($this, 'display_brand_logo'), 16);
        add_action('woocommerce_single_product_summary', array($this, 'display_color_swatches_row'), 17);
        add_action('woocommerce_single_product_summary', array($this, 'display_product_gender'), 18);
        add_action('woocommerce_single_product_summary', array($this, 'display_total_stock_info'), 19);
        
        // Register shortcodes
        add_shortcode('amrod_brand_logo', array($this, 'shortcode_brand_logo'));
        add_shortcode('amrod_color_swatches', array($this, 'shortcode_color_swatches'));
        add_shortcode('amrod_gender', array($this, 'shortcode_gender'));
        add_shortcode('amrod_total_stock', array($this, 'shortcode_total_stock'));
        add_shortcode('amrod_category_filter', array($this, 'shortcode_category_filter'));

        // Add Branding Guide tab next to Description/Additional Information
        add_filter('woocommerce_product_tabs', array($this, 'add_branding_guide_tab'));

		// Force-inject buttons only if theme didn't render them (optional via settings)
		add_action('wp_footer', array($this, 'maybe_force_product_buttons'), 5);
        
        // Only override purchasability and stock status if NOT in default mode
        // When in default mode, use standard WooCommerce behavior without any interference
        $purchasability_mode = get_option('bytemash_allow_orders_without_price', 'default');
        if ($purchasability_mode !== 'default') {
            // Allow products to be purchasable even without prices
            add_filter('woocommerce_is_purchasable', array($this, 'make_products_purchasable_without_price'), 10, 2);
            add_filter('woocommerce_variation_is_purchasable', array($this, 'make_variations_purchasable_without_price'), 10, 2);
            add_filter('woocommerce_product_is_in_stock', array($this, 'force_in_stock_when_has_stock'), 10, 2);
            add_filter('woocommerce_product_get_stock_status', array($this, 'force_stock_status_when_has_stock'), 10, 2);
            add_filter('woocommerce_variation_get_stock_status', array($this, 'force_stock_status_when_has_stock'), 10, 2);
            // Hide the out of stock message for products that should allow quote requests
            add_filter('woocommerce_get_stock_html', array($this, 'hide_out_of_stock_message_for_quote_requests'), 10, 2);
        }
        // DISABLED: These interfere with YITH Request a Quote and other quote plugins
        // They set fake '0' prices which breaks quote button detection
        // add_filter('woocommerce_product_get_price', array($this, 'set_default_price_for_amrod_products'), 10, 2);
        // add_action('woocommerce_before_add_to_cart_button', array($this, 'maybe_set_product_price'));
        // Removed: Don't override WooCommerce price display for normal ordering flow
        // add_filter('woocommerce_get_price_html', array($this, 'hide_zero_price_display'), 10, 2);
        
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'register_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            
            // AJAX handlers
            add_action('wp_ajax_bytemash_authenticate', array($this, 'ajax_authenticate'));
            add_action('wp_ajax_bytemash_save_api_url', array($this, 'ajax_save_api_url'));
            add_action('wp_ajax_bytemash_test_connection', array($this, 'ajax_test_connection'));
            add_action('wp_ajax_bytemash_clear_logs', array($this, 'ajax_clear_logs'));
            add_action('wp_ajax_bytemash_get_sync_progress', array($this, 'ajax_get_sync_progress'));
            add_action('wp_ajax_bytemash_stop_sync', array($this, 'ajax_stop_sync'));
            add_action('wp_ajax_bytemash_manual_sync', array($this, 'ajax_manual_sync'));
            add_action('wp_ajax_bytemash_sync_products_incremental', array($this, 'ajax_sync_products_incremental'));
            add_action('wp_ajax_bytemash_sync_stock', array($this, 'ajax_sync_stock'));
            add_action('wp_ajax_bytemash_stock_sync_incremental', array($this, 'ajax_sync_stock_incremental'));
            add_action('wp_ajax_bytemash_sync_prices', array($this, 'ajax_sync_prices'));
            add_action('wp_ajax_bytemash_price_sync_incremental', array($this, 'ajax_sync_prices_incremental'));
            add_action('wp_ajax_bytemash_sync_orphan_prices', array($this, 'ajax_sync_orphan_prices'));
            add_action('wp_ajax_bytemash_sync_categories', array($this, 'ajax_sync_categories'));
            add_action('wp_ajax_bytemash_sync_color_swatches', array($this, 'ajax_sync_color_swatches'));
            add_action('wp_ajax_bytemash_sync_brands', array($this, 'ajax_sync_brands'));
            add_action('wp_ajax_bytemash_sync_branding_departments', array($this, 'ajax_sync_branding_departments'));
            add_action('wp_ajax_bytemash_sync_branding_prices', array($this, 'ajax_sync_branding_prices'));
            add_action('wp_ajax_bytemash_sync_inclusive_brandings', array($this, 'ajax_sync_inclusive_brandings'));
            add_action('wp_ajax_bytemash_process_batch', array($this, 'ajax_process_batch'));
            add_action('wp_ajax_bytemash_get_batch', array($this, 'ajax_get_batch'));
            add_action('wp_ajax_bytemash_cleanup_zero_prices', array($this, 'ajax_cleanup_zero_prices'));
            add_action('wp_ajax_bytemash_cleanup_duplicate_meta', array($this, 'ajax_cleanup_duplicate_meta'));
            add_action('wp_ajax_bytemash_delete_excess_products', array($this, 'ajax_delete_excess_products'));
            add_action('wp_ajax_bytemash_process_cleanup_batch', array($this, 'ajax_process_cleanup_batch'));
            
            // Cron manager AJAX handlers
            add_action('wp_ajax_bytemash_toggle_test_mode', array($this, 'ajax_toggle_test_mode'));
            add_action('wp_ajax_bytemash_toggle_full_test_mode', array($this, 'ajax_toggle_full_test_mode'));
            add_action('wp_ajax_bytemash_toggle_incremental_test_mode', array($this, 'ajax_toggle_incremental_test_mode'));
            add_action('wp_ajax_bytemash_toggle_production_full_sync', array($this, 'ajax_toggle_production_full_sync'));
            add_action('wp_ajax_bytemash_enable_production_cron', array($this, 'ajax_enable_production_cron'));
            add_action('wp_ajax_bytemash_enable_production_system_cron', array($this, 'ajax_enable_production_system_cron'));
            add_action('wp_ajax_bytemash_enable_system_cron', array($this, 'ajax_enable_system_cron'));
            add_action('wp_ajax_bytemash_emergency_stop_syncs', array($this, 'ajax_emergency_stop_syncs'));
            add_action('wp_ajax_bytemash_get_scheduled_sync_status', array($this, 'ajax_get_scheduled_sync_status'));
        }
        
        // Activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Register custom taxonomies for product behaviour and promotion flags
     */
    public function register_product_flag_taxonomies() {
        // Behaviour taxonomy
        if (!taxonomy_exists('amrod_product_behaviour')) {
            register_taxonomy(
                'amrod_product_behaviour',
                array('product'),
                array(
                    'labels' => array(
                        'name' => __('Product Behaviour', 'bytemash-woo-sync'),
                        'singular_name' => __('Behaviour', 'bytemash-woo-sync'),
                        'search_items' => __('Search Behaviour Flags', 'bytemash-woo-sync'),
                        'all_items' => __('All Behaviour Flags', 'bytemash-woo-sync'),
                        'parent_item' => __('Parent Behaviour', 'bytemash-woo-sync'),
                        'parent_item_colon' => __('Parent Behaviour:', 'bytemash-woo-sync'),
                        'edit_item' => __('Edit Behaviour', 'bytemash-woo-sync'),
                        'update_item' => __('Update Behaviour', 'bytemash-woo-sync'),
                        'add_new_item' => __('Add New Behaviour', 'bytemash-woo-sync'),
                        'new_item_name' => __('New Behaviour Name', 'bytemash-woo-sync'),
                        'menu_name' => __('Product Behaviour', 'bytemash-woo-sync'),
                    ),
                    'hierarchical' => true,
                    'show_ui' => true,
                    'show_in_menu' => true,
                    'show_admin_column' => true,
                    'show_in_nav_menus' => false,
                    'show_tagcloud' => false,
                    'show_in_rest' => true,
                    'query_var' => true,
                    'rewrite' => array(
                        'slug' => 'product-behaviour',
                        'with_front' => false,
                    ),
                )
            );
        }

        // Promotion taxonomy
        if (!taxonomy_exists('amrod_product_promotion')) {
            register_taxonomy(
                'amrod_product_promotion',
                array('product'),
                array(
                    'labels' => array(
                        'name' => __('Product Promotion', 'bytemash-woo-sync'),
                        'singular_name' => __('Promotion', 'bytemash-woo-sync'),
                        'search_items' => __('Search Promotion Flags', 'bytemash-woo-sync'),
                        'all_items' => __('All Promotion Flags', 'bytemash-woo-sync'),
                        'parent_item' => __('Parent Promotion', 'bytemash-woo-sync'),
                        'parent_item_colon' => __('Parent Promotion:', 'bytemash-woo-sync'),
                        'edit_item' => __('Edit Promotion', 'bytemash-woo-sync'),
                        'update_item' => __('Update Promotion', 'bytemash-woo-sync'),
                        'add_new_item' => __('Add New Promotion', 'bytemash-woo-sync'),
                        'new_item_name' => __('New Promotion Name', 'bytemash-woo-sync'),
                        'menu_name' => __('Product Promotion', 'bytemash-woo-sync'),
                    ),
                    'hierarchical' => true,
                    'show_ui' => true,
                    'show_in_menu' => true,
                    'show_admin_column' => true,
                    'show_in_nav_menus' => false,
                    'show_tagcloud' => false,
                    'show_in_rest' => true,
                    'query_var' => true,
                    'rewrite' => array(
                        'slug' => 'product-promotion',
                        'with_front' => false,
                    ),
                )
            );
        }

        // Gender taxonomy
        if (!taxonomy_exists('amrod_product_gender')) {
            register_taxonomy(
                'amrod_product_gender',
                array('product'),
                array(
                    'labels' => array(
                        'name' => __('Product Gender', 'bytemash-woo-sync'),
                        'singular_name' => __('Gender', 'bytemash-woo-sync'),
                        'search_items' => __('Search Genders', 'bytemash-woo-sync'),
                        'all_items' => __('All Genders', 'bytemash-woo-sync'),
                        'edit_item' => __('Edit Gender', 'bytemash-woo-sync'),
                        'update_item' => __('Update Gender', 'bytemash-woo-sync'),
                        'add_new_item' => __('Add New Gender', 'bytemash-woo-sync'),
                        'new_item_name' => __('New Gender Name', 'bytemash-woo-sync'),
                        'menu_name' => __('Product Gender', 'bytemash-woo-sync'),
                    ),
                    'hierarchical' => false,
                    'show_ui' => true,
                    'show_in_menu' => true,
                    'show_admin_column' => true,
                    'show_in_nav_menus' => false,
                    'show_tagcloud' => false,
                    'show_in_rest' => true,
                    'query_var' => true,
                    'rewrite' => array(
                        'slug' => 'product-gender',
                        'with_front' => false,
                    ),
                )
            );
        }

        // Colour taxonomy
        if (!taxonomy_exists('amrod_product_color')) {
            register_taxonomy(
                'amrod_product_color',
                array('product'),
                array(
                    'labels' => array(
                        'name' => __('Product Colours', 'bytemash-woo-sync'),
                        'singular_name' => __('Colour', 'bytemash-woo-sync'),
                        'search_items' => __('Search Colours', 'bytemash-woo-sync'),
                        'all_items' => __('All Colours', 'bytemash-woo-sync'),
                        'edit_item' => __('Edit Colour', 'bytemash-woo-sync'),
                        'update_item' => __('Update Colour', 'bytemash-woo-sync'),
                        'add_new_item' => __('Add New Colour', 'bytemash-woo-sync'),
                        'new_item_name' => __('New Colour Name', 'bytemash-woo-sync'),
                        'menu_name' => __('Product Colours', 'bytemash-woo-sync'),
                    ),
                    'hierarchical' => false,
                    'show_ui' => true,
                    'show_in_menu' => true,
                    'show_admin_column' => true,
                    'show_in_nav_menus' => false,
                    'show_tagcloud' => false,
                    'show_in_rest' => true,
                    'query_var' => true,
                    'rewrite' => array(
                        'slug' => 'product-colour',
                        'with_front' => false,
                    ),
                )
            );
        }

        // Size taxonomy
        if (!taxonomy_exists('amrod_product_size')) {
            register_taxonomy(
                'amrod_product_size',
                array('product'),
                array(
                    'labels' => array(
                        'name' => __('Product Sizes', 'bytemash-woo-sync'),
                        'singular_name' => __('Size', 'bytemash-woo-sync'),
                        'search_items' => __('Search Sizes', 'bytemash-woo-sync'),
                        'all_items' => __('All Sizes', 'bytemash-woo-sync'),
                        'edit_item' => __('Edit Size', 'bytemash-woo-sync'),
                        'update_item' => __('Update Size', 'bytemash-woo-sync'),
                        'add_new_item' => __('Add New Size', 'bytemash-woo-sync'),
                        'new_item_name' => __('New Size Name', 'bytemash-woo-sync'),
                        'menu_name' => __('Product Sizes', 'bytemash-woo-sync'),
                    ),
                    'hierarchical' => false,
                    'show_ui' => true,
                    'show_in_menu' => true,
                    'show_admin_column' => true,
                    'show_in_nav_menus' => false,
                    'show_tagcloud' => false,
                    'show_in_rest' => true,
                    'query_var' => true,
                    'rewrite' => array(
                        'slug' => 'product-size',
                        'with_front' => false,
                    ),
                )
            );
        }

        // Ensure default terms exist for behaviour
        $behaviour_terms = array(
            '0' => array('slug' => 'normal', 'name' => __('Normal', 'bytemash-woo-sync')),
            '1' => array('slug' => 'featured', 'name' => __('Featured', 'bytemash-woo-sync')),
            '2' => array('slug' => 'hidden', 'name' => __('Hidden', 'bytemash-woo-sync')),
        );

        foreach ($behaviour_terms as $term_data) {
            if (!term_exists($term_data['slug'], 'amrod_product_behaviour')) {
                wp_insert_term(
                    $term_data['name'],
                    'amrod_product_behaviour',
                    array('slug' => $term_data['slug'])
                );
            }
        }

        // Ensure default terms exist for promotion
        $promotion_terms = array(
            '0' => array('slug' => 'normal', 'name' => __('Normal', 'bytemash-woo-sync')),
            '1' => array('slug' => 'promotion', 'name' => __('On Promotion', 'bytemash-woo-sync')),
            '2' => array('slug' => 'new', 'name' => __('New', 'bytemash-woo-sync')),
            '3' => array('slug' => 'clearance', 'name' => __('Clearance', 'bytemash-woo-sync')),
        );

        foreach ($promotion_terms as $term_data) {
            if (!term_exists($term_data['slug'], 'amrod_product_promotion')) {
                wp_insert_term(
                    $term_data['name'],
                    'amrod_product_promotion',
                    array('slug' => $term_data['slug'])
                );
            }
        }
    }
    
    /**
     * Declare compatibility with WooCommerce HPOS
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }
    
    /**
     * Include products from child categories when viewing a parent category
     * This ensures that when viewing a major category, all products from subcategories are displayed
     */
    public function include_child_category_products($query) {
        // Only run on frontend product category archives, not in admin
        if (is_admin() || !$query->is_main_query()) {
            return;
        }
        
        // Check if this is a product category archive
        if (!is_tax('product_cat')) {
            return;
        }
        
        // Get the current category
        $category = get_queried_object();
        
        if (!$category || !isset($category->term_id)) {
            return;
        }
        
        // PHENOMENAL OPTIMIZATION: Optimize query for large categories
        // Reduce meta queries and improve performance
        $query->set('update_post_meta_cache', false);
        $query->set('update_post_term_cache', false);
        $query->set('no_found_rows', false); // Keep for pagination
        
        // Ensure we don't load all posts unless explicitly requested
        $posts_per_page = $query->get('posts_per_page');
        if (empty($posts_per_page) || $posts_per_page == -1) {
            $query->set('posts_per_page', 12);
        }
        
        // Modify the tax_query to include all categories without fetching children manually
        $tax_query = $query->get('tax_query') ?: array();
        
        // Find and modify the product_cat query
        $found_product_cat = false;
        foreach ($tax_query as $key => $tax) {
            if (isset($tax['taxonomy']) && $tax['taxonomy'] === 'product_cat') {
                // Manual child fetching for performance
                $child_ids = get_term_children($category->term_id, 'product_cat');
                $all_ids = is_array($child_ids) ? array_merge(array($category->term_id), $child_ids) : array($category->term_id);
                
                $tax_query[$key]['terms'] = $all_ids;
                $tax_query[$key]['field'] = 'term_id';
                $tax_query[$key]['include_children'] = false; // We already included them
                if (!isset($tax_query[$key]['operator'])) {
                    $tax_query[$key]['operator'] = 'IN';
                }
                $found_product_cat = true;
                break;
            }
        }
        
        // If no product_cat tax query exists, add one
        if (!$found_product_cat) {
            // Manual child fetching for performance
            $child_ids = get_term_children($category->term_id, 'product_cat');
            $all_ids = is_array($child_ids) ? array_merge(array($category->term_id), $child_ids) : array($category->term_id);
            
            $tax_query[] = array(
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $all_ids,
                'operator' => 'IN',
                'include_children' => false,
            );
        }
        
        $query->set('tax_query', $tax_query);
    }
    
    /**
     * Filter products based on category filter URL parameter
     * Allows filtering products on current page without navigation
     */
    public function filter_products_by_category_parameter($query) {
        // Only run on frontend shop/archive pages
        if (is_admin() || !$query->is_main_query()) {
            return;
        }
        
        // Determine category via slug or legacy ID parameter
        $category = null;
        $category_id = 0;
        
        if (isset($_GET['product_cat']) && $_GET['product_cat'] !== '') {
            $category_slug = sanitize_title(wp_unslash($_GET['product_cat']));
            if ($category_slug !== '') {
                $category = get_term_by('slug', $category_slug, 'product_cat');
            }
        } elseif (isset($_GET['filter_category']) && !empty($_GET['filter_category'])) {
            $category_id = intval($_GET['filter_category']);
            $category = get_term($category_id, 'product_cat');
        } else {
            return;
        }
        
        if (!$category || is_wp_error($category)) {
            return;
        }
        
        if (!$category_id && isset($category->term_id)) {
            $category_id = (int) $category->term_id;
        }
        
        // PHENOMENAL OPTIMIZATION: Optimize query for large categories
        // Reduce meta queries and improve performance
        $query->set('update_post_meta_cache', false);
        $query->set('update_post_term_cache', false);
        $query->set('no_found_rows', false); // Keep for pagination
        
        // Get existing tax_query
        $tax_query = $query->get('tax_query') ?: array();
        
        // Remove any existing product_cat filters
        foreach ($tax_query as $key => $tax) {
            if (isset($tax['taxonomy']) && $tax['taxonomy'] === 'product_cat') {
                unset($tax_query[$key]);
            }
        }
        
        // Add our filter
        $tax_query[] = array(
            'taxonomy' => 'product_cat',
            'field' => 'term_id',
            'terms' => array($category_id),
            'operator' => 'IN',
            'include_children' => true,
        );
        
        // Normalize array keys
        $tax_query = array_values($tax_query);
        
        $query->set('tax_query', $tax_query);
    }
    
    /**
     * PHENOMENAL OPTIMIZATION: Optimize WooCommerce product queries for large categories
     * Reduces unnecessary meta queries and improves performance significantly
     */
    public function optimize_product_query($query) {
        // Only optimize frontend queries, not admin
        if (is_admin()) {
            return;
        }
        
        // Disable expensive cache updates for better performance
        // WooCommerce will load meta/terms on-demand when needed
        $query->set('update_post_meta_cache', false);
        $query->set('update_post_term_cache', false);
        
        // Ensure pagination works correctly
        $query->set('no_found_rows', false);
        
        // Ensure we don't load all posts unless explicitly requested
        $posts_per_page = $query->get('posts_per_page');
        if (empty($posts_per_page) || $posts_per_page == -1) {
            $query->set('posts_per_page', 12);
        }
        
        // Optimize orderby for better index usage
        // Sorting by title is extremely slow (unindexed). Default to date (indexed) or ID.
        if (!$query->get('orderby')) {
            $query->set('orderby', 'date');
            $query->set('order', 'DESC');
        }
    }
    
    /**
     * Filter Bricks product queries based on category filter parameter
     * Works with Bricks Query Loop element
     * Supports both Bricks format (brx_vqxomj[0]=slug), WooCommerce product_cat, and legacy filter_category=ID
     */
    public function filter_bricks_product_query($query_vars, $settings) {
        // Only filter if post type is product
        if (isset($query_vars['post_type']) && $query_vars['post_type'] !== 'product') {
            return $query_vars;
        }
        
        // PHENOMENAL OPTIMIZATION: Optimize Bricks queries for large categories
        // Reduce meta queries and improve performance
        $query_vars['update_post_meta_cache'] = false;
        $query_vars['update_post_term_cache'] = false;
        
        // Ensure we don't load all posts unless explicitly requested
        // If posts_per_page is -1 (all) or empty, force a reasonable limit to prevent crashing/slowdown
        if (!isset($query_vars['posts_per_page']) || $query_vars['posts_per_page'] == -1) {
            $query_vars['posts_per_page'] = 12; // Default to 12 if not specified or set to unlimited
        }
        
        // REVERTED: Aggressive category filtering logic was causing issues with subcategories.
        // We now rely on Bricks/WordPress to handle the main category filtering.
        // The custom filter logic below is preserved ONLY for explicit custom filter parameters
        // but we will NOT clear existing tax_queries blindly.
        
        $category_id = null;
        
        // Check for Bricks format: brx_vqxomj[0]=category_slug
        if (isset($_GET['brx_vqxomj']) && is_array($_GET['brx_vqxomj']) && !empty($_GET['brx_vqxomj'][0])) {
            $category_slug = sanitize_text_field($_GET['brx_vqxomj'][0]);
            $category = get_term_by('slug', $category_slug, 'product_cat');
            if ($category && !is_wp_error($category)) {
                $category_id = $category->term_id;
            }
        }
        // Legacy fallback: filter_category=ID
        elseif (isset($_GET['filter_category']) && !empty($_GET['filter_category'])) {
            $category_id = intval($_GET['filter_category']);
            $category = get_term($category_id, 'product_cat');
            if ($category && !is_wp_error($category)) {
                $category_id = $category->term_id;
            }
        }
        
        // Only apply custom filter if we explicitly found a custom filter parameter
        if ($category_id) {
            // Initialize tax_query if it doesn't exist
            if (!isset($query_vars['tax_query'])) {
                $query_vars['tax_query'] = array();
            }
            
            // Manual child fetching for performance
            $child_ids = get_term_children($category_id, 'product_cat');
            $all_ids = is_array($child_ids) ? array_merge(array($category_id), $child_ids) : array($category_id);
            
            // Add our filter
            $query_vars['tax_query'][] = array(
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $all_ids,
                'operator' => 'IN',
                'include_children' => false,
            );
        }
        
        return $query_vars;
    }
    
    /**
     * Action Scheduler instance
     */
    private $action_scheduler;
    
    /**
     * Whether to use Action Scheduler
     */
    private $use_action_scheduler = false;
    
    /**
     * Initialize scheduler after all dependencies are loaded
     */
    public function init_scheduler() {
        new ByteMash_Sync_Scheduler();
        // Initialize Action Scheduler integration
        $this->init_action_scheduler();
    }
    
    /**
     * Initialize Action Scheduler integration
     */
    private function init_action_scheduler() {
        // Use existing instance created in load_dependencies (hooks already registered)
        if (!($this->action_scheduler instanceof ByteMash_Action_Scheduler_Sync) && class_exists('ByteMash_Action_Scheduler_Sync')) {
            $this->action_scheduler = new ByteMash_Action_Scheduler_Sync();
        }
        if ($this->action_scheduler instanceof ByteMash_Action_Scheduler_Sync) {
            // Use Action Scheduler for scheduling if available
            $this->use_action_scheduler = $this->action_scheduler->is_action_scheduler_available();
            
            // Only log once per session to avoid spam
            if (!get_transient('bytemash_action_scheduler_logged')) {
                if ($this->use_action_scheduler) {
                    $logger = new ByteMash_Logger();
                    $logger->log('info', 'Action Scheduler integration enabled in main plugin class', array(), 'action_scheduler');
                } else {
                    $logger = new ByteMash_Logger();
                    $logger->log('warning', 'Action Scheduler not available in main plugin class', array(), 'action_scheduler');
                }
                set_transient('bytemash_action_scheduler_logged', true, HOUR_IN_SECONDS);
            }
        } else {
            $this->use_action_scheduler = false;
            if (!get_transient('bytemash_action_scheduler_logged')) {
                $logger = new ByteMash_Logger();
                $logger->log('warning', 'Action Scheduler class not found in main plugin class', array(), 'action_scheduler');
                set_transient('bytemash_action_scheduler_logged', true, HOUR_IN_SECONDS);
            }
        }
    }
    
    /**
     * Check if WooCommerce is active
     */
    public function check_dependencies() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            deactivate_plugins(BYTEMASH_WOO_SYNC_PLUGIN_BASENAME);
            return;
        }
    }
    
    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p><strong>' . esc_html__('ByteMash WooCommerce Amrod Sync', 'bytemash-woo-sync') . '</strong> ' . esc_html__('requires WooCommerce to be installed and active.', 'bytemash-woo-sync') . '</p></div>';
    }
    
    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('bytemash-woo-sync', false, dirname(BYTEMASH_WOO_SYNC_PLUGIN_BASENAME) . '/languages');
    }
    
    /**
     * Register admin menu
     */
    public function register_admin_menu() {
        add_menu_page(
            __('Amrod Sync', 'bytemash-woo-sync'),
            __('Amrod Sync', 'bytemash-woo-sync'),
            'manage_woocommerce',
            'bytemash-amrod-sync',
            array('ByteMash_Admin_Dashboard', 'render_dashboard'),
            'dashicons-update',
            56
        );
        
        add_submenu_page(
            'bytemash-amrod-sync',
            __('Dashboard', 'bytemash-woo-sync'),
            __('Dashboard', 'bytemash-woo-sync'),
            'manage_woocommerce',
            'bytemash-amrod-sync',
            array('ByteMash_Admin_Dashboard', 'render_dashboard')
        );
        
        add_submenu_page(
            'bytemash-amrod-sync',
            __('Settings', 'bytemash-woo-sync'),
            __('Settings', 'bytemash-woo-sync'),
            'manage_woocommerce',
            'bytemash-amrod-settings',
            array('ByteMash_Admin_Settings', 'render_settings')
        );
        
        add_submenu_page(
            'bytemash-amrod-sync',
            __('Sync Logs', 'bytemash-woo-sync'),
            __('Sync Logs', 'bytemash-woo-sync'),
            'manage_woocommerce',
            'bytemash-amrod-logs',
            array('ByteMash_Admin_Dashboard', 'render_logs')
        );
        
        add_submenu_page(
            'bytemash-amrod-sync',
            __('Admin Tools', 'bytemash-woo-sync'),
            __('Admin Tools', 'bytemash-woo-sync'),
            'manage_woocommerce',
            'bytemash-amrod-tools',
            array('ByteMash_Admin_Tools', 'render')
        );
        
        // Add Product Count Test page
        add_submenu_page(
            'bytemash-amrod-sync',
            __('Product Count Test', 'bytemash-woo-sync'),
            __('Product Count Test', 'bytemash-woo-sync'),
            'manage_woocommerce',
            'bytemash-product-count-test',
            array($this, 'render_product_count_test_page')
        );

        // Add Quote Requests Page
        add_submenu_page(
            'bytemash-amrod-sync',
            __('Quote Requests', 'bytemash-woo-sync'),
            __('Quote Requests', 'bytemash-woo-sync'),
            'manage_woocommerce',
            'bytemash-quote-requests',
            array($this, 'render_quote_requests_page')
        );

        // Add Quote Settings Page
        add_submenu_page(
            'bytemash-amrod-sync',
            __('Quote Settings', 'bytemash-woo-sync'),
            __('Quote Settings', 'bytemash-woo-sync'),
            'manage_woocommerce',
            'bytemash-quote-settings',
            array($this, 'render_quote_settings_page')
        );
    }
    
    /**
     * Render Quote Requests Page (Wrapper for ByteMash_Quote_Admin)
     */
    public function render_quote_requests_page() {
        $instance = ByteMash_Quote_Admin::get_instance();
        if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
            $instance->render_quote_details_page(intval($_GET['id']));
        } else {
            $instance->render_quote_list_page();
        }
    }

    /**
     * Render Quote Settings Page (Wrapper for ByteMash_Quote_Admin)
     */
    public function render_quote_settings_page() {
        ByteMash_Quote_Admin::get_instance()->render_settings_page();
    }
    
    /**
     * Render Product Count Test Page
     */
    public function render_product_count_test_page() {
        // Check if WP_DEBUG is enabled
        $debug_enabled = defined('WP_DEBUG') && WP_DEBUG;
        $debug_log_enabled = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Product Count Test', 'bytemash-woo-sync'); ?></h1>
            <p><?php echo esc_html__('This page shows raw product counts from the Amrod API and category product counts in WooCommerce.', 'bytemash-woo-sync'); ?></p>
            
            <?php if (!$debug_enabled || !$debug_log_enabled): ?>
            <div class="notice notice-warning" style="margin-top: 20px;">
                <p><strong><?php echo esc_html__('Debug Logging is NOT Enabled', 'bytemash-woo-sync'); ?></strong></p>
                <p><?php echo esc_html__('To see detailed error logs, add these lines to your wp-config.php:', 'bytemash-woo-sync'); ?></p>
                <pre style="background: #f5f5f5; padding: 10px; border: 1px solid #ddd;">define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);</pre>
                <p><?php echo esc_html__('Debug logs will be saved to: wp-content/debug.log', 'bytemash-woo-sync'); ?></p>
            </div>
            <?php else: ?>
            <div class="notice notice-success" style="margin-top: 20px;">
                <p><strong><?php echo esc_html__('Debug Logging is Enabled ✓', 'bytemash-woo-sync'); ?></strong></p>
                <p><?php echo esc_html__('All operations are being logged to:', 'bytemash-woo-sync'); ?> <code><?php echo esc_html(WP_CONTENT_DIR . '/debug.log'); ?></code></p>
                <p><?php echo esc_html__('Look for lines starting with "ByteMash Product Count Test:"', 'bytemash-woo-sync'); ?></p>
            </div>
            <?php endif; ?>
            
            <div id="product-count-results" style="margin-top: 20px;">
                <button type="button" class="button button-primary" id="run-product-count-test">
                    <?php echo esc_html__('Run Product Count Test', 'bytemash-woo-sync'); ?>
                </button>
                <span class="spinner" style="float: none; margin: 0 10px;"></span>
                <div id="count-results" style="margin-top: 20px;"></div>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#run-product-count-test').on('click', function() {
                var button = $(this);
                var spinner = button.next('.spinner');
                var resultsDiv = $('#count-results');
                
                button.prop('disabled', true);
                spinner.addClass('is-active');
                resultsDiv.html('<p>Loading product counts...</p>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bytemash_get_product_counts',
                        nonce: '<?php echo wp_create_nonce('bytemash_product_counts'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var html = '<div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px;">';
                            
                            // API Product Count
                            html += '<h2 style="margin-top: 0;">API Product Count</h2>';
                            html += '<p style="font-size: 18px;"><strong>Total Products from API:</strong> ' + response.data.api_count + '</p>';
                            html += '<div style="margin-left: 20px; padding: 10px; background: #f9f9f9; border-left: 3px solid #2271b1;">';
                            html += '<p style="margin: 5px 0;"><strong>Regular Products:</strong> ' + response.data.api_regular_count + '</p>';
                            html += '<p style="margin: 5px 0;"><strong>Decoupled Products:</strong> ' + response.data.api_decoupled_count + ' <em style="color: #666;">(standalone, sold separately)</em></p>';
                            html += '</div>';
                            
                            // WooCommerce Product Count
                            html += '<h2>WooCommerce Product Count</h2>';
                            html += '<p style="font-size: 18px;"><strong>Total Products in WooCommerce:</strong> ' + response.data.wc_total + '</p>';
                            html += '<div style="margin-left: 20px; padding: 10px; background: #f9f9f9; border-left: 3px solid #2271b1;">';
                            html += '<p style="margin: 5px 0;"><strong>Regular Products:</strong> ' + response.data.wc_regular_count + '</p>';
                            html += '<p style="margin: 5px 0;"><strong>Decoupled Products:</strong> ' + response.data.wc_decoupled_count + ' <em style="color: #666;">(marked with _amrod_decoupled meta)</em></p>';
                            html += '</div>';
                            
                            // Category Counts
                            html += '<h2>Category Product Counts (API vs Database)</h2>';
                            html += '<p class="description">Compares product counts from the Amrod API with what\'s stored in WooCommerce. Discrepancies indicate products that should be in a category but aren\'t assigned correctly.</p>';
                            html += '<table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">';
                            html += '<thead><tr>';
                            html += '<th style="padding: 10px;">Category Name</th>';
                            html += '<th style="padding: 10px;">API Count</th>';
                            html += '<th style="padding: 10px;">DB Direct</th>';
                            html += '<th style="padding: 10px;">DB Total (with children)</th>';
                            html += '<th style="padding: 10px;">Discrepancy</th>';
                            html += '<th style="padding: 10px;">Parent</th>';
                            html += '</tr></thead><tbody>';
                            
                            var categoriesWithDiscrepancy = 0;
                            
                            $.each(response.data.categories, function(index, category) {
                                var rowClass = '';
                                var discrepancyText = '';
                                var discrepancyColor = '';
                                
                                if (category.has_discrepancy) {
                                    categoriesWithDiscrepancy++;
                                    rowClass = 'style="background-color: #fff3cd;"';
                                    var discrepancy = category.discrepancy || 0;
                                    if (discrepancy > 0) {
                                        discrepancyText = '<strong style="color: #dc3545;">+' + discrepancy + '</strong> (API has more)';
                                        discrepancyColor = '#dc3545';
                                    } else {
                                        discrepancyText = '<strong style="color: #ff9800;">' + discrepancy + '</strong> (DB has more)';
                                        discrepancyColor = '#ff9800';
                                    }
                                } else {
                                    discrepancyText = '<span style="color: #28a745;">✓ Match</span>';
                                }
                                
                                html += '<tr ' + rowClass + '>';
                                html += '<td style="padding: 10px;"><strong>' + category.name + '</strong>';
                                if (category.path) {
                                    html += '<br><small style="color: #666;">Path: ' + category.path + '</small>';
                                }
                                html += '</td>';
                                html += '<td style="padding: 10px; text-align: center;">';
                                if (category.api_count > 0) {
                                    html += '<strong style="color: #0073aa;">' + category.api_count + '</strong>';
                                } else {
                                    html += '<span style="color: #999;">-</span>';
                                }
                                html += '</td>';
                                html += '<td style="padding: 10px; text-align: center;">' + category.direct_count + '</td>';
                                html += '<td style="padding: 10px; text-align: center;"><strong style="color: #0073aa;">' + category.total_count + '</strong></td>';
                                html += '<td style="padding: 10px; text-align: center;">' + discrepancyText + '</td>';
                                html += '<td style="padding: 10px;">' + (category.parent || '-') + '</td>';
                                html += '</tr>';
                            });
                            
                            html += '</tbody></table>';
                            
                            if (categoriesWithDiscrepancy > 0) {
                                html += '<div class="notice notice-warning" style="margin-top: 20px;">';
                                html += '<p><strong>⚠️ Found ' + categoriesWithDiscrepancy + ' categor' + (categoriesWithDiscrepancy === 1 ? 'y' : 'ies') + ' with discrepancies.</strong> ';
                                html += 'This indicates that some products from the API are not correctly assigned to categories in WooCommerce. ';
                                html += 'Run a full product sync to fix these discrepancies.</p>';
                                html += '</div>';
                            } else {
                                html += '<div class="notice notice-success" style="margin-top: 20px;">';
                                html += '<p><strong>✓ All category counts match!</strong> All products are correctly assigned to their categories.</p>';
                                html += '</div>';
                            }
                            html += '</div>';
                            
                            resultsDiv.html(html);
                        } else {
                            resultsDiv.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        resultsDiv.html('<div class="notice notice-error"><p>An error occurred while fetching product counts.</p></div>');
                    },
                    complete: function() {
                        button.prop('disabled', false);
                        spinner.removeClass('is-active');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'bytemash-amrod') === false) {
            return;
        }
        
        // Force enqueue jQuery first to ensure it's loaded
        wp_enqueue_script('jquery');
        
        // Use filemtime for cache busting in addition to version
        $css_file = BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'assets/css/admin.css';
        $js_file = BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'assets/js/admin.js';
        
        $css_version = BYTEMASH_WOO_SYNC_VERSION . '.' . (file_exists($css_file) ? filemtime($css_file) : time());
        $js_version = BYTEMASH_WOO_SYNC_VERSION . '.' . (file_exists($js_file) ? filemtime($js_file) : time());
        
        wp_enqueue_style(
            'bytemash-woo-sync-admin',
            BYTEMASH_WOO_SYNC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            $css_version
        );
        
        wp_enqueue_script(
            'bytemash-woo-sync-admin',
            BYTEMASH_WOO_SYNC_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            $js_version,
            true
        );
        
        $production_full_sync_enabled = (bool) get_option('bytemash_cron_production_full_sync_enabled', false);
        $action_scheduler_detected = function_exists('as_get_scheduled_actions') && function_exists('as_schedule_recurring_action');
        
        // Ensure localized data is always available
        $localize_data = array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bytemash_woo_sync_nonce'),
            'strings' => array(
                'syncing' => __('Syncing...', 'bytemash-woo-sync'),
                'success' => __('Sync completed successfully!', 'bytemash-woo-sync'),
                'error' => __('Sync failed. Check logs for details.', 'bytemash-woo-sync'),
                'scheduler_running' => __('Action Scheduler is running now', 'bytemash-woo-sync'),
                'scheduler_idle' => __('Action Scheduler is waiting for the next schedule', 'bytemash-woo-sync'),
                'never' => __('Never', 'bytemash-woo-sync'),
                'schedule_pending' => __('Schedule pending', 'bytemash-woo-sync'),
                'monitor_error' => __('Unable to fetch Action Scheduler status.', 'bytemash-woo-sync'),
                'action_scheduler_missing' => __('Action Scheduler is not available on this site.', 'bytemash-woo-sync'),
                'monitor_loading' => __('Checking Action Scheduler status...', 'bytemash-woo-sync'),
            ),
            'debug' => array(
                'plugin_url' => BYTEMASH_WOO_SYNC_PLUGIN_URL,
                'is_admin' => is_admin(),
                'hook' => $hook,
            ),
            'production_full_sync' => array(
                'enabled' => $production_full_sync_enabled,
                'poll_interval' => 30000,
                'action_scheduler_available' => $action_scheduler_detected,
            ),
        );
        
        wp_localize_script('bytemash-woo-sync-admin', 'bytemashWooSync', $localize_data);
        
        // Add inline script to verify JavaScript is loading
        wp_add_inline_script('bytemash-woo-sync-admin', 
            'console.log("ByteMash WooSync Admin JS Loaded", bytemashWooSync);',
            'after'
        );
    }

    /**
     * Add a "Branding Guide" tab on the single product page
     */
    public function add_branding_guide_tab($tabs) {
        if (!is_product()) {
            return $tabs;
        }
        global $product;
        if (!$product) {
            return $tabs;
        }
        $product_id = $product->get_id();
        $full = get_post_meta($product_id, '_amrod_full_branding_guide', true);
        $logo24 = get_post_meta($product_id, '_amrod_logo24_branding_guide', true);
        $guide_url = $full ?: $logo24;
        if (empty($guide_url)) {
            return $tabs;
        }

        $tabs['branding_guide'] = array(
            'title'    => __('Branding Guide', 'bytemash-woo-sync'),
            'priority' => 40,
            'callback' => array($this, 'render_branding_guide_tab_content'),
        );
        return $tabs;
    }

    /**
     * Render the Branding Guide tab content
     * Uses lazy-loading and multiple embedding methods to prevent Firefox auto-download
     */
    public function render_branding_guide_tab_content() {
        global $product;
        if (!$product) {
            return;
        }
        $product_id = $product->get_id();
        $full = get_post_meta($product_id, '_amrod_full_branding_guide', true);
        $logo24 = get_post_meta($product_id, '_amrod_logo24_branding_guide', true);
        
        echo '<div class="bytemash-branding-guide-tab">';
        
        // Show both guides if available
        if (!empty($full)) {
            $this->render_single_branding_guide($full, __('Full Branding Guide', 'bytemash-woo-sync'), 'full');
        }
        
        if (!empty($logo24)) {
            $this->render_single_branding_guide($logo24, __('Logo24 Branding Guide', 'bytemash-woo-sync'), 'logo24');
        }
        
        if (empty($full) && empty($logo24)) {
            echo '<p>' . esc_html__('Branding guide not available for this product.', 'bytemash-woo-sync') . '</p>';
        }
        
        $this->render_product_dimension_details_section($product_id);
        echo '</div>';
    }
    
    /**
     * Render a single branding guide with lazy-loading to prevent auto-download
     */
    private function render_single_branding_guide($guide_url, $title, $id_suffix) {
        $safe_url = esc_url($guide_url);
        $container_id = 'bytemash-pdf-container-' . $id_suffix;
        $button_id = 'bytemash-pdf-toggle-' . $id_suffix;
        
        ?>
        <div class="bytemash-branding-guide-item" style="margin-bottom: 30px;">
            <h4 style="margin: 0 0 10px 0; color: #333;"><?php echo esc_html($title); ?></h4>
            
            <!-- Quick action buttons -->
            <div style="margin-bottom: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="<?php echo $safe_url; ?>" 
                   target="_blank" 
                   rel="noopener noreferrer" 
                   class="button button-primary"
                   style="text-decoration: none;">
                     <?php esc_html_e('Open in New Tab', 'bytemash-woo-sync'); ?>
                </a>
                
                <button type="button" 
                        id="<?php echo esc_attr($button_id); ?>" 
                        class="button"
                        data-pdf-url="<?php echo esc_attr($safe_url); ?>"
                        data-container-id="<?php echo esc_attr($container_id); ?>">
                    <?php esc_html_e('View Here', 'bytemash-woo-sync'); ?>
                </button>
            </div>
            
            <!-- PDF container (hidden by default, loads on click) -->
            <div id="<?php echo esc_attr($container_id); ?>" 
                 style="display: none; border: 1px solid #ddd; border-radius: 4px; overflow: hidden; background: #f5f5f5; min-height: 600px;">
                <div style="padding: 10px; background: #fff; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-weight: 600;"><?php echo esc_html($title); ?></span>
                    <button type="button" 
                            class="button button-small" 
                            onclick="document.getElementById('<?php echo esc_js($container_id); ?>').style.display='none';"
                            style="padding: 5px 10px;">
                        ✕ <?php esc_html_e('Close', 'bytemash-woo-sync'); ?>
                    </button>
                </div>
                <div style="position: relative; height: 600px; width: 100%;">
                    <!-- Use object tag first (better Firefox compatibility) -->
                    <object id="<?php echo esc_attr($container_id); ?>-object" 
                            data="" 
                            type="application/pdf" 
                            width="100%" 
                            height="100%"
                            style="display: none;">
                        <iframe id="<?php echo esc_attr($container_id); ?>-iframe" 
                                src="" 
                                width="100%" 
                                height="100%" 
                                style="border: none; display: none;"
                                loading="lazy">
                            <p style="padding: 20px; text-align: center;">
                                <?php esc_html_e('Your browser does not support PDF viewing.', 'bytemash-woo-sync'); ?>
                                <a href="<?php echo $safe_url; ?>" target="_blank"><?php esc_html_e('Download PDF', 'bytemash-woo-sync'); ?></a>
                            </p>
                        </iframe>
                    </object>
                    <div id="<?php echo esc_attr($container_id); ?>-loading" 
                         style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                        <div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 10px;"></div>
                        <p><?php esc_html_e('Loading PDF...', 'bytemash-woo-sync'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        </style>
        
        <script>
        (function() {
            var button = document.getElementById('<?php echo esc_js($button_id); ?>');
            var container = document.getElementById('<?php echo esc_js($container_id); ?>');
            var objectEl = document.getElementById('<?php echo esc_js($container_id); ?>-object');
            var iframeEl = document.getElementById('<?php echo esc_js($container_id); ?>-iframe');
            var loadingEl = document.getElementById('<?php echo esc_js($container_id); ?>-loading');
            var pdfUrl = '<?php echo esc_js($safe_url); ?>';
            var loaded = false;
            
            if (!button || !container) return;
            
            button.addEventListener('click', function() {
                if (container.style.display === 'none') {
                    // Show container
                    container.style.display = 'block';
                    
                    // Load PDF only on first click (prevents auto-download on page load)
                    if (!loaded) {
                        loadingEl.style.display = 'block';
                        
                        // Try object tag first (better Firefox support)
                        objectEl.data = pdfUrl + '#view=FitH';
                        objectEl.style.display = 'block';
                        
                        // Fallback to iframe if object fails
                        objectEl.onerror = function() {
                            objectEl.style.display = 'none';
                            iframeEl.src = pdfUrl + '#view=FitH';
                            iframeEl.style.display = 'block';
                        };
                        
                        // Hide loading when PDF loads
                        setTimeout(function() {
                            loadingEl.style.display = 'none';
                        }, 1000);
                        
                        loaded = true;
                    } else {
                        loadingEl.style.display = 'none';
                    }
                    
                    button.textContent = ' <?php echo esc_js(__('Hide Inline View', 'bytemash-woo-sync')); ?>';
                } else {
                    // Hide container
                    container.style.display = 'none';
                    button.textContent = ' <?php echo esc_js(__('View Inline', 'bytemash-woo-sync')); ?>';
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Render dimension details section for product tab
     */
    private function render_product_dimension_details_section($product_id) {
        $show_dimensions = get_option('bytemash_show_dimension_details', true);
        if (!$show_dimensions) {
            return;
        }

        $details = get_post_meta($product_id, '_amrod_dimension_details', true);
        if (empty($details) || !is_array($details)) {
            return;
        }

        echo '<div class="bytemash-dimension-details">';
        echo '<h3>' . esc_html__('Dimension Details', 'bytemash-woo-sync') . '</h3>';

        if (!empty($details['product']) && is_array($details['product'])) {
            $product_measurements = $this->format_dimension_measurements($details['product']);
            $product_weight = $this->extract_dimension_weight($details['product']);

            if ($product_measurements || $product_weight !== '') {
                echo '<div class="bytemash-dimension-block">';
                echo '<h4>' . esc_html__('Product', 'bytemash-woo-sync') . '</h4>';
                if ($product_measurements) {
                    echo '<p>' . esc_html($product_measurements) . '</p>';
                }
                if ($product_weight !== '') {
                    /* translators: %s: Product weight */
                    echo '<p>' . esc_html(sprintf(__('Weight: %s', 'bytemash-woo-sync'), $product_weight)) . '</p>';
                }
                echo '</div>';
            }
        }

        if (!empty($details['packaging']) && is_array($details['packaging'])) {
            $packaging_measurements = $this->format_dimension_measurements($details['packaging']);
            $packaging_weight = $this->extract_dimension_weight($details['packaging']);
            $pieces_per_carton = $this->extract_dimension_value($details['packaging'], 'pieces_per_carton');

            if ($packaging_measurements || $packaging_weight !== '' || $pieces_per_carton !== '') {
                echo '<div class="bytemash-dimension-block">';
                echo '<h4>' . esc_html__('Packaging', 'bytemash-woo-sync') . '</h4>';
                if ($packaging_measurements) {
                    /* translators: %s: packaging measurements */
                    echo '<p>' . esc_html(sprintf(__('Carton size: %s', 'bytemash-woo-sync'), $packaging_measurements)) . '</p>';
                }
                if ($pieces_per_carton !== '') {
                    /* translators: %s: pieces per carton */
                    echo '<p>' . esc_html(sprintf(__('Pieces per carton: %s', 'bytemash-woo-sync'), $pieces_per_carton)) . '</p>';
                }
                if ($packaging_weight !== '') {
                    /* translators: %s: packaging weight */
                    echo '<p>' . esc_html(sprintf(__('Carton weight: %s', 'bytemash-woo-sync'), $packaging_weight)) . '</p>';
                }
                echo '</div>';
            }
        }

        if (!empty($details['variants']) && is_array($details['variants'])) {
            $has_variant_dimensions = false;
            foreach ($details['variants'] as $variant) {
                if (!empty($variant['product']) || !empty($variant['packaging'])) {
                    $has_variant_dimensions = true;
                    break;
                }
            }

            if ($has_variant_dimensions) {
                echo '<div class="bytemash-dimension-block bytemash-dimension-variants">';
                echo '<h4>' . esc_html__('Variants', 'bytemash-woo-sync') . '</h4>';
                echo '<div class="bytemash-dimension-table-wrapper">';
                echo '<table class="bytemash-dimension-table">';
                echo '<thead><tr>';
                echo '<th>' . esc_html__('Variant', 'bytemash-woo-sync') . '</th>';
                echo '<th>' . esc_html__('Product Dimensions', 'bytemash-woo-sync') . '</th>';
                echo '<th>' . esc_html__('Product Weight', 'bytemash-woo-sync') . '</th>';
                echo '<th>' . esc_html__('Packaging Dimensions', 'bytemash-woo-sync') . '</th>';
                echo '<th>' . esc_html__('Pieces/Carton', 'bytemash-woo-sync') . '</th>';
                echo '<th>' . esc_html__('Carton Weight', 'bytemash-woo-sync') . '</th>';
                echo '</tr></thead><tbody>';

                foreach ($details['variants'] as $variant) {
                    if (empty($variant['product']) && empty($variant['packaging'])) {
                        continue;
                    }

                    $variant_label = $this->format_variant_label($variant);
                    $variant_product_measurements = !empty($variant['product']) ? $this->format_dimension_measurements($variant['product']) : '';
                    $variant_product_weight = !empty($variant['product']) ? $this->extract_dimension_weight($variant['product']) : '';
                    $variant_packaging_measurements = !empty($variant['packaging']) ? $this->format_dimension_measurements($variant['packaging']) : '';
                    $variant_pieces = !empty($variant['packaging']) ? $this->extract_dimension_value($variant['packaging'], 'pieces_per_carton') : '';
                    $variant_carton_weight = !empty($variant['packaging']) ? $this->extract_dimension_weight($variant['packaging']) : '';

                    echo '<tr>';
                    echo '<td>' . esc_html($variant_label) . '</td>';
                    echo '<td>' . esc_html($variant_product_measurements !== '' ? $variant_product_measurements : '—') . '</td>';
                    echo '<td>' . esc_html($variant_product_weight !== '' ? $variant_product_weight : '—') . '</td>';
                    echo '<td>' . esc_html($variant_packaging_measurements !== '' ? $variant_packaging_measurements : '—') . '</td>';
                    echo '<td>' . esc_html($variant_pieces !== '' ? $variant_pieces : '—') . '</td>';
                    echo '<td>' . esc_html($variant_carton_weight !== '' ? $variant_carton_weight : '—') . '</td>';
                    echo '</tr>';
                }

                echo '</tbody></table>';
                echo '</div></div>';
            }
        }

        echo '</div>';
    }

    /**
     * Build formatted dimension string (Length/Width/Height/Depth)
     */
    private function format_dimension_measurements($values) {
        if (empty($values) || !is_array($values)) {
            return '';
        }

        $labels = array(
            'length' => __('L', 'bytemash-woo-sync'),
            'width' => __('W', 'bytemash-woo-sync'),
            'height' => __('H', 'bytemash-woo-sync'),
            'depth' => __('D', 'bytemash-woo-sync'),
        );

        $parts = array();
        foreach ($labels as $key => $label) {
            if (isset($values[$key]) && $values[$key] !== '') {
                $parts[] = sprintf('%s: %s', $label, $this->format_dimension_value_for_display($values[$key]));
            }
        }

        return !empty($parts) ? implode(' | ', $parts) : '';
    }

    /**
     * Extract weight value from dimension array
     */
    private function extract_dimension_weight($values) {
        if (!is_array($values) || !array_key_exists('weight', $values)) {
            return '';
        }

        return $this->format_dimension_value_for_display($values['weight']);
    }

    /**
     * Extract specific dimension value (e.g. pieces_per_carton)
     */
    private function extract_dimension_value($values, $key) {
        if (!is_array($values) || !array_key_exists($key, $values)) {
            return '';
        }

        return $this->format_dimension_value_for_display($values[$key]);
    }

    /**
     * Format numeric/string dimension value for display
     */
    private function format_dimension_value_for_display($value) {
        if (is_numeric($value)) {
            $formatted = number_format((float) $value, 2, '.', '');
            $formatted = rtrim(rtrim($formatted, '0'), '.');
            return $formatted;
        }

        return (string) $value;
    }

    /**
     * Build human-readable variant label
     */
    private function format_variant_label($variant) {
        $parts = array();

        if (is_array($variant)) {
            if (!empty($variant['colour'])) {
                $parts[] = $variant['colour'];
            }
            if (!empty($variant['size'])) {
                $parts[] = $variant['size'];
            }
            if (!empty($variant['code'])) {
                $code = $variant['code'];
                $parts[] = sprintf(__('Code: %s', 'bytemash-woo-sync'), $code);
            }
        }

        return !empty($parts) ? implode(' | ', array_map('strval', $parts)) : __('Variant', 'bytemash-woo-sync');
    }

    /**
     * Enqueue frontend assets for stock modal
     */
    public function enqueue_frontend_assets() {
        if (!is_product()) {
            return;
        }
        
        // Stock modal assets
        wp_enqueue_style('bytemash-stock-modal', plugins_url('assets/css/stock-modal.css', __FILE__), array(), BYTEMASH_WOO_SYNC_VERSION);
        wp_enqueue_script('bytemash-stock-modal', plugins_url('assets/js/stock-modal.js', __FILE__), array('jquery'), BYTEMASH_WOO_SYNC_VERSION, true);
        wp_localize_script('bytemash-stock-modal', 'bytemashStockModal', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bytemash_woo_sync_nonce'),
            'product_id' => get_the_ID(),
        ));
        
        // Color swatches and size buttons assets
        // Load for ALL products (not just variable) to support quote requests
        // Get product properly - global $product might not be available at this hook point
        $product_id = get_the_ID();
        $product = wc_get_product($product_id);
        
        if ($product && is_a($product, 'WC_Product')) {
            // Always load color swatches CSS and JS for quote requests
            // Load color swatches CSS
            wp_enqueue_style('bytemash-color-swatches', plugins_url('assets/css/color-swatches.css', __FILE__), array(), BYTEMASH_WOO_SYNC_VERSION);
            
            // Load color swatches JS
            wp_enqueue_script('bytemash-color-swatches', plugins_url('assets/js/color-swatches.js', __FILE__), array('jquery'), BYTEMASH_WOO_SYNC_VERSION, true);
            
            // Get color swatches data for JavaScript
            $product_id = $product->get_id();
            $color_mapping = get_post_meta($product_id, '_amrod_color_mapping', true);
            $swatches_data = array();
            
            // For variable products, get color mapping from meta
            if (!empty($color_mapping) && is_array($color_mapping)) {
                foreach ($color_mapping as $color_name => $color_code) {
                    $swatch_data = get_option("amrod_color_swatch_{$color_code}");
                    if ($swatch_data && !empty($swatch_data['hexValue']) && is_array($swatch_data['hexValue'])) {
                        $swatches_data[strtolower($color_name)] = array(
                            'code' => $color_code,
                            'name' => $swatch_data['name'] ?? $color_name,
                            'hexValue' => $swatch_data['hexValue'][0] ?? '',
                            'textColour' => $swatch_data['textColour'] ?? '#000',
                            'tickColour' => $swatch_data['tickColour'] ?? '#fff',
                        );
                    }
                }
            }
            
            // For simple products, try to get color data from product attributes if available
            if (empty($swatches_data) && !$product->is_type('variable')) {
                $attributes = $product->get_attributes();
                if (!empty($attributes)) {
                    foreach ($attributes as $attr_name => $attr) {
                        if (strtolower($attr_name) === 'color' || strtolower($attr_name) === 'pa_color') {
                            $options = $attr->get_options();
                            if (!empty($options)) {
                                foreach ($options as $color_name) {
                                    // Try to find swatch data for this color
                                    $swatch_data = null;
                                    // Search through all color swatches to find a match
                                    $all_swatches = get_option('amrod_color_swatches', array());
                                    if (!empty($all_swatches)) {
                                        foreach ($all_swatches as $code => $swatch) {
                                            if (isset($swatch['name']) && strtolower($swatch['name']) === strtolower($color_name)) {
                                                $swatch_data = $swatch;
                                                break;
                                            }
                                        }
                                    }
                                    
                                    if ($swatch_data && !empty($swatch_data['hexValue'])) {
                                        $hex_value = is_array($swatch_data['hexValue']) ? $swatch_data['hexValue'][0] : $swatch_data['hexValue'];
                                        $swatches_data[strtolower($color_name)] = array(
                                            'code' => $code ?? '',
                                            'name' => $swatch_data['name'] ?? $color_name,
                                            'hexValue' => $hex_value,
                                            'textColour' => $swatch_data['textColour'] ?? '#000',
                                            'tickColour' => $swatch_data['tickColour'] ?? '#fff',
                                        );
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            // Pass color swatches data to JavaScript (even if empty, so JS can still run)
            wp_localize_script('bytemash-color-swatches', 'bytemashColorSwatches', $swatches_data);
        }
    }

    /**
     * Enqueue branding modal assets
     */
    public function enqueue_brandings_assets() {
        if (!is_product()) {
            return;
        }
        // Reuse stock modal CSS for consistent styling
        wp_enqueue_style('bytemash-stock-modal');
        wp_enqueue_script('bytemash-brandings-modal', plugins_url('assets/js/branding-modal.js', __FILE__), array('jquery'), BYTEMASH_WOO_SYNC_VERSION, true);
        wp_localize_script('bytemash-brandings-modal', 'bytemashBrandingsModal', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bytemash_woo_sync_nonce'),
            'product_id' => get_the_ID(),
        ));
    }

    /**
     * Render button and modal container on product page
     */
    public function render_stock_modal_trigger() {
        global $product;
        static $bytemash_stock_modal_rendered = false;
        if ($bytemash_stock_modal_rendered) {
            return;
        }
        if (!$product) {
            return;
        }
        echo '<div id="bytemash-stock-modal-trigger" class="bytemash-stock-trigger"><button type="button" class="button">View Stock Availability</button></div>';
        echo '<div id="bytemash-stock-modal" class="bytemash-stock-modal" style="display:none">'
            . '<div class="bytemash-stock-modal__dialog">'
            . '<button type="button" class="bytemash-stock-modal__close" aria-label="Close">×</button>'
            . '<h3>Stock Availability</h3>'
            . '<div id="bytemash-stock-modal__content"><div class="bytemash-spinner"></div></div>'
            . '</div>'
            . '</div>';
        $bytemash_stock_modal_rendered = true;
    }

    /**
     * Render brandings button + modal container on product page
     */
    public function render_brandings_modal_trigger() {
        global $product;
        static $bytemash_brandings_modal_rendered = false;
        if ($bytemash_brandings_modal_rendered) {
            return;
        }
        if (!$product) {
            return;
        }
        echo '<div id="bytemash-brandings-modal-trigger" class="bytemash-stock-trigger"><button type="button" class="button">View Branding Options</button></div>';
        echo '<div id="bytemash-brandings-modal" class="bytemash-stock-modal" style="display:none">'
            . '<div class="bytemash-stock-modal__dialog">'
            . '<button type="button" class="bytemash-stock-modal__close" aria-label="Close">×</button>'
            . '<h3>Branding Options</h3>'
            . '<div id="bytemash-brandings-modal__content"><div class="bytemash-spinner"></div></div>'
            . '</div>'
            . '</div>';
        $bytemash_brandings_modal_rendered = true;
    }

    /**
     * AJAX: Get branding options for product
     */
    public function ajax_get_product_brandings() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        if (!$product_id) {
            wp_send_json_error(array('message' => __('Invalid product.', 'bytemash-woo-sync')));
        }
        $brandings = get_post_meta($product_id, '_amrod_brandings', true);
        if (empty($brandings) || !is_array($brandings)) {
            wp_send_json_success(array('html' => '<p>No branding options available for this product.</p>'));
        }
        // Build simple HTML list of positions and methods
        ob_start();
        echo '<div class="bytemash-brandings">';
        foreach ($brandings as $position) {
            $posName = esc_html($position['positionName'] ?? '');
            echo '<div class="bytemash-branding-pos">';
            echo '<h4>' . $posName . '</h4>';
            if (!empty($position['method']) && is_array($position['method'])) {
                echo '<ul class="bytemash-branding-methods">';
                foreach ($position['method'] as $method) {
                    $mName = esc_html($method['brandingName'] ?? '');
                    $dept = esc_html($method['brandingDepartment'] ?? '');
                    $code = esc_html($method['brandingCode'] ?? '');
                    $w = esc_html($method['maxPrintingSizeWidth'] ?? '');
                    $h = esc_html($method['maxPrintingSizeHeight'] ?? '');
                    echo '<li>' . $mName . ' (' . $dept . ', ' . $code . ') - ' . $w . ' x ' . $h . ' mm</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        }
        echo '</div>';
        $html = ob_get_clean();
        wp_send_json_success(array('html' => $html));
    }
    
    /**
     * AJAX handler for product count test page
     */
    public function ajax_get_product_counts() {
        try {
            error_log('ByteMash Product Count Test: Starting product count test');
            
            check_ajax_referer('bytemash_product_counts', 'nonce');
            
            error_log('ByteMash Product Count Test: Nonce verified');
            
            // Initialize handlers
            $api_client = new ByteMash_Amrod_API_Client();
            $logger = new ByteMash_Logger();
            
            error_log('ByteMash Product Count Test: Handlers initialized');
            
            // Get API product count
            error_log('ByteMash Product Count Test: Fetching products from API...');
            $api_products = $api_client->get_products_with_branding();
            
            if (is_wp_error($api_products)) {
                error_log('ByteMash Product Count Test ERROR: API returned WP_Error - ' . $api_products->get_error_message());
                wp_send_json_error('API Error: ' . $api_products->get_error_message());
                return;
            }
            
            $api_count = is_array($api_products) ? count($api_products) : 0;
            error_log('ByteMash Product Count Test: API product count = ' . $api_count);
            
            // Count decoupled vs regular products
            $decoupled_count = 0;
            $regular_count = 0;
            
            if (is_array($api_products)) {
                foreach ($api_products as $product) {
                    if (isset($product['decoupled']) && $product['decoupled'] === true) {
                        $decoupled_count++;
                    } else {
                        $regular_count++;
                    }
                }
            }
            
            error_log('ByteMash Product Count Test: Regular products = ' . $regular_count . ', Decoupled products = ' . $decoupled_count);
            
            // Get WooCommerce product count
            error_log('ByteMash Product Count Test: Counting WooCommerce products...');
            $wc_args = array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids'
            );
            $wc_products = get_posts($wc_args);
            $wc_total = count($wc_products);
            error_log('ByteMash Product Count Test: WooCommerce product count = ' . $wc_total);
            
            // Count WooCommerce decoupled products
            $wc_decoupled_count = 0;
            $wc_regular_count = 0;
            
            foreach ($wc_products as $product_id) {
                $is_decoupled = get_post_meta($product_id, '_amrod_decoupled', true);
                if ($is_decoupled) {
                    $wc_decoupled_count++;
                } else {
                    $wc_regular_count++;
                }
            }
            
            error_log('ByteMash Product Count Test: WooCommerce Regular = ' . $wc_regular_count . ', Decoupled = ' . $wc_decoupled_count);
            
            // Count products per category from API data
            error_log('ByteMash Product Count Test: Counting products per category from API...');
            $api_category_counts = array();
            if (is_array($api_products)) {
                foreach ($api_products as $product) {
                    if (!empty($product['categories']) && is_array($product['categories'])) {
                        foreach ($product['categories'] as $cat) {
                            $cat_name = $cat['name'] ?? '';
                            $cat_path = $cat['path'] ?? ($cat['categoryPath'] ?? '');
                            
                            if (!empty($cat_name)) {
                                // Use path as key if available, otherwise use name
                                $key = !empty($cat_path) ? $cat_path : $cat_name;
                                
                                if (!isset($api_category_counts[$key])) {
                                    $api_category_counts[$key] = array(
                                        'name' => $cat_name,
                                        'path' => $cat_path,
                                        'count' => 0
                                    );
                                }
                                $api_category_counts[$key]['count']++;
                            }
                        }
                    }
                }
            }
            error_log('ByteMash Product Count Test: Found ' . count($api_category_counts) . ' categories in API data');
            
            // Get category counts
            error_log('ByteMash Product Count Test: Fetching categories from database...');
            $categories = get_terms(array(
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'orderby' => 'name',
                'order' => 'ASC'
            ));
            
            if (is_wp_error($categories)) {
                error_log('ByteMash Product Count Test ERROR: get_terms returned WP_Error - ' . $categories->get_error_message());
                wp_send_json_error('Category Error: ' . $categories->get_error_message());
                return;
            }
            
            error_log('ByteMash Product Count Test: Found ' . count($categories) . ' categories in database');
            
            $category_data = array();
            $category_count = 0;
            
            foreach ($categories as $category) {
                $category_count++;
                
                try {
                    // Get the category path from metadata
                    $category_path = get_term_meta($category->term_id, '_amrod_category_path', true);
                    
                    // Get direct product count (products directly in this category, not children)
                    $direct_args = array(
                        'post_type' => 'product',
                        'post_status' => 'publish',
                        'posts_per_page' => -1,
                        'fields' => 'ids',
                        'tax_query' => array(
                            array(
                                'taxonomy' => 'product_cat',
                                'field' => 'term_id',
                                'terms' => $category->term_id,
                                'include_children' => false
                            )
                        )
                    );
                    $direct_products = get_posts($direct_args);
                    $direct_count = count($direct_products);
                    
                    // Get total count (including child categories)
                    $total_args = array(
                        'post_type' => 'product',
                        'post_status' => 'publish',
                        'posts_per_page' => -1,
                        'fields' => 'ids',
                        'tax_query' => array(
                            array(
                                'taxonomy' => 'product_cat',
                                'field' => 'term_id',
                                'terms' => $category->term_id,
                                'include_children' => true
                            )
                        )
                    );
                    $total_products = get_posts($total_args);
                    $total_count = count($total_products);
                    
                    // Find matching API category count
                    $api_count = 0;
                    $api_path = '';
                    
                    // Try to match by path first (most accurate)
                    if (!empty($category_path)) {
                        foreach ($api_category_counts as $key => $api_cat) {
                            if ($api_cat['path'] === $category_path || $key === $category_path) {
                                $api_count = $api_cat['count'];
                                $api_path = $api_cat['path'];
                                break;
                            }
                        }
                    }
                    
                    // If no match by path, try by name (less accurate but better than nothing)
                    if ($api_count === 0) {
                        foreach ($api_category_counts as $key => $api_cat) {
                            if ($api_cat['name'] === $category->name) {
                                $api_count = $api_cat['count'];
                                $api_path = $api_cat['path'];
                                break;
                            }
                        }
                    }
                    
                    // Calculate discrepancy
                    $discrepancy = $api_count - $total_count;
                    $has_discrepancy = $discrepancy !== 0;
                    
                    // Get parent category name
                    $parent_name = '';
                    if ($category->parent > 0) {
                        $parent = get_term($category->parent, 'product_cat');
                        if ($parent && !is_wp_error($parent)) {
                            $parent_name = $parent->name;
                        }
                    }
                    
                    $category_data[] = array(
                        'name' => $category->name,
                        'slug' => $category->slug,
                        'path' => $category_path,
                        'api_count' => $api_count,
                        'api_path' => $api_path,
                        'direct_count' => $direct_count,
                        'total_count' => $total_count,
                        'discrepancy' => $discrepancy,
                        'has_discrepancy' => $has_discrepancy,
                        'parent' => $parent_name
                    );
                    
                    if ($has_discrepancy) {
                        error_log('ByteMash Product Count Test: Category "' . $category->name . '" - API: ' . $api_count . ', DB Total: ' . $total_count . ', Discrepancy: ' . $discrepancy);
                    } else {
                        error_log('ByteMash Product Count Test: Category "' . $category->name . '" - API: ' . $api_count . ', DB Total: ' . $total_count . ' (Match)');
                    }
                    
                } catch (Exception $e) {
                    error_log('ByteMash Product Count Test ERROR: Failed to process category "' . $category->name . '" - ' . $e->getMessage());
                    error_log('ByteMash Product Count Test ERROR: Stack trace - ' . $e->getTraceAsString());
                    // Continue with other categories
                }
            }
            
            error_log('ByteMash Product Count Test: Successfully processed all categories');
            error_log('ByteMash Product Count Test: Sending response - API: ' . $api_count . ', WC: ' . $wc_total . ', Categories: ' . count($category_data));
            
            wp_send_json_success(array(
                'api_count' => $api_count,
                'api_regular_count' => $regular_count,
                'api_decoupled_count' => $decoupled_count,
                'wc_total' => $wc_total,
                'wc_regular_count' => $wc_regular_count,
                'wc_decoupled_count' => $wc_decoupled_count,
                'categories' => $category_data
            ));
            
        } catch (Exception $e) {
            error_log('ByteMash Product Count Test FATAL ERROR: ' . $e->getMessage());
            error_log('ByteMash Product Count Test FATAL ERROR: File - ' . $e->getFile() . ' Line - ' . $e->getLine());
            error_log('ByteMash Product Count Test FATAL ERROR: Stack trace - ' . $e->getTraceAsString());
            
            wp_send_json_error('An error occurred: ' . $e->getMessage() . ' (Check debug.log for details)');
        }
    }

    /**
     * Render branding options as multi-select fields on the product page
     */
    public function render_branding_options_fields() {
        // Don't render if quote mode is enabled (quote mode has its own rendering)
        if ($this->is_quote_mode_enabled()) {
            return;
        }
        
        // Prevent duplicate rendering (since we hook to both before_add_to_cart_button and single_product_summary)
        static $branding_options_rendered = false;
        if ($branding_options_rendered) {
            return;
        }
        
        // Get product properly - global $product might not be available
        $product_id = get_the_ID();
        $product = wc_get_product($product_id);
        
        if (!$product || !is_a($product, 'WC_Product')) {
            return;
        }
        
        $brandings = get_post_meta($product_id, '_amrod_brandings', true);
        // Show branding options section even if empty - allows quote requests for all products
        if (empty($brandings) || !is_array($brandings)) {
            // Still show the section header for quote requests, but no options
            return;
        }
        
        // Mark as rendered to prevent duplicates
        $branding_options_rendered = true;
        echo '<div class="bytemash-branding-options">';
        echo '<h4>' . esc_html__('Branding Options', 'bytemash-woo-sync') . '</h4>';
        // Instruction
        echo '<p>' . esc_html__('Select one or more branding methods. Details for each method are shown for informed decisions.', 'bytemash-woo-sync') . '</p>';
        foreach ($brandings as $idx => $pos) {
            $posName = esc_html($pos['positionName'] ?? '');
            $posCode = esc_attr($pos['positionCode'] ?? ('pos_' . $idx));
            echo '<div class="bytemash-branding-group">';
            echo '<strong>' . $posName . '</strong>';
            if (!empty($pos['method']) && is_array($pos['method'])) {
                foreach ($pos['method'] as $midx => $method) {
                    $code = esc_attr($method['brandingCode'] ?? '');
                    $name = esc_html($method['brandingName'] ?? '');
                    $dept = esc_html($method['brandingDepartment'] ?? '');
                    $w = esc_html($method['maxPrintingSizeWidth'] ?? '');
                    $h = esc_html($method['maxPrintingSizeHeight'] ?? '');
                    $field_id = 'bytemash_brandings_' . $posCode . '_' . $code . '_' . $midx;
                    echo '<label style="display:block; margin:6px 0;">';
                    echo '<input type="checkbox" name="bytemash_brandings[' . $posCode . '][]" value="' . $code . '" id="' . $field_id . '" /> ';
                    echo $name . ' (' . $dept . ', ' . $code . ') - ' . $w . ' x ' . $h . ' mm';
                    echo '</label>';
                }
            }
            echo '</div>';
        }
        echo '</div>';
    }
    
    /**
     * Render branding options in quote mode (ensures it shows even if form is hidden)
     */
    public function render_branding_options_fields_quote_mode() {
        if (!$this->is_quote_mode_enabled()) {
            return;
        }
        
        // Prevent duplicate rendering
        static $quote_branding_options_rendered = false;
        if ($quote_branding_options_rendered) {
            return;
        }
        $quote_branding_options_rendered = true;
        
        // Get product
        $product_id = get_the_ID();
        $product = wc_get_product($product_id);
        
        if (!$product || !is_a($product, 'WC_Product')) {
            return;
        }
        
        $brandings = get_post_meta($product_id, '_amrod_brandings', true);
        if (empty($brandings) || !is_array($brandings)) {
            return;
        }
        
        // Mark as rendered to prevent duplicates
        $branding_options_rendered = true;
        echo '<div class="bytemash-branding-options">';
        echo '<h4>' . esc_html__('Branding Options', 'bytemash-woo-sync') . '</h4>';
        echo '<p>' . esc_html__('Select one or more branding methods. Details for each method are shown for informed decisions.', 'bytemash-woo-sync') . '</p>';
        foreach ($brandings as $idx => $pos) {
            $posName = esc_html($pos['positionName'] ?? '');
            $posCode = esc_attr($pos['positionCode'] ?? ('pos_' . $idx));
            echo '<div class="bytemash-branding-group">';
            echo '<strong>' . $posName . '</strong>';
            if (!empty($pos['method']) && is_array($pos['method'])) {
                foreach ($pos['method'] as $midx => $method) {
                    $code = esc_attr($method['brandingCode'] ?? '');
                    $name = esc_html($method['brandingName'] ?? '');
                    $dept = esc_html($method['brandingDepartment'] ?? '');
                    $w = esc_html($method['maxPrintingSizeWidth'] ?? '');
                    $h = esc_html($method['maxPrintingSizeHeight'] ?? '');
                    $field_id = 'bytemash_brandings_' . $posCode . '_' . $code . '_' . $midx;
                    echo '<label style="display:block; margin:6px 0;">';
                    echo '<input type="checkbox" name="bytemash_brandings[' . $posCode . '][]" value="' . $code . '" id="' . $field_id . '" /> ';
                    echo $name . ' (' . $dept . ', ' . $code . ') - ' . $w . ' x ' . $h . ' mm';
                    echo '</label>';
                }
            }
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * Validate branding options posted
     */
    public function validate_branding_options($passed, $product_id, $quantity) {
        // Don't validate branding if validation already failed (let WooCommerce handle its own validation first)
        if (!$passed) {
            return $passed;
        }
        
        // Branding is optional - if no branding is selected, validation passes
        if (!isset($_POST['bytemash_brandings']) || empty($_POST['bytemash_brandings'])) { 
            return $passed; 
        }
        
        $selected = $_POST['bytemash_brandings'];
        $brandings = get_post_meta($product_id, '_amrod_brandings', true);
        
        // If no branding options exist for this product, branding is optional
        if (!is_array($brandings) || empty($brandings)) { 
            return $passed; 
        }
        
        // Build whitelist map positionCode => [codes]
        $map = array();
        foreach ($brandings as $pos) {
            $posCode = $pos['positionCode'] ?? '';
            if (!$posCode) { continue; }
            $map[$posCode] = array();
            if (!empty($pos['method'])) {
                foreach ($pos['method'] as $method) {
                    if (!empty($method['brandingCode'])) {
                        $map[$posCode][] = $method['brandingCode'];
                    }
                }
            }
        }
        
        // Validate selections exist in whitelist (only if branding was actually selected)
        foreach ((array)$selected as $posCode => $codes) {
            // Skip empty selections (branding is optional)
            if (empty($codes)) { 
                continue; 
            }
            
            if (!isset($map[$posCode])) { 
                // Invalid position code
                wc_add_notice(__('Invalid branding position selected.', 'bytemash-woo-sync'), 'error');
                return false;
            }
            
            foreach ((array)$codes as $code) {
                if (!in_array($code, $map[$posCode], true)) {
                    wc_add_notice(__('Invalid branding selection.', 'bytemash-woo-sync'), 'error');
                    return false;
                }
            }
        }
        
        return $passed;
    }

    /**
     * Attach branding data to cart item
     */
    public function add_branding_to_cart_item($cart_item_data, $product_id, $variation_id) {
        if (!isset($_POST['bytemash_brandings'])) { return $cart_item_data; }
        $selected = $_POST['bytemash_brandings'];
        if (!empty($selected)) {
            $cart_item_data['bytemash_brandings'] = array_map(function($arr){ return array_values((array)$arr); }, (array)$selected);
            $cart_item_data['bytemash_brandings_hash'] = md5(wp_json_encode($cart_item_data['bytemash_brandings']));
        }
        return $cart_item_data;
    }

    /**
     * Show branding choices in cart/checkout
     */
    public function display_branding_in_cart($item_data, $cart_item) {
        if (empty($cart_item['bytemash_brandings'])) { return $item_data; }
        $display = array();
        foreach ($cart_item['bytemash_brandings'] as $posCode => $codes) {
            $display[] = strtoupper(sanitize_text_field($posCode)) . ': ' . implode(', ', array_map('sanitize_text_field', (array)$codes));
        }
        $item_data[] = array(
            'key' => __('Branding', 'bytemash-woo-sync'),
            'value' => wp_kses_post(implode('<br/>', $display)),
            'display' => implode(', ', $display),
        );
        return $item_data;
    }

    /**
     * Persist branding choices to order line items
     */
    public function add_branding_to_order_items($item, $cart_item_key, $values, $order) {
        if (empty($values['bytemash_brandings'])) { return; }
        foreach ($values['bytemash_brandings'] as $posCode => $codes) {
            $item->add_meta_data('Branding ' . strtoupper(sanitize_text_field($posCode)), implode(', ', array_map('sanitize_text_field', (array)$codes)), true);
        }
    }

    /**
     * AJAX: Build stock table data for modal
     */
    public function ajax_get_product_stock_table() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        if (!$product_id) {
            wp_send_json_error(array('message' => __('Invalid product.', 'bytemash-woo-sync')));
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array('message' => __('Product not found.', 'bytemash-woo-sync')));
        }

        $rows = array();
        $totals = array('stock' => 0, 'reserved' => 0, 'incoming' => 0);

        // Helper to compute incoming stock details
        $compute_incoming = function($incoming) {
            $total = 0;
            $eta_entries = array();
            
            if (is_array($incoming)) {
                foreach ($incoming as $inc) {
                    $qty = isset($inc['total']) ? intval($inc['total']) : 0;
                    $date = isset($inc['date']) ? $inc['date'] : '';
                    
                    if ($qty > 0) {
                        $total += $qty;
                        
                        if ($date) {
                            $formatted_date = date_i18n(get_option('date_format'), strtotime($date));
                            $eta_entries[] = $qty . ' on ' . $formatted_date;
                        } else {
                            $eta_entries[] = $qty . ' (TBC)';
                        }
                    }
                }
            }
            
            // If no incoming stock, return empty string for ETA
            if ($total == 0) {
                return array(0, '');
            }
            
            // Return total and formatted ETA entries
            $eta_text = implode(', ', $eta_entries);
            return array($total, $eta_text);
        };

        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $vid) {
                $v = wc_get_product($vid);
                if (!$v) { continue; }
                $variation_sku = $v->get_sku();
                $detail = get_post_meta($vid, '_amrod_stock_detail', true);
                if (!is_array($detail)) {
                    $detail = array();
                }
                // Only use _amrod_stock_detail if it belongs to THIS variation (API fullCode = variation SKU).
                // Otherwise we might show another variation's stock; API has one row per fullCode with its own stock.
                $detail_belongs_to_this_variation = true;
                if (!empty($detail['fullCode']) && (string) $detail['fullCode'] !== (string) $variation_sku) {
                    $detail_belongs_to_this_variation = false;
                }
                if ($detail_belongs_to_this_variation && isset($detail['stock'])) {
                    $stock = intval($detail['stock']);
                } else {
                    // Use _stock meta only; never fall back to get_stock_quantity() (can return parent stock)
                    $stock_meta = get_post_meta($vid, '_stock', true);
                    $stock = ($stock_meta !== '' && $stock_meta !== null) ? intval($stock_meta) : 0;
                }
                $reserved = ($detail_belongs_to_this_variation && isset($detail['reserved'])) ? intval($detail['reserved']) : (($detail_belongs_to_this_variation && isset($detail['reservedStock'])) ? intval($detail['reservedStock']) : 0);
                $incoming_raw = ($detail_belongs_to_this_variation && isset($detail['incoming'])) ? $detail['incoming'] : (($detail_belongs_to_this_variation && isset($detail['incomingStock'])) ? $detail['incomingStock'] : array());
                list($incoming_total, $eta_text) = $compute_incoming(is_array($incoming_raw) ? $incoming_raw : array());

                $attrs = wc_get_formatted_variation($v, true, false, false);

                $rows[] = array(
                    'label' => $attrs,
                    'sku' => $v->get_sku(),
                    'stock' => $stock,
                    'reserved' => $reserved,
                    'incoming' => $incoming_total,
                    'eta' => $eta_text,
                );

                $totals['stock'] += max(0, $stock);
                $totals['reserved'] += max(0, $reserved);
                $totals['incoming'] += max(0, $incoming_total);
            }
        } else {
            $detail = get_post_meta($product_id, '_amrod_stock_detail', true);
            if (!is_array($detail)) {
                $detail = array();
            }
            $stock_meta_simple = get_post_meta($product_id, '_stock', true);
            $stock = isset($detail['stock']) ? intval($detail['stock']) : (($stock_meta_simple !== '' && $stock_meta_simple !== null) ? intval($stock_meta_simple) : 0);
            $reserved = isset($detail['reserved']) ? intval($detail['reserved']) : (isset($detail['reservedStock']) ? intval($detail['reservedStock']) : 0);
            $incoming_arr = isset($detail['incoming']) ? $detail['incoming'] : (isset($detail['incomingStock']) ? $detail['incomingStock'] : array());
            list($incoming_total, $eta_text) = $compute_incoming(is_array($incoming_arr) ? $incoming_arr : array());

            $rows[] = array(
                'label' => $product->get_name(),
                'sku' => $product->get_sku(),
                'stock' => $stock,
                'reserved' => $reserved,
                'incoming' => $incoming_total,
                'eta' => $eta_text,
            );

            $totals['stock'] = max(0, $stock);
            $totals['reserved'] = max(0, $reserved);
            $totals['incoming'] = max(0, $incoming_total);
        }

        wp_send_json_success(array(
            'rows' => $rows,
            'totals' => $totals,
        ));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create logs table
        global $wpdb;
        $table_name = $wpdb->prefix . 'bytemash_sync_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sync_type varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            message longtext,
            data longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        // CLEANUP: Clear all existing syncs and transients on fresh installation
        $this->cleanup_existing_syncs();
        
        // Set default options
        // Note: API URLs are now fixed in the code (identity.amrod.co.za for auth, vendorapi.amrod.co.za for data)
        add_option('bytemash_amrod_batch_size', 10); // Conservative batch size for products
        add_option('bytemash_amrod_sync_schedule', 'daily');
        
        // Ensure test modes are disabled by default
        add_option('bytemash_cron_full_test_mode_enabled', false);
        add_option('bytemash_cron_incremental_test_mode_enabled', false);
        
        // Force disable test modes on activation (in case they were previously enabled)
        update_option('bytemash_cron_full_test_mode_enabled', false);
        update_option('bytemash_cron_incremental_test_mode_enabled', false);
        
        // DO NOT automatically start syncing - require manual configuration
        // The user must configure API credentials and manually start syncs
        // This prevents immediate syncing on installation
        
        // Initialize true cron manager (but don't schedule anything yet)
        $cron_manager = new ByteMash_True_Cron_Manager();
    }
    
    /**
     * Clean up existing syncs and transients on installation
     */
    private function cleanup_existing_syncs() {
        global $wpdb;
        
        // Clear all sync progress options
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bytemash_sync_progress_%'");
        
        // Clear all sync transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bytemash_sync_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_bytemash_sync_%'");
        
        // Clear Action Scheduler transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bytemash_action_scheduler_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_bytemash_action_scheduler_%'");
        
        // Clear running sync flags
        delete_transient('bytemash_full_sync_running');
        delete_transient('bytemash_incremental_sync_running');
        delete_transient('bytemash_sync_running');
        
        // Clear any scheduled WordPress cron events
        wp_clear_scheduled_hook('bytemash_full_sync_cron');
        wp_clear_scheduled_hook('bytemash_incremental_sync_cron');
        wp_clear_scheduled_hook('bytemash_amrod_sync_cron');
        wp_clear_scheduled_hook('bytemash_process_products_batch');
        wp_clear_scheduled_hook('bytemash_process_products_chunk');
        wp_clear_scheduled_hook('bytemash_process_stock_batch');
        wp_clear_scheduled_hook('bytemash_process_prices_batch');
        wp_clear_scheduled_hook('bytemash_process_categories_batch');
        
        // Clear Action Scheduler actions if available
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('bytemash_action_scheduler_full_sync', array(), 'bytemash-sync');
            as_unschedule_all_actions('bytemash_action_scheduler_incremental_sync', array(), 'bytemash-sync');
            as_unschedule_all_actions('bytemash_action_scheduler_batch_sync', array(), 'bytemash-sync');
            as_unschedule_all_actions('bytemash_action_scheduler_cleanup', array(), 'bytemash-sync');

            // Also clear any actions with these hooks regardless of group (defensive cleanup)
            as_unschedule_all_actions('bytemash_action_scheduler_full_sync');
            as_unschedule_all_actions('bytemash_action_scheduler_incremental_sync');
            as_unschedule_all_actions('bytemash_action_scheduler_batch_sync');
            as_unschedule_all_actions('bytemash_action_scheduler_cleanup');

            // Deep cleanup via Action Scheduler store (older/persisted jobs)
            if (class_exists('ActionScheduler_Store')) {
                try {
                    $store = ActionScheduler_Store::instance();
                    if (method_exists($store, 'query_actions') && method_exists($store, 'cancel_action')) {
                        $action_ids = $store->query_actions(array(
                            'group' => 'bytemash-sync',
                            'status' => array('pending', 'in-progress', 'running', 'failed', 'queued'),
                            'per_page' => 1000,
                        ));
                        if (is_array($action_ids)) {
                            foreach ($action_ids as $action_id) {
                                // Cancel if possible, otherwise delete
                                if (method_exists($store, 'cancel_action')) {
                                    $store->cancel_action($action_id);
                                }
                                if (method_exists($store, 'delete_action')) {
                                    $store->delete_action($action_id);
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Best-effort: ignore errors during cleanup
                }
            }
        }
        
        // Log the cleanup
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Cleaned up all existing syncs and transients on plugin activation', array(), 'plugin_activation');
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear all sync schedules
        $scheduler = new ByteMash_Sync_Scheduler();
        $scheduler->clear_all_schedules();
        
        // Clear true cron manager
        $cron_manager = new ByteMash_True_Cron_Manager();
        $cron_manager->clear_all_schedules();
    }
    
    /**
     * Use external image URL instead of attachment ID
     */
    public function use_external_image_url($image_id, $product) {
        // Ensure $product is an object
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return $image_id;
        }
        
        $external_url = get_post_meta($product->get_id(), '_thumbnail_external_url', true);
        
        if ($external_url) {
            // Return the URL as a fake attachment ID
            // This will be intercepted by replace_with_external_url filter
            return 'external_' . $product->get_id();
        }
        
        return $image_id;
    }
    
    /**
     * Replace attachment image src with external URL
     */
    public function replace_with_external_url($image, $attachment_id, $size, $icon) {
        // Check if this is our fake external featured image
        if (is_string($attachment_id) && strpos($attachment_id, 'external_') === 0) {
            $product_id = str_replace('external_', '', $attachment_id);
            $external_url = get_post_meta($product_id, '_thumbnail_external_url', true);
            
            if ($external_url) {
                return array($external_url, 1024, 1024, false);
            }
        }
        
        // Check if this is our fake gallery image
        if (is_string($attachment_id) && strpos($attachment_id, 'gallery_') === 0) {
            // Extract product_id and index from: gallery_123_0
            $parts = explode('_', $attachment_id);
            if (count($parts) >= 3) {
                $product_id = $parts[1];
                $index = (int) $parts[2];
                
                $gallery_urls = get_post_meta($product_id, '_amrod_gallery_images', true);
                
                if ($gallery_urls && is_array($gallery_urls) && isset($gallery_urls[$index])) {
                    return array($gallery_urls[$index], 1024, 1024, false);
                }
            }
        }
        
        return $image;
    }
    
    /**
     * Use external URLs for gallery images
     */
    public function use_external_gallery_urls($gallery_ids, $product) {
        // Ensure $product is an object
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return $gallery_ids;
        }
        
        // If product has external gallery images, return fake IDs
        $gallery_urls = get_post_meta($product->get_id(), '_amrod_gallery_images', true);
        
        if ($gallery_urls && is_array($gallery_urls)) {
            // Return fake IDs that will be replaced by URLs
            $fake_ids = array();
            foreach ($gallery_urls as $index => $url) {
                $fake_ids[] = 'gallery_' . $product->get_id() . '_' . $index;
            }
            return $fake_ids;
        }
        
        return $gallery_ids;
    }

	/**
	 * Build consistent <img> HTML for archive/shop thumbnails using external URL if set
	 */
	public function archive_product_thumbnail_html($html, $size, $attr) {
		global $product;
		if (!$product || !is_a($product, 'WC_Product')) {
			return $html;
		}
		$external_url = get_post_meta($product->get_id(), '_thumbnail_external_url', true);
		if (empty($external_url)) {
			return $html;
		}
		$classes = 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail';
		$alt = esc_attr(get_the_title($product->get_id()));
		$src = esc_url($external_url);
		return '<img class="' . $classes . '" src="' . $src . '" alt="' . $alt . '" loading="lazy" />';
	}

	/**
	 * Fallback for themes using the_post_thumbnail() directly in product loops
	 */
	public function archive_post_thumbnail_html($html, $post_id, $post_thumbnail_id, $size, $attr) {
		// Only affect product archives/shop contexts
		if (!(is_shop() || is_product_taxonomy() || is_post_type_archive('product'))) {
			return $html;
		}
		$product = function_exists('wc_get_product') ? wc_get_product($post_id) : null;
		if (!$product) {
			return $html;
		}
		$external_url = get_post_meta($post_id, '_thumbnail_external_url', true);
		if (empty($external_url)) {
			return $html;
		}
		$classes = 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail';
		$alt = esc_attr(get_the_title($post_id));
		$src = esc_url($external_url);
		return '<img class="' . $classes . '" src="' . $src . '" alt="' . $alt . '" loading="lazy" />';
	}
    
    /**
     * AJAX: Authenticate with Amrod
     */
    public function ajax_authenticate() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Get credentials
        $username = isset($_POST['username']) ? sanitize_text_field($_POST['username']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : ''; // Don't sanitize password
        $customer_code = isset($_POST['customer_code']) ? sanitize_text_field($_POST['customer_code']) : '';
        
        if (empty($username) || empty($password)) {
            wp_send_json_error(array('message' => __('Username and password are required', 'bytemash-woo-sync')));
        }
        
        // Attempt authentication
        $api_client = new ByteMash_Amrod_API_Client();
        $result = $api_client->authenticate($username, $password, $customer_code);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
        }
        
        wp_send_json_success(array(
            'message' => __('Authentication successful! Credentials stored for automatic token refresh.', 'bytemash-woo-sync')
        ));
    }
    
    /**
     * AJAX: Save API URL
     */
    public function ajax_save_api_url() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Get API URL
        $api_url = isset($_POST['api_url']) ? esc_url_raw($_POST['api_url']) : '';
        
        if (empty($api_url)) {
            wp_send_json_error(array('message' => __('API URL is required', 'bytemash-woo-sync')));
        }
        
        // Save API URL
        update_option('bytemash_amrod_api_url', $api_url);
        
        wp_send_json_success(array(
            'message' => __('API URL saved', 'bytemash-woo-sync')
        ));
    }
    
    /**
     * AJAX: Test API connection
     */
    public function ajax_test_connection() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Test connection
        $api_client = new ByteMash_Amrod_API_Client();
        $is_connected = $api_client->test_connection();
        
        if ($is_connected) {
            wp_send_json_success(array(
                'message' => __('Connection successful!', 'bytemash-woo-sync')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Connection failed. Please check your credentials.', 'bytemash-woo-sync')
            ));
        }
    }
    
    /**
     * AJAX: Clear logs
     */
    public function ajax_clear_logs() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Clear logs from database
        global $wpdb;
        $table_name = $wpdb->prefix . 'bytemash_sync_logs';
        $wpdb->query("TRUNCATE TABLE $table_name");
        
        // Clear log files
        $log_dir = BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'logs/';
        if (is_dir($log_dir)) {
            $files = glob($log_dir . '*.log');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        
        wp_send_json_success(array(
            'message' => __('Logs cleared successfully', 'bytemash-woo-sync')
        ));
    }
    
    /**
     * AJAX: Cleanup zero prices (YITH compatibility)
     */
    public function ajax_cleanup_zero_prices() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        global $wpdb;
        
        // Count before cleanup
        $count_before = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->postmeta}
            WHERE meta_key IN ('_price', '_regular_price')
            AND meta_value = '0'
        ");
        
        // Get affected products for logging
        $affected_products = $wpdb->get_col("
            SELECT DISTINCT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key IN ('_price', '_regular_price')
            AND meta_value = '0'
        ");
        
        $product_count = count($affected_products);
        
        if ($product_count === 0) {
            wp_send_json_success(array(
                'message' => __('✅ No fake zero prices found. Your products are clean!', 'bytemash-woo-sync'),
                'count' => 0,
                'products' => 0
            ));
            return;
        }
        
        // Delete the meta entries
        $deleted = $wpdb->query("
            DELETE FROM {$wpdb->postmeta}
            WHERE meta_key IN ('_price', '_regular_price')
            AND meta_value = '0'
        ");
        
        if ($deleted === false) {
            wp_send_json_error(array(
                'message' => __('❌ Database error occurred during cleanup.', 'bytemash-woo-sync')
            ));
            return;
        }
        
        // Clear WooCommerce transients for affected products
        foreach ($affected_products as $product_id) {
            delete_transient('wc_product_children_' . $product_id);
            delete_transient('wc_var_prices_' . $product_id);
            wc_delete_product_transients($product_id);
        }
        
        // Clear WooCommerce cache
        if (function_exists('wc_delete_shop_order_transients')) {
            wc_delete_shop_order_transients();
        }
        
        wp_send_json_success(array(
            'message' => sprintf(
                __('✅ Successfully removed %d fake price entries from %d products. Clear your cache and check YITH now!', 'bytemash-woo-sync'),
                $deleted,
                $product_count
            ),
            'count' => $deleted,
            'products' => $product_count
        ));
    }
    
    /**
     * AJAX: Cleanup duplicate stock & price meta (keeps one row per post_id + meta_key, most recent)
     */
    public function ajax_cleanup_duplicate_meta() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        global $wpdb;
        $meta_keys = array(
            '_stock', '_stock_status', '_manage_stock', '_backorders',
            '_amrod_stock_detail',
            '_bytemash_stock_hash', '_bytemash_stock_last_modified',
            '_regular_price', '_sale_price', '_price',
            '_bytemash_price_hash', '_bytemash_price_last_modified',
        );
        $keys_escaped = array_map(function ($k) use ($wpdb) { return "'" . esc_sql($k) . "'"; }, $meta_keys);
        $keys_in = implode(',', $keys_escaped);
        // MySQL doesn't reliably allow DELETE from a table while subquery selects from same table.
        // Step 1: find meta_id of every duplicate row (keep only MAX(meta_id) per post_id, meta_key).
        $ids_to_delete = $wpdb->get_col("
            SELECT pm.meta_id FROM {$wpdb->postmeta} pm
            INNER JOIN (
                SELECT post_id, meta_key, MAX(meta_id) AS keep_id
                FROM {$wpdb->postmeta}
                WHERE meta_key IN ({$keys_in})
                GROUP BY post_id, meta_key
                HAVING COUNT(*) > 1
            ) dup ON pm.post_id = dup.post_id AND pm.meta_key = dup.meta_key AND pm.meta_id <> dup.keep_id
        ");
        if (!is_array($ids_to_delete)) {
            wp_send_json_error(array('message' => __('Database error during cleanup.', 'bytemash-woo-sync')));
        }
        $deleted = 0;
        if (!empty($ids_to_delete)) {
            $ids_to_delete = array_map('intval', $ids_to_delete);
            $chunk_size = 5000;
            foreach (array_chunk($ids_to_delete, $chunk_size) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
                $result = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ({$placeholders})",
                    $chunk
                ));
                if ($result !== false) {
                    $deleted += (int) $result;
                }
            }
        }
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Duplicate stock/price meta cleaned', array('deleted_rows' => $deleted, 'user' => get_current_user_id()), 'admin_tools');
        wp_send_json_success(array(
            'message' => sprintf(
                __('✅ Cleanup complete. Removed %d duplicate meta rows. Stock and price values now have one row per product.', 'bytemash-woo-sync'),
                (int) $deleted
            ),
            'deleted' => (int) $deleted,
        ));
    }
    
    /**
     * AJAX: Get sync progress
     */
    public function ajax_get_sync_progress() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Get active syncs from batch processor
        $batch_processor = new ByteMash_Batch_Processor();
        $syncs = $batch_processor->get_active_syncs();
        
        // Add real-time details for each sync
        foreach ($syncs as $sync_id => &$sync) {
            // Calculate percentage
            if ($sync['total'] > 0) {
                $sync['percentage'] = round(($sync['processed'] / $sync['total']) * 100, 1);
            } else {
                $sync['percentage'] = 0;
            }
            
            // Add chunk/batch info
            if (isset($sync['chunk_count'])) {
                $sync['progress_text'] = sprintf(
                    'Chunk %d/%d - %d/%d products',
                    $sync['current_chunk'],
                    $sync['chunk_count'],
                    $sync['processed'],
                    $sync['total']
                );
            } elseif (isset($sync['batch_count'])) {
                $sync['progress_text'] = sprintf(
                    'Batch %d/%d - %d/%d items',
                    $sync['current_batch'],
                    $sync['batch_count'],
                    $sync['processed'],
                    $sync['total']
                );
            } else {
                $sync['progress_text'] = sprintf(
                    '%d/%d processed',
                    $sync['processed'],
                    $sync['total']
                );
            }
            
            // Time elapsed
            if (isset($sync['started'])) {
                $started = strtotime($sync['started']);
                $elapsed = time() - $started;
                $sync['elapsed'] = $elapsed;
                $sync['elapsed_text'] = sprintf('%dm %ds', floor($elapsed / 60), $elapsed % 60);
            }
            
            // Estimated time remaining
            if ($sync['processed'] > 0 && isset($elapsed)) {
                $rate = $sync['processed'] / $elapsed; // products per second
                $remaining = $sync['total'] - $sync['processed'];
                $eta = $remaining / $rate;
                $sync['eta'] = round($eta);
                $sync['eta_text'] = sprintf('%dm %ds', floor($eta / 60), $eta % 60);
            }
        }
        
        wp_send_json_success(array(
            'syncs' => $syncs,
            'timestamp' => current_time('mysql')
        ));
    }
    
    /**
     * AJAX: Stop active sync
     */
    public function ajax_stop_sync() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Get sync ID if provided
        $sync_id = isset($_POST['sync_id']) ? sanitize_text_field($_POST['sync_id']) : '';
        
        $batch_processor = new ByteMash_Batch_Processor();
        $logger = new ByteMash_Logger();
        
        if (!empty($sync_id)) {
            // Stop specific sync
            $sync_info = get_option("bytemash_sync_{$sync_id}");
            
            if ($sync_info) {
                $sync_info['status'] = 'stopped';
                $sync_info['message'] = 'Sync stopped by user';
                $sync_info['completed'] = current_time('mysql');
                
                update_option("bytemash_sync_{$sync_id}", $sync_info, false);
                
                // Clear sync running transient
                delete_transient('bytemash_sync_running');
                
                  // Clean up queue table
                global $wpdb;
                $table_name = $wpdb->prefix . 'bytemash_sync_queue';
                $wpdb->delete($table_name, array('sync_id' => $sync_id));
                
                $logger->log('warning', 'Sync stopped by user', array(
                    'sync_id' => $sync_id,
                    'processed' => $sync_info['processed'] ?? 0,
                    'total' => $sync_info['total'] ?? 0,
                ), 'manual_sync');
                
                wp_send_json_success(array(
                    'message' => sprintf(__('Sync stopped. %d/%d products were synced.', 'bytemash-woo-sync'), 
                        $sync_info['processed'] ?? 0, 
                        $sync_info['total'] ?? 0
                    )
                ));
            } else {
                wp_send_json_error(array('message' => __('Sync not found', 'bytemash-woo-sync')));
            }
        } else {
            // Stop all active syncs
            $syncs = $batch_processor->get_active_syncs();
            $stopped = 0;
            
            foreach ($syncs as $sync_id => $sync) {
                if (in_array($sync['status'], ['scheduled', 'processing'])) {
                    $sync['status'] = 'cancelled';
                    $sync['message'] = 'Sync cancelled by user';
                    $sync['completed'] = current_time('mysql');
                    
                    update_option("bytemash_sync_progress_{$sync_id}", $sync, false);
                    
                    // Clear scheduled events
                    wp_clear_scheduled_hook('bytemash_process_products_chunk', array($sync_id, $sync['current_chunk'] ?? 0));
                    wp_clear_scheduled_hook('bytemash_process_products_batch', array($sync_id, $sync['current_batch'] ?? 0));
                    
                    // Clean up transients
                    if (isset($sync['chunk_count'])) {
                        for ($i = $sync['current_chunk']; $i < $sync['chunk_count']; $i++) {
                            delete_transient("bytemash_sync_{$sync_id}_chunk_{$i}");
                        }
                    }
                    delete_transient("bytemash_sync_{$sync_id}_meta");
                    
                    $stopped++;
                }
            }
            
            $logger->log('warning', 'All syncs cancelled by user', array('stopped' => $stopped), 'manual_sync');
            
            wp_send_json_success(array(
                'message' => sprintf(_n('%d sync stopped.', '%d syncs stopped.', $stopped, 'bytemash-woo-sync'), $stopped)
            ));
        }
    }
    
    /**
     * AJAX: Manual sync (trigger product sync)
     */
    public function ajax_manual_sync() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Log the manual sync trigger
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Manual sync triggered', array('user' => get_current_user_id()), 'manual_sync');
        
        // Clear any stale sync status
        delete_transient('bytemash_sync_running');
        
        // Trigger product sync
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_all_products(false, true); // force=false, with_branding=true
        
        if ($result['success']) {
            // Store batches in queue table
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            $logger->log('info', 'Batches stored in queue table', array(
                'sync_id' => $result['sync_id'],
                'batch_count' => $result['batch_count'],
            ), 'manual_sync');
            
            // Return minimal response
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message']
            ));
        }
    }
    
    /**
     * AJAX: Sync stock levels
     */
    public function ajax_sync_stock() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Log the sync trigger
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Stock sync triggered', array('user' => get_current_user_id()), 'stock_sync');
        
        // Clear any stale sync status
        delete_transient('bytemash_sync_running');
        
        $sync_id = 'stock_' . time() . '_' . wp_generate_password(8, false);
        
        // Use optimized stock sync if available
        if (class_exists('ByteMash_Stock_Sync_Optimized')) {
            $optimized_sync = new ByteMash_Stock_Sync_Optimized();
            $result = $optimized_sync->sync_all_stock($sync_id);
            
            if ($result['success']) {
                $stats = $result['stats'];
                wp_send_json_success(array(
                    'message' => __('Stock sync initialized (streaming mode)', 'bytemash-woo-sync'),
                    'sync_id' => $sync_id,
                    'total' => $stats['total_items'],
                    'batch_count' => $stats['batch_count']
                ));
            } else {
                wp_send_json_error(array(
                    'message' => isset($result['error']) ? $result['error'] : __('Failed to initialize optimized stock sync', 'bytemash-woo-sync')
                ));
            }
        } else {
            // Fallback to legacy stock sync
            $product_sync = new ByteMash_Product_Sync();
            $result = $product_sync->sync_stock_levels();
            
            if ($result['success']) {
                // Store batches in queue table
                $this->store_batches_in_queue($result['sync_id'], $result['batches']);
                
                wp_send_json_success(array(
                    'message' => $result['message'],
                    'sync_id' => $result['sync_id'],
                    'total' => $result['total'],
                    'batch_count' => $result['batch_count']
                ));
            } else {
                wp_send_json_error(array(
                    'message' => $result['message']
                ));
            }
        }
    }
    
    /**
     * AJAX: Sync prices
     */
    public function ajax_sync_prices() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Log the sync trigger
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Price sync triggered', array('user' => get_current_user_id()), 'price_sync');
        
        // Clear any stale sync status
        delete_transient('bytemash_sync_running');
        
        // Trigger price sync
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_prices();
        
        if ($result['success']) {
            // Optimized price sync processes synchronously and returns stats (no batches)
            if (isset($result['stats'])) {
                $stats = $result['stats'];
                $total = isset($stats['total_items']) ? (int) $stats['total_items'] : 0;
                $updated = isset($stats['updated']) ? (int) $stats['updated'] : 0;
                $skipped = isset($stats['skipped']) ? (int) $stats['skipped'] : 0;
                if ($updated > 0) {
                    $message = sprintf(
                        /* translators: 1: number of products updated, 2: total processed */
                        __('Price sync completed: %1$d products updated out of %2$d processed', 'bytemash-woo-sync'),
                        $updated,
                        $total
                    );
                } else {
                    $message = sprintf(
                        /* translators: 1: total processed, 2: skipped count */
                        __('Price sync completed: 0 products updated out of %1$d processed. All items were skipped (no price change detected or no matching product; %2$d skipped).', 'bytemash-woo-sync'),
                        $total,
                        $skipped
                    );
                }
                wp_send_json_success(array(
                    'message' => $message,
                    'total' => $total,
                    'total_updated' => $updated,
                    'skipped' => $skipped,
                ));
                return;
            }
            // Legacy batch-based sync
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            $error_message = isset($result['error']) ? $result['error'] : (isset($result['message']) ? $result['message'] : __('Price sync failed', 'bytemash-woo-sync'));
            wp_send_json_error(array('message' => $error_message));
        }
    }
    
    /**
     * AJAX: Sync updated products (incremental)
     */
    public function ajax_sync_products_incremental() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Incremental product sync triggered', array('user' => get_current_user_id()), 'product_sync');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_updated_products(true);
        
        if ($result['success'] && isset($result['batches'])) {
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }

    /**
     * AJAX: Delete WooCommerce products that no longer exist in the Amrod API.
     */
    public function ajax_delete_excess_products() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }

        $with_branding = !empty($_POST['with_branding']);
        $context = isset($_POST['context'])
            ? sanitize_key(wp_unslash($_POST['context']))
            : 'manual_cleanup';

        $logger = new ByteMash_Logger();
        $logger->log('info', 'Delete excess products requested', array(
            'with_branding' => $with_branding,
            'context' => $context,
            'user' => get_current_user_id(),
        ), 'product_cleanup');

        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->delete_excess_products(array(
            'with_branding' => $with_branding,
            'context' => $context,
            'track_progress' => true, // Enable progress tracking
        ));

        if (!empty($result['success'])) {
            wp_send_json_success(array(
                'message' => $result['message'],
                'checked' => $result['checked'],
                'deleted' => $result['deleted'],
                'sync_id' => $result['sync_id'] ?? '',
            ));
        }

        wp_send_json_error(array(
            'message' => $result['message'] ?? __('Cleanup failed. Please try again.', 'bytemash-woo-sync'),
        ));
    }
    
    /**
     * AJAX: Process cleanup batch (for progress tracking)
     */
    public function ajax_process_cleanup_batch() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }

        $sync_id = isset($_POST['sync_id']) ? sanitize_text_field($_POST['sync_id']) : '';
        $batch_size = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 50;

        if (empty($sync_id)) {
            wp_send_json_error(array('message' => __('Sync ID required', 'bytemash-woo-sync')));
        }

        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->process_cleanup_batch($sync_id, $batch_size);

        if (!empty($result['success'])) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Sync stock incremental
     */
    public function ajax_sync_stock_incremental() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Incremental stock sync triggered', array('user' => get_current_user_id()), 'stock_sync');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_stock_updated();
        
        if ($result['success'] && isset($result['batches'])) {
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Sync prices incremental
     */
    public function ajax_sync_prices_incremental() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Incremental price sync triggered', array('user' => get_current_user_id()), 'price_sync');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_prices_updated();
        
        if ($result['success']) {
            // Optimized price sync processes synchronously and returns stats (no batches)
            if (isset($result['stats'])) {
                $stats = $result['stats'];
                $total = isset($stats['total_items']) ? (int) $stats['total_items'] : 0;
                $updated = isset($stats['updated']) ? (int) $stats['updated'] : 0;
                $skipped = isset($stats['skipped']) ? (int) $stats['skipped'] : 0;
                if ($updated > 0) {
                    $message = isset($result['message']) ? $result['message'] : sprintf(
                        /* translators: 1: number of products updated, 2: total processed */
                        __('Price sync completed: %1$d products updated out of %2$d processed', 'bytemash-woo-sync'),
                        $updated,
                        $total
                    );
                } else {
                    $message = sprintf(
                        /* translators: 1: total processed, 2: skipped count */
                        __('Price sync completed: 0 products updated out of %1$d processed. All items were skipped (no price change detected or no matching product; %2$d skipped).', 'bytemash-woo-sync'),
                        $total,
                        $skipped
                    );
                }
                wp_send_json_success(array(
                    'message' => $message,
                    'total' => $total,
                    'total_updated' => $updated,
                    'skipped' => $skipped,
                ));
                return;
            }
            // Legacy batch-based sync
            if (isset($result['batches'])) {
                $this->store_batches_in_queue($result['sync_id'], $result['batches']);
                wp_send_json_success(array(
                    'message' => $result['message'],
                    'sync_id' => $result['sync_id'],
                    'total' => $result['total'],
                    'batch_count' => $result['batch_count']
                ));
                return;
            }
            wp_send_json_success(array('message' => isset($result['message']) ? $result['message'] : __('Sync completed', 'bytemash-woo-sync'), 'total' => 0));
        } else {
            $error_message = isset($result['error']) ? $result['error'] : (isset($result['message']) ? $result['message'] : __('Incremental price sync failed', 'bytemash-woo-sync'));
            wp_send_json_error(array('message' => $error_message));
        }
    }
    
    /**
     * AJAX: Sync orphan prices (products without prices)
     */
    public function ajax_sync_orphan_prices() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Orphan price sync triggered', array('user' => get_current_user_id()), 'price_sync_orphan');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_orphan_product_prices();
        
        if ($result['success'] && isset($result['batches'])) {
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Sync categories
     */
    public function ajax_sync_categories() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Categories sync triggered', array('user' => get_current_user_id()), 'category_sync');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_categories();
        
        if ($result['success'] && isset($result['batches'])) {
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Sync color swatches
     */
    public function ajax_sync_color_swatches() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Color swatches sync triggered', array('user' => get_current_user_id()), 'color_swatches_sync');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_color_swatches();
        
        if ($result['success'] && isset($result['batches'])) {
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Sync brands
     */
    public function ajax_sync_brands() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Brands sync triggered', array('user' => get_current_user_id()), 'brands_sync');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_brands();
        
        if (!$result['success'] || empty($result['data'])) {
            wp_send_json_error($result);
            return;
        }
        
        // Process all batches immediately
        $batch_processor = new ByteMash_Batch_Processor();
        $process_result = $batch_processor->process_brands_sync_immediately($result['data'], $result['sync_id']);
        
        if ($process_result['success']) {
            wp_send_json_success(array(
                'message' => "Brands sync completed: {$process_result['processed']} processed, {$process_result['errors']} errors",
                'sync_id' => $result['sync_id'],
                'processed' => $process_result['processed'],
                'errors' => $process_result['errors'],
                'total' => $process_result['total'],
            ));
        } else {
            wp_send_json_error($process_result);
        }
    }
    
    /**
     * AJAX: Sync branding departments
     */
    public function ajax_sync_branding_departments() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Branding departments sync triggered', array('user' => get_current_user_id()), 'branding_sync');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_branding_departments();
        
        if ($result['success'] && isset($result['batches'])) {
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Sync branding prices
     */
    public function ajax_sync_branding_prices() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Branding prices sync triggered', array('user' => get_current_user_id()), 'branding_sync');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_branding_prices();
        
        if ($result['success'] && isset($result['batches'])) {
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Sync inclusive brandings
     */
    public function ajax_sync_inclusive_brandings() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Inclusive brandings sync triggered', array('user' => get_current_user_id()), 'branding_sync');
        
        delete_transient('bytemash_sync_running');
        
        $product_sync = new ByteMash_Product_Sync();
        $result = $product_sync->sync_inclusive_brandings();
        
        if ($result['success'] && isset($result['batches'])) {
            $this->store_batches_in_queue($result['sync_id'], $result['batches']);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        } else {
            wp_send_json_success(array('message' => $result['message']));
        }
    }
    
    /**
     * Helper: Store batches in queue table
     */
    private function store_batches_in_queue($sync_id, $batches) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bytemash_sync_queue';
        
        // Ensure we have enough memory for this operation
        $current_limit = ini_get('memory_limit');
        $current_bytes = wp_convert_hr_to_bytes($current_limit);
        $desired_bytes = 512 * 1024 * 1024; // 512MB
        
        if ($current_bytes < $desired_bytes) {
            @ini_set('memory_limit', '512M');
        }
        
        // Create table if not exists
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            sync_id VARCHAR(100),
            batch_index INT,
            batch_data LONGTEXT,
            status VARCHAR(20) DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX(sync_id),
            INDEX(status)
        ) {$wpdb->get_charset_collate()}");
        
        // Insert batches in chunks to avoid max_allowed_packet issues
    $chunks = array_chunk($batches, 50, true);
    
    foreach ($chunks as $chunk) {
        $values = array();
        $placeholders = array();
        
        foreach ($chunk as $index => $batch) {
            $values[] = $sync_id;
            $values[] = $index;
            $values[] = json_encode($batch);
            $values[] = 'pending';
            $placeholders[] = "('%s', '%d', '%s', '%s')";
        }
        
        $query = "INSERT INTO $table_name (sync_id, batch_index, batch_data, status) VALUES " . implode(', ', $placeholders);
        $wpdb->query($wpdb->prepare($query, $values));
        
        // Free memory after each chunk
        wp_cache_flush();
        gc_collect_cycles();
    }
    }
    
    /**
     * AJAX: Process next pending batch from queue
     */
    public function ajax_process_batch() {
        // Suppress any accidental output (warnings, notices, etc.) to prevent JSON corruption
        ob_start();
        
        try {
            // Check nonce
            check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
            
            // Check permissions
            if (!current_user_can('manage_woocommerce')) {
                if (ob_get_length()) ob_end_clean();
                wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
            }
            
            // Get parameters
            $sync_id = isset($_POST['sync_id']) ? sanitize_text_field($_POST['sync_id']) : '';
            
            $log_file = WP_CONTENT_DIR . '/uploads/bytemash_stock_debug_batches.log';
            @error_log("[" . date('Y-m-d H:i:s') . "] AJAX CALL | Sync: $sync_id\n", 3, $log_file);

            if (empty($sync_id)) {
                if (ob_get_length()) ob_end_clean();
                wp_send_json_error(array('message' => __('Sync ID required', 'bytemash-woo-sync')));
            }
        
        // Get sync info
        $sync_info = get_option("bytemash_sync_{$sync_id}");
        
        if (!$sync_info) {
            wp_send_json_error(array('message' => __('Sync not found', 'bytemash-woo-sync')));
        }
        
        // Check if stopped
        if ($sync_info['status'] === 'stopped') {
            wp_send_json_success(array(
                'message' => 'Sync stopped',
                'stopped' => true
            ));
            return;
        }
        
        // Get next pending batch from queue
        global $wpdb;
        $table_name = $wpdb->prefix . 'bytemash_sync_queue';
        
        // Ensure updated_at column exists (for timeout detection)
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'updated_at'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
        
        // First, reset any stuck "processing" batches (older than 5 minutes)
        // This handles cases where a batch was marked as processing but the request timed out
        $timeout_seconds = 300; // 5 minutes
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name} 
             SET status = 'pending', updated_at = NOW() 
             WHERE sync_id = %s 
             AND status = 'processing' 
             AND (updated_at < DATE_SUB(NOW(), INTERVAL %d SECOND) OR updated_at IS NULL)",
            $sync_id,
            $timeout_seconds
        ));
        
        $batch_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE sync_id = %s AND status = 'pending' ORDER BY batch_index ASC LIMIT 1",
            $sync_id
        ));
        
        if (!$batch_row) {
            // No more batches - mark sync as complete
            $sync_info['status'] = 'completed';
            $sync_info['completed'] = current_time('mysql');
            update_option("bytemash_sync_{$sync_id}", $sync_info, false);
            delete_transient('bytemash_sync_running');
            
            // Clean up queue table
            $wpdb->delete($table_name, array('sync_id' => $sync_id));
            
            // After stock sync completes, sync WooCommerce product lookup tables so stock displays correctly
            $sync_type = $sync_info['type'] ?? '';
            if ($sync_type === 'stock' && function_exists('wc_update_product_lookup_tables')) {
                wc_update_product_lookup_tables();
                $logger = new ByteMash_Logger();
                $logger->log('info', 'Stock sync complete: WooCommerce product lookup tables updated', array('sync_id' => $sync_id), 'stock_sync');
            }
            
            wp_send_json_success(array(
                'done' => true,
                'message' => 'All batches completed'
            ));
            return;
        }
        
        // Decode batch data
        $batch_data = json_decode($batch_row->batch_data, true);
        $batch_index = $batch_row->batch_index;
        
        // Mark batch as processing (with timestamp for timeout detection)
        $wpdb->update($table_name, 
            array(
                'status' => 'processing',
                'updated_at' => current_time('mysql')
            ),
            array('id' => $batch_row->id)
        );
        
        // Process this batch based on sync type
        $product_sync = new ByteMash_Product_Sync();
        $processed = 0;
        $errors = 0;
        $skipped = 0;
        $last_changed_fields = array();
        $last_processing_reason = '';
        $last_skip_reason = '';
        
        $sync_type = $sync_info['type'] ?? 'products';
    
    if ($sync_type === 'stock') {
        // Give stock batch more time (avoids timeout returning HTML instead of JSON)
        @set_time_limit(120);
        // Use optimized stock sync for maximum performance
        if (class_exists('ByteMash_Stock_Sync_Optimized')) {
            try {
                $optimized_sync = new ByteMash_Stock_Sync_Optimized();
                $batch_stats = $optimized_sync->process_stock_batch($sync_id, $batch_index, $batch_data);
                
                $processed = isset($batch_stats['processed']) ? $batch_stats['processed'] : 0;
                $errors = isset($batch_stats['errors']) ? $batch_stats['errors'] : 0;
                $skipped = isset($batch_stats['skipped']) ? $batch_stats['skipped'] : 0;
                $updated = isset($batch_stats['updated']) ? $batch_stats['updated'] : $processed;
            } catch (Throwable $e) {
                $errors++;
                $logger = new ByteMash_Logger();
                $logger->log('error', 'Optimized stock sync failed (Throwable)', array(
                    'error' => $e->getMessage(),
                    'batch_index' => $batch_index
                ), 'stock_sync');
            }
        } else {
            // Fallback to legacy method if class missing
            foreach ($batch_data as $item_data) {
                $result = $product_sync->update_single_stock($item_data);
                if (!empty($result['success'])) {
                    $processed++;
                } else {
                    $errors++;
                }
            }
        }
    } else {
        // Process other sync types normally
        foreach ($batch_data as $item_data) {
            if ($sync_type === 'prices') {
                $result = $product_sync->update_single_price($item_data);
            } elseif ($sync_type === 'orphan_prices') {
                    $prices_lookup = get_option("bytemash_sync_{$sync_id}_prices_lookup");
                    $result = $product_sync->update_single_orphan_product($item_data, $prices_lookup);
                } elseif ($sync_type === 'categories') {
                    $result = $product_sync->sync_single_category($item_data);
                } elseif ($sync_type === 'brands') {
                    $result = $product_sync->sync_single_brand($item_data);
                } elseif ($sync_type === 'branding_departments') {
                    $result = $product_sync->sync_single_branding_department($item_data);
                } elseif ($sync_type === 'branding_prices') {
                    $result = $product_sync->sync_single_branding_price($item_data);
                } elseif ($sync_type === 'inclusive_brandings') {
                    $result = $product_sync->sync_single_inclusive_branding($item_data);
                } elseif ($sync_type === 'color_swatches') {
                    $result = $product_sync->sync_single_color_swatch($item_data);
                } else {
                // Default: product sync
                    $result = $product_sync->sync_single_product($item_data);
                }
                
                if (!empty($result['skipped'])) {
                    $skipped++;
                    $last_skip_reason = $result['skip_reason'] ?? 'unknown';
                } elseif (!empty($result['success'])) {
                    $processed++;
                    if (!empty($result['changed_fields']) && is_array($result['changed_fields'])) {
                        $last_changed_fields = $result['changed_fields'];
                    }
                    if (!empty($result['processing_reason'])) {
                        $last_processing_reason = $result['processing_reason'];
                    }
                } else {
                    $errors++;
                }
            }
        }
        
        // Mark batch as complete
        $wpdb->update($table_name,
            array(
                'status' => 'completed',
                'updated_at' => current_time('mysql')
            ),
            array('id' => $batch_row->id)
        );
        
        // Update sync info
        $sync_info['current_batch'] = $batch_index + 1;
        $sync_info['processed'] += $processed;
        $sync_info['errors'] += $errors;
        if (isset($sync_info['skipped'])) {
            $sync_info['skipped'] += $skipped;
        } else {
            $sync_info['skipped'] = $skipped;
        }
        $sync_info['status'] = 'processing';
        
        update_option("bytemash_sync_{$sync_id}", $sync_info, false);
        
        // Get current WooCommerce product counts for dashboard update
        $product_counts = wp_count_posts('product');
        
        // Get list of all completed batch indices for frontend gap detection
        $completed_batches = $wpdb->get_col($wpdb->prepare(
            "SELECT batch_index FROM {$table_name} 
             WHERE sync_id = %s AND status = 'completed' 
             ORDER BY batch_index ASC",
            $sync_id
        ));
        
            // Clear buffer and send JSON
            ob_end_clean();
            wp_send_json_success(array(
                'batch' => $batch_index,
                'processed' => $processed,
                'errors' => $errors,
                'skipped' => $skipped,
                'total_processed' => $sync_info['processed'],
                'total_errors' => $sync_info['errors'],
                'total_skipped' => $sync_info['skipped'],
                'total_products' => $sync_info['total'],
                'woo_product_count' => $product_counts->publish,
                'done' => false,
                'last_changed_fields' => $last_changed_fields,
                'last_processing_reason' => $last_processing_reason,
                'last_skip_reason' => $last_skip_reason,
                'completed_batches' => array_map('intval', $completed_batches), // All completed batch indices
            ));
        } catch (Throwable $t) {
            $err_msg = $t->getMessage();
            $logger = new ByteMash_Logger();
            $logger->log('error', 'Critical error in ajax_process_batch', array('error' => $err_msg), 'sync_engine');
            
            if (ob_get_length()) {
                ob_end_clean();
            }
            
            wp_send_json_error(array(
                'message' => 'Critical sync error: ' . $err_msg,
                'details' => $t->getTraceAsString()
            ));
        }
    }
    
    /**
     * AJAX: Get a single batch from transient
     */
    public function ajax_get_batch() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $sync_id = isset($_POST['sync_id']) ? sanitize_text_field($_POST['sync_id']) : '';
        $batch_index = isset($_POST['batch_index']) ? (int) $_POST['batch_index'] : 0;
        
        if (empty($sync_id)) {
            wp_send_json_error(array('message' => __('Sync ID required', 'bytemash-woo-sync')));
        }
        
        // Get batches from transient
        $batches = get_transient("bytemash_sync_{$sync_id}_batches");
        
        if (!$batches || !isset($batches[$batch_index])) {
            wp_send_json_error(array('message' => __('Batch not found', 'bytemash-woo-sync')));
        }
        
        wp_send_json_success(array(
            'batch' => $batches[$batch_index]
        ));
    }
    
    /**
     * AJAX: Toggle test mode
     */
    public function ajax_toggle_test_mode() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $test_mode = get_option('bytemash_cron_test_mode_enabled', false);
        $new_test_mode = !$test_mode;
        
        if ($new_test_mode) {
            $this->enable_test_mode();
        } else {
            $this->disable_test_mode();
        }
        
        wp_send_json_success(array(
            'test_mode' => $new_test_mode,
            'message' => $new_test_mode ? __('Test mode enabled', 'bytemash-woo-sync') : __('Test mode disabled', 'bytemash-woo-sync'),
        ));
    }
    
    /**
     * AJAX: Toggle full sync test mode
     */
    public function ajax_toggle_full_test_mode() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $test_mode = get_option('bytemash_cron_full_test_mode_enabled', false);
        
        // Ensure the option exists and is boolean
        if (!get_option('bytemash_cron_full_test_mode_enabled')) {
            update_option('bytemash_cron_full_test_mode_enabled', false);
        }
        
        // Force check - if option is not explicitly false, set it to false
        $current_value = get_option('bytemash_cron_full_test_mode_enabled', false);
        if ($current_value !== false) {
            update_option('bytemash_cron_full_test_mode_enabled', false);
        }
        $new_test_mode = !$test_mode;
        
        if ($new_test_mode) {
            $cron_result = $this->enable_full_test_mode();
            $message = __('Full sync test mode enabled', 'bytemash-woo-sync');
            
            if (!$cron_result['success']) {
                $message .= '. ' . $cron_result['message'];
            }
        } else {
            $this->disable_full_test_mode();
            $message = __('Full sync test mode disabled', 'bytemash-woo-sync');
        }
        
        wp_send_json_success(array(
            'test_mode' => $new_test_mode,
            'message' => $message,
        ));
    }
    
    /**
     * AJAX: Toggle incremental sync test mode
     */
    public function ajax_toggle_incremental_test_mode() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $test_mode = get_option('bytemash_cron_incremental_test_mode_enabled', false);
        
        // Ensure the option exists and is boolean
        if (!get_option('bytemash_cron_incremental_test_mode_enabled')) {
            update_option('bytemash_cron_incremental_test_mode_enabled', false);
        }
        
        // Force check - if option is not explicitly false, set it to false
        $current_value = get_option('bytemash_cron_incremental_test_mode_enabled', false);
        if ($current_value !== false) {
            update_option('bytemash_cron_incremental_test_mode_enabled', false);
        }
        $new_test_mode = !$test_mode;
        
        if ($new_test_mode) {
            $this->enable_incremental_test_mode();
        } else {
            $this->disable_incremental_test_mode();
        }
        
        wp_send_json_success(array(
            'test_mode' => $new_test_mode,
            'message' => $new_test_mode ? __('Incremental sync test mode enabled', 'bytemash-woo-sync') : __('Incremental sync test mode disabled', 'bytemash-woo-sync'),
        ));
    }
    
    /**
     * AJAX: Toggle production full sync
     */
    public function ajax_toggle_production_full_sync() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        if (!$this->use_action_scheduler || !$this->action_scheduler) {
            wp_send_json_error(array('message' => __('Action Scheduler not available', 'bytemash-woo-sync')));
        }
        
        $production_full_sync_enabled = get_option('bytemash_cron_production_full_sync_enabled', false);
        $new_state = !$production_full_sync_enabled;
        
        if ($new_state) {
            // Enable production full sync
            $result = $this->action_scheduler->enable_production_full_sync();
            if ($result['success']) {
                wp_send_json_success(array(
                    'enabled' => true,
                    'message' => $result['message'],
                    'next_full_sync' => $result['next_full_sync'] ?? null,
                    'next_incremental_sync' => $result['next_incremental_sync'] ?? null,
                ));
            } else {
                wp_send_json_error(array('message' => $result['message'] ?? __('Failed to enable production full sync', 'bytemash-woo-sync')));
            }
        } else {
            // Disable production full sync
            $result = $this->action_scheduler->disable_production_full_sync();
            if ($result['success']) {
                wp_send_json_success(array(
                    'enabled' => false,
                    'message' => $result['message'],
                ));
            } else {
                wp_send_json_error(array('message' => $result['message'] ?? __('Failed to disable production full sync', 'bytemash-woo-sync')));
            }
        }
    }
    
    /**
     * Enable test mode
     */
    private function enable_test_mode() {
        // Store original schedules
        $original_schedules = array(
            'full_sync_frequency' => get_option('bytemash_full_sync_frequency', 'daily_at_0030'),
            'incremental_frequency' => get_option('bytemash_incremental_sync_frequency', 'every_5_hours'),
        );
        update_option('bytemash_cron_original_schedules', $original_schedules);
        
        // Clear existing schedules
        $scheduler = new ByteMash_Sync_Scheduler();
        $scheduler->clear_all_schedules();
        
        // Schedule test schedules
        wp_schedule_single_event(time() + 120, 'bytemash_full_sync_cron'); // 2 minutes from now
        wp_schedule_event(time(), 'every_5_minutes', 'bytemash_incremental_sync_cron');
        
        update_option('bytemash_cron_test_mode_enabled', true);
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Test mode enabled', array(), 'cron_manager');
    }
    
    /**
     * Disable test mode
     */
    private function disable_test_mode() {
        // Clear test schedules
        wp_clear_scheduled_hook('bytemash_full_sync_cron');
        wp_clear_scheduled_hook('bytemash_incremental_sync_cron');
        
        // Restore original schedules
        $original_schedules = get_option('bytemash_cron_original_schedules', array());
        if (!empty($original_schedules)) {
            $scheduler = new ByteMash_Sync_Scheduler();
            $scheduler->update_schedule(
                $original_schedules['full_sync_frequency'],
                $original_schedules['incremental_frequency']
            );
        }
        
        update_option('bytemash_cron_test_mode_enabled', false);
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Test mode disabled', array(), 'cron_manager');
    }
    
    /**
     * Enable full sync test mode
     */
    private function enable_full_test_mode() {
        // Store original full sync schedule
        $original_full_sync = get_option('bytemash_full_sync_frequency', 'daily_at_0130');
        update_option('bytemash_cron_original_full_sync', $original_full_sync);
        
        // Clear existing full sync schedule
        wp_clear_scheduled_hook('bytemash_full_sync_cron');
        
        // Schedule WordPress cron event (2 minutes from now)
        wp_schedule_single_event(time() + 120, 'bytemash_full_sync_cron');
        
        // Try to create and install system cron script for test mode
        $cron_result = $this->create_test_system_cron_script('full', 2);
        
        update_option('bytemash_cron_full_test_mode_enabled', true);
        
        $logger = new ByteMash_Logger();
        if ($cron_result['success']) {
            $logger->log('info', 'Full sync test mode enabled with system cron', array(), 'cron_manager');
        } else {
            $logger->log('warning', 'Full sync test mode enabled but system cron failed', array(
                'error' => $cron_result['message']
            ), 'cron_manager');
        }
        
        return $cron_result;
    }
    
    /**
     * Disable full sync test mode
     */
    private function disable_full_test_mode() {
        // Clear test full sync schedule
        wp_clear_scheduled_hook('bytemash_full_sync_cron');
        
        // Clean up test system cron script
        $script_path = get_option('bytemash_cron_test_full_script');
        if ($script_path && file_exists($script_path)) {
            unlink($script_path);
            delete_option('bytemash_cron_test_full_script');
        }
        
        // Restore original full sync schedule only (don't touch incremental)
        $original_full_sync = get_option('bytemash_cron_original_full_sync', 'daily_at_0030');
        if ($original_full_sync) {
            $scheduler = new ByteMash_Sync_Scheduler();
            // Only restore the full sync schedule, leave incremental unchanged
            $scheduler->restore_full_sync_schedule($original_full_sync);
        }
        
        update_option('bytemash_cron_full_test_mode_enabled', false);
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Full sync test mode disabled and system cron script cleaned up', array(), 'cron_manager');
    }
    
    /**
     * Enable incremental sync test mode
     */
    private function enable_incremental_test_mode() {
        // Store original incremental sync schedule
        $original_incremental_sync = get_option('bytemash_incremental_sync_frequency', 'every_5_hours');
        update_option('bytemash_cron_original_incremental_sync', $original_incremental_sync);
        
        // Clear existing incremental sync schedule
        wp_clear_scheduled_hook('bytemash_incremental_sync_cron');
        
        // Schedule WordPress cron event (every 5 minutes)
        wp_schedule_event(time(), 'every_5_minutes', 'bytemash_incremental_sync_cron');
        
        // Create system cron script for test mode
        $this->create_test_system_cron_script('incremental', 5); // Every 5 minutes
        
        update_option('bytemash_cron_incremental_test_mode_enabled', true);
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Incremental sync test mode enabled with system cron', array(), 'cron_manager');
    }
    
    /**
     * Disable incremental sync test mode
     */
    private function disable_incremental_test_mode() {
        // Clear test incremental sync schedule
        wp_clear_scheduled_hook('bytemash_incremental_sync_cron');
        
        // Clean up test system cron script
        $script_path = get_option('bytemash_cron_test_incremental_script');
        if ($script_path && file_exists($script_path)) {
            unlink($script_path);
            delete_option('bytemash_cron_test_incremental_script');
        }
        
        // Restore original incremental sync schedule
        $original_incremental_sync = get_option('bytemash_cron_original_incremental_sync', 'every_5_hours');
        if ($original_incremental_sync) {
            $scheduler = new ByteMash_Sync_Scheduler();
            $scheduler->update_schedule(get_option('bytemash_full_sync_frequency', 'daily_at_0030'), $original_incremental_sync);
        }
        
        update_option('bytemash_cron_incremental_test_mode_enabled', false);
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Incremental sync test mode disabled and system cron script cleaned up', array(), 'cron_manager');
    }
    
    /**
     * AJAX: Enable system cron
     */
    public function ajax_enable_system_cron() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $result = $this->install_system_cron();
        
        if ($result['success']) {
            update_option('bytemash_cron_system_cron_enabled', true);
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Install system cron
     */
    private function install_system_cron() {
        // Check prerequisites
        if (!function_exists('exec')) {
            return array(
                'success' => false, 
                'message' => __('exec() function is not available on this server. Please set up cron manually via cPanel or use an external cron service. See documentation for instructions.', 'bytemash-woo-sync'),
                'show_instructions' => true
            );
        }
        
        // Create cron script directory
        $upload_dir = wp_upload_dir();
        $cron_dir = $upload_dir['basedir'] . '/bytemash-cron';
        
        if (!wp_mkdir_p($cron_dir)) {
            return array('success' => false, 'message' => __('Cannot create cron directory', 'bytemash-woo-sync'));
        }
        
        // Generate cron script
        $script_path = $cron_dir . '/cron-runner.sh';
        $cron_url = site_url('/wp-cron.php?doing_wp_cron');
        
        $script_content = "#!/bin/bash\n";
        $script_content .= "# ByteMash Woo Sync Cron Runner\n";
        $script_content .= "# Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $script_content .= "wget -q -O - \"$cron_url\" >/dev/null 2>&1\n";
        
        if (file_put_contents($script_path, $script_content) === false) {
            return array('success' => false, 'message' => __('Cannot write cron script', 'bytemash-woo-sync'));
        }
        
        chmod($script_path, 0755);
        
        // Store configuration
        update_option('bytemash_cron_system_cron_script', $script_path);
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'System cron script created', array(
            'script_path' => $script_path,
        ), 'cron_manager');
        
        return array('success' => true, 'message' => __('System cron script created successfully. Please add this line to your crontab: */5 * * * * ' . $script_path, 'bytemash-woo-sync'));
    }
    
    /**
     * Create test system cron script
     */
    private function create_test_system_cron_script($type, $minutes) {
        // Check prerequisites
        if (!function_exists('exec')) {
            return array('success' => false, 'message' => __('exec() function is not available', 'bytemash-woo-sync'));
        }
        
        // Create cron script directory
        $upload_dir = wp_upload_dir();
        $cron_dir = $upload_dir['basedir'] . '/bytemash-cron';
        
        if (!wp_mkdir_p($cron_dir)) {
            return array('success' => false, 'message' => __('Cannot create cron directory', 'bytemash-woo-sync'));
        }
        
        // Generate test cron script
        $script_path = $cron_dir . "/test-{$type}-sync.sh";
        $cron_url = site_url('/wp-cron.php?doing_wp_cron');
        
        $script_content = "#!/bin/bash\n";
        $script_content .= "# ByteMash Woo Sync Test {$type} Sync\n";
        $script_content .= "# Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        if ($type === 'full') {
            // Single execution for full sync
            $script_content .= "wget -q -O - \"$cron_url\" >/dev/null 2>&1\n";
        } else {
            // Recurring execution for incremental sync
            $script_content .= "wget -q -O - \"$cron_url\" >/dev/null 2>&1\n";
        }
        
        if (file_put_contents($script_path, $script_content) === false) {
            return array('success' => false, 'message' => __('Cannot write test cron script', 'bytemash-woo-sync'));
        }
        
        chmod($script_path, 0755);
        
        // Store configuration
        update_option("bytemash_cron_test_{$type}_script", $script_path);
        
        $logger = new ByteMash_Logger();
        $logger->log('info', "Test {$type} sync system cron script created", array(
            'script_path' => $script_path,
            'minutes' => $minutes,
        ), 'cron_manager');
        
        if ($type === 'full') {
            $crontab_line = "*/{$minutes} * * * * {$script_path}";
        } else {
            $crontab_line = "*/{$minutes} * * * * {$script_path}";
        }
        
        // Try to install the cron job automatically
        $install_result = $this->install_test_cron_job($crontab_line);
        
        if ($install_result['success']) {
            return array('success' => true, 'message' => sprintf(
                __('Test %s sync system cron script created and installed successfully', 'bytemash-woo-sync'),
                $type
            ));
        } else {
            return array('success' => false, 'message' => sprintf(
                __('Test %s sync system cron script created but installation failed: %s. Manual installation required: %s', 'bytemash-woo-sync'),
                $type,
                $install_result['message'],
                $crontab_line
            ));
        }
    }
    
    /**
     * Install test cron job
     */
    private function install_test_cron_job($crontab_line) {
        // Check prerequisites
        if (!function_exists('exec')) {
            return array('success' => false, 'message' => __('exec() function is not available', 'bytemash-woo-sync'));
        }
        
        try {
            // Get current crontab
            $current_crontab = shell_exec('crontab -l 2>/dev/null') ?: '';
            
            // Check if line already exists
            if (strpos($current_crontab, $crontab_line) !== false) {
                return array('success' => true, 'message' => __('Cron job already exists', 'bytemash-woo-sync'));
            }
            
            // Add new line to crontab
            $new_crontab = $current_crontab . "\n" . $crontab_line . "\n";
            
            // Install new crontab
            $temp_file = tempnam(sys_get_temp_dir(), 'crontab_');
            file_put_contents($temp_file, $new_crontab);
            
            $result = shell_exec("crontab {$temp_file} 2>&1");
            unlink($temp_file);
            
            if (empty($result)) {
                return array('success' => true, 'message' => __('Cron job installed successfully', 'bytemash-woo-sync'));
            } else {
                return array('success' => false, 'message' => sprintf(__('Failed to install cron job: %s', 'bytemash-woo-sync'), $result));
            }
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => sprintf(__('Error installing cron job: %s', 'bytemash-woo-sync'), $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Enable production cron
     */
    public function ajax_enable_production_cron() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Clear any test mode schedules
        wp_clear_scheduled_hook('bytemash_full_sync_cron');
        wp_clear_scheduled_hook('bytemash_incremental_sync_cron');
        
        // Enable production schedules
        $scheduler = new ByteMash_Sync_Scheduler();
            $scheduler->update_schedule('daily_at_0130', 'every_5_hours');
        
        // Disable test modes
        update_option('bytemash_cron_full_test_mode_enabled', false);
        update_option('bytemash_cron_incremental_test_mode_enabled', false);
        
        $logger = new ByteMash_Logger();
        $logger->log('info', 'Production cron enabled', array(), 'cron_manager');
        
        wp_send_json_success(array(
            'message' => __('Production cron schedules enabled successfully', 'bytemash-woo-sync'),
        ));
    }
    
    /**
     * AJAX: Enable production system cron (combined)
     */
    public function ajax_enable_production_system_cron() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // First enable production schedules
        wp_clear_scheduled_hook('bytemash_full_sync_cron');
        wp_clear_scheduled_hook('bytemash_incremental_sync_cron');
        
        $scheduler = new ByteMash_Sync_Scheduler();
            $scheduler->update_schedule('daily_at_0130', 'every_5_hours');
        
        // Disable test modes
        update_option('bytemash_cron_full_test_mode_enabled', false);
        update_option('bytemash_cron_incremental_test_mode_enabled', false);
        
        // Then enable system cron
        $system_cron_result = $this->install_system_cron();
        
        if ($system_cron_result['success']) {
            update_option('bytemash_cron_system_cron_enabled', true);
            
            $logger = new ByteMash_Logger();
            $logger->log('info', 'Production system cron enabled (combined)', array(), 'cron_manager');
            
            wp_send_json_success(array(
                'message' => __('Production schedules and system cron enabled successfully. ' . $system_cron_result['message'], 'bytemash-woo-sync'),
            ));
        } else {
            // Production schedules are enabled, but system cron failed
            $logger = new ByteMash_Logger();
            $logger->log('warning', 'Production schedules enabled but system cron failed', array(
                'error' => $system_cron_result['message']
            ), 'cron_manager');
            
            // Build detailed message with instructions
            $site_url = site_url();
            $cron_url = site_url('/wp-cron.php?doing_wp_cron');
            
            $instructions = '<div style="text-align: left; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; margin-top: 10px;">';
            $instructions .= '<h4 style="margin-top: 0;">✅ Production Schedules Enabled!</h4>';
            $instructions .= '<p><strong>However, automatic system cron installation failed because exec() is disabled on your server.</strong></p>';
            $instructions .= '<h4>📋 Setup Instructions (Choose One):</h4>';
            $instructions .= '<h5>Option 1: Manual cPanel Cron (Recommended)</h5>';
            $instructions .= '<ol>';
            $instructions .= '<li>Login to your <strong>cPanel</strong> or hosting control panel</li>';
            $instructions .= '<li>Find <strong>"Cron Jobs"</strong> section</li>';
            $instructions .= '<li>Add new cron job with these settings:<br>';
            $instructions .= '<code style="background: #f0f0f0; padding: 5px; display: block; margin: 5px 0;">*/5 * * * * wget -q -O - "' . esc_url($cron_url) . '" >/dev/null 2>&1</code>';
            $instructions .= '</li>';
            $instructions .= '</ol>';
            $instructions .= '<h5>Option 2: External Cron Service (Free)</h5>';
            $instructions .= '<ul>';
            $instructions .= '<li><strong>EasyCron.com</strong> - Free tier available</li>';
            $instructions .= '<li><strong>cron-job.org</strong> - Completely free</li>';
            $instructions .= '<li><strong>UptimeRobot.com</strong> - Free monitoring + cron</li>';
            $instructions .= '</ul>';
            $instructions .= '<p>Configure to ping: <code style="background: #f0f0f0; padding: 2px 5px;">' . esc_url($cron_url) . '</code> every 5 minutes</p>';
            $instructions .= '<p><a href="' . esc_url(BYTEMASH_WOO_SYNC_PLUGIN_URL . 'documentation/TROUBLESHOOTING-LIVE-SERVER.md') . '" target="_blank" class="button">View Full Documentation</a></p>';
            $instructions .= '</div>';
            
            wp_send_json_success(array(
                'message' => __('Production schedules enabled successfully!', 'bytemash-woo-sync'),
                'warning' => $system_cron_result['message'],
                'instructions' => $instructions,
                'show_instructions' => true,
            ));
        }
    }
    
    /**
     * AJAX: Emergency stop all syncs
     */
    public function ajax_emergency_stop_syncs() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Clear all sync schedules
        wp_clear_scheduled_hook('bytemash_full_sync_cron');
        wp_clear_scheduled_hook('bytemash_incremental_sync_cron');
        wp_clear_scheduled_hook('bytemash_cron_health_check');
		
		// Also clear Action Scheduler jobs related to our syncs
		if (function_exists('as_unschedule_all_actions')) {
			// Cancel all pending recurring and single actions for our hooks
			as_unschedule_all_actions('bytemash_action_scheduler_full_sync', array('with_branding' => true), 'bytemash-sync');
			as_unschedule_all_actions('bytemash_action_scheduler_incremental_sync', array('with_branding' => true), 'bytemash-sync');
			// Batch and cleanup actions may have varying args; cancel without args to catch all
			as_unschedule_all_actions('bytemash_action_scheduler_batch_sync', null, 'bytemash-sync');
			as_unschedule_all_actions('bytemash_action_scheduler_cleanup', null, 'bytemash-sync');
		}
        
        // Clear running transients
        delete_transient('bytemash_full_sync_running');
        delete_transient('bytemash_incremental_sync_running');
        delete_transient('bytemash_sync_running');
        
        // Clear batch processor active syncs
        $batch_processor = new ByteMash_Batch_Processor();
        $active_syncs = $batch_processor->get_active_syncs();
        
        foreach ($active_syncs as $sync) {
            $batch_processor->stop_sync($sync['sync_id']);
        }
        
        // Clear all sync-related transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bytemash_sync_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_bytemash_sync_%'");
        
        $logger = new ByteMash_Logger();
        $logger->log('warning', 'Emergency stop executed - all syncs stopped', array(
            'user' => get_current_user_id(),
            'stopped_syncs' => count($active_syncs)
        ), 'emergency_stop');
        
        wp_send_json_success(array(
            'message' => sprintf(
                __('Emergency stop executed. Stopped %d active syncs and cleared all schedules.', 'bytemash-woo-sync'),
                count($active_syncs)
            ),
        ));
    }
    
    /**
     * AJAX: Get scheduled sync status
     */
    public function ajax_get_scheduled_sync_status() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Get next scheduled times
        $full_sync_next = wp_next_scheduled('bytemash_full_sync_cron');
        $incremental_sync_next = wp_next_scheduled('bytemash_incremental_sync_cron');
        
        // Check Action Scheduler if WP-Cron is empty or production mode is enabled
        $production_full_enabled = get_option('bytemash_cron_production_full_sync_enabled', false);
        
        if (function_exists('as_next_scheduled_action')) {
            if (!$full_sync_next || $production_full_enabled) {
                $as_full_next = as_next_scheduled_action('bytemash_action_scheduler_full_sync');
                if ($as_full_next) {
                    $full_sync_next = $as_full_next;
                }
            }
            
            if (!$incremental_sync_next || $production_full_enabled) {
                $as_incremental_next = as_next_scheduled_action('bytemash_action_scheduler_incremental_sync');
                if ($as_incremental_next) {
                    $incremental_sync_next = $as_incremental_next;
                }
            }
        }
        
        // Check if syncs are running
        $full_sync_running = get_transient('bytemash_full_sync_running');
        $incremental_sync_running = get_transient('bytemash_incremental_sync_running');
        
        // Get test mode status
        $test_mode = get_option('bytemash_cron_test_mode_enabled', false);
        $full_test_mode = get_option('bytemash_cron_full_test_mode_enabled', false);
        $incremental_test_mode = get_option('bytemash_cron_incremental_test_mode_enabled', false);
        
        // Adjust display for test modes and production mode
        if ($full_test_mode && $full_sync_next) {
            $full_sync_next = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $full_sync_next) . ' (Test Mode)';
        } elseif ($production_full_enabled && $full_sync_next) {
            $full_sync_next = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $full_sync_next) . ' (Production - AS)';
        } elseif (!$full_sync_next) {
            $full_sync_next = __('Not scheduled', 'bytemash-woo-sync');
        } else {
             $full_sync_next = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $full_sync_next);
        }
        
        if ($incremental_test_mode && $incremental_sync_next) {
            $incremental_sync_next = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $incremental_sync_next) . ' (Test Mode)';
        } elseif ($production_full_enabled && $incremental_sync_next) {
            $incremental_sync_next = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $incremental_sync_next) . ' (Production - AS)';
        } elseif (!$incremental_sync_next) {
            $incremental_sync_next = __('Not scheduled', 'bytemash-woo-sync');
        } else {
            $incremental_sync_next = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $incremental_sync_next);
        }    
        
        // Get recent logs
        $logger = new ByteMash_Logger();
        $recent_logs = $logger->get_logs(5);
        
        // Format times
        $full_sync_next_formatted = $full_sync_next;
        $incremental_sync_next_formatted = $incremental_sync_next;
        
        // Check for active sync progress
        $sync_progress = null;
        if ($full_sync_running || $incremental_sync_running) {
            $batch_processor = new ByteMash_Batch_Processor();
            $active_syncs = $batch_processor->get_active_syncs();
            
            if (!empty($active_syncs)) {
                $sync_progress = array(
                    'active_syncs' => $active_syncs
                );
            }
        }
        
        wp_send_json_success(array(
            'full_sync_next' => $full_sync_next_formatted,
            'incremental_sync_next' => $incremental_sync_next_formatted,
            'full_sync_running' => (bool) $full_sync_running,
            'incremental_sync_running' => (bool) $incremental_sync_running,
            'test_mode' => $test_mode,
            'full_test_mode' => $full_test_mode,
            'incremental_test_mode' => $incremental_test_mode,
            'recent_logs' => $recent_logs,
            'sync_progress' => $sync_progress,
        ));
    }
    
    /**
     * Display branding guide PDFs on product pages
     */
    public function display_branding_guides() {
        global $product;
        static $amrod_branding_guides_rendered = false;

        // Prevent duplicate rendering if hooked in multiple places
        if ($amrod_branding_guides_rendered) {
            return;
        }
        
        if (!$product) {
            return;
        }
        
        $full_guide = get_post_meta($product->get_id(), '_amrod_full_branding_guide', true);
        $logo24_guide = get_post_meta($product->get_id(), '_amrod_logo24_branding_guide', true);
        
        if (empty($full_guide) && empty($logo24_guide)) {
            return;
        }
        
        echo '<div class="amrod-branding-guides" style="margin-top: 20px; padding: 15px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 5px;">';
        echo '<h4 style="margin: 0 0 15px 0; color: #333; font-size: 16px;">Branding Guides</h4>';
        
        if (!empty($full_guide)) {
            echo '<div style="margin-bottom: 10px;">';
            echo '<a href="' . esc_url($full_guide) . '" target="_blank" class="button" style="display: inline-flex; align-items: center; text-decoration: none; background: #0073aa; color: white; padding: 8px 16px; border-radius: 3px; font-size: 14px;">';
            echo ' Full Branding Guide';
            echo '</a>';
            echo '</div>';
        }
        
        if (!empty($logo24_guide)) {
            echo '<div>';
            echo '<a href="' . esc_url($logo24_guide) . '" target="_blank" class="button" style="display: inline-flex; align-items: center; text-decoration: none; background: #28a745; color: white; padding: 8px 16px; border-radius: 3px; font-size: 14px;">';
            echo 'Logo24 Branding Guide';
            echo '</a>';
            echo '</div>';
        }
        
        echo '</div>';
        $amrod_branding_guides_rendered = true;
    }

    /**
     * Force-inject product buttons (branding guides + stock modal) when setting is enabled
     */
    public function maybe_force_product_buttons() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        $force = get_option('bytemash_force_product_buttons', false);
        if (!$force) {
            return;
        }
        // Build forced buttons only if theme didn't already output them
        global $product;
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            if (function_exists('wc_get_product')) {
                $product = wc_get_product(get_the_ID());
            }
        }
        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }
        $full_guide = get_post_meta($product->get_id(), '_amrod_full_branding_guide', true);
        $logo24_guide = get_post_meta($product->get_id(), '_amrod_logo24_branding_guide', true);

        $guide_html = '';
        if (!empty($full_guide)) {
            $guide_html .= '<a href="' . esc_url($full_guide) . '" target="_blank" class="bm-branding-guide-button button">Full Branding Guide</a>';
        }
        if (!empty($logo24_guide)) {
            $guide_html .= '<a href="' . esc_url($logo24_guide) . '" target="_blank" class="bm-logo24-guide-button button" style="background:#28a745;color:#fff;padding:6px 12px;border-radius:3px;text-decoration:none;">Logo24 Branding Guide</a>';
        }
        $inline_html = $guide_html . '<button type="button" class="button" id="bytemash-inline-stock" style="background:#6c757d;color:#fff;padding:6px 12px;border-radius:3px;">View Stock</button>';

        echo '<script>(function(){
            function ready(fn){ if(document.readyState!=="loading"){ fn(); } else { document.addEventListener("DOMContentLoaded", fn); } }
            ready(function(){
                // Only skip if BOTH already exist (avoid missing one of them)
                if (document.querySelector(".amrod-branding-guides") && document.getElementById("bytemash-stock-modal-trigger")) { return; }
                var summary = document.querySelector(".single-product .summary, .product .summary, .product, .brx-content, main, #content");
                if(!summary) return;
                var title = summary.querySelector(".product_title, h1.product_title, h1");
                var anchor = title || summary.firstElementChild || summary;
                var bar = document.createElement("div");
                bar.className = "bytemash-action-inline";
                bar.style.cssText = "margin:10px 0; display:flex; gap:10px; flex-wrap:wrap; align-items:center;";
                var html = ' . json_encode($inline_html) . ';
                bar.innerHTML = html;
                if (anchor && anchor.parentNode){
                    if (anchor.nextSibling){ anchor.parentNode.insertBefore(bar, anchor.nextSibling); }
                    else { anchor.parentNode.appendChild(bar); }
                }
                var stockBtn = document.getElementById("bytemash-inline-stock");
                if (stockBtn){
                    stockBtn.addEventListener("click", function(){
                        function ensureTriggerAndModal(){
                            // Ensure trigger exists (same structure as server output)
                            var triggerWrap = document.getElementById("bytemash-stock-modal-trigger");
                            if (!triggerWrap){
                                triggerWrap = document.createElement("div");
                                triggerWrap.id = "bytemash-stock-modal-trigger";
                                triggerWrap.style.display = "none";
                                var btn = document.createElement("button");
                                btn.type = "button";
                                btn.className = "button";
                                btn.textContent = "View Stock Availability";
                                triggerWrap.appendChild(btn);
                                document.body.appendChild(triggerWrap);
                            }
                            // Ensure modal container exists
                            var modal = document.getElementById("bytemash-stock-modal");
                            if (!modal){
                                var wrap = document.createElement("div");
                                wrap.id = "bytemash-stock-modal";
                                wrap.className = "bytemash-stock-modal";
                                wrap.style.display = "none";
                                wrap.innerHTML = "<div class=\"bytemash-stock-modal__dialog\">"
                                    + "<button type=\"button\" class=\"bytemash-stock-modal__close\" aria-label=\"Close\">×</button>"
                                    + "<h3>Stock Availability</h3>"
                                    + "<div id=\"bytemash-stock-modal__content\"><div class=\"bytemash-spinner\"></div></div>"
                                    + "</div>";
                                document.body.appendChild(wrap);
                            }
                        }
                        function openViaTrigger(){
                            var t = document.querySelector("#bytemash-stock-modal-trigger button");
                            if (t){ t.click(); return true; }
                            return false;
                        }
                        // Ensure required elements, then open like the original button
                        ensureTriggerAndModal();
                        if (!openViaTrigger()){
                            // As a fallback, observe and open when ready
                            var started=Date.now();
                            var obs=new MutationObserver(function(){
                                if (openViaTrigger() || (Date.now()-started)>5000){ try{obs.disconnect();}catch(e){} }
                            });
                            obs.observe(document.body,{childList:true,subtree:true});
                        }
                    });
                }
            });
        })();</script>';
    }

    /**
     * Render top action bar (under nav, before content) with branding + stock buttons when forced
     */
    public function render_product_action_bar() {
        static $bytemash_action_bar_rendered = false;
        if ($bytemash_action_bar_rendered) {
            return;
        }
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        if (!get_option('bytemash_force_product_buttons', false)) {
            return;
        }
        global $product;
        // Ensure we have a valid WC_Product object (wp_body_open may fire before globals are set)
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            if (function_exists('wc_get_product')) {
                $product = wc_get_product(get_the_ID());
            }
        }
        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }
        // Read guide URLs
        $full_guide = get_post_meta($product->get_id(), '_amrod_full_branding_guide', true);
        $logo24_guide = get_post_meta($product->get_id(), '_amrod_logo24_branding_guide', true);

        // Output a simple flex row action bar
        echo '<div id="bytemash-action-bar" class="bytemash-action-bar" style="position:relative; z-index:2; background:#f8f9fb; border-bottom:1px solid #e9ecef;">';
        echo '<div style="max-width:1200px; margin:0 auto; padding:10px 15px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">';
        if (!empty($full_guide)) {
            echo '<a href="' . esc_url($full_guide) . '" target="_blank" class="button" style="background:#0073aa; color:#fff; padding:8px 14px; border-radius:3px; text-decoration:none;">Full Branding Guide</a>';
        }
        if (!empty($logo24_guide)) {
            echo '<a href="' . esc_url($logo24_guide) . '" target="_blank" class="button" style="background:#28a745; color:#fff; padding:8px 14px; border-radius:3px; text-decoration:none;">Logo24 Branding Guide</a>';
        }
        // Header stock button that triggers existing modal opener
        echo '<button type="button" class="button" id="bytemash-actionbar-stock" style="background:#6c757d; color:#fff; padding:8px 14px; border-radius:3px;">View Stock</button>';
        echo '</div>';
        echo '</div>';

        // Wire header button to open existing modal trigger if present
        echo '<script>(function(){
            var btn=function(){return document.getElementById("bytemash-actionbar-stock")};
            function openModal(){
                var t=document.querySelector("#bytemash-stock-modal-trigger button");
                if(t){ t.click(); return true; }
                return false;
            }
            var b=btn();
            if(b){ b.addEventListener("click", function(){ if(!openModal()){ setTimeout(openModal,300); } }); }
            // Try to move the action bar directly under header/nav if present
            var bar=document.getElementById(\"bytemash-action-bar\");
            if(bar){
                var header=document.querySelector(\"header[role=\\\"banner\\\"], .brx-header, header.site-header, .site-header\");
                if(header && header.parentNode){
                    if(header.nextSibling){ header.parentNode.insertBefore(bar, header.nextSibling); }
                    else { header.parentNode.appendChild(bar); }
                }
            }
        })();</script>';
        $bytemash_action_bar_rendered = true;
    }
    
    /**
     * Display brand information on product pages
     */
    public function display_brand_info() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        $brand = get_post_meta($product->get_id(), '_amrod_brand', true);
        
        if (empty($brand)) {
            return;
        }
        
        echo '<div class="amrod-brand-info" style="margin: 15px 0; padding: 10px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">';
        echo '<strong style="color: #856404;">🏷️ Brand:</strong> <span style="color: #333; font-weight: 500;">' . esc_html($brand) . '</span>';
        echo '</div>';
    }
    
    /**
     * Display brand logo on product page
     */
    public function display_brand_logo() {
        global $product;
        if (!$product) {
            return;
        }
        echo $this->shortcode_brand_logo();
    }
    
    /**
     * Shortcode: [amrod_brand_logo] - Display brand logo
     * Gets brand code/name from product meta, then looks up logo URL from synced brands data
     */
    public function shortcode_brand_logo($atts = array()) {
        // Parse attributes
        $atts = shortcode_atts(array(
            'product_id' => 0,
            'debug' => false,
        ), $atts, 'amrod_brand_logo');
        
        $debug_info = array();
        
        // Try to get product from global first
        global $product, $post;
        
        $debug_info['initial_global_product'] = is_object($product) ? $product->get_id() : 'none';
        $debug_info['initial_global_post'] = !empty($post) ? $post->ID . ' (' . $post->post_type . ')' : 'none';
        $debug_info['get_the_ID'] = get_the_ID();
        
        // If no product in global, try multiple fallback methods
        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            // Method 1: Try from current post (works in loops and singular pages)
            $product_id = get_the_ID();
            if ($product_id && get_post_type($product_id) === 'product') {
                $product = wc_get_product($product_id);
                $debug_info['method'] = 'get_the_ID';
            }
            
            // Method 2: Try from global $post (works in some loop contexts)
            if ((!$product || !is_object($product)) && !empty($post) && $post->post_type === 'product') {
                $product = wc_get_product($post->ID);
                $debug_info['method'] = 'global_post';
            }
        } else {
            $debug_info['method'] = 'global_product';
        }
        
        // If still no product and shortcode is called with product_id attribute
        if ((!$product || !is_object($product)) && !empty($atts['product_id'])) {
            $product = wc_get_product(intval($atts['product_id']));
            $debug_info['method'] = 'attribute';
        }
        
        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            // Return debug info if requested
            if ($atts['debug']) {
                return '<!-- DEBUG: No product found. Info: ' . wp_json_encode($debug_info) . ' -->';
            }
            return '';
        }
        
        $debug_info['product_found'] = $product->get_id();
        
        $product_id = $product->get_id();
        $logo_url = '';
        $brand_name = '';
        
        // Get brand code from product meta (primary method)
        $brand_code = get_post_meta($product_id, '_amrod_brand_code', true);
        
        // For variations, check parent product
        if (empty($brand_code) && $product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            if ($parent_id) {
                $brand_code = get_post_meta($parent_id, '_amrod_brand_code', true);
            }
        }
        
        // Method 1: If we have brand code, look up brand data from options
        if (!empty($brand_code)) {
            $brand_data = get_option("amrod_brand_{$brand_code}");
            if (is_array($brand_data) && !empty($brand_data['image'])) {
                $logo_url = $brand_data['image'];
                $brand_name = $brand_data['name'] ?? '';
            }
        }
        
        // Method 2: If no brand code or data not found, try to find by brand name
        if (empty($logo_url)) {
            $brand_name_meta = get_post_meta($product_id, '_amrod_brand', true);
            if (empty($brand_name_meta) && $product->is_type('variation')) {
                $parent_id = $product->get_parent_id();
                if ($parent_id) {
                    $brand_name_meta = get_post_meta($parent_id, '_amrod_brand', true);
                }
            }
            
            // Search all synced brands to find match by name
            if (!empty($brand_name_meta)) {
                global $wpdb;
                $brand_options = $wpdb->get_results(
                    "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'amrod_brand_%'",
                    ARRAY_A
                );
                
                foreach ($brand_options as $option) {
                    $brand_data = maybe_unserialize($option['option_value']);
                    if (is_array($brand_data) && 
                        !empty($brand_data['name']) && 
                        strcasecmp($brand_data['name'], $brand_name_meta) === 0 &&
                        !empty($brand_data['image'])) {
                        $logo_url = $brand_data['image'];
                        $brand_name = $brand_data['name'];
                        break;
                    }
                }
            }
        }
        
        $debug_info['brand_code'] = $brand_code ?? 'none';
        $debug_info['logo_url'] = $logo_url ?? 'none';
        
        if (empty($logo_url)) {
            if ($atts['debug']) {
                return '<!-- DEBUG: No logo found. Info: ' . wp_json_encode($debug_info) . ' --><div style="border: 2px dashed red; padding: 10px; margin: 10px 0;">No brand logo (debug mode)</div>';
            }
            return '';
        }
        
        ob_start();
        
        // Show debug info if requested
        if ($atts['debug']) {
            echo '<!-- DEBUG: Brand Logo Info: ' . wp_json_encode($debug_info) . ' -->';
        }
        ?>
        <div class="amrod-brand-logo" style="margin: 15px 0;">
            <?php if ($brand_name): ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($brand_name); ?>" style="max-height: 60px; max-width: 200px; object-fit: contain;">
            <?php else: ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="Brand Logo" style="max-height: 60px; max-width: 200px; object-fit: contain;">
            <?php endif; ?>
            <?php if ($atts['debug']): ?>
                <div style="font-size: 11px; color: #666; margin-top: 5px;">
                    Product ID: <?php echo $product_id; ?> | Brand: <?php echo esc_html($brand_name ?: 'N/A'); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Display color swatches row on product page
     */
    public function display_color_swatches_row() {
        global $product;
        if (!$product) {
            return;
        }
        echo $this->shortcode_color_swatches();
    }
    
    /**
     * Shortcode: [amrod_color_swatches] - Display color swatches
     */
    public function shortcode_color_swatches($atts = array()) {
        global $product, $post;
        
        // Try to get product using multiple methods
        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            $product_id = get_the_ID();
            if ($product_id && get_post_type($product_id) === 'product') {
                $product = wc_get_product($product_id);
            }
            if ((!$product || !is_object($product)) && !empty($post) && $post->post_type === 'product') {
                $product = wc_get_product($post->ID);
            }
        }
        
        if ((!$product || !is_object($product)) && !empty($atts['product_id'])) {
            $product = wc_get_product(intval($atts['product_id']));
        }
        
        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            return '';
        }
        
        $product_id = $product->get_id();
        $color_mapping = get_post_meta($product_id, '_amrod_color_mapping', true);
        
        if (empty($color_mapping) || !is_array($color_mapping)) {
            return '';
        }
        
        $swatches = array();
        foreach ($color_mapping as $color_name => $color_code) {
            $swatch_data = get_option("amrod_color_swatch_{$color_code}");
            if ($swatch_data && !empty($swatch_data['hexValue']) && is_array($swatch_data['hexValue'])) {
                $hex = $swatch_data['hexValue'][0] ?? '';
                if (!empty($hex)) {
                    $swatches[] = array(
                        'name' => $swatch_data['name'] ?? $color_name,
                        'hex' => $hex,
                        'code' => $color_code,
                    );
                }
            }
        }
        
        if (empty($swatches)) {
            return '';
        }
        
        ob_start();
        ?>
        <div class="amrod-color-swatches-row" style="margin: 15px 0;">
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <?php foreach ($swatches as $swatch): ?>
                    <div class="amrod-color-swatch-circle" 
                         style="width: 30px; height: 30px; border-radius: 50%; background-color: <?php echo esc_attr($swatch['hex']); ?>; border: 2px solid #000; cursor: pointer; display: inline-block; box-shadow: 0 2px 4px rgba(0,0,0,0.15);"
                         title="<?php echo esc_attr($swatch['name']); ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Display product gender on product page
     */
    public function display_product_gender() {
        global $product;
        if (!$product) {
            return;
        }
        echo $this->shortcode_gender();
    }
    
    /**
     * Shortcode: [amrod_gender] - Display product gender
     */
    public function shortcode_gender($atts = array()) {
        global $product, $post;
        
        // Try to get product using multiple methods
        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            $product_id = get_the_ID();
            if ($product_id && get_post_type($product_id) === 'product') {
                $product = wc_get_product($product_id);
            }
            if ((!$product || !is_object($product)) && !empty($post) && $post->post_type === 'product') {
                $product = wc_get_product($post->ID);
            }
        }
        
        if ((!$product || !is_object($product)) && !empty($atts['product_id'])) {
            $product = wc_get_product(intval($atts['product_id']));
        }
        
        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            return '';
        }
        
        $gender = get_post_meta($product->get_id(), '_amrod_gender', true);
        if (empty($gender)) {
            return '';
        }
        
        // Normalize gender text: "mens" -> "men"
        $gender = str_ireplace('mens', 'men', $gender);
        $gender = ucfirst(trim($gender));
        
        ob_start();
        ?>
        <div class="amrod-product-gender" style="margin: 15px 0;">
            <strong>Gender:</strong> <span><?php echo esc_html($gender); ?></span>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Display total stock info on product page
     */
    public function display_total_stock_info() {
        global $product;
        if (!$product) {
            return;
        }
        echo $this->shortcode_total_stock();
    }
    
    /**
     * Shortcode: [amrod_total_stock] - Display total stock and incoming stock
     */
    public function shortcode_total_stock($atts = array()) {
        global $product, $post;
        
        // Try to get product using multiple methods
        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            $product_id = get_the_ID();
            if ($product_id && get_post_type($product_id) === 'product') {
                $product = wc_get_product($product_id);
            }
            if ((!$product || !is_object($product)) && !empty($post) && $post->post_type === 'product') {
                $product = wc_get_product($post->ID);
            }
        }
        
        if ((!$product || !is_object($product)) && !empty($atts['product_id'])) {
            $product = wc_get_product(intval($atts['product_id']));
        }
        
        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            return '';
        }
        
        $product_id = $product->get_id();
        $total_stock = 0;
        $total_incoming = 0;
        
        if ($product->is_type('variable')) {
            // Sum stock from each variation using _stock meta (never get_stock_quantity() - can return parent stock per variation and compound)
            $variations = $product->get_children();
            foreach ($variations as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) continue;
                
                $detail = get_post_meta($variation_id, '_amrod_stock_detail', true);
                if (!is_array($detail)) {
                    $detail = array();
                }
                $variation_sku = $variation->get_sku();
                $detail_belongs = empty($detail['fullCode']) || (string) $detail['fullCode'] === (string) $variation_sku;
                if ($detail_belongs && isset($detail['stock'])) {
                    $stock_qty = (int) $detail['stock'];
                } else {
                    $stock_meta = get_post_meta($variation_id, '_stock', true);
                    $stock_qty = ($stock_meta !== '' && $stock_meta !== null) ? (int) $stock_meta : 0;
                }
                $total_stock += $stock_qty;
                
                $incoming_raw = ($detail_belongs && isset($detail['incoming'])) ? $detail['incoming'] : (isset($detail['incomingStock']) ? $detail['incomingStock'] : array());
                if (is_array($incoming_raw)) {
                    foreach ($incoming_raw as $incoming) {
                        $total_incoming += isset($incoming['total']) ? (int) $incoming['total'] : 0;
                    }
                }
            }
        } else {
            // Simple product: use _stock or _amrod_stock_detail, not get_stock_quantity() (can be compounded)
            $detail = get_post_meta($product_id, '_amrod_stock_detail', true);
            if (!is_array($detail)) {
                $detail = array();
            }
            if (isset($detail['stock'])) {
                $total_stock = (int) $detail['stock'];
            } else {
                $stock_meta = get_post_meta($product_id, '_stock', true);
                $total_stock = ($stock_meta !== '' && $stock_meta !== null) ? (int) $stock_meta : 0;
            }
            
            $incoming_raw = isset($detail['incoming']) ? $detail['incoming'] : (isset($detail['incomingStock']) ? $detail['incomingStock'] : array());
            if (is_array($incoming_raw)) {
                foreach ($incoming_raw as $incoming) {
                    $total_incoming += isset($incoming['total']) ? (int) $incoming['total'] : 0;
                }
            }
        }
        
        // Don't return early - we want to show "Out of stock" when stock is 0
        // Only return if there's no stock data at all (product might not have been synced)
        // if ($total_stock === 0) {
        //     // Check if product has stock management enabled to determine if we should show "Out of stock"
        //     $stock_managed = $product->managing_stock();
        //     if (!$stock_managed) {
        //         // Product doesn't manage stock, so don't show anything
        //         return '';
        //     }
        // }
        
        ob_start();
        ?>
        <div class="amrod-total-stock-info" style="margin: 15px 0;">
            <?php if ($total_stock > 0): ?>
                <div style="margin-bottom: 5px;">
                    <strong>Total Stock:</strong> <span><?php echo number_format($total_stock); ?></span>
                </div>
            <?php elseif ($total_stock === 0): ?>
                <div style="margin-bottom: 5px;">
                     <span style="color: #d63638;">Out of stock</span>
                </div>
            <?php endif; ?>
            <?php if ($total_incoming > 0): ?>
                <div>
                    <strong>Incoming Stock:</strong> <span><?php echo number_format($total_incoming); ?></span>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Shortcode: [amrod_category_filter] - Display category filter widget
     * Shows relevant subcategories based on current context
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function shortcode_category_filter($atts = array()) {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce') || !taxonomy_exists('product_cat')) {
            return '';
        }
        
        // Parse attributes
        $atts = shortcode_atts(array(
            'title' => __('Filter by Category', 'bytemash-woo-sync'),
            'show_count' => 'true',
            'hide_empty' => 'false', // Default to false so categories show even without products
            'depth' => 1, // 1 = direct children only, 0 = all levels
            'fallback' => 'true', // Show top-level categories if no children found
            'debug' => 'false', // Show debug info
        ), $atts, 'amrod_category_filter');
        
        // Convert string booleans to actual booleans
        $show_count = filter_var($atts['show_count'], FILTER_VALIDATE_BOOLEAN);
        $hide_empty = filter_var($atts['hide_empty'], FILTER_VALIDATE_BOOLEAN);
        $fallback = filter_var($atts['fallback'], FILTER_VALIDATE_BOOLEAN);
        $debug = filter_var($atts['debug'], FILTER_VALIDATE_BOOLEAN);
        
        // Build base URL for filter links (preserve other active query params)
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        if (!empty($request_uri)) {
            $absolute_current_url = home_url($request_uri);
        } else {
            if (function_exists('wc_get_page_permalink')) {
                $absolute_current_url = wc_get_page_permalink('shop');
            } else {
                $absolute_current_url = home_url('/shop/');
            }
        }
        
        $filter_url_base = remove_query_arg(
            array('filter_category', 'product_cat', 'brx_vqxomj', 'brx_vqxomj%5B0%5D', 'brx_vqxomj[0]', 'paged'),
            $absolute_current_url
        );
        
        $build_filter_url = static function($slug) use ($filter_url_base) {
            if (empty($slug)) {
                return esc_url($filter_url_base);
            }
            
            $url = add_query_arg('product_cat', $slug, $filter_url_base);
            return esc_url($url);
        };
        
        $cache_version = $this->get_category_filter_cache_version();
        $cache_ttl = 15 * MINUTE_IN_SECONDS;
        
        // Cached term loader to avoid repeated heavy taxonomy queries on large categories
        $get_terms_cached = function(array $args) use ($cache_version, $cache_ttl) {
            static $request_cache = array();
            
            $defaults = array(
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'update_term_meta_cache' => false,
                'pad_counts' => false,
            );
            
            $query_args = array_merge($defaults, $args);
            ksort($query_args);
            $cache_key = md5(wp_json_encode($query_args));
            
            if (isset($request_cache[$cache_key])) {
                return $request_cache[$cache_key];
            }
            
            $transient_key = 'bytemash_cf_' . $cache_version . '_' . $cache_key;
            $cached_terms = get_transient($transient_key);
            if ($cached_terms !== false) {
                $request_cache[$cache_key] = $cached_terms;
                return $cached_terms;
            }
            
            $terms = get_terms($query_args);
            if (is_wp_error($terms)) {
                $terms = array();
            } else {
                set_transient($transient_key, $terms, $cache_ttl);
            }
            
            $request_cache[$cache_key] = $terms;
            
            return $terms;
        };
        
        // Helper: deduplicate terms and optionally remove zero-count categories
        $normalize_terms = static function($terms, $remove_zero_count = false) {
            if (empty($terms) || is_wp_error($terms)) {
                return array();
            }
            
            $unique_terms = array();
            $seen_ids = array();
            $seen_names = array();
            
            foreach ($terms as $term) {
                if (!is_object($term) || !isset($term->term_id)) {
                    continue;
                }
                
                $term_id = (int) $term->term_id;
                
                if (isset($seen_ids[$term_id])) {
                    continue;
                }
                
                $normalized_name = '';
                if (!empty($term->name)) {
                    $normalized_name = sanitize_title($term->name);
                }
                
                $count = isset($term->count) ? (int) $term->count : 0;
                
                if ($remove_zero_count) {
                    if ($count <= 0) {
                        continue;
                    }
                }
                
                if ($normalized_name && isset($seen_names[$normalized_name])) {
                    $existing_index = $seen_names[$normalized_name];
                    $existing_term = $unique_terms[$existing_index] ?? null;
                    $existing_count = isset($existing_term->count) ? (int) $existing_term->count : 0;
                    
                    if ($count > $existing_count) {
                        // Replace with higher-count term for identical names
                        if ($existing_term && isset($existing_term->term_id)) {
                            unset($seen_ids[$existing_term->term_id]);
                        }
                        $unique_terms[$existing_index] = $term;
                        $seen_ids[$term_id] = $existing_index;
                    }
                    
                    continue;
                }
                
                $unique_terms[] = $term;
                $current_index = count($unique_terms) - 1;
                $seen_ids[$term_id] = $current_index;
                
                if ($normalized_name) {
                    $seen_names[$normalized_name] = $current_index;
                }
            }
            
            return $unique_terms;
        };
        
        // Get current category context
        $current_category = null;
        $current_category_id = null; // ID of category to highlight
        $parent_navigation = null;
        $categories_to_show = array();
        
        // Check for filter parameters (both formats) - this takes priority
        $filtered_category_id = null;
        
        // Preferred format: WooCommerce product_cat slug
        if (isset($_GET['product_cat']) && $_GET['product_cat'] !== '') {
            $filtered_category_slug = sanitize_title(wp_unslash($_GET['product_cat']));
            if ($filtered_category_slug !== '') {
                $filtered_category = get_term_by('slug', $filtered_category_slug, 'product_cat');
                if ($filtered_category && !is_wp_error($filtered_category)) {
                    $filtered_category_id = $filtered_category->term_id;
                }
            }
        }
        // Check Bricks format: brx_vqxomj[0]=slug
        elseif (isset($_GET['brx_vqxomj']) && is_array($_GET['brx_vqxomj']) && !empty($_GET['brx_vqxomj'][0])) {
            $filtered_category_slug = sanitize_text_field($_GET['brx_vqxomj'][0]);
            $filtered_category = get_term_by('slug', $filtered_category_slug, 'product_cat');
            if ($filtered_category && !is_wp_error($filtered_category)) {
                $filtered_category_id = $filtered_category->term_id;
            }
        }
        // Check our format: filter_category=ID
        elseif (isset($_GET['filter_category']) && !empty($_GET['filter_category'])) {
            $filtered_category_id = intval($_GET['filter_category']);
            $filtered_category = get_term($filtered_category_id, 'product_cat');
        } else {
            $filtered_category = null;
        }
        
        // If we have a filter parameter, use that category
        if ($filtered_category_id && $filtered_category && !is_wp_error($filtered_category)) {
            $current_category = $filtered_category;
            $current_category_id = $filtered_category_id;
            
            if (isset($filtered_category->parent) && $filtered_category->parent) {
                $parent_term = get_term($filtered_category->parent, 'product_cat');
                if ($parent_term && !is_wp_error($parent_term)) {
                    $parent_navigation = array(
                        'url' => $build_filter_url($parent_term->slug),
                        'label' => sprintf(
                            /* translators: %s = parent category name */
                            __('Back to %s', 'bytemash-woo-sync'),
                            $parent_term->name
                        ),
                    );
                }
            } else {
                $parent_navigation = array(
                    'url' => esc_url($filter_url_base),
                    'label' => __('Back to all categories', 'bytemash-woo-sync'),
                );
            }
            
            // Get direct child categories of the filtered category
            $categories_to_show = $get_terms_cached(array(
                'parent' => $filtered_category_id,
                'hide_empty' => $hide_empty,
            ));
        }
        
        // Check if we're on a product category archive page (if no filter set)
        if (!$filtered_category_id && is_tax('product_cat')) {
            $current_category = get_queried_object();
            if ($current_category && isset($current_category->term_id)) {
                $current_category_id = $current_category->term_id;
                
                if (isset($current_category->parent) && $current_category->parent) {
                    $parent_term = get_term($current_category->parent, 'product_cat');
                    if ($parent_term && !is_wp_error($parent_term)) {
                        $parent_navigation = array(
                            'url' => $build_filter_url($parent_term->slug),
                            'label' => sprintf(
                                __('Back to %s', 'bytemash-woo-sync'),
                                $parent_term->name
                            ),
                        );
                    }
                } else {
                    $parent_navigation = array(
                        'url' => esc_url($filter_url_base),
                        'label' => __('Back to all categories', 'bytemash-woo-sync'),
                    );
                }
                
                // Get direct child categories
                $categories_to_show = $get_terms_cached(array(
                    'parent' => $current_category->term_id,
                    'hide_empty' => $hide_empty,
                ));
            }
        }
        // Check if we're on a single product page (only if no filter is set)
        elseif (!$filtered_category_id && is_product()) {
            global $product;
            if ($product) {
                $product_categories = wp_get_post_terms($product->get_id(), 'product_cat', array('orderby' => 'parent', 'order' => 'ASC'));
                
                if (!empty($product_categories) && !is_wp_error($product_categories)) {
                    // Get the primary category (first one, or deepest one)
                    $primary_category = $product_categories[0];
                    $current_category_id = $primary_category->term_id; // Highlight this category
                    $current_category = $primary_category;
                    
                    if (isset($primary_category->parent) && $primary_category->parent) {
                        $parent_term = get_term($primary_category->parent, 'product_cat');
                        if ($parent_term && !is_wp_error($parent_term)) {
                            $parent_navigation = array(
                                'url' => $build_filter_url($parent_term->slug),
                                'label' => sprintf(
                                    __('Back to %s', 'bytemash-woo-sync'),
                                    $parent_term->name
                                ),
                            );
                        }
                    } else {
                        $parent_navigation = array(
                            'url' => esc_url($filter_url_base),
                            'label' => __('Back to all categories', 'bytemash-woo-sync'),
                        );
                    }
                    
                    // Show children of the primary category
                    $categories_to_show = $get_terms_cached(array(
                        'parent' => $primary_category->term_id,
                        'hide_empty' => $hide_empty,
                    ));
                }
            }
        }
        
        // Fallback: Show top-level categories if nothing found yet
        if ((empty($categories_to_show) || is_wp_error($categories_to_show)) && $fallback) {
            // Show top-level categories
            $categories_to_show = $get_terms_cached(array(
                'parent' => 0,
                'hide_empty' => $hide_empty,
            ));
        }
        
        // If still no categories found, try one more fallback - show all top-level categories
        if ((empty($categories_to_show) || is_wp_error($categories_to_show)) && $fallback) {
            // Last resort: show all top-level categories regardless of empty status
            $categories_to_show = $get_terms_cached(array(
                'parent' => 0,
                'hide_empty' => false, // Force show all
            ));
        }
        
        // Normalize main category list (dedupe, keep zero-count for now)
        $categories_to_show = $normalize_terms($categories_to_show, false);
        
        // Debug output if enabled
        if ($debug) {
            $debug_info = array(
                'is_tax' => is_tax('product_cat'),
                'is_product' => is_product(),
                'is_shop' => is_shop(),
                'current_category_id' => $current_category_id,
                'categories_count' => is_array($categories_to_show) ? count($categories_to_show) : 0,
                'hide_empty' => $hide_empty,
            );
            return '<!-- Category Filter Debug: ' . wp_json_encode($debug_info) . ' -->';
        }
        
        // Build accordion structure
        $categories_with_children = array();
        
        // If we have a current/filtered category, show IT with its children in the accordion
        if ($current_category && isset($current_category->term_id)) {
            // Get children of the current category
            $child_categories = $get_terms_cached(array(
                'parent' => $current_category->term_id,
                'hide_empty' => $hide_empty,
            ));
            
            $child_categories = $normalize_terms($child_categories, true);
            
            $categories_with_children[] = array(
                'category' => $current_category,
                'children' => $child_categories,
            );
        } else {
            // Fallback: show categories_to_show with their children
            foreach ($categories_to_show as $category) {
                if (is_wp_error($category)) continue;
                
                $child_categories = $get_terms_cached(array(
                    'parent' => $category->term_id,
                    'hide_empty' => $hide_empty,
                ));
                
                $child_categories = $normalize_terms($child_categories, true);
                
                $categories_with_children[] = array(
                    'category' => $category,
                    'children' => $child_categories,
                );
            }
        }
        
        // If still no categories found, return empty
        if (empty($categories_with_children)) {
            return '';
        }
        
        // Detect if Bricks is active
        $is_bricks_active = class_exists('Bricks\\Database') || 
                           has_filter('bricks/posts/query_vars') || 
                           defined('BRICKS_VERSION') ||
                           function_exists('bricks_is_builder') ||
                           (function_exists('is_plugin_active') && is_plugin_active('bricks/bricks.php'));
        
        ob_start();
        ?>
        <div class="amrod-category-filter-accordion" 
             data-bricks-active="<?php echo $is_bricks_active ? '1' : '0'; ?>"
             style="margin: 20px 0;">
            <?php if (!empty($atts['title'])): ?>
                <h3 class="amrod-category-filter-title" style="margin: 0 0 20px 0; font-size: 20px; font-weight: 600; color: #212529; padding-bottom: 10px; border-bottom: 2px solid #e9ecef;">
                    <?php echo esc_html($atts['title']); ?>
                </h3>
            <?php endif; ?>
            
            <?php if ($parent_navigation): ?>
                <div class="amrod-category-filter-back" style="margin-bottom: 15px; display: inline-flex; align-items: center; gap: 8px;">
                    <a href="<?php echo esc_url($parent_navigation['url']); ?>"
                       class="amrod-category-back-link"
                       style="text-decoration: none; color: #007bff; font-weight: 500; display: inline-flex; align-items: center; gap: 6px;">
                        <span aria-hidden="true" style="font-size: 18px; line-height: 1;">←</span>
                        <span><?php echo esc_html($parent_navigation['label']); ?></span>
                    </a>
                </div>
            <?php endif; ?>
            
            <div class="amrod-accordion-container">
                <?php foreach ($categories_with_children as $index => $item): 
                    $category = $item['category'];
                    $children = isset($item['children']) && is_array($item['children']) ? $item['children'] : array();
                    $has_children = !empty($children);
                    $raw_category_count = isset($category->count) ? (int) $category->count : 0;
                    
                    $child_total_count = 0;
                    if ($has_children) {
                        foreach ($children as $child_term) {
                            if (is_wp_error($child_term)) {
                                continue;
                            }
                            $child_total_count += isset($child_term->count) ? (int) $child_term->count : 0;
                        }
                    }
                    
                    $display_category_count = $has_children ? $child_total_count : $raw_category_count;
                    if ($display_category_count <= 0 && !$has_children) {
                        $display_category_count = $raw_category_count;
                    }
                    
                    // Skip categories that have zero products and no visible children
                    if ($raw_category_count <= 0 && !$has_children) {
                        continue;
                    }
                    
                    if (is_wp_error($category)) continue;
                    
                    $is_current = ($current_category_id && $current_category_id === $category->term_id);
                    $accordion_id = 'amrod-accordion-' . $category->term_id;
                    // Always expand if it's the current/filtered category, or if it has children and is the first item
                    $is_expanded = $is_current || ($index === 0 && $has_children);
                ?>
                    <div class="amrod-accordion-item" style="margin-bottom: 8px; border: 1px solid #e9ecef; border-radius: 8px; overflow: hidden; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: all 0.3s ease;">
                        <div class="amrod-accordion-header" 
                             style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; background: <?php echo $is_current ? '#007bff' : '#fff'; ?>; color: <?php echo $is_current ? '#fff' : '#212529'; ?>; transition: all 0.3s ease; user-select: none;">
                            <a href="<?php echo $build_filter_url($category->slug); ?>" 
                               data-category-id="<?php echo esc_attr($category->term_id); ?>"
                               data-category-slug="<?php echo esc_attr($category->slug); ?>"
                               class="amrod-accordion-link amrod-filter-link" 
                               style="flex: 1; text-decoration: none; color: inherit; font-weight: 500; font-size: 15px; display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <span class="category-name"><?php echo esc_html($category->name); ?></span>
                                <?php if ($show_count): ?>
                                    <span class="count" style="font-size: 0.85em; opacity: 0.7; font-weight: 400;">
                                        (<?php echo number_format(max(0, $display_category_count)); ?>)
                                    </span>
                                <?php endif; ?>
                            </a>
                            <?php if ($has_children): ?>
                                <span class="amrod-accordion-toggle" 
                                      data-accordion-id="<?php echo esc_attr($accordion_id); ?>"
                                      style="margin-left: 12px; font-size: 12px; transition: transform 0.3s ease; display: inline-block; color: <?php echo $is_current ? '#fff' : '#6c757d'; ?>; cursor: pointer; padding: 4px 8px; user-select: none;">
                                    ▼
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($has_children): ?>
                            <div class="amrod-accordion-content" 
                                 id="<?php echo esc_attr($accordion_id); ?>"
                                 style="max-height: <?php echo $is_expanded ? '1000px' : '0'; ?>; overflow: hidden; transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1), padding 0.4s ease; padding: <?php echo $is_expanded ? '12px 16px' : '0 16px'; ?>; background: #f8f9fa;">
                                <ul style="list-style: none; margin: 0; padding: 0;">
                                    <?php foreach ($children as $child): 
                                        if (is_wp_error($child)) continue;
                                        $child_count = isset($child->count) ? (int) $child->count : 0;
                                        if ($child_count <= 0) continue;
                                        
                                        $is_child_current = ($current_category_id && $current_category_id === $child->term_id);
                                    ?>
                                        <li style="margin-bottom: 6px;">
                                        <a href="<?php echo $build_filter_url($child->slug); ?>" 
                                               data-category-id="<?php echo esc_attr($child->term_id); ?>"
                                               data-category-slug="<?php echo esc_attr($child->slug); ?>"
                                               class="amrod-accordion-child-link amrod-filter-link"
                                               style="display: block; padding: 10px 12px; color: <?php echo $is_child_current ? '#fff' : '#495057'; ?>; text-decoration: none; background: <?php echo $is_child_current ? '#007bff' : '#fff'; ?>; border: 1px solid <?php echo $is_child_current ? '#007bff' : '#e9ecef'; ?>; border-radius: 6px; transition: all 0.2s ease; font-size: 14px; cursor: pointer;">
                                                <span class="category-name"><?php echo esc_html($child->name); ?></span>
                                                <?php if ($show_count): ?>
                                                    <span class="count" style="float: right; font-size: 0.85em; opacity: 0.7;">
                                                        (<?php echo number_format($child->count); ?>)
                                                    </span>
                                                <?php endif; ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <style>
            .amrod-accordion-header:hover {
                background: #f8f9fa !important;
            }
            .amrod-accordion-header[style*="background: rgb(0, 123, 255)"]:hover {
                background: #0056b3 !important;
            }
            .amrod-accordion-item:hover {
                box-shadow: 0 4px 12px rgba(0,0,0,0.1) !important;
            }
            .amrod-accordion-child-link:hover {
                background: #e9ecef !important;
                border-color: #adb5bd !important;
                color: #212529 !important;
                transform: translateX(4px);
            }
            .amrod-accordion-child-link[style*="background: rgb(0, 123, 255)"]:hover {
                background: #0056b3 !important;
                border-color: #0056b3 !important;
                color: #fff !important;
            }
            .amrod-accordion-toggle.rotated {
                transform: rotate(180deg);
            }
            .amrod-accordion-toggle:hover {
                opacity: 0.7;
            }
        </style>
        
        <script>
        (function() {
            document.addEventListener('DOMContentLoaded', function() {
                // Get current filter category from URL (support slug + legacy formats)
                var urlParams = new URLSearchParams(window.location.search);
                var currentFilterCategorySlug = urlParams.get('product_cat');
                var legacyFilterCategoryId = urlParams.get('filter_category');
                var currentBricksCategory = null;
                
                // Check for Bricks format: brx_vqxomj[0]=slug
                if (urlParams.has('brx_vqxomj[0]')) {
                    currentBricksCategory = urlParams.get('brx_vqxomj[0]');
                } else {
                    // Try to parse from URL string directly (Bricks uses array format)
                    var urlString = window.location.search;
                    var bricksMatch = urlString.match(/brx_vqxomj%5B0%5D=([^&]+)/);
                    if (bricksMatch) {
                        currentBricksCategory = decodeURIComponent(bricksMatch[1]);
                    }
                }
                
                // Handle filter links
                var filterLinks = document.querySelectorAll('.amrod-filter-link');
                if (filterLinks && filterLinks.length > 0) {
                    filterLinks.forEach(function(link) {
                        // Safety check
                        if (!link || typeof link.getAttribute !== 'function') {
                            return;
                        }
                        
                        // Highlight active filter on page load
                        var categoryId = link.getAttribute('data-category-id');
                        var categorySlug = link.getAttribute('data-category-slug');
                        
                        // Check all supported parameter formats
                        var isActive = false;
                        if (currentFilterCategorySlug && categorySlug === currentFilterCategorySlug) {
                            isActive = true;
                        } else if (legacyFilterCategoryId && categoryId === legacyFilterCategoryId) {
                            isActive = true;
                        } else if (currentBricksCategory && categorySlug === currentBricksCategory) {
                            isActive = true;
                        }
                        
                        if (isActive) {
                            link.style.background = '#007bff';
                            link.style.color = '#fff';
                            if (link.style.borderColor !== undefined) {
                                link.style.borderColor = '#007bff';
                            }
                        }
                        
                    });
                }
                
                // Accordion toggle functionality - click on icon to toggle
                var accordionToggles = document.querySelectorAll('.amrod-accordion-toggle');
                if (accordionToggles && accordionToggles.length > 0) {
                    accordionToggles.forEach(function(toggle) {
                        // Safety check
                        if (!toggle || typeof toggle.getAttribute !== 'function') {
                            return;
                        }
                        
                        toggle.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            
                            var accordionId = toggle.getAttribute('data-accordion-id');
                            if (!accordionId) return;
                            
                            var content = document.getElementById(accordionId);
                            if (!content) return;
                            
                            var isExpanded = content.style.maxHeight && content.style.maxHeight !== '0px' && content.style.maxHeight !== '0';
                            
                            if (isExpanded) {
                                // Collapse
                                content.style.maxHeight = '0';
                                content.style.padding = '0 16px';
                                toggle.classList.remove('rotated');
                            } else {
                                // Expand
                                // Close other accordions first
                                accordionToggles.forEach(function(otherToggle) {
                                    if (otherToggle !== toggle && otherToggle && typeof otherToggle.getAttribute === 'function') {
                                        var otherId = otherToggle.getAttribute('data-accordion-id');
                                        if (otherId) {
                                            var otherContent = document.getElementById(otherId);
                                            if (otherContent) {
                                                otherContent.style.maxHeight = '0';
                                                otherContent.style.padding = '0 16px';
                                                otherToggle.classList.remove('rotated');
                                            }
                                        }
                                    }
                                });
                                
                                // Expand this one
                                content.style.maxHeight = content.scrollHeight + 'px';
                                content.style.padding = '12px 16px';
                                toggle.classList.add('rotated');
                            }
                        });
                    });
                }
                
                // Set initial state for expanded items (expand if contains active filter or was initially expanded)
                accordionToggles.forEach(function(toggle) {
                    var accordionId = toggle.getAttribute('data-accordion-id');
                    var content = document.getElementById(accordionId);
                    if (content) {
                        var hasActiveChild = null;
                        if (currentFilterCategorySlug) {
                            hasActiveChild = content.querySelector('[data-category-slug="' + currentFilterCategorySlug + '"]');
                        } else if (legacyFilterCategoryId) {
                            hasActiveChild = content.querySelector('[data-category-id="' + legacyFilterCategoryId + '"]');
                        }
                        var isExpanded = content.style.maxHeight && content.style.maxHeight !== '0px' && content.style.maxHeight !== '0';
                        
                        if (hasActiveChild || isExpanded) {
                            content.style.maxHeight = content.scrollHeight + 'px';
                            content.style.padding = '12px 16px';
                            toggle.classList.add('rotated');
                        }
                    }
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Make products purchasable even without prices
     * This allows products to be orderable even if price sync is disabled or hasn't run yet
     * Respects the purchasability mode setting
     * 
     * @param bool $purchasable Whether product is purchasable
     * @param WC_Product $product Product object
     * @return bool Modified purchasable status
     */
    public function make_products_purchasable_without_price($purchasable, $product) {
        // If already purchasable, return as-is
        if ($purchasable) {
            return $purchasable;
        }
        
        // Get purchasability mode setting (default: standard WooCommerce behavior)
        $purchasability_mode = get_option('bytemash_allow_orders_without_price', 'default');
        
        // Default mode: only allow if price exists (standard WooCommerce behavior)
        if ($purchasability_mode === 'default') {
            return $purchasable;
        }
        
        $stock_quantity = $product->get_stock_quantity();
        
        // Force all mode: allow ALL products regardless of price or stock
        if ($purchasability_mode === 'force_all') {
            // Set a placeholder price of 0 to allow adding to cart
            if ($product->get_price() === '' || $product->get_price() === null) {
                $product->set_price('0');
                $product->set_regular_price('0');
            }
            return true;
        }
        
        // Force with stock mode: only allow if product has stock quantity > 0
        if ($purchasability_mode === 'force_with_stock') {
            // Check if product has stock
            if ($stock_quantity !== null && $stock_quantity > 0) {
                // Set a placeholder price of 0 to allow adding to cart
                if ($product->get_price() === '' || $product->get_price() === null) {
                    $product->set_price('0');
                    $product->set_regular_price('0');
                }
                return true;
            }
        }
        
        return $purchasable;
    }
    
    /**
     * Make variations purchasable even without prices
     * Respects the purchasability mode setting
     * 
     * @param bool $purchasable Whether variation is purchasable
     * @param WC_Product_Variation $variation Variation object
     * @return bool Modified purchasable status
     */
    public function make_variations_purchasable_without_price($purchasable, $variation) {
        // If quote mode is enabled, make ALL variations purchasable
        if ($this->is_quote_mode_enabled()) {
            return true;
        }
        
        // If already purchasable, return as-is
        if ($purchasable) {
            return $purchasable;
        }
        
        // Get purchasability mode setting (default: standard WooCommerce behavior)
        $purchasability_mode = get_option('bytemash_allow_orders_without_price', 'default');
        
        // Default mode: only allow if price exists (standard WooCommerce behavior)
        if ($purchasability_mode === 'default') {
            return $purchasable;
        }
        
        $stock_quantity = $variation->get_stock_quantity();
        
        // Force all mode: allow ALL variations regardless of price or stock
        if ($purchasability_mode === 'force_all') {
            // Set a placeholder price of 0 to allow adding to cart
            if ($variation->get_price() === '' || $variation->get_price() === null) {
                $variation->set_price('0');
                $variation->set_regular_price('0');
            }
            return true;
        }
        
        // Force with stock mode: only allow if variation has stock quantity > 0
        if ($purchasability_mode === 'force_with_stock') {
            // Check if variation has stock
            if ($stock_quantity !== null && $stock_quantity > 0) {
                // Set a placeholder price of 0 to allow adding to cart
                if ($variation->get_price() === '' || $variation->get_price() === null) {
                    $variation->set_price('0');
                    $variation->set_regular_price('0');
                }
                return true;
            }
        }
        
        return $purchasable;
    }
    
    /**
     * Force in stock status when product has stock quantity
     * WooCommerce by default sets products as out of stock if they don't have a price
     * This fixes that behavior for Amrod products
     * 
     * @param bool $is_in_stock Whether product is in stock
     * @param WC_Product $product Product object
     * @return bool Modified stock status
     */
    public function force_in_stock_when_has_stock($is_in_stock, $product) {
        // Get purchasability mode setting
        $purchasability_mode = get_option('bytemash_allow_orders_without_price', 'default');
        
        // Default mode: use standard WooCommerce behavior
        if ($purchasability_mode === 'default') {
            // Only modify for quote mode if it's actually enabled
            if ($this->is_quote_mode_enabled()) {
                // In quote mode, if product has no price, make it appear in stock for quote requests
                $price = $product->get_price();
                if (empty($price) || $price === '' || $price === null || $price === '0' || $price === 0) {
                    $product_id = $product->get_id();
                    $amrod_code = get_post_meta($product_id, '_amrod_simple_code', true);
                    if (!empty($amrod_code)) {
                        return true;
                    }
                }
            }
            return $is_in_stock;
        }
        
        // Force all mode: force all products to be in stock (for quote requests)
        if ($purchasability_mode === 'force_all') {
            return true;
        }
        
        // Force with stock mode: check both WooCommerce stock and Amrod stock data
        if ($purchasability_mode === 'force_with_stock') {
            $product_id = $product->get_id();
            
            // Check WooCommerce stock quantity
            $stock_quantity = $product->get_stock_quantity();
            if ($stock_quantity !== null && $stock_quantity > 0) {
                return true;
            }
            
            // Also check Amrod stock detail (for products without WooCommerce stock set)
            $stock_detail = get_post_meta($product_id, '_amrod_stock_detail', true);
            if (!empty($stock_detail) && is_array($stock_detail)) {
                $amrod_stock = isset($stock_detail['stock']) ? intval($stock_detail['stock']) : 0;
                if ($amrod_stock > 0) {
                    return true;
                }
            }
            
            // For variable products, check variations
            if ($product->is_type('variable')) {
                $variations = $product->get_children();
                foreach ($variations as $variation_id) {
                    $variation_stock = get_post_meta($variation_id, '_stock', true);
                    if ($variation_stock !== null && intval($variation_stock) > 0) {
                        return true;
                    }
                    // Check Amrod stock detail for variation
                    $variation_stock_detail = get_post_meta($variation_id, '_amrod_stock_detail', true);
                    if (!empty($variation_stock_detail) && is_array($variation_stock_detail)) {
                        $variation_amrod_stock = isset($variation_stock_detail['stock']) ? intval($variation_stock_detail['stock']) : 0;
                        if ($variation_amrod_stock > 0) {
                            return true;
                        }
                    }
                }
            }
        }
        
        return $is_in_stock;
    }
    
    /**
     * Force stock status to 'instock' when product has stock (for quote requests)
     * This filter handles the stock_status property directly
     * 
     * @param string $stock_status Current stock status ('instock', 'outofstock', 'onbackorder')
     * @param WC_Product $product Product object
     * @return string Modified stock status
     */
    public function force_stock_status_when_has_stock($stock_status, $product) {
        // If already in stock, return as-is
        if ($stock_status === 'instock') {
            return $stock_status;
        }
        
        // Get purchasability mode setting
        $purchasability_mode = get_option('bytemash_allow_orders_without_price', 'default');
        
        // Default mode: use standard WooCommerce behavior (but still check for quote requests)
        if ($purchasability_mode === 'default') {
            // Even in default mode, if product has no price, allow quote requests by making it in stock
            $price = $product->get_price();
            if (empty($price) || $price === '' || $price === null || $price === '0' || $price === 0) {
                // Check if it's an Amrod product (has Amrod meta)
                $product_id = $product->get_id();
                $amrod_code = get_post_meta($product_id, '_amrod_simple_code', true);
                if (!empty($amrod_code)) {
                    // It's an Amrod product without price - allow quote requests by making it appear in stock
                    return 'instock';
                }
            }
            return $stock_status;
        }
        
        // Force all mode: force all products to be in stock (for quote requests)
        if ($purchasability_mode === 'force_all') {
            return 'instock';
        }
        
        // Force with stock mode: check both WooCommerce stock and Amrod stock data
        if ($purchasability_mode === 'force_with_stock') {
            $product_id = $product->get_id();
            
            // Check WooCommerce stock quantity
            $stock_quantity = $product->get_stock_quantity();
            if ($stock_quantity !== null && $stock_quantity > 0) {
                return 'instock';
            }
            
            // Also check Amrod stock detail (for products without WooCommerce stock set)
            $stock_detail = get_post_meta($product_id, '_amrod_stock_detail', true);
            if (!empty($stock_detail) && is_array($stock_detail)) {
                $amrod_stock = isset($stock_detail['stock']) ? intval($stock_detail['stock']) : 0;
                if ($amrod_stock > 0) {
                    return 'instock';
                }
            }
            
            // For variable products, check variations
            if ($product->is_type('variable')) {
                $variations = $product->get_children();
                foreach ($variations as $variation_id) {
                    $variation_stock = get_post_meta($variation_id, '_stock', true);
                    if ($variation_stock !== null && intval($variation_stock) > 0) {
                        return 'instock';
                    }
                    // Check Amrod stock detail for variation
                    $variation_stock_detail = get_post_meta($variation_id, '_amrod_stock_detail', true);
                    if (!empty($variation_stock_detail) && is_array($variation_stock_detail)) {
                        $variation_amrod_stock = isset($variation_stock_detail['stock']) ? intval($variation_stock_detail['stock']) : 0;
                        if ($variation_amrod_stock > 0) {
                            return 'instock';
                        }
                    }
                }
            }
        }
        
        return $stock_status;
    }
    
    /**
     * Hide the "out of stock" message for products that should allow quote requests
     * This prevents WooCommerce from showing "This product is currently out of stock and unavailable."
     * 
     * @param string $html Stock HTML message
     * @param WC_Product $product Product object
     * @return string Modified stock HTML (empty string to hide message)
     */
    public function hide_out_of_stock_message_for_quote_requests($html, $product) {
        // Only hide out of stock messages if quote mode is enabled
        // When quote mode is OFF, use normal WooCommerce stock messages
        if (!$this->is_quote_mode_enabled()) {
            return $html;
        }
        
        // Get purchasability mode setting
        $purchasability_mode = get_option('bytemash_allow_orders_without_price', 'default');
        
        // Check if product has no price
        $price = $product->get_price();
        $has_no_price = empty($price) || $price === '' || $price === null || $price === '0' || $price === 0;
        
        // Check if it's an Amrod product
        $product_id = $product->get_id();
        $amrod_code = get_post_meta($product_id, '_amrod_simple_code', true);
        $is_amrod_product = !empty($amrod_code);
        
        // If it's an Amrod product without price, hide the out of stock message (for quote mode)
        if ($is_amrod_product && $has_no_price) {
            // Force all mode: always hide the message
            if ($purchasability_mode === 'force_all') {
                return '';
            }
            
            // Force with stock mode: hide if product has stock
            if ($purchasability_mode === 'force_with_stock') {
                // Check WooCommerce stock
                $stock_quantity = $product->get_stock_quantity();
                if ($stock_quantity !== null && $stock_quantity > 0) {
                    return '';
                }
                
                // Check Amrod stock detail
                $stock_detail = get_post_meta($product_id, '_amrod_stock_detail', true);
                if (!empty($stock_detail) && is_array($stock_detail)) {
                    $amrod_stock = isset($stock_detail['stock']) ? intval($stock_detail['stock']) : 0;
                    if ($amrod_stock > 0) {
                        return '';
                    }
                }
                
                // For variable products, check variations
                if ($product->is_type('variable')) {
                    $variations = $product->get_children();
                    foreach ($variations as $variation_id) {
                        $variation_stock = get_post_meta($variation_id, '_stock', true);
                        if ($variation_stock !== null && intval($variation_stock) > 0) {
                            return '';
                        }
                        $variation_stock_detail = get_post_meta($variation_id, '_amrod_stock_detail', true);
                        if (!empty($variation_stock_detail) && is_array($variation_stock_detail)) {
                            $variation_amrod_stock = isset($variation_stock_detail['stock']) ? intval($variation_stock_detail['stock']) : 0;
                            if ($variation_amrod_stock > 0) {
                                return '';
                            }
                        }
                    }
                }
            }
            
            // Default mode: still hide for Amrod products without price (to allow quote requests)
            if ($purchasability_mode === 'default') {
                return '';
            }
        }
        
        return $html;
    }
    
    /**
     * Check if quote mode is enabled
     */
    private function is_quote_mode_enabled() {
        return get_option('bytemash_quote_mode_enabled', false) === true || get_option('bytemash_quote_mode_enabled', false) === '1';
    }
    
    /**
     * Add wrapper div for quote mode (allows styling while keeping form visible)
     */
    public function maybe_add_quote_mode_wrapper() {
        if (!$this->is_quote_mode_enabled()) {
            return;
        }
        
        // Output CSS to hide ONLY the add to cart button, keep variations and branding visible
        echo '<style type="text/css">';
        echo '.bytemash-quote-mode-active .single_add_to_cart_button { display: none !important; }';
        echo '.bytemash-quote-mode-active .variations_button { display: none !important; }';
        echo '.bytemash-quote-mode-active form.cart { display: block !important; }';
        echo '.bytemash-quote-mode-active .variations { display: block !important; }';
        echo '.bytemash-quote-mode-active .variations_form { display: block !important; }';
        echo '#bytemash-request-quote-btn, #bytemash-request-quote-btn-fallback { display: block !important; visibility: visible !important; }';
        echo '</style>';
        
        // Start wrapper div
        echo '<div class="bytemash-quote-mode-active bytemash-custom-quote-form">';
    }
    
    /**
     * Hide add to cart button in quote mode (but keep variation form and branding visible)
     */
    public function maybe_hide_add_to_cart_in_quote_mode() {
        // This is handled by CSS in maybe_add_quote_mode_wrapper
        // This hook is kept for compatibility but doesn't need to do anything
    }
    
    /**
     * Replace add to cart button with quote request button
     */
    public function maybe_replace_add_to_cart_with_quote_button() {
        if (!$this->is_quote_mode_enabled()) {
            return;
        }
        
        // Use static flag to prevent duplicate rendering
        static $quote_button_rendered = false;
        if ($quote_button_rendered) {
            return;
        }
        $quote_button_rendered = true;
        
        // Add quote request button after the add to cart button (which is hidden by CSS)
        echo '<div class="bytemash-quote-submit" style="margin: 20px 0; clear: both; display: block !important;">';
        echo '<button type="button" id="bytemash-request-quote-btn" class="button alt" style="width: 100%; padding: 15px; font-size: 16px; display: block !important; visibility: visible !important;">';
        echo esc_html__('Make Quote Request', 'bytemash-woo-sync');
        echo '</button>';
        echo '<div id="bytemash-quote-request-message" style="margin-top: 10px; display: none;"></div>';
        echo '</div>';
    }
    
    /**
     * Close wrapper div for quote mode
     */
    public function maybe_close_quote_mode_wrapper() {
        if (!$this->is_quote_mode_enabled()) {
            return;
        }
        
        // Close wrapper div
        echo '</div>';
    }
    
    /**
     * Show all variations in quote mode (even if they would normally be hidden)
     */
    public function show_all_variations_in_quote_mode($hide, $variation_id, $variation) {
        if (!$this->is_quote_mode_enabled()) {
            return $hide;
        }
        
        // Don't hide any variations in quote mode
        return false;
    }
    
    /**
     * Include all variations in children array in quote mode
     */
    public function include_all_variations_in_quote_mode($children, $product) {
        if (!$this->is_quote_mode_enabled()) {
            return $children;
        }
        
        // If it's a variable product, get ALL variation IDs (not just visible ones)
        if ($product && $product->is_type('variable')) {
            $all_variation_ids = $product->get_children();
            // Return all variations, not just purchasable/visible ones
            return $all_variation_ids;
        }
        
        return $children;
    }
    
    /**
     * Make all variations visible in quote mode
     */
    public function make_all_variations_visible_in_quote_mode($visibility, $variation) {
        if (!$this->is_quote_mode_enabled()) {
            return $visibility;
        }
        
        // Make all variations visible in quote mode
        return 'visible';
    }
    
    /**
     * Make all variations visible (for visibility filter)
     */
    public function make_all_variations_visible_in_quote_mode_visibility($visible, $variation_id, $id, $variation) {
        if (!$this->is_quote_mode_enabled()) {
            return $visible;
        }
        
        // Make all variations visible in quote mode
        return true;
    }
    
    /**
     * Include all variations in available variation data (for JavaScript)
     */
    public function include_all_variations_in_available_data($variation_data, $product, $variation) {
        if (!$this->is_quote_mode_enabled()) {
            return $variation_data;
        }
        
        // If variation data is empty or null, create it so the variation appears
        if (empty($variation_data) || !is_array($variation_data)) {
            // Build basic variation data so it appears in the form
            $variation_data = array(
                'variation_id' => $variation->get_id(),
                'attributes' => $variation->get_variation_attributes(),
                'price_html' => '',
                'availability_html' => '',
                'image' => array(),
                'is_purchasable' => true,
                'is_in_stock' => true,
                'is_visible' => true,
            );
        }
        
        // Ensure variation is always visible and purchasable in quote mode
        $variation_data['is_purchasable'] = true;
        $variation_data['is_in_stock'] = true;
        $variation_data['is_visible'] = true;
        
        return $variation_data;
    }

    /**
     * Inject external image data for variations so swatches update gallery correctly
     */
    public function inject_external_variation_images($variation_data, $product, $variation) {
        if (!$variation || !is_a($variation, 'WC_Product_Variation')) {
            return $variation_data;
        }

        $variation_id = $variation->get_id();

        $external_url = get_post_meta($variation_id, '_thumbnail_external_url', true);
        if (empty($external_url)) {
            $external_url = get_post_meta($variation_id, '_amrod_variation_image', true);
        }

        if (!empty($external_url)) {
            $image_payload = $this->build_external_image_payload($external_url, $variation_id, $variation);
            if (!is_array($variation_data)) {
                $variation_data = array();
            }

            $variation_data['image'] = array_merge(
                isset($variation_data['image']) && is_array($variation_data['image']) ? $variation_data['image'] : array(),
                $image_payload
            );
            $variation_data['image_id'] = $image_payload['id'];
        }

        $gallery = get_post_meta($variation_id, '_amrod_variation_gallery', true);
        if (is_array($gallery) && !empty($gallery)) {
            $variation_data['bytemash_variation_gallery'] = array_values(array_unique($gallery));
        } else {
            unset($variation_data['bytemash_variation_gallery']);
        }

        return $variation_data;
    }

    /**
     * Build WooCommerce-compatible image payload for external variation images
     */
    private function build_external_image_payload($url, $variation_id, $variation = null) {
        $safe_url = esc_url($url);
        $alt = $variation ? $variation->get_name() : '';
        if (empty($alt)) {
            $alt = get_the_title($variation_id);
        }

        $id = 'external_var_' . $variation_id;

        return array(
            'id' => $id,
            'src' => $safe_url,
            'url' => $safe_url,
            'thumb_src' => $safe_url,
            'full_src' => $safe_url,
            'gallery_thumbnail_src' => $safe_url,
            'src_w' => 1024,
            'src_h' => 1024,
            'full_src_w' => 1024,
            'full_src_h' => 1024,
            'gallery_thumbnail_src_w' => 300,
            'gallery_thumbnail_src_h' => 300,
            'srcset' => $safe_url,
            'full_srcset' => $safe_url,
            'sizes' => '(max-width: 1024px) 100vw, 1024px',
            'full_sizes' => '(max-width: 1024px) 100vw, 1024px',
            'alt' => esc_attr($alt),
            'name' => $alt,
        );
    }
    
    /**
     * Set default price for Amrod products that don't have prices
     * This filter intercepts the price getter and returns 0 if price is empty
     * 
     * @param mixed $price Product price
     * @param WC_Product $product Product object
     * @return mixed Modified price
     */
    public function set_default_price_for_amrod_products($price, $product) {
        // Only apply to Amrod products
        $amrod_sku = get_post_meta($product->get_id(), '_amrod_simple_code', true);
        $amrod_full_code = get_post_meta($product->get_id(), '_amrod_full_code', true);
        
        if (!empty($amrod_sku) || !empty($amrod_full_code)) {
            // If price is empty or null, set to 0
            if (empty($price) || $price === null) {
                return '0';
            }
        }
        
        return $price;
    }
    
    /**
     * Maybe set product price before displaying add to cart button
     * This ensures the price is saved in the database
     */
    public function maybe_set_product_price() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        // Check if this is an Amrod product
        $amrod_sku = get_post_meta($product->get_id(), '_amrod_simple_code', true);
        $amrod_full_code = get_post_meta($product->get_id(), '_amrod_full_code', true);
        
        if (!empty($amrod_sku) || !empty($amrod_full_code)) {
            // If product doesn't have a price, set it to 0
            if (empty($product->get_price()) || $product->get_price() === null) {
                $product->set_price('0');
                $product->set_regular_price('0');
                $product->save();
            }
        }
    }
    
    /**
     * Hide price display when it's 0 (for Amrod products without prices)
     * 
     * @param string $price_html Price HTML
     * @param WC_Product $product Product object
     * @return string Modified price HTML
     */
    public function hide_zero_price_display($price_html, $product) {
        // Check if this is an Amrod product
        $amrod_sku = get_post_meta($product->get_id(), '_amrod_simple_code', true);
        $amrod_full_code = get_post_meta($product->get_id(), '_amrod_full_code', true);
        
        if (!empty($amrod_sku) || !empty($amrod_full_code)) {
            // If price is 0, show custom message instead
            $price = $product->get_price();
            if (empty($price) || $price === '0' || $price === 0) {
                return '<span class="price">Price on request</span>';
            }
        }
        
        return $price_html;
    }
    
    /**
     * Register custom order status for quote requests
     */
    public function register_quote_request_order_status() {
        register_post_status('wc-quote-request', array(
            'label' => __('Quote Request', 'bytemash-woo-sync'),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Quote Request <span class="count">(%s)</span>', 'Quote Requests <span class="count">(%s)</span>', 'bytemash-woo-sync'),
        ));
    }
    
    /**
     * Add quote request status to WooCommerce order statuses
     */
    public function add_quote_request_order_status($order_statuses) {
        $new_order_statuses = array();
        foreach ($order_statuses as $key => $status) {
            $new_order_statuses[$key] = $status;
            if ($key === 'wc-pending') {
                $new_order_statuses['wc-quote-request'] = __('Quote Request', 'bytemash-woo-sync');
            }
        }
        return $new_order_statuses;
    }
    
    /**
     * Render quote request button in quote mode
     * ONLY shows when quote mode is enabled
     * This ensures the button shows even if the add to cart form doesn't render properly
     */
    public function render_quote_request_button_fallback() {
        if (!$this->is_quote_mode_enabled()) {
            return;
        }
        
        // Always render the button - JavaScript will hide it if the main button already exists
        echo '<div class="bytemash-quote-request-fallback" style="margin: 20px 0; clear: both; display: block !important;">';
        echo '<button type="button" id="bytemash-request-quote-btn-fallback" class="button alt" style="width: 100%; padding: 15px; font-size: 16px; display: block !important; visibility: visible !important;">';
        echo esc_html__('Make Quote Request', 'bytemash-woo-sync');
        echo '</button>';
        echo '<div id="bytemash-quote-request-message-fallback" style="margin-top: 10px; display: none;"></div>';
        echo '</div>';
    }
    
    /**
     * Enqueue quote request assets
     */
    public function enqueue_quote_request_assets() {
        if (!is_product()) {
            return;
        }
        
        wp_enqueue_script('bytemash-quote-request', plugins_url('assets/js/quote-request.js', __FILE__), array('jquery'), BYTEMASH_WOO_SYNC_VERSION, true);
        
        // Get product properly - global $product might not be available at this hook point
        $product_id = get_the_ID();
        $product = wc_get_product($product_id);
        
        // Ensure we have a valid product ID
        if (!$product || !is_a($product, 'WC_Product')) {
            $product_id = 0;
        } else {
            $product_id = $product->get_id();
        }
        
        wp_localize_script('bytemash-quote-request', 'bytemashQuoteRequest', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bytemash_woo_sync_nonce'),
            'product_id' => $product_id,
            'strings' => array(
                'requesting' => __('Requesting quote...', 'bytemash-woo-sync'),
                'success' => __('Quote request submitted successfully! We will contact you soon.', 'bytemash-woo-sync'),
                'error' => __('Failed to submit quote request. Please try again.', 'bytemash-woo-sync'),
                'select_variation' => __('Please select a variation first.', 'bytemash-woo-sync'),
            ),
        ));
    }
    
    /**
     * AJAX: Submit quote request
     */
    public function ajax_submit_quote_request() {
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
        $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
        $selected_color = isset($_POST['color']) ? sanitize_text_field($_POST['color']) : '';
        $selected_size = isset($_POST['size']) ? sanitize_text_field($_POST['size']) : '';
        $brandings = isset($_POST['brandings']) ? $_POST['brandings'] : array();
        
        if (!$product_id) {
            wp_send_json_error(array('message' => __('Invalid product.', 'bytemash-woo-sync')));
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array('message' => __('Product not found.', 'bytemash-woo-sync')));
        }
        
        // VALIDATION: Check if variation is required for variable products
        if ($product->is_type('variable')) {
            if ($variation_id <= 0) {
                wp_send_json_error(array('message' => __('Please select a variation (color and size) before submitting your quote request.', 'bytemash-woo-sync')));
            }
            
            // Verify variation belongs to this product
            $variation = wc_get_product($variation_id);
            if (!$variation || $variation->get_parent_id() != $product_id) {
                wp_send_json_error(array('message' => __('Invalid variation selected.', 'bytemash-woo-sync')));
            }
        }
        
        // VALIDATION: Ensure quantity is valid
        if ($quantity <= 0) {
            $quantity = 1;
        }
        
        // Get current user info or use guest info
        $current_user = wp_get_current_user();
        $customer_email = $current_user->user_email;
        $customer_name = trim($current_user->first_name . ' ' . $current_user->last_name);
        
        if (empty($customer_email)) {
            // Try to get from POST if guest
            $customer_email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
            $customer_name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        }
        
        if (empty($customer_email)) {
            wp_send_json_error(array('message' => __('Email address is required.', 'bytemash-woo-sync')));
        }
        
        // Create WooCommerce order for quote request
        try {
            $order = wc_create_order();
            
            // Set customer
            if ($current_user->ID > 0) {
                $order->set_customer_id($current_user->ID);
            }
            $order->set_billing_email($customer_email);
            $order->set_billing_first_name(!empty($customer_name) ? $customer_name : __('Guest', 'bytemash-woo-sync'));
            
            // Determine which product to add
            $product_to_add = $product;
            if ($variation_id > 0) {
                $variation = wc_get_product($variation_id);
                if ($variation && $variation->get_parent_id() == $product_id) {
                    $product_to_add = $variation;
                }
            }
            
            // For quote requests, temporarily allow products without prices
            // Store original price if it exists
            $original_price = $product_to_add->get_price();
            $original_regular_price = $product_to_add->get_regular_price();
            $product_id_to_modify = $product_to_add->get_id();
            $has_price = !empty($original_price) && is_numeric($original_price) && floatval($original_price) > 0;
            
            // If no price or price is 0, set a temporary price of 0.01 to allow order creation
            // We'll set it to 0 after adding to order
            $price_was_changed = false;
            if (!$has_price) {
                // Set temporary price directly in meta first
                update_post_meta($product_id_to_modify, '_price', '0.01');
                update_post_meta($product_id_to_modify, '_regular_price', '0.01');
                update_post_meta($product_id_to_modify, '_sale_price', '');
                
                // Then update the product object
                $product_to_add->set_price('0.01');
                $product_to_add->set_regular_price('0.01');
                $product_to_add->set_sale_price('');
                $saved = $product_to_add->save();
                $price_was_changed = true;
                
                if (!$saved) {
                    // Restore on failure
                    update_post_meta($product_id_to_modify, '_price', $original_price ?: '');
                    update_post_meta($product_id_to_modify, '_regular_price', $original_regular_price ?: '');
                    update_post_meta($product_id_to_modify, '_sale_price', '');
                    throw new Exception(__('Failed to prepare product for order creation.', 'bytemash-woo-sync'));
                }
                
                // CRITICAL: Reload the product to ensure WooCommerce sees the updated price
                wc_delete_product_transients($product_id_to_modify);
                $product_to_add = wc_get_product($product_id_to_modify);
                
                // Verify price is set
                $verify_price = $product_to_add->get_price();
                if (empty($verify_price) || floatval($verify_price) <= 0) {
                    // Restore on failure
                    update_post_meta($product_id_to_modify, '_price', $original_price ?: '');
                    update_post_meta($product_id_to_modify, '_regular_price', $original_regular_price ?: '');
                    update_post_meta($product_id_to_modify, '_sale_price', '');
                    wc_delete_product_transients($product_id_to_modify);
                    throw new Exception(__('Failed to set product price for order creation.', 'bytemash-woo-sync'));
                }
            }
            
            // Add product to order
            try {
                $item_id = $order->add_product($product_to_add, $quantity);
            } catch (Exception $e) {
                // Restore original price if we changed it
                if ($price_was_changed) {
                    update_post_meta($product_id_to_modify, '_price', $original_price ?: '');
                    update_post_meta($product_id_to_modify, '_regular_price', $original_regular_price ?: '');
                    update_post_meta($product_id_to_modify, '_sale_price', '');
                    wc_delete_product_transients($product_id_to_modify);
                    $restore_product = wc_get_product($product_id_to_modify);
                    if ($restore_product) {
                        $restore_product->set_price($original_price ?: '');
                        $restore_product->set_regular_price($original_regular_price ?: '');
                        $restore_product->set_sale_price('');
                        $restore_product->save();
                    }
                }
                throw new Exception(__('Failed to add product to order: ', 'bytemash-woo-sync') . $e->getMessage());
            }
            
            // Restore original price (or remove it) if we changed it
            if ($price_was_changed) {
                update_post_meta($product_id_to_modify, '_price', $original_price ?: '');
                update_post_meta($product_id_to_modify, '_regular_price', $original_regular_price ?: '');
                update_post_meta($product_id_to_modify, '_sale_price', '');
                wc_delete_product_transients($product_id_to_modify);
                $restore_product = wc_get_product($product_id_to_modify);
                if ($restore_product) {
                    $restore_product->set_price($original_price ?: '');
                    $restore_product->set_regular_price($original_regular_price ?: '');
                    $restore_product->set_sale_price('');
                    $restore_product->save();
                }
            }
            
            if (!$item_id) {
                throw new Exception(__('Failed to add product to order.', 'bytemash-woo-sync'));
            }
            
            // Set line item price to 0 for quote requests (no price)
            if (!$has_price) {
                $order_item = $order->get_item($item_id);
                if ($order_item) {
                    $order_item->set_subtotal(0);
                    $order_item->set_total(0);
                    $order_item->save();
                }
            }
            
            // Add variation details as meta
            if ($variation_id > 0) {
                $order_item = $order->get_item($item_id);
                if ($order_item) {
                    if ($selected_color) {
                        $order_item->add_meta_data('Color', $selected_color, true);
                    }
                    if ($selected_size) {
                        $order_item->add_meta_data('Size', $selected_size, true);
                    }
                    $order_item->save();
                }
            }
            
            // Add branding options as meta with full text (not just codes)
            if (!empty($brandings) && is_array($brandings)) {
                $order_item = $order->get_item($item_id);
                if ($order_item) {
                    // Get full branding data from product meta to get names
                    $product_brandings = get_post_meta($product_id, '_amrod_brandings', true);
                    
                    foreach ($brandings as $pos_code => $codes) {
                        if (is_array($codes) && !empty($codes)) {
                            // Build display text with full names
                            $display_items = array();
                            
                            // Find position name
                            $position_name = '';
                            if (!empty($product_brandings) && is_array($product_brandings)) {
                                foreach ($product_brandings as $pos) {
                                    $pos_code_check = $pos['positionCode'] ?? '';
                                    if ($pos_code_check === $pos_code) {
                                        $position_name = $pos['positionName'] ?? '';
                                        break;
                                    }
                                }
                            }
                            
                            // For each selected code, find the full branding details
                            foreach ($codes as $code) {
                                $code = sanitize_text_field($code);
                                $branding_text = $code; // Default to code if not found
                                
                                // Look up full branding details
                                if (!empty($product_brandings) && is_array($product_brandings)) {
                                    foreach ($product_brandings as $pos) {
                                        if (($pos['positionCode'] ?? '') === $pos_code && !empty($pos['method']) && is_array($pos['method'])) {
                                            foreach ($pos['method'] as $method) {
                                                if (($method['brandingCode'] ?? '') === $code) {
                                                    $name = $method['brandingName'] ?? '';
                                                    $dept = $method['brandingDepartment'] ?? '';
                                                    $w = $method['maxPrintingSizeWidth'] ?? '';
                                                    $h = $method['maxPrintingSizeHeight'] ?? '';
                                                    
                                                    // Build full text: "Name (Department, Code) - W x H mm"
                                                    $branding_text = $name;
                                                    if ($dept) {
                                                        $branding_text .= ' (' . $dept;
                                                        if ($code) {
                                                            $branding_text .= ', ' . $code;
                                                        }
                                                        $branding_text .= ')';
                                                    } elseif ($code) {
                                                        $branding_text .= ' (' . $code . ')';
                                                    }
                                                    if ($w && $h) {
                                                        $branding_text .= ' - ' . $w . ' x ' . $h . ' mm';
                                                    }
                                                    break 2; // Break out of both loops
                                                }
                                            }
                                        }
                                    }
                                }
                                
                                $display_items[] = $branding_text;
                            }
                            
                            // Use position name if available, otherwise use position code
                            $meta_key = !empty($position_name) 
                                ? 'Branding: ' . sanitize_text_field($position_name)
                                : 'Branding ' . strtoupper(sanitize_text_field($pos_code));
                            
                            $order_item->add_meta_data($meta_key, implode('; ', $display_items), true);
                        }
                    }
                    $order_item->save();
                }
            }
            
            // Add quote request flag
            $order->add_meta_data('_bytemash_quote_request', 'yes', true);
            $order->add_meta_data('_bytemash_quote_request_date', current_time('mysql'), true);
            
            // Set order status (use full status name with wc- prefix)
            $order->set_status('wc-quote-request');
            
            // For quote requests without prices, set line item totals to 0 BEFORE calculating
            if (!$has_price) {
                $order_item = $order->get_item($item_id);
                if ($order_item) {
                    $order_item->set_subtotal(0);
                    $order_item->set_total(0);
                    $order_item->set_subtotal_tax(0);
                    $order_item->set_total_tax(0);
                    $order_item->save();
                }
            }
            
            // Calculate totals
            $order->calculate_totals();
            
            // For quote requests without prices, ensure order total is 0 AFTER calculation
            if (!$has_price) {
                // Set order total to 0 (only method available on WC_Order)
                $order->set_total(0);
            }
            
            // Save order
            $order->save();
            
            // Send email to admin
            $admin_email = get_option('bytemash_quote_admin_email', get_option('admin_email'));
            if (is_email($admin_email)) {
                $subject_tmpl = get_option('bytemash_quote_email_subject', 'New Quote Request #{quote_number}');
                $body_tmpl = get_option('bytemash_quote_email_template', "New quote request received.\n\nCustomer: {customer_name}\nQuote #: {quote_number}\n\nPlease check the admin dashboard for details.");
                
                // Prepare product summary
                $product_summary = $product->get_name() . ' (Qty: ' . $quantity . ')';
                if ($variation_id) {
                    $var_obj = wc_get_product($variation_id);
                    if ($var_obj) {
                        $product_summary .= ' - ' . wc_get_formatted_variation($var_obj, true);
                    }
                }
                
                $replacements = array(
                    '{customer_name}' => $order->get_formatted_billing_full_name(),
                    '{quote_number}' => $order->get_order_number(),
                    '{site_name}' => get_bloginfo('name'),
                    '{product_table}' => $product_summary,
                    '{branding_details}' => '' 
                );
                
                $subject = str_replace(array_keys($replacements), array_values($replacements), $subject_tmpl);
                $message = str_replace(array_keys($replacements), array_values($replacements), $body_tmpl);
                
                $headers = array('Content-Type: text/html; charset=UTF-8');
                
                // Simple NL2BR if no HTML tags detected
                if (strpos($message, '<') === false) {
                    $message = nl2br($message);
                }
                
                wp_mail($admin_email, $subject, $message, $headers);
            }
            
            // Log the quote request
            $logger = new ByteMash_Logger();
            $logger->log('info', 'Quote request submitted', array(
                'order_id' => $order->get_id(),
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'quantity' => $quantity,
                'customer_email' => $customer_email,
            ), 'quote_request');
            
            wp_send_json_success(array(
                'message' => __('Quote request submitted successfully! We will contact you soon.', 'bytemash-woo-sync'),
                'order_id' => $order->get_id(),
            ));
            
        } catch (Exception $e) {
            // Log the error for debugging
            $logger = new ByteMash_Logger();
            $logger->log('error', 'Quote request failed', array(
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ), 'quote_request');
            
            wp_send_json_error(array(
                'message' => __('Failed to submit quote request. Please try again.', 'bytemash-woo-sync'),
                'debug' => $e->getMessage() // Include in response for debugging (remove in production)
            ));
        }
    }

    /**
     * Retrieve the cache version for category filter term caching
     */
    private function get_category_filter_cache_version() {
        $version = (int) get_option('bytemash_category_filter_cache_version', 1);
        if ($version < 1) {
            $version = 1;
            update_option('bytemash_category_filter_cache_version', $version, false);
        }
        return $version;
    }
    
    /**
     * Bump the cache version so cached category filter data is regenerated
     */
    public function bust_category_filter_cache() {
        $version = (int) get_option('bytemash_category_filter_cache_version', 1);
        $version++;
        update_option('bytemash_category_filter_cache_version', $version, false);
    }
}

// Register plugin activation hook
register_activation_hook(__FILE__, array('ByteMash_Woo_Sync', 'register_activation'));

/**
 * Initialize the plugin
 */
function bytemash_woo_sync_init() {
    return ByteMash_Woo_Sync::get_instance();
}

// Start the plugin
bytemash_woo_sync_init();


// External image display fix for Product Collection blocks and all WooCommerce contexts
// Ensures WooCommerce, Gutenberg blocks, and Bricks Builder render external product images
// stored in custom meta `_external_image_url` by providing complete <img> HTML when present.
// This runs for shop grids, single product pages, and Gutenberg Product Collection blocks.
add_filter('woocommerce_product_get_image', 'bytemash_external_image_display_fix', 10, 6);
add_filter('woocommerce_blocks_product_grid_item_html', 'bytemash_gutenberg_product_collection_image_fix', 10, 3);

// Additional hook for Product Image block specifically
add_filter('render_block', 'bytemash_render_product_image_block', 10, 2);
/**
 * Filter WooCommerce product image HTML to use an external image when available.
 *
 * @param string     $image         The existing image HTML generated by WooCommerce.
 * @param WC_Product $product       The product object.
 * @param string|array $size        Requested image size (unused here; we emit a simple <img>).
 * @param array      $attr          Attributes array (ignored, we generate our own minimal set).
 * @param bool       $placeholder   Whether a placeholder was used by Woo (informational).
 * @param string     $image_class   CSS class string Woo would apply (we'll include it if present).
 * @return string                   The final image HTML.
 */
function bytemash_external_image_display_fix($image, $product, $size, $attr, $placeholder, $image_class) {
    // Validate product object
    if (!is_object($product) || !method_exists($product, 'get_id')) {
        return $image;
    }

    // Look for custom external image meta
    $external_url = get_post_meta($product->get_id(), '_external_image_url', true);
    if (empty($external_url)) {
        return $image; // Fallback to default when no external URL is set
    }

    // Build safe, minimal <img> with proper escaping and alt text
    $alt   = esc_attr($product->get_name());
    $src   = esc_url($external_url);
    $class = trim('woocommerce-product-image' . (is_string($image_class) && $image_class !== '' ? ' ' . $image_class : ''));

    // Keep HTML minimal to play nicely with Bricks/various themes and lazyloaders
    $html = '<img class="' . esc_attr($class) . '" src="' . $src . '" alt="' . $alt . '" loading="lazy" />';

    return $html;
}

/**
 * Fix product images in Gutenberg Product Collection blocks
 * The Product Collection block needs explicit image HTML injection
 */
function bytemash_gutenberg_product_collection_image_fix($html, $data, $product) {
    // Validate product object
    if (!is_object($product) || !method_exists($product, 'get_id')) {
        return $html;
    }

    // Look for external image URL
    $external_url = get_post_meta($product->get_id(), '_external_image_url', true);
    if (empty($external_url)) {
        return $html;
    }
    // Replace the image section in the HTML
    $alt = esc_attr($product->get_name());
    $src = esc_url($external_url);
    $new_image = '<img src="' . $src . '" alt="' . $alt . '" class="wp-block-woocommerce-product-collection-image" loading="lazy" />';

    // Try to replace existing image tag
    $html = preg_replace('/<img[^>]+>/', $new_image, $html, 1);

    return $html;
}

/**
 * Render external images in WooCommerce Product Image blocks
 * This handles the core/woocommerce blocks that render product images
 */
function bytemash_render_product_image_block($block_content, $block) {
    // Only process WooCommerce product image related blocks
    if (empty($block['blockName']) || 
        (strpos($block['blockName'], 'woocommerce/product-image') === false &&
         strpos($block['blockName'], 'core/post-featured-image') === false)) {
        return $block_content;
    }
    
    // Try to get product context
    global $product, $post;
    
    $product_id = 0;
    
    // Method 1: Global product
    if ($product && is_object($product) && method_exists($product, 'get_id')) {
        $product_id = $product->get_id();
    }
    
    // Method 2: Current post
    if (!$product_id) {
        $current_id = get_the_ID();
        if ($current_id && get_post_type($current_id) === 'product') {
            $product_id = $current_id;
        }
    }
    
    // Method 3: Global $post
    if (!$product_id && !empty($post) && $post->post_type === 'product') {
        $product_id = $post->ID;
    }
    
    if (!$product_id) {
        return $block_content;
    }
    
    // Check for external image
    $external_url = get_post_meta($product_id, '_external_image_url', true);
    if (empty($external_url)) {
        return $block_content;
    }
    
    // Get product for alt text
    if (!$product) {
        $product = wc_get_product($product_id);
    }
    
    $alt = $product ? esc_attr($product->get_name()) : 'Product';
    $src = esc_url($external_url);
    
    // Replace any existing img tag with our external image
    $new_image = '<img src="' . $src . '" alt="' . $alt . '" class="wp-post-image" loading="lazy" />';
    
    if (strpos($block_content, '<img') !== false) {
        $block_content = preg_replace('/<img[^>]+>/', $new_image, $block_content, 1);
    } else {
        // If no image exists in content, inject it
        $block_content = $new_image;
    }
    
    return $block_content;
}



// External URL image support for Bricks `{woo_product_images}` and theme overrides (no sideloading)
// 1) Make products with external images report as having a thumbnail
add_filter('has_post_thumbnail', function($has_thumbnail, $post) {
	if (!$post || get_post_type($post) !== 'product') {
		return $has_thumbnail;
	}
	
	// If already has thumbnail, return true
	if ($has_thumbnail) {
		return true;
	}
	
	// Check if product has external image
	$post_id = is_numeric($post) ? $post : $post->ID;
	$external = get_post_meta($post_id, '_external_image_url', true);
	
	// Return true if external image exists (so blocks know to render image)
	return !empty($external) ? true : $has_thumbnail;
}, 10, 2);

// 2) Neutralize fake/non-numeric thumbnail IDs so builders don't try to load them as attachments
add_filter('get_post_metadata', function ($value, $object_id, $meta_key, $single) {
	if ($meta_key !== '_thumbnail_id') return $value;
	if (get_post_type($object_id) !== 'product') return $value;
	$external = get_post_meta($object_id, '_external_image_url', true);
	if (empty($external)) return $value;
	// If stored thumb is a non-numeric marker (e.g., "external_123"), force 0 so templates fall back
	$raw = is_array($value) ? ($value[0] ?? null) : $value;
	if (is_string($raw) && !ctype_digit($raw)) {
		return $single ? 0 : array(0);
	}
	return $value;
}, 20, 4);

// 3) Safety net: if an <img> ends up with src="external_*", swap to the real external URL
add_filter('wp_get_attachment_image_attributes', function ($attr, $attachment, $size) {
	$id = is_string($attachment)
		? $attachment
		: (is_object($attachment) ? ($attachment->ID ?? '') : $attachment);
	if (!is_string($id) || strpos($id, 'external_') !== 0) return $attr;
	// Resolve current product context
	$product_id = 0;
	if (!empty($GLOBALS['product']) && is_object($GLOBALS['product']) && method_exists($GLOBALS['product'], 'get_id')) {
		$product_id = (int) $GLOBALS['product']->get_id();
	} elseif (!empty($GLOBALS['post']) && $GLOBALS['post']->post_type === 'product') {
		$product_id = (int) $GLOBALS['post']->ID;
	}
	if (!$product_id) return $attr;
	$external = get_post_meta($product_id, '_external_image_url', true);
	if (empty($external)) return $attr;
	$attr['src'] = esc_url($external);
	if (isset($attr['srcset'])) unset($attr['srcset']);
	return $attr;
}, 20, 3);

// 4) Fallback renderer for Bricks `{woo_product_images}` when no attachment is available
add_action('woo_product_images', function () {
	global $product;
	static $bytemash_external_images_rendered = false;
	if ($bytemash_external_images_rendered) return;
	if (!$product || !method_exists($product, 'get_id')) return;
	$external = get_post_meta($product->get_id(), '_external_image_url', true);
	if (empty($external)) return;
	// If there is no valid numeric thumbnail id, render external image
	$thumb = get_post_meta($product->get_id(), '_thumbnail_id', true);
	if (!ctype_digit((string) $thumb)) {
		echo '<img class="wp-post-image" src="' . esc_url($external) . '" alt="' . esc_attr($product->get_name()) . '" loading="lazy" />';
		$bytemash_external_images_rendered = true;
	}
}, 5);

// Bricks dynamic tag: Woo Product Thumbnail URL (returns plain URL for Image elements)
add_filter('bricks/dynamic_data/register', function($tags){
	$tags['woo_product_thumbnail_url'] = [
		'label'   => 'Woo Product Thumbnail URL',
		'context' => ['post','product'],
	];
	return $tags;
});

add_filter('bricks/dynamic_data/render', function($value, $tag, $context){
	if ($tag !== 'woo_product_thumbnail_url') return $value;

	$post_id = (is_object($context) && isset($context->post_id)) ? (int) $context->post_id : get_the_ID();

	// Prefer external URLs saved by sync
	$url = get_post_meta($post_id, '_thumbnail_external_url', true);
	if (!$url) $url = get_post_meta($post_id, '_external_image_url', true);

	// Fallback to real attachment URL if available
	if (!$url) {
		$thumb_id = get_post_thumbnail_id($post_id);
		if ($thumb_id) $url = wp_get_attachment_image_url($thumb_id, 'full');
	}

	return $url ? esc_url($url) : '';
}, 10, 3);

// Older Bricks API (pre-1.9): register & render the same tag
add_filter('bricks/dynamic_tags', function($tags){
	$tags['woo_product_thumbnail_url'] = [
		'label' => esc_html__('Woo Product Thumbnail URL', 'bytemash-woo-sync'),
		'type'  => 'text',
	];
	return $tags;
});

add_filter('bricks/dynamic_tag_render', function($value, $name){
	if ($name !== 'woo_product_thumbnail_url') return $value;

	$post_id = get_the_ID();
	$url = get_post_meta($post_id, '_thumbnail_external_url', true);
	if (!$url) $url = get_post_meta($post_id, '_external_image_url', true);
	if (!$url) {
		$thumb_id = get_post_thumbnail_id($post_id);
		if ($thumb_id) $url = wp_get_attachment_image_url($thumb_id, 'full');
	}
	return $url ? esc_url($url) : '';
}, 10, 2);

// Bricks dynamic data hook integration
// Expose a WooCommerce action hook as a Bricks dynamic data tag so it can be used inside Bricks fields.
// Configure the tag name and target Woo action here for reuse.
$bytemash_bricks_hook_tag_name = 'amrod_before_title'; // Use in Bricks as {amrod_before_title}
$bytemash_bricks_target_action = 'woocommerce_before_shop_loop_item_title'; // The WooCommerce hook to output

// Register the tag label so it appears in the Dynamic Data picker (newer API)
add_filter('bricks/dynamic_data/register', function($tags) use ($bytemash_bricks_hook_tag_name) {
	$tags[$bytemash_bricks_hook_tag_name] = [
		'label'   => 'Amrod: Before Product Title (Woo Hook)',
		'context' => ['post','product'],
	];
	return $tags;
});

// Register for older Bricks versions (pre-1.9)
add_filter('bricks/dynamic_tags', function($tags) use ($bytemash_bricks_hook_tag_name) {
	$tags[$bytemash_bricks_hook_tag_name] = [
		'label' => esc_html__('Amrod: Before Product Title (Woo Hook)', 'bytemash-woo-sync'),
		'type'  => 'text',
	];
	return $tags;
});

// Render the tag by executing the WooCommerce action and returning its captured HTML (newer API helper)
add_filter('bricks/dynamic_data/render_tag', function($value, $name, $context) use ($bytemash_bricks_hook_tag_name, $bytemash_bricks_target_action) {
	if ($name !== $bytemash_bricks_hook_tag_name) return $value;
	ob_start();
	// Execute the target WooCommerce action; any callbacks attached to this hook can print HTML
	do_action($bytemash_bricks_target_action);
	return ob_get_clean();
}, 10, 3);

// Also render via the older API for compatibility
add_filter('bricks/dynamic_tag_render', function($value, $name) use ($bytemash_bricks_hook_tag_name, $bytemash_bricks_target_action) {
	if ($name !== $bytemash_bricks_hook_tag_name) return $value;
	ob_start();
	do_action($bytemash_bricks_target_action);
	return ob_get_clean();
}, 10, 2);

// === Regenerate Bricks CSS Tool ===
// Adds a quick admin bar button for administrators to clear & regenerate Bricks compiled CSS
// (/wp-content/uploads/bricks/css). Includes nonce verification and success notice.

// 1) Admin bar button for admins
add_action('admin_bar_menu', function($wp_admin_bar) {
	if (!is_user_logged_in() || !current_user_can('manage_options') || !is_admin_bar_showing()) {
		return;
	}
	$nonce = wp_create_nonce('bytemash_regen_bricks_css');
	$url = add_query_arg(array(
		'action' => 'bytemash_regenerate_bricks_css',
		'_wpnonce' => $nonce,
	), admin_url('admin-post.php'));

	$wp_admin_bar->add_node(array(
		'id' => 'bytemash-regen-bricks-css',
		'parent' => false,
		'title' => 'Regenerate Bricks CSS',
		'href' => $url,
		'meta' => array('title' => 'Delete Bricks CSS cache & clear Bricks assets cache')
	));
}, 100);

// 2) Handler to delete CSS files & clear Bricks cache
add_action('admin_post_bytemash_regenerate_bricks_css', function() {
	if (!current_user_can('manage_options')) {
		wp_die(esc_html__('Insufficient permissions', 'bytemash-woo-sync'));
	}
	check_admin_referer('bytemash_regen_bricks_css');

	$ok = true;
	$msg = '';

	// Delete files in /uploads/bricks/css
	$uploads = wp_upload_dir();
	$css_dir = trailingslashit($uploads['basedir']) . 'bricks/css';
	if (is_dir($css_dir) && is_readable($css_dir)) {
		$files = @glob($css_dir . '/*');
		if (is_array($files)) {
			foreach ($files as $f) {
				if (is_file($f)) {
					@unlink($f);
				}
			}
		}
	} else {
		$ok = false;
		$msg = esc_html__('Bricks CSS directory not found or not readable.', 'bytemash-woo-sync');
	}

	// Call Bricks cache clear if available (version-safe)
	if (class_exists('\\Bricks\\Assets')) {
		try {
			if (method_exists('Bricks\\Assets', 'clear_cache')) {
				\Bricks\Assets::clear_cache();
			} elseif (method_exists('Bricks\\Assets', 'clearCache')) {
				\Bricks\Assets::clearCache();
			}
		} catch (Exception $e) {
			$ok = false;
			$msg = $msg ?: $e->getMessage();
		}
	}

	$redirect = wp_get_referer();
	if (!$redirect) {
		$redirect = admin_url();
	}
	$redirect = add_query_arg(array(
		'bytemash_bricks_css' => $ok ? '1' : '0',
		'bytemash_bricks_msg' => $msg ? rawurlencode($msg) : '',
	), $redirect);
	wp_safe_redirect($redirect);
	exit;
});

// 3) Admin notice after regeneration
add_action('admin_notices', function() {
	if (!isset($_GET['bytemash_bricks_css'])) return;
	$ok = ($_GET['bytemash_bricks_css'] === '1');
	$msg = isset($_GET['bytemash_bricks_msg']) ? sanitize_text_field(wp_unslash($_GET['bytemash_bricks_msg'])) : '';
	if ($ok) {
		echo '<div class="notice notice-success is-dismissible"><p>✅ ' . esc_html__('Bricks CSS regenerated successfully!', 'bytemash-woo-sync') . '</p></div>';
	} else {
		$extra = $msg ? ' ' . esc_html($msg) : '';
		echo '<div class="notice notice-error is-dismissible"><p>❌ ' . esc_html__('Failed to regenerate Bricks CSS.', 'bytemash-woo-sync') . $extra . '</p></div>';
	}
});

// Shortcode: [amrod_before_title] — outputs WooCommerce hook content for use in Bricks or classic editors
// Usage in Bricks: Shortcode element → [amrod_before_title]
add_shortcode('amrod_before_title', function($atts = array()) {
	// Ensure we have a product context
	global $product, $post;
	
	// Try to get product using multiple methods
	if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
		// Method 1: Try from current post (works in loops and singular pages)
		$product_id = get_the_ID();
		if ($product_id && get_post_type($product_id) === 'product') {
			$product = wc_get_product($product_id);
		}
		
		// Method 2: Try from global $post (works in some loop contexts)
		if ((!$product || !is_object($product)) && !empty($post) && $post->post_type === 'product') {
			$product = wc_get_product($post->ID);
		}
	}
	
	// If still no product and shortcode is called with product_id attribute
	if ((!$product || !is_object($product)) && !empty($atts['product_id'])) {
		$product = wc_get_product(intval($atts['product_id']));
	}
	
	// Only proceed if we have a valid product context
	if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
		return '';
	}
	
	// Capture any HTML printed by callbacks hooked to this action
	ob_start();
	do_action('woocommerce_before_shop_loop_item_title');
	return ob_get_clean();
});

// Additional shortcodes registered in ByteMash_Woo_Sync class