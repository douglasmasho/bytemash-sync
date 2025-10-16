# Enhanced Stock Display Feature 📊

## Overview

The Enhanced Stock Display feature shows beautiful, color-coded stock availability badges on your WooCommerce product pages.

---

## Features

### Visual Stock Indicators

**✅ In Stock (Green Badge)**
```
✅ In Stock: 150 units available
```
- Shown when stock is ABOVE the low stock threshold
- Green background with green border
- Reassures customers product is readily available

**⚠️ Low Stock (Orange Badge)**
```
⚠️ Low Stock: Only 5 left!
```
- Shown when stock is between 1 and the threshold
- Orange background with orange border
- Creates urgency to encourage purchase

**❌ Out of Stock (Red Badge)**
```
❌ Out of Stock
```
- Shown when stock is 0
- Red background with red border
- Clear indication product is unavailable

---

## How to Enable/Disable

### Step 1: Go to Settings
1. Navigate to **WordPress Admin → Amrod Sync → Settings**
2. Scroll to **"Advanced Settings"** section

### Step 2: Toggle the Feature
- **Checkbox:** "Show enhanced stock information on product pages"
  - ✅ **Checked** = Stock badges are displayed
  - ⬜ **Unchecked** = Stock badges are hidden

### Step 3: Configure Low Stock Threshold
- **Field:** "Low Stock Threshold"
- **Default:** 10 units
- **Range:** 1-100 units
- **Example:** If set to 10, products with 10 or fewer units show "Low Stock" warning

### Step 4: Save Settings
Click **"Save Settings"** button at the bottom

---

## Settings Details

### Enhanced Stock Display
- **Type:** Checkbox (on/off toggle)
- **Default:** Enabled (checked)
- **Location:** Advanced Settings section
- **Description:** Display stock quantities with color-coded badges

### Low Stock Threshold
- **Type:** Number input
- **Default:** 10 units
- **Minimum:** 1 unit
- **Maximum:** 100 units
- **Description:** Stock quantity below which products show "Low Stock" warning

---

## Display Location

The stock badge appears on **single product pages** in the product summary area:

```
Product Name
-----------
Price: $99.99
✅ In Stock: 50 units available  ← Stock Badge Here
[Add to Cart]
```

**Position:** After the price, before the "Add to Cart" button (priority 15 in WooCommerce hooks)

---

## Technical Details

### Requirements
- Product must have "Manage stock" enabled in WooCommerce
- Stock quantity must be tracked
- Works with both simple and variable products

### Styling
- **CSS File:** `assets/css/stock-display.css`
- **Mobile Responsive:** Adapts to small screens
- **Customizable:** You can override styles in your theme

### Performance
- **Lightweight:** Only loads CSS on single product pages
- **No JavaScript:** Pure CSS and PHP
- **Cacheable:** Works with all caching plugins

---

## Customization

### Override Styles

Add to your theme's `style.css` or custom CSS:

```css
/* Make badges larger */
.bytemash-stock-display {
    padding: 12px 20px;
    font-size: 16px;
}

/* Change colors */
.bytemash-stock-display.in-stock {
    background: #d4edda;
    color: #155724;
    border-color: #28a745;
}

/* Hide icons */
.bytemash-stock-display .stock-icon {
    display: none;
}
```

### Change Position

Use WordPress filter to change where badge appears:

```php
// Remove from default location
remove_action('woocommerce_single_product_summary', array($plugin_instance, 'display_enhanced_stock'), 15);

// Add to new location (priority 25 = after Add to Cart)
add_action('woocommerce_single_product_summary', array($plugin_instance, 'display_enhanced_stock'), 25);
```

---

## Examples

### Example 1: High Stock Product
**Stock Quantity:** 250 units  
**Low Threshold:** 10 units  
**Display:**
```
✅ In Stock: 250 units available
```

### Example 2: Low Stock Product
**Stock Quantity:** 7 units  
**Low Threshold:** 10 units  
**Display:**
```
⚠️ Low Stock: Only 7 left!
```

### Example 3: Out of Stock Product
**Stock Quantity:** 0 units  
**Display:**
```
❌ Out of Stock
```

---

## Troubleshooting

### Badge Not Showing?

**Check these:**
1. ✅ Feature is enabled in Settings
2. ✅ Viewing a single product page (not shop/category)
3. ✅ Product has "Manage stock" enabled
4. ✅ Stock quantity is set
5. ✅ Clear cache (if using caching plugin)

### Wrong Stock Quantity?

**Solution:**
1. Go to Amrod Sync → Dashboard
2. Click "Sync Stock" button
3. Wait for sync to complete
4. Check product page again

### Styling Issues?

**Common fixes:**
- Clear browser cache (Ctrl+F5)
- Check for theme CSS conflicts
- Inspect element with browser dev tools
- Add `!important` to custom CSS if needed

---

## FAQ

**Q: Can I change the threshold for individual products?**  
A: Currently, the threshold is global for all products. This keeps things simple and consistent.

**Q: Does this work with variable products?**  
A: Yes! It shows stock for the parent product. Individual variation stock is managed by WooCommerce.

**Q: Can I translate the text?**  
A: Yes, all text uses WordPress translation functions. Use a plugin like Loco Translate.

**Q: Will this slow down my site?**  
A: No. The CSS only loads on product pages, and there's no JavaScript. It's very lightweight.

**Q: Can I remove the emoji icons?**  
A: Yes, add this CSS:
```css
.bytemash-stock-display .stock-icon {
    display: none;
}
```

**Q: What if I disable the feature?**  
A: The badges will immediately stop displaying. Your stock data remains unchanged.

---

## Benefits

### For Store Owners
- ✅ **Increase conversions** with "Low Stock" urgency
- ✅ **Reduce support tickets** about availability
- ✅ **Professional appearance** with color-coded badges
- ✅ **Easy to manage** with simple on/off toggle

### For Customers
- ✅ **Clear visibility** of product availability
- ✅ **Better buying decisions** with stock info
- ✅ **Visual clarity** with color-coded badges
- ✅ **Mobile-friendly** design

---

## Version

**Feature Added:** Version 2.5.0  
**Date:** October 15, 2025  
**Status:** ✅ Active

---

## Support

If you need help with the stock display feature:

1. Check this guide
2. Review the Troubleshooting section
3. Check your Settings (Amrod Sync → Settings → Advanced)
4. Sync stock data (Dashboard → Sync Stock)

**Remember:** You can toggle this feature on/off anytime in Settings!

