# Development Tools 🛠️

## Overview

Development-only buttons have been added to the plugin settings page to help with testing and debugging. These tools allow you to quickly clear all data and start fresh.

## 🔧 **Available Tools**

### 1. **Clear Everything** (Red Button)
- ✅ Deletes ALL products
- ✅ Deletes ALL product categories
- ✅ Deletes ALL product brands
- ✅ Deletes ALL product attributes
- ✅ Clears ALL sync data
- ✅ Clears ALL logs and queue
- ✅ Clears ALL transients
- ✅ Flushes ALL caches

### 2. **Clear Products Only** (Orange Button)
- ✅ Deletes ALL products
- ✅ Keeps categories and brands
- ✅ Clears WooCommerce cache

### 3. **Clear Logs & Queue** (Gray Button)
- ✅ Clears sync data
- ✅ Clears queue table
- ✅ Clears logs
- ✅ Clears transients

---

## 🚀 **How to Use**

### **Step 1: Enable Development Mode**
Make sure `WP_DEBUG` is enabled in your `wp-config.php`:
```php
define('WP_DEBUG', true);
```

### **Step 2: Access Development Tools**
1. Go to **WordPress Admin → Amrod Sync → Settings**
2. Scroll down to the **"Development Tools"** section
3. You'll see three colored buttons

### **Step 3: Use the Tools**
- **Red Button**: Clears everything (use for complete reset)
- **Orange Button**: Clears products only (keeps categories/brands)
- **Gray Button**: Clears logs and queue (for debugging)

---

## ⚠️ **Safety Features**

### **Development Only**
- ✅ Only appears when `WP_DEBUG` is enabled
- ✅ Automatically hidden in production
- ✅ Security checks and nonces
- ✅ Admin permission required

### **Confirmation Dialogs**
- ✅ JavaScript confirmation before action
- ✅ Clear warning messages
- ✅ Cannot be accidentally clicked

### **Success Messages**
- ✅ Clear feedback after each action
- ✅ Specific messages for each tool
- ✅ Redirects back to settings page

---

## 🏭 **Production Deployment**

### **To Hide Development Tools in Production:**

#### **Method 1: Disable WP_DEBUG (Recommended)**
```php
// In wp-config.php
define('WP_DEBUG', false);
```

#### **Method 2: Remove Development Section (Manual)**
If you want to completely remove the development tools:

1. **Open:** `admin/class-admin-settings.php`
2. **Find:** The development section (around line 379)
3. **Delete:** The entire `<?php if (defined('WP_DEBUG') && WP_DEBUG) : ?>` block
4. **Save:** The file

#### **Method 3: Comment Out (Quick)**
```php
// Comment out the development section
/*
<?php if (defined('WP_DEBUG') && WP_DEBUG) : ?>
    <!-- Development Section -->
    ...
<?php endif; ?>
*/
```

---

## 🔍 **What Gets Deleted**

### **Clear Everything:**
- All WooCommerce products
- All product categories
- All product brands
- All product attributes
- All sync data (`bytemash_sync_*` options)
- All Amrod data (`bytemash_amrod_*` options)
- All queue data (`bytemash_sync_queue` table)
- All logs (`bytemash_sync_logs` table)
- All transients (`_transient_bytemash_*`)
- All WooCommerce caches

### **Clear Products Only:**
- All WooCommerce products
- WooCommerce product caches
- WordPress object cache

### **Clear Logs & Queue:**
- All sync data
- All queue data
- All logs
- All transients
- WordPress object cache

---

## 🧪 **Testing Workflow**

### **Complete Reset Test:**
1. Click **"Clear Everything"**
2. Go to **Dashboard → Sync Products**
3. Test the new product type logic
4. Verify products are created correctly

### **Partial Reset Test:**
1. Click **"Clear Products Only"**
2. Keep categories and brands
3. Test product sync with existing categories
4. Verify category assignments

### **Debug Test:**
1. Click **"Clear Logs & Queue"**
2. Start a fresh sync
3. Monitor logs for issues
4. Debug any problems

---

## 📝 **Code Structure**

### **Settings Page:**
```php
// Development section only shows when WP_DEBUG is true
<?php if (defined('WP_DEBUG') && WP_DEBUG) : ?>
    <!-- Development Tools -->
<?php endif; ?>
```

### **Security Checks:**
```php
// Only allow in development mode
if (!defined('WP_DEBUG') || !WP_DEBUG) {
    wp_die('Development tools are only available when WP_DEBUG is enabled');
}

// Security nonce verification
if (!wp_verify_nonce($_POST['bytemash_dev_clear_all_nonce'], 'bytemash_dev_clear_all')) {
    wp_die('Security check failed');
}

// Admin permission check
if (!current_user_can('manage_options')) {
    wp_die('You do not have permission to perform this action');
}
```

---

## 🎯 **Benefits**

### **For Development:**
- ✅ Quick data reset for testing
- ✅ No manual database cleanup
- ✅ Fresh start for each test
- ✅ Isolated testing environment

### **For Production:**
- ✅ Automatically hidden
- ✅ No security risks
- ✅ Clean production code
- ✅ No performance impact

---

## 🚨 **Important Notes**

### **⚠️ WARNING:**
- These tools **permanently delete data**
- **Cannot be undone**
- **Use only in development**
- **Never use on production data**

### **🔒 Security:**
- Only available when `WP_DEBUG` is enabled
- Requires admin permissions
- Uses WordPress nonces
- Confirmation dialogs required

### **🏭 Production:**
- Tools are automatically hidden
- No performance impact
- No security risks
- Clean production code

---

## 📋 **Quick Reference**

| Tool | What It Deletes | Use Case |
|------|-----------------|----------|
| **Clear Everything** | All data | Complete reset |
| **Clear Products Only** | Products only | Test with existing categories |
| **Clear Logs & Queue** | Logs and queue | Debug sync issues |

---

**Status:** ✅ Ready for Development  
**Safety:** ✅ Development only  
**Production:** ✅ Automatically hidden  
**Security:** ✅ Multiple protection layers
