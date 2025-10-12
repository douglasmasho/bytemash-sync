# API URL Issue - Troubleshooting Guide

## 🔴 **Problem Identified**

```
Error: 530 - Origin DNS error
URL: https://api.amrod.co.za/api/products/count
Cause: Incorrect API base URL
```

**The authentication works** (✅ `https://identity.amrod.co.za/VendorLogin`)  
**But product API fails** (❌ `https://api.amrod.co.za/api/products/count`)

This means the API base URL is wrong!

---

## ✅ **Solutions Implemented**

### 1. **Changed Default API URL**
```
OLD: https://api.amrod.co.za
NEW: https://newapidocs.amrod.co.za
```

### 2. **Auto-Detection from Authentication**
The plugin now checks if the authentication response includes the correct API URL and uses it automatically.

### 3. **Manual Testing Tool**
Created `update-api-url.php` to quickly test different URLs.

---

## 🚀 **What to Do Next**

### **Option A: Re-authenticate**

1. **Disconnect** from current session:
   ```
   Amrod Sync → Settings → Click "Disconnect"
   ```

2. **Re-authenticate**:
   ```
   Enter credentials again
   Plugin will use new default URL
   ```

3. **Check Debug Logs**:
   ```
   Visit: debug-logs.php
   Look for: "Authentication successful"
   Check: "full_response" field
   ```

### **Option B: Manual URL Testing**

1. **Edit** `update-api-url.php`:
   ```php
   $new_api_url = 'https://CORRECT-URL-HERE';
   ```

2. **Visit**: `http://woo-sync.local/update-api-url.php`

3. **Test sync** and check debug logs

4. **Repeat** with different URLs until you find the right one

---

## 🎯 **URLs to Try**

Based on Amrod's infrastructure, try these:

| URL | Purpose | Status |
|-----|---------|--------|
| `https://identity.amrod.co.za` | Authentication | ✅ Working |
| `https://api.amrod.co.za` | Product API | ❌ DNS Error |
| `https://newapidocs.amrod.co.za` | Documentation/API | ? Test This |
| `https://vendor.amrod.co.za/api` | Vendor Portal API | ? Test This |
| `https://amrod.co.za/api` | Main Site API | ? Test This |

---

## 🔍 **How to Find the Correct URL**

### **Method 1: Check Authentication Response**

1. **Re-authenticate** with new code
2. **Check debug logs** immediately after
3. **Look for** authentication log entry
4. **Expand** the "Additional Data" section
5. **Find** `full_response` field
6. **Look for** any of these keys:
   - `api_url`
   - `apiUrl`
   - `base_url`
   - `baseUrl`
   - `api_endpoint`
   - `endpoint`

### **Method 2: Contact Amrod**

Email/call Amrod support and ask:
```
"What is the correct API base URL for 
product endpoints after authenticating 
via https://identity.amrod.co.za/VendorLogin?"
```

### **Method 3: Check Documentation**

1. Visit: https://newapidocs.amrod.co.za/
2. Look for "Base URL" or "Endpoint" section
3. Check if there's a different URL for authenticated requests

---

## 📝 **Debug Checklist**

Run through these steps:

### ✅ **Step 1: Check Current URL**
```
Settings → Connection Info → API URL
Current: ?
```

### ✅ **Step 2: Check Authentication Response**
```
Debug Logs → Filter: "authentication"
Look for: "full_response"
Contains api_url?: ?
```

### ✅ **Step 3: Test with Different URL**
```
1. Update via update-api-url.php
2. Try sync
3. Check debug logs
4. Look for 200 status instead of 530
```

### ✅ **Step 4: Verify Token Works**
```
If you get 401 (Unauthorized):
  - Token is correct format but may not have permissions
  - Try re-authenticating

If you get 404 (Not Found):
  - URL is right server but wrong endpoint path
  - Check endpoint structure

If you get 530 (DNS Error):
  - URL/domain doesn't exist
  - Try different base URL
```

---

## 🔧 **Quick Test Script**

If you want to test URLs manually via curl:

```bash
# Get your token from the database
TOKEN="your-token-here"

# Test different base URLs
curl -H "Authorization: Bearer $TOKEN" \
     https://newapidocs.amrod.co.za/api/products/count

curl -H "Authorization: Bearer $TOKEN" \
     https://vendor.amrod.co.za/api/products/count

curl -H "Authorization: Bearer $TOKEN" \
     https://amrod.co.za/api/v1/products/count
```

---

## 📊 **Expected Working Response**

When you find the correct URL, you should see:

```json
{
  "status": "success",
  "count": 1234,
  "data": { ... }
}
```

Or:

```json
{
  "total": 1234,
  "products": [ ... ]
}
```

**Not**:
```html
<!doctype html>
<html>
  <title>Origin DNS error</title>
```

---

## 🎯 **Most Likely Solution**

Based on the working authentication endpoint, the API is probably:

```
Authentication: https://identity.amrod.co.za/VendorLogin ✅
Products API:   https://identity.amrod.co.za/api/products ❓

OR

Products API:   https://vendor.amrod.co.za/api/products ❓
```

**Try these first!**

---

## ⚠️ **Important Notes**

1. **After finding correct URL**: Update in Settings and re-sync
2. **Delete test files**: `update-api-url.php` and `debug-logs.php`
3. **Document it**: Save the working URL for future reference

---

## 🆘 **Still Not Working?**

If none of the URLs work:

1. **Contact Amrod Support** - They'll provide the exact endpoint
2. **Check API Access** - Your account may need API access enabled
3. **Verify Token** - The token may not have product read permissions
4. **Check Customer Code** - You may need to provide a specific customer code

---

**The plugin is ready - we just need the correct API URL from Amrod!** 🚀

