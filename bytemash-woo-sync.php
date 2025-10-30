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
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-amrod-api-client.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-image-handler.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-batch-processor.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-product-sync.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-sync-scheduler.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-true-cron-manager.php';
        require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'includes/class-action-scheduler-sync.php';
        
        // Admin classes
        if (is_admin()) {
            require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'admin/class-admin-settings.php';
            require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'admin/class-admin-dashboard.php';
            require_once BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'admin/class-admin-tools.php';
        }

        // Frontend hooks for stock modal
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('woocommerce_single_product_summary', array($this, 'render_stock_modal_trigger'), 25);

        // Public AJAX for stock modal data
        add_action('wp_ajax_bytemash_get_product_stock_table', array($this, 'ajax_get_product_stock_table'));
        add_action('wp_ajax_nopriv_bytemash_get_product_stock_table', array($this, 'ajax_get_product_stock_table'));
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'check_dependencies'));
        add_action('init', array($this, 'load_textdomain'));
        add_action('init', array($this, 'init_scheduler'));
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
        
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

		// Force-inject buttons only if theme didn't render them (optional via settings)
		add_action('wp_footer', array($this, 'maybe_force_product_buttons'), 5);
        
        // Allow products to be purchasable even without prices
        add_filter('woocommerce_is_purchasable', array($this, 'make_products_purchasable_without_price'), 10, 2);
        add_filter('woocommerce_product_is_in_stock', array($this, 'force_in_stock_when_has_stock'), 10, 2);
        add_filter('woocommerce_product_get_price', array($this, 'set_default_price_for_amrod_products'), 10, 2);
        add_action('woocommerce_before_add_to_cart_button', array($this, 'maybe_set_product_price'));
        add_filter('woocommerce_get_price_html', array($this, 'hide_zero_price_display'), 10, 2);
        
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
            
            // Cron manager AJAX handlers
            add_action('wp_ajax_bytemash_toggle_test_mode', array($this, 'ajax_toggle_test_mode'));
            add_action('wp_ajax_bytemash_toggle_full_test_mode', array($this, 'ajax_toggle_full_test_mode'));
            add_action('wp_ajax_bytemash_toggle_incremental_test_mode', array($this, 'ajax_toggle_incremental_test_mode'));
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
     * Declare compatibility with WooCommerce HPOS
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }
    
    /**
     * Initialize scheduler after all dependencies are loaded
     */
    public function init_scheduler() {
        new ByteMash_Sync_Scheduler();
        // Only initialize Action Scheduler integration when scheduling is enabled
        $prod = (bool) get_option('bytemash_cron_production_enabled', false);
        $test = (bool) get_option('bytemash_cron_test_mode_enabled', false)
            || (bool) get_option('bytemash_cron_full_test_mode_enabled', false)
            || (bool) get_option('bytemash_cron_incremental_test_mode_enabled', false);
        if ($prod || $test) {
            new ByteMash_Action_Scheduler_Sync();
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
        
        // Ensure localized data is always available
        $localize_data = array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bytemash_woo_sync_nonce'),
            'strings' => array(
                'syncing' => __('Syncing...', 'bytemash-woo-sync'),
                'success' => __('Sync completed successfully!', 'bytemash-woo-sync'),
                'error' => __('Sync failed. Check logs for details.', 'bytemash-woo-sync'),
            ),
            'debug' => array(
                'plugin_url' => BYTEMASH_WOO_SYNC_PLUGIN_URL,
                'is_admin' => is_admin(),
                'hook' => $hook,
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
     * Enqueue frontend assets for stock modal
     */
    public function enqueue_frontend_assets() {
        if (!is_product()) {
            return;
        }
        wp_enqueue_style('bytemash-stock-modal', plugins_url('assets/css/stock-modal.css', __FILE__), array(), BYTEMASH_WOO_SYNC_VERSION);
        wp_enqueue_script('bytemash-stock-modal', plugins_url('assets/js/stock-modal.js', __FILE__), array('jquery'), BYTEMASH_WOO_SYNC_VERSION, true);
        wp_localize_script('bytemash-stock-modal', 'bytemashStockModal', array(
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
                $detail = get_post_meta($vid, '_amrod_stock_detail', true);
                $stock = isset($detail['stock']) ? intval($detail['stock']) : intval($v->get_stock_quantity());
                $reserved = isset($detail['reserved']) ? intval($detail['reserved']) : 0;
                list($incoming_total, $eta_text) = $compute_incoming($detail['incoming'] ?? array());

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
            $stock = isset($detail['stock']) ? intval($detail['stock']) : intval($product->get_stock_quantity());
            $reserved = isset($detail['reserved']) ? intval($detail['reserved']) : 0;
            list($incoming_total, $eta_text) = $compute_incoming($detail['incoming'] ?? array());

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
        
        // Trigger stock sync
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
        
        // Insert each batch as a separate row
        foreach ($batches as $index => $batch) {
            $wpdb->insert($table_name, array(
                'sync_id' => $sync_id,
                'batch_index' => $index,
                'batch_data' => json_encode($batch),
                'status' => 'pending'
            ));
            
            // Free memory after every 10 batches to prevent buildup
            if ($index % 10 === 0) {
                wp_cache_flush();
                gc_collect_cycles();
            }
        }
    }
    
    /**
     * AJAX: Process next pending batch from queue
     */
    public function ajax_process_batch() {
        // Check nonce
        check_ajax_referer('bytemash_woo_sync_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        // Get parameters
        $sync_id = isset($_POST['sync_id']) ? sanitize_text_field($_POST['sync_id']) : '';
        
        if (empty($sync_id)) {
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
            
            wp_send_json_success(array(
                'done' => true,
                'message' => 'All batches completed'
            ));
            return;
        }
        
        // Decode batch data
        $batch_data = json_decode($batch_row->batch_data, true);
        $batch_index = $batch_row->batch_index;
        
        // Mark batch as processing
        $wpdb->update($table_name, 
            array('status' => 'processing'),
            array('id' => $batch_row->id)
        );
        
        // Process this batch based on sync type
        $product_sync = new ByteMash_Product_Sync();
        $processed = 0;
        $errors = 0;
        
        $sync_type = $sync_info['type'] ?? 'products';
        
        foreach ($batch_data as $item_data) {
            if ($sync_type === 'stock') {
                $result = $product_sync->update_single_stock($item_data);
            } elseif ($sync_type === 'prices') {
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
                
                if ($result['success']) {
                    $processed++;
                } else {
                    $errors++;
            }
        }
        
        // Mark batch as complete
        $wpdb->update($table_name,
            array('status' => 'completed'),
            array('id' => $batch_row->id)
        );
        
        // Update sync info
        $sync_info['current_batch'] = $batch_index + 1;
        $sync_info['processed'] += $processed;
        $sync_info['errors'] += $errors;
        $sync_info['status'] = 'processing';
        
        update_option("bytemash_sync_{$sync_id}", $sync_info, false);
        
        // Get current WooCommerce product counts for dashboard update
        $product_counts = wp_count_posts('product');
        
        wp_send_json_success(array(
            'batch' => $batch_index,
            'processed' => $processed,
            'errors' => $errors,
            'skipped' => 0, // Not tracking skipped in current version
            'total_processed' => $sync_info['processed'],
            'total_errors' => $sync_info['errors'],
            'total_skipped' => 0, // Not tracking skipped in current version
            'total_products' => $sync_info['total'],
            'woo_product_count' => $product_counts->publish,
            'done' => false
        ));
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
        
        // Check if syncs are running
        $full_sync_running = get_transient('bytemash_full_sync_running');
        $incremental_sync_running = get_transient('bytemash_incremental_sync_running');
        
        // Get test mode status
        $test_mode = get_option('bytemash_cron_test_mode_enabled', false);
        $full_test_mode = get_option('bytemash_cron_full_test_mode_enabled', false);
        $incremental_test_mode = get_option('bytemash_cron_incremental_test_mode_enabled', false);
        
        // Adjust display for test modes
        if ($full_test_mode && $full_sync_next) {
            $full_sync_next = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $full_sync_next) . ' (Test Mode)';
        } elseif (!$full_sync_next) {
            $full_sync_next = __('Not scheduled', 'bytemash-woo-sync');
        }
        
        if ($incremental_test_mode && $incremental_sync_next) {
            $incremental_sync_next = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $incremental_sync_next) . ' (Test Mode)';
        } elseif (!$incremental_sync_next) {
            $incremental_sync_next = __('Not scheduled', 'bytemash-woo-sync');
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
            $guide_html .= '<a href="' . esc_url($full_guide) . '" target="_blank" class="button" style="background:#0073aa;color:#fff;padding:6px 12px;border-radius:3px;text-decoration:none;">Full Branding Guide</a>';
        }
        if (!empty($logo24_guide)) {
            $guide_html .= '<a href="' . esc_url($logo24_guide) . '" target="_blank" class="button" style="background:#28a745;color:#fff;padding:6px 12px;border-radius:3px;text-decoration:none;">Logo24 Branding Guide</a>';
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
     * Make products purchasable even without prices
     * This allows products to be orderable even if price sync is disabled or hasn't run yet
     * 
     * @param bool $purchasable Whether product is purchasable
     * @param WC_Product $product Product object
     * @return bool Modified purchasable status
     */
    public function make_products_purchasable_without_price($purchasable, $product) {
        // Check if this is an Amrod-synced product (has Amrod metadata)
        $amrod_sku = get_post_meta($product->get_id(), '_amrod_simple_code', true);
        $amrod_full_code = get_post_meta($product->get_id(), '_amrod_full_code', true);
        
        if (!empty($amrod_sku) || !empty($amrod_full_code)) {
            // This is an Amrod product - allow purchase even without price
            // Set a placeholder price of 0 to allow adding to cart
            if ($product->get_price() === '' || $product->get_price() === null) {
                $product->set_price('0');
                $product->set_regular_price('0');
            }
            return true;
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
        // If already in stock, use default behavior
        if ($is_in_stock) {
            return $is_in_stock;
        }
        
        // Check if this is an Amrod-synced product (has Amrod metadata)
        $amrod_sku = get_post_meta($product->get_id(), '_amrod_simple_code', true);
        $amrod_full_code = get_post_meta($product->get_id(), '_amrod_full_code', true);
        
        if (!empty($amrod_sku) || !empty($amrod_full_code)) {
            // This is an Amrod product - check if it has stock
            $stock_quantity = $product->get_stock_quantity();
            
            if ($stock_quantity !== null && $stock_quantity > 0) {
                // Has stock but WooCommerce marked as out of stock (probably due to no price)
                // Force it to be in stock
                return true;
            }
        }
        
        return $is_in_stock;
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
}

/**
 * Initialize the plugin
 */
function bytemash_woo_sync_init() {
    return ByteMash_Woo_Sync::get_instance();
}

// Start the plugin
bytemash_woo_sync_init();


// External image display fix
// Ensures WooCommerce and Bricks Builder render external product images stored in
// custom meta `_external_image_url` by providing a complete <img> HTML when present.
// This runs for both shop grids and single product contexts.
add_filter('woocommerce_product_get_image', 'bytemash_external_image_display_fix', 10, 6);
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

// External URL image support for Bricks `{woo_product_images}` and theme overrides (no sideloading)
// 1) Neutralize fake/non-numeric thumbnail IDs so builders don’t try to load them as attachments
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

// 2) Safety net: if an <img> ends up with src="external_*", swap to the real external URL
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

// 3) Fallback renderer for Bricks `{woo_product_images}` when no attachment is available
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
add_shortcode('amrod_before_title', function() {
	// Capture any HTML printed by callbacks hooked to this action
	ob_start();
	do_action('woocommerce_before_shop_loop_item_title');
	return ob_get_clean();
});