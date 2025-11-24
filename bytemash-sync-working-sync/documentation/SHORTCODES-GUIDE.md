# Shortcodes Guide

This guide explains all available shortcodes in the ByteMash WooCommerce Amrod Sync plugin.

## Overview

The plugin provides several shortcodes that allow you to display Amrod product information anywhere in your WordPress site. Most shortcodes are **automatically displayed on product pages by default**, but you can also use them in:

- Page builders (Elementor, Gutenberg, Bricks, etc.)
- Widgets
- Custom templates
- Product descriptions
- Any content area

---

## Available Shortcodes

### 1. `[amrod_brand_logo]`

**Description:** Displays the brand logo for the current product.

**Usage:**
```
[amrod_brand_logo]
```

**How it works:**
- Retrieves the brand code from the product's `_amrod_brand_code` meta field
- Fetches the brand logo URL from the brand sync data (`amrod_brand_{code}` option)
- Displays the logo image with appropriate sizing

**Display:**
- Automatically displayed on product pages (priority 16)
- Shows nothing if product has no brand or logo is not available

**Output:**
- HTML `<img>` tag with the brand logo
- Max height: 60px, Max width: 200px
- Responsive sizing

---

### 2. `[amrod_color_swatches]`

**Description:** Displays a row of color swatch circles showing all available colors for the product.

**Usage:**
```
[amrod_color_swatches]
```

**How it works:**
- Gets the color mapping from product's `_amrod_color_mapping` meta field
- Looks up each color code in the global color swatches (`amrod_color_swatch_{code}`)
- Retrieves the hex color value from the swatch data
- Displays colored circles for each available color

**Display:**
- Automatically displayed on product pages (priority 17)
- Only shows for products with color variations
- Shows nothing if no color swatches are available

**Output:**
- Row of circular color swatches (30px diameter)
- Each circle shows the actual color using hex values
- Hover tooltip shows color name
- Responsive layout

**Example:**
```
Available Colors: [●] [●] [●] [●]
```

---

### 3. `[amrod_gender]`

**Description:** Displays the product gender (e.g., Men, Women, Unisex).

**Usage:**
```
[amrod_gender]
```

**How it works:**
- Retrieves the gender from product's `_amrod_gender` meta field
- Capitalizes the first letter for display
- Shows nothing if gender is not set

**Display:**
- Automatically displayed on product pages (priority 18)
- Only shows if product has gender information

**Output:**
- Styled box with "Gender:" label
- Displays the gender value (e.g., "Men", "Women", "Unisex")

**Example:**
```
Gender: Men
```

---

### 4. `[amrod_total_stock]`

**Description:** Displays the total stock quantity (sum of all variations) and total incoming stock for the product.

**Usage:**
```
[amrod_total_stock]
```

**How it works:**

**For Variable Products:**
- Loops through all product variations
- Sums up stock quantities from each variation
- Sums up incoming stock from each variation's `_amrod_stock_detail` meta

**For Simple Products:**
- Gets stock quantity directly from the product
- Gets incoming stock from product's `_amrod_stock_detail` meta

**Display:**
- Automatically displayed on product pages (priority 19)
- Only shows if stock data is available (total stock > 0 or incoming stock > 0)
- Shows nothing if product has no stock data

**Output:**
- Styled box showing:
  - **Total Stock:** (in green) - Sum of all variations' stock
  - **Incoming Stock:** (in blue) - Sum of all incoming stock dates

**Example:**
```
Total Stock: 1,250
Incoming Stock: 500
```

**Note:** For variable products, this calculates the sum across all variations, giving you a complete picture of total inventory.

---

### 5. `[amrod_before_title]`

**Description:** Outputs WooCommerce hook content for use in page builders like Bricks.

**Usage:**
```
[amrod_before_title]
```

**How it works:**
- Captures output from `woocommerce_before_shop_loop_item_title` action hook
- Useful for page builders that don't support WordPress hooks directly

**Display:**
- NOT automatically displayed (requires manual placement)
- Intended for custom layouts in page builders

**Use Case:**
- When using page builders (Bricks, Elementor, etc.)
- When you need to place product content in custom locations
- When hooks aren't available in your template

---

## Auto-Display on Product Pages

The following shortcodes are **automatically displayed** on single product pages by default:

1. `[amrod_brand_logo]` - After product title (priority 16)
2. `[amrod_color_swatches]` - After brand logo (priority 17)
3. `[amrod_gender]` - After color swatches (priority 18)
4. `[amrod_total_stock]` - After gender (priority 19)

**Note:** These appear in the `woocommerce_single_product_summary` hook, which is typically where product information is displayed.

**To disable auto-display:**
You can remove the hooks in your theme's `functions.php`:

