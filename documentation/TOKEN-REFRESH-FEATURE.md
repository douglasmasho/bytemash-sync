# Automatic Token Refresh Feature

## Overview

The plugin now includes **automatic token refresh** functionality. When a 401 Unauthorized error is detected (indicating an expired or invalid token), the plugin will automatically re-authenticate using stored credentials and retry the failed request.

---

## How It Works

### Normal Flow (Token Valid):
```
1. Make API request with current token
2. Receive successful response (200 OK)
3. Process data
```

### Token Expired Flow (Automatic Recovery):
```
1. Make API request with expired token
2. Receive 401 Unauthorized response
3. 🔄 AUTOMATICALLY re-authenticate using stored credentials
4. Retry original request with new token
5. Receive successful response (200 OK)
6. Process data
```

### Flow Without Stored Credentials:
```
1. Make API request with expired token
2. Receive 401 Unauthorized response
3. No stored credentials found
4. Return error asking user to re-authenticate manually
```

---

## Features

### ✅ Seamless Token Renewal
- Automatically detects 401 Unauthorized errors
- Re-authenticates in the background
- Retries the original request
- No user intervention needed

### ✅ Prevents Sync Failures
- Scheduled syncs won't fail due to expired tokens
- Background operations continue uninterrupted
- Automatic recovery from authentication issues

### ✅ Secure Credential Storage
- Credentials stored in WordPress options table
- Password encoded using base64 (basic obfuscation)
- Same security level as WordPress core
- Credentials only used for automatic re-authentication

### ✅ Comprehensive Logging
- Logs when 401 is detected
- Logs token refresh attempts
- Logs success or failure of refresh
- Helps troubleshoot authentication issues

### ✅ Infinite Loop Prevention
- Only retries request once after token refresh
- Prevents endless authentication loops
- Clear error messages if refresh fails

---

## When Credentials Are Stored

Credentials are automatically stored when you authenticate through the plugin settings:

1. Go to **ByteMash Amrod Sync > Settings**
2. Enter your Amrod credentials:
   - Username
   - Password
   - Customer Code (optional)
3. Click "Test Connection" or "Save Settings"
4. ✅ Credentials are stored for automatic token refresh

**Note:** Credentials are stored in the WordPress database options table. They are encoded but not encrypted. Ensure your WordPress installation is secure.

---

## Security Considerations

### Storage Method:
- **Username:** Stored as plain text in `wp_options` table
- **Password:** Stored as base64-encoded string (obfuscation, not encryption)
- **Token:** Stored as plain text in `wp_options` table

### Security Recommendations:
1. ✅ Use a dedicated Amrod API account (not admin account)
2. ✅ Limit API account permissions to minimum required
3. ✅ Keep WordPress core, plugins, and themes updated
4. ✅ Use strong WordPress database passwords
5. ✅ Implement WordPress security best practices
6. ✅ Regular security audits of your WordPress installation

### Why Base64 Instead of Encryption?
- WordPress doesn't have a standard encryption key system
- Base64 prevents casual viewing in database exports
- True encryption would require key management (adds complexity)
- Security relies on WordPress database security

---

## Usage Examples

### Example 1: Scheduled Sync with Expired Token

```php
// 3:00 AM - Scheduled sync starts
// Token expired at 2:00 AM

// Plugin makes API request
$products = $api_client->get_products_with_branding();

// Internal flow:
// 1. Request sent with expired token
// 2. 401 Unauthorized received
// 3. Plugin automatically re-authenticates
// 4. New token obtained
// 5. Request retried with new token
// 6. Products successfully retrieved
// ✅ Sync completes successfully
```

### Example 2: Manual Sync After Long Inactivity

```php
// User hasn't synced in 48 hours (token expired)
// User clicks "Sync All Products"

// Plugin makes API request
$result = $api_client->get_products_with_branding();

// Internal flow:
// 1. Request sent with expired token
// 2. 401 Unauthorized received
// 3. Plugin checks for stored credentials
// 4. Credentials found
// 5. Automatic re-authentication
// 6. New token obtained
// 7. Request retried
// ✅ Sync completes without user noticing any issue
```

### Example 3: First Authentication (No Stored Credentials)

```php
// Fresh installation, no credentials stored yet
// User tries to sync without authenticating

// Plugin makes API request
$result = $api_client->get_products();

// Flow:
// 1. No token found
// 2. Error: "API token not configured"
// 3. User must authenticate manually via settings

// After authentication:
// 1. Credentials stored
// 2. Token obtained
// 3. ✅ Future 401 errors will auto-refresh
```

---

## Logging

All token refresh activities are logged for monitoring and troubleshooting.

### Log Location:
```
wp-content/plugins/bytemash-woo-sync/logs/debug.log
```

### Log Examples:

#### Successful Token Refresh:
```
[WARNING] 401 Unauthorized - Attempting to refresh token
- url: https://vendorapi.amrod.co.za/api/v1/Products/GetProductsAndBranding
- status_code: 401

[INFO] Attempting automatic token refresh

[SUCCESS] Authentication successful
- token_preview: eyJhbGciOiJIUzI1NiIs...
- credentials_stored: true

[SUCCESS] Token refreshed successfully
- username: your-username

[SUCCESS] Token refreshed successfully, retrying original request
- url: https://vendorapi.amrod.co.za/api/v1/Products/GetProductsAndBranding

[INFO] API Request Success
- data_count: 3542
```

