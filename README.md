# ByteMash WooCommerce Amrod Sync ✨

**Fully Optimized for Large Datasets - No Timeouts, No Memory Issues** Plugin

A robust, memory-efficient WooCommerce plugin that seamlessly integrates with the Amrod API to automate product synchronization.

## 🚀 Features

### Core Functionality
- **Complete Product Sync**: Syncs all product data including:
  - Product information (name, description, SKU)
  - Categories and brands
  - Variations and attributes
  - Pricing (regular and sale prices)
  - Stock levels
  - Product images
  - Swatches and colors
  - Branding options and guidelines

### Memory Efficiency
- **Batch Processing**: Processes products in configurable batches (10-200 products)
- **Pagination**: Efficient API calls with pagination support
- **Memory Management**: Automatic cache clearing and memory optimization
- **Timeout Handling**: Prevents script timeouts on large product catalogs

### Admin Dashboard
- **Intuitive Interface**: User-friendly dashboard for monitoring and control
- **Real-time Status**: View sync status and progress
- **Statistics**: Track successful syncs, errors, and product counts
- **Recent Activity**: Monitor recent sync operations

### Scheduling & Automation
- **Flexible Scheduling**: Choose from multiple sync frequencies:
  - Manual only
  - Hourly
  - Every 6 hours
  - Every 12 hours
  - Twice daily
  - Daily
  - Weekly
- **WordPress Cron**: Leverages WordPress built-in cron system
- **Background Processing**: Syncs run in the background without affecting site performance

### Logging & Monitoring
- **Comprehensive Logging**: Track all sync activities and errors
- **Log Filtering**: Filter logs by type (success, info, warning, error)
- **Log Retention**: Automatic cleanup of old logs (configurable)
- **Detailed Error Messages**: Clear error messages for troubleshooting

### Authentication & Security
- **Automatic Token Refresh**: Automatically re-authenticates on 401 errors
- **Seamless Recovery**: No manual intervention needed for expired tokens
- **Secure Credential Storage**: Credentials stored securely for automatic refresh
- **Intelligent Retry Logic**: Prevents infinite authentication loops

## 📋 Requirements

- **WordPress**: 5.8 or higher
- **WooCommerce**: 6.0 or higher
- **PHP**: 7.4 or higher
- **Memory**: 512MB recommended (256MB minimum, will auto-adjust)
- **Amrod API Access**: Valid API token from Amrod

## 🔧 Installation

### Manual Installation

1. Download the plugin files
2. Upload to `/wp-content/plugins/bytemash-woo-sync/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Navigate to **Amrod Sync > Settings** to configure

### Configuration

1. **API Settings**:
   - Enter your Amrod API URL (default: `https://api.amrod.co.za`)
   - Enter your Amrod API Bearer token
   - Test the connection to verify credentials

2. **Sync Settings**:
   - Set batch size (default: 50 products per batch)
   - Choose sync schedule frequency
   - Configure log retention period

3. **Run Initial Sync**:
   - Go to **Amrod Sync > Dashboard**
   - Click "Sync All Products" to start initial synchronization

## 📊 Usage

### Manual Sync

Navigate to **Amrod Sync > Dashboard** and click:
- **Sync All Products**: Full product sync including images, variations, and all data
- **Sync Stock Only**: Quick stock level update for existing products

### Automatic Sync

Once configured, the plugin will automatically sync products based on your chosen schedule. Monitor progress through the dashboard.

### Viewing Logs

Navigate to **Amrod Sync > Sync Logs** to:
- View detailed sync history
- Filter logs by type
- Export or clear logs
- Troubleshoot sync issues

## 🏗️ Plugin Structure

