# SQL Prepare Fix - Dynamic IN Clauses ✅

## 🐛 **The Problem:**

When using `$wpdb->prepare()` with dynamic `IN` clauses, you can't do this:

```php
// ❌ WRONG - This causes PHP errors
$placeholders = implode(',', array_fill(0, count($skus), '%s'));
$result = $wpdb->prepare(
    "SELECT * FROM table WHERE column IN ($placeholders)",
    $skus  // Array gets passed directly
);
```

**Why it fails:**
- `$wpdb->prepare()` expects individual arguments, not arrays
- Dynamic placeholders don't work with array spreading
- Results in: `<div id="e"...` error (PHP error page instead of JSON)

---

## ✅ **The Solution:**

Build the IN clause manually with proper escaping:

```php
// ✅ CORRECT - Manually prepare each value
$placeholders = array();
foreach ($skus as $sku) {
    $placeholders[] = $wpdb->prepare('%s', $sku);
}
$in_clause = implode(',', $placeholders);

// Then use it directly (no prepare needed, already escaped)
$result = $wpdb->get_results(
    "SELECT * FROM table WHERE column IN ($in_clause)"
);
```

---

## 🔧 **What Was Fixed:**

### **Fix 1: Existing SKUs Query (Line 636-648)**

**Before:**
```php
$placeholders = implode(',', array_fill(0, count($skus), '%s'));
$existing = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.ID, pm.meta_value as sku 
        FROM {$wpdb->posts} p 
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
        WHERE pm.meta_key = '_sku' AND pm.meta_value IN ($placeholders)",
        $skus  // ❌ Array
    )
);
```

**After:**
```php
// Build IN clause properly
$placeholders = array();
foreach ($skus as $sku) {
    $placeholders[] = $wpdb->prepare('%s', $sku);
}
$in_clause = implode(',', $placeholders);

$existing = $wpdb->get_results(
    "SELECT p.ID, pm.meta_value as sku 
    FROM {$wpdb->posts} p 
    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
    WHERE pm.meta_key = '_sku' AND pm.meta_value IN ($in_clause)"
);
```

### **Fix 2: Inserted Product IDs Query (Line 740-749)**

**Before:**
```php
$slugs = array_keys($product_map);
$placeholders = implode(',', array_fill(0, count($slugs), '%s'));
$inserted = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_name IN ($placeholders) AND post_type='product'",
        $slugs  // ❌ Array
    )
);
```

**After:**
```php
$slugs = array_keys($product_map);
$slug_placeholders = array();
foreach ($slugs as $slug) {
    $slug_placeholders[] = $wpdb->prepare('%s', $slug);
}
$slug_in_clause = implode(',', $slug_placeholders);

$inserted = $wpdb->get_results(
    "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_name IN ($slug_in_clause) AND post_type='product'"
);
```

---

## 🔒 **Security:**

Both approaches are secure:

### **Option 1: Array with prepare (doesn't work)**
```php
$wpdb->prepare("... IN ($placeholders)", $array)  // ❌ Fails
```

### **Option 2: Manual prepare per value (WORKS)**
```php
foreach ($values as $value) {
    $escaped[] = $wpdb->prepare('%s', $value);  // ✅ Each value escaped
}
$in_clause = implode(',', $escaped);  // ✅ Safe to use
```

**Why it's secure:**
- Each value goes through `$wpdb->prepare()` individually
- SQL injection is prevented
- Same security as using prepare() normally

---

## ✅ **Result:**

- ✅ No more PHP errors
- ✅ No more `<div id="e"...` AJAX errors
- ✅ Queries execute successfully
- ✅ Security maintained
- ✅ Same performance

---

## 📚 **Reference:**

WordPress Codex on wpdb::prepare():
> "You must pass in as many values as there are placeholders. If you have a dynamic number of values, you need to build the query differently."

**This is the "different" way!** 🎯

---

**Status:** ✅ Fixed  
**Version:** 2.6.0  
**Files Changed:** `includes/class-product-sync.php`  
**Lines:** 636-648, 740-749


