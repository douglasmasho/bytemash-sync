<?php
/**
 * Database Migration Class
 * 
 * Handles database schema migrations for ultra-high-performance stock sync
 * - Creates wp_bytemash_batches table for batch storage
 * - Adds performance indexes to wp_postmeta
 * - Adds unique key for ON DUPLICATE KEY UPDATE support
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_DB_Migration {
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Database version option key
     */
    const DB_VERSION_KEY = 'bytemash_db_version';
    
    /**
     * Current database version
     */
    const CURRENT_DB_VERSION = '1.2.0';
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new ByteMash_Logger();
    }
    
    /**
     * Run all pending migrations
     * 
     * @return array Result with success status and messages
     */
    public function run_migrations() {
        global $wpdb;
        
        $current_version = get_option(self::DB_VERSION_KEY, '0.0.0');
        $results = array(
            'success' => true,
            'messages' => array(),
            'errors' => array(),
        );
        
        $this->logger->log('info', 'Starting database migrations', array(
            'current_version' => $current_version,
            'target_version' => self::CURRENT_DB_VERSION,
        ), 'db_migration');
        
        // Migration 1.1.0: Create batch storage table
        if (version_compare($current_version, '1.1.0', '<')) {
            $result = $this->migrate_1_1_0();
            if ($result['success']) {
                $results['messages'][] = 'Migration 1.1.0: Created batch storage table';
            } else {
                $results['success'] = false;
                $results['errors'][] = 'Migration 1.1.0 failed: ' . $result['error'];
            }
        }
        
        // Migration 1.2.0: Add performance indexes
        if (version_compare($current_version, '1.2.0', '<')) {
            $result = $this->migrate_1_2_0();
            if ($result['success']) {
                $results['messages'][] = 'Migration 1.2.0: Added performance indexes';
            } else {
                $results['success'] = false;
                $results['errors'][] = 'Migration 1.2.0 failed: ' . $result['error'];
            }
        }
        
        // Update database version if all migrations succeeded
        if ($results['success']) {
            update_option(self::DB_VERSION_KEY, self::CURRENT_DB_VERSION, false);
            $this->logger->log('success', 'Database migrations completed successfully', array(
                'version' => self::CURRENT_DB_VERSION,
                'migrations_run' => count($results['messages']),
            ), 'db_migration');
        } else {
            $this->logger->log('error', 'Database migrations failed', array(
                'errors' => $results['errors'],
            ), 'db_migration');
        }
        
        return $results;
    }
    
    /**
     * Migration 1.1.0: Create batch storage table
     * Replaces WordPress transients for batch storage
     * 
     * @return array Result with success status
     */
    private function migrate_1_1_0() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'bytemash_batches';
        $charset_collate = $wpdb->get_charset_collate();
        
        // Check if table already exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if ($table_exists) {
            return array(
                'success' => true,
                'message' => 'Table already exists',
            );
        }
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            sync_id VARCHAR(100) NOT NULL,
            batch_index INT NOT NULL,
            payload LONGTEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (sync_id, batch_index),
            KEY sync_id_idx (sync_id)
        ) ENGINE=InnoDB $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Verify table was created
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if ($table_exists) {
            $this->logger->log('success', 'Created batch storage table', array(
                'table_name' => $table_name,
            ), 'db_migration');
            
            return array('success' => true);
        } else {
            return array(
                'success' => false,
                'error' => 'Failed to create batch storage table',
            );
        }
    }
    
    /**
     * Migration 1.2.0: Add performance indexes to wp_postmeta
     * Adds composite indexes for SKU lookups and metadata queries
     * Adds unique key for ON DUPLICATE KEY UPDATE support
     * 
     * @return array Result with success status
     */
    private function migrate_1_2_0() {
        global $wpdb;
        
        $errors = array();
        $success_count = 0;
        
        // Index 1: Composite index for meta_key + meta_value lookups (SKU, hash, modified date)
        $this->add_postmeta_index_safely('meta_key_value', $errors, $success_count);

        // Index 2: Specific index for bytemash modified date queries
        $this->add_postmeta_index_safely('bytemash_modified', $errors, $success_count);
        
        // NOTE: This migration used to also add a UNIQUE KEY on
        // (post_id, meta_key) to wp_postmeta "for ON DUPLICATE KEY UPDATE
        // support". That has been removed entirely:
        //   1. It's unsafe - WordPress core and other plugins routinely
        //      store multiple meta rows with the same key for the same
        //      post (galleries, repeaters, etc.), so enforcing uniqueness
        //      on a real site's postmeta table will hit "duplicate" data
        //      that isn't actually a bug and shouldn't be deleted.
        //   2. Nothing in this codebase actually uses
        //      "INSERT ... ON DUPLICATE KEY UPDATE" anymore - meta bulk
        //      updates use DELETE+INSERT instead - so the key was dead
        //      weight.
        //   3. On a real WooCommerce store, wp_postmeta can have millions
        //      of rows; the duplicate-scan self-join plus the ALTER TABLE
        //      ADD UNIQUE KEY here could run for a very long time and,
        //      depending on the MySQL/MariaDB version's ALTER algorithm,
        //      lock the whole table (which every WooCommerce page reads
        //      from) for the duration - this is what made first activation
        //      on a real store hang the entire site.

        if (empty($errors)) {
            return array('success' => true);
        } else {
            return array(
                'success' => false,
                'error' => implode('; ', $errors),
            );
        }
    }
    
    /**
     * Add a (meta_key(191), meta_value(191)) index to wp_postmeta, preferring
     * a non-locking ALTER on InnoDB (MySQL 5.6+ / MariaDB 10.0+) so building
     * the index on a large, real-world postmeta table doesn't block reads
     * and writes to it (which every WooCommerce page depends on) for the
     * duration. Falls back to a plain ALTER for older servers that don't
     * understand the ALGORITHM/LOCK clauses.
     *
     * @param string $index_name
     * @param array  $errors        Passed by reference, appended on failure
     * @param int    $success_count Passed by reference, incremented on success
     */
    private function add_postmeta_index_safely($index_name, array &$errors, &$success_count) {
        global $wpdb;

        if ($this->index_exists($wpdb->postmeta, $index_name)) {
            $success_count++;
            return;
        }

        $columns = '(meta_key(191), meta_value(191))';
        $result = $wpdb->query("ALTER TABLE {$wpdb->postmeta} ADD INDEX $index_name $columns, ALGORITHM=INPLACE, LOCK=NONE");

        if ($result === false) {
            // Older MySQL/MariaDB (or a storage engine other than InnoDB)
            // may not support the ALGORITHM/LOCK clauses - retry plainly.
            $result = $wpdb->query("ALTER TABLE {$wpdb->postmeta} ADD INDEX $index_name $columns");
        }

        if ($result === false) {
            $errors[] = "Failed to create index: $index_name";
            $this->logger->log('error', 'Failed to create index', array(
                'index' => $index_name,
                'error' => $wpdb->last_error,
            ), 'db_migration');
        } else {
            $success_count++;
            $this->logger->log('success', 'Created performance index', array(
                'index' => $index_name,
            ), 'db_migration');
        }
    }

    /**
     * Check if an index exists on a table
     *
     * @param string $table_name Table name
     * @param string $index_name Index name
     * @return bool True if index exists
     */
    private function index_exists($table_name, $index_name) {
        global $wpdb;
        
        $result = $wpdb->get_results("SHOW INDEX FROM $table_name WHERE Key_name = '$index_name'");
        return !empty($result);
    }
    
    /**
     * Rollback all migrations (for testing/debugging)
     * WARNING: This will drop the batch table and remove indexes
     * 
     * @return array Result with success status
     */
    public function rollback_migrations() {
        global $wpdb;
        
        $results = array(
            'success' => true,
            'messages' => array(),
            'errors' => array(),
        );
        
        $this->logger->log('warning', 'Rolling back database migrations', array(), 'db_migration');
        
        // Drop batch storage table
        $table_name = $wpdb->prefix . 'bytemash_batches';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        $results['messages'][] = 'Dropped batch storage table';
        
        // Remove indexes (ignore errors if they don't exist)
        $wpdb->query("ALTER TABLE {$wpdb->postmeta} DROP INDEX meta_key_value");
        $wpdb->query("ALTER TABLE {$wpdb->postmeta} DROP INDEX bytemash_modified");
        $wpdb->query("ALTER TABLE {$wpdb->postmeta} DROP INDEX unique_postmeta");
        $results['messages'][] = 'Removed performance indexes';
        
        // Reset database version
        delete_option(self::DB_VERSION_KEY);
        
        $this->logger->log('success', 'Database migrations rolled back', array(), 'db_migration');
        
        return $results;
    }
    
    /**
     * Get current database version
     * 
     * @return string Current version
     */
    public function get_current_version() {
        return get_option(self::DB_VERSION_KEY, '0.0.0');
    }
    
    /**
     * Check if migrations are needed
     * 
     * @return bool True if migrations are needed
     */
    public function needs_migration() {
        $current_version = $this->get_current_version();
        return version_compare($current_version, self::CURRENT_DB_VERSION, '<');
    }
}
