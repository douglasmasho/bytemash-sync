# True Cron System Documentation

## Overview

The ByteMash Woo Sync plugin now includes a comprehensive production-ready cron system that ensures reliable execution of sync jobs without requiring user visits. This system implements multiple fallback strategies to guarantee cron jobs run even on low-traffic sites.

## Key Features

### 1. **Test Mode**
- **Purpose**: Allows testing sync functionality with frequent schedules
- **Behavior**: 
  - Full sync runs 2 minutes after enabling
  - Incremental sync runs every 5 minutes
  - Temporarily suspends production schedules
- **Access**: Admin → ByteMash Amrod Sync → Cron Manager

### 2. **True Cron Methods (Priority Order)**

#### **System Cron (Recommended)**
- **Best for**: Maximum reliability
- **Requirements**: 
  - `exec()` function enabled
  - `crontab` command available
  - User permission to write to uploads directory
- **Implementation**: 
  - Creates shell script in `wp-content/uploads/bytemash-cron/`
  - Adds crontab entry: `*/5 * * * * /path/to/script`
  - Uses `wget` to ping WordPress cron endpoint

#### **Hosted Pinger Service**
- **Best for**: Shared hosting environments
- **Requirements**: External service integration
- **Implementation**: Placeholder for third-party services like:
  - EasyCron
  - Cron-job.org
  - SetCronJob

#### **Self-Ping Fallback**
- **Best for**: Always available fallback
- **Implementation**: 
  - Non-blocking `wp_remote_post()` to site's cron endpoint
  - Automatic frequency control to prevent spam
  - Loopback detection and handling

### 3. **Safety & Diagnostics**

#### **Security Checks**
- All system-level operations require explicit user consent
- Strict validation of prerequisites before attempting installation
- Comprehensive logging of all operations

#### **Diagnostics Panel**
- **PHP Functions**: exec(), shell_exec(), wp_remote_post()
- **System Commands**: crontab, wget, WP-CLI
- **Permissions**: Plugin directory, uploads directory
- **Network**: Loopback status, external requests
- **Cron Health**: Last ping, success rate

## Admin Interface

### **Cron Manager Dashboard**
- **Location**: Admin → ByteMash Amrod Sync → Cron Manager
- **Features**:
  - Real-time status overview
  - Test mode toggle
  - True cron method configuration
  - Live diagnostics with auto-refresh

### **Status Indicators**
- **Active Method**: Shows which cron method is currently active
- **Test Mode**: Visual indicator of test mode status
- **Last Sync Times**: Full and incremental sync timestamps
- **Health Metrics**: Ping success rate and last execution

## Configuration

### **Default Schedules**
- **Full Sync**: Daily at 00:30 GMT+2 (South Africa time)
- **Incremental Sync**: Every 5 hours
- **Health Check**: Every hour
- **Self-Ping**: As needed (fallback only)

### **Test Mode Schedules**
- **Full Sync**: 2 minutes after enabling
- **Incremental Sync**: Every 5 minutes
- **Duration**: Until manually disabled

## API Integration

### **Helper Functions**
```php
// Schedule full sync
my_plugin_schedule_full_sync($when = null);

// Schedule incremental sync  
my_plugin_schedule_incremental_sync($recurrence = null);

// Clear all schedules
my_plugin_clear_all_schedules();

// Restore original schedules
my_plugin_restore_original_schedules();
```

### **Hooks & Filters**
```php
// Fired when true cron method is chosen
do_action('my_plugin_true_cron_method_chosen', $method);

// Filter cron ping payload
apply_filters('my_plugin_cron_ping_payload', $payload);
```

## Installation & Setup

### **Automatic Setup**
1. Plugin activation automatically initializes default schedules
2. Health check runs every hour to monitor cron health
3. Self-ping fallback activates automatically if needed

### **Manual Configuration**
1. Go to **Cron Manager** in admin
2. Review diagnostics to see available options
3. Enable preferred true cron method:
   - **System Cron**: For maximum reliability
   - **Hosted Pinger**: For shared hosting
   - **Self-Ping**: Always available fallback

