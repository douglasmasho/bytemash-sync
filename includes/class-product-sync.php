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
            $this->logger->log('info', 'Creating/updating variable product with variations', array(), 'product_sync');
            
            // Check if parent product exists
            $product_id = wc_get_product_id_by_sku($parent_sku);
            
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
        
        // Save parent product using safe method
        $product_id = $this->save_product_safely($product);
        
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
        
        // Check if variants array is empty or missing
        if (empty($product_data['variants']) || !is_array($product_data['variants']) || count($product_data['variants']) === 0) {
            $this->logger->log('warning', 'No variants in product data - will convert to simple product', array(
                'product_id' => $product_id,
                'sku' => $parent_sku,
            ), 'product_sync');
        } else {
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
        }
        
        $this->logger->log('success', "Variable product synced: {$variation_count} variations created, {$variation_errors} errors", array(
            'product_id' => $product_id,
            'sku' => $parent_sku,
            'variations_created' => $variation_count,
            'variation_errors' => $variation_errors,
        ), 'product_sync');
        
        // If no variations were created, convert back to simple product
        if ($variation_count === 0) {
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
            
            return array('success' => true, 'product_id' => $new_product_id, 'message' => "Product converted to simple (no variations created)");
        }
        
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
        $variation_id = wc_get_product_id_by_sku($variant_sku);
        
        if ($variation_id) {
            $this->logger->log('info', 'Updating existing variation', array(), 'product_sync');
            $variation = new WC_Product_Variation($variation_id);
        } else {
            $this->logger->log('info', 'Creating new variation', array(), 'product_sync');
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
        
        // Check if product has variants (sizes/colors)
        // TEMPORARY: Can disable variable products via option for testing
        $enable_variable_products = get_option('bytemash_enable_variable_products', true);
        $has_variants = $enable_variable_products && !empty($product_data['variants']) && is_array($product_data['variants']) && count($product_data['variants']) > 0;
        
        // Check if existing product is variable but should be simple (no variants in API)
        $product_id = wc_get_product_id_by_sku($sku);
        if ($product_id && !$has_variants) {
            $existing_product = wc_get_product($product_id);
            if ($existing_product && $existing_product->is_type('variable')) {
                $this->logger->log('info', 'Product was variable but now has no variants - converting to simple', array(
                    'product_id' => $product_id,
                    'sku' => $sku,
                ), 'product_sync');
                
                // Get product data before deleting
                $product_name = $existing_product->get_name();
                $product_sku = $existing_product->get_sku();
                $product_description = $existing_product->get_description();
                $category_ids = $existing_product->get_category_ids();
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
                
                // Update product_id for the sync below
                $product_id = $new_product_id;
                
                // IMPORTANT: After conversion, sync_product_meta will be called which will sync branding from API
                // This ensures branding is always up-to-date from the API response
                // We excluded _amrod_brandings from meta restore above to prevent overwriting fresh API data
            }
        }
        
        $this->logger->log('info', 'Product variant check', array(), 'product_sync');
        
        if ($has_variants) {
            // Create/update as Variable Product with variations
            $this->logger->log('info', 'Routing to variable product sync', array(), 'product_sync');
            
            try {
                return $this->sync_variable_product($product_data, $sku, $force);
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
        
        $this->logger->log('info', 'Routing to simple product sync', array(), 'product_sync');
        
        // Otherwise create/update as Simple Product
        // Note: product_id may have been set above if we converted variable to simple
        if (!isset($product_id)) {
            $product_id = wc_get_product_id_by_sku($sku);
        }
        
        if ($product_id && !$force) {
            // Check if product data has changed before updating
            $existing_product = wc_get_product($product_id);
            $is_unchanged = $this->is_product_data_unchanged($existing_product, $product_data);
            
            // Check if brand needs to be updated even if product is otherwise unchanged
            $needs_brand_update = false;
            if (!empty($product_data['brand'])) {
                $existing_brand = get_post_meta($product_id, '_amrod_brand', true);
                $existing_brand_code = get_post_meta($product_id, '_amrod_brand_code', true);
                $api_brand = '';
                $api_brand_code = '';
                if (is_array($product_data['brand'])) {
                    $api_brand = $product_data['brand']['brandName'] ?? $product_data['brand']['name'] ?? $product_data['brand']['Brand'] ?? '';
                    $api_brand_code = $product_data['brand']['code'] ?? '';
                } else {
                    $api_brand = (string) $product_data['brand'];
                }
                
                // Need to update brand if: brand meta is missing, or brand name differs, or brand code differs
                if (empty($existing_brand) || $existing_brand !== $api_brand || $existing_brand_code !== $api_brand_code) {
                    $needs_brand_update = true;
                    
                    // Log that brand update is needed
                    $this->logger->log('info', "Brand update needed for product: {$sku}", array(
                        'product_id' => $product_id,
                        'sku' => $sku,
                        'existing_brand' => $existing_brand,
                        'new_brand' => $api_brand,
                        'existing_code' => $existing_brand_code,
                        'new_code' => $api_brand_code,
                    ), 'product_sync');
                }
            }
            
            // Check if branding options need to be updated even if product is otherwise unchanged
            $needs_branding_update = false;
            $existing_brandings = get_post_meta($product_id, '_amrod_brandings', true);
            $api_brandings = $product_data['brandings'] ?? null;
            
            // Need to update branding if: branding meta is missing but API has branding, or branding differs
            if (empty($existing_brandings) && !empty($api_brandings) && is_array($api_brandings)) {
                $needs_branding_update = true;
                $this->logger->log('info', "Branding options missing, update needed for product: {$sku}", array(
                    'product_id' => $product_id,
                    'sku' => $sku,
                ), 'product_sync');
            } elseif (!empty($existing_brandings) && (empty($api_brandings) || !is_array($api_brandings))) {
                // API no longer has branding, need to clear it
                $needs_branding_update = true;
                $this->logger->log('info', "Branding options need to be cleared for product: {$sku}", array(
                    'product_id' => $product_id,
                    'sku' => $sku,
                ), 'product_sync');
            } elseif (!empty($existing_brandings) && !empty($api_brandings) && is_array($api_brandings)) {
                // Compare branding options
                $existing_normalized = wp_json_encode($existing_brandings);
                $api_normalized = wp_json_encode($api_brandings);
                if ($existing_normalized !== $api_normalized) {
                    $needs_branding_update = true;
                    $this->logger->log('info', "Branding options changed, update needed for product: {$sku}", array(
                        'product_id' => $product_id,
                        'sku' => $sku,
                    ), 'product_sync');
                }
            }
            
            if ($is_unchanged && !$needs_brand_update && !$needs_branding_update) {
                $this->logger->log('info', "Product data unchanged, skipping: {$sku}", array(
                    'sku' => $sku,
                    'product_id' => $product_id,
                ), 'product_sync');
                return array('success' => true, 'product_id' => $product_id, 'skipped' => true, 'message' => 'Product data unchanged');
            }
            
            // Update existing product (data has changed or brand needs update)
            $product = $existing_product;
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
            
            // Always set brand if present in API data (even if product is otherwise unchanged)
            if (!empty($product_data['brand'])) {
                $this->set_product_brand($product, $product_data['brand']);
            }
            
            // Note: Stock and prices are synced separately via their own endpoints
            // Amrod recommends separate syncs for better performance
            
            // Save product using safe method
            $product_id = $this->save_product_safely($product);
            
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
            
            // Store stock from product data if available
            if (isset($product_data['stock']) && is_numeric($product_data['stock'])) {
                $stock_qty = (int) $product_data['stock'];
                $product->set_manage_stock(true);
                $product->set_stock_quantity($stock_qty);
                $product->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');
            }
            
            // Reduce noise: avoid per-product success logs
            
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
        
        // Compare categories
        $existing_categories = $existing_product->get_category_ids();
        $api_categories = array();
        
        if (!empty($api_data['categories']) && is_array($api_data['categories'])) {
            $api_categories = $this->sync_product_categories($api_data['categories']);
        }
        
        if (array_diff($existing_categories, $api_categories) || array_diff($api_categories, $existing_categories)) {
            return false;
        }
        
        // Compare brand - always update if brand meta is missing but API has brand data
        $existing_brand = get_post_meta($existing_product->get_id(), '_amrod_brand', true);
        $existing_brand_code = get_post_meta($existing_product->get_id(), '_amrod_brand_code', true);
        $api_brand = '';
        $api_brand_code = '';
        if (!empty($api_data['brand'])) {
            if (is_array($api_data['brand'])) {
                $api_brand = $api_data['brand']['brandName'] ?? $api_data['brand']['name'] ?? $api_data['brand']['Brand'] ?? '';
                $api_brand_code = $api_data['brand']['code'] ?? '';
            } else {
                $api_brand = (string) $api_data['brand'];
            }
        }
        
        // If brand meta is missing but API has brand data, consider it changed
        if (empty($existing_brand) && !empty($api_brand)) {
            return false; // Need to update to add brand
        }
        
        // If existing brand differs from API brand, consider it changed
        if (!empty($api_brand) && $existing_brand !== $api_brand) {
            return false;
        }
        
        // If brand code differs, consider it changed
        if (!empty($api_brand_code) && $existing_brand_code !== $api_brand_code) {
            return false;
        }
        
        // Compare images (check if image URLs have changed)
        $existing_images = $this->get_existing_product_images($existing_product->get_id());
        $api_images = $api_data['images'] ?? array();
        
        if (!$this->are_images_unchanged($existing_images, $api_images)) {
            return false;
        }
        
        // Compare branding options - always update if branding meta is missing but API has branding data
        $existing_brandings = get_post_meta($existing_product->get_id(), '_amrod_brandings', true);
        $api_brandings = $api_data['brandings'] ?? null;
        
        // If branding meta is missing but API has branding data, consider it changed
        if (empty($existing_brandings) && !empty($api_brandings) && is_array($api_brandings)) {
            return false; // Need to update to add branding
        }
        
        // If API has no branding but product has branding, consider it changed (to clear old branding)
        if (!empty($existing_brandings) && (empty($api_brandings) || !is_array($api_brandings))) {
            return false; // Need to update to clear branding
        }
        
        // If both exist, compare them (deep comparison)
        if (!empty($existing_brandings) && !empty($api_brandings) && is_array($api_brandings)) {
            // Normalize both arrays for comparison
            $existing_normalized = wp_json_encode($existing_brandings);
            $api_normalized = wp_json_encode($api_brandings);
            
            if ($existing_normalized !== $api_normalized) {
                return false; // Branding has changed
            }
        }
        
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
     */
    private function save_product_safely($product) {
        // Use WordPress's built-in bulk operation handling
        wp_defer_term_counting(true);
        wp_defer_comment_counting(true);
        
        try {
            // Save the product using WooCommerce's native method
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
            
            // Reduce noise: do not log per-product success
            
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
        } finally {
            // Re-enable counting
            wp_defer_term_counting(false);
            wp_defer_comment_counting(false);
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
        
        $this->logger->log('info', 'Starting image sync', array(), 'image_sync');
        
        foreach ($images as $image_data) {
            if (empty($image_data['urls']) || !is_array($image_data['urls'])) {
                $this->logger->log('warning', 'Skipping image - no urls array', array(), 'image_sync');
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
                $this->logger->log('warning', 'Skipping image - no URL found', array(), 'image_sync');
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
        } else if (!empty($image_ids)) {
            // If no default image specified, use the first image as featured
            $first_image = $image_ids[0];
            update_post_meta($product_id, '_thumbnail_external_url', $first_image);
            update_post_meta($product_id, '_amrod_featured_image', $first_image);
        }
        
        if (!empty($image_ids)) {
            update_post_meta($product_id, '_amrod_gallery_images', $image_ids);
        }
        
        // Store all image URLs together
        $all_images = $default_image_id ? array_merge(array($default_image_id), $image_ids) : $image_ids;
        if (!empty($all_images)) {
            update_post_meta($product_id, '_amrod_all_images', $all_images);
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
        
        update_post_meta($product_id, '_amrod_brand', sanitize_text_field($brand_name));
        
        $brand_code = '';
        if (is_array($brand_data)) {
            if (!empty($brand_data['code'])) {
                $brand_code = sanitize_text_field($brand_data['code']);
                update_post_meta($product_id, '_amrod_brand_code', $brand_code);
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
                update_post_meta($product_id, '_amrod_brandings', $valid_brandings);
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
            'data' => $stock_data,
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
            'data' => $stock_data,
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
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(), 'price_sync');
        
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
        
        // Split into batches (same as full price sync)
        $batches = array_chunk($prices_data, 100);
        $batch_count = count($batches);
        
        $this->logger->log('info', "Split into {$batch_count} batches", array(), 'price_sync');
        
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
        $exact_match_product_ids = array();
        foreach ($skus_to_try as $sku) {
            $product_id = wc_get_product_id_by_sku($sku);
            if ($product_id) {
                $exact_match_product_ids[] = $product_id;
                $matched_sku = $sku;
                $exact_match_found = true;
                $this->logger->log('success', "✅ Exact SKU matched: {$sku}", array(), 'stock_sync');
                break;
            }
        }
        
        // If we found an exact match, check if it's a simple product
        // If so, only update that product (don't do pattern matching for variable parents)
        if ($exact_match_found && !empty($exact_match_product_ids)) {
            $exact_product = wc_get_product($exact_match_product_ids[0]);
            if ($exact_product && !$exact_product->is_type('variable')) {
                // Exact match is a simple product - only update this one
                $product_ids = $exact_match_product_ids;
            } else {
                // Exact match is variable or not found - do pattern matching for variations
                $product_ids = $exact_match_product_ids;
        
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
            }
        } else {
            // No exact match - try pattern matching
            $product_ids = array();
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
                        $product_ids[] = $match->post_id;
                        $pattern_matched_count++;
                    }
                    
                    if ($pattern_matched_count > 0) {
                        $matched_sku = $simpleCode . '*';
                        $this->logger->log('success', "✅ Pattern matched {$pattern_matched_count} product(s) with SKU starting with: {$simpleCode}", array(), 'stock_sync');
                    }
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
                    // Determine if this is base product stock or variation stock
                    // Base product stock indicators (in priority order):
                    // 1. fullCode === simpleCode AND no colourCode (definitely base product, not a variation)
                    // 2. stockType === 0 (explicit base stock)
                    // 3. fullCode === simpleCode AND product SKU matches simpleCode
                    $is_base_stock = false;
                    $product_sku = $product->get_sku();
                    $colour_code = $stock_item['colourCode'] ?? null;
                    
                    // First check: if fullCode === simpleCode and no colourCode, it's ALWAYS base product stock
                    if ($fullCode === $simpleCode && empty($colour_code)) {
                        // This is definitely base product stock, not a variation
                        $is_base_stock = true;
                    } elseif ($stock_type === 0) {
                        // StockType 0 is always base product stock
                        $is_base_stock = true;
                    } elseif ($fullCode === $simpleCode && $product_sku === $simpleCode) {
                        // Product SKU matches = base product stock
                        $is_base_stock = true;
                    }
                    
                    if ($is_base_stock) {
                        // Update base variable product stock directly
                        $product->set_manage_stock(true);
                        $product->set_stock_quantity($stock_qty);
                        $product->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');
                        $this->save_product_safely($product);
                        
                        $detail = array(
                            'stock' => $stock_qty,
                            'reserved' => $reserved_qty,
                            'incoming' => $incoming,
                            'modified' => $modified,
                            'fullCode' => $stock_item['fullCode'] ?? '',
                            'simpleCode' => $simpleCode,
                            'stockType' => 0,
                        );
                        update_post_meta($pid, '_amrod_stock_detail', $detail);
                    } else {
                        // Try to update as variation (existing logic)
                        // Ensure simpleCode is passed correctly to the variation update method
                        $stock_item['simpleCode'] = $simpleCode;
                    $this->update_variable_product_stock($product, $stock_item, $stock_qty, $reserved_qty, $incoming, $modified, $stock_type);
                    }
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
            $product_id = wc_get_product_id_by_sku($sku);
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
                    $this->logger->log('info', "Found parent category for: {$category_name}", array(), 'category_sync');
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
                
                $this->logger->log('info', "Category already exists: {$category_name}", array(), 'category_sync');
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
            
            $this->logger->log('success', "Category synced: {$category_name}", array(), 'category_sync');
            
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
                // Only log warning if this is actually a variation (stockType 1 or 2)
                // If stockType is 0 or fullCode === simpleCode with no colourCode, 
                // this should have been handled as base product stock earlier
                if ($stock_type >= 1) {
                $this->logger->log('warning', 'Could not find matching variation for stock update', array(
                    'parent_id' => $product_id,
                    'full_code' => $full_code,
                    'simple_code' => $simple_code,
                    'colour_code' => $colour_code,
                        'stock_type' => $stock_type,
                ), 'product_sync');
                }
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