```php
remove_action('woocommerce_single_product_summary', array($GLOBALS['bytemash_woo_sync'], 'display_brand_logo'), 16);
remove_action('woocommerce_single_product_summary', array($GLOBALS['bytemash_woo_sync'], 'display_color_swatches_row'), 17);
remove_action('woocommerce_single_product_summary', array($GLOBALS['bytemash_woo-sync'], 'display_product_gender'), 18);
remove_action('woocommerce_single_product_summary', array($GLOBALS['bytemash_woo_sync'], 'display_total_stock_info'), 19);
```

---

## Usage Examples

### In Product Description

Add shortcodes directly in the product description editor:

```
[amrod_brand_logo]
[amrod_color_swatches]
[amrod_gender]
[amrod_total_stock]
```

### In Page Builder (Elementor/Bricks)

Use shortcode widgets/elements:

1. Add a "Shortcode" widget
2. Enter the shortcode: `[amrod_brand_logo]`
3. Position it where you want the logo to appear

### In Custom Templates

Use in your theme's template files:

```php
<?php
global $product;
if ($product) {
    echo do_shortcode('[amrod_brand_logo]');
    echo do_shortcode('[amrod_color_swatches]');
    echo do_shortcode('[amrod_gender]');
    echo do_shortcode('[amrod_total_stock]');
}
?>
```

### In Widgets

Add shortcodes to text widgets:

1. Go to **Appearance > Widgets**
2. Add a "Text" widget
3. Enter the shortcode: `[amrod_brand_logo]`
4. Save and position widget

---

## Requirements

For shortcodes to work properly:

1. **Brand Sync:** Brand logo requires brands to be synced via the Brands sync
2. **Color Swatches Sync:** Color swatches require color swatches to be synced
3. **Product Meta:** Products must have the relevant meta fields:
   - `_amrod_brand_code` - For brand logo
   - `_amrod_color_mapping` - For color swatches
   - `_amrod_gender` - For gender
   - `_amrod_stock_detail` - For stock information

**Sync Order:**
1. Run **Products** sync first
2. Run **Brands** sync for brand logos
3. Run **Color Swatches** sync for color display
4. Run **Stock** sync for stock information

---

## Styling

All shortcodes output HTML with inline styles for immediate display. To customize the appearance:

### CSS Override

Add custom CSS to your theme:

```css
/* Brand Logo */
.amrod-brand-logo img {
    max-height: 80px !important;
    max-width: 300px !important;
}

/* Color Swatches */
.amrod-color-swatches-row {
    margin: 20px 0 !important;
}

.amrod-color-swatch-circle {
    width: 40px !important;
    height: 40px !important;
    border: 3px solid #333 !important;
}

/* Gender Display */
.amrod-product-gender {
    background: #f0f0f0 !important;
    border-left-color: #0073aa !important;
}

/* Stock Info */
.amrod-total-stock-info {
    background: #e8f5e9 !important;
    border-color: #4caf50 !important;
}
```

---

## Troubleshooting

### Shortcode Not Displaying

1. **Check if data exists:**
   - Verify product has the required meta fields
   - Check if syncs have run successfully

2. **Check sync status:**
   - Go to **Amrod Sync > Dashboard**
   - Verify Brands, Color Swatches, and Stock syncs have completed

3. **Check product type:**
   - Some shortcodes only work for variable products (color swatches)
   - Simple products may not have all data

### Brand Logo Not Showing

- Verify brand sync has run
- Check product has `_amrod_brand_code` meta
- Verify brand logo exists in `amrod_brand_{code}` option

### Color Swatches Not Showing

- Verify color swatches sync has run
- Check product has `_amrod_color_mapping` meta
- Verify color codes match swatch codes in options

### Stock Information Not Showing

- Verify stock sync has run
- Check product/variations have stock quantities
- Verify `_amrod_stock_detail` meta exists

---

## Settings Page

The shortcodes are also documented in the plugin settings page:

**Navigate to:** Amrod Sync > Settings

You'll find a "📋 Available Shortcodes" section at the top of the settings page with:
- Shortcode names
- Descriptions
- Usage examples
- Auto-display notes

---

## Additional Resources

- **Settings Page:** Amrod Sync > Settings (shortcodes section)
- **Product Sync:** See USAGE-GUIDE.md for sync instructions
- **Color Swatches:** See COLOR-SWATCHES-GUIDE.md for detailed color swatch information

---

## Support

If you encounter issues with shortcodes:

1. Check the sync logs: **Amrod Sync > Sync Logs**
2. Verify syncs have completed successfully
3. Check product meta fields in product editor
4. Review this documentation

For additional help, contact ByteMash support.

