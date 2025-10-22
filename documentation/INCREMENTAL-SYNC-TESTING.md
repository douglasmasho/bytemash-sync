# Incremental Sync Testing Guide

This guide will help you test the new incremental sync functionality that follows the Amrod API documentation requirements.

## Overview

The plugin now implements proper incremental updates with:
- **Full sync at 00:30 GMT+2 daily** (clears and repopulates all data)
- **Incremental sync every 5 hours** (only processes changes since last full sync)
- **Server-side automation** (no user interaction required)
- **Proper timestamp tracking** (prevents duplicate processing)

## Testing Methods

### Method 1: Quick Test Script (Recommended)

1. **Load the test script in your browser:**
   ```
   https://your-site.com/wp-content/plugins/bytemash-woo-sync/test-incremental-sync.php
   ```

2. **The script will check:**
   - ✅ Authentication status
   - ✅ Cron schedules are set correctly
   - ✅ Last sync timestamps
   - ✅ Prerequisites for incremental sync
   - ✅ API response timestamps
   - ✅ WordPress cron configuration

3. **Use the manual triggers** to test syncs immediately

### Method 2: WordPress Admin Dashboard

1. **Go to Settings Page:**
   ```
   Dashboard → Amrod Sync → Settings
   ```

2. **Check Sync Status section** to see:
   - Last Full Sync time
   - Last Incremental Sync time
   - Next scheduled Full Sync
   - Next scheduled Incremental Sync

3. **Configure schedules:**
   - Full Sync Schedule: Daily at 00:30 GMT+2 (Recommended)
   - Incremental Sync Schedule: Every 5 Hours (Recommended)

4. **Save settings** and check logs

### Method 3: WP-CLI Commands

If you have WP-CLI installed, you can test directly from command line:

```bash
# Check scheduled cron events
wp cron event list

# Run full sync manually
wp cron event run bytemash_full_sync_cron

# Run incremental sync manually
wp cron event run bytemash_incremental_sync_cron

# Check if events are scheduled
wp cron event list --fields=hook,next_run_relative --format=table
```

### Method 4: PHP Direct Testing

Create a test file in your theme directory:

```php
<?php
// test-sync.php

require_once('wp-load.php');

$scheduler = new ByteMash_Sync_Scheduler();

echo "=== Testing Full Sync ===\n";
$scheduler->run_full_sync();

echo "\n=== Testing Incremental Sync ===\n";
$scheduler->run_incremental_sync();

echo "\n=== Sync Status ===\n";
print_r($scheduler->get_sync_status());
```

Run it via command line:
```bash
php test-sync.php
```

## Test Scenarios

### Scenario 1: Fresh Installation

**Steps:**
1. Install and activate the plugin
2. Authenticate with Amrod credentials
3. Check that schedules are automatically set
4. Wait for 00:30 GMT+2 or trigger manually

**Expected Results:**
- ✅ Full sync scheduled for 00:30 GMT+2
- ✅ Incremental sync scheduled for every 5 hours
- ✅ Incremental sync won't run until full sync completes

### Scenario 2: Test Full Sync

**Steps:**
1. Go to test script: `test-incremental-sync.php`
2. Click "Test Full Sync" button
3. Check WordPress dashboard
4. Monitor sync progress

**Expected Results:**
- ✅ All products synced from API
- ✅ All stock levels synced
- ✅ All prices synced
- ✅ All categories synced
- ✅ Timestamp `bytemash_last_full_sync` is updated

**Verify:**
```php
// Check last full sync time
$last_full = get_option('bytemash_last_full_sync');
echo "Last full sync: " . $last_full;
```

### Scenario 3: Test Incremental Sync

**Prerequisites:**
- Full sync must be completed first

**Steps:**
1. Ensure full sync completed
2. Go to test script
3. Click "Test Incremental Sync" button
4. Check logs

**Expected Results:**
- ✅ Only changed products are synced
- ✅ Only changed stock is synced
- ✅ Only changed prices are synced
- ✅ Timestamp `bytemash_last_incremental_sync` is updated
- ✅ API timestamps stored for duplicate prevention

**Verify:**
```php
// Check incremental sync time
$last_incremental = get_option('bytemash_last_incremental_sync');
echo "Last incremental sync: " . $last_incremental;

// Check API timestamps
$stock_timestamp = get_option('bytemash_api_last_stock_update');
echo "Last stock API timestamp: " . $stock_timestamp;
```

### Scenario 4: Test Prerequisite Check

**Steps:**
1. Clear all timestamps (use test script button)
2. Try to run incremental sync
3. Check logs

**Expected Results:**
- ❌ Incremental sync is blocked
- ⚠️ Warning logged: "No full sync completed yet"
- ✅ System prevents running without full sync

### Scenario 5: Test Duplicate Prevention

**Steps:**
1. Run incremental sync
2. Immediately run it again (without waiting)
3. Check logs

**Expected Results:**
- ✅ Second run detects no new updates
- ✅ Message: "No stock updates available"
- ✅ No duplicate processing

### Scenario 6: Test Cron Scheduling

**Steps:**
1. Check scheduled events:
   ```bash
   wp cron event list
   ```

2. Look for:
   - `bytemash_full_sync_cron`
   - `bytemash_incremental_sync_cron`

**Expected Results:**
- ✅ Full sync scheduled for next 00:30 GMT+2
- ✅ Incremental sync scheduled for next 5-hour interval
- ✅ Events repeat on their intervals

## Monitoring and Logs

### Check Sync Logs

**Via WordPress Admin:**
```
Dashboard → Amrod Sync → Sync Logs
```

