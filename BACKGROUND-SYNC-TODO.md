# Background Sync Implementation TODO

## Current Status

**Current Sync Method:** JavaScript-driven AJAX calls (requires browser tab open)
**Location:** `assets/js/admin.js` - `processNextBatchFromQueue()` function

**Problem:** User must keep browser tab open during entire sync (30+ minutes for 3930 products)

---

## Goal

Implement true background sync using WP-Cron so:
- ✅ Syncs run without browser open
- ✅ Scheduled syncs work automatically
- ✅ Can close browser after starting sync
- ✅ Syncs continue even if user logs out

---

## Implementation Plan

### 1. Update Scheduler to Use Queue System

**File:** `includes/class-sync-scheduler.php`

**Current Code (OLD):**
```php
public function run_scheduled_sync() {
    $product_sync = new ByteMash_Product_Sync();
    $product_sync->sync_all_products(); // OLD METHOD
}
```

**Need to Change To:**
```php
public function run_scheduled_sync() {
    // Start queue-based sync
    $product_sync = new ByteMash_Product_Sync();
    $result = $product_sync->sync_all_products(false, true);
    
    if ($result['success'] && isset($result['sync_id'])) {
        // Trigger background processing of queue
        $this->process_queue_in_background($result['sync_id']);
    }
}

private function process_queue_in_background($sync_id) {
    // Schedule WP-Cron to process batches one by one
    wp_schedule_single_event(time(), 'bytemash_process_queue_batch', array($sync_id));
}
```

---

### 2. Add WP-Cron Hook for Queue Processing

**File:** `includes/class-batch-processor.php`

**Add to `init_hooks()`:**
```php
add_action('bytemash_process_queue_batch', array($this, 'process_queue_batch_cron'), 10, 1);
```

**Add New Method:**
```php
public function process_queue_batch_cron($sync_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bytemash_sync_queue';
    
    // Get next pending batch from queue
    $batch_row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE sync_id = %s AND status = 'pending' ORDER BY batch_index ASC LIMIT 1",
        $sync_id
    ));
    
    if (!$batch_row) {
        // No more batches - mark sync as complete
        $this->complete_sync($sync_id);
        return;
    }
    
    // Process this batch
    $batch_data = json_decode($batch_row->batch_data, true);
    $batch_index = $batch_row->batch_index;
    
    // Mark as processing
    $wpdb->update($table_name, 
        array('status' => 'processing'),
        array('id' => $batch_row->id)
    );
    
    // Process products
    $product_sync = new ByteMash_Product_Sync();
    $processed = 0;
    $errors = 0;
    
    foreach ($batch_data as $product_data) {
        $result = $product_sync->sync_single_product($product_data);
        if ($result['success']) {
            $processed++;
        } else {
            $errors++;
        }
    }
    
    // Mark batch as complete
    $wpdb->update($table_name,
        array('status' => 'completed'),
        array('id' => $batch_row->id)
    );
    
    // Update sync progress
    $sync_info = get_option("bytemash_sync_{$sync_id}");
    $sync_info['current_batch'] = $batch_index + 1;
    $sync_info['processed'] += $processed;
    $sync_info['errors'] += $errors;
    update_option("bytemash_sync_{$sync_id}", $sync_info, false);
    
    // Schedule next batch (5 seconds delay to avoid server overload)
    wp_schedule_single_event(time() + 5, 'bytemash_process_queue_batch', array($sync_id));
}

private function complete_sync($sync_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bytemash_sync_queue';
    
    // Clean up queue table
    $wpdb->delete($table_name, array('sync_id' => $sync_id));
    
    // Update sync info
    $sync_info = get_option("bytemash_sync_{$sync_id}");
    $sync_info['status'] = 'completed';
    $sync_info['completed'] = current_time('mysql');
    update_option("bytemash_sync_{$sync_id}", $sync_info, false);
    
    // Clear sync running flag
    delete_transient('bytemash_sync_running');
    
    // Log completion
    $this->logger->log('success', 'Background sync completed', array(
        'sync_id' => $sync_id,
        'total_processed' => $sync_info['processed'],
    ), 'scheduled_sync');
}
```

---

### 3. Update Manual Sync to Support Both Modes

**File:** `bytemash-woo-sync.php`

**In `ajax_manual_sync()`, add parameter:**
```php
public function ajax_manual_sync() {
    // ... existing code ...
    
    $background = isset($_POST['background']) ? (bool) $_POST['background'] : false;
    
    $result = $product_sync->sync_all_products(false, true);
    
    if ($result['success']) {
        if ($background) {
            // Use WP-Cron for background processing
            $scheduler = new ByteMash_Sync_Scheduler();
            $scheduler->process_queue_in_background($result['sync_id']);
            
            wp_send_json_success(array(
                'message' => 'Background sync started',
                'sync_id' => $result['sync_id'],
                'background' => true
            ));
        } else {
            // Use JavaScript for real-time processing (current method)
            wp_send_json_success(array(
                'message' => $result['message'],
                'sync_id' => $result['sync_id'],
                'total' => $result['total'],
                'batch_count' => $result['batch_count']
            ));
        }
    }
}
```

