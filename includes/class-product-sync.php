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
     * Constructor
     */
    public function __construct() {
        $this->api_client = new ByteMash_Amrod_API_Client();
        $this->logger = new ByteMash_Logger();
        $this->image_handler = new ByteMash_Image_Handler();
        $this->batch_size = (int) get_option('bytemash_amrod_batch_size', 50);
    }
    
    /**
     * Sync all products (uses batch processor for memory efficiency)
     * 
     * @param bool $force Force update existing products
     * @param bool $with_branding Include branding information (heavier payload)
     * @return array Result with sync_id
     */
    public function sync_all_products($force = false, $with_branding = true) {
        $this->logger->log('info', 'Starting full product sync', array(
            'with_branding' => $with_branding,
        ), 'product_sync');
        
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
        $this->logger->log('info', "Found {$total} products to sync", array(
            'total' => $total,
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ), 'product_sync');
        
        // Generate unique sync ID
        $sync_id = 'products_' . time() . '_' . wp_generate_password(8, false);
        
        // Split into batches
        $batches = array_chunk($products, $this->batch_size);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(
            'total_products' => $total,
            'batch_size' => $this->batch_size,
            'batch_count' => $batch_count,
        ), 'product_sync');
        
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
        
        $this->logger->log('info', "Sync ready - returning batches to JavaScript", array(
            'sync_id' => $sync_id,
            'batch_count' => $batch_count,
        ), 'product_sync');
        
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
        $this->logger->log('info', 'Starting incremental product sync', array(
            'with_branding' => $with_branding,
        ), 'product_sync');
        
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
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(
            'total_products' => $total,
            'batch_size' => $this->batch_size,
            'batch_count' => $batch_count,
        ), 'product_sync');
        
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
    private function sync_variable_product($product_data, $parent_sku, $force = false) {
        try {
            $this->logger->log('info', 'Creating/updating variable product with variations', array(
                'sku' => $parent_sku,
                'variant_count' => count($product_data['variants']),
            ), 'product_sync');
            
            // Check if parent product exists
            $product_id = wc_get_product_id_by_sku($parent_sku);
            
            if ($product_id) {
                $product = wc_get_product($product_id);
                
                // If exists but is not variable, delete and recreate
                if ($product && !$product->is_type('variable')) {
                    $this->logger->log('info', 'Converting simple product to variable', array('product_id' => $product_id), 'product_sync');
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
        
        // Set parent product data
        $product->set_sku($parent_sku);
        $product->set_name(sanitize_text_field($product_data['productName'] ?? ''));
        $product->set_description(wp_kses_post($product_data['description'] ?? ''));
        
        // Set categories
        if (!empty($product_data['categories']) && is_array($product_data['categories'])) {
            $category_ids = $this->sync_product_categories($product_data['categories']);
            $product->set_category_ids($category_ids);
        }
        
        // Set brand
        if (!empty($product_data['brand'])) {
            $this->set_product_brand($product, $product_data['brand']);
        }
        
        // Save parent product
        $product_id = $product->save();
        
        // Sync parent images
        if (!empty($product_data['images']) && is_array($product_data['images'])) {
            try {
                $this->sync_product_images($product_id, $product_data['images']);
            } catch (Exception $e) {
                $this->logger->log('warning', 'Parent image sync failed', array(
                    'product_id' => $product_id,
                    'error' => $e->getMessage(),
                ), 'image_sync');
            }
        }
        
        // Store parent metadata
        $this->sync_product_meta($product_id, $product_data);
        
        // Create product attributes (Size and Color)
        $attribute_data = $this->create_product_attributes($product_data['variants']);
        $product->set_attributes($attribute_data['attributes']);
        $product->save();
        
        // Store color code mapping for frontend color swatches
        if (!empty($attribute_data['color_mapping'])) {
            update_post_meta($product_id, '_amrod_color_mapping', $attribute_data['color_mapping']);
        }
        
        // Create/update variations
        $variation_count = 0;
        $variation_errors = 0;
        
        foreach ($product_data['variants'] as $variant_data) {
            try {
                $result = $this->create_product_variation($product_id, $variant_data, $product_data);
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
        
        // Set default attributes to first variation to prevent "Please select options" message
        if ($variation_count > 0 && !empty($product_data['variants'])) {
            $first_variant = $product_data['variants'][0];
            $default_attributes = array();
            
            if (!empty($first_variant['codeSizeName'])) {
                $default_attributes['size'] = sanitize_title($first_variant['codeSizeName']);
            }
            if (!empty($first_variant['codeColourName'])) {
                $default_attributes['color'] = sanitize_title($first_variant['codeColourName']);
            }
            
            if (!empty($default_attributes)) {
                $product = wc_get_product($product_id);
                $product->set_default_attributes($default_attributes);
                $product->save();
                
                $this->logger->log('info', 'Set default attributes for variable product', array(
                    'product_id' => $product_id,
                    'defaults' => $default_attributes,
                ), 'product_sync');
            }
        }
        
        $this->logger->log('success', "Variable product synced: {$variation_count} variations created, {$variation_errors} errors", array(
            'product_id' => $product_id,
            'sku' => $parent_sku,
            'variations_created' => $variation_count,
            'variation_errors' => $variation_errors,
        ), 'product_sync');
        
        return array('success' => true, 'product_id' => $product_id, 'message' => "Variable product created with {$variation_count} variations");
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
    private function create_product_variation($parent_id, $variant_data, $parent_data) {
        $variant_sku = $variant_data['fullCode'] ?? '';
        
        if (empty($variant_sku)) {
            $this->logger->log('warning', 'Variation missing SKU', array('parent_id' => $parent_id), 'product_sync');
            return false;
        }
        
        $this->logger->log('info', 'Creating variation', array(
            'parent_id' => $parent_id,
            'sku' => $variant_sku,
            'size' => $variant_data['codeSizeName'] ?? 'N/A',
            'color' => $variant_data['codeColourName'] ?? 'N/A',
        ), 'product_sync');
        
        // Check if variation exists
        $variation_id = wc_get_product_id_by_sku($variant_sku);
        
        if ($variation_id) {
            $this->logger->log('info', 'Updating existing variation', array('variation_id' => $variation_id), 'product_sync');
            $variation = new WC_Product_Variation($variation_id);
        } else {
            $this->logger->log('info', 'Creating new variation', array('sku' => $variant_sku), 'product_sync');
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($parent_id);
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
        
        // Save variation
        $variation_id = $variation->save();
        
        if (!$variation_id) {
            $this->logger->log('error', 'Failed to save variation - save() returned false', array(
                'parent_id' => $parent_id,
                'sku' => $variant_sku,
            ), 'product_sync');
            return false;
        }
        
        $this->logger->log('success', 'Variation saved successfully', array(
            'variation_id' => $variation_id,
            'sku' => $variant_sku,
        ), 'product_sync');
        
        // Set variation image from colourImages if available
        if (!empty($parent_data['colourImages']) && !empty($variant_data['codeColour'])) {
            $color_code = $variant_data['codeColour'];
            
            foreach ($parent_data['colourImages'] as $color_data) {
                if ($color_data['code'] === $color_code && !empty($color_data['images'])) {
                    // Get the first image for this color
                    $color_image = $color_data['images'][0] ?? null;
                    if ($color_image && !empty($color_image['urls'])) {
                        // Get highest res URL
                        $highest_res = null;
                        $max_width = 0;
                        foreach ($color_image['urls'] as $url_data) {
                            $width = $url_data['width'] ?? 0;
                            if ($width > $max_width && !empty($url_data['url'])) {
                                $highest_res = $url_data['url'];
                                $max_width = $width;
                            }
                        }
                        
                        if ($highest_res) {
                            update_post_meta($variation_id, '_thumbnail_external_url', $highest_res);
                            update_post_meta($variation_id, '_amrod_variation_image', $highest_res);
                        }
                    }
                    break;
                }
            }
        }
        
        // Store variant-specific metadata
        if (!empty($variant_data['packagingAndDimension'])) {
            update_post_meta($variation_id, '_amrod_packaging', $variant_data['packagingAndDimension']);
        }
        
        return $variation_id;
    }
    
    /**
     * Process entire batch of products using optimized bulk operations
     * 
     * @param array $products Array of product data
     * @return array Result with processed/error counts
     */
    public function sync_batch_products($products) {
        global $wpdb;
        
        $processed = 0;
        $errors = 0;
        $skipped = 0;
        
        // Enable performance mode
        $this->enable_performance_mode();
        
        $this->logger->log('info', 'Starting ULTRA-FAST batch product sync', array(
            'batch_size' => count($products),
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ), 'product_sync');
        
        // Separate simple and variable products for different processing
        $simple_products = array();
        $variable_products = array();
        
        foreach ($products as $product_data) {
            $has_variants = $this->check_if_variable($product_data);
            
            if ($has_variants) {
                $variable_products[] = $product_data;
            } else {
                $simple_products[] = $product_data;
            }
        }
        
        $this->logger->log('info', 'Product categorization', array(
            'simple_count' => count($simple_products),
            'variable_count' => count($variable_products),
        ), 'product_sync');
        
        // Process simple products in BULK (ultra-fast)
        if (!empty($simple_products)) {
            $result = $this->bulk_insert_simple_products($simple_products);
            $processed += $result['processed'];
            $errors += $result['errors'];
        }
        
        // Process variable products normally (complex, can't bulk)
        if (!empty($variable_products)) {
            foreach ($variable_products as $product_data) {
                $result = $this->sync_single_product($product_data);
                if ($result['success']) {
                    $processed++;
                } else {
                    $errors++;
                }
            }
        }
        
        // Clear memory
        wp_cache_flush();
        gc_collect_cycles();
        
        // Disable performance mode
        $this->disable_performance_mode();
        
        $this->logger->log('info', 'ULTRA-FAST batch product sync completed', array(
            'processed' => $processed,
            'errors' => $errors,
            'skipped' => $skipped,
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ), 'product_sync');
        
        return array(
            'success' => true,
            'processed' => $processed,
            'errors' => $errors,
            'skipped' => $skipped,
        );
    }
    
    /**
     * Check if product should be variable (based on Amrod API structure)
     * 
     * IMPORTANT: Amrod API structure is:
     * - Each API item = ONE parent product
     * - simpleCode = Parent product SKU
     * - variants array = Variations of that parent
     * - If variants has MORE THAN 1 entry → Variable product
     * - If variants has 1 entry OR is empty → Simple product
     */
    private function check_if_variable($product_data) {
        $enable_variable_products = get_option('bytemash_enable_variable_products', true);
        
        if (!$enable_variable_products) {
            return false;
        }
        
        // CORRECT RULE: Only variable if variants array has MORE THAN 1 entry
        // If only 1 variant → Simple product
        // If 2+ variants → Variable product
        return !empty($product_data['variants']) && 
               is_array($product_data['variants']) && 
               count($product_data['variants']) > 1;
    }
    
    /**
     * Bulk insert/update simple products using WooCommerce (RELIABLE + OPTIMIZED)
     */
    private function bulk_insert_simple_products($products) {
        $processed = 0;
        $errors = 0;
        
        // Pre-fetch all existing SKUs in one query
        global $wpdb;
        $skus = array_filter(array_map(function($p) {
            return $p['simpleCode'] ?? $p['fullCode'] ?? null;
        }, $products));
        
        $existing_products = array();
        if (!empty($skus)) {
            $sku_list = array();
            foreach ($skus as $sku) {
                $sku_list[] = $wpdb->prepare('%s', $sku);
            }
            $sku_in = implode(',', $sku_list);
            
            $results = $wpdb->get_results(
                "SELECT p.ID, pm.meta_value as sku 
                FROM {$wpdb->posts} p 
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                WHERE pm.meta_key = '_sku' AND pm.meta_value IN ($sku_in)"
            );
            
            foreach ($results as $row) {
                $existing_products[$row->sku] = $row->ID;
            }
        }
        
        // Cache categories to avoid repeated term_exists() calls
        $category_cache = array();
        
        foreach ($products as $product_data) {
            $sku = $product_data['simpleCode'] ?? $product_data['fullCode'] ?? null;
            if (!$sku) {
                $errors++;
                continue;
            }
            
            $sku = sanitize_text_field($sku);
            
            // Use pre-fetched data instead of querying each time
            $existing_id = $existing_products[$sku] ?? null;
            
            if ($existing_id) {
                $product = wc_get_product($existing_id);
            } else {
                $product = new WC_Product_Simple();
            }
            
            if (!$product) {
                $errors++;
                continue;
            }
            
            // Set basic data
            $product->set_sku($sku);
            $product->set_name(sanitize_text_field($product_data['productName'] ?? ''));
            $product->set_description(wp_kses_post($product_data['description'] ?? ''));
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_manage_stock(true);
            $product->set_stock_status('instock');
            $product->set_regular_price(0);
            $product->set_tax_status('taxable');
            $product->set_tax_class('');
            
            // Set categories (with caching)
            if (!empty($product_data['categories']) && is_array($product_data['categories'])) {
                $category_ids = array();
                foreach ($product_data['categories'] as $category_data) {
                    $category_name = $category_data['name'] ?? null;
                    if (!$category_name) continue;
                    
                    // Check cache first
                    if (!isset($category_cache[$category_name])) {
                        $term = term_exists($category_name, 'product_cat');
                        if (!$term) {
                            $term = wp_insert_term($category_name, 'product_cat');
                        }
                        
                        if (!is_wp_error($term) && isset($term['term_id'])) {
                            $category_cache[$category_name] = (int) $term['term_id'];
                        } else {
                            $category_cache[$category_name] = null;
                        }
                    }
                    
                    if ($category_cache[$category_name]) {
                        $category_ids[] = $category_cache[$category_name];
                    }
                }
                if (!empty($category_ids)) {
                    $product->set_category_ids($category_ids);
                }
            }
            
            // Save product
            $product_id = $product->save();
            
            if (!$product_id) {
                $errors++;
                continue;
            }
            
            // Set external image
            if (!empty($product_data['images'][0]['urls'][0]['url'])) {
                update_post_meta($product_id, '_thumbnail_external_url', $product_data['images'][0]['urls'][0]['url']);
            }
            
            // Store all Amrod metadata (includes branding guides)
            $this->sync_product_meta($product_id, $product_data);
            
            $processed++;
        }
        
        return array('processed' => $processed, 'errors' => $errors);
    }
    
    /**
     * Enable performance optimizations for batch sync
     */
    private function enable_performance_mode() {
        // Defer term counting (HUGE performance boost - recounts only once at end)
        wp_defer_term_counting(true);
        
        // Defer comment counting
        wp_defer_comment_counting(true);
        
        // Suspend cache invalidation (speeds up database operations)
        wp_suspend_cache_invalidation(true);
        
        // Remove unnecessary WordPress actions
        remove_action('transition_post_status', '_update_blog_date_on_post_publish', 10);
        remove_action('transition_post_status', '_update_posts_count_on_transition_post_status', 10);
        
        // Remove WooCommerce product sync actions (we'll do it ourselves)
        remove_action('woocommerce_new_product', 'wc_delete_product_transients');
        remove_action('woocommerce_update_product', 'wc_delete_product_transients');
        
        $this->logger->log('info', 'Performance mode enabled for batch processing', array(), 'product_sync');
    }
    
    /**
     * Disable performance optimizations after batch sync
     */
    private function disable_performance_mode() {
        // Re-enable term counting
        wp_defer_term_counting(false);
        
        // Re-enable comment counting  
        wp_defer_comment_counting(false);
        
        // Re-enable cache invalidation
        wp_suspend_cache_invalidation(false);
        
        $this->logger->log('info', 'Performance mode disabled', array(), 'product_sync');
    }
    
    /**
     * Clean up simple product to ensure no variable product remnants
     */
    private function clean_simple_product_attributes($product_id) {
        global $wpdb;
        
        // Remove any variable product attributes
        $wpdb->delete(
            $wpdb->postmeta,
            array(
                'post_id' => $product_id,
                'meta_key' => '_product_attributes'
            )
        );
        
        // Remove any variation-related meta
        $variation_meta_keys = array(
            '_default_attributes',
            '_product_variation_shipping_class',
            '_product_variation_shipping_class_id'
        );
        
        foreach ($variation_meta_keys as $meta_key) {
            delete_post_meta($product_id, $meta_key);
        }
        
        // Ensure product type is simple
        wp_set_object_terms($product_id, 'simple', 'product_type');
        
        $this->logger->log('info', 'Cleaned simple product attributes', array('product_id' => $product_id), 'product_sync');
    }
    
    /**
     * Sync single product (handles Amrod's data structure)
     */
    public function sync_single_product($product_data, $force = false) {
        // Static counter for reduced logging
        static $product_counter = 0;
        $product_counter++;
        $should_log = ($product_counter % 10 === 0); // Log every 10th product
        
        // Amrod uses 'simpleCode' or 'fullCode' as SKU
        $sku = $product_data['simpleCode'] ?? $product_data['fullCode'] ?? null;
        
        if (!$sku) {
            $this->logger->log('error', 'Product missing SKU', array(), 'product_sync');
            return array('success' => false, 'message' => 'Missing SKU');
        }
        
        $sku = sanitize_text_field($sku);
        
        // Check if product has variants - trust the API structure
        // Amrod API: If variants array exists, create variable product
        $has_variants = $this->check_if_variable($product_data);
        
        // Reduced logging - only every 10th product
        if ($should_log) {
            $this->logger->log('info', 'Product type determination', array(
                'sku' => $sku,
                'product_count' => $product_counter,
                'has_variants' => $has_variants,
                'variant_count' => isset($product_data['variants']) ? count($product_data['variants']) : 0,
            ), 'product_sync');
        }
        
        if ($has_variants) {
            // Create/update as Variable Product with variations
            try {
                return $this->sync_variable_product($product_data, $sku, $force);
            } catch (Exception $e) {
                $this->logger->log('error', 'Variable product sync failed - falling back to simple product', array(
                    'sku' => $sku,
                    'error' => $e->getMessage(),
                ), 'product_sync');
                
                // Fallback: Create as simple product to prevent total failure
                $has_variants = false; // Force simple product creation
            }
        }
        
        // Otherwise create/update as Simple Product
        $product_id = wc_get_product_id_by_sku($sku);
        
        if ($product_id && !$force) {
            // Update existing product
            $product = wc_get_product($product_id);
            
            // If existing product is variable but we want simple, convert it
            if ($product && $product->is_type('variable')) {
                $this->logger->log('info', 'Converting variable product to simple', array('product_id' => $product_id), 'product_sync');
                
                // Delete all variations first
                $variations = $product->get_children();
                foreach ($variations as $variation_id) {
                    wp_delete_post($variation_id, true);
                }
                
                // Delete the variable product
                wp_delete_post($product_id, true);
                $product_id = null;
            } else if ($product && $product->is_type('simple')) {
                // Clean up any variable product remnants from existing simple product
                $this->clean_simple_product_attributes($product_id);
            }
        } else {
            // Create new simple product
            $product = new WC_Product_Simple();
        }
        
        try {
            // Set basic product data (Amrod's field names)
            $product->set_sku($sku);
            $product->set_name(sanitize_text_field($product_data['productName'] ?? ''));
            $product->set_description(wp_kses_post($product_data['description'] ?? ''));
            
            // Set categories (Amrod returns nested category objects)
            if (!empty($product_data['categories']) && is_array($product_data['categories'])) {
                $category_ids = $this->sync_product_categories($product_data['categories']);
                $product->set_category_ids($category_ids);
            }
            
            // Set brand
            if (!empty($product_data['brand'])) {
                $this->set_product_brand($product, $product_data['brand']);
            }
            
            // Note: Stock and prices are synced separately via their own endpoints
            // Amrod recommends separate syncs for better performance
            
            // Save product
            $product_id = $product->save();
            
            // Sync images (Amrod returns image objects with URLs and metadata)
            // Images are optional - if they fail, product still syncs
            if (!empty($product_data['images']) && is_array($product_data['images'])) {
                try {
                $this->sync_product_images($product_id, $product_data['images']);
                } catch (Exception $e) {
                    $this->logger->log('warning', 'Image sync failed but product created', array(
                        'product_id' => $product_id,
                        'error' => $e->getMessage(),
                    ), 'image_sync');
                }
            }
            
            // Store Amrod-specific metadata (includes branding guides, color swatches, etc.)
            $this->sync_product_meta($product_id, $product_data);
            
            // Ensure simple product doesn't have variable product attributes
            $this->clean_simple_product_attributes($product_id);
            
            // Store stock from product data if available
            if (isset($product_data['stock']) && is_numeric($product_data['stock'])) {
                $stock_qty = (int) $product_data['stock'];
                $product->set_manage_stock(true);
                $product->set_stock_quantity($stock_qty);
                $product->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');
            }
            
            // Reduced logging for performance - only log every 10th product
            static $sync_counter = 0;
            $sync_counter++;
            if ($sync_counter % 10 === 0) {
                $this->logger->log('info', "Synced {$sync_counter} products (last: {$sku})", array(), 'product_sync');
            }
            
            return array('success' => true, 'product_id' => $product_id);
            
        } catch (Exception $e) {
            $this->logger->log('error', "Failed to sync product: {$sku}", array(
                'sku' => $sku,
                'error' => $e->getMessage(),
            ), 'product_sync');
            
            return array('success' => false, 'message' => $e->getMessage());
        }
    }
    
    /**
     * Sync product categories from Amrod format
     */
    private function sync_product_categories($categories) {
        $category_ids = array();
        
        foreach ($categories as $cat_data) {
            if (empty($cat_data['name'])) {
                continue;
            }
            
            $cat_name = sanitize_text_field($cat_data['name']);
            
            // Try to find existing category by Amrod ID first
            if (!empty($cat_data['id'])) {
                $existing = get_terms(array(
                    'taxonomy' => 'product_cat',
                    'meta_key' => 'amrod_category_id',
                    'meta_value' => $cat_data['id'],
                    'hide_empty' => false,
                ));
                
                if (!empty($existing) && !is_wp_error($existing)) {
                    $category_ids[] = $existing[0]->term_id;
                    continue;
                }
            }
            
            // Find or create by name
            $term = get_term_by('name', $cat_name, 'product_cat');
            
            if (!$term) {
                $result = wp_insert_term($cat_name, 'product_cat', array(
                    'description' => $cat_data['path'] ?? '',
                ));
                
                if (!is_wp_error($result)) {
                    $term_id = $result['term_id'];
                    
                    // Store Amrod category metadata
                    if (!empty($cat_data['id'])) {
                        update_term_meta($term_id, 'amrod_category_id', $cat_data['id']);
                    }
                    if (!empty($cat_data['code'])) {
                        update_term_meta($term_id, 'amrod_category_code', $cat_data['code']);
                    }
                    
                    $category_ids[] = $term_id;
                }
            } else {
                $category_ids[] = $term->term_id;
            }
        }
        
        return $category_ids;
    }
    
    /**
     * Sync product images from Amrod format
     */
    private function sync_product_images($product_id, $images) {
        $image_ids = array();
        $default_image_id = null;
        
        $this->logger->log('info', 'Starting image sync', array(
            'product_id' => $product_id,
            'image_count' => count($images),
            'images_structure' => array_map(function($img) {
                return array(
                    'name' => $img['name'] ?? 'unknown',
                    'isDefault' => $img['isDefault'] ?? false,
                    'urls_count' => isset($img['urls']) ? count($img['urls']) : 0,
                );
            }, $images),
        ), 'image_sync');
        
        foreach ($images as $image_data) {
            if (empty($image_data['urls']) || !is_array($image_data['urls'])) {
                $this->logger->log('warning', 'Skipping image - no urls array', array(
                    'image_data' => $image_data,
                    'product_id' => $product_id,
                ), 'image_sync');
                continue;
            }
            
            // Get the highest resolution image
            $image_url = '';
            $max_width = 0;
            
            foreach ($image_data['urls'] as $url_data) {
                if (!empty($url_data['url']) && $url_data['width'] > $max_width) {
                    $image_url = $url_data['url'];
                    $max_width = $url_data['width'];
                }
            }
            
            if (empty($image_url)) {
                $this->logger->log('warning', 'Skipping image - no URL found', array(
                    'image_name' => $image_data['name'] ?? 'unknown',
                    'product_id' => $product_id,
                ), 'image_sync');
                continue;
            }
            
            // Store image URL (don't download - use Amrod CDN directly!)
                if (!empty($image_data['isDefault'])) {
                $default_image_id = $image_url; // Store URL, not attachment ID
                } else {
                $image_ids[] = $image_url; // Store URL, not attachment ID
            }
        }
        
        // Store image URLs as meta (not WordPress attachments)
            if ($default_image_id) {
            update_post_meta($product_id, '_thumbnail_external_url', $default_image_id);
            update_post_meta($product_id, '_amrod_featured_image', $default_image_id);
            }
            
            if (!empty($image_ids)) {
            update_post_meta($product_id, '_amrod_gallery_images', $image_ids);
        }
        
        // Store all image URLs together
        $all_images = $default_image_id ? array_merge(array($default_image_id), $image_ids) : $image_ids;
        if (!empty($all_images)) {
            update_post_meta($product_id, '_amrod_all_images', $all_images);
        }
        
        $this->logger->log('success', 'Image URLs stored (using Amrod CDN)', array(
            'product_id' => $product_id,
            'featured_url' => $default_image_id,
            'gallery_count' => count($image_ids),
        ), 'image_sync');
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
        
        // Check if brand taxonomy exists (many themes/plugins use 'product_brand')
        if (taxonomy_exists('product_brand')) {
            $term = get_term_by('name', $brand_name, 'product_brand');
            
            if (!$term) {
                $result = wp_insert_term($brand_name, 'product_brand');
                
                if (!is_wp_error($result)) {
                    wp_set_object_terms($product->get_id(), $result['term_id'], 'product_brand');
                }
            } else {
                wp_set_object_terms($product->get_id(), $term->term_id, 'product_brand');
            }
        } else {
            // Store as meta if taxonomy doesn't exist
            update_post_meta($product->get_id(), '_product_brand', sanitize_text_field($brand_name));
        }
    }
    
    /**
     * Sync product meta data (Amrod-specific fields)
     */
    private function sync_product_meta($product_id, $product_data) {
        // Store Amrod codes for reference
        if (!empty($product_data['simpleCode'])) {
            update_post_meta($product_id, '_amrod_simple_code', sanitize_text_field($product_data['simpleCode']));
        }
        
        if (!empty($product_data['fullCode'])) {
            update_post_meta($product_id, '_amrod_full_code', sanitize_text_field($product_data['fullCode']));
        }
        
        // Store branding guides (important for customer downloads!)
        if (!empty($product_data['fullBrandingGuide'])) {
            update_post_meta($product_id, '_amrod_full_branding_guide', esc_url_raw($product_data['fullBrandingGuide']));
        }
        
        if (!empty($product_data['logo24BrandingGuide'])) {
            update_post_meta($product_id, '_amrod_logo24_branding_guide', esc_url_raw($product_data['logo24BrandingGuide']));
        }
        
        // Store branding information if available
        if (!empty($product_data['brandings'])) {
            update_post_meta($product_id, '_amrod_brandings', $product_data['brandings']);
        }
        
        // Store color swatches for future swatch functionality
        if (!empty($product_data['colourImages'])) {
            update_post_meta($product_id, '_amrod_colour_images', $product_data['colourImages']);
            
            // Extract simplified swatch data
            $swatches = array();
            foreach ($product_data['colourImages'] as $color) {
                $swatch_images = array();
                
                if (!empty($color['images']) && is_array($color['images'])) {
                    foreach ($color['images'] as $img) {
                        if (!empty($img['urls']) && is_array($img['urls'])) {
                            foreach ($img['urls'] as $url_data) {
                                if (!empty($url_data['url'])) {
                                    $swatch_images[] = $url_data['url'];
                                    break;
                                }
                            }
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
            }
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
        if (!empty($product_data['companionCodes'])) {
            update_post_meta($product_id, '_amrod_companion_codes', $product_data['companionCodes']);
        }
        
        if (!empty($product_data['relatedCodes'])) {
            update_post_meta($product_id, '_amrod_related_codes', $product_data['relatedCodes']);
        }
        
        // Store promotion flag
        if (isset($product_data['promotion'])) {
            update_post_meta($product_id, '_amrod_promotion', $product_data['promotion']);
        }
        
        // Store last sync timestamp
        update_post_meta($product_id, '_amrod_last_sync', current_time('mysql'));
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
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(
            'total_items' => $total,
            'batch_size' => 100,
            'batch_count' => $batch_count,
        ), 'stock_sync');
        
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
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(
            'total_items' => $total,
            'batch_size' => 100,
            'batch_count' => $batch_count,
        ), 'stock_sync');
        
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
        
        // Split into batches (larger batches for prices - simpler data)
        $batches = array_chunk($prices_data, 100);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(
            'total_items' => $total,
            'batch_size' => 100,
            'batch_count' => $batch_count,
        ), 'price_sync');
        
        // Just store minimal sync info
        update_option("bytemash_sync_{$sync_id}", array(
            'type' => 'prices',
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
            'message' => "Ready to sync {$total} prices in {$batch_count} batches",
                'sync_id' => $sync_id,
                'total' => $total,
            'batch_count' => $batch_count,
            'batches' => $batches,
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
        
        // Split into batches (same as full price sync)
        $batches = array_chunk($prices_data, 100);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(
            'total_items' => $total,
            'batch_size' => 100,
            'batch_count' => $batch_count,
        ), 'price_sync');
        
        // Store minimal sync info
        update_option("bytemash_sync_{$sync_id}", array(
            'type' => 'prices',
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
            'message' => "Ready to sync {$total} price updates in {$batch_count} batches",
                'sync_id' => $sync_id,
                'total' => $total,
            'batch_count' => $batch_count,
            'batches' => $batches,
        );
    }
    
    /**
     * ULTRA FAST: Update stock for entire batch at once (3 SQL queries instead of 300+)
     */
    public function update_batch_stock($stock_items) {
        global $wpdb;
        
        // Build SKU to stock mapping with detailed data
        $sku_stock_map = array();
        foreach ($stock_items as $item) {
            $simpleCode = $item['simpleCode'] ?? $item['simplecode'] ?? '';
            if (empty($simpleCode)) continue;
            
            $stock_qty = isset($item['stock']) ? (int) $item['stock'] : 0;
            $reserved_stock = isset($item['reservedStock']) ? (int) $item['reservedStock'] : 0;
            $incoming_stock = $item['incomingStock'] ?? null;
            
            $sku_stock_map[$simpleCode] = array(
                'stock' => $stock_qty,
                'reserved' => $reserved_stock,
                'incoming' => $incoming_stock
            );
        }
        
        if (empty($sku_stock_map)) {
            return array('processed' => 0, 'errors' => 0);
        }
        
        // Get ALL product IDs for ALL SKUs - build OR conditions safely
        $like_conditions = array();
        foreach (array_keys($sku_stock_map) as $sku) {
            $like_conditions[] = $wpdb->prepare("meta_value LIKE %s", $wpdb->esc_like($sku) . '%');
        }
        
        if (empty($like_conditions)) {
            return array('processed' => 0, 'errors' => 0);
        }
        
        $like_sql = implode(' OR ', $like_conditions);
        $product_sku_map = $wpdb->get_results(
            "SELECT post_id, meta_value as sku FROM {$wpdb->postmeta} 
            WHERE meta_key = '_sku' 
            AND ({$like_sql})",
            ARRAY_A
        );
        
        if (empty($product_sku_map)) {
            return array('processed' => 0, 'errors' => 0);
        }
        
        // Build CASE statements for bulk update
        $stock_qty_cases = array();
        $stock_status_cases = array();
        $product_ids = array();
        $detailed_stock_data = array(); // Store detailed data for later
        $matched_count = 0;
        $unmatched_count = 0;
        
        foreach ($product_sku_map as $row) {
            $product_id = $row['post_id'];
            $sku = $row['sku'];
            
            // Find matching simple code
            $stock_data = null;
            foreach ($sku_stock_map as $simple_code => $data) {
                if (strpos($sku, $simple_code) === 0) {
                    $stock_data = $data;
                    break;
                }
            }
            
            if ($stock_data === null) {
                $unmatched_count++;
                continue;
            }
            
            $stock_qty = $stock_data['stock'];
            $product_ids[] = (int) $product_id;
            $stock_status = $stock_qty > 0 ? 'instock' : 'outofstock';
            $matched_count++;
            
            $stock_qty_cases[] = $wpdb->prepare("WHEN %d THEN %s", $product_id, $stock_qty);
            $stock_status_cases[] = $wpdb->prepare("WHEN %d THEN %s", $product_id, $stock_status);
            
            // Store detailed data for this product
            $detailed_stock_data[$product_id] = $stock_data;
        }
        
        if (empty($product_ids)) {
            return array('processed' => 0, 'errors' => 0);
        }
        
        // PERFORMANCE: Disable unnecessary actions during bulk update
        $actions_to_remove = array(
            'woocommerce_update_product',
            'woocommerce_product_set_stock',
            'woocommerce_variation_set_stock',
            'woocommerce_product_object_updated_props'
        );
        
        foreach ($actions_to_remove as $action) {
            remove_all_actions($action);
        }
        
        // Use WooCommerce's proper stock management functions
        $updated_count = 0;
        $error_count = 0;
        $updated_skus = array();
        
        foreach ($product_ids as $pid) {
            if (!isset($detailed_stock_data[$pid])) continue;
            
            $data = $detailed_stock_data[$pid];
            $stock_qty = $data['stock'];
            
            // Get WooCommerce product object
            $product = wc_get_product($pid);
            if (!$product) {
                $error_count++;
                continue;
            }
            
            // Set stock using WooCommerce methods
            $product->set_manage_stock(true);
            $product->set_stock_quantity($stock_qty);
            $product->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');
            
            // Save the product (this handles all the meta updates correctly)
            $product->save();
            
            // Store custom Amrod data
            update_post_meta($pid, '_amrod_reserved_stock', $data['reserved']);
            
            if (!empty($data['incoming']) && is_array($data['incoming'])) {
                update_post_meta($pid, '_amrod_incoming_stock', json_encode($data['incoming']));
            } else {
                delete_post_meta($pid, '_amrod_incoming_stock');
            }
            
            // Track updated SKUs
            $sku = $product->get_sku();
            if ($sku) {
                $updated_skus[] = "{$sku}={$stock_qty}";
            }
            
            $updated_count++;
        }
        
        // Log summary of stock updates with SKUs
        if ($updated_count > 0) {
            $summary = "Stock Update: Successfully updated {$updated_count} products" . ($error_count > 0 ? ", {$error_count} errors" : "");
            error_log($summary);
            
            // Log SKUs (in chunks to avoid huge log lines)
            $chunk_size = 20;
            $sku_chunks = array_chunk($updated_skus, $chunk_size);
            foreach ($sku_chunks as $index => $chunk) {
                $chunk_num = $index + 1;
                error_log("Stock Update SKUs (batch {$chunk_num}/" . count($sku_chunks) . "): " . implode(', ', $chunk));
            }
        }
        
        // FAST: Minimal cache clearing (only what's absolutely necessary)
        wp_cache_flush(); // Single global flush is faster than individual clears
        
        
        $this->logger->log('info', 'Batch stock updated: ' . count($product_ids) . ' products', array(), 'stock_sync');
        
        return array('processed' => count($stock_items), 'errors' => 0);
    }
    
    /**
     * Update stock for a single product
     */
    public function update_single_stock($stock_item) {
        // Stock item structure: {simpleCode/simplecode, fullCode, stock}
        $fullCode = $stock_item['fullCode'] ?? '';
        $simpleCode = $stock_item['simpleCode'] ?? $stock_item['simplecode'] ?? '';
        
        if (empty($fullCode) && empty($simpleCode)) {
            return array('success' => false, 'message' => 'No SKU in stock data');
        }
        
        // PERFORMANCE: Only log every 50th stock update
        static $stock_counter = 0;
        $stock_counter++;
        $should_log = ($stock_counter % 50 === 0);
        
        global $wpdb;
        $stock_qty = isset($stock_item['stock']) ? (int) $stock_item['stock'] : 0;
        $stock_status = $stock_qty > 0 ? 'instock' : 'outofstock';
        $reserved_stock = isset($stock_item['reservedStock']) ? (int) $stock_item['reservedStock'] : 0;
        $incoming_stock = $stock_item['incomingStock'] ?? null;
        
        // Build SKU search patterns
        $skus_to_try = array_filter(array(
            $fullCode,
            $simpleCode,
            preg_replace('/-0-0$/', '', $fullCode)
        ));
        
        $product_ids = array();
        
        // FAST: Single query to find all matching products (exact + pattern)
        if (!empty($simpleCode)) {
            $like_pattern = $wpdb->esc_like($simpleCode) . '%';
            $sku_list = "'" . implode("','", array_map('esc_sql', $skus_to_try)) . "'";
            
            $product_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_sku' 
                AND (meta_value IN ({$sku_list}) OR meta_value LIKE %s)
                LIMIT 50",
                $like_pattern
            ));
        }
        
        if (empty($product_ids)) {
            if ($stock_counter % 100 === 0) { // Only log every 100th miss
                $this->logger->log('warning', "Stock: No match for {$simpleCode}", array(), 'stock_sync');
            }
            return array('success' => false, 'message' => "Product not found");
        }
        
        // FAST: Bulk SQL update instead of loading WC objects
        $post_ids = implode(',', array_map('intval', $product_ids));
        
        // Update stock quantity
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
            SELECT ID, '_stock', %s FROM {$wpdb->posts}
            WHERE ID IN ({$post_ids})
            ON DUPLICATE KEY UPDATE meta_value = %s",
            $stock_qty, $stock_qty
        ));
        
        // Update stock status
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
            SELECT ID, '_stock_status', %s FROM {$wpdb->posts}
            WHERE ID IN ({$post_ids})
            ON DUPLICATE KEY UPDATE meta_value = %s",
            $stock_status, $stock_status
        ));
        
        // Set manage stock to 'yes'
        $wpdb->query(
            "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
            SELECT ID, '_manage_stock', 'yes' FROM {$wpdb->posts}
            WHERE ID IN ({$post_ids})
            ON DUPLICATE KEY UPDATE meta_value = 'yes'"
        );
        
        // Store detailed stock information for all matched products
        foreach ($product_ids as $pid) {
            update_post_meta($pid, '_amrod_reserved_stock', $reserved_stock);
            
            if (!empty($incoming_stock) && is_array($incoming_stock)) {
                update_post_meta($pid, '_amrod_incoming_stock', json_encode($incoming_stock));
            } else {
                delete_post_meta($pid, '_amrod_incoming_stock');
            }
        }
        
        // Clear WooCommerce cache for these products
        foreach ($product_ids as $pid) {
            wp_cache_delete($pid, 'posts');
            wp_cache_delete($pid, 'post_meta');
        }
        
        if ($should_log) {
            $this->logger->log('info', "Stock updated: {$stock_counter} items (last: {$simpleCode}, qty: {$stock_qty})", array(), 'stock_sync');
        }
        
        return array('success' => true, 'sku' => $simpleCode, 'stock' => $stock_qty, 'updated_count' => count($product_ids));
    }
    
    /**
     * ULTRA FAST: Update prices for entire batch at once (3-4 SQL queries instead of 400+)
     */
    public function update_batch_prices($price_items) {
        global $wpdb;
        
        // Build SKU to price mapping
        $sku_price_map = array();
        foreach ($price_items as $item) {
            $simpleCode = $item['simpleCode'] ?? $item['simplecode'] ?? '';
            if (empty($simpleCode)) continue;
            
            $sku_price_map[$simpleCode] = array(
                'regular_price' => $item['price'] ?? 0,
                'sale_price' => $item['salePrice'] ?? 0
            );
        }
        
        if (empty($sku_price_map)) {
            return array('processed' => 0, 'errors' => 0);
        }
        
        // Get ALL product IDs for ALL SKUs - build OR conditions safely
        $like_conditions = array();
        foreach (array_keys($sku_price_map) as $sku) {
            $like_conditions[] = $wpdb->prepare("meta_value LIKE %s", $wpdb->esc_like($sku) . '%');
        }
        
        if (empty($like_conditions)) {
            return array('processed' => 0, 'errors' => 0);
        }
        
        $like_sql = implode(' OR ', $like_conditions);
        $product_sku_map = $wpdb->get_results(
            "SELECT post_id, meta_value as sku FROM {$wpdb->postmeta} 
            WHERE meta_key = '_sku' 
            AND ({$like_sql})",
            ARRAY_A
        );
        
        if (empty($product_sku_map)) {
            return array('processed' => 0, 'errors' => 0);
        }
        
        // Build CASE statements for bulk update
        $regular_price_cases = array();
        $sale_price_cases = array();
        $display_price_cases = array();
        $product_ids = array();
        
        foreach ($product_sku_map as $row) {
            $product_id = $row['post_id'];
            $sku = $row['sku'];
            
            // Find matching simple code
            $prices = null;
            foreach ($sku_price_map as $simple_code => $price_data) {
                if (strpos($sku, $simple_code) === 0) {
                    $prices = $price_data;
                    break;
                }
            }
            
            if ($prices === null) continue;
            
            $product_ids[] = (int) $product_id;
            $regular_price = $prices['regular_price'];
            $sale_price = $prices['sale_price'];
            $display_price = $sale_price > 0 ? $sale_price : $regular_price;
            
            if ($regular_price > 0) {
                $regular_price_cases[] = $wpdb->prepare("WHEN %d THEN %s", $product_id, $regular_price);
                $display_price_cases[] = $wpdb->prepare("WHEN %d THEN %s", $product_id, $display_price);
            }
            if ($sale_price > 0) {
                $sale_price_cases[] = $wpdb->prepare("WHEN %d THEN %s", $product_id, $sale_price);
            }
        }
        
        if (empty($product_ids)) {
            return array('processed' => 0, 'errors' => 0);
        }
        
        $product_ids_str = implode(',', $product_ids);
        
        // BULK UPDATE 1: Regular prices (ONE query for ALL products)
        if (!empty($regular_price_cases)) {
            $regular_price_case_sql = implode(' ', $regular_price_cases);
            $wpdb->query(
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                SELECT ID, '_regular_price', CASE ID {$regular_price_case_sql} END
                FROM {$wpdb->posts}
                WHERE ID IN ({$product_ids_str})
                ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
            );
        }
        
        // BULK UPDATE 2: Display prices (ONE query for ALL products)
        if (!empty($display_price_cases)) {
            $display_price_case_sql = implode(' ', $display_price_cases);
            $wpdb->query(
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                SELECT ID, '_price', CASE ID {$display_price_case_sql} END
                FROM {$wpdb->posts}
                WHERE ID IN ({$product_ids_str})
                ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
            );
        }
        
        // BULK UPDATE 3: Sale prices (ONE query for ALL products with sale prices)
        if (!empty($sale_price_cases)) {
            $sale_price_case_sql = implode(' ', $sale_price_cases);
            $wpdb->query(
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                SELECT ID, '_sale_price', CASE ID {$sale_price_case_sql} END
                FROM {$wpdb->posts}
                WHERE ID IN ({$product_ids_str})
                ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
            );
        }
        
        // Clear cache for all products
        foreach ($product_ids as $pid) {
            wp_cache_delete($pid, 'posts');
            wp_cache_delete($pid, 'post_meta');
        }
        
        $this->logger->log('info', 'Batch prices updated: ' . count($product_ids) . ' products', array(), 'price_sync');
        
        return array('processed' => count($price_items), 'errors' => 0);
    }
    
    /**
     * Update price for a single product
     */
    public function update_single_price($price_item) {
        // Price item structure: {simpleCode/simplecode, fullCode, price, salePrice}
        $fullCode = $price_item['fullCode'] ?? '';
        $simpleCode = $price_item['simpleCode'] ?? $price_item['simplecode'] ?? '';
        
        if (empty($fullCode) && empty($simpleCode)) {
            return array('success' => false, 'message' => 'No SKU in price data');
        }
        
        // PERFORMANCE: Only log every 50th price update
        static $price_counter = 0;
        $price_counter++;
        $should_log = ($price_counter % 50 === 0);
        
        global $wpdb;
        $regular_price = $price_item['price'] ?? 0;
        $sale_price = $price_item['salePrice'] ?? 0;
        
        // Build SKU search patterns
        $skus_to_try = array_filter(array(
            $fullCode,
            $simpleCode,
            preg_replace('/-0-0$/', '', $fullCode)
        ));
        
        // FAST: Single query to find all matching products (exact + pattern)
        if (!empty($simpleCode)) {
            $like_pattern = $wpdb->esc_like($simpleCode) . '%';
            $sku_list = "'" . implode("','", array_map('esc_sql', $skus_to_try)) . "'";
            
            $product_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_sku' 
                AND (meta_value IN ({$sku_list}) OR meta_value LIKE %s)
                LIMIT 50",
                $like_pattern
            ));
        }
        
        if (empty($product_ids)) {
            if ($price_counter % 100 === 0) { // Only log every 100th miss
                $this->logger->log('warning', "Price: No match for {$simpleCode}", array(), 'price_sync');
            }
            return array('success' => false, 'message' => "Product not found");
        }
        
        // FAST: Bulk SQL update instead of loading WC objects
        $post_ids = implode(',', array_map('intval', $product_ids));
        
        // Update regular price
        if ($regular_price > 0) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                SELECT ID, '_regular_price', %s FROM {$wpdb->posts}
                WHERE ID IN ({$post_ids})
                ON DUPLICATE KEY UPDATE meta_value = %s",
                $regular_price, $regular_price
            ));
            
            // Also update _price (WooCommerce uses this for display)
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                SELECT ID, '_price', %s FROM {$wpdb->posts}
                WHERE ID IN ({$post_ids})
                ON DUPLICATE KEY UPDATE meta_value = %s",
                $regular_price, $regular_price
            ));
        }
        
        // Update sale price if provided
        if ($sale_price > 0) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                SELECT ID, '_sale_price', %s FROM {$wpdb->posts}
                WHERE ID IN ({$post_ids})
                ON DUPLICATE KEY UPDATE meta_value = %s",
                $sale_price, $sale_price
            ));
            
            // Update _price to sale price (WooCommerce displays sale price)
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                SELECT ID, '_price', %s FROM {$wpdb->posts}
                WHERE ID IN ({$post_ids})
                ON DUPLICATE KEY UPDATE meta_value = %s",
                $sale_price, $sale_price
            ));
        }
        
        // Clear WooCommerce cache for these products
        foreach ($product_ids as $pid) {
            wp_cache_delete($pid, 'posts');
            wp_cache_delete($pid, 'post_meta');
        }
        
        if ($should_log) {
            $this->logger->log('info', "Prices updated: {$price_counter} items (last: {$simpleCode}, price: {$regular_price})", array(), 'price_sync');
        }
        
        return array('success' => true, 'sku' => $simpleCode, 'price' => $regular_price, 'updated_count' => count($product_ids));
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
        $this->logger->log('info', "Found {$total} orphan products without prices", array('total' => $total), 'price_sync_orphan');
        
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
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(
            'total_orphans' => $total,
            'batch_size' => 50,
            'batch_count' => $batch_count,
        ), 'price_sync_orphan');
        
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
        
        $this->logger->log('success', "✅ Orphan matched: {$sku} → prefix: {$prefix} → price: " . ($matched_price['price'] ?? 0), array(
            'sku' => $sku,
            'product_id' => $product_id,
            'prefix' => $prefix,
            'price' => $matched_price['price'] ?? 0,
        ), 'price_sync_orphan');
        
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
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(
            'total_brands' => $total,
            'batch_size' => 50,
            'batch_count' => $batch_count,
        ), 'brands_sync');
        
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
        
        $this->logger->log('success', "Brand synced: {$brand_name}", array(
            'code' => $brand_code,
        ), 'brands_sync');
        
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
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(
            'total_departments' => $total,
            'batch_count' => $batch_count,
        ), 'branding_sync');
        
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
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(
            'total_price_groups' => $total,
            'batch_count' => $batch_count,
        ), 'branding_sync');
        
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
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(
            'total_brandings' => $total,
            'batch_count' => $batch_count,
        ), 'branding_sync');
        
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
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(
            'total_swatches' => $total,
            'batch_count' => $batch_count,
        ), 'color_swatches_sync');
        
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
        
        $this->logger->log('success', "Color swatch synced: {$color_name}", array(
            'code' => $color_code,
            'hex' => $swatch_data['hexValue'] ?? 'N/A',
        ), 'color_swatches_sync');
        
        return array('success' => true, 'code' => $color_code, 'name' => $color_name);
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
        
        $this->logger->log('info', "Flattened hierarchical categories", array(
            'original_count' => count($categories),
            'flattened_count' => count($flat_categories),
        ), 'category_sync');
        
        $total = count($flat_categories);
        $sync_id = 'categories_' . time() . '_' . wp_generate_password(8, false);
        
        // Split into batches (smaller batches for categories due to hierarchical processing)
        $batches = array_chunk($flat_categories, 25);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(
            'total_categories' => $total,
            'batch_size' => 25,
            'batch_count' => $batch_count,
        ), 'category_sync');
        
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
        $parent_path = $category_data['_parent_path'] ?? '';
        
        if (empty($category_name)) {
            $this->logger->log('error', 'Category missing name', array(
                'data' => $category_data,
            ), 'category_sync');
            return array('success' => false, 'message' => 'Category missing name');
        }
        
        try {
            // Find parent category ID if this is a child category
            $parent_id = 0;
            if (!empty($parent_path)) {
                // Try to find parent by its path
                $parent_terms = get_terms(array(
                    'taxonomy' => 'product_cat',
                    'meta_key' => '_amrod_category_path',
                    'meta_value' => $parent_path,
                    'hide_empty' => false,
                    'number' => 1,
                ));
                
                if (!empty($parent_terms) && !is_wp_error($parent_terms)) {
                    $parent_id = $parent_terms[0]->term_id;
                    $this->logger->log('info', "Found parent category for: {$category_name}", array(
                        'parent_path' => $parent_path,
                        'parent_id' => $parent_id,
                    ), 'category_sync');
                }
            }
            
            // Create or update category
            $term = term_exists($category_name, 'product_cat');
            
            if ($term) {
                // Update existing
                $term_id = $term['term_id'];
                
                // Update parent if needed
                if ($parent_id > 0) {
                    wp_update_term($term_id, 'product_cat', array('parent' => $parent_id));
                }
                
                $this->logger->log('info', "Category already exists: {$category_name}", array(
                    'term_id' => $term_id,
                    'parent_id' => $parent_id,
                ), 'category_sync');
            } else {
                // Create new with parent
                $args = array('slug' => sanitize_title($category_name));
                if ($parent_id > 0) {
                    $args['parent'] = $parent_id;
                }
                
                $result = wp_insert_term($category_name, 'product_cat', $args);
                
                if (is_wp_error($result)) {
                    $this->logger->log('error', "Failed to create category: {$category_name}", array(
                        'error' => $result->get_error_message(),
                        'error_code' => $result->get_error_code(),
                        'category_data' => $category_data,
                        'parent_id' => $parent_id,
                    ), 'category_sync');
                    return array('success' => false, 'message' => 'Failed to create category: ' . $result->get_error_message());
                }
                
                $term_id = $result['term_id'];
            }
            
            // Store Amrod metadata
            update_term_meta($term_id, '_amrod_category_path', $category_path);
            update_term_meta($term_id, '_amrod_category_code', $category_code);
            
            if (!empty($category_image)) {
                update_term_meta($term_id, '_amrod_category_image', esc_url_raw($category_image));
            }
            
            $this->logger->log('success', "Category synced: {$category_name}", array(
                'term_id' => $term_id,
                'code' => $category_code,
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
}

