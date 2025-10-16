# Stock Details Modal Feature 📊

## Overview

The enhanced stock display now shows **detailed stock information** in an interactive modal popup when customers click on the stock badge.

---

## Features

### Stock Badge Display

**Basic Display (No Details):**
```
In Stock: 50 units available
```
- Shows available stock
- Not clickable
- No additional details

**Detailed Display (Clickable):**
```
In Stock: 45 units available ⓘ
```
- Shows available stock (total - reserved)
- **Clickable** - cursor changes to pointer
- **Info icon (ⓘ)** indicates more details available
- **Hover effect** - slight lift and shadow

---

## Modal Content

When customers click the stock badge, a modal popup shows:

### Stock Breakdown Table

| Field | Description | Example |
|-------|-------------|---------|
| **Total Stock** | Total units in warehouse | 50 units |
| **Reserved Stock** | Units reserved for other orders | 5 units |
| **Available Stock** | Units you can order (Total - Reserved) | **45 units** |

### Incoming Stock Table (if applicable)

| Quantity | Expected Date |
|----------|---------------|
| 100 units | Dec 4, 2025 |
| 50 units | Dec 15, 2025 |

**Shown when:** Supplier has incoming stock scheduled

---

## How It Works

### Data Storage

**During stock sync, we now store:**
1. `_stock` - Total stock quantity (WooCommerce standard)
2. `_stock_status` - Stock status: instock/outofstock (WooCommerce standard)
3. `_manage_stock` - Enable stock management (WooCommerce standard)
4. `_amrod_reserved_stock` - Reserved stock quantity (NEW)
5. `_amrod_incoming_stock` - Incoming stock data JSON (NEW)

**Example incoming stock data:**
```json
[
  {
    "total": 100,
    "date": "2025-12-04T02:00:00+02:00"
  },
  {
    "total": 50,
    "date": "2025-12-15T02:00:00+02:00"
  }
]
```

### Frontend Display

**Stock badge shows:**
- **Available stock** = Total stock - Reserved stock
- Clickable if reserved stock > 0 OR incoming stock exists
- Info icon (ⓘ) to indicate interactivity

**On click:**
- Modal opens with detailed breakdown
- Shows reserved stock
- Shows incoming stock with dates (if any)
- Can close via X button, clicking overlay, or ESC key

---

## User Experience

### Example 1: Product with Reserved Stock

**Badge Display:**
```
In Stock: 95 units available ⓘ
```

**Customer clicks** → Modal shows:
```
Stock Details
--------------------------
Total Stock:      100 units
Reserved Stock:   5 units
Available Stock:  95 units
```

### Example 2: Product with Incoming Stock

**Badge Display:**
```
Low Stock: Only 5 left! ⓘ
```

**Customer clicks** → Modal shows:
```
Stock Details
--------------------------
Total Stock:      5 units
Reserved Stock:   0 units
Available Stock:  5 units

Incoming Stock
--------------------------
Quantity         Expected Date
100 units        Dec 4, 2025
50 units         Dec 15, 2025
```

### Example 3: Simple Stock (No Details)

**Badge Display:**
```
In Stock: 50 units available
```

**No info icon** - Not clickable (no reserved or incoming stock)

---

## Settings

**Location:** Amrod Sync → Settings → Advanced Settings

### Enhanced Stock Display
- **Type:** Checkbox
- **Default:** Enabled
- **Function:** Show/hide stock badges

### Low Stock Threshold
- **Type:** Number (1-100)
- **Default:** 10 units
- **Function:** When to show "Low Stock" warning

---

## Technical Implementation

### Backend (PHP)

**Stock Sync (`update_batch_stock()`):**
```php
// Store detailed data
foreach ($product_ids as $pid) {
    update_post_meta($pid, '_amrod_reserved_stock', $reserved);
    update_post_meta($pid, '_amrod_incoming_stock', json_encode($incoming));
}
```

**Frontend Display (`display_enhanced_stock()`):**
```php
// Get detailed data
$reserved = get_post_meta($product_id, '_amrod_reserved_stock', true);
$incoming = json_decode(get_post_meta($product_id, '_amrod_incoming_stock', true), true);

// Calculate available stock
$available = $total - $reserved;

// Output clickable badge with data attributes
echo '<div data-stock-details="' . json_encode($modal_data) . '">';
```

### Frontend (JavaScript)

**Modal Handler (`stock-modal.js`):**
```javascript
$('.bytemash-stock-display.has-details').on('click', function() {
    const stockData = $(this).data('stock-details');
    
    // Populate modal
    $('#modal-total-stock').text(stockData.total);
    $('#modal-reserved-stock').text(stockData.reserved);
    $('#modal-available-stock').text(stockData.available);
    
    // Show incoming stock if exists
    if (stockData.incoming) {
        // Build incoming stock table rows
    }
    
    // Show modal
    $('#bytemash-stock-modal').fadeIn();
});
```

### Styling (CSS)

**Components:**
- `.bytemash-stock-display.has-details` - Clickable badge with hover effect
- `.bytemash-stock-modal` - Full-screen modal overlay
- `.bytemash-stock-modal-content` - Centered modal box
- `.bytemash-stock-table` - Stock information table
- Responsive design for mobile

---

## API Data Structure

**Amrod Stock API Returns:**
```json
{
  "simpleCode": "ABC-123",
  "fullCode": "ABC-123-R-M",
  "stock": 50,
  "reservedStock": 5,
  "incomingStock": [
    {
      "total": 100,
      "date": "2025-12-04T02:00:00+02:00"
    }
  ]
}
```