---

### 4. Add UI Toggle for Sync Mode

**File:** `admin/class-admin-dashboard.php`

**Add checkbox to sync buttons:**
```php
<label>
    <input type="checkbox" id="background_sync" value="1">
    Run in background (can close browser)
</label>

<button type="button" 
        data-ajax-action="bytemash_manual_sync"
        data-background-sync="#background_sync">
    Full Sync
</button>
```

**Update JavaScript:**
```javascript
const useBackground = $(this).data('background-sync');
const backgroundEnabled = useBackground ? $(useBackground).is(':checked') : false;

$.ajax({
    data: {
        action: action,
        background: backgroundEnabled,
        nonce: nonce
    }
});
```

---

### 5. Add Progress Monitoring for Background Syncs

**File:** `admin/class-admin-dashboard.php`

**Show background sync status:**
```php
// Check for running background syncs
$batch_processor = new ByteMash_Batch_Processor();
$active_syncs = $batch_processor->get_active_syncs();

foreach ($active_syncs as $sync) {
    if ($sync['status'] === 'processing') {
        echo '<div class="notice notice-info">';
        echo '<p>Background sync in progress: ' . $sync['processed'] . '/' . $sync['total'] . ' products</p>';
        echo '</div>';
    }
}
```

---

## Testing Checklist

When implementing, test:

- [ ] Manual sync with background=false (current JavaScript method)
- [ ] Manual sync with background=true (WP-Cron method)
- [ ] Scheduled sync uses WP-Cron automatically
- [ ] Can close browser tab during background sync
- [ ] Background sync continues after tab closed
- [ ] Background sync completes successfully
- [ ] Queue table cleaned up after completion
- [ ] Progress visible in dashboard even after browser closed/reopened
- [ ] Stop button works for both modes
- [ ] Error handling for failed batches
- [ ] Memory limits still respected

---

## Files to Modify

1. **includes/class-sync-scheduler.php** - Update scheduled sync to use queue
2. **includes/class-batch-processor.php** - Add WP-Cron batch processing method
3. **bytemash-woo-sync.php** - Add background parameter to manual sync
4. **admin/class-admin-dashboard.php** - Add background sync toggle checkbox
5. **assets/js/admin.js** - Pass background flag to AJAX

---

## Key Differences

### JavaScript Mode (Current):
- Requires browser open
- Real-time visual feedback
- Faster (no 5-second delays)
- Best for: Testing, watching progress

### Background Mode (To Implement):
- Runs via WP-Cron
- No browser needed
- 5-second delays between batches
- Best for: Large syncs, scheduled syncs, production

---

## Important Notes

### WP-Cron Limitations:
- Only runs when site is visited
- May not run if no traffic
- Can be unreliable on some hosts
- Consider using server cron for production

### Alternative: Action Scheduler
- More reliable than WP-Cron
- Used by WooCommerce
- Requires Action Scheduler library
- Better for production environments

---

## Recommended Approach

### Phase 1: Basic Background Support
- Implement WP-Cron as described above
- Keep JavaScript mode as default
- Add checkbox for background mode

### Phase 2: Enhanced Reliability
- Integrate Action Scheduler library
- More reliable batch processing
- Better for high-traffic sites

### Phase 3: Hybrid Approach
- Start sync in JavaScript (first 2-3 batches)
- Switch to WP-Cron for remaining batches
- Best of both worlds: instant feedback + background processing

---

## Current Implementation Status

✅ Queue-based storage system ready
✅ Process batch method works
✅ Database table structure correct
✅ Memory optimization complete
✅ Stop button functional

⏳ Need to add:
- WP-Cron hook registration
- Background processing method
- UI toggle for background mode
- Progress monitoring for background syncs

---

## Estimated Implementation Time

- Basic WP-Cron integration: ~30 minutes
- UI updates and testing: ~20 minutes
- Documentation updates: ~10 minutes
- **Total: ~1 hour**

---

## Priority

**Current Priority:** Medium

**Reasons:**
- JavaScript mode works fine for manual syncs
- User can watch progress in real-time
- Main use case (manual sync) is functional

**When to Prioritize:**
- User has large catalogs (10,000+ products)
- Needs unattended syncs
- Browser stability issues
- Production deployment

---

## Reference

**Current Working Code:**
- `ajax_process_batch()` in `bytemash-woo-sync.php` line ~630
- `processNextBatchFromQueue()` in `assets/js/admin.js` line ~255
- Queue table structure at plugin activation line ~254

**Will Need:**
- New method: `process_queue_batch_cron()` in batch processor
- New action: `bytemash_process_queue_batch`
- Updated: `run_scheduled_sync()` in sync scheduler

---

**This document serves as complete specification for implementing background sync when needed.**