```
bytemash-woo-sync/
├── admin/
│   ├── class-admin-dashboard.php    # Dashboard interface
│   └── class-admin-settings.php     # Settings page
├── assets/
│   ├── css/
│   │   └── admin.css                # Admin styles
│   └── js/
│       └── admin.js                 # Admin JavaScript
├── includes/
│   ├── class-amrod-api-client.php   # API client
│   ├── class-product-sync.php       # Product sync logic
│   ├── class-image-handler.php      # Image processing
│   ├── class-sync-scheduler.php     # Scheduling & cron
│   └── class-logger.php             # Logging system
└── bytemash-woo-sync.php            # Main plugin file
```

## 🔌 API Endpoints Used

The plugin utilizes the following Amrod API endpoints:

- `/api/categories` - Fetch product categories
- `/api/products` - Fetch products with pagination
- `/api/products/{id}` - Get single product details
- `/api/products/{id}/variations` - Get product variations
- `/api/products/{id}/stock` - Get stock levels
- `/api/products/{id}/images` - Get product images
- `/api/products/{id}/swatches` - Get color swatches
- `/api/products/{id}/branding` - Get branding options
- `/api/brands` - Fetch brands
- `/api/branding/guidelines` - Get branding guidelines

For complete API documentation, visit: [Amrod API Documentation](https://newapidocs.amrod.co.za/)

## 🎨 Key Features Details

### Memory-Efficient Design

The plugin is specifically designed to handle large product catalogs without memory issues:

1. **Batch Processing**: Products are processed in configurable batches
2. **Cache Management**: Automatic WordPress cache flushing during sync
3. **Efficient Queries**: Optimized database queries
4. **Image Handling**: Smart image caching and duplicate prevention

### Product Variations

Automatically handles:
- Variable products with multiple attributes
- Variation-specific pricing
- Variation-specific stock levels
- Variation images

### Image Management

- Downloads and stores images in WordPress media library
- Sets featured images automatically
- Creates product galleries
- Prevents duplicate image downloads
- Cleans up unused images

### Brand Support

- Creates and assigns product brands (if taxonomy exists)
- Falls back to meta fields if brand taxonomy not available
- Compatible with popular brand plugins

## 🛠️ Troubleshooting

### Memory Errors (NEW - AUTO-FIXED!)

The plugin now **automatically manages memory limits** to prevent exhaustion errors. If you still experience issues:

1. **Run the diagnostic tool**: `wp-content/plugins/bytemash-woo-sync/diagnose-memory.php`
2. **Read the comprehensive guide**: See `MEMORY-OPTIMIZATION.md` for detailed solutions
3. **Quick fixes**:
   - Reduce batch size to 5 in settings
   - Increase PHP memory limit to 512M
   - Use incremental syncs instead of full syncs

**Technical Details**: The plugin automatically:
- Increases memory limit to 512MB during API operations
- Optimizes JSON decoding for large responses (50MB+)
- Aggressively cleans up memory after each batch
- Logs detailed memory usage for monitoring

### Sync Not Running

1. Check API credentials in settings
2. Test API connection
3. Verify WooCommerce is active
4. Check WordPress cron is working

### Missing Products

1. Check sync logs for errors
2. Verify API token permissions
3. Check product visibility settings
4. Run manual sync to retry

### Timeout Errors

1. Reduce batch size
2. Increase PHP max execution time
3. Switch to less frequent sync schedule

## 📝 Changelog

### Version 1.0.0
- Initial release
- Full product sync with categories, variations, images
- Stock level synchronization
- Flexible scheduling options
- Comprehensive logging system
- Memory-efficient batch processing
- Admin dashboard and settings interface

## 🤝 Support

For support, please:
1. Check the logs in **Amrod Sync > Sync Logs**
2. Review [Amrod API Documentation](https://newapidocs.amrod.co.za/)
3. Contact ByteMash support

## 📄 License

This plugin is licensed under GPL v2 or later.

## 👨‍💻 Author

**ByteMash**
- Website: https://bytemash.com

## 🙏 Credits

- Built for WooCommerce
- Integrates with Amrod API
- Follows WordPress coding standards

