# Amrod API Structure - Correct Understanding 📚

## ❌ **WRONG Understanding (What We Were Doing):**

We were treating each item in the API response as needing analysis to determine if it should be simple or variable.

## ✅ **CORRECT Understanding (Amrod's Actual Structure):**

### **API Response Structure:**
```json
[
  {
    "simpleCode": "ALT-HAI",  ← PARENT PRODUCT SKU
    "fullCode": "ALT-HAI",
    "productName": "Altitude Haircalf Combo",
    "variants": [  ← CHILD VARIATIONS
      {
        "simpleCode": "ALT-HAI",
        "fullCode": "ALT-HAI-W",   ← VARIATION 1
        "codeColourName": "White"
      },
      {
        "simpleCode": "ALT-HAI",
        "fullCode": "ALT-HAI-LB",  ← VARIATION 2
        "codeColourName": "Light Blue"
      }
    ]
  }
]
```

### **What This Means:**
1. **Each array item** = ONE parent product
2. **`simpleCode`** = Parent product SKU
3. **`variants` array** = Variations of that parent
4. **If `variants` exists** → Create variable product with variations
5. **If NO `variants`** → Create simple product

---

## 🎯 **Correct Logic:**

### **Rule:**
```
if (product has variants array with MORE THAN 1 entry) {
    Create VARIABLE product using simpleCode as parent SKU
    Create variations from variants array using fullCode
    Set first variation as default (prevents "select options" message)
} else if (product has variants array with 1 entry OR no variants) {
    Create SIMPLE product using simpleCode
}
```

### **NOT:**
```
// WRONG - Don't analyze variant differences
if (variants have different colors/sizes) {
    Create variable...
}
```

---

## 📊 **Examples:**

### **Example 1: Variable Product**
```json
{
  "simpleCode": "ALT-HAI",
  "variants": [
    { "fullCode": "ALT-HAI-W", "codeColourName": "White" },
    { "fullCode": "ALT-HAI-LB", "codeColourName": "Light Blue" }
  ]
}
```
**Result:** 
- ✅ ONE variable product: "ALT-HAI"
- ✅ TWO variations: "ALT-HAI-W", "ALT-HAI-LB"

### **Example 2: Simple Product**
```json
{
  "simpleCode": "AF-AM-7-D",
  "variants": null  // or empty array
}
```
**Result:**
- ✅ ONE simple product: "AF-AM-7-D"

---

## ⚠️ **The Bug We Had:**

### **What Was Happening:**
```
ALT-HAI-W → Created as separate simple product
ALT-HAI-LB → Created as separate simple product
```

### **What Should Happen:**
```
ALT-HAI → Created as variable product (parent)
  ├── ALT-HAI-W → Variation 1
  └── ALT-HAI-LB → Variation 2
```

---

## ✅ **Fixed Logic:**

```php
// CORRECT:
$has_variants = !empty($product_data['variants']);

if ($has_variants) {
    // Create variable product using simpleCode
    create_variable_product($product_data['simpleCode']);
    
    // Create variations from variants array
    foreach ($product_data['variants'] as $variant) {
        create_variation($variant['fullCode']);
    }
} else {
    // Create simple product using simpleCode
    create_simple_product($product_data['simpleCode']);
}
```

---

## 🔍 **Key Points:**

1. ✅ **Each API item** = ONE WooCommerce product
2. ✅ **`simpleCode`** = Parent/Product SKU
3. ✅ **`variants`** = Whether to create variations
4. ✅ **Don't analyze** variant differences
5. ✅ **Trust the API** structure

---

**This is the CORRECT interpretation of Amrod's API structure!**
