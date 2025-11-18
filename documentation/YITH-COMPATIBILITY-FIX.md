# YITH Request a Quote Compatibility Fix

## Problem

The ByteMash Sync plugin was interfering with YITH Request a Quote (and other quote plugins) by setting fake prices of `'0'` on products without prices. This caused YITH to think products had prices and prevented the quote button from showing.

## Root Causes

### 1. `set_default_price_for_amrod_products()` Filter
**Location**: Line 224 (now commented out)

This filter intercepted `woocommerce_product_get_price` and returned `'0'` for products without prices:
```php
if (empty($price) || $price === null) {
    return '0';  // Made YITH think product HAS a price
}
```

### 2. `maybe_set_product_price()` Action
**Location**: Line 225 (now commented out)

This action ran on `woocommerce_before_add_to_cart_button` and **permanently saved** `'0'` prices to the database:
```php
if (empty($product->get_price()) || $product->get_price() === null) {
    $product->set_price('0');
    $product->set_regular_price('0');
    $product->save();  // Wrote '0' to database!
}
```

## The Fix

### Code Changes Made

**File**: `bytemash-woo-sync.php` (Lines 224-227)

**Before:**
```php
add_filter('woocommerce_product_get_price', array($this, 'set_default_price_for_amrod_products'), 10, 2);
add_action('woocommerce_before_add_to_cart_button', array($this, 'maybe_set_product_price'));
```

**After:**
```php
// DISABLED: These interfere with YITH Request a Quote and other quote plugins
// They set fake '0' prices which breaks quote button detection
// add_filter('woocommerce_product_get_price', array($this, 'set_default_price_for_amrod_products'), 10, 2);
// add_action('woocommerce_before_add_to_cart_button', array($this, 'maybe_set_product_price'));
```

## Database Cleanup Required

Since `maybe_set_product_price()` already saved `'0'` prices to the database, you need to clean them up.

### Option 1: Use Settings Page Button (Recommended)

1. Go to: **WordPress Admin → Amrod Sync → Settings**
2. Scroll to **"YITH Compatibility"** section
3. Click **"Remove Fake Zero Prices"** button
4. Confirm the action
5. The cleanup will run automatically and show results

### Option 2: Manual SQL Query

Run this in your database (phpMyAdmin, WP-CLI, etc.):

```sql
-- Remove fake '0' prices
DELETE FROM wp_postmeta
WHERE meta_key IN ('_price', '_regular_price')
AND meta_value = '0';
```

### Option 3: WP-CLI (Advanced)

If you have WP-CLI installed:
```bash
wp db query "DELETE FROM wp_postmeta WHERE meta_key IN ('_price', '_regular_price') AND meta_value = '0';"
```

**Note**: The Settings Page button (Option 1) is recommended as it also clears WooCommerce caches automatically.

## Testing YITH After Fix

1. ✅ **Clear all caches** (WordPress, browser, CDN)
2. ✅ **Visit a product page** without prices
3. ✅ **YITH quote button should appear**

### If YITH Still Doesn't Show

Check YITH's own settings:
- **WooCommerce → YITH Request a Quote → Settings**
- Ensure "Hide Add to Cart" is enabled for products without prices
- Check product exclusions/inclusions
- Verify YITH is active and configured

## Why These Functions Existed

These functions were originally added to:
- Allow the plugin's own Quote Mode to work
- Make products orderable even without prices
- Provide a default price for WooCommerce's add-to-cart functionality

However, they interfered with YITH and other third-party quote plugins.

## Current Behavior

With these functions disabled:
- ✅ YITH and other quote plugins work correctly
- ✅ Products without prices won't be purchasable (standard WooCommerce)
- ✅ YITH will show quote buttons for products without prices
- ✅ No interference with third-party plugins

## Plugin's Quote Mode Still Works

The plugin's **built-in Quote Mode** still works when enabled:
- It has its own quote button rendering
- It doesn't rely on these disabled functions
- Enable via: **Amrod Sync → Settings → Enable Quote Mode**

However, you should **NOT use both** the plugin's Quote Mode AND YITH simultaneously - choose one.

## Future-Proofing

These functions will remain commented out to ensure compatibility with:
- YITH Request a Quote
- WooCommerce Catalog Mode plugins
- Other quote/inquiry plugins
- Standard WooCommerce behavior

If you need products without prices to be purchasable, use one of:
1. **YITH Request a Quote** (for quote functionality)
2. **Plugin's Quote Mode** (built-in, but conflicts with YITH)
3. **Set "Allow Orders Without Price"** to "Force with stock" (makes products purchasable)

---

## Summary

**Fixed**: Commented out two functions that set fake `'0'` prices  
**Cleanup**: Use provided cleanup tool or SQL to remove existing `'0'` prices  
**Result**: YITH Request a Quote now works correctly  
**Trade-off**: Products without prices won't be directly purchasable (which is correct behavior)

