<?php
/**
 * True Cron Manager
 * 
 * Implements a production-ready WP plugin cron system that manages full syncs and incremental syncs
 * with true cron capabilities for reliable execution without user visits.
 * 
 * @package ByteMash_Woo_Sync
 * @version 2.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_True_Cron_Manager {
    
    /**
     * Plugin identifier for options
     */
    const OPTION_PREFIX = 'bytemash_cron_';
    
    /**
     * True cron methods
     */
    const METHOD_SYSTEM_CRON = 'system_cron';
    const METHOD_HOSTED_PINGER = 'hosted_pinger';
    const METHOD_SELF_PING = 'self_ping';
    const METHOD_NONE = 'none';
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Sync scheduler instance
     */
    private $scheduler;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new ByteMash_Logger();
        $this->scheduler = new ByteMash_Sync_Scheduler();
        
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_bytemash_toggle_test_mode', array($this, 'ajax_toggle_test_mode'));
        add_action('wp_ajax_bytemash_enable_system_cron', array($this, 'ajax_enable_system_cron'));
        add_action('wp_ajax_bytemash_enable_hosted_pinger', array($this, 'ajax_enable_hosted_pinger'));
        add_action('wp_ajax_bytemash_cron_diagnostics', array($this, 'ajax_cron_diagnostics'));
        
        // Cron hooks
        add_action('bytemash_true_cron_ping', array($this, 'execute_cron_ping'));
        add_action('bytemash_cron_health_check', array($this, 'health_check'));
        
        // Activation/deactivation hooks
        register_activation_hook(BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'bytemash-woo-sync.php', array($this, 'activate'));
        register_deactivation_hook(BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'bytemash-woo-sync.php', array($this, 'deactivate'));
        
        // Self-ping fallback
        add_action('init', array($this, 'maybe_trigger_self_ping'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'bytemash-amrod-sync',
            __('Cron Manager', 'bytemash-woo-sync'),
            __('Cron Manager', 'bytemash-woo-sync'),
            'manage_options',
            'bytemash-cron-manager',
            array($this, 'render_cron_manager')
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'bytemash-cron-manager') === false) {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_script(
            'bytemash-cron-manager',
            BYTEMASH_WOO_SYNC_PLUGIN_URL . 'assets/js/cron-manager.js',
            array('jquery'),
            BYTEMASH_WOO_SYNC_VERSION,
            true
        );
        
        wp_localize_script('bytemash-cron-manager', 'bytemashCron', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bytemash_cron_nonce'),
        ));
    }
    
    /**
     * Render cron manager admin page
     */
    public function render_cron_manager() {
        $test_mode = get_option(self::OPTION_PREFIX . 'test_mode_enabled', false);
        $system_cron_enabled = get_option(self::OPTION_PREFIX . 'system_cron_enabled', false);
        $hosted_pinger_enabled = get_option(self::OPTION_PREFIX . 'hosted_pinger_enabled', false);
        $active_method = $this->get_active_cron_method();
        $diagnostics = $this->get_diagnostics();
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Cron Manager', 'bytemash-woo-sync'); ?></h1>
            
            <div class="bytemash-cron-dashboard">
                <!-- Status Overview -->
                <div class="postbox">
                    <h2><?php esc_html_e('Cron Status Overview', 'bytemash-woo-sync'); ?></h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th><?php esc_html_e('Active Method', 'bytemash-woo-sync'); ?></th>
                                <td>
                                    <span class="cron-method-badge cron-method-<?php echo esc_attr($active_method); ?>">
                                        <?php echo esc_html($this->get_method_display_name($active_method)); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Test Mode', 'bytemash-woo-sync'); ?></th>
                                <td>
                                    <span class="test-mode-badge <?php echo $test_mode ? 'enabled' : 'disabled'; ?>">
                                        <?php echo $test_mode ? __('Enabled', 'bytemash-woo-sync') : __('Disabled', 'bytemash-woo-sync'); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Last Full Sync', 'bytemash-woo-sync'); ?></th>
                                <td><?php echo esc_html(get_option('bytemash_last_full_sync', __('Never', 'bytemash-woo-sync'))); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Last Incremental Sync', 'bytemash-woo-sync'); ?></th>
                                <td><?php echo esc_html(get_option('bytemash_last_incremental_sync', __('Never', 'bytemash-woo-sync'))); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Test Mode Controls -->
                <div class="postbox">
                    <h2><?php esc_html_e('Test Mode', 'bytemash-woo-sync'); ?></h2>
                    <div class="inside">
                        <p><?php esc_html_e('Enable test mode to run syncs more frequently for testing purposes.', 'bytemash-woo-sync'); ?></p>
                        <button type="button" id="toggle-test-mode" class="button button-primary">
                            <?php echo $test_mode ? __('Disable Test Mode', 'bytemash-woo-sync') : __('Enable Test Mode', 'bytemash-woo-sync'); ?>
                        </button>
                        <div id="test-mode-status"></div>
                    </div>
                </div>
                
                <!-- True Cron Methods -->
                <div class="postbox">
                    <h2><?php esc_html_e('True Cron Methods', 'bytemash-woo-sync'); ?></h2>
                    <div class="inside">
                        <p><?php esc_html_e('Configure how cron jobs run reliably without user visits.', 'bytemash-woo-sync'); ?></p>
                        
                        <!-- System Cron -->
                        <div class="cron-method-section">
                            <h3><?php esc_html_e('System Cron (Recommended)', 'bytemash-woo-sync'); ?></h3>
                            <p><?php esc_html_e('Automatically installs a system cron job for maximum reliability.', 'bytemash-woo-sync'); ?></p>
                            <button type="button" id="enable-system-cron" class="button" <?php echo $system_cron_enabled ? 'disabled' : ''; ?>>
                                <?php esc_html_e('Enable System Cron', 'bytemash-woo-sync'); ?>
                            </button>
                            <div id="system-cron-status"></div>
                        </div>
                        
                        <!-- Hosted Pinger -->
                        <div class="cron-method-section">
                            <h3><?php esc_html_e('Hosted Pinger Service', 'bytemash-woo-sync'); ?></h3>
                            <p><?php esc_html_e('Use a third-party service to ping your cron endpoint.', 'bytemash-woo-sync'); ?></p>
                            <button type="button" id="enable-hosted-pinger" class="button" <?php echo $hosted_pinger_enabled ? 'disabled' : ''; ?>>
                                <?php esc_html_e('Enable Hosted Pinger', 'bytemash-woo-sync'); ?>
                            </button>
                            <div id="hosted-pinger-status"></div>
                        </div>
                        
                        <!-- Self-Ping Fallback -->
                        <div class="cron-method-section">
                            <h3><?php esc_html_e('Self-Ping Fallback', 'bytemash-woo-sync'); ?></h3>
                            <p><?php esc_html_e('Automatically enabled as fallback when other methods are not available.', 'bytemash-woo-sync'); ?></p>
                            <span class="status-badge <?php echo $active_method === self::METHOD_SELF_PING ? 'active' : 'inactive'; ?>">
                                <?php echo $active_method === self::METHOD_SELF_PING ? __('Active', 'bytemash-woo-sync') : __('Inactive', 'bytemash-woo-sync'); ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Diagnostics -->
                <div class="postbox">
                    <h2><?php esc_html_e('Diagnostics', 'bytemash-woo-sync'); ?></h2>
                    <div class="inside">
                        <button type="button" id="refresh-diagnostics" class="button">
                            <?php esc_html_e('Refresh Diagnostics', 'bytemash-woo-sync'); ?>
                        </button>
                        <div id="diagnostics-content">
                            <?php $this->render_diagnostics($diagnostics); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .cron-method-badge {
            padding: 4px 8px;
            border-radius: 3px;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 11px;
        }
        .cron-method-system_cron { background: #28a745; color: white; }
        .cron-method-hosted_pinger { background: #007cba; color: white; }
        .cron-method-self_ping { background: #ffc107; color: black; }
        .cron-method-none { background: #dc3545; color: white; }
        
        .test-mode-badge.enabled { color: #28a745; font-weight: bold; }
        .test-mode-badge.disabled { color: #6c757d; }
        
        .cron-method-section {
            margin: 20px 0;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .status-badge.active { color: #28a745; font-weight: bold; }
        .status-badge.inactive { color: #6c757d; }
        </style>
        <?php
    }
    
    /**
     * Render diagnostics content
     */
    private function render_diagnostics($diagnostics) {
        ?>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e('PHP Functions', 'bytemash-woo-sync'); ?></th>
                <td>
                    exec(): <?php echo function_exists('exec') ? '✅' : '❌'; ?><br>
                    shell_exec(): <?php echo function_exists('shell_exec') ? '✅' : '❌'; ?><br>
                    wp_remote_post(): <?php echo function_exists('wp_remote_post') ? '✅' : '❌'; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('System Commands', 'bytemash-woo-sync'); ?></th>
                <td>
                    crontab: <?php echo $diagnostics['crontab_available'] ? '✅' : '❌'; ?><br>
                    wget: <?php echo $diagnostics['wget_available'] ? '✅' : '❌'; ?><br>
                    WP-CLI: <?php echo $diagnostics['wp_cli_available'] ? '✅' : '❌'; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Permissions', 'bytemash-woo-sync'); ?></th>
                <td>
                    Plugin Dir Writable: <?php echo $diagnostics['plugin_writable'] ? '✅' : '❌'; ?><br>
                    Uploads Dir Writable: <?php echo $diagnostics['uploads_writable'] ? '✅' : '❌'; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Network', 'bytemash-woo-sync'); ?></th>
                <td>
                    Loopback Blocked: <?php echo $diagnostics['loopback_blocked'] ? '❌' : '✅'; ?><br>
                    External Requests: <?php echo $diagnostics['external_requests'] ? '✅' : '❌'; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Cron Health', 'bytemash-woo-sync'); ?></th>
                <td>
                    Last Ping: <?php echo esc_html($diagnostics['last_ping']); ?><br>
                    Ping Success Rate: <?php echo esc_html($diagnostics['ping_success_rate']); ?>%
                </td>
            </tr>
        </table>
        <?php
    }
    
    /**
     * Get active cron method
     */
    private function get_active_cron_method() {
        if (get_option(self::OPTION_PREFIX . 'system_cron_enabled', false)) {
            return self::METHOD_SYSTEM_CRON;
        }
        
        if (get_option(self::OPTION_PREFIX . 'hosted_pinger_enabled', false)) {
            return self::METHOD_HOSTED_PINGER;
        }
        
        if (get_option(self::OPTION_PREFIX . 'self_ping_enabled', true)) {
            return self::METHOD_SELF_PING;
        }
        
        return self::METHOD_NONE;
    }
    
    /**
     * Get method display name
     */
    private function get_method_display_name($method) {
        $names = array(
            self::METHOD_SYSTEM_CRON => __('System Cron', 'bytemash-woo-sync'),
            self::METHOD_HOSTED_PINGER => __('Hosted Pinger', 'bytemash-woo-sync'),
            self::METHOD_SELF_PING => __('Self-Ping', 'bytemash-woo-sync'),
            self::METHOD_NONE => __('None', 'bytemash-woo-sync'),
        );
        
        return isset($names[$method]) ? $names[$method] : $method;
    }
    
    /**
     * Get diagnostics data
     */
    private function get_diagnostics() {
        return array(
            'crontab_available' => $this->check_command_available('crontab'),
            'wget_available' => $this->check_command_available('wget'),
            'wp_cli_available' => $this->check_command_available('wp'),
            'plugin_writable' => is_writable(BYTEMASH_WOO_SYNC_PLUGIN_DIR),
            'uploads_writable' => is_writable(wp_upload_dir()['basedir']),
            'loopback_blocked' => get_option(self::OPTION_PREFIX . 'loopback_blocked', false),
            'external_requests' => $this->test_external_requests(),
            'last_ping' => get_option(self::OPTION_PREFIX . 'last_ping', __('Never', 'bytemash-woo-sync')),
            'ping_success_rate' => $this->calculate_ping_success_rate(),
        );
    }
    
    /**
     * Check if command is available
     */
    private function check_command_available($command) {
        if (!function_exists('exec')) {
            return false;
        }
        
        $output = array();
        $return_var = 0;
        exec("which $command 2>/dev/null", $output, $return_var);
        
        return $return_var === 0;
    }
    
    /**
     * Test external requests
     */
    private function test_external_requests() {
        $response = wp_remote_get('https://httpbin.org/status/200', array(
            'timeout' => 5,
            'sslverify' => false,
        ));
        
        return !is_wp_error($response);
    }
    
    /**
     * Calculate ping success rate
     */
    private function calculate_ping_success_rate() {
        $success_count = get_option(self::OPTION_PREFIX . 'ping_success_count', 0);
        $total_count = get_option(self::OPTION_PREFIX . 'ping_total_count', 0);
        
        if ($total_count === 0) {
            return 0;
        }
        
        return round(($success_count / $total_count) * 100, 1);
    }
    
    /**
     * AJAX: Toggle test mode
     */
    public function ajax_toggle_test_mode() {
        check_ajax_referer('bytemash_cron_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $test_mode = get_option(self::OPTION_PREFIX . 'test_mode_enabled', false);
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
     * Enable test mode
     */
    private function enable_test_mode() {
        // Store original schedules
        $original_schedules = array(
            'full_sync_frequency' => get_option('bytemash_full_sync_frequency', 'daily_at_0030'),
            'incremental_frequency' => get_option('bytemash_incremental_sync_frequency', 'every_5_hours'),
        );
        update_option(self::OPTION_PREFIX . 'original_schedules', $original_schedules);
        
        // Clear existing schedules
        $this->scheduler->clear_all_schedules();
        
        // Schedule test schedules
        $this->schedule_full_sync(time() + 120); // 2 minutes from now
        $this->schedule_incremental_sync('every_5_minutes');
        
        update_option(self::OPTION_PREFIX . 'test_mode_enabled', true);
        
        $this->logger->log('info', 'Test mode enabled', array(), 'cron_manager');
    }
    
    /**
     * Disable test mode
     */
    private function disable_test_mode() {
        // Clear test schedules
        $this->scheduler->clear_all_schedules();
        
        // Restore original schedules
        $original_schedules = get_option(self::OPTION_PREFIX . 'original_schedules', array());
        if (!empty($original_schedules)) {
            $this->scheduler->update_schedule(
                $original_schedules['full_sync_frequency'],
                $original_schedules['incremental_frequency']
            );
        }
        
        update_option(self::OPTION_PREFIX . 'test_mode_enabled', false);
        
        $this->logger->log('info', 'Test mode disabled', array(), 'cron_manager');
    }
    
    /**
     * AJAX: Enable system cron
     */
    public function ajax_enable_system_cron() {
        check_ajax_referer('bytemash_cron_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $result = $this->install_system_cron();
        
        if ($result['success']) {
            update_option(self::OPTION_PREFIX . 'system_cron_enabled', true);
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
            return array('success' => false, 'message' => __('exec() function is not available', 'bytemash-woo-sync'));
        }
        
        if (!$this->check_command_available('crontab')) {
            return array('success' => false, 'message' => __('crontab command is not available', 'bytemash-woo-sync'));
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
        
        // Add to crontab
        $crontab_line = "*/5 * * * * $script_path";
        
        // Get current crontab
        $current_crontab = array();
        exec('crontab -l 2>/dev/null', $current_crontab);
        
        // Check if already exists
        $cron_exists = false;
        foreach ($current_crontab as $line) {
            if (strpos($line, $script_path) !== false) {
                $cron_exists = true;
                break;
            }
        }
        
        if (!$cron_exists) {
            // Add new cron entry
            $current_crontab[] = $crontab_line;
            $new_crontab = implode("\n", $current_crontab);
            
            $temp_file = tempnam(sys_get_temp_dir(), 'crontab');
            file_put_contents($temp_file, $new_crontab);
            
            exec("crontab $temp_file 2>&1", $output, $return_var);
            unlink($temp_file);
            
            if ($return_var !== 0) {
                return array('success' => false, 'message' => __('Failed to install crontab entry', 'bytemash-woo-sync'));
            }
        }
        
        // Store configuration
        update_option(self::OPTION_PREFIX . 'system_cron_script', $script_path);
        update_option(self::OPTION_PREFIX . 'system_cron_line', $crontab_line);
        
        $this->logger->log('info', 'System cron installed successfully', array(
            'script_path' => $script_path,
            'cron_line' => $crontab_line,
        ), 'cron_manager');
        
        return array('success' => true, 'message' => __('System cron installed successfully', 'bytemash-woo-sync'));
    }
    
    /**
     * AJAX: Enable hosted pinger
     */
    public function ajax_enable_hosted_pinger() {
        check_ajax_referer('bytemash_cron_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $result = $this->register_hosted_pinger();
        
        if ($result['success']) {
            update_option(self::OPTION_PREFIX . 'hosted_pinger_enabled', true);
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Register hosted pinger
     */
    private function register_hosted_pinger() {
        // This is a placeholder implementation
        // In a real implementation, you would integrate with a service like:
        // - EasyCron
        // - Cron-job.org
        // - SetCronJob
        
        $pinger_client = new ByteMash_Hosted_Pinger_Client();
        $result = $pinger_client->register_ping_job();
        
        if ($result['success']) {
            update_option(self::OPTION_PREFIX . 'hosted_pinger_id', $result['job_id']);
            update_option(self::OPTION_PREFIX . 'hosted_pinger_url', $result['ping_url']);
        }
        
        return $result;
    }
    
    /**
     * AJAX: Get diagnostics
     */
    public function ajax_cron_diagnostics() {
        check_ajax_referer('bytemash_cron_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'bytemash-woo-sync')));
        }
        
        $diagnostics = $this->get_diagnostics();
        
        wp_send_json_success($diagnostics);
    }
    
    /**
     * Execute cron ping
     */
    public function execute_cron_ping() {
        $this->logger->log('info', 'Cron ping executed', array(), 'cron_manager');
        
        // Update ping statistics
        $total_count = get_option(self::OPTION_PREFIX . 'ping_total_count', 0);
        $success_count = get_option(self::OPTION_PREFIX . 'ping_success_count', 0);
        
        update_option(self::OPTION_PREFIX . 'ping_total_count', $total_count + 1);
        update_option(self::OPTION_PREFIX . 'ping_success_count', $success_count + 1);
        update_option(self::OPTION_PREFIX . 'last_ping', current_time('mysql'));
        
        // Trigger self-ping if needed
        $this->maybe_trigger_self_ping();
    }
    
    /**
     * Health check
     */
    public function health_check() {
        $last_ping = get_option(self::OPTION_PREFIX . 'last_ping');
        $ping_timeout = 10 * MINUTE_IN_SECONDS; // 10 minutes
        
        if ($last_ping && (time() - strtotime($last_ping)) > $ping_timeout) {
            $this->logger->log('warning', 'Cron health check failed - no recent pings', array(), 'cron_manager');
            
            // Trigger self-ping as fallback
            $this->trigger_self_ping();
        }
    }
    
    /**
     * Maybe trigger self-ping
     */
    public function maybe_trigger_self_ping() {
        $active_method = $this->get_active_cron_method();
        
        // Only use self-ping if no other method is active
        if ($active_method === self::METHOD_SELF_PING || $active_method === self::METHOD_NONE) {
            $this->trigger_self_ping();
        }
    }
    
    /**
     * Trigger self-ping
     */
    private function trigger_self_ping() {
        $cron_url = site_url('/wp-cron.php?doing_wp_cron');
        
        $response = wp_remote_post($cron_url, array(
            'blocking' => false,
            'timeout' => 0.01,
            'headers' => array(
                'User-Agent' => 'ByteMash-Woo-Sync-Self-Ping/1.0',
            ),
        ));
        
        if (is_wp_error($response)) {
            $this->logger->log('error', 'Self-ping failed', array(
                'error' => $response->get_error_message(),
            ), 'cron_manager');
            
            // Mark loopback as blocked if it fails consistently
            $this->check_loopback_blocked();
        } else {
            $this->logger->log('info', 'Self-ping executed successfully', array(), 'cron_manager');
        }
    }
    
    /**
     * Check if loopback is blocked
     */
    private function check_loopback_blocked() {
        $failed_count = get_option(self::OPTION_PREFIX . 'ping_failed_count', 0);
        $failed_count++;
        
        update_option(self::OPTION_PREFIX . 'ping_failed_count', $failed_count);
        
        if ($failed_count >= 3) {
            update_option(self::OPTION_PREFIX . 'loopback_blocked', true);
            $this->logger->log('warning', 'Loopback appears to be blocked', array(), 'cron_manager');
        }
    }
    
    /**
     * Schedule full sync
     */
    public function schedule_full_sync($when = null) {
        if ($when === null) {
            $when = time() + 120; // 2 minutes from now
        }
        
        wp_schedule_single_event($when, 'bytemash_full_sync_cron');
        
        $this->logger->log('info', 'Full sync scheduled', array(
            'when' => date('Y-m-d H:i:s', $when),
        ), 'cron_manager');
    }
    
    /**
     * Schedule incremental sync
     */
    public function schedule_incremental_sync($recurrence = null) {
        if ($recurrence === null) {
            $recurrence = 'every_5_hours';
        }
        
        wp_schedule_event(time(), $recurrence, 'bytemash_incremental_sync_cron');
        
        $this->logger->log('info', 'Incremental sync scheduled', array(
            'recurrence' => $recurrence,
        ), 'cron_manager');
    }
    
    /**
     * Clear all schedules
     */
    public function clear_all_schedules() {
        wp_clear_scheduled_hook('bytemash_full_sync_cron');
        wp_clear_scheduled_hook('bytemash_incremental_sync_cron');
        wp_clear_scheduled_hook('bytemash_true_cron_ping');
        wp_clear_scheduled_hook('bytemash_cron_health_check');
        
        $this->logger->log('info', 'All schedules cleared', array(), 'cron_manager');
    }
    
    /**
     * Restore original schedules
     */
    public function restore_original_schedules() {
        $original_schedules = get_option(self::OPTION_PREFIX . 'original_schedules', array());
        
        if (!empty($original_schedules)) {
            $this->scheduler->update_schedule(
                $original_schedules['full_sync_frequency'],
                $original_schedules['incremental_frequency']
            );
        }
        
        $this->logger->log('info', 'Original schedules restored', array(), 'cron_manager');
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Schedule health check
        wp_schedule_event(time(), 'hourly', 'bytemash_cron_health_check');
        
        // Initialize default schedules
        $this->scheduler->update_schedule('daily_at_0030', 'every_5_hours');
        
        $this->logger->log('info', 'Cron manager activated', array(), 'cron_manager');
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear all schedules
        $this->clear_all_schedules();
        
        // Clean up system cron if installed
        $script_path = get_option(self::OPTION_PREFIX . 'system_cron_script');
        if ($script_path && file_exists($script_path)) {
            unlink($script_path);
        }
        
        $this->logger->log('info', 'Cron manager deactivated', array(), 'cron_manager');
    }
}

/**
 * Hosted Pinger Client (Placeholder Implementation)
 */
class ByteMash_Hosted_Pinger_Client {
    
    /**
     * Register ping job with hosted service
     */
    public function register_ping_job() {
        // This is a placeholder implementation
        // In a real implementation, you would:
        // 1. Get API credentials from admin settings
        // 2. Make API call to hosted service
        // 3. Store job ID for future management
        
        $ping_url = site_url('/wp-cron.php?doing_wp_cron');
        
        // Simulate API call
        $job_id = 'placeholder_' . time();
        
        return array(
            'success' => true,
            'job_id' => $job_id,
            'ping_url' => $ping_url,
            'message' => __('Hosted pinger registered (placeholder)', 'bytemash-woo-sync'),
        );
    }
}
