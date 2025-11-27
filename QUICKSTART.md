# Ultra-High-Performance Stock Sync - Quick Start Guide

## Installation & Activation

1. **Activate Plugin** (runs database migrations automatically):
   ```bash
   wp plugin activate bytemash-woo-sync
   ```

2. **Verify Database Migrations**:
   ```sql
   -- Check batch table
   SHOW TABLES LIKE 'wp_bytemash_batches';
   
   -- Check indexes
   SHOW INDEX FROM wp_postmeta WHERE Key_name IN ('meta_key_value', 'bytemash_modified', 'unique_postmeta');
   ```

## Usage

### Option 1: WP-CLI (Recommended for Maximum Performance)

```bash
# Basic stock sync
wp bytemash sync-stock

# High-performance mode (powerful servers)
wp bytemash sync-stock --parallel=10

# Benchmark mode
wp bytemash sync-stock --benchmark
```

### Option 2: Admin Dashboard

1. Navigate to **ByteMash → Dashboard**
2. Click **"Sync Stock"** button
3. Monitor real-time progress
4. View completion stats

### Option 3: Programmatic

```php
$stock_sync = new ByteMash_Stock_Sync_Optimized();
$result = $stock_sync->sync_all_stock('custom_sync_' . time());

if ($result['success']) {
    echo "Processed: " . $result['stats']['processed'];
    echo "Duration: " . $result['duration'] . "s";
}
```

## Performance Tuning

### Increase Concurrency (Powerful Servers)

```php
// In functions.php or custom plugin
add_filter('bytemash_action_scheduler_concurrency', function() {
    return 15; // 15 concurrent runners (requires 4GB+ RAM)
});
```

### Adjust Batch Size

```php
// Larger batches = fewer database round-trips
update_option('bytemash_stock_batch_size', 200); // Default: 100
```

## Monitoring

### Check Sync Logs

```bash
tail -f wp-content/uploads/bytemash-logs/stock_sync.log
```

### Monitor Database Performance

```sql
-- Check batch table size
SELECT COUNT(*) FROM wp_bytemash_batches;

-- Check index usage
EXPLAIN SELECT pm.post_id 
FROM wp_postmeta pm 
WHERE pm.meta_key = '_sku' 
AND pm.meta_value = 'TEST-SKU';
```

## Troubleshooting

### Issue: Slow Sync Performance

**Solution**: Increase concurrency (if server has resources)
```php
add_filter('bytemash_action_scheduler_concurrency', fn() => 10);
```

### Issue: Database Deadlocks

**Solution**: Reduce concurrency
```php
add_filter('bytemash_action_scheduler_concurrency', fn() => 3);
```

### Issue: Memory Errors

**Solution**: Reduce batch size
```php
update_option('bytemash_stock_batch_size', 50);
```

## Rollback

If issues arise:

```bash
# Deactivate plugin
wp plugin deactivate bytemash-woo-sync

# Rollback migrations
wp eval "
require_once 'wp-content/plugins/bytemash-sync/includes/class-logger.php';
require_once 'wp-content/plugins/bytemash-sync/includes/class-db-migration.php';
\$migration = new ByteMash_DB_Migration();
\$migration->rollback_migrations();
"

# Reactivate
wp plugin activate bytemash-woo-sync
```

## Performance Targets

| Products | Expected Time | Throughput |
|----------|---------------|------------|
| 10,000 | 12-24s | 400-800/s |
| 50,000 | 60-120s | 400-800/s |
| 100,000 | 120-240s | 400-800/s |

*With 5 concurrent runners on resource-limited servers*

## Support

For issues or questions:
1. Check logs: `wp-content/uploads/bytemash-logs/`
2. Review walkthrough: `walkthrough.md`
3. Contact ByteMash support
