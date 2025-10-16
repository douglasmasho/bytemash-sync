# Stock Display Fix - Frontend Shows "Out of Stock" 🔧

## Problem

**Symptoms:**
- ✅ Stock sync completes successfully
- ✅ WooCommerce dashboard shows products are "In Stock"
- ❌ **Frontend product pages show "Out of Stock"**

**Root Cause:**
WooCommerce wasn't correctly reading the stock status on the frontend due to:
1. Cached stock status from before the sync
2. WooCommerce lookup tables not being updated
3. Stock availability filters returning stale data

---

## Solutions Implemented

### 1. **Fixed Stock Availability Filter**

Added filter to force WooCommerce to correctly read stock from database:

```php
add_filter('woocommerce_product_is_in_stock', 'fix_stock_availability');
add_filter('woocommerce_variation_is_in_stock', 'fix_stock_availability');
```

**Logic:**
- If `stock_quantity > 0` → Product is in stock
- If `stock_status === 'instock'` → Product is in stock
- If `stock_quantity <= 0` → Product is out of stock

This ensures frontend always reads fresh stock data from database.

---

### 2. **Aggressive Cache Clearing**

Enhanced cache clearing after stock sync:

**WordPress Cache:**
```php
wp_cache_delete($pid, 'posts');
wp_cache_delete($pid, 'post_meta');
clean_post_cache($pid);
```

**WooCommerce Cache:**
```php
wp_cache_delete('product-' . $pid, 'products');
delete_transient('wc_product_' . $pid);
delete_transient('wc_var_prices_' . $pid);
```

**Global Cache:**
```php
wp_cache_flush(); // Clear all caches
```

---

### 3. **Update WooCommerce Lookup Tables**

WooCommerce 3.6+ uses lookup tables for performance. We now update them:

```php
$data_store = WC_Data_Store::load('product');
$data_store->update_lookup_table($product_id, 'wc_product_meta_lookup');
```

This ensures WooCommerce's internal tables match the updated stock.

---

## How to Fix Existing Products

### Option 1: Re-sync Stock (Recommended)

1. Go to **Amrod Sync → Dashboard**
2. Click **"Sync Stock"**
3. Wait for completion
4. **Clear your browser cache** (Ctrl+F5)
5. **Visit product page** - should now show "In Stock"

### Option 2: Clear WordPress Cache

If using a caching plugin:
1. **WP Super Cache:** Go to Settings → WP Super Cache → Delete Cache
2. **W3 Total Cache:** Go to Performance → Dashboard → Empty All Caches
3. **WP Rocket:** Go to Settings → WP Rocket → Clear Cache
4. **LiteSpeed Cache:** Go to LiteSpeed Cache → Toolbox → Purge All

### Option 3: Use Diagnostic Script

1. Go to: `your-site.com/wp-content/plugins/bytemash-woo-sync/check-product-stock.php`
2. Enter a product SKU
3. View actual database values vs WooCommerce object values
4. Identify discrepancies

---

## Files Modified

### `bytemash-woo-sync.php`

**Added Filters:**
```php
add_filter('woocommerce_product_is_in_stock', array($this, 'fix_stock_availability'), 10, 2);
add_filter('woocommerce_variation_is_in_stock', array($this, 'fix_stock_availability'), 10, 2);
```

**New Method:**
```php
public function fix_stock_availability($is_in_stock, $product) {
    // Force correct stock status reading
}
```

### `includes/class-product-sync.php`

**Enhanced Cache Clearing:**
- Added WooCommerce-specific cache deletion
- Added transient deletion
- Added lookup table updates
- Added global cache flush

---

## Testing

### Test 1: Check Product Stock (Diagnostic)

Visit: `/wp-content/plugins/bytemash-woo-sync/check-product-stock.php`

**Enter SKU** → See:
- ✅ **Stock Quantity:** Should show correct number
- ✅ **Stock Status:** Should show "instock"
- ✅ **Is In Stock?:** Should show "Yes"

If all green checkmarks → Stock is correctly saved!

### Test 2: Frontend Product Page

1. **Visit product page**
2. **Look for:** "Add to Cart" button (not "Out of Stock" message)
3. **Check stock display:** Should show "In Stock: X units available"

### Test 3: WooCommerce Dashboard

1. **Products → All Products**
2. **Check "Stock" column:** Should show quantities
3. **Edit a product:** Should show stock quantity in "Inventory" tab

---

## Troubleshooting

### Still Showing "Out of Stock"?

**Step 1: Clear ALL caches**
```bash
# WordPress object cache
wp cache flush

# Clear transients
wp transient delete --all

# Opcache (if enabled)
wp cli cache clear
```

**Step 2: Check theme/plugin conflicts**
- Temporarily switch to a default theme (Storefront/Twenty Twenty-Four)
- Deactivate other plugins temporarily
- Check if stock displays correctly

**Step 3: Check WooCommerce settings**
- Go to **WooCommerce → Settings → Products → Inventory**
- Ensure **"Stock management"** is enabled
- Check **"Out of stock threshold"** (should be 0)

**Step 4: Manually update one product**
- Edit a product in WooCommerce
- Set stock quantity manually
- Save
- Check if it displays correctly
- If YES → Problem is with our sync (unlikely)
- If NO → Problem is with theme/caching

### Different Message on Variable Products?

For variable products, check individual variations:
- Edit product → Variations tab
- Expand each variation
- Check "Stock quantity" field
- Ensure "Stock status" is set correctly

---

## Why This Happens

### WooCommerce Stock Flow:

```
1. Stock saved to database (_stock meta)
2. WooCommerce reads from database
3. WooCommerce caches the value
4. Frontend uses cached value
5. Theme displays cached value
```

**Problem occurs when:**
- Database updated (by our sync) ✅
- Cache not cleared ❌
- Frontend shows old cached value ❌

**Our fix ensures:**
- Database updated ✅
- All caches cleared ✅
- Filters force fresh read ✅
- Frontend shows correct value ✅

---

## Prevention

To prevent this issue in the future:

**1. Regular cache clearing after syncs** (now automatic)

**2. Use our enhanced stock display** (shows real-time database values)
- Enable in Settings → Advanced Settings
- Shows stock with visual indicators

**3. Monitor with diagnostic script**
- Bookmark the check-product-stock.php page
- Quick way to verify stock after syncs

---

## Summary

**Problem:** Frontend showed "Out of Stock" despite correct database values  

**Solutions Applied:**
1. ✅ Added stock availability filters
2. ✅ Enhanced cache clearing (WordPress + WooCommerce)
3. ✅ Update WooCommerce lookup tables
4. ✅ Created diagnostic tool

**Result:** Frontend now correctly displays stock status! 🎉

**Next Steps:**
1. Re-sync stock (Dashboard → Sync Stock)
2. Clear browser cache (Ctrl+F5)
3. Visit product page → Should work!

---

**Version:** 2.5.0  
**Date:** October 16, 2025  
**Status:** ✅ Fixed

