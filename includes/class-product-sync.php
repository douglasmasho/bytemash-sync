<?php
/**
 * Product Sync Class
 * 
 * Handles synchronization of products from Amrod to WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_Product_Sync {
    
    /**
     * API Client
     */
    private $api_client;
    
    /**
     * Logger
     */
    private $logger;
    
    /**
     * Image Handler
     */
    private $image_handler;
    
    /**
     * Batch size for processing
     */
    private $batch_size;
    
    /**
     * SKU to product ID cache for batch processing
     */
    private $sku_cache = array();
    
    /**
     * Category cache for batch processing
     */
    private $category_cache = array();
    
    /**
     * Flag to indicate batch processing mode
     */
    private $batch_mode = false;
    
    /**
     * Fields used when generating product payload signatures
     * to detect unchanged products between syncs
     */
    private $signature_fields = array(
        'productName',
        'shortDescription',
        'description',
        'categories',
        'brand',
        'brandings',
        'images',
        'colourImages',
        'material',
        'gender',
        'fit',
        'feature',
        'minimum',
        'maximum',
        'incrementedBy',
        'companionCodes',
        'relatedCodes',
        'behaviour',
        'inventoryType',
        'variants',
        'fullBrandingGuide',
        'logo24BrandingGuide',
        'decoupled',
        'simpleCode',
        'fullCode',
    );

    /**
     * Variant fields considered when generating product payload signatures.
     * Excludes volatile inventory/pricing data that is handled by other sync jobs.
     */
    private $variant_signature_fields = array(
        'fullCode',
        'codeColour',
        'codeColourName',
        'codeColourFamily',
        'codeSize',
        'codeSizeName',
        'codeSizeAbbreviation',
        'categorisedAttribute',
        'productDimension',
        'packagingAndDimension',
    );

    /**
     * Fields that primarily affect product meta storage.
     */
    private $meta_payload_fields = array(
        'brand',
        'brandings',
        'colourImages',
        'material',
        'gender',
        'fit',
        'feature',
        'minimum',
        'maximum',
        'incrementedBy',
        'companionCodes',
        'relatedCodes',
        'behaviour',
        'inventoryType',
        'fullBrandingGuide',
        'logo24BrandingGuide',
        'decoupled',
        'simpleCode',
        'fullCode',
    );

    /**
     * Normalize an image URL for signature comparisons (strip query/fragment, lowercase host).
     *
     * @param string $url
     * @return string
     */
    private function normalize_image_url_for_signature($url) {
        if (empty($url) || !is_string($url)) {
            return '';
        }

        $parsed = wp_parse_url($url);
        if (!$parsed) {
            $parts = explode('?', $url, 2);
            return trim($parts[0]);
        }

        $normalized = '';

        if (!empty($parsed['scheme'])) {
            $normalized .= strtolower($parsed['scheme']) . '://';
        }

        if (!empty($parsed['host'])) {
            $normalized .= strtolower($parsed['host']);
        }

        if (!empty($parsed['path'])) {
            $normalized .= $parsed['path'];
        }

        return $normalized ?: (isset($parsed['path']) ? $parsed['path'] : '');
    }

    /**
     * Log entry helper for the real-time dashboard stream.
     *
     * @param string $status
     * @param string $message
     * @param array $data
     */
    private function log_realtime_activity($status, $message, $data = array()) {
        $payload = array_merge(array(
            'timestamp' => current_time('mysql'),
        ), $data);

        $this->logger->log($status, $message, $payload, 'realtime_activity');
    }

    /**
     * Determine why a product is being processed (vs skipped).
     */
    private function determine_processing_reason($product_id, $force, $existing_signature, $payload_signature, $had_existing_signature = false, $origin_product_id = null) {
        if (!$product_id) {
            // If this product previously existed (signature stored or we started with an ID),
            // treat it as an update even if the ID was temporarily cleared during conversion.
            if ($had_existing_signature || !empty($origin_product_id)) {
                return 'signature_changed';
            }
            return 'new_product';
        }

        if ($force) {
            return 'force_requested';
        }

        if (empty($existing_signature) || empty($payload_signature)) {
            return 'missing_signature';
        }

        return 'signature_changed';
    }

    /**
     * Convert machine-friendly reason keys to human readable text.
     */
    private function format_activity_reason_label($reason_key) {
        $map = array(
            'new_product' => __('new product', 'bytemash-woo-sync'),
            'force_requested' => __('force update requested', 'bytemash-woo-sync'),
            'missing_signature' => __('signature not stored yet', 'bytemash-woo-sync'),
            'signature_changed' => __('payload changed', 'bytemash-woo-sync'),
            'payload_unchanged' => __('payload unchanged', 'bytemash-woo-sync'),
        );

        $reason_key = strtolower($reason_key);
        if (isset($map[$reason_key])) {
            return $map[$reason_key];
        }

        return strtolower(str_replace('_', ' ', $reason_key));
    }

    /**
     * Retrieve the previously stored payload snapshot for a product.
     *
     * @param int $product_id
     * @return array
     */
    private function get_payload_snapshot($product_id) {
        if (!$product_id) {
            return array();
        }

        $snapshot = get_post_meta($product_id, '_amrod_payload_snapshot', true);
        return is_array($snapshot) ? $snapshot : array();
    }

    /**
     * Persist the current payload snapshot for later diffing.
     *
     * @param int $product_id
     * @param array $payload_snapshot
     */
    private function save_payload_snapshot($product_id, $payload_snapshot) {
        if (!$product_id || empty($payload_snapshot) || !is_array($payload_snapshot)) {
            return;
        }

        update_post_meta($product_id, '_amrod_payload_snapshot', $payload_snapshot, false);
    }

    /**
     * Build a list of changed fields between two payload snapshots.
     *
     * @param array $previous
     * @param array $current
     * @return array
     */
    private function diff_payload_snapshots($previous, $current) {
        $changes = array();
        $fields = array_unique(array_merge(array_keys((array) $previous), array_keys((array) $current)));

        foreach ($fields as $field) {
            $old_value = $previous[$field] ?? null;
            $new_value = $current[$field] ?? null;

            if ($this->payload_values_equal($old_value, $new_value)) {
                continue;
            }

            $changes[] = array(
                'field' => $field,
                'previous' => $this->stringify_payload_value($old_value),
                'current' => $this->stringify_payload_value($new_value),
            );
        }

        return $changes;
    }

    /**
     * Check if two payload values are equivalent.
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    private function payload_values_equal($a, $b) {
        return wp_json_encode($a) === wp_json_encode($b);
    }

    /**
     * Convert payload values to a short string for logging.
     *
     * @param mixed $value
     * @param int $max_length
     * @return string
     */
    private function stringify_payload_value($value, $max_length = 140) {
        if (is_bool($value)) {
            $string = $value ? 'true' : 'false';
        } elseif (is_null($value) || $value === '') {
            $string = __('(empty)', 'bytemash-woo-sync');
        } elseif (is_array($value) || is_object($value)) {
            $string = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $string = (string) $value;
        }

        $string = trim(wp_strip_all_tags($string));
        if ($string === '') {
            $string = __('(empty)', 'bytemash-woo-sync');
        }

        if (strlen($string) > $max_length) {
            $string = substr($string, 0, $max_length - 3) . '...';
        }

        return $string;
    }

    /**
     * Format a succinct summary string for changed payload fields.
     *
     * @param array $changes
     * @param int $limit
     * @return string
     */
    private function format_payload_change_summary($changes, $limit = 3) {
        if (empty($changes)) {
            return '';
        }

        $snippets = array();
        $display_changes = array_slice($changes, 0, $limit);
        foreach ($display_changes as $change) {
            $snippets[] = sprintf(
                '%s: "%s" -> "%s"',
                $change['field'],
                $change['previous'],
                $change['current']
            );
        }

        if (count($changes) > $limit) {
            $snippets[] = sprintf(
                __('+%d more', 'bytemash-woo-sync'),
                count($changes) - $limit
            );
        }

        return implode('; ', $snippets);
    }

    /**
     * Check if specific payload fields changed.
     *
     * @param array $changes
     * @param array $fields
     * @return bool
     */
    private function payload_fields_changed($changes, $fields = array()) {
        if (empty($changes)) {
            return false;
        }

        if (empty($fields)) {
            return true;
        }

        foreach ($changes as $change) {
            if (in_array($change['field'], $fields, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide if a sync section should run based on diff data.
     *
     * @param bool $run_all
     * @param array $changes
     * @param array $fields
     * @return bool
     */
    private function should_run_section($run_all, $changes, $fields = array()) {
        if ($run_all) {
            return true;
        }

        if (empty($fields)) {
            return !empty($changes);
        }

        return $this->payload_fields_changed($changes, $fields);
    }

    /**
     * Determine if the API payload truly contains usable variants.
     *
     * @param array $product_data
     * @return bool
     */
    private function product_has_valid_variants($product_data) {
        if (empty($product_data['variants']) || !is_array($product_data['variants'])) {
            return false;
        }

        $valid_signatures = array();

        foreach ($product_data['variants'] as $index => $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $has_code = !empty($variant['fullCode']) || !empty($variant['simpleCode']);
            if (!$has_code) {
                continue;
            }

            $has_identifiers = !empty($variant['codeColourName']) || !empty($variant['codeSizeName']) || !empty($variant['codeColour']) || !empty($variant['codeSize']);
            $has_stock = array_key_exists('stock', $variant) && $variant['stock'] !== null;
            $has_price = array_key_exists('price', $variant) && $variant['price'] !== null;
            $has_attributes = !empty($variant['categorisedAttribute']) && is_array($variant['categorisedAttribute']);

            if ($has_identifiers || $has_stock || $has_price || $has_attributes) {
                $signature = strtolower(trim(sprintf(
                    '%s|%s|%s|%s|%s',
                    $variant['fullCode'] ?? '',
                    $variant['simpleCode'] ?? '',
                    $variant['codeColour'] ?? $variant['codeColourName'] ?? '',
                    $variant['codeSize'] ?? $variant['codeSizeName'] ?? '',
                    (string) ($variant['stock'] ?? '')
                )));

                if ($signature === '') {
                    $signature = 'variant_' . $index;
                }

                $valid_signatures[$signature] = true;
            }
        }

        return count($valid_signatures) > 1;
    }
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->api_client = new ByteMash_Amrod_API_Client();
        $this->logger = new ByteMash_Logger();
        $this->image_handler = new ByteMash_Image_Handler();
        $this->batch_size = (int) get_option('bytemash_amrod_batch_size', 50);
    }
    
    /**
     * Build a set of SKU lookup candidates that covers common formatting differences.
     *
     * @param string $sku
     * @return array
     */
    private function build_sku_lookup_candidates($sku) {
        if (!is_string($sku)) {
            return array();
        }
        
        $candidates = array();
        $raw = trim($sku);
        if ($raw === '') {
            return $candidates;
        }
        
        $candidates[] = $raw;
        
        $sanitized = sanitize_text_field($raw);
        if ($sanitized !== '' && $sanitized !== $raw) {
            $candidates[] = $sanitized;
        }
        
        $without_suffix = preg_replace('/-0-0$/', '', $sanitized);
        if ($without_suffix !== '' && $without_suffix !== $sanitized) {
            $candidates[] = $without_suffix;
        }
        
        $upper = strtoupper($sanitized);
        $lower = strtolower($sanitized);
        if ($upper !== '' && $upper !== $sanitized) {
            $candidates[] = $upper;
        }
        if ($lower !== '' && $lower !== $sanitized) {
            $candidates[] = $lower;
        }
        
        return array_values(array_unique(array_filter($candidates)));
    }
    
    /**
     * Enable batch processing mode and preload SKU cache for a batch of products
     * This significantly reduces database queries by doing one batch lookup instead of N individual queries
     * 
     * @param array $products Array of product data from API
     */
    public function start_batch_mode($products) {
        $this->batch_mode = true;
        $this->sku_cache = array();
        $this->category_cache = array();
        
        // Extract all SKUs (with aggressive variants) from the batch
        $lookup_codes = array();
        foreach ($products as $product_data) {
            $sku = $product_data['simpleCode'] ?? $product_data['fullCode'] ?? null;
            if ($sku) {
                $candidates = $this->build_sku_lookup_candidates($sku);
                if (!empty($candidates)) {
                    $lookup_codes = array_merge($lookup_codes, $candidates);
                } else {
                    $lookup_codes[] = sanitize_text_field($sku);
                }
            }
        }
        
        $lookup_codes = array_values(array_unique(array_filter($lookup_codes)));
        
        if (empty($lookup_codes)) {
            return;
        }
        
        
        // Batch lookup all SKUs in one query
        global $wpdb;
        
        // OPTIMIZATION: Use direct escaped query instead of wpdb->prepare with call_user_func_array
        // The prepare approach is EXTREMELY slow for large arrays (processes each placeholder individually)
        // This direct approach is 10-100x faster for batch queries
        $escaped_codes = array_map(function($code) use ($wpdb) {
            return "'" . esc_sql($code) . "'";
        }, $lookup_codes);
        
        $conditions = array();
        if (!empty($escaped_codes)) {
            $conditions[] = "pm.meta_value IN (" . implode(',', $escaped_codes) . ")";
        }
        
        $normalized_codes = array_values(array_unique(array_filter(array_map(function($code) {
            return strtolower(trim($code));
        }, $lookup_codes))));
        
        $escaped_normalized_codes = array_map(function($code) use ($wpdb) {
            return "'" . esc_sql($code) . "'";
        }, $normalized_codes);
        
        if (!empty($escaped_normalized_codes)) {
            $conditions[] = "LOWER(TRIM(pm.meta_value)) IN (" . implode(',', $escaped_normalized_codes) . ")";
        }
        
        if (empty($conditions)) {
            return;
        }
        
        // Query for matches across all Amrod identifiers so we can recover products even if SKUs were edited
        $meta_keys = "'_sku','_amrod_simple_code','_amrod_full_code'";
        $query = "SELECT pm.post_id, pm.meta_key, pm.meta_value as code, p.post_type
                  FROM {$wpdb->postmeta} pm
                  INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                  WHERE pm.meta_key IN ($meta_keys)
                  AND (" . implode(' OR ', $conditions) . ")";
        
        $results = $wpdb->get_results($query);
        
        // Build SKU cache with case-insensitive indexing
        // Store both exact match and normalized (lowercase) keys for aggressive matching
        foreach ($results as $row) {
            $cache_entry = array(
                'product_id' => (int) $row->post_id,
                'post_type' => $row->post_type,
            );
            
            $code_variants = $this->build_sku_lookup_candidates($row->code);
            if (empty($code_variants)) {
                $code_variants = array($row->code);
            }
            
            foreach ($code_variants as $code_variant) {
                $normalized_code = strtolower(trim($code_variant));
                
                if ($code_variant !== '') {
                    $this->sku_cache[$code_variant] = $cache_entry;
                }
                
                if ($normalized_code !== '') {
                    $this->sku_cache[$normalized_code] = $cache_entry;
                }
            }
        }
    }
    
    /**
     * Disable batch processing mode and clear caches
     */
    public function end_batch_mode() {
        $this->batch_mode = false;
        $this->sku_cache = array();
        $this->category_cache = array();
    }
    
    /**
     * Get product ID by SKU using cache in batch mode
     * Falls back to aggressive case-insensitive lookup if not in batch mode
     * 
     * @param string $sku Product SKU
     * @return int|null Product ID or null if not found
     */
    private function get_product_id_by_sku_cached($sku, $log_options = array()) {
        $normalized_sku = strtolower(trim($sku));
        $log_activity = !empty($log_options['log_activity']);
        $activity_context = isset($log_options['activity_context']) && is_array($log_options['activity_context'])
            ? $log_options['activity_context']
            : array();
        
        $log_match = function($strategy, $product_id) use ($log_activity, $activity_context, $sku) {
            if (!$log_activity || !$product_id) {
                return;
            }
            
            $payload = array_merge(array(
                'sku' => $sku,
                'product_id' => $product_id,
                'action' => 'sku_lookup',
                'lookup_strategy' => $strategy,
            ), $activity_context);
            
            $message = sprintf(
                'Matched existing product %s via %s lookup',
                $sku,
                $strategy
            );
            
            $this->log_realtime_activity('info', $message, $payload);
        };
        
        // Try exact match first (batch mode)
        if ($this->batch_mode) {
            if (isset($this->sku_cache[$sku])) {
                $product_id = $this->sku_cache[$sku]['product_id'];
                $log_match('batch_cache_exact', $product_id);
                return $product_id;
            }
            // Try case-insensitive match
            if (isset($this->sku_cache[$normalized_sku])) {
                $product_id = $this->sku_cache[$normalized_sku]['product_id'];
                $log_match('batch_cache_normalized', $product_id);
                return $product_id;
            }
        }
        
        // Aggressive fallback: try multiple lookup strategies
        // First try standard WooCommerce lookup
        $product_id = wc_get_product_id_by_sku($sku);
        if ($product_id) {
            $log_match('wc_lookup_exact', $product_id);
            return $product_id;
        }
        
        if ($normalized_sku !== '' && $normalized_sku !== $sku) {
            $product_id = wc_get_product_id_by_sku($normalized_sku);
            if ($product_id) {
                $log_match('wc_lookup_normalized', $product_id);
                return $product_id;
            }
        }
        
        // Try direct SQL lookup across all known Amrod identifiers
        $candidate_codes = $this->build_sku_lookup_candidates($sku);
        if (empty($candidate_codes)) {
            return null;
        }
        
        global $wpdb;
        
        $escaped_codes = array_map(function($code) use ($wpdb) {
            return "'" . esc_sql($code) . "'";
        }, $candidate_codes);
        
        $conditions = array();
        if (!empty($escaped_codes)) {
            $conditions[] = "pm.meta_value IN (" . implode(',', $escaped_codes) . ")";
        }
        
        $normalized_candidates = array_values(array_unique(array_filter(array_map(function($code) {
            return strtolower(trim($code));
        }, $candidate_codes))));
        
        $escaped_normalized_codes = array_map(function($code) use ($wpdb) {
            return "'" . esc_sql($code) . "'";
        }, $normalized_candidates);
        
        if (!empty($escaped_normalized_codes)) {
            $conditions[] = "LOWER(TRIM(pm.meta_value)) IN (" . implode(',', $escaped_normalized_codes) . ")";
        }
        
        if (empty($conditions)) {
            return null;
        }
        
        $meta_keys = "'_sku','_amrod_simple_code','_amrod_full_code'";
        $query = "SELECT pm.post_id, p.post_type, pm.meta_key
                  FROM {$wpdb->postmeta} pm
                  INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                  WHERE pm.meta_key IN ($meta_keys)
                  AND (" . implode(' OR ', $conditions) . ")
                  ORDER BY FIELD(pm.meta_key, '_sku','_amrod_simple_code','_amrod_full_code')
                  LIMIT 1";
        
        $product_row = $wpdb->get_row($query);
        
        if ($product_row) {
            $product_id = (int) $product_row->post_id;
            $log_match('direct_sql_' . $product_row->meta_key, $product_id);
            return $product_id;
        }
        
        return null;
    }
    
    /**
     * Get product type by SKU using cache in batch mode
     * 
     * @param string $sku Product SKU
     * @return string|null Post type or null if not found
     */
    private function get_product_type_by_sku_cached($sku) {
        $normalized_sku = strtolower(trim($sku));
        
        if ($this->batch_mode) {
            // Try exact match first
            if (isset($this->sku_cache[$sku])) {
                return $this->sku_cache[$sku]['post_type'];
            }
            // Try case-insensitive match
            if (isset($this->sku_cache[$normalized_sku])) {
                return $this->sku_cache[$normalized_sku]['post_type'];
            }
        }
        
        // Fallback: get from product ID if available
        $product_id = $this->get_product_id_by_sku_cached($sku);
        if ($product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                return $product->get_type() === 'variation' ? 'product_variation' : 'product';
            }
        }
        
        return null;
    }

    /**
     * Build deterministic signature hash for a product payload.
     * Used to skip unchanged products between sync runs.
     *
     * @param array $product_data
     * @return string
     */
    private function generate_product_signature_hash($product_data) {
        if (empty($product_data) || !is_array($product_data)) {
            return '';
        }

        $payload = $this->build_signature_payload($product_data);

        if (empty($payload)) {
            return '';
        }

        return md5(wp_json_encode($payload));
    }

    /**
     * Normalize mixed values so hashes remain stable regardless of ordering.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalize_signature_value($value) {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalize_signature_value($item);
            }

            if ($this->is_associative_array($value)) {
                ksort($value);
            } else {
                usort($value, function($a, $b) {
                    return strcmp(
                        wp_json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        wp_json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    );
                });
            }

            return $value;
        }

        if (is_string($value)) {
            $value = trim(preg_replace('/\s+/u', ' ', $value));
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_null($value)) {
            return '';
        }

        return $value;
    }

    /**
     * Helper to determine if an array is associative.
     *
     * @param array $array
     * @return bool
     */
    private function is_associative_array($array) {
        if (!is_array($array) || empty($array)) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Build the sanitized payload used for product signature hashing.
     *
     * @param array $product_data
     * @return array
     */
    private function build_signature_payload($product_data) {
        $payload = array();

        foreach ($this->signature_fields as $field) {
            if (!array_key_exists($field, $product_data)) {
                continue;
            }

            switch ($field) {
                case 'categories':
                    $payload[$field] = $this->sanitize_categories_for_signature($product_data[$field]);
                    break;
                case 'brand':
                    $payload[$field] = $this->sanitize_brand_for_signature($product_data[$field]);
                    break;
                case 'brandings':
                    $payload[$field] = $this->sanitize_brandings_for_signature($product_data[$field]);
                    break;
                case 'images':
                    $payload[$field] = $this->sanitize_image_payload($product_data[$field]);
                    break;
                case 'colourImages':
                    $payload[$field] = $this->sanitize_colour_image_payload($product_data[$field]);
                    break;
                case 'variants':
                    $payload[$field] = $this->sanitize_variants_for_signature($product_data[$field]);
                    break;
                default:
                    $payload[$field] = $this->normalize_signature_value($product_data[$field]);
                    break;
            }
        }

        // Remove empty entries to keep payload lean
        $payload = array_filter($payload, function($value) {
            return $value !== null && $value !== '' && $value !== array();
        });

        return $payload;
    }

    private function sanitize_categories_for_signature($categories) {
        $sanitized = array();

        foreach ((array) $categories as $category) {
            if (empty($category['name']) && empty($category['path']) && empty($category['categoryPath'])) {
                continue;
            }

            $sanitized[] = array(
                'name' => $category['name'] ?? '',
                'code' => $category['code'] ?? '',
                'path' => $category['path'] ?? ($category['categoryPath'] ?? ''),
            );
        }

        if (empty($sanitized)) {
            return array();
        }

        usort($sanitized, function($a, $b) {
            $aKey = strtolower(($a['path'] ?? '') . '|' . ($a['code'] ?? '') . '|' . ($a['name'] ?? ''));
            $bKey = strtolower(($b['path'] ?? '') . '|' . ($b['code'] ?? '') . '|' . ($b['name'] ?? ''));
            return strcmp($aKey, $bKey);
        });

        return $this->normalize_signature_value($sanitized);
    }

    private function sanitize_brand_for_signature($brand) {
        if (is_array($brand)) {
            $normalized = array(
                'name' => $brand['brandName'] ?? $brand['name'] ?? $brand['Brand'] ?? '',
                'code' => $brand['code'] ?? '',
            );
            return $this->normalize_signature_value($normalized);
        }

        return $this->normalize_signature_value((string) $brand);
    }

    private function sanitize_brandings_for_signature($brandings) {
        $sanitized = array();

        foreach ((array) $brandings as $branding) {
            if (empty($branding['positionCode']) && empty($branding['positionName'])) {
                continue;
            }

            $methods = array();
            if (!empty($branding['method']) && is_array($branding['method'])) {
                foreach ($branding['method'] as $method) {
                    $methods[] = array(
                        'code' => $method['code'] ?? '',
                        'name' => $method['name'] ?? '',
                    );
                }
            }

            $sanitized[] = array(
                'positionCode' => $branding['positionCode'] ?? '',
                'positionName' => $branding['positionName'] ?? '',
                'method' => $methods,
            );
        }

        if (!empty($sanitized)) {
            usort($sanitized, function($a, $b) {
                $aKey = strtolower(($a['positionCode'] ?? '') . '|' . ($a['positionName'] ?? ''));
                $bKey = strtolower(($b['positionCode'] ?? '') . '|' . ($b['positionName'] ?? ''));
                return strcmp($aKey, $bKey);
            });
        }

        return $this->normalize_signature_value($sanitized);
    }

    private function sanitize_image_payload($images) {
        $sanitized = array();

        foreach ((array) $images as $image) {
            $url = $this->get_highest_resolution_url($image['urls'] ?? array());
            $normalized_url = $this->normalize_image_url_for_signature($url);
            if (!$normalized_url) {
                continue;
            }
            $sanitized[] = array(
                'url' => $normalized_url,
                'isDefault' => !empty($image['isDefault']),
                'hasLogo' => !empty($image['hasLogo']),
            );
        }

        if (!empty($sanitized)) {
            usort($sanitized, function($a, $b) {
                return strcmp($a['url'], $b['url']);
            });
        }

        return $this->normalize_signature_value($sanitized);
    }

    private function sanitize_colour_image_payload($colour_images) {
        $sanitized = array();

        foreach ((array) $colour_images as $colour) {
            $image_urls = array();
            if (!empty($colour['images']) && is_array($colour['images'])) {
                foreach ($colour['images'] as $image_entry) {
                    $url = $image_entry['url'] ?? $this->get_highest_resolution_url($image_entry['urls'] ?? array());
                    $normalized_url = $this->normalize_image_url_for_signature($url);
                    if ($normalized_url) {
                        $image_urls[] = $normalized_url;
                    }
                }
            }

            $sanitized[] = array(
                'code' => $colour['code'] ?? '',
                'name' => $colour['name'] ?? '',
                'images' => $image_urls,
            );
        }

        if (!empty($sanitized)) {
            foreach ($sanitized as &$entry) {
                if (!empty($entry['images'])) {
                    sort($entry['images']);
                }
            }
            unset($entry);

            usort($sanitized, function($a, $b) {
                $aKey = strtolower(($a['code'] ?? '') . '|' . ($a['name'] ?? ''));
                $bKey = strtolower(($b['code'] ?? '') . '|' . ($b['name'] ?? ''));
                return strcmp($aKey, $bKey);
            });
        }

        return $this->normalize_signature_value($sanitized);
    }

    private function sanitize_variants_for_signature($variants) {
        $sanitized = array();

        foreach ((array) $variants as $variant) {
            $variant_entry = array();
            foreach ($this->variant_signature_fields as $field) {
                if (!array_key_exists($field, $variant)) {
                    continue;
                }
                $variant_entry[$field] = $this->normalize_signature_value($variant[$field]);
            }
            if (!empty($variant_entry)) {
                $sanitized[] = $variant_entry;
            }
        }

        if (!empty($sanitized)) {
            usort($sanitized, function($a, $b) {
                $aKey = strtolower(($a['fullCode'] ?? '') . '|' . ($a['codeColour'] ?? '') . '|' . ($a['codeSize'] ?? ''));
                $bKey = strtolower(($b['fullCode'] ?? '') . '|' . ($b['codeColour'] ?? '') . '|' . ($b['codeSize'] ?? ''));
                return strcmp($aKey, $bKey);
            });
        }

        return $this->normalize_signature_value($sanitized);
    }

    /**
     * Generate a signature hash for a single variant payload.
     *
     * @param array $variant_data
     * @return string
     */
    private function generate_variant_signature($variant_data) {
        if (empty($variant_data) || !is_array($variant_data)) {
            return '';
        }

        $payload = array();
        foreach ($this->variant_signature_fields as $field) {
            if (array_key_exists($field, $variant_data)) {
                $payload[$field] = $this->normalize_signature_value($variant_data[$field]);
            }
        }

        if (empty($payload)) {
            return '';
        }

        return md5(wp_json_encode($payload));
    }

    /**
     * Sync all products (uses batch processor for memory efficiency)
     * 
     * @param bool $force Force update existing products
     * @param bool $with_branding Include branding information (heavier payload)
     * @return array Result with sync_id
     */
    public function sync_all_products($force = false, $with_branding = true) {
        $this->logger->log('info', 'Starting full product sync', array(), 'product_sync');
        
        // Fetch products from Amrod API
        if ($with_branding) {
            $products = $this->api_client->get_products_with_branding();
        } else {
            $products = $this->api_client->get_products_without_branding();
        }
        
        if (is_wp_error($products)) {
            $this->logger->log('error', 'Failed to fetch products', array(
                'error' => $products->get_error_message(),
            ), 'product_sync');
            return array('success' => false, 'message' => $products->get_error_message());
        }
        
        if (!is_array($products) || empty($products)) {
            $this->logger->log('warning', 'No products found in Amrod API', array(), 'product_sync');
            return array('success' => false, 'message' => 'No products found');
        }
        
        $total = count($products);
        $this->logger->log('info', "Found {$total} products to sync", array(), 'product_sync');
        
        // Generate unique sync ID
        $sync_id = 'products_' . time() . '_' . wp_generate_password(8, false);
        
        // Split into batches
        $batches = array_chunk($products, $this->batch_size);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(), 'product_sync');
        
        // DON'T store in transient - causes memory exhaustion!
        // Instead, return batches directly to JavaScript
        // JavaScript will handle storage and send batches one by one
        
        // Just store minimal sync info
        update_option("bytemash_sync_{$sync_id}", array(
            'type' => 'products',
            'total' => $total,
            'batch_count' => $batch_count,
            'batch_size' => $this->batch_size,
            'current_batch' => 0,
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'status' => 'ready',
            'started' => current_time('mysql'),
        ), false);
        
        $this->logger->log('info', "Sync ready - returning batches to JavaScript", array(), 'product_sync');
        
        // Store SKU snapshot for reconciliation after processing completes
        $this->prepare_sku_snapshot(
            $sync_id,
            $products,
            array(
                'context' => 'full_sync',
                'fetch_full_catalog' => false,
                'with_branding' => $with_branding,
            )
        );
        
        return array(
            'success' => true,
            'message' => "Ready to sync {$total} products in {$batch_count} batches",
            'sync_id' => $sync_id,
            'total' => $total,
            'batch_count' => $batch_count,
            'batches' => $batches, // Send batches to JavaScript
        );
    }
    
    /**
     * Sync updated products only (incremental sync)
     * 
     * @param bool $with_branding Include branding information
     * @return array Result
     */
    public function sync_updated_products($with_branding = true) {
        $this->logger->log('info', 'Starting incremental product sync', array(), 'product_sync');
        
        // Fetch updated products only
        if ($with_branding) {
            $products = $this->api_client->get_products_with_branding_updated();
                } else {
            $products = $this->api_client->get_products_without_branding_updated();
        }
        
        if (is_wp_error($products)) {
            return array('success' => false, 'message' => $products->get_error_message());
        }
        
        if (!is_array($products) || empty($products)) {
            $this->logger->log('info', 'No updated products found', array(), 'product_sync');
            return array('success' => true, 'message' => 'No updates available', 'total' => 0);
        }
        
        $total = count($products);
        $sync_id = 'products_update_' . time() . '_' . wp_generate_password(8, false);
        
        // Split into batches (same as full sync)
        $batches = array_chunk($products, $this->batch_size);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(), 'product_sync');
        
        // Store minimal sync info
        update_option("bytemash_sync_{$sync_id}", array(
            'type' => 'products',
            'total' => $total,
            'batch_count' => $batch_count,
            'batch_size' => $this->batch_size,
            'current_batch' => 0,
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'status' => 'ready',
            'started' => current_time('mysql'),
        ), false);
        
        // Capture snapshot of current catalog to reconcile counts after incremental sync
        $this->prepare_sku_snapshot(
            $sync_id,
            null,
            array(
                'context' => 'incremental_sync',
                'fetch_full_catalog' => true,
                'with_branding' => false,
            )
        );
        
        return array(
            'success' => true,
            'message' => "Ready to sync {$total} updated products in {$batch_count} batches",
            'sync_id' => $sync_id,
            'total' => $total,
            'batch_count' => $batch_count,
            'batches' => $batches,
            );
    }
    
    /**
     * Sync variable product with size/color variations
     */
    private function sync_variable_product($product_data, $parent_sku, $force = false, $payload_signature = '', $payload_snapshot = array()) {
        try {
            $this->logger->log('info', 'Inspecting variable product for changes', array(
                'sku' => $parent_sku,
            ), 'product_sync');
            
            // Check if parent product exists (use cached lookup for better performance)
            $product_id = $this->get_product_id_by_sku_cached($parent_sku, array(
                'log_activity' => true,
                'activity_context' => array(
                    'product_type' => 'variable_parent',
                ),
            ));
            
            if ($product_id) {
                $product = wc_get_product($product_id);
                
                // If exists but is not variable, delete and recreate
                if ($product && !$product->is_type('variable')) {
                    $this->logger->log('info', 'Converting simple product to variable', array(), 'product_sync');
                    wp_delete_post($product_id, true);
                    $product_id = null;
                }
            }
            
            // Create new variable product if doesn't exist
            if (!$product_id) {
                $product = new WC_Product_Variable();
            } else {
                $product = wc_get_product($product_id);
            }
        } catch (Exception $e) {
            $this->logger->log('error', 'Failed to initialize variable product', array(
                'sku' => $parent_sku,
                'error' => $e->getMessage(),
            ), 'product_sync');
            return array('success' => false, 'message' => 'Failed to initialize variable product: ' . $e->getMessage());
        }

        $existing_signature = $product_id ? get_post_meta($product_id, '_amrod_payload_signature', true) : '';
        $previous_snapshot = $this->get_payload_snapshot($product_id);
        if (!$force && $product_id && !empty($payload_signature) && $existing_signature === $payload_signature) {
            $this->log_realtime_activity('info', sprintf(
                __('Skipped %1$s (%2$s)', 'bytemash-woo-sync'),
                $parent_sku,
                $this->format_activity_reason_label('payload_unchanged')
            ), array(
                'sku' => $parent_sku,
                'product_id' => $product_id,
                'action' => 'skipped',
                'reason' => 'payload_unchanged',
                'product_type' => 'variable',
                'changes' => array(),
            ));
            $this->logger->log('info', 'Variable product unchanged - skipping', array(
                'product_id' => $product_id,
                'sku' => $parent_sku,
                'existing_signature' => $existing_signature,
            ), 'product_sync');
            return array(
                'success' => true,
                'product_id' => $product_id,
                'skipped' => true,
                'skip_reason' => 'payload_unchanged',
                'payload_signature' => $payload_signature,
                'existing_signature' => $existing_signature,
                'message' => 'Product data unchanged'
            );
        }

        $had_existing_signature = !empty($existing_signature);
        $origin_product_id = $product_id;
        $processing_reason = $this->determine_processing_reason(
            $product_id,
            $force,
            $existing_signature,
            $payload_signature,
            $had_existing_signature,
            $origin_product_id
        );
        $payload_changes = array();
        $changed_fields = array();
        $change_summary = '';
        if ($processing_reason === 'signature_changed' && !empty($previous_snapshot) && !empty($payload_snapshot)) {
            $payload_changes = $this->diff_payload_snapshots($previous_snapshot, $payload_snapshot);
            $change_summary = $this->format_payload_change_summary($payload_changes);
            $changed_fields = array_values(array_unique(array_map(function($change) {
                return $change['field'];
            }, $payload_changes)));
        }

        $log_message = sprintf(
            __('Processing variable product %1$s (%2$s)', 'bytemash-woo-sync'),
            $parent_sku,
            $this->format_activity_reason_label($processing_reason)
        );
        if (!empty($change_summary)) {
            $log_message .= ' | ' . $change_summary;
        }

        $this->log_realtime_activity('info', $log_message, array(
            'sku' => $parent_sku,
            'product_id' => $product_id,
            'action' => 'processing',
            'reason' => $processing_reason,
            'product_type' => 'variable',
            'changes' => $payload_changes,
        ));

        $this->logger->log('info', 'Creating/updating variable product with variations', array(
            'product_id' => $product_id,
            'sku' => $parent_sku,
            'origin_product_id' => $origin_product_id,
            'payload_signature' => $payload_signature,
            'existing_signature' => $existing_signature,
        ), 'product_sync');
        
        // Set parent product data
        $product->set_sku($parent_sku);
        $product->set_name(sanitize_text_field($product_data['productName'] ?? ''));
        $product->set_description(wp_kses_post($product_data['description'] ?? ''));
        
        $has_diff_data = !empty($payload_changes);
        $run_all_sections = !$has_diff_data;
        $should_update_categories = $this->should_run_section($run_all_sections, $payload_changes, array('categories'));
        $should_update_images = $this->should_run_section($run_all_sections, $payload_changes, array('images', 'colourImages'));
        $should_update_meta = $this->should_run_section($run_all_sections, $payload_changes, $this->meta_payload_fields);
        $should_update_brand = $this->should_run_section($run_all_sections, $payload_changes, array('brand'));
        $should_update_variations = $this->should_run_section($run_all_sections, $payload_changes, array('variants', 'colourImages', 'images'));
        $force_variation_refresh = $this->should_run_section($run_all_sections, $payload_changes, array('colourImages', 'images'));

        // Set categories only when changed
        if ($should_update_categories && !empty($product_data['categories']) && is_array($product_data['categories'])) {
            $category_ids = $this->sync_product_categories($product_data['categories']);
                    if (!empty($category_ids)) {
            $product->set_category_ids($category_ids);
                    }
        }
        
        // Set brand when changed
        if ($should_update_brand && !empty($product_data['brand'])) {
            $this->set_product_brand($product, $product_data['brand']);
        }
        
        // Save parent product using safe method
        $product_id = $this->save_product_safely($product);
        
        // Sync parent images when payload changed
        if ($should_update_images && !empty($product_data['images']) && is_array($product_data['images'])) {
            try {
                $colour_images = isset($product_data['colourImages']) && is_array($product_data['colourImages'])
                    ? $product_data['colourImages']
                    : array();
                $this->sync_product_images($product_id, $product_data['images'], $colour_images);
            } catch (Exception $e) {
                $this->logger->log('warning', 'Parent image sync failed', array(
                    'product_id' => $product_id,
                    'error' => $e->getMessage(),
                ), 'image_sync');
            }
        }
        
        // Store parent metadata if necessary
        if ($should_update_meta) {
        $this->sync_product_meta($product_id, $product_data);
        }

        if (!empty($payload_signature)) {
            update_post_meta($product_id, '_amrod_payload_signature', $payload_signature, false);
        }
        if (!empty($payload_snapshot)) {
            $this->save_payload_snapshot($product_id, $payload_snapshot);
        }
        
        if ($should_update_variations) {
        // Create product attributes (Size and Color)
        $attribute_data = $this->create_product_attributes($product_data['variants']);
        $product->set_attributes($attribute_data['attributes']);
        $product->save();
        
        // Store color code mapping for frontend color swatches
        if (!empty($attribute_data['color_mapping'])) {
            update_post_meta($product_id, '_amrod_color_mapping', $attribute_data['color_mapping']);
            }
        }
        
        // Create/update variations
        $variation_count = 0;
        $variation_errors = 0;
        $variation_skipped = 0;
        $api_has_variants = !empty($product_data['variants']) && is_array($product_data['variants']) && count($product_data['variants']) > 0;
        
        // Check if variants array is empty or missing
        if (!$api_has_variants) {
            $this->logger->log('warning', 'No variants in product data - will convert to simple product', array(
                'product_id' => $product_id,
                'sku' => $parent_sku,
            ), 'product_sync');
        } elseif ($should_update_variations) {
        foreach ($product_data['variants'] as $variant_data) {
            try {
                    $result = $this->create_product_variation($product_id, $variant_data, $product_data, $force_variation_refresh);
                    if ($result === 'skipped') {
                        $variation_skipped++;
                        continue;
                    }
                if ($result) {
                    $variation_count++;
                } else {
                    $variation_errors++;
                }
            } catch (Exception $e) {
                $variation_errors++;
                $this->logger->log('error', 'Failed to create variation', array(
                    'parent_id' => $product_id,
                    'variant_sku' => $variant_data['fullCode'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ), 'product_sync');
                }
            }
        }
        
        $this->logger->log('success', "Variable product synced: {$variation_count} variations created, {$variation_errors} errors, {$variation_skipped} skipped", array(
            'product_id' => $product_id,
            'sku' => $parent_sku,
            'variations_created' => $variation_count,
            'variation_errors' => $variation_errors,
            'variations_skipped' => $variation_skipped,
        ), 'product_sync');
        
        $should_convert_to_simple =
            !$api_has_variants ||
            ($should_update_variations && $variation_count === 0 && $variation_skipped === 0);

        // If no variations remain, convert back to simple product
        if ($should_convert_to_simple) {
            $this->logger->log('warning', 'No variations created - converting variable product back to simple', array(
                'product_id' => $product_id,
                'sku' => $parent_sku,
            ), 'product_sync');
            
            // Get product data before deleting
            $product_name = $product->get_name();
            $product_sku = $product->get_sku();
            $product_description = $product->get_description();
            $category_ids = $product->get_category_ids();
            $image_ids = $product->get_gallery_image_ids();
            $meta_data = get_post_meta($product_id);
            
            // Delete variable product
            wp_delete_post($product_id, true);
            
            // Create simple product
            $simple_product = new WC_Product_Simple();
            $simple_product->set_sku($product_sku);
            $simple_product->set_name($product_name);
            $simple_product->set_description($product_description);
            $simple_product->set_category_ids($category_ids);
            
            $new_product_id = $this->save_product_safely($simple_product);
            
            // Restore meta data (but exclude branding - it will be synced from API below)
            foreach ($meta_data as $key => $value) {
                if (!in_array($key, ['_sku', '_product_attributes', '_default_attributes', '_product_version', '_amrod_brandings'])) {
                    update_post_meta($new_product_id, $key, $value[0] ?? $value);
                }
            }
            
            // IMPORTANT: Sync branding from API data after conversion
            // This ensures branding is always up-to-date from the API response
            $this->sync_product_meta($new_product_id, $product_data);
            
        if (!empty($payload_signature)) {
            update_post_meta($new_product_id, '_amrod_payload_signature', $payload_signature, false);
        }
        if (!empty($payload_snapshot)) {
            $this->save_payload_snapshot($new_product_id, $payload_snapshot);
        }
            
            return array(
                'success' => true,
                'product_id' => $new_product_id,
                'message' => "Product converted to simple (no variations created)",
                'changed_fields' => $changed_fields,
                'processing_reason' => $processing_reason,
                'payload_signature' => $payload_signature,
                'existing_signature' => $existing_signature,
            );
        }
        
        return array(
            'success' => true,
            'product_id' => $product_id,
            'message' => "Variable product created with {$variation_count} variations",
            'changed_fields' => $changed_fields,
            'processing_reason' => $processing_reason,
            'payload_signature' => $payload_signature,
            'existing_signature' => $existing_signature,
        );
    }
    
    /**
     * Create product attributes from variants
     */
    private function create_product_attributes($variants) {
        $attributes = array();
        
        // Collect all unique sizes and colors
        $sizes = array();
        $colors = array();
        $color_code_mapping = array(); // Map color name to color code
        
        foreach ($variants as $variant) {
            if (!empty($variant['codeSizeName'])) {
                $sizes[$variant['codeSizeName']] = $variant['codeSizeName'];
            }
            if (!empty($variant['codeColourName']) && !empty($variant['codeColour'])) {
                $color_name = $variant['codeColourName'];
                $color_code = $variant['codeColour'];
                
                $colors[$color_name] = $color_name;
                
                // Store the mapping of color name to code
                // Use lowercase for consistent matching
                $color_code_mapping[strtolower($color_name)] = $color_code;
            }
        }
        
        // Create Size attribute if sizes exist
        if (!empty($sizes)) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_name('size'); // Custom attribute name
            $attribute->set_options(array_values($sizes));
            $attribute->set_position(0);
            $attribute->set_visible(true);
            $attribute->set_variation(true);
            $attributes[] = $attribute;
        }
        
        // Create Color attribute if colors exist
        if (!empty($colors)) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_name('color'); // Custom attribute name
            $attribute->set_options(array_values($colors));
            $attribute->set_position(1);
            $attribute->set_visible(true);
            $attribute->set_variation(true);
            $attributes[] = $attribute;
        }
        
        return array(
            'attributes' => $attributes,
            'color_mapping' => $color_code_mapping,
        );
    }
    
    /**
     * Create single product variation
     */
    private function create_product_variation($parent_id, $variant_data, $parent_data, $force_refresh = false) {
        $variant_sku = $variant_data['fullCode'] ?? '';
        
        if (empty($variant_sku)) {
            $this->logger->log('warning', 'Variation missing SKU', array(
                'parent_id' => $parent_id,
                'variant_data' => $variant_data,
            ), 'product_sync');
            return false;
        }
        
        $this->logger->log('info', 'Creating variation', array(
            'parent_id' => $parent_id,
            'sku' => $variant_sku,
        ), 'product_sync');
        
        // Check if variation exists
        $variation_id = $this->get_product_id_by_sku_cached($variant_sku);
        $variant_signature = $this->generate_variant_signature($variant_data);
        
        if ($variation_id) {
            $this->logger->log('info', 'Updating existing variation', array(), 'product_sync');
            $variation = new WC_Product_Variation($variation_id);
        } else {
            $this->logger->log('info', 'Creating new variation', array(), 'product_sync');
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($parent_id);
        }

        if ($variation_id && !$force_refresh && $variant_signature) {
            $existing_variant_signature = get_post_meta($variation_id, '_amrod_variation_signature', true);
            if (!empty($existing_variant_signature) && $existing_variant_signature === $variant_signature) {
                $this->log_realtime_activity('info', sprintf(
                    __('Skipped variant %1$s (payload unchanged)', 'bytemash-woo-sync'),
                    $variant_sku
                ), array(
                    'sku' => $variant_sku,
                    'action' => 'variation_skipped',
                    'reason' => 'payload_unchanged',
                    'parent_id' => $parent_id,
                ));
                return 'skipped';
            }
        }
        
        // Set variation data
        $variation->set_sku($variant_sku);
        
        // Set variation attributes (keys must match parent attribute names EXACTLY)
        $variation_attributes = array();
        
        if (!empty($variant_data['codeSizeName'])) {
            $variation_attributes['size'] = $variant_data['codeSizeName'];
        }
        
        if (!empty($variant_data['codeColourName'])) {
            $variation_attributes['color'] = $variant_data['codeColourName'];
        }
        
        $variation->set_attributes($variation_attributes);
        
        // Set description from categorised attributes (measurements)
        if (!empty($variant_data['categorisedAttribute'])) {
            $measurements = array();
            foreach ($variant_data['categorisedAttribute'] as $attr) {
                if (isset($attr['key']) && isset($attr['value'])) {
                    $measurements[] = $attr['key'] . ': ' . $attr['value'];
                }
            }
            if (!empty($measurements)) {
                $variation->set_description(implode(' | ', $measurements));
            }
        }
        
        // Set weight and dimensions if available
        if (!empty($variant_data['productDimension'])) {
            $dim = $variant_data['productDimension'];
            if (isset($dim['weight'])) {
                $variation->set_weight($dim['weight']);
            }
            if (isset($dim['length']) && isset($dim['width'])) {
                $variation->set_length($dim['length']);
                $variation->set_width($dim['width']);
            }
        }
        //
        // Save variation
        try {
            $variation_id = $variation->save();
            
            if (!$variation_id) {
                $this->logger->log('error', 'Failed to save variation - save() returned false', array(
                    'parent_id' => $parent_id,
                    'sku' => $variant_sku,
                    'variation_attributes' => $variation_attributes,
                ), 'product_sync');
                return false;
            }
            
            $this->logger->log('success', 'Variation saved successfully', array(
                'variation_id' => $variation_id,
                'parent_id' => $parent_id,
                'sku' => $variant_sku,
            ), 'product_sync');
        } catch (Exception $e) {
            $this->logger->log('error', 'Variation save failed with exception', array(
                'parent_id' => $parent_id,
                'sku' => $variant_sku,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ), 'product_sync');
            return false;
        }
        
        // Set variation image from colourImages if available
        $variation_gallery = array();
        $variation_image = '';
        
        if (!empty($parent_data['colourImages'])) {
            $colour_map = $this->build_colour_gallery_map($parent_data['colourImages']);
            
            $key_candidates = array_filter(array(
                strtolower($variant_data['codeColour'] ?? ''),
                strtolower($variant_data['codeColourName'] ?? ''),
                sanitize_title($variant_data['codeColourName'] ?? ''),
            ));
            
            $colour_entry = null;
            foreach ($key_candidates as $candidate) {
                if ($candidate && isset($colour_map[$candidate])) {
                    $colour_entry = $colour_map[$candidate];
                    break;
                }
            }
            
            if ($colour_entry && !empty($colour_entry['images'])) {
                foreach ($colour_entry['images'] as $image_meta) {
                    $url = $image_meta['url'] ?? '';
                    if (!$url || in_array($url, $variation_gallery, true)) {
                        continue;
                    }
                    
                    $variation_gallery[] = $url;
                    
                    if (!$variation_image && !empty($image_meta['isDefault']) && empty($image_meta['hasLogo'])) {
                        $variation_image = $url;
                    }
                }
            }
        }

        if (empty($variation_gallery) && !empty($parent_data['colourImages']) && !empty($variant_data['codeColour'])) {
            $color_code = $variant_data['codeColour'];
            foreach ($parent_data['colourImages'] as $color_data) {
                if (($color_data['code'] ?? '') !== $color_code || empty($color_data['images']) || !is_array($color_data['images'])) {
                    continue;
                }
                foreach ($color_data['images'] as $image_entry) {
                    $url = $this->get_highest_resolution_url($image_entry['urls'] ?? array());
                    if (!$url || in_array($url, $variation_gallery, true)) {
                        continue;
                    }
                    $variation_gallery[] = $url;
                    if (!$variation_image) {
                        $variation_image = $url;
                    }
                }
                break;
            }
        }
        
        if (!$variation_image && !empty($variation_gallery)) {
            $variation_image = $variation_gallery[0];
        }
        
        if ($variation_image) {
            update_post_meta($variation_id, '_thumbnail_external_url', $variation_image);
            update_post_meta($variation_id, '_amrod_variation_image', $variation_image);
        }
        
        if (!empty($variation_gallery)) {
            update_post_meta($variation_id, '_amrod_variation_gallery', $variation_gallery);
        } else {
            delete_post_meta($variation_id, '_amrod_variation_gallery');
        }
        
        // Store variant-specific metadata
        if (!empty($variant_data['packagingAndDimension'])) {
            update_post_meta($variation_id, '_amrod_packaging', $variant_data['packagingAndDimension']);
        }

        if (!empty($variant_signature)) {
            update_post_meta($variation_id, '_amrod_variation_signature', $variant_signature, false);
        } else {
            delete_post_meta($variation_id, '_amrod_variation_signature');
        }
        
        return $variation_id;
    }
    
    /**
     * Sync single product (handles Amrod's data structure)
     */
    public function sync_single_product($product_data, $force = false) {
        // Amrod uses 'simpleCode' or 'fullCode' as SKU
        $sku = $product_data['simpleCode'] ?? $product_data['fullCode'] ?? null;
        
        if (!$sku) {
            $this->logger->log('error', 'Product missing SKU', array(), 'product_sync');
            return array('success' => false, 'message' => 'Missing SKU');
        }
        
        $sku = sanitize_text_field($sku);

        $payload_signature = $this->generate_product_signature_hash($product_data);
        $payload_snapshot = $this->build_signature_payload($product_data);
        
        // Check if product is decoupled (sold separately from grouped base)
        $is_decoupled = isset($product_data['decoupled']) && $product_data['decoupled'] === true;
        
        if ($is_decoupled) {
            $this->logger->log('info', 'Product is decoupled - will be treated as standalone product', array(
                'sku' => $sku,
                'product_name' => $product_data['productName'] ?? '',
            ), 'product_sync');
        }
        
        // Check if product has variants (sizes/colors)
        // OPTIMIZATION: Cache option lookup (only checked once per product, but faster)
        static $enable_variable_products_cache = null;
        if ($enable_variable_products_cache === null) {
            $enable_variable_products_cache = get_option('bytemash_enable_variable_products', true);
        }
        $has_variants = $enable_variable_products_cache && $this->product_has_valid_variants($product_data);
        if ($enable_variable_products_cache && !$has_variants && !empty($product_data['variants'])) {
            $this->logger->log('info', 'Variants payload present but no valid variations detected, treating as simple product', array(
                'sku' => $sku,
                'variant_count' => is_array($product_data['variants']) ? count($product_data['variants']) : 0,
            ), 'product_sync');
        }
        
        // OPTIMIZATION: Use cached SKU lookup with aggressive fallback
        $normalized_sku = strtolower(trim($sku));
        $product_id = null;
        $is_variable_type = false;
        $prefetch_strategy = '';
        
        if ($this->batch_mode) {
            // Try exact match first
            if (isset($this->sku_cache[$sku])) {
                $product_id = $this->sku_cache[$sku]['product_id'];
                $is_variable_type = $this->sku_cache[$sku]['post_type'] === 'product_variation';
                $prefetch_strategy = 'prefetch_cache_exact';
            } elseif (isset($this->sku_cache[$normalized_sku])) {
                // Try case-insensitive match
                $product_id = $this->sku_cache[$normalized_sku]['product_id'];
                $is_variable_type = $this->sku_cache[$normalized_sku]['post_type'] === 'product_variation';
                $prefetch_strategy = 'prefetch_cache_normalized';
            }
        }
        
        // If not found in cache, use aggressive lookup
        if (!$product_id) {
            global $wpdb;
            // Try exact match first
            $product_row = $wpdb->get_row($wpdb->prepare(
                "SELECT pm.post_id, p.post_type 
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE pm.meta_key = '_sku' AND pm.meta_value = %s 
                 LIMIT 1",
                $sku
            ));
            
            // If not found, try case-insensitive match
            if (!$product_row) {
                $product_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT pm.post_id, p.post_type 
                     FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                     WHERE pm.meta_key = '_sku' AND LOWER(TRIM(pm.meta_value)) = %s 
                     LIMIT 1",
                    $normalized_sku
                ));
            }
            
            if ($product_row) {
                $product_id = (int) $product_row->post_id;
                $is_variable_type = $product_row->post_type === 'product_variation';
                $prefetch_strategy = $prefetch_strategy ?: 'prefetch_sql_' . ($product_row ? 'match' : 'unknown');
            }
        }

        if ($product_id && $prefetch_strategy) {
            $this->log_realtime_activity('info', sprintf(
                'Matched existing product %1$s via %2$s lookup',
                $sku,
                $prefetch_strategy
            ), array(
                'sku' => $sku,
                'product_id' => $product_id,
                'action' => 'sku_lookup',
                'lookup_strategy' => $prefetch_strategy,
            ));
        }

        $pending_variable_convert = false;
        if ($product_id) {
            if ($has_variants && $is_variable_type) {
                // No action needed - correct type already
            } elseif ($has_variants && !$is_variable_type) {
                // Needs conversion to variable
                $pending_variable_convert = true;
            } elseif (!$has_variants && $is_variable_type === false) {
                // Already simple, no action
            } elseif (!$has_variants) {
                // Product currently variable but API says simple - convert now
                    $existing_product = wc_get_product($product_id);
                    if ($existing_product) {
                        $this->logger->log('info', 'Product was variable but now has no variants - converting to simple', array(
                            'product_id' => $product_id,
                            'sku' => $sku,
                        ), 'product_sync');
                        
                        $product_name = $existing_product->get_name();
                        $product_sku = $existing_product->get_sku();
                        $product_description = $existing_product->get_description();
                        $category_ids = $existing_product->get_category_ids();
                        $meta_data = get_post_meta($product_id);
                        
                        wp_delete_post($product_id, true);
                        
                        $simple_product = new WC_Product_Simple();
                        $simple_product->set_sku($product_sku);
                        $simple_product->set_name($product_name);
                        $simple_product->set_description($product_description);
                        $simple_product->set_category_ids($category_ids);
                        
                        $new_product_id = $this->save_product_safely($simple_product);
                        
                        foreach ($meta_data as $key => $value) {
                            if (!in_array($key, ['_sku', '_product_attributes', '_default_attributes', '_product_version', '_amrod_brandings'])) {
                                update_post_meta($new_product_id, $key, $value[0] ?? $value);
                            }
                        }
                        
                        $product_id = $new_product_id;
                }
            }
        }
        
        // OPTIMIZATION: Removed debug logging for performance
        
        if ($has_variants) {
            // Create/update as Variable Product with variations
            
            try {
                return $this->sync_variable_product($product_data, $sku, $force, $payload_signature, $payload_snapshot);
            } catch (Exception $e) {
                $this->logger->log('error', 'Variable product sync failed - falling back to simple product', array(
                    'sku' => $sku,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ), 'product_sync');
                
                // Fallback: Create as simple product to prevent total failure
                $this->logger->log('warning', 'Creating as simple product instead', array(), 'product_sync');
                $has_variants = false; // Force simple product creation
            }
        }
        
        // Otherwise create/update as Simple Product
        // Note: product_id may have been set above if we converted variable to simple
        // OPTIMIZATION: Reuse product_id from earlier query if available
        // Only do additional lookup if product_id is not set OR if it's a variable type when we need simple
        if (!$product_id || ($product_id && $is_variable_type)) {
            // Need to find simple product by SKU (aggressive lookup already handles this)
            $found_id = $this->get_product_id_by_sku_cached($sku, array(
                'log_activity' => true,
                'activity_context' => array(
                    'product_type' => 'simple',
                ),
            ));
            if ($found_id && !$is_variable_type) {
                $product_id = $found_id;
            } elseif ($found_id) {
                // Found a product but it's variable type - check if it's actually simple
                $check_product = wc_get_product($found_id);
                if ($check_product && !$check_product->is_type('variable')) {
                    $product_id = $found_id;
                    $is_variable_type = false;
                }
            } elseif (!$product_id) {
                $product_id = $found_id; // Use found_id even if null (will create new)
            }
        }
        
        $existing_signature = $product_id ? get_post_meta($product_id, '_amrod_payload_signature', true) : '';
        $previous_snapshot = $this->get_payload_snapshot($product_id);
        if ($product_id && !$force) {
            if (!empty($payload_signature) && !empty($existing_signature) && $existing_signature === $payload_signature) {
                $this->log_realtime_activity('info', sprintf(
                    __('Skipped %1$s (%2$s)', 'bytemash-woo-sync'),
                    $sku,
                    $this->format_activity_reason_label('payload_unchanged')
                ), array(
                    'sku' => $sku,
                    'product_id' => $product_id,
                    'action' => 'skipped',
                    'reason' => 'payload_unchanged',
                    'product_type' => 'simple',
                    'changes' => array(),
                ));
                $this->logger->log('info', 'Simple product unchanged - skipping', array(
                    'product_id' => $product_id,
                    'sku' => $sku,
                    'existing_signature' => $existing_signature,
                ), 'product_sync');
                return array(
                    'success' => true,
                    'product_id' => $product_id,
                    'skipped' => true,
                    'skip_reason' => 'payload_unchanged',
                    'payload_signature' => $payload_signature,
                    'existing_signature' => $existing_signature,
                    'message' => 'Product data unchanged'
                );
            }

            $existing_product = wc_get_product($product_id);
            $product = $existing_product ?: new WC_Product_Simple();
                } else {
            // Create new simple product
            $product = new WC_Product_Simple();
                }
                
        $had_existing_signature = !empty($existing_signature);
        $origin_product_id = $product_id;
        $processing_reason = $this->determine_processing_reason(
            $product_id,
            $force,
            $existing_signature,
            $payload_signature,
            $had_existing_signature,
            $origin_product_id
        );
        $payload_changes = array();
        $changed_fields = array();
        $change_summary = '';
        if ($processing_reason === 'signature_changed' && !empty($previous_snapshot) && !empty($payload_snapshot)) {
            $payload_changes = $this->diff_payload_snapshots($previous_snapshot, $payload_snapshot);
            $change_summary = $this->format_payload_change_summary($payload_changes);
            $changed_fields = array_values(array_unique(array_map(function($change) {
                return $change['field'];
            }, $payload_changes)));
        }

        $log_message = sprintf(
            __('Processing product %1$s (%2$s)', 'bytemash-woo-sync'),
            $sku,
            $this->format_activity_reason_label($processing_reason)
        );
        if (!empty($change_summary)) {
            $log_message .= ' | ' . $change_summary;
        }

        $this->log_realtime_activity('info', $log_message, array(
            'sku' => $sku,
            'product_id' => $product_id,
            'action' => 'processing',
            'reason' => $processing_reason,
            'product_type' => 'simple',
            'changes' => $payload_changes,
        ));

        $has_diff_data = !empty($payload_changes);
        $run_all_sections = !$has_diff_data;
        $should_update_categories = $this->should_run_section($run_all_sections, $payload_changes, array('categories'));
        $should_update_images = $this->should_run_section($run_all_sections, $payload_changes, array('images', 'colourImages'));
        $should_update_meta = $this->should_run_section($run_all_sections, $payload_changes, $this->meta_payload_fields);
        $should_update_brand = $this->should_run_section($run_all_sections, $payload_changes, array('brand'));
        
        try {
            // Set basic product data (Amrod's field names)
            $product->set_sku($sku);
            $product->set_name(sanitize_text_field($product_data['productName'] ?? ''));
            $product->set_description(wp_kses_post($product_data['description'] ?? ''));
            
            // Set categories (Amrod returns nested category objects)
            // Note: Unchanged products already returned early, so we only reach here if updating
            if ($should_update_categories && !empty($product_data['categories']) && is_array($product_data['categories'])) {
                $category_ids = $this->sync_product_categories($product_data['categories']);
                $product->set_category_ids($category_ids);
            }
            
            // Always set brand if present in API data (even if product is otherwise unchanged)
            if ($should_update_brand && !empty($product_data['brand'])) {
                $this->set_product_brand($product, $product_data['brand']);
            }
            
            // Note: Stock and prices are synced separately via their own endpoints
            // Amrod recommends separate syncs for better performance
            
            // Save product using safe method
            $product_id = $this->save_product_safely($product);
            
            // Sync images (Amrod returns image objects with URLs and metadata)
            // Images are optional - if they fail, product still syncs
            if ($should_update_images && !empty($product_data['images']) && is_array($product_data['images'])) {
                try {
                    $colour_images = isset($product_data['colourImages']) && is_array($product_data['colourImages'])
                        ? $product_data['colourImages']
                        : array();
                    $this->sync_product_images($product_id, $product_data['images'], $colour_images);
                } catch (Exception $e) {
                    $this->logger->log('warning', 'Image sync failed but product created', array(
                        'product_id' => $product_id,
                        'error' => $e->getMessage(),
                    ), 'image_sync');
                }
            }
            
            // Store Amrod-specific metadata (includes branding guides, color swatches, etc.)
            if ($should_update_meta) {
            $this->sync_product_meta($product_id, $product_data);
            }

        if (!empty($payload_signature)) {
            update_post_meta($product_id, '_amrod_payload_signature', $payload_signature, false);
        }
        if (!empty($payload_snapshot)) {
            $this->save_payload_snapshot($product_id, $payload_snapshot);
        }
            
            // Store stock from product data if available
            // OPTIMIZATION: Only save again if stock was set (product was already saved above)
            if (isset($product_data['stock']) && is_numeric($product_data['stock'])) {
                $stock_qty = (int) $product_data['stock'];
                // Reload product to ensure we have fresh data
                $product = wc_get_product($product_id);
                if ($product) {
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity($stock_qty);
                    $product->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');
                    // Save only if stock changed
                    $product->save();
                }
            }
            
            // Reduce noise: avoid per-product success logs
            
            return array(
                'success' => true,
                'product_id' => $product_id,
                'changed_fields' => $changed_fields,
                'processing_reason' => $processing_reason,
                'payload_signature' => $payload_signature,
                'existing_signature' => $existing_signature,
            );
            
        } catch (Exception $e) {
            $this->logger->log('error', "Failed to sync product: {$sku}", array(
                'sku' => $sku,
                'error' => $e->getMessage(),
            ), 'product_sync');
            
            return array('success' => false, 'message' => $e->getMessage());
        }
    }
    
    /**
     * Check if product data has changed compared to existing product
     * 
     * @param WC_Product $existing_product Existing WooCommerce product
     * @param array $api_data New data from API
     * @return bool True if unchanged, false if changed
     */
    private function is_product_data_unchanged($existing_product, $api_data) {
        // Compare basic product data
        $existing_name = $existing_product->get_name();
        $api_name = sanitize_text_field($api_data['productName'] ?? '');
        
        if ($existing_name !== $api_name) {
            return false;
        }
        
        $existing_description = $existing_product->get_description();
        $api_description = wp_kses_post($api_data['description'] ?? '');
        
        if ($existing_description !== $api_description) {
            return false;
        }
        
        // Compare stock data if available
        if (isset($api_data['stock']) && is_numeric($api_data['stock'])) {
            $existing_stock = $existing_product->get_stock_quantity();
            $api_stock = (int) $api_data['stock'];
            
            if ($existing_stock !== $api_stock) {
                return false;
            }
        }
        
        // OPTIMIZATION: Don't sync categories during unchanged check - just compare existing
        // This avoids expensive category creation/lookup during the check
        $existing_categories = $existing_product->get_category_ids();
        if (!empty($api_data['categories']) && is_array($api_data['categories'])) {
            // Quick check: if API has categories but product has none, it changed
            if (empty($existing_categories)) {
                return false;
            }
            // For detailed comparison, we'd need to sync categories, but that's expensive
            // So we'll do a lightweight check: if category count differs significantly, assume changed
            // Full category sync will happen during actual update
        }
        
        // OPTIMIZATION: Skip brand, stock, category, and image comparison - these are handled separately
        // Brand updates are checked via needs_brand_update flag
        // Stock is synced via separate stock endpoint
        // Categories are synced separately
        // Images are synced separately
        
        // If we get here, basic data (name, description) is unchanged
        return true;
        
        // If we get here, no significant changes detected
        return true;
    }
    
    /**
     * Get existing product images for comparison
     */
    private function get_existing_product_images($product_id) {
        $images = array();
        
        // Get featured image
        $featured_id = get_post_thumbnail_id($product_id);
        if ($featured_id) {
            $featured_url = wp_get_attachment_url($featured_id);
            if ($featured_url) {
                $images[] = array('url' => $featured_url);
            }
        }
        
        // Get gallery images
        $gallery_ids = get_post_meta($product_id, '_product_image_gallery', true);
        if ($gallery_ids) {
            $gallery_ids = explode(',', $gallery_ids);
            foreach ($gallery_ids as $gallery_id) {
                $gallery_url = wp_get_attachment_url($gallery_id);
                if ($gallery_url) {
                    $images[] = array('url' => $gallery_url);
                }
            }
        }
        
        return $images;
    }
    
    /**
     * Compare existing images with API images
     */
    private function are_images_unchanged($existing_images, $api_images) {
        if (count($existing_images) !== count($api_images)) {
            return false;
        }
        
        $existing_urls = array_column($existing_images, 'url');
        $api_urls = array_column($api_images, 'url');
        
        sort($existing_urls);
        sort($api_urls);
        
        return $existing_urls === $api_urls;
    }
    
    /**
     * Handle product save with proper WooCommerce hooks and database management
     * OPTIMIZATION: Term counting is handled at batch level, not per-product
     */
    private function save_product_safely($product) {
        // OPTIMIZATION: Don't defer term counting here - it's already deferred at batch level
        // This prevents re-enabling counting after each product (major performance bottleneck)
        
        try {
            // Save the product using WooCommerce's native method
            // WooCommerce hooks will still fire: woocommerce_before_product_object_save, woocommerce_after_product_object_save, etc.
            $product_id = $product->save();
            
            // Ensure the product is properly saved
            if (!$product_id) {
                $this->logger->log('error', 'Product save failed - no ID returned', array(
                    'sku' => $product->get_sku(),
                    'name' => $product->get_name(),
                    'type' => $product->get_type(),
                ), 'product_sync');
                throw new Exception('Failed to save product');
            }
            
            return $product_id;
        } catch (Exception $e) {
            $this->logger->log('error', 'Product save failed with exception', array(
                'sku' => $product->get_sku(),
                'name' => $product->get_name(),
                'type' => $product->get_type(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ), 'product_sync');
            throw $e;
        }
    }
    
    /**
     * Sync product categories from Amrod format
     * 
     * Handles products that appear in multiple categories and ensures
     * subcategories with the same name in different parent categories
     * are properly distinguished using the full path.
     */
    private function sync_product_categories($categories) {
        $category_ids = array();
        
        if (empty($categories) || !is_array($categories)) {
            return array();
        }
        
        foreach ($categories as $cat_data) {
            if (empty($cat_data['name'])) {
                continue;
            }
            
            $cat_name = sanitize_text_field($cat_data['name']);
            
            // Use the full path from the API - this is critical for distinguishing
            // subcategories with the same name in different parent categories
            // e.g., "Main A / Sub 1" vs "Main B / Sub 1"
            $cat_path = '';
            
            // Check for 'path' field (from product API)
            if (isset($cat_data['path']) && $cat_data['path'] !== '') {
                $cat_path = trim($cat_data['path']);
            }
            // Check for 'categoryPath' field (alternative format)
            elseif (isset($cat_data['categoryPath']) && $cat_data['categoryPath'] !== '') {
                $cat_path = trim($cat_data['categoryPath']);
            }
            
            // If no path is provided, use the category name as fallback
            if (empty($cat_path)) {
                $cat_path = $cat_name;
                // OPTIMIZATION: Removed logging for performance
            }

            $meta = array(
                'id' => $cat_data['id'] ?? '',
                'code' => $cat_data['code'] ?? '',
                'image' => $cat_data['image'] ?? '',
            );

            $result = $this->ensure_category_hierarchy($cat_path, $cat_name, $meta);

            if ($result['success']) {
                $category_ids[] = $result['term_id'];
                // OPTIMIZATION: Removed debug logging for performance
            } else {
                // Only log errors, not successes
                $this->logger->log('error', 'Failed to ensure category hierarchy', array(
                    'category' => $cat_name,
                    'path' => $cat_path,
                    'message' => $result['message'],
                ), 'category_sync');
            }
        }
        
        // Return unique category IDs - products can appear in multiple categories
        // and we want to ensure all are assigned
        $unique_category_ids = array_unique(array_filter($category_ids));
        
        if (count($unique_category_ids) !== count($category_ids)) {
            // OPTIMIZATION: Removed logging for performance
            // Duplicate category IDs are normal and expected
        }
        
        return $unique_category_ids;
    }
    
    /**
     * Sync product images from Amrod format
     */
    private function sync_product_images($product_id, $images, $colour_images = array()) {
        $featured_image = null;
        $gallery_images = array();
        $gallery_seen = array();
        $all_images = array();
        $all_seen = array();
        
        $this->logger->log('info', 'Starting image sync', array(), 'image_sync');
        
        $add_all = function($url) use (&$all_images, &$all_seen) {
            if (empty($url) || isset($all_seen[$url])) {
                return;
            }
            $all_seen[$url] = true;
            $all_images[] = $url;
        };
        
        $add_gallery = function($url) use (&$gallery_images, &$gallery_seen) {
            if (empty($url) || isset($gallery_seen[$url])) {
                return;
            }
            $gallery_seen[$url] = true;
            $gallery_images[] = $url;
        };
        
        foreach ((array) $images as $image_data) {
            if (empty($image_data['urls']) || !is_array($image_data['urls'])) {
                $this->logger->log('warning', 'Skipping image - no urls array', array(), 'image_sync');
                continue;
            }
            
            $image_url = $this->get_highest_resolution_url($image_data['urls']);
            
            if (empty($image_url)) {
                $this->logger->log('warning', 'Skipping image - no URL found', array(), 'image_sync');
                continue;
            }
            
            $add_all($image_url);
            
            if (!empty($image_data['isDefault']) && !$featured_image) {
                $featured_image = $image_url;
            } else {
                $add_gallery($image_url);
            }
        }
        
        $colour_gallery_map = $this->build_colour_gallery_map($colour_images);
        
        foreach ($colour_gallery_map as $entry) {
            if (empty($entry['images']) || !is_array($entry['images'])) {
                continue;
            }
            
            foreach ($entry['images'] as $image_meta) {
                $url = $image_meta['url'] ?? '';
                if (!$url) {
                    continue;
                }
                $add_all($url);
                
                if (empty($image_meta['isDefault']) || !empty($image_meta['hasLogo'])) {
                    $add_gallery($url);
                } elseif (!$featured_image) {
                    $featured_image = $url;
                }
            }
        }
        
        if (!$featured_image && !empty($all_images)) {
            $featured_image = $all_images[0];
        }
        
        if ($featured_image) {
            update_post_meta($product_id, '_thumbnail_external_url', $featured_image);
            update_post_meta($product_id, '_external_image_url', $featured_image); // For Gutenberg blocks
            update_post_meta($product_id, '_amrod_featured_image', $featured_image);
            
            if (($key = array_search($featured_image, $gallery_images, true)) !== false) {
                unset($gallery_images[$key]);
                $gallery_images = array_values($gallery_images);
            }
        } else {
            delete_post_meta($product_id, '_thumbnail_external_url');
            delete_post_meta($product_id, '_external_image_url');
            delete_post_meta($product_id, '_amrod_featured_image');
        }
        
        if (!empty($gallery_images)) {
            update_post_meta($product_id, '_amrod_gallery_images', $gallery_images);
        } else {
            delete_post_meta($product_id, '_amrod_gallery_images');
        }
        
        if (!empty($all_images)) {
            update_post_meta($product_id, '_amrod_all_images', $all_images);
        } else {
            delete_post_meta($product_id, '_amrod_all_images');
        }
        
        if (!empty($colour_gallery_map)) {
            update_post_meta($product_id, '_amrod_colour_gallery_map', $colour_gallery_map);
        } else {
            delete_post_meta($product_id, '_amrod_colour_gallery_map');
        }
        
        $this->logger->log('success', 'Image URLs stored (using Amrod CDN)', array(), 'image_sync');
    }
    
    /**
     * Sync product variations
     */
    private function sync_product_variations($product_id, $product_data) {
        // Convert simple product to variable
        $parent_product = wc_get_product($product_id);
        
        if (!$parent_product) {
            return;
        }
        
        // Delete old product and create variable product
        wp_delete_post($product_id, true);
        
        $variable_product = new WC_Product_Variable();
        $variable_product->set_sku($product_data['sku']);
        $variable_product->set_name($product_data['name'] ?? '');
        $variable_product->set_description($product_data['description'] ?? '');
        $variable_product->set_short_description($product_data['short_description'] ?? '');
        
        $parent_id = $variable_product->save();
        
        // Create attributes
        $attributes = array();
        
        foreach ($product_data['variations'] as $variation_data) {
            foreach ($variation_data['attributes'] ?? array() as $attr_name => $attr_value) {
                if (!isset($attributes[$attr_name])) {
                    $attributes[$attr_name] = array();
                }
                
                if (!in_array($attr_value, $attributes[$attr_name])) {
                    $attributes[$attr_name][] = $attr_value;
                }
            }
        }
        
        // Set attributes
        $product_attributes = array();
        
        foreach ($attributes as $attr_name => $attr_values) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_name($attr_name);
            $attribute->set_options($attr_values);
            $attribute->set_visible(true);
            $attribute->set_variation(true);
            
            $product_attributes[] = $attribute;
        }
        
        $variable_product->set_attributes($product_attributes);
        $variable_product->save();
        
        // Create variations
        foreach ($product_data['variations'] as $variation_data) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($parent_id);
            
            if (isset($variation_data['sku'])) {
                $variation->set_sku($variation_data['sku']);
            }
            
            if (isset($variation_data['price'])) {
                $variation->set_regular_price($variation_data['price']);
            }
            
            if (isset($variation_data['stock_quantity'])) {
                $variation->set_stock_quantity($variation_data['stock_quantity']);
                $variation->set_manage_stock(true);
            }
            
            if (isset($variation_data['attributes'])) {
                $variation->set_attributes($variation_data['attributes']);
            }
            
            $variation->save();
        }
        
        $this->logger->log('success', "Product variations synced", array(
            'product_id' => $parent_id,
            'variations_count' => count($product_data['variations']),
        ), 'product_sync');
    }
    
    /**
     * Set product brand
     */
    private function set_product_brand($product, $brand_data) {
        // Extract brand name from brand data (could be string or array)
        if (is_array($brand_data)) {
            // Amrod returns brand as object with multiple fields
            $brand_name = $brand_data['brandName'] ?? $brand_data['name'] ?? $brand_data['Brand'] ?? '';
        } else {
            $brand_name = $brand_data;
        }
        
        // If no brand name, skip
        if (empty($brand_name)) {
            return;
        }
        
        // Persist brand meta for reference (only identifier, not logo URL)
        // Logo URL is stored in brands sync data (amrod_brand_{code} option)
        $product_id = $product->get_id();
        $sku = $product->get_sku();
        
        // Check if brand is already set (to determine if it's an update or new)
        $existing_brand = get_post_meta($product_id, '_amrod_brand', true);
        $is_update = !empty($existing_brand);
        
        // OPTIMIZATION: Disable autoloading for sync meta (not needed in queries, saves memory)
        update_post_meta($product_id, '_amrod_brand', sanitize_text_field($brand_name), false);
        
        $brand_code = '';
        if (is_array($brand_data)) {
            if (!empty($brand_data['code'])) {
                $brand_code = sanitize_text_field($brand_data['code']);
                update_post_meta($product_id, '_amrod_brand_code', $brand_code, false);
            }
            // Note: Logo URL is NOT stored in product meta - it's retrieved from brands sync data
        }
        
        // Log brand sync
        $log_data = array(
            'product_id' => $product_id,
            'sku' => $sku,
            'brand_name' => $brand_name,
        );
        if (!empty($brand_code)) {
            $log_data['brand_code'] = $brand_code;
        }
        
        if ($is_update && $existing_brand !== $brand_name) {
            $this->logger->log('info', "Brand updated: {$brand_name}", $log_data, 'product_sync');
        } else if (!$is_update) {
            $this->logger->log('info', "Brand set: {$brand_name}", $log_data, 'product_sync');
        }
        
        // Check if brand taxonomy exists (many themes/plugins use 'product_brand')
        if (taxonomy_exists('product_brand')) {
            $term = get_term_by('name', $brand_name, 'product_brand');
            
            if (!$term) {
                $result = wp_insert_term($brand_name, 'product_brand');
                
                if (!is_wp_error($result)) {
                    wp_set_object_terms($product_id, $result['term_id'], 'product_brand');
                }
            } else {
                wp_set_object_terms($product_id, $term->term_id, 'product_brand');
            }
        } else {
            // Store as meta if taxonomy doesn't exist
            update_post_meta($product_id, '_product_brand', sanitize_text_field($brand_name));
        }
    }
    
    /**
     * Sync product meta data (Amrod-specific fields)
     */
    private function sync_product_meta($product_id, $product_data) {
        // Store Amrod codes for reference
        if (!empty($product_data['simpleCode'])) {
            // OPTIMIZATION: Disable autoloading for sync meta
            update_post_meta($product_id, '_amrod_simple_code', sanitize_text_field($product_data['simpleCode']), false);
        }
        
        if (!empty($product_data['fullCode'])) {
            update_post_meta($product_id, '_amrod_full_code', sanitize_text_field($product_data['fullCode']));
        }
        
        // Store decoupled flag - indicates this product is sold separately from the main grouped base
        // Decoupled products are their own standalone products (e.g., end of life, clearance, specials)
        $is_decoupled = isset($product_data['decoupled']) && $product_data['decoupled'] === true;
        if ($is_decoupled) {
            update_post_meta($product_id, '_amrod_decoupled', 1);
            $this->logger->log('info', 'Product marked as decoupled (standalone product)', array(
                'product_id' => $product_id,
                'sku' => $product_data['simpleCode'] ?? '',
            ), 'product_sync');
        } else {
            // Ensure the meta is removed if product is no longer decoupled
            delete_post_meta($product_id, '_amrod_decoupled');
        }
        
        // Store branding guides (important for customer downloads!)
        if (!empty($product_data['fullBrandingGuide'])) {
            update_post_meta($product_id, '_amrod_full_branding_guide', esc_url_raw($product_data['fullBrandingGuide']));
        }
        
        if (!empty($product_data['logo24BrandingGuide'])) {
            update_post_meta($product_id, '_amrod_logo24_branding_guide', esc_url_raw($product_data['logo24BrandingGuide']));
        }
        
        // Store branding information - always check and update
        // Handle cases where brandings key might be missing, null, empty array, or valid array
        $api_brandings = $product_data['brandings'] ?? null;
        
        if (!empty($api_brandings) && is_array($api_brandings)) {
            // Validate branding structure before saving
            $valid_brandings = array();
            foreach ($api_brandings as $idx => $position) {
                if (is_array($position)) {
                    // Accept position if it has positionCode OR positionName (some may only have name)
                    $has_position_code = !empty($position['positionCode']);
                    $has_position_name = !empty($position['positionName']);
                    
                    if ($has_position_code || $has_position_name) {
                        // Include position if it has methods OR if it's a valid position structure
                        // Some positions might not have methods yet, but should still be saved
                        if (isset($position['method'])) {
                            // If method exists, it should be an array (even if empty)
                            if (is_array($position['method'])) {
                                $valid_brandings[] = $position;
                            } else {
                                // Method exists but is not an array - log warning but still include position
                                $this->logger->log('warning', "Branding position has invalid method type", array(
                                    'product_id' => $product_id,
                                    'position_index' => $idx,
                                    'method_type' => gettype($position['method']),
                                ), 'product_sync');
                                // Still include it - might be valid in some cases
                                $valid_brandings[] = $position;
                            }
                        } else {
                            // No method key - include position anyway (methods might be added later or via separate endpoint)
                            $valid_brandings[] = $position;
                        }
                    } else {
                        // Log invalid position structure
                        $this->logger->log('warning', "Invalid branding position structure (missing positionCode and positionName)", array(
                            'product_id' => $product_id,
                            'position_index' => $idx,
                        ), 'product_sync');
                    }
                }
            }
            
            if (!empty($valid_brandings)) {
                // OPTIMIZATION: Disable autoloading for sync meta
                update_post_meta($product_id, '_amrod_brandings', $valid_brandings, false);
                $this->logger->log('info', "Branding options synced", array(
                    'product_id' => $product_id,
                    'branding_count' => count($valid_brandings),
                    'positions' => array_map(function($b) { return $b['positionCode'] ?? 'unknown'; }, $valid_brandings),
                ), 'product_sync');
            } else {
                // API returned brandings but none were valid - clear existing
                delete_post_meta($product_id, '_amrod_brandings');
                $this->logger->log('warning', "Branding options cleared (API returned invalid structure)", array(
                    'product_id' => $product_id,
                    'api_brandings_type' => gettype($api_brandings),
                ), 'product_sync');
            }
        } else {
            // API returned null, empty, or brandings key doesn't exist
            // Check if product currently has branding - if so, clear it (API is source of truth)
            $existing_brandings = get_post_meta($product_id, '_amrod_brandings', true);
            if (empty($existing_brandings)) {
                // Product never had branding, API doesn't have it - this is fine, just log
                $this->logger->log('info', "No branding options in API response (product has none)", array(
                    'product_id' => $product_id,
                    'api_brandings_type' => $api_brandings !== null ? gettype($api_brandings) : 'missing_key',
                ), 'product_sync');
            } else {
                // Product has branding but API doesn't - clear it (API is source of truth)
                delete_post_meta($product_id, '_amrod_brandings');
                $this->logger->log('info', "Branding options cleared (API returned empty/null, product previously had branding)", array(
                    'product_id' => $product_id,
                    'api_brandings_type' => $api_brandings !== null ? gettype($api_brandings) : 'missing_key',
                ), 'product_sync');
            }
        }
        
        // Store color swatches for future swatch functionality
        if (!empty($product_data['colourImages'])) {
            update_post_meta($product_id, '_amrod_colour_images', $product_data['colourImages']);
            
            $normalized_colour_map = $this->build_colour_gallery_map($product_data['colourImages']);
            if (!empty($normalized_colour_map)) {
                update_post_meta($product_id, '_amrod_colour_gallery_map', $normalized_colour_map);
            } else {
                delete_post_meta($product_id, '_amrod_colour_gallery_map');
            }
            
            // Extract simplified swatch data
            $swatches = array();
            foreach ((array) $product_data['colourImages'] as $color) {
                $swatch_images = array();
                
                if (!empty($color['images']) && is_array($color['images'])) {
                    foreach ($color['images'] as $img) {
                        $url = $this->get_highest_resolution_url($img['urls'] ?? array());
                        if ($url && !in_array($url, $swatch_images, true)) {
                            $swatch_images[] = $url;
                        }
                    }
                }
                
                $swatches[] = array(
                    'name' => $color['name'] ?? '',
                    'code' => $color['code'] ?? '',
                    'images' => $swatch_images,
                );
            }
            
            if (!empty($swatches)) {
                update_post_meta($product_id, '_amrod_color_swatches', $swatches);
            } else {
                delete_post_meta($product_id, '_amrod_color_swatches');
            }
        } else {
            delete_post_meta($product_id, '_amrod_colour_images');
            delete_post_meta($product_id, '_amrod_colour_gallery_map');
            delete_post_meta($product_id, '_amrod_color_swatches');
        }
        
        // Store inventory type and behavior
        if (isset($product_data['inventoryType'])) {
            update_post_meta($product_id, '_amrod_inventory_type', $product_data['inventoryType']);
        }
        
        if (isset($product_data['behaviour'])) {
            update_post_meta($product_id, '_amrod_behaviour', $product_data['behaviour']);
        }
        
        // Store product attributes
        if (!empty($product_data['material'])) {
            update_post_meta($product_id, '_amrod_material', sanitize_text_field($product_data['material']));
        }
        
        if (!empty($product_data['gender'])) {
            update_post_meta($product_id, '_amrod_gender', sanitize_text_field($product_data['gender']));
        }
        
        if (!empty($product_data['fit'])) {
            update_post_meta($product_id, '_amrod_fit', sanitize_text_field($product_data['fit']));
        }
        
        if (!empty($product_data['feature'])) {
            update_post_meta($product_id, '_amrod_feature', sanitize_text_field($product_data['feature']));
        }
        
        // Store min/max/increment
        if (isset($product_data['minimum'])) {
            update_post_meta($product_id, '_amrod_minimum', (int) $product_data['minimum']);
        }
        
        if (isset($product_data['maximum'])) {
            update_post_meta($product_id, '_amrod_maximum', (int) $product_data['maximum']);
        }
        
        if (isset($product_data['incrementedBy'])) {
            update_post_meta($product_id, '_amrod_incremented_by', (int) $product_data['incrementedBy']);
        }
        
        // Store related/companion codes
        // Note: For decoupled products, these relationships are still maintained
        // as they may reference other products that are also decoupled or separately available
        if (!empty($product_data['companionCodes'])) {
            update_post_meta($product_id, '_amrod_companion_codes', $product_data['companionCodes']);
        }
        
        if (!empty($product_data['relatedCodes'])) {
            update_post_meta($product_id, '_amrod_related_codes', $product_data['relatedCodes']);
        }
        
        // Store grouping codes - used for product variations and sets
        if (!empty($product_data['groupingCodes'])) {
            update_post_meta($product_id, '_amrod_grouping_codes', $product_data['groupingCodes']);
        }
        
        if (!empty($product_data['matchingCodes'])) {
            update_post_meta($product_id, '_amrod_matching_codes', $product_data['matchingCodes']);
        }
        
        // Store promotion flag
        if (isset($product_data['promotion'])) {
            update_post_meta($product_id, '_amrod_promotion', $product_data['promotion']);
        }

        $dimension_details = $this->extract_dimension_details($product_data);
        if (!empty($dimension_details)) {
            update_post_meta($product_id, '_amrod_dimension_details', $dimension_details);
        } else {
            delete_post_meta($product_id, '_amrod_dimension_details');
        }

        $this->sync_product_flag_terms($product_id, $product_data);
        
        // Store last sync timestamp
        update_post_meta($product_id, '_amrod_last_sync', current_time('mysql'));
    }

    /**
     * Build dimension and packaging details structure for storage
     */
    private function extract_dimension_details($product_data) {
        $details = array();

        if (!empty($product_data['productDimension']) && is_array($product_data['productDimension'])) {
            $product_dimension = $this->sanitize_product_dimension($product_data['productDimension']);
            if (!empty($product_dimension)) {
                $details['product'] = $product_dimension;
            }
        }

        if (!empty($product_data['packagingAndDimension']) && is_array($product_data['packagingAndDimension'])) {
            $packaging_dimension = $this->sanitize_packaging_dimension($product_data['packagingAndDimension']);
            if (!empty($packaging_dimension)) {
                $details['packaging'] = $packaging_dimension;
            }
        }

        if (!empty($product_data['variants']) && is_array($product_data['variants'])) {
            $variant_details = array();

            foreach ($product_data['variants'] as $variant) {
                if (!is_array($variant)) {
                    continue;
                }

                $variant_entry = array();

                if (!empty($variant['fullCode'])) {
                    $variant_entry['code'] = sanitize_text_field($variant['fullCode']);
                } elseif (!empty($variant['simpleCode'])) {
                    $variant_entry['code'] = sanitize_text_field($variant['simpleCode']);
                }

                if (!empty($variant['codeSizeName'])) {
                    $variant_entry['size'] = sanitize_text_field($variant['codeSizeName']);
                } elseif (!empty($variant['codeSize'])) {
                    $variant_entry['size'] = sanitize_text_field($variant['codeSize']);
                }

                if (!empty($variant['codeColourName'])) {
                    $variant_entry['colour'] = sanitize_text_field($variant['codeColourName']);
                } elseif (!empty($variant['codeColour'])) {
                    $variant_entry['colour'] = sanitize_text_field($variant['codeColour']);
                }

                if (!empty($variant['productDimension']) && is_array($variant['productDimension'])) {
                    $product_dimension = $this->sanitize_product_dimension($variant['productDimension']);
                    if (!empty($product_dimension)) {
                        $variant_entry['product'] = $product_dimension;
                    }
                }

                if (!empty($variant['packagingAndDimension']) && is_array($variant['packagingAndDimension'])) {
                    $packaging_dimension = $this->sanitize_packaging_dimension($variant['packagingAndDimension']);
                    if (!empty($packaging_dimension)) {
                        $variant_entry['packaging'] = $packaging_dimension;
                    }
                }

                if (!empty($variant_entry)) {
                    $variant_details[] = $variant_entry;
                }
            }

            if (!empty($variant_details)) {
                $details['variants'] = $variant_details;
            }
        }

        return $details;
    }

    /**
     * Normalize product dimension values
     */
    private function sanitize_product_dimension($dimension_data) {
        $dimension_map = array(
            'length' => 'length',
            'width' => 'width',
            'height' => 'height',
            'depth' => 'depth',
            'weight' => 'weight',
        );

        return $this->sanitize_dimension_values($dimension_data, $dimension_map);
    }

    /**
     * Normalize packaging dimension values
     */
    private function sanitize_packaging_dimension($dimension_data) {
        $dimension_map = array(
            'cartonSizeDimensionL' => 'length',
            'cartonSizeDimensionW' => 'width',
            'cartonSizeDimensionH' => 'height',
            'cartonWeight' => 'weight',
        );

        $values = $this->sanitize_dimension_values($dimension_data, $dimension_map);

        if (isset($dimension_data['piecesPerCarton']) && $dimension_data['piecesPerCarton'] !== '') {
            $values['pieces_per_carton'] = (int) $dimension_data['piecesPerCarton'];
        }

        return $values;
    }

    /**
     * Sanitize individual dimension datasets
     */
    private function sanitize_dimension_values($dimension_data, $dimension_map) {
        $values = array();

        foreach ($dimension_map as $source_key => $target_key) {
            if (isset($dimension_data[$source_key]) && $dimension_data[$source_key] !== '') {
                $values[$target_key] = $this->sanitize_dimension_value($dimension_data[$source_key]);
            }
        }

        return $values;
    }

    /**
     * Sanitize a single numeric dimension value
     */
    private function sanitize_dimension_value($value) {
        if (is_numeric($value)) {
            return round((float) $value, 4);
        }

        return sanitize_text_field($value);
    }

    /**
     * Sync behaviour and promotion taxonomy terms on the product
     */
    private function sync_product_flag_terms($product_id, $product_data) {
        $behaviour_taxonomy = 'amrod_product_behaviour';
        $promotion_taxonomy = 'amrod_product_promotion';

        $behaviour_map = array(
            '0' => array('slug' => 'normal', 'name' => __('Normal', 'bytemash-woo-sync')),
            '1' => array('slug' => 'featured', 'name' => __('Featured', 'bytemash-woo-sync')),
            '2' => array('slug' => 'hidden', 'name' => __('Hidden', 'bytemash-woo-sync')),
        );

        $promotion_map = array(
            '0' => array('slug' => 'normal', 'name' => __('Normal', 'bytemash-woo-sync')),
            '1' => array('slug' => 'promotion', 'name' => __('On Promotion', 'bytemash-woo-sync')),
            '2' => array('slug' => 'new', 'name' => __('New', 'bytemash-woo-sync')),
            '3' => array('slug' => 'clearance', 'name' => __('Clearance', 'bytemash-woo-sync')),
        );

        // Handle behaviour term assignment
        if (taxonomy_exists($behaviour_taxonomy)) {
            $behaviour_value = isset($product_data['behaviour']) ? (string) $product_data['behaviour'] : '';
            $term_id = 0;

            if ($behaviour_value !== '' && isset($behaviour_map[$behaviour_value])) {
                $behaviour_term = $behaviour_map[$behaviour_value];
                $term = get_term_by('slug', $behaviour_term['slug'], $behaviour_taxonomy);

                if (!$term) {
                    $created = wp_insert_term($behaviour_term['name'], $behaviour_taxonomy, array('slug' => $behaviour_term['slug']));
                    if (!is_wp_error($created)) {
                        $term_id = (int) $created['term_id'];
                    } else {
                        $this->logger->log('error', 'Failed to create behaviour term', array(
                            'product_id' => $product_id,
                            'slug' => $behaviour_term['slug'],
                            'error' => $created->get_error_message(),
                        ), 'product_sync');
                    }
                } else {
                    $term_id = (int) $term->term_id;
                }
            } elseif ($behaviour_value !== '' && !isset($behaviour_map[$behaviour_value])) {
                $this->logger->log('warning', 'Unknown behaviour flag encountered', array(
                    'product_id' => $product_id,
                    'behaviour' => $behaviour_value,
                ), 'product_sync');
            }

            if ($term_id > 0) {
                wp_set_object_terms($product_id, array($term_id), $behaviour_taxonomy, false);
            } else {
                wp_set_object_terms($product_id, array(), $behaviour_taxonomy);
            }
        } else {
            $this->logger->log('warning', 'Behaviour taxonomy missing; skipping behaviour flag sync', array(
                'product_id' => $product_id,
            ), 'product_sync');
        }

        // Handle promotion term assignment
        if (taxonomy_exists($promotion_taxonomy)) {
            $promotion_value = isset($product_data['promotion']) ? (string) $product_data['promotion'] : '';
            $term_id = 0;

            if ($promotion_value !== '' && isset($promotion_map[$promotion_value])) {
                $promotion_term = $promotion_map[$promotion_value];
                $term = get_term_by('slug', $promotion_term['slug'], $promotion_taxonomy);

                if (!$term) {
                    $created = wp_insert_term($promotion_term['name'], $promotion_taxonomy, array('slug' => $promotion_term['slug']));
                    if (!is_wp_error($created)) {
                        $term_id = (int) $created['term_id'];
                    } else {
                        $this->logger->log('error', 'Failed to create promotion term', array(
                            'product_id' => $product_id,
                            'slug' => $promotion_term['slug'],
                            'error' => $created->get_error_message(),
                        ), 'product_sync');
                    }
                } else {
                    $term_id = (int) $term->term_id;
                }
            } elseif ($promotion_value !== '' && !isset($promotion_map[$promotion_value])) {
                $this->logger->log('warning', 'Unknown promotion flag encountered', array(
                    'product_id' => $product_id,
                    'promotion' => $promotion_value,
                ), 'product_sync');
            }

            if ($term_id > 0) {
                wp_set_object_terms($product_id, array($term_id), $promotion_taxonomy, false);
            } else {
                wp_set_object_terms($product_id, array(), $promotion_taxonomy);
            }
        } else {
            $this->logger->log('warning', 'Promotion taxonomy missing; skipping promotion flag sync', array(
                'product_id' => $product_id,
            ), 'product_sync');
        }

        // Sync gender taxonomy
        $gender_value = '';
        if (!empty($product_data['gender'])) {
            $gender_value = sanitize_text_field($product_data['gender']);
        } else {
            $gender_value = sanitize_text_field(get_post_meta($product_id, '_amrod_gender', true));
        }
        $this->assign_taxonomy_terms($product_id, 'amrod_product_gender', $gender_value ? array($gender_value) : array());

        // Sync colour taxonomy
        $colour_names = array();
        if (!empty($product_data['variants']) && is_array($product_data['variants'])) {
            foreach ($product_data['variants'] as $variant) {
                if (!is_array($variant)) {
                    continue;
                }
                if (!empty($variant['codeColourName'])) {
                    $colour_names[] = $variant['codeColourName'];
                } elseif (!empty($variant['codeColour'])) {
                    $colour_names[] = $variant['codeColour'];
                }
            }
        }
        if (empty($colour_names) && !empty($product_data['colourImages']) && is_array($product_data['colourImages'])) {
            foreach ($product_data['colourImages'] as $colour) {
                if (is_array($colour) && !empty($colour['name'])) {
                    $colour_names[] = $colour['name'];
                }
            }
        }
        if (empty($colour_names)) {
            $color_mapping = get_post_meta($product_id, '_amrod_color_mapping', true);
            if (is_array($color_mapping)) {
                $colour_names = array_keys($color_mapping);
            }
        }
        $this->assign_taxonomy_terms($product_id, 'amrod_product_color', $colour_names);

        // Sync size taxonomy
        $size_names = array();
        if (!empty($product_data['variants']) && is_array($product_data['variants'])) {
            foreach ($product_data['variants'] as $variant) {
                if (!is_array($variant)) {
                    continue;
                }
                if (!empty($variant['codeSizeName'])) {
                    $size_names[] = $variant['codeSizeName'];
                } elseif (!empty($variant['codeSize'])) {
                    $size_names[] = $variant['codeSize'];
                }
            }
        }
        if (empty($size_names)) {
            $dimension_details = get_post_meta($product_id, '_amrod_dimension_details', true);
            if (is_array($dimension_details) && !empty($dimension_details['variants'])) {
                foreach ($dimension_details['variants'] as $variant_detail) {
                    if (is_array($variant_detail) && !empty($variant_detail['size'])) {
                        $size_names[] = $variant_detail['size'];
                    }
                }
            }
        }
        $this->assign_taxonomy_terms($product_id, 'amrod_product_size', $size_names);
    }

    /**
     * Ensure taxonomy terms exist and are assigned to product
     *
     * @param int    $product_id Product ID
     * @param string $taxonomy   Taxonomy name
     * @param array  $names      Array of term names (strings)
     */
    private function assign_taxonomy_terms($product_id, $taxonomy, $names) {
        if (!taxonomy_exists($taxonomy)) {
            return;
        }

        $unique_terms = array();
        foreach ((array) $names as $name) {
            $clean_name = trim(wp_strip_all_tags($name));
            if ($clean_name === '') {
                continue;
            }
            $key = strtolower($clean_name);
            if (!isset($unique_terms[$key])) {
                $unique_terms[$key] = $clean_name;
            }
        }

        if (empty($unique_terms)) {
            wp_set_object_terms($product_id, array(), $taxonomy);
            return;
        }

        $term_ids = array();
        foreach ($unique_terms as $clean_name) {
            $slug = sanitize_title($clean_name);
            if ($slug === '') {
                continue;
            }

            $term = term_exists($slug, $taxonomy);

            if (!$term) {
                $created = wp_insert_term($clean_name, $taxonomy, array('slug' => $slug));
                if (is_wp_error($created)) {
                    if ($created->get_error_code() === 'term_exists') {
                        $existing_id = $created->get_error_data('term_exists');
                        if ($existing_id) {
                            $term_ids[] = (int) $existing_id;
                        }
                    } else {
                        $this->logger->log('error', 'Failed to create taxonomy term', array(
                            'taxonomy' => $taxonomy,
                            'name' => $clean_name,
                            'error' => $created->get_error_message(),
                        ), 'product_sync');
                    }
                    continue;
                }
                $term_ids[] = (int) $created['term_id'];
            } else {
                $term_ids[] = (int) (is_array($term) ? $term['term_id'] : $term);
            }
        }

        if (empty($term_ids)) {
            wp_set_object_terms($product_id, array(), $taxonomy);
            return;
        }

        wp_set_object_terms($product_id, $term_ids, $taxonomy);
    }
    
    /**
     * Sync all stock levels (uses batch processor)
     * 
     * @return array Result with sync_id
     */
    public function sync_stock_levels() {
        $this->logger->log('info', 'Starting full stock sync', array(), 'stock_sync');
        
        // Fetch all stock from Amrod
        $stock_data = $this->api_client->get_stock();
        
        if (is_wp_error($stock_data)) {
            $this->logger->log('error', 'Failed to fetch stock', array(
                'error' => $stock_data->get_error_message(),
            ), 'stock_sync');
            return array('success' => false, 'message' => $stock_data->get_error_message());
        }
        
        if (!is_array($stock_data) || empty($stock_data)) {
            return array('success' => false, 'message' => 'No stock data available');
        }
        
        $total = count($stock_data);
        $sync_id = 'stock_' . time() . '_' . wp_generate_password(8, false);
        
        // Split into batches (larger batches for stock - simpler data)
        $batches = array_chunk($stock_data, 100);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(), 'stock_sync');
        
        // Just store minimal sync info
        update_option("bytemash_sync_{$sync_id}", array(
            'type' => 'stock',
            'total' => $total,
            'batch_count' => $batch_count,
            'batch_size' => 100,
            'current_batch' => 0,
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'status' => 'ready',
            'started' => current_time('mysql'),
        ), false);
        
        return array(
            'success' => true,
            'message' => "Ready to sync {$total} stock items in {$batch_count} batches",
            'sync_id' => $sync_id,
            'total' => $total,
            'batch_count' => $batch_count,
            'batches' => $batches,
        );
    }
    
    /**
     * Pre-warm SKU cache for stock sync to dramatically speed up lookups
     * Loads all relevant SKUs in bulk queries instead of individual lookups
     * 
     * @param array $stock_data Array of stock items
     */
    private function prewarm_sku_cache_for_stock($stock_data) {
        if (empty($stock_data) || !is_array($stock_data)) {
            return;
        }
        
        // Extract all unique SKUs from stock data
        $all_skus = array();
        foreach ($stock_data as $item) {
            $simple_code = $item['simpleCode'] ?? $item['simplecode'] ?? '';
            $full_code = $item['fullCode'] ?? '';
            
            if ($simple_code) {
                $all_skus[] = $simple_code;
            }
            if ($full_code && $full_code !== $simple_code) {
                $all_skus[] = $full_code;
            }
            // Also try fullCode without "-0-0" suffix
            if ($full_code && preg_match('/-0-0$/', $full_code)) {
                $all_skus[] = preg_replace('/-0-0$/', '', $full_code);
            }
        }
        
        $all_skus = array_unique(array_filter($all_skus));
        if (empty($all_skus)) {
            return;
        }
        
        // Batch load all SKU mappings in one query (MUCH faster than individual lookups)
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($all_skus), '%s'));
        $query = $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value as sku, p.post_type
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_sku' 
            AND pm.meta_value IN ($placeholders)
            AND p.post_status != 'trash'",
            ...$all_skus
        );
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        // Also check Amrod-specific meta keys for additional matches
        $amrod_query = $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value as code, pm.meta_key
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key IN ('_amrod_simple_code', '_amrod_full_code')
            AND pm.meta_value IN ($placeholders)
            AND p.post_status != 'trash'",
            ...$all_skus
        );
        
        $amrod_results = $wpdb->get_results($amrod_query, ARRAY_A);
        
        // Build cache map
        if (!$this->batch_mode) {
            $this->batch_mode = true;
            $this->sku_cache = array();
        }
        
        // Cache standard SKU lookups
        foreach ($results as $row) {
            $sku = $row['sku'];
            $pid = (int) $row['post_id'];
            $type = $row['post_type'];
            
            if (!isset($this->sku_cache[$sku])) {
                $this->sku_cache[$sku] = array(
                    'product_id' => $pid,
                    'type' => $type,
                );
            }
        }
        
        // Cache Amrod code lookups
        foreach ($amrod_results as $row) {
            $code = $row['code'];
            $pid = (int) $row['post_id'];
            $key = $row['meta_key'];
            
            // Map Amrod codes to SKU cache for faster lookups
            if (!isset($this->sku_cache[$code])) {
                $this->sku_cache[$code] = array(
                    'product_id' => $pid,
                    'type' => 'product',
                    'source' => $key,
                );
            }
        }
        
        $cached_count = count($this->sku_cache);
        $this->logger->log('info', "Pre-warmed SKU cache with {$cached_count} entries", array(
            'unique_skus' => count($all_skus),
            'cached_entries' => $cached_count,
        ), 'stock_sync');
    }
    
    /**
     * Sync updated stock levels only (incremental)
     * 
     * @return array Result
     */
    public function sync_stock_updated() {
        $this->logger->log('info', 'Starting incremental stock sync', array(), 'stock_sync');
        
        // Fetch updated stock only
        $stock_data = $this->api_client->get_stock_updated();
            
        if (is_wp_error($stock_data)) {
            return array('success' => false, 'message' => $stock_data->get_error_message());
        }
        
        if (!is_array($stock_data) || empty($stock_data)) {
            return array('success' => true, 'message' => 'No stock updates available', 'total' => 0);
        }
        
        $total = count($stock_data);
        $sync_id = 'stock_update_' . time() . '_' . wp_generate_password(8, false);
        
        // Split into batches (same as full stock sync)
        $batches = array_chunk($stock_data, 100);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(), 'stock_sync');
        
        // Store minimal sync info
        update_option("bytemash_sync_{$sync_id}", array(
            'type' => 'stock',
            'total' => $total,
            'batch_count' => $batch_count,
            'batch_size' => 100,
            'current_batch' => 0,
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'status' => 'ready',
            'started' => current_time('mysql'),
        ), false);
        
        return array(
            'success' => true,
            'message' => "Ready to sync {$total} stock updates in {$batch_count} batches",
            'sync_id' => $sync_id,
            'total' => $total,
            'batch_count' => $batch_count,
            'batches' => $batches,
        );
    }
    
    /**
     * Sync all prices (uses batch processor)
     * 
     * @return array Result with sync_id
     */
    public function sync_prices() {
        $this->logger->log('info', 'Starting full price sync', array(), 'price_sync');
        
        // Fetch all prices from Amrod
        $prices_data = $this->api_client->get_prices();
        
        if (is_wp_error($prices_data)) {
            $this->logger->log('error', 'Failed to fetch prices', array(
                'error' => $prices_data->get_error_message(),
            ), 'price_sync');
            return array('success' => false, 'message' => $prices_data->get_error_message());
        }
        
        if (!is_array($prices_data) || empty($prices_data)) {
            return array('success' => false, 'message' => 'No price data available');
        }
        
        $total = count($prices_data);
        $sync_id = 'prices_' . time() . '_' . wp_generate_password(8, false);
        
        // Split into batches (OPTIMIZED: 500 items per batch for lightning-fast performance)
        $batches = array_chunk($prices_data, 500);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(), 'price_sync');
        
        // Just store minimal sync info
        update_option("bytemash_sync_{$sync_id}", array(
            'type' => 'prices',
            'total' => $total,
            'batch_count' => $batch_count,
            'batch_size' => 500,
            'current_batch' => 0,
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'status' => 'ready',
            'started' => current_time('mysql'),
        ), false);
        
            return array(
                'success' => true,
            'message' => "Ready to sync {$total} prices in {$batch_count} batches",
                'sync_id' => $sync_id,
                'total' => $total,
            'batch_count' => $batch_count,
            'batches' => $batches,
            'data' => $prices_data,
            );
    }
    
    /**
     * Sync updated prices only (incremental)
     * 
     * @return array Result
     */
    public function sync_prices_updated() {
        $this->logger->log('info', 'Starting incremental price sync', array(), 'price_sync');
        
        // Fetch updated prices only
        $prices_data = $this->api_client->get_prices_updated();
        
        if (is_wp_error($prices_data)) {
            return array('success' => false, 'message' => $prices_data->get_error_message());
        }
        
        if (!is_array($prices_data) || empty($prices_data)) {
            return array('success' => true, 'message' => 'No price updates available', 'total' => 0);
        }
        
        $total = count($prices_data);
        $sync_id = 'prices_update_' . time() . '_' . wp_generate_password(8, false);
        
        // Split into batches (OPTIMIZED: 500 items per batch for lightning-fast performance)
        $batches = array_chunk($prices_data, 500);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(), 'price_sync');
        
        // Store minimal sync info
        update_option("bytemash_sync_{$sync_id}", array(
            'type' => 'prices',
            'total' => $total,
            'batch_count' => $batch_count,
            'batch_size' => 500,
            'current_batch' => 0,
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'status' => 'ready',
            'started' => current_time('mysql'),
        ), false);
        
            return array(
                'success' => true,
            'message' => "Ready to sync {$total} price updates in {$batch_count} batches",
                'sync_id' => $sync_id,
                'total' => $total,
            'batch_count' => $batch_count,
            'batches' => $batches,
            'data' => $prices_data,
            );
    }
    
    /**
     * Sync updated categories only (incremental)
     * 
     * @return array Result
     */
    public function sync_categories_updated() {
        $this->logger->log('info', 'Starting incremental category sync', array(), 'category_sync');
        
        // Fetch updated categories only
        $categories_data = $this->api_client->get_categories_updated();
        
        if (is_wp_error($categories_data)) {
            return array('success' => false, 'message' => $categories_data->get_error_message());
        }
        
        if (!is_array($categories_data) || empty($categories_data)) {
            return array('success' => true, 'message' => 'No category updates available', 'total' => 0);
        }
        
        $total = count($categories_data);
        $sync_id = 'categories_update_' . time() . '_' . wp_generate_password(8, false);
        
        // Split into batches
        $batches = array_chunk($categories_data, 50);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(
            'total_items' => $total,
            'batch_size' => 50,
            'batch_count' => $batch_count,
        ), 'category_sync');
        
        // Store minimal sync info
        update_option("bytemash_sync_{$sync_id}", array(
            'type' => 'categories',
            'total' => $total,
            'batch_count' => $batch_count,
            'batch_size' => 50,
            'current_batch' => 0,
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'status' => 'ready',
            'started' => current_time('mysql'),
        ), false);
        
        return array(
            'success' => true,
            'message' => "Ready to sync {$total} category updates in {$batch_count} batches",
            'sync_id' => $sync_id,
            'total' => $total,
            'batch_count' => $batch_count,
            'batches' => $batches,
        );
    }
    
    /**
     * Sync updated brands only (incremental)
     * 
     * @return array Result
     */
    public function sync_brands_updated() {
        $this->logger->log('info', 'Starting incremental brand sync', array(), 'brand_sync');
        
        // Fetch updated brands only
        $brands_data = $this->api_client->get_brands_updated();
        
        if (is_wp_error($brands_data)) {
            return array('success' => false, 'message' => $brands_data->get_error_message());
        }
        
        if (!is_array($brands_data) || empty($brands_data)) {
            return array('success' => true, 'message' => 'No brand updates available', 'total' => 0);
        }
        
        $total = count($brands_data);
        $sync_id = 'brands_update_' . time() . '_' . wp_generate_password(8, false);
        
        // Split into batches
        $batches = array_chunk($brands_data, 50);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(
            'total_items' => $total,
            'batch_size' => 50,
            'batch_count' => $batch_count,
        ), 'brand_sync');
        
        // Store minimal sync info
        update_option("bytemash_sync_{$sync_id}", array(
            'type' => 'brands',
            'total' => $total,
            'batch_count' => $batch_count,
            'batch_size' => 50,
            'current_batch' => 0,
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'status' => 'ready',
            'started' => current_time('mysql'),
        ), false);
        
        return array(
            'success' => true,
            'message' => "Ready to sync {$total} brand updates in {$batch_count} batches",
            'sync_id' => $sync_id,
            'total' => $total,
            'batch_count' => $batch_count,
            'batches' => $batches,
        );
    }
    
    /**
     * Update stock for a single product
     */
    public function update_single_stock($stock_item) {
        // Stock item structure: {simpleCode/simplecode, fullCode, stock}
        // Note: API sometimes uses 'simplecode' (lowercase) and sometimes 'simpleCode' (camelCase)
        $fullCode = $stock_item['fullCode'] ?? '';
        $simpleCode = $stock_item['simpleCode'] ?? $stock_item['simplecode'] ?? '';
        
        if (empty($fullCode) && empty($simpleCode)) {
            return array('success' => false, 'message' => 'No SKU in stock data');
        }
        
        // Try multiple SKU variations (products might be stored with different formats)
        $skus_to_try = array_filter(array(
            $fullCode,                              // Try full code first (e.g., "AF-AM-7-D-0-0")
            $simpleCode,                            // Try simple code (e.g., "AF-AM-7-D")
            preg_replace('/-0-0$/', '', $fullCode) // Try fullCode without "-0-0" suffix
        ));
        
        $this->logger->log('info', '🔍 Attempting to match stock SKU', array(), 'stock_sync');
        
        $product_ids = array();
        $matched_sku = '';
        $exact_match_found = false;
        
        // Try exact matches first
        foreach ($skus_to_try as $sku) {
            $product_id = wc_get_product_id_by_sku($sku);
            if ($product_id) {
                $product_ids[] = $product_id;
                $matched_sku = $sku;
                $exact_match_found = true;
                $this->logger->log('success', "✅ Exact SKU matched: {$sku}", array(), 'stock_sync');
                break;
            }
        }
        
        // ALWAYS try pattern matching with simpleCode to catch all variants
        // Example: Even if "ALT-1603" exists, also update "ALT-1603-Y", "ALT-1603-R", etc.
        if (!empty($simpleCode)) {
            global $wpdb;
            $like_pattern = $wpdb->esc_like($simpleCode) . '%';
            
            $matching_products = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value as sku FROM {$wpdb->postmeta} 
                WHERE meta_key = '_sku' AND meta_value LIKE %s",
                $like_pattern
            ));
            
            if ($matching_products) {
                $pattern_matched_count = 0;
                foreach ($matching_products as $match) {
                    // Avoid duplicates
                    if (!in_array($match->post_id, $product_ids)) {
                        $product_ids[] = $match->post_id;
                        $pattern_matched_count++;
                    }
                }
                
                if ($pattern_matched_count > 0) {
                    $matched_sku = $simpleCode . '*';
                    $log_msg = $exact_match_found 
                        ? "✅ Pattern matched {$pattern_matched_count} additional variant(s) with SKU starting with: {$simpleCode}"
                        : "✅ Pattern matched {$pattern_matched_count} product(s) with SKU starting with: {$simpleCode}";
                    
                    $this->logger->log('success', $log_msg, array(), 'stock_sync');
                }
            }
        }
        
        if (empty($product_ids)) {
            $attempted = implode(', ', $skus_to_try);
            $this->logger->log('warning', "⚠️ No SKU match found", array(), 'stock_sync');
            return array('success' => false, 'message' => "Product not found. Tried SKUs: {$attempted}, Pattern: {$simpleCode}%");
        }
        
        // Update all matched products
        $updated_count = 0;
        $failed_count = 0;
        $stock_qty = isset($stock_item['stock']) ? (int) $stock_item['stock'] : 0;
        $reserved_qty = isset($stock_item['reservedStock']) ? (int) $stock_item['reservedStock'] : 0;
        $incoming = array();
        if (!empty($stock_item['incomingStock']) && is_array($stock_item['incomingStock'])) {
            foreach ($stock_item['incomingStock'] as $inc) {
                $incoming[] = array(
                    'total' => isset($inc['total']) ? (int) $inc['total'] : 0,
                    'date' => isset($inc['date']) ? sanitize_text_field($inc['date']) : '',
                );
            }
        }
        $modified = isset($stock_item['modifiedDate']) ? sanitize_text_field($stock_item['modifiedDate']) : '';
        $stock_type = isset($stock_item['stockType']) ? (int) $stock_item['stockType'] : 0;
        
        foreach ($product_ids as $pid) {
            try {
                $product = wc_get_product($pid);
                
                if (!$product) {
                    $failed_count++;
                    $this->logger->log('warning', 'Product not found for stock update', array(
                        'product_id' => $pid,
                        'sku' => $matched_sku,
                    ), 'product_sync');
                    continue;
                }
                
                // For variable products, handle stock differently based on stockType
                if ($product->is_type('variable')) {
                    $this->update_variable_product_stock($product, $stock_item, $stock_qty, $reserved_qty, $incoming, $modified, $stock_type);
                } else {
                    // Simple product - update directly
                $product->set_manage_stock(true);
                $product->set_stock_quantity($stock_qty);
                $product->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');
                $this->save_product_safely($product);
                    
                    // Store detailed stock breakdown
                    $detail = array(
                        'stock' => $stock_qty,
                        'reserved' => $reserved_qty,
                        'incoming' => $incoming,
                        'modified' => $modified,
                    );
                    update_post_meta($pid, '_amrod_stock_detail', $detail);
                }
                
                $updated_count++;
                
                $this->logger->log('info', 'Stock updated successfully', array(
                    'product_id' => $pid,
                    'sku' => $product->get_sku(),
                    'stock_qty' => $stock_qty,
                    'product_type' => $product->get_type(),
                ), 'product_sync');
                
            } catch (Exception $e) {
                $failed_count++;
                $this->logger->log('error', 'Stock update failed', array(
                    'product_id' => $pid,
                    'sku' => $matched_sku,
                    'stock_qty' => $stock_qty,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ), 'product_sync');
            }
        }
        
        if ($updated_count === 0) {
            return array('success' => false, 'message' => "Failed to update any products");
        }
        
        $message = $updated_count > 1 ? " ({$updated_count} variants)" : "";
        return array('success' => true, 'sku' => $matched_sku, 'stock' => $stock_qty, 'updated_count' => $updated_count, 'message' => $message);
    }
    
    /**
     * Update price for a single product
     */
    public function update_single_price($price_item) {
        // Price item structure: {simpleCode/simplecode, fullCode, price, salePrice}
        // Note: API sometimes uses 'simplecode' (lowercase) and sometimes 'simpleCode' (camelCase)
        $fullCode = $price_item['fullCode'] ?? '';
        $simpleCode = $price_item['simpleCode'] ?? $price_item['simplecode'] ?? '';
        
        if (empty($fullCode) && empty($simpleCode)) {
            return array('success' => false, 'message' => 'No SKU in price data');
        }
        
        // Try multiple SKU variations (products might be stored with different formats)
        $skus_to_try = array_filter(array(
            $fullCode,                              // Try full code first (e.g., "AF-AM-7-D-0-0")
            $simpleCode,                            // Try simple code (e.g., "AF-AM-7-D")
            preg_replace('/-0-0$/', '', $fullCode) // Try fullCode without "-0-0" suffix
        ));
        
        $this->logger->log('info', '🔍 Attempting to match price SKU', array(), 'price_sync');
        
        $product_ids = array();
        $matched_sku = '';
        $exact_match_found = false;
        
        // Try exact matches first
        foreach ($skus_to_try as $sku) {
            $product_id = $this->get_product_id_by_sku_cached($sku);
            if ($product_id) {
                $product_ids[] = $product_id;
                $matched_sku = $sku;
                $exact_match_found = true;
                $this->logger->log('success', "✅ Exact SKU matched: {$sku}", array(), 'price_sync');
                break;
            }
        }
        
        // ALWAYS try pattern matching with simpleCode to catch all variants
        // Example: Even if "ALT-1603" exists, also update "ALT-1603-Y", "ALT-1603-R", etc.
        if (!empty($simpleCode)) {
            global $wpdb;
            $like_pattern = $wpdb->esc_like($simpleCode) . '%';
            
            $matching_products = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value as sku FROM {$wpdb->postmeta} 
                WHERE meta_key = '_sku' AND meta_value LIKE %s",
                $like_pattern
            ));
            
            if ($matching_products) {
                $pattern_matched_count = 0;
                foreach ($matching_products as $match) {
                    // Avoid duplicates
                    if (!in_array($match->post_id, $product_ids)) {
                        $product_ids[] = $match->post_id;
                        $pattern_matched_count++;
                    }
                }
                
                if ($pattern_matched_count > 0) {
                    $matched_sku = $simpleCode . '*';
                    $log_msg = $exact_match_found 
                        ? "✅ Pattern matched {$pattern_matched_count} additional variant(s) with SKU starting with: {$simpleCode}"
                        : "✅ Pattern matched {$pattern_matched_count} product(s) with SKU starting with: {$simpleCode}";
                    
                    $this->logger->log('success', $log_msg, array(), 'price_sync');
                }
            }
        }
        
        if (empty($product_ids)) {
            $attempted = implode(', ', $skus_to_try);
            $this->logger->log('warning', "⚠️ No SKU match found", array(), 'price_sync');
            return array('success' => false, 'message' => "Product not found. Tried SKUs: {$attempted}, Pattern: {$simpleCode}%");
        }
        
        // Update all matched products
        $updated_count = 0;
        $failed_count = 0;
        
        foreach ($product_ids as $pid) {
            try {
                $product = wc_get_product($pid);
                
                if (!$product) {
                    $failed_count++;
                    $this->logger->log('warning', 'Product not found for price update', array(
                        'product_id' => $pid,
                        'sku' => $matched_sku,
                    ), 'product_sync');
                    continue;
                }
                
                // Update prices
                if (isset($price_item['price'])) {
                    $product->set_regular_price($price_item['price']);
                }
                
                if (isset($price_item['salePrice']) && $price_item['salePrice'] > 0) {
                    $product->set_sale_price($price_item['salePrice']);
                }
                
                $this->save_product_safely($product);
                $updated_count++;
                
                $this->logger->log('info', 'Price updated successfully', array(
                    'product_id' => $pid,
                    'sku' => $product->get_sku(),
                    'regular_price' => $price_item['price'] ?? 'not set',
                    'sale_price' => $price_item['salePrice'] ?? 'not set',
                ), 'product_sync');
            } catch (Exception $e) {
                $failed_count++;
                $this->logger->log('error', 'Price update failed', array(
                    'product_id' => $pid,
                    'sku' => $matched_sku,
                    'regular_price' => $price_item['price'] ?? 'not set',
                    'sale_price' => $price_item['salePrice'] ?? 'not set',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ), 'product_sync');
            }
        }
        
        if ($updated_count === 0) {
            return array('success' => false, 'message' => "Failed to update any products");
        }
        
        $message = $updated_count > 1 ? " ({$updated_count} variants)" : "";
        return array('success' => true, 'sku' => $matched_sku, 'price' => $price_item['price'] ?? 0, 'updated_count' => $updated_count, 'message' => $message);
    }
    
    /**
     * Sync orphan products - products without prices using prefix matching
     * This is Phase 2 of price sync - run AFTER normal price sync
     */
    public function sync_orphan_product_prices() {
        $this->logger->log('info', 'Starting orphan product price sync (Phase 2)', array(), 'price_sync_orphan');
        
        // Step 1: Find all orphan products (products without prices)
        global $wpdb;
        $orphan_products = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value as sku 
            FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->postmeta} price ON pm.post_id = price.post_id AND price.meta_key = '_price'
            WHERE pm.meta_key = '_sku' 
            AND (price.meta_value IS NULL OR price.meta_value = '' OR price.meta_value = '0')
            LIMIT 5000"
        );
        
        if (empty($orphan_products)) {
            $this->logger->log('info', 'No orphan products found', array(), 'price_sync_orphan');
            return array('success' => true, 'message' => 'No products without prices found', 'total' => 0);
        }
        
        $total = count($orphan_products);
        $this->logger->log('info', "Found {$total} orphan products without prices", array(), 'price_sync_orphan');
        
        // Step 2: Fetch all prices for matching
        $prices_data = $this->api_client->get_prices();
        
        if (is_wp_error($prices_data)) {
            return array('success' => false, 'message' => $prices_data->get_error_message());
        }
        
        if (!is_array($prices_data) || empty($prices_data)) {
            return array('success' => true, 'message' => 'No price data available', 'total' => 0);
        }
        
        // Step 3: Create batches of orphan products with price data
        $sync_id = 'orphan_prices_' . time() . '_' . wp_generate_password(8, false);
        
        $batches = array_chunk($orphan_products, 50); // Process 50 orphans per batch
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(), 'price_sync_orphan');
        
        // Store minimal sync info
        update_option("bytemash_sync_{$sync_id}", array(
            'type' => 'orphan_prices',
            'total' => $total,
            'batch_count' => $batch_count,
            'batch_size' => 50,
            'current_batch' => 0,
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'status' => 'ready',
            'started' => current_time('mysql'),
        ), false);
        
        // Store price data for reference during batch processing
        update_option("bytemash_sync_{$sync_id}_prices_lookup", $prices_data, false);
        
        return array(
            'success' => true,
            'message' => "Ready to match {$total} products without prices in {$batch_count} batches",
            'sync_id' => $sync_id,
            'total' => $total,
            'batch_count' => $batch_count,
            'batches' => $batches,
        );
    }
    
    /**
     * Process single orphan product - find matching price and update
     * Called during batch processing
     */
    public function update_single_orphan_product($orphan_data, $prices_lookup) {
        $product_id = $orphan_data->post_id;
        $sku = $orphan_data->sku;
        
        // Try to find matching price by extracting prefix from product SKU
        $prefix = $this->extract_sku_prefix($sku);
        
        if (strlen($prefix) < 6) {
            return array('success' => false, 'message' => "SKU prefix too short: {$sku}");
        }
        
        // Search for matching price in the lookup data
        $matched_price = null;
        
        foreach ($prices_lookup as $price_item) {
            $price_fullCode = $price_item['fullCode'] ?? '';
            $price_simpleCode = $price_item['simpleCode'] ?? $price_item['simplecode'] ?? '';
            
            $price_prefix = $this->extract_sku_prefix($price_fullCode ?: $price_simpleCode);
            
            // If prefixes match, use this price
            if ($price_prefix === $prefix) {
                $matched_price = $price_item;
                break;
            }
        }
        
        if (!$matched_price) {
            return array('success' => false, 'message' => "No price found for prefix: {$prefix}");
        }
        
        // Update product with matched price
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return array('success' => false, 'message' => "Failed to load product: {$sku}");
        }
        
        if (isset($matched_price['price'])) {
            $product->set_regular_price($matched_price['price']);
        }
        
        if (isset($matched_price['salePrice']) && $matched_price['salePrice'] > 0) {
            $product->set_sale_price($matched_price['salePrice']);
        }
        
        $product->save();
        
        $this->logger->log('success', "✅ Orphan matched: {$sku} → prefix: {$prefix} → price: " . ($matched_price['price'] ?? 0), array(), 'price_sync_orphan');
        
        return array('success' => true, 'sku' => $sku, 'price' => $matched_price['price'] ?? 0);
    }
    
    /**
     * Extract SKU prefix following ABC-123 pattern
     * Returns first 6-7 chars like "ALT-GCG" from "ALT-GCG-NT-32"
     */
    private function extract_sku_prefix($sku) {
        if (empty($sku)) {
            return '';
        }
        
        // Match pattern: 2-3 letters/numbers, dash, 2-3 letters/numbers
        // Examples: ALT-GCG, ABC-123, AM-7, SKN-8
        if (preg_match('/^([A-Z0-9]{2,3}-[A-Z0-9]{2,3})/', strtoupper($sku), $matches)) {
            return $matches[1];
        }
        
        // Fallback: return first 7 characters
        return strlen($sku) >= 7 ? substr(strtoupper($sku), 0, 7) : strtoupper($sku);
    }
    
    /**
     * Sync brands from Amrod
     * 
     * @return array Result
     */
    public function sync_brands() {
        $this->logger->log('info', 'Starting brands sync', array(), 'brands_sync');
        
        $brands = $this->api_client->get_brands();
        
        if (is_wp_error($brands)) {
            return array('success' => false, 'message' => $brands->get_error_message());
        }
        
        if (!is_array($brands) || empty($brands)) {
            return array('success' => true, 'message' => 'No brands available', 'total' => 0);
        }
        
        // Extract from 'value' wrapper if it exists (Amrod API returns {value: [...], Count: X})
        if (isset($brands['value']) && is_array($brands['value'])) {
            $brands = $brands['value'];
        }
        
        $total = count($brands);
        $sync_id = 'brands_' . time() . '_' . wp_generate_password(8, false);
        
        // Split into batches
        $batches = array_chunk($brands, 50);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(), 'brands_sync');
        
        // Store minimal sync info
        update_option("bytemash_sync_{$sync_id}", array(
            'type' => 'brands',
            'total' => $total,
            'batch_count' => $batch_count,
            'batch_size' => 50,
            'current_batch' => 0,
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'status' => 'ready',
            'started' => current_time('mysql'),
        ), false);
        
        return array(
            'success' => true,
            'message' => "Ready to sync {$total} brands in {$batch_count} batches",
            'sync_id' => $sync_id,
            'total' => $total,
            'batch_count' => $batch_count,
            'batches' => $batches,
            'data' => $brands,
        );
    }
    
    /**
     * Sync single brand
     */
    public function sync_single_brand($brand_data) {
        $brand_name = $brand_data['name'] ?? '';
        $brand_code = $brand_data['code'] ?? '';
        $brand_image = $brand_data['image'] ?? '';
        $brand_order = $brand_data['order'] ?? 99999;
        
        if (empty($brand_name)) {
            return array('success' => false, 'message' => 'Brand missing name');
        }
        
        // Store in options table
        update_option("amrod_brand_{$brand_code}", array(
            'name' => $brand_name,
            'code' => $brand_code,
            'image' => $brand_image,
            'order' => $brand_order,
        ));
        
        $this->logger->log('success', "Brand synced: {$brand_name}", array(), 'brands_sync');
        
        return array('success' => true, 'code' => $brand_code, 'name' => $brand_name);
    }
    
    /**
     * Sync branding departments
     * 
     * @return array Result
     */
    public function sync_branding_departments() {
        $this->logger->log('info', 'Starting branding departments sync', array(), 'branding_sync');
        
        $departments = $this->api_client->get_branding_departments();
        
        if (is_wp_error($departments)) {
            return array('success' => false, 'message' => $departments->get_error_message());
        }
        
        if (!is_array($departments) || empty($departments)) {
            return array('success' => true, 'message' => 'No branding departments available', 'total' => 0);
        }
        
        $total = count($departments);
        $sync_id = 'branding_depts_' . time() . '_' . wp_generate_password(8, false);
        
        // These are small, batch them anyway for consistency
        $batches = array_chunk($departments, 25);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(), 'branding_sync');
        
        update_option("bytemash_sync_{$sync_id}", array(
            'type' => 'branding_departments',
            'total' => $total,
            'batch_count' => $batch_count,
            'batch_size' => 25,
            'current_batch' => 0,
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'status' => 'ready',
            'started' => current_time('mysql'),
        ), false);
        
        return array(
            'success' => true,
            'message' => "Ready to sync {$total} branding departments in {$batch_count} batches",
            'sync_id' => $sync_id,
            'total' => $total,
            'batch_count' => $batch_count,
            'batches' => $batches,
        );
    }
    
    /**
     * Sync single branding department
     */
    public function sync_single_branding_department($dept_data) {
        $dept_name = $dept_data['name'] ?? '';
        $dept_code = $dept_data['code'] ?? '';
        
        if (empty($dept_name)) {
            return array('success' => false, 'message' => 'Department missing name');
        }
        
        // Store as individual option
        update_option("amrod_branding_dept_{$dept_code}", $dept_data);
        
        return array('success' => true, 'code' => $dept_code, 'name' => $dept_name);
    }
    
    /**
     * Sync branding prices
     * 
     * @return array Result
     */
    public function sync_branding_prices() {
        $this->logger->log('info', 'Starting branding prices sync', array(), 'branding_sync');
        
        $prices = $this->api_client->get_branding_prices();
        
        if (is_wp_error($prices)) {
            return array('success' => false, 'message' => $prices->get_error_message());
        }
        
        if (!is_array($prices) || empty($prices)) {
            return array('success' => true, 'message' => 'No branding prices available', 'total' => 0);
        }
        
        $total = count($prices);
        $sync_id = 'branding_prices_' . time() . '_' . wp_generate_password(8, false);
        
        $batches = array_chunk($prices, 25);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(), 'branding_sync');
        
        update_option("bytemash_sync_{$sync_id}", array(
            'type' => 'branding_prices',
            'total' => $total,
            'batch_count' => $batch_count,
            'batch_size' => 25,
            'current_batch' => 0,
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'status' => 'ready',
            'started' => current_time('mysql'),
        ), false);
        
        return array(
            'success' => true,
            'message' => "Ready to sync {$total} branding price groups in {$batch_count} batches",
            'sync_id' => $sync_id,
            'total' => $total,
            'batch_count' => $batch_count,
            'batches' => $batches,
        );
    }
    
    /**
     * Sync single branding price group
     */
    public function sync_single_branding_price($price_data) {
        $branding_code = $price_data['brandingCode'] ?? '';
        
        if (empty($branding_code)) {
            return array('success' => false, 'message' => 'Branding price missing code');
        }
        
        // Store as individual option
        update_option("amrod_branding_price_{$branding_code}", $price_data);
        
        return array('success' => true, 'code' => $branding_code);
    }
    
    /**
     * Sync inclusive brandings
     * 
     * @return array Result
     */
    public function sync_inclusive_brandings() {
        $this->logger->log('info', 'Starting inclusive brandings sync', array(), 'branding_sync');
        
        $brandings = $this->api_client->get_inclusive_brandings();
        
        if (is_wp_error($brandings)) {
            return array('success' => false, 'message' => $brandings->get_error_message());
        }
        
        if (!is_array($brandings) || empty($brandings)) {
            return array('success' => true, 'message' => 'No inclusive brandings available', 'total' => 0);
        }
        
        $total = count($brandings);
        $sync_id = 'inclusive_brandings_' . time() . '_' . wp_generate_password(8, false);
        
        $batches = array_chunk($brandings, 50);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(), 'branding_sync');
        
        update_option("bytemash_sync_{$sync_id}", array(
            'type' => 'inclusive_brandings',
            'total' => $total,
            'batch_count' => $batch_count,
            'batch_size' => 50,
            'current_batch' => 0,
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'status' => 'ready',
            'started' => current_time('mysql'),
        ), false);
        
        return array(
            'success' => true,
            'message' => "Ready to sync {$total} inclusive brandings in {$batch_count} batches",
            'sync_id' => $sync_id,
            'total' => $total,
            'batch_count' => $batch_count,
            'batches' => $batches,
        );
    }
    
    /**
     * Sync single inclusive branding
     */
    public function sync_single_inclusive_branding($branding_data) {
        $branding_id = $branding_data['inclusiveBrandingId'] ?? '';
        $simple_code = $branding_data['simpleCode'] ?? '';
        
        if (empty($branding_id)) {
            return array('success' => false, 'message' => 'Inclusive branding missing ID');
        }
        
        // Store as individual option
        update_option("amrod_inclusive_branding_{$branding_id}", $branding_data);
        
        return array('success' => true, 'id' => $branding_id, 'simple_code' => $simple_code);
    }
    
    /**
     * Sync color swatches from Amrod
     * 
     * @return array Result
     */
    public function sync_color_swatches() {
        $this->logger->log('info', 'Starting color swatches sync', array(), 'color_swatches_sync');
        
        $swatches = $this->api_client->get_colour_swatches();
        
        if (is_wp_error($swatches)) {
            return array('success' => false, 'message' => $swatches->get_error_message());
        }
        
        if (!is_array($swatches) || empty($swatches)) {
            return array('success' => true, 'message' => 'No color swatches available', 'total' => 0);
        }
        
        // Extract from 'value' wrapper if it exists (Amrod API returns {value: [...], Count: X})
        if (isset($swatches['value']) && is_array($swatches['value'])) {
            $swatches = $swatches['value'];
        }
        
        $total = count($swatches);
        $sync_id = 'color_swatches_' . time() . '_' . wp_generate_password(8, false);
        
        $batches = array_chunk($swatches, 50);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(), 'color_swatches_sync');
        
        update_option("bytemash_sync_{$sync_id}", array(
            'type' => 'color_swatches',
            'total' => $total,
            'batch_count' => $batch_count,
            'batch_size' => 50,
            'current_batch' => 0,
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'status' => 'ready',
            'started' => current_time('mysql'),
        ), false);
        
        return array(
            'success' => true,
            'message' => "Ready to sync {$total} color swatches in {$batch_count} batches",
            'sync_id' => $sync_id,
            'total' => $total,
            'batch_count' => $batch_count,
            'batches' => $batches,
        );
    }
    
    /**
     * Sync single color swatch
     */
    public function sync_single_color_swatch($swatch_data) {
        $color_name = $swatch_data['name'] ?? '';
        $color_code = $swatch_data['code'] ?? '';
        
        if (empty($color_name)) {
            return array('success' => false, 'message' => 'Color swatch missing name');
        }
        
        // Store as individual option with color code as key
        update_option("amrod_color_swatch_{$color_code}", $swatch_data);
        
        $this->logger->log('success', "Color swatch synced: {$color_name}", array(), 'color_swatches_sync');
        
        return array('success' => true, 'code' => $color_code, 'name' => $color_name);
    }
    
    /**
     * Normalize category path for consistent lookups
     *
     * @param string $path Original path
     * @return string Normalized path
     */
    private function normalize_category_path($path) {
        if ($path === null) {
            return '';
        }

        $normalized = trim((string) $path);

        if ($normalized === '') {
            return '';
        }

        // Lowercase and collapse whitespace for consistent matching
        $normalized = strtolower($normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return $normalized;
    }

    /**
     * Locate an existing WooCommerce category by Amrod path metadata
     *
     * @param string $path Original category path
     * @return WP_Term|null
     */
    private function get_category_term_by_path($path) {
        $normalized_path = $this->normalize_category_path($path);

        if ($normalized_path === '') {
            return null;
        }

        $terms = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'number' => 1,
            'meta_key' => '_amrod_category_path_normalized',
            'meta_value' => $normalized_path,
        ));

        if (!empty($terms) && !is_wp_error($terms)) {
            return $terms[0];
        }

        // Fallback for legacy data that only stored the raw path meta
        $terms = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'number' => 1,
            'meta_key' => '_amrod_category_path',
            'meta_value' => $path,
        ));

        if (!empty($terms) && !is_wp_error($terms)) {
            return $terms[0];
        }

        return null;
    }

    /**
     * Best-effort formatting for intermediate category segment names
     *
     * @param string $segment Path segment
     * @return string Human-friendly name
     */
    private function format_category_segment_name($segment) {
        $segment = trim((string) $segment);

        if ($segment === '') {
            return '';
        }

        // If the segment already contains uppercase characters, respect the casing
        if ($segment !== strtolower($segment)) {
            return preg_replace('/\s+/', ' ', $segment);
        }

        // Preserve hyphenated words while capitalising each word
        $segment = str_replace('-', ' - ', $segment);
        $segment = ucwords($segment);
        $segment = str_replace(' - ', '-', $segment);

        return preg_replace('/\s+/', ' ', $segment);
    }

    /**
     * Ensure full category hierarchy exists for a given path
     *
     * @param string $path Full category path from Amrod
     * @param string $final_name Display name for the terminal category
     * @param array  $meta Additional metadata (code, id, image)
     * @return array { success: bool, term_id?: int, message?: string }
     */
    private function ensure_category_hierarchy($path, $final_name, $meta = array()) {
        $path = trim((string) ($path ?: $final_name));
        $final_name = trim((string) ($final_name ?: $path));

        $segments = array_values(array_filter(array_map('trim', explode('/', $path)), 'strlen'));

        if (empty($segments)) {
            $segments[] = $final_name;
        }

        $parent_id = 0;
        $current_segments = array();
        $last_term_id = 0;

        foreach ($segments as $index => $segment) {
            $current_segments[] = $segment;
            $current_path = implode('/', $current_segments);
            $normalized_path = $this->normalize_category_path($current_path);
            $is_last_segment = ($index === count($segments) - 1);

            $display_name = $is_last_segment ? $final_name : $this->format_category_segment_name($segment);
            if ($display_name === '') {
                $display_name = $this->format_category_segment_name($segment);
            }

            // Always use path-based lookup first - this is critical for distinguishing
            // subcategories with the same name in different parent categories
            $existing_term = $this->get_category_term_by_path($current_path);

            $slug = sanitize_title($display_name ?: $segment);

            if (!$existing_term instanceof WP_Term) {
                // Try to find by slug with parent context first
                // This ensures we don't mix up subcategories with the same name
                // in different parent categories
                if ($slug !== '' && $parent_id > 0) {
                    $term_lookup = term_exists($slug, 'product_cat', $parent_id);
                    if ($term_lookup) {
                        $term_object = get_term(is_array($term_lookup) ? $term_lookup['term_id'] : $term_lookup, 'product_cat');
                        if ($term_object instanceof WP_Term) {
                            // Verify this term has the correct path metadata
                            $term_path = get_term_meta($term_object->term_id, '_amrod_category_path', true);
                            if ($term_path === $current_path || empty($term_path)) {
                            $existing_term = $term_object;
                        }
                        }
                    }
                }

                // If still not found and we have a parent, don't try global slug match
                // as this could incorrectly match a subcategory from a different parent
                // Only do global lookup if this is a top-level category (parent_id = 0)
                if (!$existing_term && $slug !== '' && $parent_id === 0) {
                    $term_lookup = term_exists($slug, 'product_cat');
                    if ($term_lookup) {
                        $term_object = get_term(is_array($term_lookup) ? $term_lookup['term_id'] : $term_lookup, 'product_cat');
                        if ($term_object instanceof WP_Term) {
                            // Only use if it's also top-level and has matching path
                            if ((int) $term_object->parent === 0) {
                                $term_path = get_term_meta($term_object->term_id, '_amrod_category_path', true);
                                if ($term_path === $current_path || empty($term_path)) {
                            $existing_term = $term_object;
                                }
                            }
                        }
                    }
                }

                // Only check by name as last resort for top-level categories
                // This prevents mixing up subcategories with same name in different parents
                if (!$existing_term && $display_name && $parent_id === 0) {
                    $term_object = get_term_by('name', $display_name, 'product_cat');
                    if ($term_object instanceof WP_Term && (int) $term_object->parent === 0) {
                        $term_path = get_term_meta($term_object->term_id, '_amrod_category_path', true);
                        if ($term_path === $current_path || empty($term_path)) {
                        $existing_term = $term_object;
                        }
                    }
                }
            }

            if ($existing_term instanceof WP_Term) {
                $term_id = (int) $existing_term->term_id;

                // Verify the existing term has the correct path - if not, it might be a different category
                $existing_path = get_term_meta($term_id, '_amrod_category_path', true);
                if (!empty($existing_path) && $existing_path !== $current_path) {
                    // This term has a different path - it's a different category with the same name
                    // We should create a new term instead of reusing this one
                    $this->logger->log('warning', 'Found category with same name but different path - creating new category', array(
                        'existing_path' => $existing_path,
                        'new_path' => $current_path,
                        'category_name' => $display_name,
                        'parent_id' => $parent_id,
                    ), 'category_sync');
                    $existing_term = null; // Force creation of new category
                } else {
                // Correct parent if it changed
                if ((int) $existing_term->parent !== (int) $parent_id) {
                    wp_update_term($term_id, 'product_cat', array('parent' => $parent_id));
                }

                // Refresh name if API casing changed
                if ($display_name && $existing_term->name !== $display_name) {
                    wp_update_term($term_id, 'product_cat', array('name' => $display_name));
                }
                }
            }
            
            // Create new category if we don't have an existing one
            if (!$existing_term instanceof WP_Term) {
                $args = array('parent' => $parent_id);
                if ($slug) {
                    $args['slug'] = $slug;
                }

                $created = wp_insert_term($display_name ?: $segment, 'product_cat', $args);

                if (is_wp_error($created)) {
                    if ($created->get_error_code() === 'term_exists') {
                        $existing_id = $created->get_error_data('term_exists');
                        $maybe_term = get_term($existing_id, 'product_cat');

                        if ($maybe_term instanceof WP_Term) {
                            $term_id = (int) $maybe_term->term_id;

                            if ((int) $maybe_term->parent !== (int) $parent_id) {
                                wp_update_term($term_id, 'product_cat', array('parent' => $parent_id));
                            }
                        } else {
                            return array(
                                'success' => false,
                                'message' => $created->get_error_message(),
                            );
                        }
                    } else {
                        return array(
                            'success' => false,
                            'message' => $created->get_error_message(),
                        );
                    }
                } else {
                    $term_id = (int) $created['term_id'];
                }
            }

            if (empty($term_id)) {
                return array(
                    'success' => false,
                    'message' => 'Unable to create category path segment',
                );
            }

            update_term_meta($term_id, '_amrod_category_path', $current_path);
            update_term_meta($term_id, '_amrod_category_path_normalized', $normalized_path);

            if ($is_last_segment) {
                if (!empty($meta['code'])) {
                    update_term_meta($term_id, '_amrod_category_code', sanitize_text_field($meta['code']));
                }
                if (!empty($meta['image'])) {
                    update_term_meta($term_id, '_amrod_category_image', esc_url_raw($meta['image']));
                }
                if (!empty($meta['id'])) {
                    update_term_meta($term_id, 'amrod_category_id', sanitize_text_field($meta['id']));
                }
            }

            $parent_id = $term_id;
            $last_term_id = $term_id;
        }

        if (!$last_term_id) {
            return array(
                'success' => false,
                'message' => 'Failed to determine category ID',
            );
        }

        return array(
            'success' => true,
            'term_id' => $last_term_id,
        );
    }

    /**
     * Extract highest resolution URL from Amrod image data
     *
     * @param array $urls
     * @return string
     */
    private function get_highest_resolution_url($urls) {
        $best_url = '';
        $best_width = -1;

        foreach ((array) $urls as $url_data) {
            if (empty($url_data['url'])) {
                continue;
            }
            $width = isset($url_data['width']) && is_numeric($url_data['width'])
                ? (int) $url_data['width']
                : 0;
            if ($width > $best_width) {
                $best_width = $width;
                $best_url = $url_data['url'];
            }
        }

        if (!$best_url && !empty($urls[0]['url'])) {
            $best_url = $urls[0]['url'];
        }

        return $best_url ? esc_url_raw($best_url) : '';
    }

    /**
     * Normalize colour image payload into consistent map for galleries/variations
     *
     * @param array $colour_images
     * @return array
     */
    private function build_colour_gallery_map($colour_images) {
        $map = array();

        foreach ((array) $colour_images as $colour) {
            if (empty($colour['images']) || !is_array($colour['images'])) {
                continue;
            }

            $code = isset($colour['code']) ? (string) $colour['code'] : '';
            $name = isset($colour['name']) ? (string) $colour['name'] : '';

            $key_candidates = array_filter(array(
                strtolower($code),
                strtolower($name),
                sanitize_title($name),
            ));

            if (empty($key_candidates)) {
                continue;
            }

            $images = array();

            foreach ($colour['images'] as $image_entry) {
                $url = $this->get_highest_resolution_url($image_entry['urls'] ?? array());

                if (!$url) {
                    continue;
                }

                $images[] = array(
                    'url' => $url,
                    'isDefault' => !empty($image_entry['isDefault']),
                    'hasLogo' => !empty($image_entry['hasLogo']),
                    'type' => $image_entry['type'] ?? '',
                    'name' => $image_entry['name'] ?? '',
                );
            }

            if (empty($images)) {
                continue;
            }

            $entry = array(
                'code' => $code,
                'name' => $name,
                'images' => $images,
            );

            foreach (array_unique($key_candidates) as $candidate) {
                if ($candidate === '') {
                    continue;
                }
                $map[$candidate] = $entry;
            }
        }

        return $map;
    }

    /**
     * Flatten hierarchical category structure
     * Recursively extracts all categories and their children into a flat array
     * 
     * @param array $categories Hierarchical category array
     * @param string $parent_path Parent path for tracking hierarchy
     * @return array Flat array of all categories with parent info
     */
    private function flatten_categories($categories, $parent_path = '') {
        $flat = array();
        
        foreach ($categories as $category) {
            // Add the current category with parent info
            $category_copy = $category;
            $category_copy['_parent_path'] = $parent_path;
            
            // Don't include children in the category data (we'll process them separately)
            $has_children = !empty($category['children']) && is_array($category['children']);
            unset($category_copy['children']);
            
            $flat[] = $category_copy;
            
            // Recursively add children if they exist
            if ($has_children) {
                $current_path = $category['categoryPath'] ?? '';
                $child_categories = $this->flatten_categories($category['children'], $current_path);
                $flat = array_merge($flat, $child_categories);
            }
        }
        
        return $flat;
    }
    
    /**
     * Sync categories (full tree structure)
     * 
     * @return array Result
     */
    public function sync_categories() {
        $this->logger->log('info', 'Starting category sync', array(), 'category_sync');
        
        // Fetch all categories from Amrod
        $categories = $this->api_client->get_categories();
        
        if (is_wp_error($categories)) {
            return array('success' => false, 'message' => $categories->get_error_message());
        }
        
        if (!is_array($categories) || empty($categories)) {
            return array('success' => false, 'message' => 'No categories available');
        }
        
        // Extract from 'value' wrapper if it exists (Amrod API returns {value: [...], Count: X})
        if (isset($categories['value']) && is_array($categories['value'])) {
            $categories = $categories['value'];
        }
        
        // Flatten hierarchical categories (include all children)
        $flat_categories = $this->flatten_categories($categories);
        
        $this->logger->log('info', "Flattened hierarchical categories", array(), 'category_sync');
        
        $total = count($flat_categories);
        $sync_id = 'categories_' . time() . '_' . wp_generate_password(8, false);
        
        // Split into batches (smaller batches for categories due to hierarchical processing)
        $batches = array_chunk($flat_categories, 25);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(), 'category_sync');
        
        // Store minimal sync info
        update_option("bytemash_sync_{$sync_id}", array(
            'type' => 'categories',
            'total' => $total,
            'batch_count' => $batch_count,
            'batch_size' => 25,
            'current_batch' => 0,
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'status' => 'ready',
            'started' => current_time('mysql'),
        ), false);
        
        return array(
            'success' => true,
            'message' => "Ready to sync {$total} categories in {$batch_count} batches",
            'sync_id' => $sync_id,
            'total' => $total,
            'batch_count' => $batch_count,
            'batches' => $batches,
            'tree' => $categories,
            'data' => $flat_categories,
        );
    }
    
    /**
     * Sync single category
     */
    public function sync_single_category($category_data) {
        $category_name = $category_data['categoryName'] ?? '';
        $category_path = $category_data['categoryPath'] ?? '';
        $category_code = $category_data['categoryCode'] ?? '';
        $category_image = $category_data['categoryImage'] ?? '';
        
        if (empty($category_name)) {
            $this->logger->log('error', 'Category missing name', array(
                'data' => $category_data,
            ), 'category_sync');
            return array('success' => false, 'message' => 'Category missing name');
        }

        $meta = array(
            'id' => $category_data['id'] ?? '',
            'code' => $category_code,
            'image' => $category_image,
        );

        try {
            $result = $this->ensure_category_hierarchy($category_path, $category_name, $meta);

            if (!$result['success']) {
                $this->logger->log('error', "Failed to sync category: {$category_name}", array(
                    'category_path' => $category_path,
                    'error' => $result['message'],
                ), 'category_sync');

                return $result;
            }

            $term_id = $result['term_id'];

            $this->logger->log('success', "Category synced: {$category_name}", array(
                'term_id' => $term_id,
                'category_path' => $category_path,
            ), 'category_sync');
            
            return array('success' => true, 'term_id' => $term_id, 'name' => $category_name);
        } catch (Exception $e) {
            $this->logger->log('error', "Exception syncing category: {$category_name}", array(
                'exception' => $e->getMessage(),
                'category_data' => $category_data,
            ), 'category_sync');
            return array('success' => false, 'message' => 'Exception: ' . $e->getMessage());
        }
    }

    /**
     * Store a snapshot of SKUs returned by the API so we can reconcile product counts
     *
     * @param string $sync_id Sync identifier
     * @param array|null $products Products returned from API (optional)
     * @param array $args Additional options
     */
    public function prepare_sku_snapshot($sync_id, $products = null, $args = array()) {
        $defaults = array(
            'context' => 'unknown',
            'fetch_full_catalog' => false,
            'with_branding' => false,
        );
        $args = wp_parse_args($args, $defaults);
        $context = $args['context'];
        $fetch_full_catalog = (bool) $args['fetch_full_catalog'];
        $with_branding = (bool) $args['with_branding'];
        
        if ((!is_array($products) || empty($products)) && $fetch_full_catalog) {
            $this->logger->log('info', 'Fetching catalog snapshot for reconciliation', array(
                'context' => $context,
                'sync_id' => $sync_id,
                'with_branding' => $with_branding,
            ), 'product_sync');
            
            $products = $with_branding
                ? $this->api_client->get_products_with_branding()
                : $this->api_client->get_products_without_branding();
            
            if (is_wp_error($products)) {
                $this->logger->log('warning', 'Failed to fetch catalog snapshot', array(
                    'context' => $context,
                    'sync_id' => $sync_id,
                    'error' => $products->get_error_message(),
                ), 'product_sync');
                return;
            }
        }
        
        if (!is_array($products) || empty($products)) {
            $this->logger->log('warning', 'No products available for SKU snapshot', array(
                'context' => $context,
                'sync_id' => $sync_id,
            ), 'product_sync');
            return;
        }
        
        $sku_list = $this->extract_product_skus($products);
        
        if (empty($sku_list)) {
            $this->logger->log('warning', 'SKU snapshot contained no values', array(
                'context' => $context,
                'sync_id' => $sync_id,
            ), 'product_sync');
            return;
        }
        
        set_transient("bytemash_sync_{$sync_id}_product_skus", $sku_list, DAY_IN_SECONDS);
        
        $this->logger->log('info', 'Stored SKU snapshot for reconciliation', array(
            'context' => $context,
            'sync_id' => $sync_id,
            'sku_count' => count($sku_list),
        ), 'product_sync');
    }
    
    /**
     * Cleanup WooCommerce products that are no longer present in the API snapshot
     *
     * @param string $sync_id Sync identifier
     * @param int $batch_limit Maximum products to process in this batch (0 = all)
     * @return array Result with checked/deleted/skipped counts
     */
    public function cleanup_products_not_in_snapshot($sync_id, $batch_limit = 0) {
        $sku_snapshot = get_transient("bytemash_sync_{$sync_id}_product_skus");
        
        if (empty($sku_snapshot) || !is_array($sku_snapshot)) {
            $this->logger->log('info', 'No SKU snapshot found for reconciliation', array(
                'sync_id' => $sync_id,
            ), 'product_sync');
            return array('checked' => 0, 'deleted' => 0, 'skipped' => 0);
        }
        
        // Update progress to show cleanup is starting
        $batch_processor = new ByteMash_Batch_Processor();
        $progress = $batch_processor->get_sync_progress($sync_id);
        if ($progress) {
            $progress['status'] = 'deleting_excess';
            $progress['cleanup_status'] = 'in_progress';
            $progress['cleanup_message'] = __('Deleting excess products...', 'bytemash-woo-sync');
            $batch_processor->save_sync_progress($sync_id, $progress);
        }
        
        $this->logger->log('info', 'Starting cleanup of products not in API snapshot', array(
            'sync_id' => $sync_id,
            'snapshot_count' => count($sku_snapshot),
            'batch_limit' => $batch_limit,
        ), 'product_sync');
        
        $result = $this->reconcile_catalog_against_skus($sku_snapshot, array(
            'sync_id' => $sync_id,
            'context' => 'snapshot_cleanup',
        ), $batch_limit);
        
        // Update progress with cleanup results
        if ($progress && $result) {
            $has_more = isset($result['has_more']) && $result['has_more'];
            
            if ($has_more) {
                // More batches to process
                $progress['cleanup_status'] = 'in_progress';
                $progress['cleanup_message'] = sprintf(
                    __('Deleting excess products... %d checked, %d deleted (processing...)', 'bytemash-woo-sync'),
                    $result['total_checked'] ?? $result['checked'] ?? 0,
                    $result['total_deleted'] ?? $result['deleted'] ?? 0
                );
            } else {
                // All done
                $progress['status'] = 'completed';
                $progress['cleanup_status'] = 'completed';
                $progress['cleanup_message'] = sprintf(
                    __('Deleting excess products completed: %d checked, %d deleted', 'bytemash-woo-sync'),
                    $result['total_checked'] ?? $result['checked'] ?? 0,
                    $result['total_deleted'] ?? $result['deleted'] ?? 0
                );
            }
            
            $progress['cleanup_checked'] = $result['total_checked'] ?? $result['checked'] ?? 0;
            $progress['cleanup_deleted'] = $result['total_deleted'] ?? $result['deleted'] ?? 0;
            $batch_processor->save_sync_progress($sync_id, $progress);
        }
        
        // Only delete transient if we're completely done
        if ($progress && (!isset($result['has_more']) || !$result['has_more'])) {
            delete_transient("bytemash_sync_{$sync_id}_product_skus");
        }

        return $result;
    }
    
    /**
     * Process a batch of deletion cleanup (for AJAX polling)
     *
     * @param string $sync_id Sync identifier
     * @param int $batch_size Number of products to process per batch
     * @return array Result with progress info
     */
    public function process_cleanup_batch($sync_id, $batch_size = 50) {
        $sku_snapshot = get_transient("bytemash_sync_{$sync_id}_product_skus");
        
        if (empty($sku_snapshot) || !is_array($sku_snapshot)) {
            return array(
                'success' => false,
                'message' => __('No SKU snapshot found', 'bytemash-woo-sync'),
                'done' => true,
            );
        }
        
        $result = $this->cleanup_products_not_in_snapshot($sync_id, $batch_size);
        
        if (empty($result)) {
            return array(
                'success' => false,
                'message' => __('Cleanup failed', 'bytemash-woo-sync'),
                'done' => true,
            );
        }
        
        $has_more = isset($result['has_more']) && $result['has_more'];
        
        return array(
            'success' => true,
            'checked' => $result['total_checked'] ?? $result['checked'] ?? 0,
            'deleted' => $result['total_deleted'] ?? $result['deleted'] ?? 0,
            'skipped' => $result['skipped'] ?? 0,
            'done' => !$has_more,
        );
    }
    
    /**
     * Incremental cleanup - deletes a limited number of excess products after each batch
     * This is faster than waiting until the end
     *
     * @param string $sync_id Sync identifier
     * @param int $limit Maximum number of products to check/delete per call
     * @return array Result with checked/deleted counts
     */
    public function cleanup_products_incremental($sync_id, $limit = 50) {
        $sku_snapshot = get_transient("bytemash_sync_{$sync_id}_product_skus");
        
        if (empty($sku_snapshot) || !is_array($sku_snapshot)) {
            return array('checked' => 0, 'deleted' => 0, 'skipped' => 0);
        }
        
        // Get progress to track which products we've already checked
        $batch_processor = new ByteMash_Batch_Processor();
        $progress = $batch_processor->get_sync_progress($sync_id);
        
        // Track which products we've already checked (to avoid re-checking)
        $checked_products = isset($progress['cleanup_checked_product_ids']) 
            ? $progress['cleanup_checked_product_ids'] 
            : array();
        
        // OPTIMIZATION: Cache normalized SKU map in progress to avoid re-normalizing every time
        if (!isset($progress['cleanup_normalized_sku_map'])) {
            $normalized_map = array();
            foreach ($sku_snapshot as $sku) {
                $normalized = $this->normalize_sku($sku);
                if ($normalized !== '') {
                    $normalized_map[$normalized] = true;
                }
            }
            $progress['cleanup_normalized_sku_map'] = $normalized_map;
            $batch_processor->save_sync_progress($sync_id, $progress);
        } else {
            $normalized_map = $progress['cleanup_normalized_sku_map'];
        }
        
        if (empty($normalized_map)) {
            return array('checked' => 0, 'deleted' => 0, 'skipped' => 0);
        }
        
        global $wpdb;
        $table_posts = $wpdb->posts;
        $table_meta = $wpdb->postmeta;
        
        // Get a limited number of Amrod products we haven't checked yet
        $where_conditions = array(
            "p.post_type = 'product'",
            "p.post_status NOT IN ('trash', 'auto-draft')"
        );
        
        if (!empty($checked_products)) {
            $checked_ids = array_map('intval', $checked_products);
            if (!empty($checked_ids)) {
                $checked_ids_str = implode(',', $checked_ids);
                // Safe because all values are cast to int via array_map('intval')
                $where_conditions[] = "p.ID NOT IN ({$checked_ids_str})";
            }
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        $limit_int = intval($limit);
        
        $query = "
            SELECT DISTINCT p.ID as post_id, 
                   pm_amrod.meta_value AS sku
            FROM {$table_posts} p
            INNER JOIN {$table_meta} pm_amrod ON (
                pm_amrod.post_id = p.ID 
                AND pm_amrod.meta_key = '_amrod_simple_code'
                AND pm_amrod.meta_value IS NOT NULL
                AND pm_amrod.meta_value != ''
            )
            WHERE {$where_clause}
            LIMIT {$limit_int}
        ";
        
        $rows = $wpdb->get_results($query);
        $checked = 0;
        $deleted = 0;
        $skipped = 0;
        
        // OPTIMIZATION: Enable bulk operation mode for faster processing
        wp_defer_term_counting(true);
        wp_defer_comment_counting(true);
        wp_suspend_cache_addition(true);
        
        try {
            if ($rows) {
                foreach ($rows as $row) {
                    $product_id = (int) $row->post_id;
                    $checked_products[] = $product_id; // Track that we've checked this
                    $checked++;
                    
                    $product_sku = $this->normalize_sku($row->sku ?? '');
                    
                    // Skip if SKU is empty or if product is in the allowed list
                    if ($product_sku === '' || isset($normalized_map[$product_sku])) {
                        $skipped++;
                        continue;
                    }
                    
                    // OPTIMIZATION: Don't load full product object - we only need ID for deletion
                    // wp_delete_post() will handle WooCommerce hooks properly:
                    // - before_delete_post
                    // - delete_post
                    // - woocommerce_before_delete_product
                    // - woocommerce_delete_product
                    $this->logger->log('warning', 'Deleting product not in API snapshot (incremental)', array(
                        'product_id' => $product_id,
                        'sku' => $row->sku,
                    ), 'product_sync');
                    
                    // Delete the product (force delete, bypass trash)
                    $delete_result = wp_delete_post($product_id, true);
                    
                    if ($delete_result) {
                        $deleted++;
                        $this->logger->log('success', 'Product deleted successfully (incremental)', array(
                            'product_id' => $product_id,
                            'sku' => $row->sku,
                        ), 'product_sync');
                    } else {
                        $this->logger->log('error', 'Failed to delete product (incremental)', array(
                            'product_id' => $product_id,
                            'sku' => $row->sku,
                        ), 'product_sync');
                    }
                }
            }
        } finally {
            // OPTIMIZATION: Re-enable counting and cache (term counts update in bulk)
            wp_defer_term_counting(false);
            wp_defer_comment_counting(false);
            wp_suspend_cache_addition(false);
        }
        
        // Update progress with checked product IDs
        if ($progress) {
            $progress['cleanup_checked_product_ids'] = $checked_products;
            $progress['cleanup_incremental_checked'] = ($progress['cleanup_incremental_checked'] ?? 0) + $checked;
            $progress['cleanup_incremental_deleted'] = ($progress['cleanup_incremental_deleted'] ?? 0) + $deleted;
            
            // Update cleanup status for UI
            if ($deleted > 0 || $checked > 0) {
                $progress['cleanup_status'] = 'in_progress';
                $progress['cleanup_message'] = sprintf(
                    'Deleting excess products... %d checked, %d deleted (incremental)',
                    $progress['cleanup_incremental_checked'],
                    $progress['cleanup_incremental_deleted']
                );
                $progress['cleanup_checked'] = $progress['cleanup_incremental_checked'];
                $progress['cleanup_deleted'] = $progress['cleanup_incremental_deleted'];
            }
            
            $batch_processor->save_sync_progress($sync_id, $progress);
        }
        
        return array(
            'checked' => $checked,
            'deleted' => $deleted,
            'skipped' => $skipped,
        );
    }

    /**
     * Fetch the latest catalog from Amrod and delete WooCommerce products
     * that no longer exist in the API response.
     *
     * @param array $args {
     *     Optional arguments.
     *
     *     @type bool   $with_branding  Whether to include branding data in the fetch request.
     *     @type string $context        Free-form context label for logging.
     *     @type bool   $track_progress Whether to expose progress in the dashboard.
     *     @type string $sync_id        Optional sync ID to reuse for tracking.
     * }
     * @return array Result array with success flag and stats.
     */
    public function delete_excess_products($args = array()) {
        $defaults = array(
            'with_branding' => false,
            'context' => 'manual_cleanup',
            'track_progress' => true,
            'sync_id' => '',
        );
        $args = wp_parse_args($args, $defaults);

        $with_branding = (bool) $args['with_branding'];
        $context = sanitize_key($args['context']);
        if ($context === '') {
            $context = 'manual_cleanup';
        }
        $track_progress = (bool) $args['track_progress'];
        $sync_id = !empty($args['sync_id']) ? sanitize_key($args['sync_id']) : '';

        $this->logger->log('info', 'Manual excess product cleanup requested', array(
            'with_branding' => $with_branding,
            'context' => $context,
        ), 'product_sync');

        $products = $with_branding
            ? $this->api_client->get_products_with_branding()
            : $this->api_client->get_products_without_branding();

        if (is_wp_error($products)) {
            return array(
                'success' => false,
                'message' => $products->get_error_message(),
            );
        }

        if (isset($products['value']) && is_array($products['value'])) {
            $products = $products['value'];
        }

        if (!is_array($products) || empty($products)) {
            return array(
                'success' => false,
                'message' => __('No products were returned from the Amrod API.', 'bytemash-woo-sync'),
            );
        }

        $allowed_skus = $this->extract_product_skus($products);

        if (empty($allowed_skus)) {
            return array(
                'success' => false,
                'message' => __('The API response did not include any SKUs to compare against.', 'bytemash-woo-sync'),
            );
        }

        if (empty($sync_id)) {
            $sync_id = 'cleanup_' . time() . '_' . wp_generate_password(6, false);
        }

        set_transient("bytemash_sync_{$sync_id}_product_skus", $allowed_skus, 12 * HOUR_IN_SECONDS);

        if ($track_progress) {
            $batch_processor = new ByteMash_Batch_Processor();
            $progress = array(
                'type' => 'products_cleanup',
                'total' => count($allowed_skus),
                'batch_count' => 1,
                'batch_size' => count($allowed_skus),
                'current_batch' => 0,
                'processed' => 0,
                'errors' => 0,
                'skipped' => 0,
                'status' => 'deleting_excess',
                'cleanup_status' => 'starting',
                'cleanup_message' => __('Deleting excess products...', 'bytemash-woo-sync'),
                'started' => current_time('mysql'),
                'context' => $context,
            );
            $batch_processor->save_sync_progress($sync_id, $progress);
        }

        $result = $this->cleanup_products_not_in_snapshot($sync_id);

        if (empty($result)) {
            return array(
                'success' => false,
                'message' => __('Cleanup could not be completed. Please try again.', 'bytemash-woo-sync'),
            );
        }

        $message = sprintf(
            __('Cleanup complete: %1$d products checked, %2$d deleted.', 'bytemash-woo-sync'),
            $result['checked'] ?? 0,
            $result['deleted'] ?? 0
        );

        return array(
            'success' => true,
            'message' => $message,
            'sync_id' => $sync_id,
            'checked' => $result['checked'] ?? 0,
            'deleted' => $result['deleted'] ?? 0,
        );
    }
    
    /**
     * Extract normalized SKUs from API payload
     *
     * @param array $products
     * @return array
     */
    private function extract_product_skus($products) {
        $skus = array();
        
        foreach ((array) $products as $product) {
            if (!is_array($product)) {
                continue;
            }
            
            $raw_sku = $product['simpleCode'] ?? $product['fullCode'] ?? null;
            $normalized = $this->normalize_sku($raw_sku);
            
            if ($normalized !== '') {
                $skus[$normalized] = true;
            }
        }
        
        return array_keys($skus);
    }
    
    /**
     * Normalize SKU for comparisons
     *
     * @param string|null $sku
     * @return string
     */
    private function normalize_sku($sku) {
        if ($sku === null) {
            return '';
        }
        
        $sanitized = sanitize_text_field($sku);
        $trimmed = trim($sanitized);
        
        if ($trimmed === '') {
            return '';
        }
        
        return strtoupper($trimmed);
    }
    
    /**
     * Reconcile WooCommerce catalog against allowed SKUs
     * Deletes products that are no longer present in the API
     *
     * @param array $allowed_skus
     * @param array $context
     * @param int $limit Maximum products to process in this batch (0 = all)
     * @return array Result with checked/deleted/skipped counts
     */
    private function reconcile_catalog_against_skus(array $allowed_skus, $context = array(), $limit = 0) {
        if (empty($allowed_skus)) {
            $this->logger->log('warning', 'Cannot reconcile catalog with empty SKU list', $context, 'product_sync');
            return array('checked' => 0, 'deleted' => 0, 'skipped' => 0);
        }
        
        $normalized_map = array();
        foreach ($allowed_skus as $sku) {
            $normalized = $this->normalize_sku($sku);
            if ($normalized !== '') {
                $normalized_map[$normalized] = true;
            }
        }
        
        if (empty($normalized_map)) {
            $this->logger->log('warning', 'No valid SKUs available for reconciliation after normalization', $context, 'product_sync');
            return array('checked' => 0, 'deleted' => 0, 'skipped' => 0);
        }
        
        $sync_id = $context['sync_id'] ?? '';
        $batch_processor = !empty($sync_id) ? new ByteMash_Batch_Processor() : null;
        $progress = $batch_processor ? $batch_processor->get_sync_progress($sync_id) : null;
        
        // Get already checked product IDs to avoid re-checking
        $checked_products = isset($progress['cleanup_checked_product_ids']) 
            ? $progress['cleanup_checked_product_ids'] 
            : array();
        
        $this->logger->log('info', 'Starting catalog reconciliation', array_merge($context, array(
            'snapshot_total' => count($normalized_map),
            'limit' => $limit,
            'already_checked' => count($checked_products),
        )), 'product_sync');
        
        global $wpdb;
        $table_posts = $wpdb->posts;
        $table_meta = $wpdb->postmeta;
        
        // Build WHERE clause to exclude already checked products
        $where_conditions = array(
            "p.post_type = 'product'",
            "p.post_status NOT IN ('trash', 'auto-draft')"
        );
        
        if (!empty($checked_products)) {
            $checked_ids = array_map('intval', $checked_products);
            if (!empty($checked_ids)) {
                $checked_ids_str = implode(',', $checked_ids);
                $where_conditions[] = "p.ID NOT IN ({$checked_ids_str})";
            }
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        $limit_clause = $limit > 0 ? "LIMIT {$limit}" : '';
        
        // Find all Amrod products by checking _amrod_simple_code meta
        // Also check _sku meta as fallback for products that might not have _amrod_simple_code yet
        $query = "
            SELECT DISTINCT p.ID as post_id, 
                   COALESCE(pm_amrod.meta_value, pm_sku.meta_value) AS sku
            FROM {$table_posts} p
            LEFT JOIN {$table_meta} pm_amrod ON (
                pm_amrod.post_id = p.ID 
                AND pm_amrod.meta_key = '_amrod_simple_code'
                AND pm_amrod.meta_value IS NOT NULL
                AND pm_amrod.meta_value != ''
            )
            LEFT JOIN {$table_meta} pm_sku ON (
                pm_sku.post_id = p.ID 
                AND pm_sku.meta_key = '_sku'
                AND pm_sku.meta_value IS NOT NULL
                AND pm_sku.meta_value != ''
                AND pm_amrod.meta_value IS NULL
            )
            WHERE {$where_clause}
              AND (pm_amrod.meta_value IS NOT NULL OR pm_sku.meta_value IS NOT NULL)
            {$limit_clause}
        ";
        
        $rows = $wpdb->get_results($query);
        $checked = 0;
        $deleted = 0;
        $skipped = 0;
        $total_checked = isset($progress['cleanup_checked']) ? (int) $progress['cleanup_checked'] : 0;
        $total_deleted = isset($progress['cleanup_deleted']) ? (int) $progress['cleanup_deleted'] : 0;
        
        $this->logger->log('info', 'Catalog reconciliation query executed', array_merge($context, array(
            'products_found' => $rows ? count($rows) : 0,
            'snapshot_total' => count($normalized_map),
            'limit' => $limit,
        )), 'product_sync');
        
        // Enable bulk operation mode for faster processing
        wp_defer_term_counting(true);
        wp_defer_comment_counting(true);
        wp_suspend_cache_addition(true);
        
        try {
            if ($rows) {
                foreach ($rows as $row) {
                    $product_id = (int) $row->post_id;
                    $checked_products[] = $product_id; // Track that we've checked this
                    $checked++;
                    $total_checked++;
                    
                    $product_sku = $this->normalize_sku($row->sku ?? '');
                    
                    // Skip if SKU is empty or if product is in the allowed list
                    if ($product_sku === '' || isset($normalized_map[$product_sku])) {
                        $skipped++;
                        continue;
                    }
                    
                    // Product is not in the API snapshot - delete it
                    $product = wc_get_product($product_id);
                    
                    if ($product) {
                        $product_name = $product->get_name();
                        $product_sku_display = $product->get_sku();
                        
                        $this->logger->log('warning', 'Deleting product not in API snapshot', array(
                            'product_id' => $product_id,
                            'product_name' => $product_name,
                            'sku' => $product_sku_display,
                            'normalized_sku' => $product_sku,
                        ), 'product_sync');
                        
                        // Delete the product (force delete, bypass trash)
                        $delete_result = wp_delete_post($product_id, true);
                        
                        if ($delete_result) {
                            $deleted++;
                            $total_deleted++;
                            $this->logger->log('success', 'Product deleted successfully', array(
                                'product_id' => $product_id,
                                'sku' => $product_sku_display,
                            ), 'product_sync');
                        } else {
                            $this->logger->log('error', 'Failed to delete product', array(
                                'product_id' => $product_id,
                                'sku' => $product_sku_display,
                            ), 'product_sync');
                        }
                    }
                    
                    // Update progress every 10 items or every deletion
                    if ($batch_processor && ($checked % 10 === 0 || $deleted > 0)) {
                        $progress = $batch_processor->get_sync_progress($sync_id);
                        if ($progress) {
                            $progress['status'] = 'deleting_excess';
                            $progress['cleanup_status'] = 'in_progress';
                            $progress['cleanup_message'] = sprintf(
                                __('Deleting excess products... %d checked, %d deleted', 'bytemash-woo-sync'),
                                $total_checked,
                                $total_deleted
                            );
                            $progress['cleanup_checked'] = $total_checked;
                            $progress['cleanup_deleted'] = $total_deleted;
                            $progress['cleanup_checked_product_ids'] = $checked_products;
                            $batch_processor->save_sync_progress($sync_id, $progress);
                        }
                    }
                }
            }
        } finally {
            // Re-enable counting and cache
            wp_defer_term_counting(false);
            wp_defer_comment_counting(false);
            wp_suspend_cache_addition(false);
        }
        
        // Final progress update
        if ($batch_processor && $progress) {
            $progress = $batch_processor->get_sync_progress($sync_id);
            if ($progress) {
                $progress['cleanup_checked'] = $total_checked;
                $progress['cleanup_deleted'] = $total_deleted;
                $progress['cleanup_checked_product_ids'] = $checked_products;
                $batch_processor->save_sync_progress($sync_id, $progress);
            }
        }
        
        $this->logger->log('info', 'Catalog reconciliation batch completed', array_merge($context, array(
            'snapshot_total' => count($normalized_map),
            'products_checked' => $checked,
            'products_skipped' => $skipped,
            'products_deleted' => $deleted,
            'total_checked' => $total_checked,
            'total_deleted' => $total_deleted,
        )), 'product_sync');
        
        // Return result for progress tracking
        return array(
            'checked' => $checked,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'total_checked' => $total_checked,
            'total_deleted' => $total_deleted,
            'snapshot_total' => count($normalized_map),
            'has_more' => $limit > 0 && count($rows) >= $limit, // Indicates if there are more to process
        );
    }
    
    /**
     * Update stock for variable products based on stock type
     */
    private function update_variable_product_stock($product, $stock_item, $stock_qty, $reserved_qty, $incoming, $modified, $stock_type) {
        $product_id = $product->get_id();
        $full_code = $stock_item['fullCode'];
        $simple_code = $stock_item['simpleCode'];
        $colour_code = isset($stock_item['colourCode']) ? $stock_item['colourCode'] : null;
        
        // Store detailed stock breakdown for this specific variation
        $detail = array(
            'stock' => $stock_qty,
            'reserved' => $reserved_qty,
            'incoming' => $incoming,
            'modified' => $modified,
            'fullCode' => $full_code,
            'simpleCode' => $simple_code,
            'colourCode' => $colour_code,
            'stockType' => $stock_type,
        );
        
        if ($stock_type === 0) {
            // Base product stock (stockType: 0) - update parent variable product
            $product->set_manage_stock(true);
            $product->set_stock_quantity($stock_qty);
            $product->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');
            $this->save_product_safely($product);
            
            // Store base stock detail on parent
            update_post_meta($product_id, '_amrod_stock_detail', $detail);
            
            $this->logger->log('info', 'Updated base variable product stock', array(
                'product_id' => $product_id,
                'sku' => $product->get_sku(),
                'stock_qty' => $stock_qty,
            ), 'product_sync');
            
        } else {
            // Variation stock (stockType: 1 or 2) - find and update matching variation
            $variation_id = $this->find_matching_variation($product, $full_code, $simple_code, $colour_code);
            
            if ($variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation) {
                    $variation->set_manage_stock(true);
                    $variation->set_stock_quantity($stock_qty);
                    $variation->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');
                    $this->save_product_safely($variation);
                    
                    // Store variation stock detail
                    update_post_meta($variation_id, '_amrod_stock_detail', $detail);
                    
                    $this->logger->log('info', 'Updated variation stock', array(
                        'variation_id' => $variation_id,
                        'parent_id' => $product_id,
                        'full_code' => $full_code,
                        'stock_qty' => $stock_qty,
                    ), 'product_sync');
                }
            } else {
                $this->logger->log('warning', 'Could not find matching variation for stock update', array(
                    'parent_id' => $product_id,
                    'full_code' => $full_code,
                    'simple_code' => $simple_code,
                    'colour_code' => $colour_code,
                ), 'product_sync');
            }
        }
    }

    /**
     * Find matching variation based on full code, simple code, and colour code
     */
    private function find_matching_variation($variable_product, $full_code, $simple_code, $colour_code) {
        $variations = $variable_product->get_children();
        
        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) continue;
            
            $variation_sku = $variation->get_sku();
            
            // Direct SKU match
            if ($variation_sku === $full_code) {
                return $variation_id;
            }
            
            // Try to match by simple code + colour code pattern
            if ($colour_code && strpos($variation_sku, $simple_code) === 0) {
                // Check if the variation SKU contains the colour code
                if (strpos($variation_sku, $colour_code) !== false) {
                    return $variation_id;
                }
            }
        }
        
        return null;
    }
}

