# Solution Guide: Variable Products & Stock Modal Issues 🔧

## Problem 1: Simple Products Being Set as Variable

### Root Cause
Some products are incorrectly created as variable products when they should be simple products, causing the "Please select some product options" message.

### Solution

#### Step 1: Run the Cleanup Script
```bash
# Navigate to your plugin directory
cd wp-content/plugins/bytemash-woo-sync

# Run the cleanup script
php run-cleanup.php
```

#### Step 2: Manual Cleanup (if needed)
If the script doesn't work, you can manually clean up products:

1. **Go to WooCommerce → Products**
2. **Find products showing "Please select options"**
3. **Edit the product**
4. **Change Product Data from "Variable product" to "Simple product"**
5. **Save the product**

#### Step 3: Prevent Future Issues
The plugin now includes automatic cleanup:
- ✅ Converts variable products to simple when appropriate
- ✅ Removes variable product attributes from simple products
- ✅ Ensures product type is correctly set

---

## Problem 2: Check Stock Button Not Opening Modal

### Root Cause
The modal template might not be rendered or JavaScript might not be loading correctly.

### Solution

#### Step 1: Test the Modal
```bash
# Run the test script
php test-stock-modal.php
```

This will show you:
- ✅ If stock data is being generated
- ✅ If the modal HTML is being rendered
- ✅ If JavaScript is loading correctly

#### Step 2: Check Browser Console
1. **Open a product page**
2. **Press F12 to open Developer Tools**
3. **Go to Console tab**
4. **Click the "Check Stock" button**
5. **Look for these messages:**
   - ✅ "Check Stock button clicked"
   - ✅ Stock data object
   - ❌ Any JavaScript errors

#### Step 3: Verify Files Are Loading
Check if these files are being loaded:
- ✅ `assets/css/stock-display.css`
- ✅ `assets/js/stock-modal.js`

#### Step 4: Manual Testing
1. **Visit a product page with stock**
2. **Look for the blue "Check Stock" button**
3. **Click the button**
4. **Modal should open with stock table**

---

## Debugging Steps

### 1. Check if Stock Display is Enabled
```php
// In WordPress admin or via code
$enabled = get_option('bytemash_show_stock_display', '1');
echo "Stock Display Enabled: " . ($enabled ? 'Yes' : 'No');
```

### 2. Check if Product Has Stock Data
```php
$product_id = 123; // Replace with actual product ID
$reserved = get_post_meta($product_id, '_amrod_reserved_stock', true);
$incoming = get_post_meta($product_id, '_amrod_incoming_stock', true);
echo "Reserved: " . $reserved . "\n";
echo "Incoming: " . $incoming . "\n";
```

### 3. Check if Modal HTML is Present
1. **Right-click on product page**
2. **Select "View Page Source"**
3. **Search for "bytemash-stock-modal"**
4. **Should find the modal HTML**

### 4. Check JavaScript Console
1. **Open Developer Tools (F12)**
2. **Go to Console tab**
3. **Look for errors like:**
   - ❌ "jQuery is not defined"
   - ❌ "Cannot read property of undefined"
   - ❌ "Modal not found"

---

## Quick Fixes

### Fix 1: Force Modal to Show
Add this to your theme's `functions.php`:
```php
// Force show stock modal for testing
add_action('wp_footer', function() {
    if (is_product()) {
        echo '<script>
        jQuery(document).ready(function($) {
            $(".bytemash-check-stock-btn").on("click", function() {
                $("#bytemash-stock-modal").show();
            });
        });
        </script>';
    }
});
```

### Fix 2: Check if jQuery is Loading
Add this to your theme's `functions.php`:
```php
// Ensure jQuery is loaded
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_script('jquery');
});
```

### Fix 3: Manual Modal Trigger
Add this button to test the modal:
```html
<button onclick="document.getElementById('bytemash-stock-modal').style.display='block'">
    Test Modal
</button>
```

---

## File Checklist

### Required Files (Should Exist):
- ✅ `assets/css/stock-display.css`
- ✅ `assets/js/stock-modal.js`
- ✅ `bytemash-woo-sync.php` (main plugin file)
- ✅ `includes/class-product-sync.php`

### Test Files (Created for Debugging):
- ✅ `cleanup-variable-products.php`
- ✅ `run-cleanup.php`
- ✅ `test-stock-modal.php`

---

## Common Issues & Solutions

### Issue 1: "Check Stock button clicked" but no modal
**Solution:** Modal HTML not rendered
- Check if `render_stock_modal_template()` is being called
- Verify modal HTML is in page source

### Issue 2: Modal opens but shows no data
**Solution:** Stock data not being passed
- Check if `data-stock-details` attribute has data
- Verify stock sync has run and stored data

### Issue 3: JavaScript errors in console
**Solution:** jQuery or other dependencies missing
- Ensure jQuery is loaded
- Check for JavaScript syntax errors

### Issue 4: Products still showing "Please select options"
**Solution:** Cleanup not complete
- Run the cleanup script again
- Manually convert products in WooCommerce admin

---

## Testing Checklist

### ✅ Variable Product Cleanup
- [ ] Run cleanup script
- [ ] Check products no longer show "Please select options"
- [ ] Verify products can be added to cart directly

### ✅ Stock Modal
- [ ] Check Stock button appears on product pages
- [ ] Button click opens modal
- [ ] Modal shows stock table with data
- [ ] Modal can be closed with X or ESC key

### ✅ Stock Data
- [ ] Stock sync has run successfully
- [ ] Products have stock quantities
- [ ] Reserved and incoming stock data is stored

---

## Next Steps

1. **Run the cleanup script** to fix variable products
2. **Test the stock modal** on a product page
3. **Check browser console** for any errors
4. **Verify stock data** is being synced correctly

If issues persist, check the debug logs and console output for specific error messages.

---

**Status:** ✅ Ready to test  
**Files Created:** 3 test/debug scripts  
**Issues Addressed:** Variable products + Stock modal  
**Next Action:** Run cleanup and test modal functionality
