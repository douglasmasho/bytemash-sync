<?php
/**
 * Plugin Packaging Script
 * 
 * This script creates a clean zip package of the ByteMash WooCommerce Amrod Sync plugin
 * excluding development files, documentation, and test files.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    // If running from command line, define ABSPATH
    if (php_sapi_name() === 'cli') {
        define('ABSPATH', dirname(__FILE__) . '/../../../');
    } else {
        die('Direct access not allowed');
    }
}

class PluginPackager {
    
    private $plugin_dir;
    public $plugin_name = 'bytemash-woo-sync';
    public $version;
    private $exclude_patterns = array(
        // Development files
        '*.md',
        '*.txt',
        '*.log',
        '*.tmp',
        '*.bak',
        '*.swp',
        '*.swo',
        '*~',
        
        // Test files
        'test-*.php',
        'debug-*.php',
        'diagnose-*.php',
        'check-*.php',
        'cleanup-*.php',
        'clear-*.php',
        'run-*.php',
        'update-*.php',
        
        // Development directories
        'documentation/',
        'logs/',
        'temp/',
        'tmp/',
        '.git/',
        '.gitignore',
        '.gitattributes',
        '.vscode/',
        '.idea/',
        
        // Package files
        'package-plugin.php',
        'composer.json',
        'composer.lock',
        'vendor/',
        
        // Response files (API test data)
        'responses/',
        
        // Node modules if any
        'node_modules/',
        'package.json',
        'package-lock.json',
        'yarn.lock',
        
        // Build files
        'webpack.config.js',
        'gulpfile.js',
        'Gruntfile.js',
        
        // IDE files
        '*.sublime-*',
        '.DS_Store',
        'Thumbs.db'
    );
    
    public function __construct() {
        $this->plugin_dir = dirname(__FILE__);
        $this->version = $this->get_plugin_version();
    }
    
    /**
     * Get plugin version from main plugin file
     */
    private function get_plugin_version() {
        $plugin_file = $this->plugin_dir . '/bytemash-woo-sync.php';
        if (file_exists($plugin_file)) {
            $content = file_get_contents($plugin_file);
            if (preg_match('/Version:\s*([0-9.]+)/', $content, $matches)) {
                return $matches[1];
            }
        }
        return '1.0.0';
    }
    
    /**
     * Check if file should be excluded
     */
    private function should_exclude($file_path) {
        $relative_path = str_replace($this->plugin_dir . '/', '', $file_path);
        
        foreach ($this->exclude_patterns as $pattern) {
            if (fnmatch($pattern, $relative_path) || fnmatch($pattern, basename($file_path))) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get all files to include in package
     */
    private function get_files_to_package() {
        $files = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->plugin_dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && !$this->should_exclude($file->getPathname())) {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }
    
    /**
     * Create the plugin package
     */
    public function create_package() {
        echo "Creating plugin package...\n";
        echo "Plugin: {$this->plugin_name}\n";
        echo "Version: {$this->version}\n";
        echo "Source: {$this->plugin_dir}\n\n";
        
        // Get files to package
        $files = $this->get_files_to_package();
        echo "Found " . count($files) . " files to package\n\n";
        
        // Create zip file
        $zip_filename = "{$this->plugin_name}-v{$this->version}.zip";
        $zip_path = dirname($this->plugin_dir) . '/' . $zip_filename;
        
        if (file_exists($zip_path)) {
            unlink($zip_path);
            echo "Removed existing package: {$zip_filename}\n";
        }
        
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
            die("Cannot create zip file: {$zip_path}\n");
        }
        
        $added_count = 0;
        foreach ($files as $file) {
            $relative_path = str_replace($this->plugin_dir . '/', '', $file);
            $zip->addFile($file, $relative_path);
            $added_count++;
            
            if ($added_count % 50 === 0) {
                echo "Added {$added_count} files...\n";
            }
        }
        
        $zip->close();
        
        echo "\nPackage created successfully!\n";
        echo "File: {$zip_path}\n";
        echo "Size: " . $this->format_bytes(filesize($zip_path)) . "\n";
        echo "Files: {$added_count}\n";
        
        return $zip_path;
    }
    
    /**
     * Format bytes to human readable format
     */
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * Show package contents
     */
    public function show_package_contents() {
        echo "Files to be packaged:\n";
        echo str_repeat("-", 50) . "\n";
        
        $files = $this->get_files_to_package();
        sort($files);
        
        foreach ($files as $file) {
            $relative_path = str_replace($this->plugin_dir . '/', '', $file);
            echo $relative_path . "\n";
        }
        
        echo str_repeat("-", 50) . "\n";
        echo "Total: " . count($files) . " files\n\n";
    }
    
    /**
     * Show excluded files
     */
    public function show_excluded_files() {
        echo "Excluded files and patterns:\n";
        echo str_repeat("-", 50) . "\n";
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->plugin_dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        $excluded = array();
        foreach ($iterator as $file) {
            if ($file->isFile() && $this->should_exclude($file->getPathname())) {
                $relative_path = str_replace($this->plugin_dir . '/', '', $file->getPathname());
                $excluded[] = $relative_path;
            }
        }
        
        sort($excluded);
        foreach ($excluded as $file) {
            echo $file . "\n";
        }
        
        echo str_repeat("-", 50) . "\n";
        echo "Total excluded: " . count($excluded) . " files\n\n";
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    // Command line usage
    $packager = new PluginPackager();
    
    $command = isset($argv[1]) ? $argv[1] : 'package';
    
    switch ($command) {
        case 'list':
            $packager->show_package_contents();
            break;
            
        case 'excluded':
            $packager->show_excluded_files();
            break;
            
        case 'package':
        default:
            $packager->create_package();
            break;
    }
    
    echo "\nUsage:\n";
    echo "php package-plugin.php [command]\n\n";
    echo "Commands:\n";
    echo "  package  - Create the plugin zip package (default)\n";
    echo "  list     - Show files that will be packaged\n";
    echo "  excluded - Show files that will be excluded\n\n";
    
} else {
    // Web interface
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to run this script.');
    }
    
    $packager = new PluginPackager();
    $action = isset($_GET['action']) ? $_GET['action'] : 'package';
    
    echo "<h1>Plugin Packager</h1>";
    echo "<p>Plugin: {$packager->plugin_name} v{$packager->version}</p>";
    
    switch ($action) {
        case 'list':
            echo "<h2>Files to be packaged:</h2>";
            $packager->show_package_contents();
            break;
            
        case 'excluded':
            echo "<h2>Excluded files:</h2>";
            $packager->show_excluded_files();
            break;
            
        case 'package':
        default:
            echo "<h2>Creating package...</h2>";
            $zip_path = $packager->create_package();
            $zip_filename = basename($zip_path);
            echo "<p><strong>Package created:</strong> <a href='../{$zip_filename}' download>{$zip_filename}</a></p>";
            break;
    }
    
    echo "<hr>";
    echo "<p><a href='?action=list'>Show files to package</a> | ";
    echo "<a href='?action=excluded'>Show excluded files</a> | ";
    echo "<a href='?action=package'>Create package</a></p>";
}
?>
