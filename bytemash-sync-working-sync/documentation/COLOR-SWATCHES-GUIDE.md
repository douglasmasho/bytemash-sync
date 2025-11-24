# Visual Color Swatches Guide 🎨

## Overview

The plugin now displays **beautiful visual color swatches** instead of boring text dropdowns on product pages! Customers can see and click on actual colors when selecting product variations.

---

## Before vs. After

### ❌ Before (Text Dropdown):
```
Color: [Navy ▼]
       - Navy
       - Red
       - Blue
       - White
```

### ✅ After (Visual Swatches):
```
Color: 🔵 🔴 🟦 ⚪  ← Clickable colored circles with actual colors!
       
Selected: Navy
```

---

## How It Works

### 1. **Sync Color Swatches**
   - Go to: **WordPress Admin → Amrod Sync → Dashboard**
   - Under "Color Swatches" section, click **"Sync Color Swatches"**
   - Wait for sync to complete (94 swatches)
   - This only needs to be done **once** (or when Amrod adds new colors)

### 2. **Sync Products**
   - Click **"Sync All Products"** (or "Sync Updated Products")
   - The plugin automatically:
     - Creates variable products with color variations
     - Maps each color name (e.g., "Navy") to its color code (e.g., "N")
     - Links color codes to swatch hex values (e.g., "#000080")
     - Stores mapping in product meta: `_amrod_color_mapping`

### 3. **View on Frontend**
   - Visit any variable product page on your store
   - If the product has a "Color" attribute, you'll see:
     - **Colored circles** instead of dropdown
     - **Hover tooltips** showing color names
     - **Selected state** with checkmark and border
     - **Disabled state** for unavailable combinations (grayed out)

---

## Features

### Visual Design
- **40px circular swatches** (35px on mobile)
- **Hover effect:** Scales up slightly, shows tooltip
- **Selected:** Bold border, checkmark (✓) in center
- **Disabled:** Grayed out, cursor: not-allowed
- **Responsive:** Wraps nicely on mobile/tablet

### Smart Behavior
- **Only shows available colors** for current product
- **Updates on variation change** (e.g., after selecting size)
- **Integrates with WooCommerce** variation system
- **Works with "Reset" button** to clear selections

