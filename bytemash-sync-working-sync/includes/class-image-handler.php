<?php
/**
 * Image Handler Class
 * 
 * Handles product image downloads and attachment
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_Image_Handler {
    
    /**
     * Logger
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new ByteMash_Logger();
    }
    
    /**
     * Sync product images
     */
    public function sync_product_images($product_id, $images) {
        if (empty($images) || !is_array($images)) {
            return;
        }
        
        $image_ids = array();
        
        foreach ($images as $index => $image_data) {
            $image_url = isset($image_data['url']) ? $image_data['url'] : $image_data;
            
            if (empty($image_url)) {
                continue;
            }
            
            $attachment_id = $this->upload_image($image_url, $product_id);
            
            if ($attachment_id && !is_wp_error($attachment_id)) {
                $image_ids[] = $attachment_id;
                
                // Set first image as featured
                if ($index === 0) {
                    set_post_thumbnail($product_id, $attachment_id);
                }
            }
        }
        
        // Set gallery images (excluding featured)
        if (count($image_ids) > 1) {
            $gallery_ids = array_slice($image_ids, 1);
            update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
        }
        
        $this->logger->log('info', "Synced {count} images for product {$product_id}", array(
            'product_id' => $product_id,
            'count' => count($image_ids),
        ), 'image_sync');
    }
    
    /**
     * Import/upload image from URL (public method)
     */
    public function import_image($image_url, $product_id, $alt_text = '') {
        $attachment_id = $this->upload_image($image_url, $product_id);
        
        if ($attachment_id && !is_wp_error($attachment_id) && !empty($alt_text)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
        }
        
        return $attachment_id;
    }
    
    /**
     * Upload image from URL
     */
    private function upload_image($image_url, $product_id) {
        // Validate image URL
        if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
            $this->logger->log('warning', 'Skipping image - invalid URL', array(
                'url' => $image_url,
                'product_id' => $product_id,
            ), 'image_sync');
            return false;
        }
        
        // Check if image already exists
        $existing_attachment = $this->get_attachment_by_url($image_url, $product_id);
        
        if ($existing_attachment) {
            return $existing_attachment;
        }
        
        // Download image
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $tmp = download_url($image_url, 300);
        
        if (is_wp_error($tmp)) {
            $this->logger->log('error', '❌ Image download failed', array(
                'url' => $image_url,
                'error_code' => $tmp->get_error_code(),
                'error_message' => $tmp->get_error_message(),
                'error_data' => $tmp->get_error_data(),
                'product_id' => $product_id,
            ), 'image_sync');
            return false;
        }
        
        $file_array = array(
            'name' => basename($image_url),
            'tmp_name' => $tmp,
        );
        
        // Upload to media library
        $attachment_id = media_handle_sideload($file_array, $product_id);
        
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            $this->logger->log('error', 'Failed to upload image', array(
                'url' => $image_url,
                'error' => $attachment_id->get_error_message(),
            ), 'image_sync');
            return false;
        }
        
        // Store original URL as meta
        update_post_meta($attachment_id, '_amrod_image_url', $image_url);
        
        return $attachment_id;
    }
    
    /**
     * Get attachment by original URL
     */
    private function get_attachment_by_url($image_url, $product_id) {
        $args = array(
            'post_type' => 'attachment',
            'post_parent' => $product_id,
            'posts_per_page' => 1,
            'meta_query' => array(
                array(
                    'key' => '_amrod_image_url',
                    'value' => $image_url,
                    'compare' => '=',
                ),
            ),
        );
        
        $attachments = get_posts($args);
        
        return !empty($attachments) ? $attachments[0]->ID : false;
    }
    
    /**
     * Clean up unused images
     */
    public function cleanup_unused_images($product_id) {
        $attachments = get_posts(array(
            'post_type' => 'attachment',
            'post_parent' => $product_id,
            'posts_per_page' => -1,
        ));
        
        $featured_id = get_post_thumbnail_id($product_id);
        $gallery_ids = explode(',', get_post_meta($product_id, '_product_image_gallery', true));
        $keep_ids = array_merge(array($featured_id), $gallery_ids);
        
        foreach ($attachments as $attachment) {
            if (!in_array($attachment->ID, $keep_ids)) {
                wp_delete_attachment($attachment->ID, true);
            }
        }
    }
}