### **System Cron Setup**
1. Click "Enable System Cron" in admin
2. System will:
   - Check prerequisites
   - Create cron script
   - Install crontab entry
   - Verify installation

## Troubleshooting

### **Common Issues**

#### **"exec() function is not available"**
- **Solution**: Contact hosting provider to enable exec()
- **Alternative**: Use hosted pinger or self-ping fallback

#### **"crontab command is not available"**
- **Solution**: Install cron package on server
- **Alternative**: Use hosted pinger or self-ping fallback

#### **"Loopback appears to be blocked"**
- **Solution**: Contact hosting provider about loopback restrictions
- **Alternative**: Use system cron or hosted pinger

#### **"Cannot create cron directory"**
- **Solution**: Check uploads directory permissions
- **Alternative**: Use hosted pinger or self-ping fallback

### **Diagnostics**
- **Location**: Cron Manager → Diagnostics
- **Auto-refresh**: Every 30 seconds
- **Manual refresh**: Click "Refresh Diagnostics"

## Logging

### **Log Locations**
- **Plugin logs**: `wp-content/uploads/bytemash-sync-logs/`
- **WordPress logs**: `wp-content/debug.log`
- **System logs**: `/var/log/cron` (if system cron enabled)

### **Log Levels**
- **Info**: Normal operations, schedule changes
- **Warning**: Health check failures, loopback issues
- **Error**: Installation failures, ping errors

## Best Practices

### **Production Deployment**
1. **Enable System Cron** for maximum reliability
2. **Monitor diagnostics** regularly
3. **Keep logs** for troubleshooting
4. **Test thoroughly** before going live

### **Development/Testing**
1. **Use Test Mode** for frequent testing
2. **Monitor logs** for sync behavior
3. **Test all cron methods** to ensure fallbacks work
4. **Disable Test Mode** before production

### **Maintenance**
1. **Check diagnostics** monthly
2. **Review logs** for errors
3. **Update schedules** as needed
4. **Monitor success rates**

## Security Considerations

### **System Cron Installation**
- Requires explicit user consent
- Validates all prerequisites
- Logs all operations
- Safe script generation with proper permissions

### **Self-Ping Protection**
- Frequency limiting to prevent spam
- Loopback detection
- Timeout controls
- User-agent identification

### **Admin Access**
- Requires `manage_options` capability
- Nonce verification for all AJAX requests
- Input sanitization and validation
- Error handling and logging

## Performance Impact

### **Minimal Overhead**
- Health check runs hourly (lightweight)
- Self-ping only when needed (fallback)
- System cron uses efficient shell scripts
- Hosted pinger relies on external service

### **Resource Usage**
- **Memory**: Minimal (scheduled events only)
- **CPU**: Low (efficient ping operations)
- **Network**: Minimal (small HTTP requests)
- **Storage**: Small (logs and scripts)

## Future Enhancements

### **Planned Features**
- **Multiple hosted pinger services** integration
- **Advanced scheduling** options
- **Performance metrics** dashboard
- **Automated health recovery**

### **Extensibility**
- **Hook system** for custom cron methods
- **Filter system** for payload modification
- **Action system** for event handling
- **API system** for external integrations

## Support

### **Documentation**
- This guide covers all major features
- Code comments explain implementation details
- Admin interface provides real-time help

### **Troubleshooting**
- **Diagnostics panel** shows system status
- **Log files** contain detailed information
- **Admin interface** provides error messages
- **Fallback methods** ensure reliability

### **Contact**
- **Plugin support**: Check diagnostics first
- **Hosting issues**: Contact hosting provider
- **System cron**: Check server configuration
- **Network issues**: Verify external connectivity

---

**Note**: This system is designed to be production-ready and requires no manual server configuration for basic operation. Advanced features like system cron installation require appropriate server permissions and user consent.
