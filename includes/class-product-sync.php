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
        
        // Use chunk-based approach for memory efficiency
        $chunks = array_chunk($products, $this->batch_size);
        $chunk_count = count($chunks);
        
        // Store metadata
        set_transient("bytemash_sync_{$sync_id}_meta", array(
            'total' => $total,
            'chunks' => $chunk_count,
            'batch_size' => $this->batch_size,
            'started' => time(),
        ), 2 * HOUR_IN_SECONDS);
        
        // Store chunks separately
        foreach ($chunks as $index => $chunk) {
            set_transient("bytemash_sync_{$sync_id}_chunk_{$index}", $chunk, 2 * HOUR_IN_SECONDS);
        }
        
        // Free memory
        unset($products, $chunks);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        $batch_processor = new ByteMash_Batch_Processor();
        $scheduled = $batch_processor->schedule_products_sync_chunked($sync_id, $chunk_count, $total);
        
        if ($scheduled) {
            return array(
                'success' => true,
                'message' => "Incremental sync scheduled: {$total} products to update in {$chunk_count} chunks",
                'sync_id' => $sync_id,
                'total' => $total,
            );
        }
        
        return array('success' => false, 'message' => 'Failed to schedule sync');
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
        
        // Check if product exists
        $product_id = wc_get_product_id_by_sku($sku);
        
        if ($product_id && !$force) {
            // Update existing product
            $product = wc_get_product($product_id);
        } else {
            // Create new product (will be simple for now, converted to variable if needed)
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
            
            // Store Amrod-specific metadata
            $this->sync_product_meta($product_id, $product_data);
            
            // Store branding information if available
            if (!empty($product_data['brandings'])) {
                update_post_meta($product_id, '_amrod_brandings', $product_data['brandings']);
            }
            
            // Store color swatches (for future swatch functionality)
            if (!empty($product_data['colourImages'])) {
                // Store raw data
                update_post_meta($product_id, '_amrod_colour_images', $product_data['colourImages']);
                
                // Extract simplified swatch data for easy use
                $swatches = array();
                foreach ($product_data['colourImages'] as $color) {
                    $swatch_images = array();
                    
                    if (!empty($color['images']) && is_array($color['images'])) {
                        foreach ($color['images'] as $img) {
                            if (!empty($img['urls']) && is_array($img['urls'])) {
                                // Get highest res URL
                                foreach ($img['urls'] as $url_data) {
                                    if (!empty($url_data['url'])) {
                                        $swatch_images[] = $url_data['url'];
                                        break; // Just get first URL for each image
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
                
                // Store simplified format for future swatch implementation
                if (!empty($swatches)) {
                    update_post_meta($product_id, '_amrod_color_swatches', $swatches);
                }
            }
            
            // Store branding guides
            if (!empty($product_data['fullBrandingGuide'])) {
                update_post_meta($product_id, '_amrod_branding_guide', $product_data['fullBrandingGuide']);
            }
            
            $this->logger->log('success', "Product synced: {$sku}", array(
                'product_id' => $product_id,
                'sku' => $sku,
            ), 'product_sync');
            
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
        
        $batch_processor = new ByteMash_Batch_Processor();
        $scheduled = $batch_processor->schedule_stock_sync($stock_data, $sync_id);
        
        if ($scheduled) {
            return array(
                'success' => true,
                'message' => "Stock update scheduled: {$total} items to update",
                'sync_id' => $sync_id,
                'total' => $total,
            );
        }
        
        return array('success' => false, 'message' => 'Failed to schedule stock sync');
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
        
        $batch_processor = new ByteMash_Batch_Processor();
        $scheduled = $batch_processor->schedule_prices_sync($prices_data, $sync_id);
        
        if ($scheduled) {
            return array(
                'success' => true,
                'message' => "Price update scheduled: {$total} items to update",
                'sync_id' => $sync_id,
                'total' => $total,
            );
        }
        
        return array('success' => false, 'message' => 'Failed to schedule price sync');
    }
    
    /**
     * Update stock for a single product
     */
    public function update_single_stock($stock_item) {
        // Stock item structure: {simpleCode, fullCode, stock}
        $sku = $stock_item['fullCode'] ?? $stock_item['simpleCode'] ?? '';
        
        if (empty($sku)) {
            return array('success' => false, 'message' => 'No SKU in stock data');
        }
        
        // Find product by SKU
        $product_id = wc_get_product_id_by_sku($sku);
        
        if (!$product_id) {
            return array('success' => false, 'message' => "Product not found: {$sku}");
        }
        
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return array('success' => false, 'message' => "Failed to load product: {$sku}");
        }
        
        // Update stock
        $stock_qty = isset($stock_item['stock']) ? (int) $stock_item['stock'] : 0;
        $product->set_stock_quantity($stock_qty);
        $product->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');
        $product->save();
        
        return array('success' => true, 'sku' => $sku, 'stock' => $stock_qty);
    }
    
    /**
     * Update price for a single product
     */
    public function update_single_price($price_item) {
        // Price item structure: {simpleCode, fullCode, price, salePrice}
        $sku = $price_item['fullCode'] ?? $price_item['simpleCode'] ?? '';
        
        if (empty($sku)) {
            return array('success' => false, 'message' => 'No SKU in price data');
        }
        
        // Find product by SKU
        $product_id = wc_get_product_id_by_sku($sku);
        
        if (!$product_id) {
            return array('success' => false, 'message' => "Product not found: {$sku}");
        }
        
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return array('success' => false, 'message' => "Failed to load product: {$sku}");
        }
        
        // Update prices
        if (isset($price_item['price'])) {
            $product->set_regular_price($price_item['price']);
        }
        
        if (isset($price_item['salePrice']) && $price_item['salePrice'] > 0) {
            $product->set_sale_price($price_item['salePrice']);
        }
        
        $product->save();
        
        return array('success' => true, 'sku' => $sku, 'price' => $price_item['price'] ?? 0);
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
        
        // Process categories synchronously (tree structure requires sequential processing)
        $batch_processor = new ByteMash_Batch_Processor();
        wp_schedule_single_event(time(), 'bytemash_process_categories_batch', array($categories));
        
        return array(
            'success' => true,
            'message' => 'Category sync scheduled',
            'total' => count($categories),
        );
    }
}

