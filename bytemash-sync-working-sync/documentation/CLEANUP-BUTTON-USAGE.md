# 🧹 Zero Prices Cleanup Button - User Guide

## What It Does

The **"Remove Fake Zero Prices"** button removes fake `'0'` prices that were set by the plugin, which interfere with YITH Request a Quote and other quote plugins.

## Location

**WordPress Admin → Amrod Sync → Settings**

Scroll down to the **"YITH Compatibility"** section.

## When to Use

Use this button if:
- ✅ YITH Request a Quote button is not showing
- ✅ Products should show quote buttons but don't
- ✅ You recently disabled the plugin's price interference
- ✅ Products have prices of exactly `0.00` that shouldn't be there

## How to Use

### Step 1: Navigate to Settings
1. Go to **WordPress Admin**
2. Click **Amrod Sync** in the left menu
3. Click **Settings**
4. Scroll to **"YITH Compatibility"** section

### Step 2: Run Cleanup
1. Click the **"Remove Fake Zero Prices"** button
2. Confirm the action in the popup dialog
3. Wait for the cleanup to complete (usually 1-3 seconds)
4. Review the results message

### Step 3: Clear Caches
After cleanup:
1. **Clear WordPress cache** (if using caching plugin)
2. **Clear browser cache** (Ctrl+Shift+R / Cmd+Shift+R)
3. **Clear CDN cache** (if applicable)

### Step 4: Test YITH
1. Visit a product page without a real price
2. YITH "Request Quote" button should now appear
3. If not, check YITH's own settings

## What the Button Does

1. ✅ **Scans database** for products with `_price = '0'` and `_regular_price = '0'`
2. ✅ **Removes fake prices** from affected products
3. ✅ **Clears WooCommerce transients** for clean data
4. ✅ **Shows results** - number of products cleaned

## Results Messages

### ✅ Success Messages

**"✅ No fake zero prices found. Your products are clean!"**
- No action needed
- YITH should already be working
- If YITH still doesn't work, check YITH's settings

**"✅ Successfully removed X fake price entries from Y products."**
- Cleanup completed successfully
- X = number of meta entries removed
- Y = number of products affected
- Clear caches and test YITH now

### ❌ Error Messages

**"❌ Database error occurred during cleanup."**
- Rare database error
- Try again
- Contact support if persists

**"Insufficient permissions"**
- You need WooCommerce management permissions
- Contact your site administrator

## FAQ

### Q: Will this delete my real prices?
**A:** No! It only removes prices that are exactly `'0'` (zero). Real prices are safe.

### Q: How often should I run this?
**A:** Once is usually enough. Run again only if:
- YITH stops working after a sync
- You notice fake zero prices appearing again

### Q: Can I undo this action?
**A:** No, but it's safe! It only removes fake `'0'` prices. Your real product prices are untouched.

### Q: What if I have products that legitimately cost $0?
**A:** If you sell free products (price = $0), you'll need to manually re-set their price to `0.00` after cleanup. This is rare.

### Q: Will this affect my synced prices?
**A:** No. The next price sync will restore proper prices from Amrod API.

### Q: YITH still doesn't work after cleanup
**A:** Check these:
1. **Cache cleared?** - Clear all caches
2. **YITH configured?** - Check YITH settings
3. **Products excluded?** - Check if products are in YITH exclusions
4. **Plugin conflict?** - Temporarily disable other plugins to test

## Technical Details

The button executes:
```sql
DELETE FROM wp_postmeta
WHERE meta_key IN ('_price', '_regular_price')
AND meta_value = '0';
```

Then clears:
- Product transients
- WooCommerce shop transients
- Product cache

## Safety

✅ **Safe to use** - Only removes fake zero prices  
✅ **Permission-protected** - Requires WooCommerce management rights  
✅ **Nonce-verified** - Protected against CSRF attacks  
✅ **Real prices preserved** - Only touches `'0'` values  
✅ **Cache clearing** - Automatically clears related caches  

## Support

If issues persist after using this button:
1. Check the documentation: `documentation/YITH-COMPATIBILITY-FIX.md`
2. Verify YITH settings are correct
3. Test with YITH support if plugin-specific issue
4. Contact plugin support with error messages if needed

---

**Remember**: This is a one-time cleanup. Once done, YITH should work correctly unless fake prices are created again.