**We Store:**
- `stock` → `_stock` meta (Total)
- `reservedStock` → `_amrod_reserved_stock` meta
- `incomingStock` → `_amrod_incoming_stock` meta (JSON)

**We Display:**
- Total Stock
- Reserved Stock
- **Available Stock = Total - Reserved** (Important!)
- Incoming Stock with formatted dates

---

## Files Created/Modified

### New Files:
1. ✅ `assets/js/stock-modal.js` - Modal functionality
2. ✅ `STOCK-MODAL-FEATURE.md` - This documentation

### Modified Files:
1. ✅ `includes/class-product-sync.php`
   - `update_batch_stock()` - Store reserved & incoming stock
   - `update_single_stock()` - Store reserved & incoming stock

2. ✅ `bytemash-woo-sync.php`
   - `enqueue_frontend_stock_display()` - Enqueue modal JS
   - `display_enhanced_stock()` - Clickable badge with modal data
   - `render_stock_modal_template()` - Modal HTML template (NEW)

3. ✅ `assets/css/stock-display.css`
   - Added modal styles
   - Added clickable badge styles
   - Added hover effects

4. ✅ `admin/class-admin-settings.php`
   - Added "Enhanced Stock Display" toggle
   - Added "Low Stock Threshold" setting
   - Settings save handler

---

## Benefits

### For Customers:
- ✅ **Transparency:** See exactly how much stock is available
- ✅ **Planning:** View incoming stock dates for pre-orders
- ✅ **Confidence:** Know reserved stock won't affect their order
- ✅ **Better UX:** Interactive, informative modal

### For Store Owners:
- ✅ **Reduce support tickets** - Customers self-serve stock info
- ✅ **Increase trust** - Transparency builds confidence
- ✅ **Enable pre-orders** - Show incoming stock dates
- ✅ **Accurate availability** - Show real available stock (not reserved)
- ✅ **Toggleable** - Turn on/off in settings

---

## Usage Examples

### Example 1: High Demand Product

**Amrod Data:**
- Total: 200 units
- Reserved: 150 units (other orders)
- Available: 50 units

**Customer sees:**
```
In Stock: 50 units available ⓘ
```

**Clicks** → Modal:
```
Total Stock:      200 units
Reserved Stock:   150 units
Available Stock:  50 units ← Can order up to 50
```

### Example 2: Pre-Order Scenario

**Amrod Data:**
- Total: 5 units
- Reserved: 0
- Incoming: 100 units on Dec 4

**Customer sees:**
```
Low Stock: Only 5 left! ⓘ
```

**Clicks** → Modal:
```
Total Stock:      5 units
Reserved Stock:   0 units
Available Stock:  5 units

Incoming Stock:
100 units        Dec 4, 2025
```

**Result:** Customer knows more stock is coming soon!

---

## Testing

### Test 1: Sync Stock with Details

1. Go to **Amrod Sync → Dashboard**
2. Click **"Sync Stock"**
3. Wait for completion
4. Visit a product page
5. Look for stock badge with **ⓘ** icon

### Test 2: Click Stock Badge

1. Find product with **ⓘ** icon
2. **Click the badge**
3. Modal should open showing:
   - Total, Reserved, Available stock
   - Incoming stock (if any)
4. Click X or overlay to close

### Test 3: Toggle Feature

1. Go to **Settings → Advanced Settings**
2. **Uncheck** "Enhanced Stock Display"
3. Save
4. Visit product page → No badge shown
5. **Check** the box again
6. Save
7. Visit product page → Badge appears

---

## Customization

### Change Modal Colors

```css
.bytemash-stock-modal-content {
    border: 3px solid #your-brand-color;
}

.bytemash-stock-modal-header {
    background: #your-brand-color;
    color: white;
}
```

### Change Badge Position

```php
// Remove from default position (priority 15)
remove_action('woocommerce_single_product_summary', array($plugin, 'display_enhanced_stock'), 15);

// Add to new position (priority 25 = after Add to Cart)
add_action('woocommerce_single_product_summary', array($plugin, 'display_enhanced_stock'), 25);
```

### Hide Info Icon

```css
.bytemash-stock-display .stock-details-icon {
    display: none;
}
```

---

## Performance

### Lightweight Implementation:
- **CSS:** ~5KB (modal styles)
- **JS:** ~3KB (modal handler)
- **HTTP Requests:** +2 (CSS + JS, cacheable)
- **Database:** No additional queries (data already loaded)

### Optimization:
- Only loads on single product pages
- Only loads if feature enabled
- CSS and JS are cached by browser
- No AJAX calls - all data in HTML

---

## Accessibility

### Keyboard Support:
- **ESC key:** Close modal
- **Tab navigation:** Navigate modal elements
- **Enter key:** Submit/close

### Screen Readers:
- Proper ARIA labels
- Semantic HTML
- Table structure for data
- Clear heading hierarchy

---

## Summary

**New Feature:** Interactive stock details modal  
**Trigger:** Click on stock badge  
**Shows:** Total, Reserved, Available, and Incoming stock  
**Toggleable:** Yes, in Settings  
**Performance:** Lightweight and fast  
**Status:** ✅ Complete  

**This feature increases customer confidence and reduces support inquiries!** 💰

---

**Version:** 2.5.0  
**Date:** October 16, 2025  
**Status:** ✅ Active