#### Failed Token Refresh (Invalid Credentials):
```
[WARNING] 401 Unauthorized - Attempting to refresh token

[INFO] Attempting automatic token refresh

[ERROR] Authentication error
- status_code: 401
- body: Invalid credentials

[ERROR] Token refresh failed
- error: Authentication failed: Invalid credentials

[ERROR] Failed to refresh token after 401
```

#### No Stored Credentials:
```
[WARNING] 401 Unauthorized - Attempting to refresh token

[ERROR] Cannot refresh token: credentials not stored

[ERROR] Failed to refresh token after 401
- error: No stored credentials available for token refresh
```

---

## Configuration

### Automatic (Default):
Token refresh is **enabled by default**. No configuration needed.

### Manual Re-authentication:
If you want to change credentials or force a new token:

1. Go to **ByteMash Amrod Sync > Settings**
2. Enter new credentials
3. Click "Test Connection"
4. New credentials will be stored
5. New token will be generated

---

## Troubleshooting

### Issue: Getting repeated 401 errors

**Possible Causes:**
1. Stored credentials are incorrect
2. Amrod account is locked or disabled
3. API permissions changed

**Solution:**
1. Check your logs: `wp-content/plugins/bytemash-woo-sync/logs/debug.log`
2. Look for "Token refresh failed" messages
3. Go to Settings and re-enter credentials
4. Test connection to verify credentials are valid

### Issue: "No stored credentials available"

**Cause:** Credentials weren't stored during initial authentication

**Solution:**
1. Go to **ByteMash Amrod Sync > Settings**
2. Re-enter your Amrod credentials
3. Click "Save Settings" or "Test Connection"
4. Credentials will now be stored for automatic refresh

### Issue: Token refresh works but sync still fails

**Possible Causes:**
1. API endpoint issues
2. Network connectivity problems
3. Server resource limitations

**Solution:**
1. Check logs for specific error messages
2. Test API connection from Settings page
3. Verify internet connectivity
4. Check server resources (memory, CPU)

---

## Technical Details

### Implementation:

**Modified Method:**
```php
private function request($endpoint, $method = 'GET', $body = null, $params = array(), $retry = true)
```

**New Method:**
```php
private function refresh_token()
```

**Detection Logic:**
```php
if ($status_code === 401 && $retry) {
    // Attempt token refresh
    $refresh_result = $this->refresh_token();
    
    if (!is_wp_error($refresh_result)) {
        // Retry with new token (retry = false to prevent loop)
        return $this->request($endpoint, $method, $body, $params, false);
    }
}
```

**Stored Options:**
- `bytemash_amrod_username` - Username for Amrod API
- `bytemash_amrod_password` - Base64-encoded password
- `bytemash_amrod_customer_code` - Customer code (optional)
- `bytemash_amrod_api_token` - Current authentication token
- `bytemash_amrod_token_expiry` - Token expiration timestamp

---

## Best Practices

### ✅ DO:
- Let the plugin handle token refresh automatically
- Monitor logs for authentication issues
- Use dedicated API credentials
- Keep WordPress secure
- Test after initial setup

### ❌ DON'T:
- Manually modify stored credentials in database
- Share your WordPress database credentials
- Ignore repeated authentication failures
- Use admin account credentials for API

---

## Compatibility

### WordPress Versions:
- ✅ WordPress 5.8+

### PHP Versions:
- ✅ PHP 7.4+
- ✅ PHP 8.0+
- ✅ PHP 8.1+
- ✅ PHP 8.2+

### WooCommerce Versions:
- ✅ WooCommerce 6.0+

---

## Benefits

1. **Uninterrupted Operations**
   - Scheduled syncs continue even if token expires
   - No manual intervention needed
   - Automatic recovery from authentication issues

2. **Better User Experience**
   - Users don't encounter authentication errors
   - Seamless background operations
   - Reliable automation

3. **Reduced Maintenance**
   - Less manual token management
   - Fewer support requests
   - Automatic handling of common issues

4. **Improved Reliability**
   - Syncs don't fail due to expired tokens
   - Background jobs complete successfully
   - Better data consistency

---

## Summary

✅ **Automatic token refresh on 401 errors**
✅ **Secure credential storage**
✅ **Prevents sync failures**
✅ **Comprehensive logging**
✅ **No user intervention needed**
✅ **Infinite loop prevention**

The token refresh feature ensures your scheduled syncs and background operations continue without interruption, even when authentication tokens expire. This is a critical feature for production environments where reliability is essential.

---

## Version Information

**Feature Added:** Version 1.0.2
**Release Date:** October 11, 2025

---

## Need Help?

If you experience issues with automatic token refresh:

1. Check logs: `logs/debug.log`
2. Verify credentials in Settings
3. Test API connection
4. Contact support with log excerpts

**The plugin now handles authentication automatically - enjoy worry-free syncing! 🎉**

