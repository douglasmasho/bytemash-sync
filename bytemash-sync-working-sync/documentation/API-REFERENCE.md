# API Reference - ByteMash WooCommerce Amrod Sync

## Overview

This document provides a comprehensive reference for developers who want to extend or integrate with the ByteMash WooCommerce Amrod Sync plugin.

## Plugin Architecture

### Main Classes

#### 1. ByteMash_Woo_Sync
**File**: `bytemash-woo-sync.php`

Main plugin class that initializes the plugin and manages dependencies.

**Methods**:
- `get_instance()` - Returns singleton instance
- `check_dependencies()` - Verifies WooCommerce is active
- `load_textdomain()` - Loads translation files
- `register_admin_menu()` - Registers admin menu pages
- `activate()` - Plugin activation handler
- `deactivate()` - Plugin deactivation handler

#### 2. ByteMash_Amrod_API_Client
**File**: `includes/class-amrod-api-client.php`

Handles all communication with the Amrod API.

**Methods**:

```php
// Test API connection
public function test_connection(): bool

// Get categories with pagination
public function get_categories(int $page = 1, int $per_page = 100): array|WP_Error

// Get products with pagination and filters
public function get_products(int $page = 1, int $per_page = 50, array $filters = []): array|WP_Error

// Get single product by ID
public function get_product(string $product_id): array|WP_Error

// Get product variations
public function get_product_variations(string $product_id, int $page = 1, int $per_page = 50): array|WP_Error

// Get product stock levels
public function get_product_stock(string $product_id): array|WP_Error

// Get product images
public function get_product_images(string $product_id): array|WP_Error

// Get all brands
public function get_brands(int $page = 1, int $per_page = 100): array|WP_Error

// Get product swatches/colors
public function get_product_swatches(string $product_id): array|WP_Error

// Get branding options for a product
public function get_product_branding(string $product_id): array|WP_Error

// Get branding guidelines
public function get_branding_guidelines(): array|WP_Error

// Get product pricing
public function get_product_pricing(string $product_id, int $quantity = 1): array|WP_Error

// Batch get multiple products
public function get_products_batch(array $product_ids): array

// Get total product count
public function get_products_count(): int
```

#### 3. ByteMash_Product_Sync
**File**: `includes/class-product-sync.php`

Handles product synchronization logic.

**Methods**:

```php
// Sync all products with batching
public function sync_all_products(bool $force = false): array

// Sync single product
public function sync_single_product(array $product_data, bool $force = false): array

// Sync stock levels only
public function sync_stock_levels(): array
```

#### 4. ByteMash_Logger
**File**: `includes/class-logger.php`

Logging system for tracking sync activities.

**Methods**:

```php
// Log a message
public function log(string $level, string $message, array $data = [], string $sync_type = 'general'): void

// Get recent logs
public function get_logs(int $limit = 100, int $offset = 0, ?string $level = null): array

// Get logs count
public function get_logs_count(?string $level = null): int

// Clear old logs
public function clear_old_logs(int $days = 30): int

// Clear all logs
public function clear_all_logs(): bool

// Get sync statistics
public function get_sync_stats(int $days = 7): array

// Get last sync time
public function get_last_sync_time(?string $sync_type = null): string|null
```

**Log Levels**:
- `ByteMash_Logger::LEVEL_INFO` - Informational messages
- `ByteMash_Logger::LEVEL_WARNING` - Warning messages
- `ByteMash_Logger::LEVEL_ERROR` - Error messages
- `ByteMash_Logger::LEVEL_SUCCESS` - Success messages

#### 5. ByteMash_Image_Handler
**File**: `includes/class-image-handler.php`

Handles product image downloads and attachments.

**Methods**:

```php
// Sync product images
public function sync_product_images(int $product_id, array $images): void

// Clean up unused images
public function cleanup_unused_images(int $product_id): void
```

#### 6. ByteMash_Sync_Scheduler
**File**: `includes/class-sync-scheduler.php`

Manages scheduled syncs and WordPress cron.

**Methods**:

```php
// Add custom cron schedules
public function add_cron_schedules(array $schedules): array

// Run scheduled sync
public function run_scheduled_sync(): void

// Update sync schedule
public function update_schedule(string $frequency): void

// Get next scheduled sync time
public function get_next_sync_time(): string
```

## WordPress Hooks

### Actions

#### `bytemash_before_product_sync`
Fired before starting full product sync.

```php
do_action('bytemash_before_product_sync');
```

#### `bytemash_after_product_sync`
Fired after completing full product sync.

```php
do_action('bytemash_after_product_sync', array $result);
```

**Parameters**:
- `$result` (array) - Sync results with 'success', 'synced', 'errors' keys

#### `bytemash_product_synced`
Fired after syncing individual product.

```php
do_action('bytemash_product_synced', int $product_id, array $product_data);
```

**Parameters**:
- `$product_id` (int) - WooCommerce product ID
- `$product_data` (array) - Amrod product data

#### `bytemash_amrod_sync_cron`
WordPress cron hook for scheduled syncs.

```php
add_action('bytemash_amrod_sync_cron', 'your_function');
```

### Filters

#### `bytemash_api_request_timeout`
Filter API request timeout.

```php
apply_filters('bytemash_api_request_timeout', int $timeout);
```

**Default**: 60 seconds

