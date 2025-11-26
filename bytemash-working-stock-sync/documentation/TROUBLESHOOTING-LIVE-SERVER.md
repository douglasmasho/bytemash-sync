# Troubleshooting Guide: Local vs Live Server Issues

## 🔍 Common Issues When Moving from Local to Live

This guide addresses the common reasons why the ByteMash WooSync plugin works locally but fails on a live server.

---

## 📌 Quick Diagnostics

**First, run the diagnostics tool:**
1. Go to: `https://your-site.com/wp-content/plugins/bytemash-woo-sync/diagnostics.php`
2. Login as an administrator
3. Check all the status indicators

---

## Issue #1: Assets Not Loading (Buttons Don't Work, Styling Off)

### Symptoms:
- Buttons don't respond to clicks
- Styling looks broken or missing
- No JavaScript errors in console (or `bytemashWooSync is not defined`)

### Causes & Solutions:

#### A. **Caching Issues** (Most Common)

**Browser Cache:**
```
Solution: Hard refresh the page
- Windows/Linux: Ctrl + Shift + R
- Mac: Cmd + Shift + R
```

**WordPress Cache Plugin:**
```
Solution: Clear cache in your caching plugin
- WP Super Cache: Settings → Delete Cache
- W3 Total Cache: Performance → Dashboard → Empty All Caches
- WP Rocket: Settings → Clear Cache
```

**Server Cache:**
```
Solution: Clear server-side cache
- cPanel: File Manager → Delete cache folders
- Plesk: Tools & Settings → Website Caching → Clear
- Cloudflare: Caching → Purge Everything
```

**CDN Cache:**
```
Solution: Purge CDN cache
- Cloudflare: Caching → Purge Everything
- Other CDNs: Check your provider's documentation
```

#### B. **File Permissions**

Check file permissions on your live server:
```bash
# Assets should be readable
chmod 644 assets/css/admin.css
chmod 644 assets/js/admin.js

# Directories should be executable
chmod 755 assets/
chmod 755 assets/css/
chmod 755 assets/js/
```

#### C. **URL/Path Mismatches**

The plugin uses these constants:
```php
BYTEMASH_WOO_SYNC_PLUGIN_URL  // Should point to plugin URL
BYTEMASH_WOO_SYNC_PLUGIN_DIR  // Should point to plugin directory
```

**Check in diagnostics.php** - these should match your actual URLs.

If they don't match, it might be due to:
- Symbolic links in your hosting setup
- Different WordPress installation paths
- Custom wp-content directory locations

#### D. **Missing jQuery**

The plugin requires jQuery. If your theme disables it:

**Solution:** The plugin now force-loads jQuery, but you can verify by checking browser console:
```javascript
// Type this in browser console:
typeof jQuery
// Should return: "function"
```

---

## Issue #2: WordPress Cron Not Running

### Symptoms:
- Scheduled syncs never run
- Manual syncs work, but automatic syncs don't
- Cron schedules show as "Not scheduled" in diagnostics

### Causes & Solutions:

#### A. **WP-Cron Disabled**

Many live servers disable WordPress cron for performance.

**Check if disabled:**
```php
// In wp-config.php, look for:
define('DISABLE_WP_CRON', true);
```

**Solution 1: Use System Cron (Recommended)**

Add to your server's crontab:
```bash
*/5 * * * * wget -q -O - "https://your-site.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```

Or use this alternative (using curl):
```bash
*/5 * * * * curl -s "https://your-site.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```

**How to add to crontab:**

For cPanel:
1. Cron Jobs → Add New Cron Job
2. Minute: `*/5`
3. Hour: `*`
4. Command: `wget -q -O - "https://your-site.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1`

For SSH access:
```bash
crontab -e
# Add the line above, save and exit
```

**Solution 2: Use Plugin's Cron Manager**

1. Go to: WP Admin → Amrod Sync → Dashboard
2. Scroll to "Scheduled Sync Monitoring"
3. Click "Enable Production System Cron"

#### B. **Low Traffic Site**

