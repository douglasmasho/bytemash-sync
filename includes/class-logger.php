<?php
/**
 * Logger Class
 * 
 * Handles logging for sync activities and errors
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_Logger {
    
    /**
     * Log levels
     */
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_SUCCESS = 'success';
    
    /**
     * Database table name
     */
    private $table_name;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'bytemash_sync_logs';
    }
    
    /**
     * Log a message
     */
    public function log($level, $message, $data = array(), $sync_type = 'general') {
        global $wpdb;
        
        $wpdb->insert(
            $this->table_name,
            array(
                'sync_type' => sanitize_text_field($sync_type),
                'status' => sanitize_text_field($level),
                'message' => sanitize_text_field($message),
                'data' => maybe_serialize($data),
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        
        // Also log to WordPress debug log if enabled
        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            $log_message = sprintf('[ByteMash Amrod Sync] [%s] %s: %s', strtoupper($level), $sync_type, $message);
            
            // Add data if available
            if (!empty($data)) {
                $log_message .= ' | Data: ' . json_encode($data);
            }
            
            error_log($log_message);
        }
    }
    
    /**
     * Get recent logs
     */
    public function get_logs($limit = 100, $offset = 0, $level = null) {
        global $wpdb;
        
        $query = "SELECT * FROM {$this->table_name}";
        
        if ($level) {
            $query .= $wpdb->prepare(" WHERE status = %s", $level);
        }
        
        $query .= " ORDER BY created_at DESC";
        $query .= $wpdb->prepare(" LIMIT %d OFFSET %d", $limit, $offset);
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        // Unserialize data
        foreach ($results as &$result) {
            $result['data'] = maybe_unserialize($result['data']);
        }
        
        return $results;
    }
    
    /**
     * Get logs count
     */
    public function get_logs_count($level = null) {
        global $wpdb;
        
        $query = "SELECT COUNT(*) FROM {$this->table_name}";
        
        if ($level) {
            $query .= $wpdb->prepare(" WHERE status = %s", $level);
        }
        
        return (int) $wpdb->get_var($query);
    }
    
    /**
     * Clear old logs (older than X days)
     */
    public function clear_old_logs($days = 30) {
        global $wpdb;
        
        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} WHERE created_at < %s",
                $date
            )
        );
        
        $this->log('info', "Cleared {$deleted} old log entries", array('days' => $days));
        
        return $deleted;
    }
    
    /**
     * Clear all logs
     */
    public function clear_all_logs() {
        global $wpdb;
        
        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        
        return true;
    }
    
    /**
     * Get sync statistics
     */
    public function get_sync_stats($days = 7) {
        global $wpdb;
        
        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $stats = array(
            'total' => 0,
            'success' => 0,
            'error' => 0,
            'warning' => 0,
            'info' => 0,
        );
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) as count FROM {$this->table_name} WHERE created_at >= %s GROUP BY status",
                $date
            ),
            ARRAY_A
        );
        
        foreach ($results as $result) {
            $stats[$result['status']] = (int) $result['count'];
            $stats['total'] += (int) $result['count'];
        }
        
        return $stats;
    }
    
    /**
     * Get last sync time
     */
    public function get_last_sync_time($sync_type = null) {
        global $wpdb;
        
        $query = "SELECT created_at FROM {$this->table_name}";
        
        if ($sync_type) {
            $query .= $wpdb->prepare(" WHERE sync_type = %s", $sync_type);
        }
        
        $query .= " ORDER BY created_at DESC LIMIT 1";
        
        return $wpdb->get_var($query);
    }
}