**Example**:
```php
add_filter('bytemash_api_request_timeout', function($timeout) {
    return 120; // 2 minutes
});
```

#### `bytemash_batch_size`
Filter batch size for product sync.

```php
apply_filters('bytemash_batch_size', int $batch_size);
```

**Example**:
```php
add_filter('bytemash_batch_size', function($batch_size) {
    return 30; // Process 30 products per batch
});
```

#### `bytemash_product_data`
Filter product data before saving to WooCommerce.

```php
apply_filters('bytemash_product_data', array $product_data, WC_Product $product);
```

**Parameters**:
- `$product_data` (array) - Product data from Amrod
- `$product` (WC_Product) - WooCommerce product object

**Example**:
```php
add_filter('bytemash_product_data', function($product_data, $product) {
    // Modify product data
    $product_data['custom_field'] = 'custom_value';
    return $product_data;
}, 10, 2);
```

#### `bytemash_skip_product`
Filter to skip specific products during sync.

```php
apply_filters('bytemash_skip_product', bool $skip, array $product_data);
```

**Example**:
```php
add_filter('bytemash_skip_product', function($skip, $product_data) {
    // Skip products from specific category
    if (in_array('Discontinued', $product_data['categories'])) {
        return true;
    }
    return $skip;
}, 10, 2);
```

## AJAX Endpoints

### `bytemash_manual_sync`
Trigger manual product sync.

**Data**:
```javascript
{
    action: 'bytemash_manual_sync',
    nonce: bytemashWooSync.nonce
}
```

**Response**:
```json
{
    "success": true,
    "data": {
        "message": "Sync completed: 150 products synced, 2 errors",
        "synced": 150,
        "errors": 2
    }
}
```

### `bytemash_stock_sync`
Trigger stock level sync only.

**Data**:
```javascript
{
    action: 'bytemash_stock_sync',
    nonce: bytemashWooSync.nonce
}
```

### `bytemash_test_connection`
Test API connection.

**Data**:
```javascript
{
    action: 'bytemash_test_connection',
    nonce: bytemashWooSync.nonce
}
```

**Response**:
```json
{
    "success": true,
    "data": {
        "message": "Connection successful!"
    }
}
```

## Database Schema

### Table: `wp_bytemash_sync_logs`

```sql
CREATE TABLE wp_bytemash_sync_logs (
    id BIGINT(20) NOT NULL AUTO_INCREMENT,
    sync_type VARCHAR(50) NOT NULL,
    status VARCHAR(20) NOT NULL,
    message LONGTEXT,
    data LONGTEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY status (status),
    KEY created_at (created_at)
);
```

## Options

### Plugin Settings

- `bytemash_amrod_api_url` - Amrod API base URL
- `bytemash_amrod_api_token` - API Bearer token
- `bytemash_amrod_batch_size` - Products per batch
- `bytemash_amrod_sync_schedule` - Sync frequency
- `bytemash_log_retention_days` - Log retention period

### Transients

- `bytemash_sync_running` - Indicates sync in progress (expires in 1 hour)

## Product Meta Fields

Products synced from Amrod include these meta fields:

- `_amrod_product_id` - Amrod product ID
- `_amrod_swatches` - Color swatches data
- `_amrod_branding_options` - Branding options
- `_amrod_branding_guidelines` - Branding guidelines
- `_amrod_last_sync` - Last sync timestamp
- `_product_brand` - Brand name (if taxonomy not available)

## Custom Development Examples

### Example 1: Custom Product Sync Handler

```php
add_action('bytemash_product_synced', 'custom_product_handler', 10, 2);

function custom_product_handler($product_id, $product_data) {
    // Add custom meta field
    update_post_meta($product_id, '_custom_field', $product_data['custom_value']);
    
    // Send notification
    wp_mail('admin@example.com', 'Product Synced', "Product {$product_id} synced");
}
```

### Example 2: Modify API Timeout

```php
add_filter('bytemash_api_request_timeout', function($timeout) {
    // Increase timeout for slow connections
    return 180; // 3 minutes
});
```

### Example 3: Custom Batch Size Based on Time

```php
add_filter('bytemash_batch_size', function($batch_size) {
    $current_hour = (int) date('H');
    
    // Smaller batches during business hours
    if ($current_hour >= 9 && $current_hour <= 17) {
        return 20;
    }
    
    // Larger batches during off-hours
    return 100;
});
```

### Example 4: Log Custom Events

```php
$logger = new ByteMash_Logger();
$logger->log(
    ByteMash_Logger::LEVEL_INFO,
    'Custom event occurred',
    array('detail' => 'value'),
    'custom_sync'
);
```

## Error Handling

The plugin uses WordPress `WP_Error` for error handling:

```php
$result = $api_client->get_products();

if (is_wp_error($result)) {
    $error_message = $result->get_error_message();
    // Handle error
}
```

## Best Practices

1. **Always check for WP_Error** when using API methods
2. **Use hooks and filters** instead of modifying core files
3. **Log important events** using the logger
4. **Test with small batch sizes** first
5. **Monitor memory usage** during development
6. **Use transients** for temporary data
7. **Sanitize and validate** all inputs
8. **Escape all outputs** for security

## Support

For technical support or questions:
- Review plugin documentation
- Check [Amrod API Documentation](https://newapidocs.amrod.co.za/)
- Contact ByteMash support

## License

GPL v2 or later

