# Product Sync Pipeline & Optimizations

### Overview

The product sync pipeline converts Amrod’s product payloads into WooCommerce products. Every Amrod product is identified by `simpleCode`/`fullCode` (stored as WooCommerce `_sku`). The sync flow:

1. **Fetch batch** from Amrod (full or incremental endpoints).
2. **Lookup by SKU** (via batch-preloaded cache or direct `_sku` SQL query).
3. **Skip unchanged payloads** using deterministic signatures.
4. **Process changes** (simple or variable products).
5. **Persist payload snapshot + signature** for future comparisons.

### Signature & Snapshot System

- Each payload is sanitized to remove noisy fields (dynamic URLs, reordering).
- An MD5 signature of the sanitized payload is stored in `_amrod_payload_signature`.
- The sanitized payload is saved in `_amrod_payload_snapshot`.
- Before processing, we recompute the signature:
  - If the signature matches the stored value, the product is skipped.
  - If it differs, we diff the two snapshots to see exactly which fields changed.

### Field-Level Diffing

To avoid heavy operations when only a small sub-section changed:

- We derive a list of changed fields from the payload diff.
- Category sync runs only if categories changed.
- Image sync runs only if image/color data changed.
- Meta sync runs only if brand/meta payload fields changed.
- Variation/attribute build runs only if variants or relevant color/image fields changed.

This dramatically reduces DB writes on incremental syncs.

### Variable Products & Variations

- Parent products become `WC_Product_Variable`.
- Attributes are rebuilt only when variant payloads changed.
- **Variant signatures**: each variation has `_amrod_variation_signature`. If a payload matches, that variant is skipped entirely.
- Variation logs indicate counts created, skipped, and errored.

### Simple Products

- Follow the same signature/snapshot logic.
- Field-level gating ensures we only touch categories, images, meta, etc., when necessary.
- Stock updates are still possible within the same pass if stock data is present.

### Conversion Logic

- If Amrod sends zero variants for a SKU that used to be variable, we convert it back to a simple product (after preserving meta).
- Conversely, if variants appear for an SKU that was simple, we upgrade it to variable.
- During conversions the WooCommerce post ID may change, but we still log the action as an update (not a new product) because the SKU already existed.

### Realtime Logging Enhancements

- Every skip or process event is logged via `log_realtime_activity()` under the `realtime_activity` channel.
- For updates we log a concise summary of which fields changed (e.g. `description: "Old" -> "New"`).
- Logs include whether the action was `skipped`, `processing`, or `variation_skipped`, with reasons (`payload unchanged`, `payload changed`, `force update`, etc.).

### Performance Optimizations Recap

- **Batch SKU lookup cache** reduces DB roundtrips per batch.
- **Payload signatures** eliminate redundant saves for unchanged products.
- **Field-gated sections** ensure categories/images/meta only run when their inputs changed.
- **Variant signatures** stop recreating variations unnecessarily.
- **Diff summaries** provide visibility into what changed without reading entire payloads.

### When Skips Are Persisted

- A product must be processed once with the new signature system to store its `_amrod_payload_signature` and `_amrod_payload_snapshot`.
- After that initial run, all subsequent syncs leverage those stored values to short-circuit unchanged payloads.

### Troubleshooting Tips

- **Seeing “new product” logs repeatedly?** Most likely the product is being converted between simple/variable and the post ID is momentarily recreated. Actual duplication would indicate a failed `_sku` lookup (check product logs for `sku` and `product_id`).
- **Skip counts seem low?** Ensure a full sync has run after the signature system was introduced so all products have baseline signatures.
- **Need to inspect a diff?** Check the `realtime_activity` logs; they include the per-field differences and reason codes.