WordPress cron requires site visitors to trigger it.

**Solution:** Set up system cron (see above) or use an external cron service:
- [EasyCron](https://www.easycron.com/)
- [cron-job.org](https://cron-job.org/)
- [UptimeRobot](https://uptimerobot.com/) (as monitor)

Configure to ping: `https://your-site.com/wp-cron.php?doing_wp_cron` every 5 minutes

---

## Issue #3: AJAX Calls Failing

### Symptoms:
- Buttons seem to work but nothing happens
- Console shows 404 or 403 errors on admin-ajax.php
- "AJAX call failed" messages

### Causes & Solutions:

#### A. **Security Plugins Blocking AJAX**

Some security plugins block admin-ajax.php.

**Solution:** Whitelist admin-ajax.php in your security plugin:
- Wordfence: Firewall → Whitelist admin-ajax.php
- Sucuri: Settings → Whitelist
- iThemes Security: Settings → HTTP Referrer Check → Disable

#### B. **ModSecurity Rules**

Server-level security might block requests.

**Solution:** Contact your host to whitelist admin-ajax.php or add to .htaccess:
```apache
<IfModule mod_security.c>
    SecRuleRemoveById 340162
    SecRuleRemoveById 340163
</IfModule>
```

#### C. **Nonce Expiration**

Nonces expire after 12-24 hours and can be cached.

**Solution:** The plugin now generates fresh nonces on every page load. If still failing:
1. Clear all caches
2. Logout and login again
3. Hard refresh the page

---

## Issue #4: Database Issues

### Symptoms:
- Syncs start but never complete
- Queue table errors
- Database connection errors

### Causes & Solutions:

#### A. **Missing Tables**

**Solution:**
1. Deactivate the plugin
2. Reactivate the plugin
3. Tables will be recreated

#### B. **Database Size Limits**

Some hosts limit database sizes.

**Solution:** Check your database size in hosting control panel. If near limit:
1. Clear old sync logs: Amrod Sync → Logs → Clear All Logs
2. Clean up failed queue items:
```sql
DELETE FROM wp_bytemash_sync_queue WHERE status != 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
```

#### C. **Max Packet Size**

Large syncs might exceed `max_allowed_packet`.

**Solution:** Ask your host to increase it, or add to wp-config.php:
```php
@ini_set('mysql.connect_timeout', 300);
@ini_set('default_socket_timeout', 300);
```

---

## Issue #5: Memory/Timeout Issues

### Symptoms:
- Syncs fail midway
- White screen of death
- 500 Internal Server Error

### Causes & Solutions:

#### A. **Low PHP Memory Limit**

**Check current limit:** See diagnostics.php

**Solution:** Increase in wp-config.php:
```php
define('WP_MEMORY_LIMIT', '512M');
define('WP_MAX_MEMORY_LIMIT', '512M');
```

Or contact your host to increase it.

#### B. **Execution Timeout**

**Solution:** Increase in wp-config.php:
```php
@ini_set('max_execution_time', 300);
@set_time_limit(300);
```

Or add to .htaccess:
```apache
php_value max_execution_time 300
```

#### C. **Batch Size Too Large**

**Solution:**
1. Go to Settings → Sync Settings
2. Reduce batch size from 10 to 5
3. Try syncing again

---

## Issue #6: API Connection Issues

### Symptoms:
- "Authentication failed" messages
- "Connection test failed"
- Syncs work locally but not on live

### Causes & Solutions:

#### A. **Firewall Blocking Outgoing Connections**

**Solution:** Ask your host to whitelist:
- `identity.amrod.co.za` (port 443)
- `vendorapi.amrod.co.za` (port 443)

#### B. **SSL Certificate Issues**

**Solution:** Update CA certificates on server, or temporarily disable SSL verification (not recommended for production):
```php
// In wp-config.php (temporary test only):
define('BYTEMASH_DISABLE_SSL_VERIFY', true);
```

#### C. **IP Restrictions**

Some hosts restrict outgoing connections.

**Solution:** Contact your host and ask them to allow connections to Amrod API endpoints.

---

## 🛠️ Emergency Troubleshooting Steps

If nothing works, try these steps in order:

### 1. **Enable WordPress Debug Mode**
```php
// Add to wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
@ini_set('display_errors', 0);
```

Then check `/wp-content/debug.log` for errors.

### 2. **Disable Other Plugins**
```
Temporarily deactivate all other plugins except:
- WooCommerce
- ByteMash WooSync

Test if it works now. If yes, reactivate plugins one by one to find the conflict.
```

### 3. **Switch to Default Theme**
```
Temporarily switch to Twenty Twenty-Three or another default WordPress theme.
Test if it works now. If yes, there's a theme conflict.
```

### 4. **Clear Everything**
```bash
# Browser cache: Ctrl+Shift+R
# WordPress cache: Delete all cache plugin data
# Object cache: Flush Redis/Memcached if using
# Server cache: Clear via cPanel/Plesk
# Database: Clear transients
```

SQL to clear transients:
```sql
DELETE FROM wp_options WHERE option_name LIKE '_transient_%';
DELETE FROM wp_options WHERE option_name LIKE '_site_transient_%';
```

### 5. **Reupload Plugin Files**
```
1. Download fresh plugin files from your source
2. Delete OLD plugin via FTP (keep database)
3. Upload NEW plugin via FTP
4. Go to Plugins → Activate
```

### 6. **Database Cleanup**
```sql
-- Clear all sync queues
TRUNCATE TABLE wp_bytemash_sync_queue;

-- Clear old logs
DELETE FROM wp_bytemash_sync_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Remove orphaned options
DELETE FROM wp_options WHERE option_name LIKE 'bytemash_sync_%' AND option_name NOT IN (
    'bytemash_sync_products',
    'bytemash_sync_stock',
    'bytemash_sync_prices',
    'bytemash_sync_categories',
    'bytemash_sync_brands'
);
```

---

## 📊 Monitoring & Verification

### After Fixing Issues:

1. **Check diagnostics.php** - All checks should be green/yellow
2. **Test manual sync** - Dashboard → Sync → Test a small sync
3. **Monitor logs** - Dashboard → Logs → Watch for errors
4. **Verify cron** - Dashboard → Scheduled Sync Monitoring → Check next run times
5. **Test scheduled sync** - Wait for scheduled time or trigger manually

### Set Up Monitoring:

Use [UptimeRobot](https://uptimerobot.com/) or similar to:
1. Monitor your site uptime
2. Ping wp-cron.php every 5 minutes
3. Alert you if syncs fail

---

## 🆘 Still Not Working?

### Collect This Information:

1. **Server Info:**
   - Hosting provider
   - PHP version
   - WordPress version
   - WooCommerce version

2. **Error Messages:**
   - Browser console errors (F12)
   - WordPress debug.log
   - Server error logs

3. **Diagnostics Output:**
   - Full screenshot of diagnostics.php
   - Any red/failing checks

4. **What Works:**
   - Does it work locally?
   - Do manual syncs work on live?
   - Do scheduled syncs work on live?

### Contact Support:

Provide all the information above when seeking help.

---

## ✅ Prevention Checklist

Before deploying to live server:

- [ ] Test on staging environment first
- [ ] Verify all assets load correctly
- [ ] Set up system cron if WP_CRON is disabled
- [ ] Configure proper PHP memory/timeout limits
- [ ] Whitelist API endpoints in firewall
- [ ] Set up monitoring for cron jobs
- [ ] Document your server-specific configuration
- [ ] Create database backups before large syncs

---

## 📝 Version History

- v1.0.0: Initial troubleshooting guide
- v1.1.2: Added diagnostics tool and enhanced asset loading

---

## 🔗 Related Documentation

- [README.md](../README.md) - General plugin documentation
- [USAGE-GUIDE.md](./USAGE-GUIDE.md) - How to use the plugin
- [API-REFERENCE.md](./API-REFERENCE.md) - API integration details

