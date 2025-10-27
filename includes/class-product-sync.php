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
        $product_id = wc_get_product_id_by_sku($sku);
        
        if ($product_id && !$force) {
            // Check if product data has changed before updating
            $existing_product = wc_get_product($product_id);
            if ($this->is_product_data_unchanged($existing_product, $product_data)) {
                $this->logger->log('info', "Product data unchanged, skipping: {$sku}", array(
                    'sku' => $sku,
                    'product_id' => $product_id,
                ), 'product_sync');
                return array('success' => true, 'product_id' => $product_id, 'skipped' => true, 'message' => 'Product data unchanged');
            }
            
            // Update existing product (data has changed)
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
            
            // Set brand
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
            
            $this->logger->log('success', "Product synced: {$sku}", array(), 'product_sync');
            
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
        
        // Compare brand
        $existing_brand = get_post_meta($existing_product->get_id(), '_amrod_brand', true);
        $api_brand = $api_data['brand']['brandName'] ?? '';
        
        if ($existing_brand !== $api_brand) {
            return false;
        }
        
        // Compare images (check if image URLs have changed)
        $existing_images = $this->get_existing_product_images($existing_product->get_id());
        $api_images = $api_data['images'] ?? array();
        
        if (!$this->are_images_unchanged($existing_images, $api_images)) {
            return false;
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
            
            $this->logger->log('info', 'Product saved successfully', array(
                'product_id' => $product_id,
                'sku' => $product->get_sku(),
                'type' => $product->get_type(),
            ), 'product_sync');
            
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
                
                // Update stock
                $product->set_manage_stock(true);
                $product->set_stock_quantity($stock_qty);
                $product->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');
                $this->save_product_safely($product);
                $updated_count++;
                
                $this->logger->log('info', 'Stock updated successfully', array(
                    'product_id' => $pid,
                    'sku' => $product->get_sku(),
                    'stock_qty' => $stock_qty,
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
}

