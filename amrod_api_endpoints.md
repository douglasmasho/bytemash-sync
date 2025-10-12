Certainly! Here’s a Markdown summary of all endpoints in the **Amrod's Client Vendors 2.0.9** collection, including their purposes and full links:

---

# Amrod's Client Vendors 2.0.9 – Endpoint Summary

## 1. Authentication

- **GET Token Vendor**  
  *Purpose:* Authenticate user and provide a token for further API calls.  
  **POST** [`https://identity.amrod.co.za/VendorLogin`](https://identity.amrod.co.za/VendorLogin)

---

## 2. Products

- **Get Products without Branding**  
  *Purpose:* Returns all products and giftsets without branding information.  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/Products/`](https://vendorapi.amrod.co.za/api/v1/Products/)

- **Get Products without Branding Updated**  
  *Purpose:* Returns only products and giftsets without branding that have changed since the previous day.  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/Products/GetUpdatedProducts`](https://vendorapi.amrod.co.za/api/v1/Products/GetUpdatedProducts)

- **Get Products with Branding**  
  *Purpose:* Returns all products and giftsets including branding information.  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/Products/GetProductsAndBranding`](https://vendorapi.amrod.co.za/api/v1/Products/GetProductsAndBranding)

- **Get Products with Branding Updated**  
  *Purpose:* Returns only products and giftsets with branding that have changed since the previous day.  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/Products/GetUpdatedProductsAndBranding`](https://vendorapi.amrod.co.za/api/v1/Products/GetUpdatedProductsAndBranding)

---

## 3. Stock

- **Get Stock**  
  *Purpose:* Returns all stock for all products (updated 4 times a day).  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/Stock/`](https://vendorapi.amrod.co.za/api/v1/Stock/)

- **Get Outlet Stock**  
  *Purpose:* Returns stock for all products at outlet level (updated 4 times a day).  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/Stock/Outlet`](https://vendorapi.amrod.co.za/api/v1/Stock/Outlet)

- **Get Stock Updated**  
  *Purpose:* Returns rolling/differential stock changes since the first full stock sync of the day.  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/Stock/GetUpdated`](https://vendorapi.amrod.co.za/api/v1/Stock/GetUpdated)

---

## 4. Prices

- **Get Prices**  
  *Purpose:* Returns the latest prices for all products.  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/Prices/`](https://vendorapi.amrod.co.za/api/v1/Prices/)

- **Get Prices Updated**  
  *Purpose:* Returns only updated or changed prices since the previous day.  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/Prices/GetUpdated`](https://vendorapi.amrod.co.za/api/v1/Prices/GetUpdated)

---

## 5. Categories

- **Get Categories**  
  *Purpose:* Returns all categories in a full tree structure with nested categories.  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/Categories/`](https://vendorapi.amrod.co.za/api/v1/Categories/)

- **Get Categories Updated**  
  *Purpose:* Returns only updated categories since the previous day.  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/Categories/GetUpdated`](https://vendorapi.amrod.co.za/api/v1/Categories/GetUpdated)

---

## 6. Brands

- **Get Brands**  
  *Purpose:* Returns all product brands offered by Amrod.  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/Brands/`](https://vendorapi.amrod.co.za/api/v1/Brands/)

- **Get Brands Updated**  
  *Purpose:* Returns only updated brands since the previous day.  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/Brands/GetUpdated`](https://vendorapi.amrod.co.za/api/v1/Brands/GetUpdated)

---

## 7. Branding Departments

- **Get Full Branding Department List**  
  *Purpose:* Returns all branding departments.  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/BrandingDepartments/`](https://vendorapi.amrod.co.za/api/v1/BrandingDepartments/)

- **Get Full Branding Department List Updated**  
  *Purpose:* Returns only updated branding departments since the previous day.  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/BrandingDepartments/GetUpdated`](https://vendorapi.amrod.co.za/api/v1/BrandingDepartments/GetUpdated)

---

## 8. Branding Prices

- **Get Full Branding Price List**  
  *Purpose:* Returns all branding prices for lookup when calculating branding costs.  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/BrandingPrices/`](https://vendorapi.amrod.co.za/api/v1/BrandingPrices/)

- **Get Full Branding Price List Updated**  
  *Purpose:* Returns only updated branding prices since the previous day.  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/BrandingPrices/GetUpdated`](https://vendorapi.amrod.co.za/api/v1/BrandingPrices/GetUpdated)

---

## 9. Inclusive Branding

- **Get Inclusive Brandings**  
  *Purpose:* Returns all inclusive branding specials.  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/InclusiveBrandings/`](https://vendorapi.amrod.co.za/api/v1/InclusiveBrandings/)

- **Get Inclusive Brandings Updated**  
  *Purpose:* Returns only updated inclusive branding specials since the previous day.  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/InclusiveBrandings/GetUpdated`](https://vendorapi.amrod.co.za/api/v1/InclusiveBrandings/GetUpdated)

---

## 10. Colour Swatches

- **Get Colour Swatches**  
  *Purpose:* Returns all product colours and their HEX values.  
  **GET** [`https://vendorapi.amrod.co.za/api/v1/ColourSwatches/`](https://vendorapi.amrod.co.za/api/v1/ColourSwatches/)

---

## 11. Example Image

- **Product Image Example**  
  *Purpose:* Example endpoint for retrieving a product image.  
  **GET** [`https://amrcdn.amrod.co.za/amrodprod-blob/ProductImages/WR-AL-7-F/WR-AL-7-F-N_1024X1024.jpg`](https://amrcdn.amrod.co.za/amrodprod-blob/ProductImages/WR-AL-7-F/WR-AL-7-F-N_1024X1024.jpg)

---




**Tip:**  
The response bodies are located in the folder /responses

