# Stock Batching Issue Fix

## Problem
The stock modal was showing incorrect stock information where:
- Multiple variations were being grouped together under the first color
- The first row showed batched stock data for all variations
- Subsequent rows only showed individual stock data for one variation

## Root Cause
The issue was in the `update_single_stock()` function in `includes/class-product-sync.php`. The function was using pattern matching to find all products with SKUs that start with the same `simpleCode` and then applying the same stock quantity to all matched variations.

For example:
- API returns stock data for "BC-HP-2-G" with 80,000 units
- Pattern matching finds both "BC-HP-2-G" and "BC-HP-2-G-BL" 
- Both variations get updated with 80,000 units
- Modal shows 80,000 units for the first color (batched) and 20,000 for the second color (individual)

## Solution
Removed the pattern matching logic from the stock sync process. Stock updates now only use exact SKU matches, ensuring that each variation gets its own specific stock data from the API.

### Changes Made:
1. **Removed pattern matching** in `update_single_stock()` function
2. **Updated error message** to remove reference to pattern matching
3. **Added explanatory comments** about why pattern matching is not appropriate for stock updates

### Code Changes:
```php
// BEFORE: Used pattern matching to catch all variants
if (!empty($simpleCode)) {
    global $wpdb;
    $like_pattern = $wpdb->esc_like($simpleCode) . '%';
    // ... pattern matching logic
}

// AFTER: Only exact matches for stock updates
// For stock updates, we should NOT use pattern matching as stock quantities
// are specific to individual variations. Only update exact matches.
// Pattern matching was causing stock to be incorrectly applied to multiple variations.
```

## Testing
1. Run the `clear-batched-stock-data.php` script to clear any existing incorrectly batched data
2. Run a fresh stock sync to get correct individual stock data
3. Check the stock modal to verify each variation shows its own specific stock data

## Files Modified:
- `includes/class-product-sync.php` - Removed pattern matching from stock sync
- `clear-batched-stock-data.php` - Script to clear existing batched data (temporary)

## Expected Result
After the fix:
- Each product variation will show its own specific stock quantity
- No more batching of stock data across different variations
- Stock modal will display accurate individual stock information for each color/size combination

