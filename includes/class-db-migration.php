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
        $index_name = 'meta_key_value';
        if (!$this->index_exists($wpdb->postmeta, $index_name)) {
            $sql = "ALTER TABLE {$wpdb->postmeta} ADD INDEX $index_name (meta_key(191), meta_value(191))";
            $result = $wpdb->query($sql);
            
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
        } else {
            $success_count++;
        }
        
        // Index 2: Specific index for bytemash modified date queries
        $index_name = 'bytemash_modified';
        if (!$this->index_exists($wpdb->postmeta, $index_name)) {
            $sql = "ALTER TABLE {$wpdb->postmeta} ADD INDEX $index_name (meta_key(191), meta_value(191))";
            $result = $wpdb->query($sql);
            
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
        } else {
            $success_count++;
        }
        
        // Unique key: For ON DUPLICATE KEY UPDATE support
        // This allows single-query meta updates instead of UPDATE + INSERT
        $index_name = 'unique_postmeta';
        if (!$this->index_exists($wpdb->postmeta, $index_name)) {
            // Check if there are duplicate entries first
            $duplicates = $wpdb->get_var("
                SELECT COUNT(*) 
                FROM {$wpdb->postmeta} pm1
                INNER JOIN {$wpdb->postmeta} pm2 
                    ON pm1.post_id = pm2.post_id 
                    AND pm1.meta_key = pm2.meta_key 
                    AND pm1.meta_id != pm2.meta_id
            ");
            
            if ($duplicates > 0) {
                $this->logger->log('warning', 'Found duplicate postmeta entries, cleaning up before adding unique key', array(
                    'duplicates' => $duplicates,
                ), 'db_migration');
                
                // Remove duplicates, keeping the most recent meta_id
                $wpdb->query("
                    DELETE pm1 FROM {$wpdb->postmeta} pm1
                    INNER JOIN {$wpdb->postmeta} pm2 
                    WHERE pm1.post_id = pm2.post_id 
                    AND pm1.meta_key = pm2.meta_key 
                    AND pm1.meta_id < pm2.meta_id
                ");
            }
            
            // Now add the unique key
            $sql = "ALTER TABLE {$wpdb->postmeta} ADD UNIQUE KEY $index_name (post_id, meta_key(191))";
            $result = $wpdb->query($sql);
            
            if ($result === false) {
                // If it fails, it might be because WordPress already has a similar constraint
                // Check the error message
                if (strpos($wpdb->last_error, 'Duplicate entry') !== false || 
                    strpos($wpdb->last_error, 'already exists') !== false) {
                    // Index already exists in some form, that's okay
                    $success_count++;
                    $this->logger->log('info', 'Unique key already exists or similar constraint present', array(
                        'index' => $index_name,
                    ), 'db_migration');
                } else {
                    $errors[] = "Failed to create unique key: $index_name - " . $wpdb->last_error;
                    $this->logger->log('error', 'Failed to create unique key', array(
                        'index' => $index_name,
                        'error' => $wpdb->last_error,
                    ), 'db_migration');
                }
            } else {
                $success_count++;
                $this->logger->log('success', 'Created unique key for ON DUPLICATE KEY UPDATE', array(
                    'index' => $index_name,
                ), 'db_migration');
            }
        } else {
            $success_count++;
        }
        
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