**Via Database:**
```sql
SELECT * FROM wp_bytemash_sync_logs 
WHERE sync_type IN ('full_sync', 'incremental_sync')
ORDER BY created_at DESC 
LIMIT 20;
```

### Important Log Messages

**Full Sync:**
```
[INFO] Running full sync (daily reset)
[INFO] Starting full product sync
[INFO] Starting full stock sync
[INFO] Starting full price sync
[SUCCESS] Full sync completed
```

**Incremental Sync:**
```
[INFO] Running incremental sync
[INFO] Last full sync: 2024-01-15 00:30:00
[INFO] Starting incremental product sync
[INFO] Starting incremental stock sync
[INFO] No stock updates available (or) Ready to sync X updates
[SUCCESS] Incremental sync completed
```

**Prerequisite Check:**
```
[WARNING] Incremental sync skipped: No full sync completed yet
```

### Check Timestamps

```php
// Get all sync timestamps
$timestamps = array(
    'Last Full Sync' => get_option('bytemash_last_full_sync'),
    'Last Incremental' => get_option('bytemash_last_incremental_sync'),
    'API Products' => get_option('bytemash_api_last_product_update'),
    'API Stock' => get_option('bytemash_api_last_stock_update'),
    'API Prices' => get_option('bytemash_api_last_price_update'),
);

print_r($timestamps);
```

## Common Issues and Solutions

### Issue 1: Schedules Not Running

**Symptoms:**
- Cron events are scheduled but not executing

**Solutions:**
1. Check if WP-Cron is enabled:
   ```php
   if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
       echo "WP-Cron is disabled. Set up server cron.";
   }
   ```

2. Set up server cron:
   ```bash
   # Add to crontab
   */5 * * * * wget -q -O - https://your-site.com/wp-cron.php?doing_wp_cron &>/dev/null
   ```

3. Trigger manually for testing:
   ```bash
   wp cron event run bytemash_full_sync_cron
   ```

### Issue 2: Incremental Sync Not Running

**Symptoms:**
- Full sync works but incremental doesn't run

**Check:**
```php
$last_full = get_option('bytemash_last_full_sync');
if (!$last_full) {
    echo "Full sync not completed yet - this is required!";
}
```

**Solution:**
- Run full sync first
- Wait for 00:30 GMT+2 or trigger manually

### Issue 3: Duplicate Data Processing

**Symptoms:**
- Same data being processed multiple times

**Check:**
```php
// Verify timestamp tracking
$api_timestamps = array(
    'stock' => get_option('bytemash_api_last_stock_update'),
    'prices' => get_option('bytemash_api_last_price_update'),
);

if (empty($api_timestamps['stock'])) {
    echo "API timestamps not being stored!";
}
```

**Solution:**
- Check if API responses include `lastUpdated` field
- Review logs for timestamp storage messages

### Issue 4: Wrong Timezone

**Symptoms:**
- Full sync runs at wrong time

**Check:**
```php
// WordPress timezone
$wp_timezone = get_option('timezone_string');
echo "WP Timezone: " . $wp_timezone;

// Next scheduled time
$next = wp_next_scheduled('bytemash_full_sync_cron');
echo "Next run: " . date_i18n('Y-m-d H:i:s T', $next);
```

**Solution:**
- Set WordPress timezone to Africa/Johannesburg (GMT+2)
- Or adjust calculation in scheduler

## Performance Testing

### Test with Large Dataset

1. **Sync 1000+ products:**
   ```php
   // Monitor memory and time
   $start_memory = memory_get_usage();
   $start_time = microtime(true);
   
   $scheduler->run_full_sync();
   
   $memory_used = (memory_get_usage() - $start_memory) / 1024 / 1024;
   $time_taken = microtime(true) - $start_time;
   
   echo "Memory used: {$memory_used} MB\n";
   echo "Time taken: {$time_taken} seconds\n";
   ```

2. **Expected Performance:**
   - Full sync: < 5 minutes for 1000 products
   - Incremental sync: < 1 minute for 50 updates
   - Memory: < 256MB

## Production Checklist

Before going live, verify:

- [ ] Authentication works and tokens refresh automatically
- [ ] Full sync scheduled for 00:30 GMT+2
- [ ] Incremental sync scheduled every 5 hours
- [ ] Server cron set up (recommended)
- [ ] Logs are being written
- [ ] Timestamps are being tracked
- [ ] No PHP errors in error log
- [ ] Memory limits are adequate (512MB recommended)
- [ ] Email notifications configured (optional)
- [ ] Backup system in place

## API Compliance Verification

### According to Amrod API Documentation:

✅ **Full Stock Sync (00:30 GMT+2):**
- Clears and repopulates all data
- Runs once daily at specified time
- Creates baseline for incremental updates

✅ **Incremental Stock Updates:**
- Only returns changes since 00:30 reset
- Should only be called after full sync
- Includes `lastUpdated` timestamp
- Returns rolling changes, not duplicates

✅ **Proper Usage:**
- Full sync first, then incremental throughout day
- Timestamps used to filter duplicate data
- No incremental sync before full sync completes

## Additional Resources

- **Amrod API Documentation:** https://newapidocs.amrod.co.za/
- **WordPress Cron:** https://developer.wordpress.org/plugins/cron/
- **WP-CLI Cron:** https://developer.wordpress.org/cli/commands/cron/

## Support

If you encounter issues:

1. Check the test script output
2. Review sync logs
3. Enable WordPress debug mode
4. Check PHP error log
5. Contact support with log excerpts

---

**Last Updated:** October 2024  
**Plugin Version:** 2.7.0


