# Usage Guide - ByteMash WooCommerce Amrod Sync

## Table of Contents

1. [Getting Started](#getting-started)
2. [Dashboard Overview](#dashboard-overview)
3. [Running Syncs](#running-syncs)
4. [Managing Settings](#managing-settings)
5. [Viewing Logs](#viewing-logs)
6. [Common Tasks](#common-tasks)
7. [Troubleshooting](#troubleshooting)
8. [Best Practices](#best-practices)

## Getting Started

After installation and configuration, your plugin is ready to sync products from Amrod to your WooCommerce store.

### First Steps

1. **Verify Configuration**
   - Navigate to **Amrod Sync > Settings**
   - Ensure API credentials are entered
   - Click "Test Connection" to verify

2. **Run Initial Sync**
   - Go to **Amrod Sync > Dashboard**
   - Click "Sync All Products"
   - Wait for completion (may take several minutes for large catalogs)

3. **Check Results**
   - Review the sync status message
   - Check **Products** in WooCommerce to see imported products
   - Review **Amrod Sync > Sync Logs** for any errors

## Dashboard Overview

The main dashboard (**Amrod Sync > Dashboard**) provides:

### Sync Status Card
- **Current Status**: Shows if a sync is running or idle
- **Last Sync**: Displays when the last sync occurred
- **Action Buttons**:
  - **Sync All Products**: Full product synchronization
  - **Sync Stock Only**: Quick stock level update

### Statistics Cards
- **Total Products**: Total products in your store
- **Amrod Products**: Products synced from Amrod
- **Successful (7d)**: Successful operations in last 7 days
- **Errors (7d)**: Errors in last 7 days

### Recent Activity
- Shows the 10 most recent sync activities
- Displays time, type, status, and message
- Color-coded status indicators

### Quick Actions
- Links to Settings, Logs, and API Documentation

## Running Syncs

### Manual Full Sync

**When to use**: Initial setup, major catalog updates, or troubleshooting

1. Navigate to **Amrod Sync > Dashboard**
2. Click **Sync All Products**
3. Confirm the action
4. Monitor progress on the dashboard
5. Wait for completion message

**What it does**:
- Fetches all products from Amrod
- Creates new products
- Updates existing products
- Syncs images, variations, prices, stock
- Updates categories and brands

### Stock Sync Only

**When to use**: Quick inventory updates without full product sync

1. Navigate to **Amrod Sync > Dashboard**
2. Click **Sync Stock Only**
3. Confirm the action
4. Wait for completion

**What it does**:
- Updates stock quantities only
- Faster than full sync
- Only affects products already synced from Amrod

### Automatic Scheduled Syncs

Once configured, the plugin runs syncs automatically based on your schedule.

**To configure**:
1. Go to **Amrod Sync > Settings**
2. Choose **Sync Schedule** frequency
3. Save settings
4. Next sync time is displayed on the settings page

## Managing Settings

Navigate to **Amrod Sync > Settings** to configure the plugin.

### API Configuration

#### API URL
- **Default**: `https://api.amrod.co.za`
- **When to change**: Only if Amrod provides a different endpoint
- **Format**: Must be a valid URL with https://

#### API Token
- **Required**: Yes
- **Format**: Bearer token from Amrod
- **Security**: Stored securely, can be shown/hidden
- **Testing**: Use "Test Connection" button to verify

### Sync Configuration

#### Batch Size
- **Range**: 10-200 products per batch
- **Default**: 50
- **Recommendations**:
  - **Small sites** (< 500 products): 100
  - **Medium sites** (500-2000 products): 50
  - **Large sites** (> 2000 products): 30
  - **Low memory**: 20
  - **High memory**: 100-200

#### Sync Schedule
- **Manual Only**: No automatic syncing
- **Hourly**: Every hour (high-traffic stores)
- **Every 6 Hours**: Good balance
- **Every 12 Hours**: Suitable for most stores
- **Twice Daily**: Morning and evening
- **Daily**: Once per day (recommended)
- **Weekly**: Once per week

### Advanced Settings

#### Log Retention
- **Default**: 30 days
- **Range**: 7-365 days
- **Purpose**: Automatic cleanup of old logs
- **Tip**: Keep at 30 days unless troubleshooting

#### Clear Logs
- Permanently deletes all logs
- Cannot be undone
- Use when logs table gets too large

## Viewing Logs

Navigate to **Amrod Sync > Sync Logs** to view detailed sync history.

### Log Table Columns

- **ID**: Unique log entry ID
- **Date/Time**: When the event occurred
- **Type**: Category of sync (product_sync, stock_sync, etc.)
- **Status**: Success, Info, Warning, or Error
- **Message**: Description of what happened
- **Details**: Button to view additional data

### Filtering Logs

Use the dropdown to filter by status:
- **All Levels**: Show everything
- **Success**: Only successful operations
- **Info**: Informational messages
- **Warning**: Warnings (non-critical issues)
- **Error**: Errors that need attention

### Understanding Log Statuses

#### Success (Green)
- Operation completed successfully
- No action needed
- Example: "Product synced successfully"

#### Info (Blue)
- Informational message
- No action needed
- Example: "Starting full product sync"

#### Warning (Yellow)
- Non-critical issue
- May need attention
- Example: "Product missing optional field"

#### Error (Red)
- Critical issue
- Requires attention
- Example: "Failed to sync product: API error"

### Viewing Log Details

1. Click **View** button in Details column
2. Modal opens with detailed JSON data
3. Shows complete information about the operation
4. Useful for debugging and troubleshooting

## Common Tasks

### Task 1: Update All Products

**Scenario**: You want to refresh all product data from Amrod

**Steps**:
1. Go to **Amrod Sync > Dashboard**
2. Click **Sync All Products**
3. Wait for completion
4. Check logs for any errors

### Task 2: Update Stock Levels Only

**Scenario**: Inventory changed but product details haven't

**Steps**:
1. Go to **Amrod Sync > Dashboard**
2. Click **Sync Stock Only**
3. Wait for completion (faster than full sync)

### Task 3: Change Sync Schedule

**Scenario**: You want syncs to run more/less frequently

**Steps**:
1. Go to **Amrod Sync > Settings**
2. Change **Sync Schedule** dropdown
3. Click **Save Settings**
4. Note the new "Next Scheduled Sync" time

### Task 4: Troubleshoot Failed Sync

**Scenario**: A sync failed with errors

**Steps**:
1. Go to **Amrod Sync > Sync Logs**
2. Filter by **Error** status
3. Read error messages
4. Click **View** to see details
5. Common fixes:
   - API connection: Check credentials
   - Timeout: Reduce batch size
   - Memory: Increase PHP memory limit
6. Try manual sync again

### Task 5: Reset API Credentials

**Scenario**: You got a new API token from Amrod

**Steps**:
1. Go to **Amrod Sync > Settings**
2. Update **API Token** field
3. Click **Test Connection** to verify
4. Click **Save Settings**
5. Run a test sync

### Task 6: Clean Up Old Logs

**Scenario**: Logs table is getting large

**Steps**:
1. Go to **Amrod Sync > Settings**
2. Scroll to **Advanced Settings**
3. Click **Clear All Logs** (or adjust retention days)
4. Confirm deletion

### Task 7: Verify Sync Status

**Scenario**: Check if products are syncing correctly

**Steps**:
1. Go to **Amrod Sync > Dashboard**
2. Check statistics:
   - Compare "Total Products" vs "Amrod Products"
   - Review success/error counts
3. Check **Recent Activity** for latest operations
4. Review a few products in WooCommerce:
   - Check if images are present
   - Verify stock levels
   - Check variations (if applicable)

## Troubleshooting

### Problem: Sync Keeps Failing

**Possible Causes & Solutions**:

1. **API Connection Issues**
   - Test connection in settings
   - Verify API token is valid
   - Check if Amrod API is accessible

2. **Memory Exhausted**
   - Reduce batch size to 20-30
   - Increase PHP memory limit
   - Check server resources

3. **Timeout Errors**
   - Reduce batch size
   - Increase PHP max_execution_time
   - Schedule syncs during low-traffic hours

4. **API Rate Limiting**
   - Use less frequent sync schedule
   - Contact Amrod about rate limits

### Problem: Products Not Appearing

**Check**:
1. View **Sync Logs** for errors
2. Verify products have SKUs in Amrod
3. Check product status (should be published)
4. Look for products in WooCommerce > Products
5. Check if products are in draft status

### Problem: Images Not Syncing

**Check**:
1. Verify image URLs in Amrod are accessible
2. Check PHP file upload settings
3. Verify WordPress media library permissions
4. Review error logs for image-related errors
5. Check if WordPress can download external images

### Problem: Stock Not Updating

**Possible Causes**:
1. Products not synced from Amrod yet (check for `_amrod_product_id` meta)
2. API token doesn't have stock access
3. Stock management disabled in WooCommerce

**Solution**:
1. Run full sync first
2. Then run stock-only sync
3. Check individual product settings

### Problem: Variations Not Working

**Check**:
1. Verify Amrod product has variations
2. Check logs for variation-specific errors
3. Ensure attributes are properly formatted
4. Try syncing the product again

## Best Practices

### 1. Initial Setup
- Start with a small batch size (20-30)
- Run initial sync during low-traffic hours
- Monitor the first sync closely
- Review logs for any issues
- Increase batch size after successful sync

### 2. Regular Maintenance
- Review logs weekly
- Clean up old logs monthly
- Test sync connection monthly
- Keep plugin updated
- Monitor sync success rates

### 3. Performance Optimization
- Use appropriate batch size for your server
- Schedule syncs during low-traffic hours
- Use stock-only sync for inventory updates
- Don't sync more frequently than needed

### 4. Troubleshooting
- Always check logs first
- Test API connection regularly
- Keep backup before major syncs
- Document any issues and solutions
- Contact support with specific error messages

### 5. Security
- Keep API token secure
- Use strong admin passwords
- Keep WordPress and plugins updated
- Regular backups
- Monitor access to settings

## Tips & Tricks

### Tip 1: Monitor Sync Times
Note how long syncs take at different batch sizes to find optimal setting.

### Tip 2: Use Stock Sync
For daily inventory updates, use stock-only sync instead of full sync.

### Tip 3: Schedule Wisely
Schedule automatic syncs when your site has low traffic.

### Tip 4: Test After Updates
After updating the plugin or WordPress, run a test sync to ensure everything works.

### Tip 5: Keep Logs Clean
Set appropriate log retention to keep database lean.

### Tip 6: Document Custom Settings
If you modify batch size or schedule, document why for future reference.

## Getting Help

If you need assistance:

1. **Check Documentation**
   - This usage guide
   - Installation guide
   - API reference

2. **Review Logs**
   - Often contain specific error messages
   - Include in support requests

3. **Test Connection**
   - Verify API credentials are working
   - Rules out connectivity issues

4. **Contact Support**
   - Provide WordPress version
   - Include WooCommerce version
   - Share relevant log entries
   - Describe steps to reproduce issue

## Additional Resources

- [Amrod API Documentation](https://newapidocs.amrod.co.za/)
- [WooCommerce Documentation](https://woocommerce.com/documentation/)
- [WordPress Codex](https://codex.wordpress.org/)

---

**Need more help?** Contact ByteMash support with specific questions or issues.