### Color Accuracy
- Uses **official Amrod hex values** from API
- Stores tick color for checkmark visibility (white/black)
- Falls back to gray (#cccccc) if swatch data missing

---

## Technical Details

### Files Created
1. **`assets/css/color-swatches.css`** - Swatch styling
   - Circle shapes, hover effects, selected states
   - Tooltips, mobile responsive
   
2. **`assets/js/color-swatches.js`** - Swatch functionality
   - Replaces `<select>` with swatches
   - Handles clicks, updates variation
   - Manages availability based on stock

### Data Storage

#### 1. Color Swatches (Global)
```php
// Stored as WordPress options (one per color)
get_option("amrod_color_swatch_N");  // Navy
get_option("amrod_color_swatch_R");  // Red
get_option("amrod_color_swatch_W");  // White

// Structure:
array(
    'code' => 'N',
    'name' => 'Navy',
    'hexValue' => '#000080',
    'textColour' => '#ffffff',
    'tickColour' => '#ffffff',
    'isDeleted' => false
)
```

#### 2. Product Color Mapping (Per Product)
```php
// Stored in product post meta
get_post_meta($product_id, '_amrod_color_mapping', true);

// Structure (lowercase keys for matching):
array(
    'navy' => 'N',     // Color name (lowercase) => Color code
    'red' => 'R',
    'white' => 'W',
    'blue' => 'BU'
)
```

#### 3. Frontend JavaScript
```javascript
// Global variable injected by PHP
bytemashColorSwatches = {
    'navy': {
        code: 'N',
        name: 'Navy',
        hexValue: '#000080',
        textColour: '#ffffff',
        tickColour: '#ffffff'
    },
    'red': { ... },
    // ...
};
```

---

## Customization

### Change Swatch Size
Edit `assets/css/color-swatches.css`:
```css
.bytemash-color-swatch {
    width: 50px;   /* Change from 40px */
    height: 50px;  /* Change from 40px */
}
```

### Change Shape (Circle → Square)
Edit `assets/css/color-swatches.css`:
```css
.bytemash-color-swatch {
    border-radius: 0;   /* Change from 50% (circle) to 0 (square) */
}
```

### Disable Tooltips
Edit `assets/css/color-swatches.css`:
```css
.bytemash-color-swatch::before,
.bytemash-color-swatch::after {
    display: none !important;
}
```

---

## Troubleshooting

### Swatches Not Showing?

**1. Check if color swatches are synced:**
```php
// Run this in WordPress admin or a test script
$swatch = get_option("amrod_color_swatch_N");
var_dump($swatch);  // Should show array with hexValue, name, etc.
```

**2. Check if product has color mapping:**
```php
$product_id = 123;  // Your product ID
$mapping = get_post_meta($product_id, '_amrod_color_mapping', true);
var_dump($mapping);  // Should show array like ['navy' => 'N', ...]
```

**3. Check browser console:**
- Open product page
- Press F12 → Console tab
- Look for: `bytemashColorSwatches` variable
- Should contain color data

**4. Re-sync the product:**
- If mapping is missing, re-sync the product
- Go to Admin → Amrod Sync → "Sync All Products"
- Or just sync that specific product

### Swatches Show Wrong Colors?

**Re-sync color swatches:**
- Admin → Amrod Sync → "Sync Color Swatches"
- This fetches latest hex values from Amrod

### Only Dropdown Showing (No Swatches)?

**Check JavaScript loading:**
```javascript
// In browser console on product page:
console.log(typeof jQuery);  // Should be "function"
console.log(typeof bytemashColorSwatches);  // Should be "object"
```

**Verify files exist:**
- `wp-content/plugins/bytemash-woo-sync/assets/css/color-swatches.css`
- `wp-content/plugins/bytemash-woo-sync/assets/js/color-swatches.js`

**Clear cache:**
- Clear WordPress cache (if using cache plugin)
- Clear browser cache (Ctrl+Shift+R)

---

## Benefits

### For Customers 👥
- ✅ **See actual colors** - No guessing what "Charcoal" looks like
- ✅ **Faster selection** - Visual recognition beats reading
- ✅ **Better mobile UX** - Touch-friendly circles
- ✅ **Professional look** - Modern e-commerce standard

### For Store Owners 🏪
- ✅ **Reduced returns** - Customers know what they're getting
- ✅ **Higher conversions** - Better UX = more sales
- ✅ **Automatic updates** - Syncs from Amrod, no manual work
- ✅ **Accurate colors** - Uses official Amrod hex values

---

## API Reference

### PHP Functions

#### Get Color Swatch Data
```php
$swatch = get_option("amrod_color_swatch_{$color_code}");
// Returns: array with hexValue, name, textColour, tickColour
```

#### Get Product Color Mapping
```php
$mapping = get_post_meta($product_id, '_amrod_color_mapping', true);
// Returns: array mapping color names to codes
```

### JavaScript Events

#### After Swatches Initialized
```javascript
jQuery(document).on('bytemash_swatches_initialized', function() {
    console.log('Color swatches ready!');
});
```

#### After Swatch Clicked
```javascript
jQuery('.bytemash-color-swatch').on('click', function() {
    var colorName = jQuery(this).attr('data-color-name');
    console.log('Selected color:', colorName);
});
```

---

## Compatibility

### WordPress
- Requires: WordPress 5.8+
- Tested up to: WordPress 6.4

### WooCommerce
- Requires: WooCommerce 6.0+
- Tested up to: WooCommerce 8.5
- Compatible with variable products only

### Themes
- Works with **any WooCommerce-compatible theme**
- Uses standard WooCommerce hooks
- CSS is scoped to avoid conflicts

### Browsers
- Chrome ✅
- Firefox ✅
- Safari ✅
- Edge ✅
- Mobile browsers ✅

---

## Future Enhancements

Possible future additions:
- **Image swatches** (if Amrod adds color images to API)
- **Swatch labels** (toggle on/off)
- **Layout options** (grid, list, inline)
- **Size swatches** (visual size selector)
- **Admin settings page** (customize appearance)

---

## Support

For issues or questions:
- Check logs: Admin → Amrod Sync → View Logs
- Review this guide
- Check CHANGELOG.md for recent changes
- Contact ByteMash support

---

**Version:** 2.2.0  
**Last Updated:** October 12, 2025



