# 🚀 Quick Start: Testing Incremental Sync

## 3-Minute Test

### 1. Load Test Dashboard
```
https://your-site.com/wp-content/plugins/bytemash-woo-sync/test-incremental-sync.php
```

### 2. Check Status
✅ Authentication connected  
✅ Cron schedules set  
✅ Timestamps tracking  

### 3. Run Tests
Click buttons:
- **"Test Full Sync"** → Wait for completion
- **"Test Incremental Sync"** → Verify only changes processed

---

## Command Line Testing

```bash
# Check scheduled events
wp cron event list | grep bytemash

# Expected output:
# bytemash_full_sync_cron        Tomorrow at 00:30
# bytemash_incremental_sync_cron In 3 hours

# Run full sync now
wp cron event run bytemash_full_sync_cron

# Run incremental sync now
wp cron event run bytemash_incremental_sync_cron

# Check sync status
wp option get bytemash_last_full_sync
wp option get bytemash_last_incremental_sync
```

---

## WordPress Admin Testing

1. **Go to:** Dashboard → Amrod Sync → Settings
2. **Check:** Sync Status section
3. **Verify:** 
   - Last Full Sync timestamp
   - Last Incremental Sync timestamp  
   - Next scheduled times

---

## What to Look For

### ✅ Success Indicators

- **Full Sync:**
  - Runs at 00:30 GMT+2 daily
  - Processes ALL products, stock, prices
  - Updates `bytemash_last_full_sync` timestamp
  
- **Incremental Sync:**
  - Runs every 5 hours (configurable)
  - Only runs AFTER full sync completed
  - Processes only CHANGED data
  - Updates `bytemash_last_incremental_sync` timestamp

### ❌ Issues to Check

- Incremental running before full sync → Should be blocked
- Same data processed twice → Check timestamps
- Cron not executing → Check WP-Cron status
- Wrong time zone → Verify WordPress timezone setting

---

## One-Line Status Check

```php
<?php
// Quick status check - paste in functions.php temporarily
add_action('admin_init', function() {
    if (current_user_can('manage_options')) {
        $status = array(
            'Last Full Sync' => get_option('bytemash_last_full_sync') ?: 'Never',
            'Last Incremental' => get_option('bytemash_last_incremental_sync') ?: 'Never',
            'Next Full' => wp_next_scheduled('bytemash_full_sync_cron') ? date('Y-m-d H:i', wp_next_scheduled('bytemash_full_sync_cron')) : 'Not scheduled',
            'Next Incremental' => wp_next_scheduled('bytemash_incremental_sync_cron') ? date('Y-m-d H:i', wp_next_scheduled('bytemash_incremental_sync_cron')) : 'Not scheduled',
        );
        error_log('Sync Status: ' . print_r($status, true));
    }
});
?>
```

Check your `debug.log` for the output.

---

## Server Cron Setup (Production)

For reliable production scheduling, add to your server crontab:

```bash
# Edit crontab
crontab -e

# Add these lines (adjust URL to your site):
30 22 * * * wget -q -O - https://your-site.com/wp-cron.php?doing_wp_cron &>/dev/null
0 */5 * * * wget -q -O - https://your-site.com/wp-cron.php?doing_wp_cron &>/dev/null
```

**Note:** 22:30 UTC = 00:30 GMT+2 (South Africa time)

---

## Troubleshooting Commands

```bash
# Clear all schedules and start fresh
wp cron event delete bytemash_full_sync_cron --all
wp cron event delete bytemash_incremental_sync_cron --all

# Then go to Settings page and save to reinitialize

# Check if WP-Cron is disabled
wp config get DISABLE_WP_CRON

# View recent logs
wp db query "SELECT * FROM wp_bytemash_sync_logs ORDER BY created_at DESC LIMIT 10"
```

---

## Need More Details?

📖 Full testing guide: `documentation/INCREMENTAL-SYNC-TESTING.md`

🔧 Test script: `test-incremental-sync.php`

📊 Dashboard: Dashboard → Amrod Sync

---

**Quick Test Success Criteria:**

1. ✅ Full sync completes without errors
2. ✅ `bytemash_last_full_sync` timestamp is set
3. ✅ Incremental sync only runs after full sync
4. ✅ Incremental sync processes fewer items than full sync
5. ✅ No duplicate data processing
6. ✅ Cron schedules are active

**Done!** 🎉


