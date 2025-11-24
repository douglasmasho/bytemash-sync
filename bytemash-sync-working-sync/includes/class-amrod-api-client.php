<?php
/**
 * Amrod API Client
 * 
 * Handles all API communication with Amrod API
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_Amrod_API_Client {
    
    /**
     * API Base URL
     */
    private $api_url;
    
    /**
     * API Token
     */
    private $api_token;
    
    /**
     * Request timeout (increased for large responses)
     */
    private $timeout = 300; // 5 minutes for large data sets
    
    /**
     * Memory limit for large operations (in MB)
     */
    private $memory_limit = 512; // 512MB for processing large datasets
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Amrod uses different base URLs for different services
        // Authentication: https://identity.amrod.co.za
        // API Data: https://vendorapi.amrod.co.za
        $this->api_url = 'https://vendorapi.amrod.co.za';
        $this->api_token = get_option('bytemash_amrod_api_token');
        $this->logger = new ByteMash_Logger();
    }
    
    /**
     * Make API request with automatic token refresh on 401
     */
    private function request($endpoint, $method = 'GET', $body = null, $params = array(), $retry = true) {
        if (empty($this->api_token)) {
            $this->logger->log('error', 'API token not configured');
            return new WP_Error('no_token', 'API token not configured');
        }
        
        // Increase memory limit for large API responses
        $original_memory_limit = ini_get('memory_limit');
        $this->increase_memory_limit();
        
        $url = trailingslashit($this->api_url) . ltrim($endpoint, '/');
        
        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }
        
        $args = array(
            'method' => $method,
            'timeout' => $this->timeout,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
        );
        
        if ($body !== null) {
            $args['body'] = is_array($body) ? json_encode($body) : $body;
        }
        
        $this->logger->log('info', 'API Request', array(
            'url' => $url,
            'method' => $method,
            'has_token' => !empty($this->api_token),
            'memory_limit' => ini_get('memory_limit'),
            'retry_enabled' => $retry,
        ), 'api_request');
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            $this->logger->log('error', 'API Request Failed', array(
                'url' => $url,
                'error' => $response->get_error_message(),
                'error_code' => $response->get_error_code(),
            ), 'api_request');
            // Restore original memory limit
            @ini_set('memory_limit', $original_memory_limit);
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $headers = wp_remote_retrieve_headers($response);
        
        // Handle 401 Unauthorized - Token expired or invalid
        if ($status_code === 401 && $retry) {
            $this->logger->log('warning', '401 Unauthorized - Attempting to refresh token', array(
                'url' => $url,
                'status_code' => $status_code,
            ), 'api_request');
            
            // Clean up current response
            unset($response, $response_body);
            
            // Attempt to re-authenticate
            $refresh_result = $this->refresh_token();
            
            if (!is_wp_error($refresh_result)) {
                $this->logger->log('success', 'Token refreshed successfully, retrying original request', array(
                    'url' => $url,
                ), 'api_request');
                
                // Retry the original request with new token (retry = false to prevent infinite loop)
                @ini_set('memory_limit', $original_memory_limit);
                return $this->request($endpoint, $method, $body, $params, false);
            } else {
                $this->logger->log('error', 'Failed to refresh token after 401', array(
                    'error' => $refresh_result->get_error_message(),
                ), 'api_request');
                @ini_set('memory_limit', $original_memory_limit);
                return new WP_Error('auth_failed', 'Authentication failed: ' . $refresh_result->get_error_message());
            }
        }
        
        // Handle HTTP 204 No Content - this is success with no data
        if ($status_code === 204 || (empty($response_body) && $status_code < 300)) {
            $this->logger->log('info', 'API returned empty response (204 No Content or empty body)', array(
                'url' => $url,
                'status_code' => $status_code,
            ), 'api_request');
            // Clean up memory
            unset($response_body, $response);
            @ini_set('memory_limit', $original_memory_limit);
            return array(); // Return empty array - no data to process
        }
        
        if ($status_code >= 400) {
            $this->logger->log('error', 'API Error Response', array(
                'url' => $url,
                'status_code' => $status_code,
                'body' => substr($response_body, 0, 500), // First 500 chars
                'content_type' => isset($headers['content-type']) ? $headers['content-type'] : 'unknown',
            ), 'api_request');
            // Clean up memory
            unset($response_body, $response);
            @ini_set('memory_limit', $original_memory_limit);
            return new WP_Error('api_error', 'API returned error: ' . $status_code);
        }
        
        // Log response size before decoding
        $response_size = strlen($response_body);
        $this->logger->log('info', 'Processing API Response', array(
            'url' => $url,
            'response_size_mb' => round($response_size / 1024 / 1024, 2),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ), 'api_request');
        
        // For very large responses, use streaming decode
        if ($response_size > 10 * 1024 * 1024) { // > 10MB
            $data = $this->json_decode_large($response_body);
        } else {
            $data = json_decode($response_body, true);
        }
        
        // Check for JSON errors BEFORE freeing memory
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Log first 500 chars of response to see what we actually got
            $response_preview = substr($response_body, 0, 500);
            $is_empty = ($response_size === 0 || trim($response_body) === '');
            
            // Write FULL response body to debug.log for inspection
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[ByteMash] FULL API RESPONSE BODY for ' . $url . ':');
                error_log('===== START RESPONSE =====');
                error_log($response_body ?: '(completely empty)');
                error_log('===== END RESPONSE =====');
                error_log('Response size: ' . $response_size . ' bytes');
                error_log('Status code: ' . $status_code);
            }
            
            $this->logger->log('error', 'Invalid JSON response', array(
                'url' => $url,
                'error' => json_last_error_msg(),
                'status_code' => $status_code,
                'response_preview' => $response_preview,
                'response_size' => $response_size,
                'is_completely_empty' => $is_empty,
            ), 'api_request');
            
            // Free up memory
            unset($response_body, $response);
            @ini_set('memory_limit', $original_memory_limit);
            
            // ONLY treat as "no updates" if response is COMPLETELY EMPTY (likely means no data)
            // Empty response (0 bytes) from "updated" endpoints typically means no changes since last fetch
            if ($is_empty && (strpos($url, 'GetUpdated') !== false || strpos($url, 'Updated') !== false)) {
                $this->logger->log('info', 'Updated endpoint returned empty response - no updates available', array(
                    'url' => $url,
                    'endpoint_type' => 'incremental',
                ), 'api_request');
                return array(); // Return empty array = no updates
            }
            
            // For non-empty invalid JSON or non-incremental endpoints, return error
            return new WP_Error('invalid_json', 'Invalid JSON response: ' . json_last_error_msg() . ' (size: ' . $response_size . ' bytes)');
        }
        
        // Free up memory after successful decode
        unset($response_body, $response);
        
        $this->logger->log('info', 'API Request Success', array(
            'url' => $url,
            'status_code' => $status_code,
            'response_size_mb' => round($response_size / 1024 / 1024, 2),
            'data_count' => is_array($data) ? count($data) : 'not_array',
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ), 'api_request');
        
        // Keep the increased memory limit for subsequent operations (e.g., storing batches in DB)
        // Don't restore to original - let PHP cleanup handle it at end of request
        
        return $data;
    }
    
    /**
     * Authenticate with Amrod using username, password and customer code
     * Returns token on success
     */
    public function authenticate($username, $password, $customer_code = '') {
        // Amrod authentication endpoint
        $auth_url = 'https://identity.amrod.co.za/VendorLogin';
        
        $args = array(
            'method' => 'POST',
            'timeout' => $this->timeout,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => json_encode(array(
                'UserName' => $username,
                'Password' => $password,
                'CustomerCode' => $customer_code,
            )),
        );
        
        $this->logger->log('info', 'Attempting Amrod authentication', array('username' => $username), 'authentication');
        
        $response = wp_remote_request($auth_url, $args);
        
        if (is_wp_error($response)) {
            $this->logger->log('error', 'Authentication request failed', array(
                'error' => $response->get_error_message(),
            ), 'authentication');
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code >= 400) {
            $this->logger->log('error', 'Authentication error', array(
                'status_code' => $status_code,
                'body' => $body,
            ), 'authentication');
            return new WP_Error('auth_error', 'Authentication failed: Invalid credentials', array('status' => $status_code));
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->log('error', 'Invalid JSON in auth response', array(
                'body' => $body,
            ), 'authentication');
            return new WP_Error('invalid_json', 'Invalid JSON response from authentication');
        }
        
        // Amrod returns token in different possible fields, check all
        $token = null;
        if (isset($data['token'])) {
            $token = $data['token'];
        } elseif (isset($data['access_token'])) {
            $token = $data['access_token'];
        } elseif (isset($data['Token'])) {
            $token = $data['Token'];
        } elseif (isset($data['AccessToken'])) {
            $token = $data['AccessToken'];
        }
        
        if ($token) {
            // Store the token
            update_option('bytemash_amrod_api_token', $token);
            $this->api_token = $token;
            
            // Store credentials securely for automatic token refresh
            // Note: These are stored in the WordPress options table which is as secure as the WordPress installation
            update_option('bytemash_amrod_username', $username);
            update_option('bytemash_amrod_password', base64_encode($password)); // Basic obfuscation
            
            // Store customer code if provided
            if (!empty($customer_code)) {
                update_option('bytemash_amrod_customer_code', $customer_code);
            }
            
            // Amrod API URL is fixed, no need to update from response
            // Authentication: https://identity.amrod.co.za
            // API Data: https://vendorapi.amrod.co.za
            
            // Store token expiry if provided
            if (isset($data['expires_in'])) {
                $expiry = time() + (int) $data['expires_in'];
                update_option('bytemash_amrod_token_expiry', $expiry);
            } elseif (isset($data['expiresIn'])) {
                $expiry = time() + (int) $data['expiresIn'];
                update_option('bytemash_amrod_token_expiry', $expiry);
            } else {
                // Default expiry: 24 hours if not specified
                $expiry = time() + (24 * 3600);
                update_option('bytemash_amrod_token_expiry', $expiry);
            }
            
            $this->logger->log('success', 'Authentication successful', array(
                'token_preview' => substr($token, 0, 20) . '...',
                'full_response' => $data, // Log full response to see all available fields
                'api_url_in_response' => isset($data['api_url']) || isset($data['apiUrl']) || isset($data['base_url']),
                'credentials_stored' => true,
            ), 'authentication');
            
            return $data;
        }
        
        $this->logger->log('error', 'No token in response', array(
            'response_keys' => array_keys($data),
        ), 'authentication');
        
        return new WP_Error('no_token', 'No access token in response');
    }
    
    /**
     * Refresh authentication token using stored credentials
     * Called automatically when 401 Unauthorized is received
     */
    private function refresh_token() {
        $this->logger->log('info', 'Attempting automatic token refresh', array(), 'authentication');
        
        // Get stored credentials
        $username = get_option('bytemash_amrod_username');
        $password_encoded = get_option('bytemash_amrod_password');
        $customer_code = get_option('bytemash_amrod_customer_code', '');
        
        if (empty($username) || empty($password_encoded)) {
            $this->logger->log('error', 'Cannot refresh token: credentials not stored', array(), 'authentication');
            return new WP_Error('no_credentials', 'No stored credentials available for token refresh. Please re-authenticate manually.');
        }
        
        // Decode password
        $password = base64_decode($password_encoded);
        
        // Re-authenticate using stored credentials
        $result = $this->authenticate($username, $password, $customer_code);
        
        if (is_wp_error($result)) {
            $this->logger->log('error', 'Token refresh failed', array(
                'error' => $result->get_error_message(),
            ), 'authentication');
            return $result;
        }
        
        $this->logger->log('success', 'Token refreshed successfully', array(
            'username' => $username,
        ), 'authentication');
        
        return $result;
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        // Test by fetching brands (lightweight endpoint)
        $result = $this->request('api/v1/Brands/');
        return !is_wp_error($result);
    }
    
    /**
     * Increase memory limit for large operations
     */
    private function increase_memory_limit() {
        $current = ini_get('memory_limit');
        
        // Convert current limit to bytes
        $current_bytes = $this->convert_to_bytes($current);
        $desired_bytes = $this->memory_limit * 1024 * 1024;
        
        // Only increase if current limit is lower
        if ($current_bytes < $desired_bytes) {
            $success = @ini_set('memory_limit', $this->memory_limit . 'M');
            if ($success) {
                $this->logger->log('info', 'Memory limit increased', array(
                    'from' => $current,
                    'to' => $this->memory_limit . 'M',
                ), 'performance');
            }
        }
    }
    
    /**
     * Convert memory limit string to bytes
     */
    private function convert_to_bytes($value) {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
    
    /**
     * Decode large JSON strings more efficiently
     * For very large JSON arrays, this helps reduce memory peaks
     */
    private function json_decode_large($json_string) {
        // Try to decode normally first, but with error suppression
        set_error_handler(function() {});
        $data = json_decode($json_string, true);
        restore_error_handler();
        
        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }
        
        // If normal decode fails due to memory, log and try alternative approach
        $this->logger->log('warning', 'Large JSON decode attempted', array(
            'size_mb' => round(strlen($json_string) / 1024 / 1024, 2),
            'error' => json_last_error_msg(),
        ), 'performance');
        
        // Clear the error
        json_decode('');
        
        // Try one more time with explicit memory cleanup
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        $data = json_decode($json_string, true);
        
        return $data;
    }
    
    /**
     * Check if token is expired
     */
    public function is_token_expired() {
        $expiry = get_option('bytemash_amrod_token_expiry');
        
        if (!$expiry) {
            return false; // No expiry set, assume valid
        }
        
        return time() >= $expiry;
    }
    
    /**
     * Check if authenticated
     */
    public function is_authenticated() {
        return !empty($this->api_token) && !$this->is_token_expired();
    }
    
    /**
     * Get all categories (full tree structure)
     */
    public function get_categories() {
        $this->logger->log('info', 'Fetching categories from Amrod', array(), 'api_request');
        return $this->request('api/v1/Categories/');
    }
    
    /**
     * Get updated categories (since previous day)
     */
    public function get_categories_updated() {
        $this->logger->log('info', 'Fetching updated categories from Amrod', array(), 'api_request');
        return $this->request('api/v1/Categories/GetUpdated');
    }
    
    /**
     * Get all products WITHOUT branding info (lighter payload)
     */
    public function get_products_without_branding() {
        $this->logger->log('info', 'Fetching products (without branding) from Amrod', array(), 'api_request');
        $result = $this->request('api/v1/Products/');
        
        if (is_wp_error($result)) {
            $this->logger->log('error', 'Failed to fetch products', array(
                'error' => $result->get_error_message(),
            ), 'api_request');
        } else {
            $product_count = is_array($result) ? count($result) : 0;
            $this->logger->log('info', 'Products fetched successfully', array(
                'count' => $product_count,
            ), 'api_request');
        }
        
        return $result;
    }
    
    /**
     * Get updated products WITHOUT branding (since previous day)
     */
    public function get_products_without_branding_updated() {
        $this->logger->log('info', 'Fetching updated products (without branding) from Amrod', array(), 'api_request');
        
        // Get last sync timestamp to avoid duplicate processing
        $last_sync = get_option('bytemash_last_incremental_sync');
        if ($last_sync) {
            $this->logger->log('info', 'Last incremental sync timestamp', array('timestamp' => $last_sync), 'api_request');
        }
        
        $result = $this->request('api/v1/Products/GetUpdatedProducts');
        
        // Store API response timestamp if available
        if (!is_wp_error($result) && is_array($result) && !empty($result)) {
            $first_item = reset($result);
            if (isset($first_item['lastUpdated']) || isset($first_item['LastUpdated'])) {
                $api_timestamp = $first_item['lastUpdated'] ?? $first_item['LastUpdated'];
                update_option('bytemash_api_last_product_update', $api_timestamp);
                $this->logger->log('info', 'API product update timestamp stored', array('timestamp' => $api_timestamp), 'api_request');
            }
        }
        
        return $result;
    }
    
    /**
     * Get all products WITH branding info (heavier payload)
     */
    public function get_products_with_branding() {
        $this->logger->log('info', 'Fetching products (with branding) from Amrod', array(), 'api_request');
        $result = $this->request('api/v1/Products/GetProductsAndBranding');
        
        if (is_wp_error($result)) {
            $this->logger->log('error', 'Failed to fetch products with branding', array(
                'error' => $result->get_error_message(),
            ), 'api_request');
        } else {
            $product_count = is_array($result) ? count($result) : 0;
            $this->logger->log('info', 'Products with branding fetched successfully', array(
                'count' => $product_count,
            ), 'api_request');
        }
        
        return $result;
    }
    
    /**
     * Get updated products WITH branding (since previous day)
     */
    public function get_products_with_branding_updated() {
        $this->logger->log('info', 'Fetching updated products (with branding) from Amrod', array(), 'api_request');
        
        // Get last sync timestamp to avoid duplicate processing
        $last_sync = get_option('bytemash_last_incremental_sync');
        if ($last_sync) {
            $this->logger->log('info', 'Last incremental sync timestamp', array('timestamp' => $last_sync), 'api_request');
        }
        
        $result = $this->request('api/v1/Products/GetUpdatedProductsAndBranding');
        
        // Store API response timestamp if available
        if (!is_wp_error($result) && is_array($result) && !empty($result)) {
            $first_item = reset($result);
            if (isset($first_item['lastUpdated']) || isset($first_item['LastUpdated'])) {
                $api_timestamp = $first_item['lastUpdated'] ?? $first_item['LastUpdated'];
                update_option('bytemash_api_last_product_branding_update', $api_timestamp);
                $this->logger->log('info', 'API product branding update timestamp stored', array('timestamp' => $api_timestamp), 'api_request');
            }
        }
        
        return $result;
    }
    
    /**
     * Get all stock (updated 4 times daily)
     */
    public function get_stock() {
        $this->logger->log('info', 'Fetching stock from Amrod', array(), 'api_request');
        return $this->request('api/v1/Stock/');
    }
    
    /**
     * Get updated stock (differential since first sync of day)
     * According to API docs: Returns rolling changes since 00:30 GMT+2 daily reset
     */
    public function get_stock_updated() {
        $this->logger->log('info', 'Fetching updated stock from Amrod', array(), 'api_request');
        
        // Get last sync timestamp to avoid duplicate processing
        $last_sync = get_option('bytemash_last_incremental_sync');
        if ($last_sync) {
            $this->logger->log('info', 'Last incremental sync timestamp', array('timestamp' => $last_sync), 'api_request');
        }
        
        $result = $this->request('api/v1/Stock/GetUpdated');
        
        // Store API response timestamp if available
        if (!is_wp_error($result) && is_array($result) && !empty($result)) {
            // Check if API response includes timestamp (some APIs do)
            $first_item = reset($result);
            if (isset($first_item['lastUpdated']) || isset($first_item['LastUpdated'])) {
                $api_timestamp = $first_item['lastUpdated'] ?? $first_item['LastUpdated'];
                update_option('bytemash_api_last_stock_update', $api_timestamp);
                $this->logger->log('info', 'API stock update timestamp stored', array('timestamp' => $api_timestamp), 'api_request');
            }
        }
        
        return $result;
    }
    
    /**
     * Get outlet stock (stock at outlet level)
     */
    public function get_outlet_stock() {
        $this->logger->log('info', 'Fetching outlet stock from Amrod', array(), 'api_request');
        return $this->request('api/v1/Stock/Outlet');
    }
    
    /**
     * Get all prices
     */
    public function get_prices() {
        $this->logger->log('info', 'Fetching prices from Amrod', array(), 'api_request');
        return $this->request('api/v1/Prices/');
    }
    
    /**
     * Get updated prices (since previous day)
     */
    public function get_prices_updated() {
        $this->logger->log('info', 'Fetching updated prices from Amrod', array(), 'api_request');
        
        // Get last sync timestamp to avoid duplicate processing
        $last_sync = get_option('bytemash_last_incremental_sync');
        if ($last_sync) {
            $this->logger->log('info', 'Last incremental sync timestamp', array('timestamp' => $last_sync), 'api_request');
        }
        
        $result = $this->request('api/v1/Prices/GetUpdated');
        
        // Store API response timestamp if available
        if (!is_wp_error($result) && is_array($result) && !empty($result)) {
            $first_item = reset($result);
            if (isset($first_item['lastUpdated']) || isset($first_item['LastUpdated'])) {
                $api_timestamp = $first_item['lastUpdated'] ?? $first_item['LastUpdated'];
                update_option('bytemash_api_last_price_update', $api_timestamp);
                $this->logger->log('info', 'API price update timestamp stored', array('timestamp' => $api_timestamp), 'api_request');
            }
        }
        
        return $result;
    }
    
    /**
     * Get all brands
     */
    public function get_brands() {
        $this->logger->log('info', 'Fetching brands from Amrod', array(), 'api_request');
        return $this->request('api/v1/Brands/');
    }
    
    /**
     * Get updated brands (since previous day)
     */
    public function get_brands_updated() {
        $this->logger->log('info', 'Fetching updated brands from Amrod', array(), 'api_request');
        
        // Get last sync timestamp to avoid duplicate processing
        $last_sync = get_option('bytemash_last_incremental_sync');
        if ($last_sync) {
            $this->logger->log('info', 'Last incremental sync timestamp', array('timestamp' => $last_sync), 'api_request');
        }
        
        $result = $this->request('api/v1/Brands/GetUpdated');
        
        // Store API response timestamp if available
        if (!is_wp_error($result) && is_array($result) && !empty($result)) {
            $first_item = reset($result);
            if (isset($first_item['lastUpdated']) || isset($first_item['LastUpdated'])) {
                $api_timestamp = $first_item['lastUpdated'] ?? $first_item['LastUpdated'];
                update_option('bytemash_api_last_brand_update', $api_timestamp);
                $this->logger->log('info', 'API brand update timestamp stored', array('timestamp' => $api_timestamp), 'api_request');
            }
        }
        
        return $result;
    }
    
    /**
     * Get branding departments
     */
    public function get_branding_departments() {
        $this->logger->log('info', 'Fetching branding departments from Amrod', array(), 'api_request');
        return $this->request('api/v1/BrandingDepartments/');
    }
    
    /**
     * Get updated branding departments
     */
    public function get_branding_departments_updated() {
        $this->logger->log('info', 'Fetching updated branding departments from Amrod', array(), 'api_request');
        return $this->request('api/v1/BrandingDepartments/GetUpdated');
    }
    
    /**
     * Get branding prices
     */
    public function get_branding_prices() {
        $this->logger->log('info', 'Fetching branding prices from Amrod', array(), 'api_request');
        return $this->request('api/v1/BrandingPrices/');
    }
    
    /**
     * Get updated branding prices
     */
    public function get_branding_prices_updated() {
        $this->logger->log('info', 'Fetching updated branding prices from Amrod', array(), 'api_request');
        return $this->request('api/v1/BrandingPrices/GetUpdated');
    }
    
    /**
     * Get inclusive brandings (specials)
     */
    public function get_inclusive_brandings() {
        $this->logger->log('info', 'Fetching inclusive brandings from Amrod', array(), 'api_request');
        return $this->request('api/v1/InclusiveBrandings/');
    }
    
    /**
     * Get updated inclusive brandings
     */
    public function get_inclusive_brandings_updated() {
        $this->logger->log('info', 'Fetching updated inclusive brandings from Amrod', array(), 'api_request');
        return $this->request('api/v1/InclusiveBrandings/GetUpdated');
    }
    
    /**
     * Get colour swatches
     */
    public function get_colour_swatches() {
        $this->logger->log('info', 'Fetching colour swatches from Amrod', array(), 'api_request');
        return $this->request('api/v1/ColourSwatches/');
    }
}

