<?php
/**
 * Quote Cart Handler
 * 
 * Manages the [bytemash_quote_cart] shortcode, renders cart items dynamically,
 * and processes the final quote request submission into a WooCommerce Order.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_Quote_Cart {

    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        add_shortcode('bytemash_quote_cart', array($this, 'render_quote_cart'));
        
        // AJAX to get cart HTML given items array
        add_action('wp_ajax_bytemash_get_cart_html', array($this, 'ajax_get_cart_html'));
        add_action('wp_ajax_nopriv_bytemash_get_cart_html', array($this, 'ajax_get_cart_html'));
        
        // AJAX to submit the cart
        add_action('wp_ajax_bytemash_submit_quote_cart', array($this, 'ajax_submit_quote_cart'));
        add_action('wp_ajax_nopriv_bytemash_submit_quote_cart', array($this, 'ajax_submit_quote_cart'));
    }

    /**
     * Enqueue JS and CSS for the quote cart
     */
    public function enqueue_assets() {
        wp_enqueue_style('bytemash-quote-cart-style', plugins_url('assets/css/quote-cart.css', dirname(__FILE__)), array(), BYTEMASH_WOO_SYNC_VERSION);
        wp_enqueue_script('bytemash-quote-cart', plugins_url('assets/js/quote-cart.js', dirname(__FILE__)), array('jquery'), BYTEMASH_WOO_SYNC_VERSION, true);
        
        $current_user = wp_get_current_user();
        wp_localize_script('bytemash-quote-cart', 'bytemashQuoteCart', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bytemash_quote_cart_nonce'),
            'empty_cart' => __('Your quote cart is empty.', 'bytemash-woo-sync'),
            'loading' => __('Loading cart...', 'bytemash-woo-sync'),
            'submitting' => __('Submitting request...', 'bytemash-woo-sync'),
            'default_email' => $current_user->exists() ? $current_user->user_email : '',
            'default_name' => $current_user->exists() ? trim($current_user->first_name . ' ' . $current_user->last_name) : ''
        ));
    }

    /**
     * Render the shortcode container
     */
    public function render_quote_cart($atts) {
        $this->enqueue_assets();
        ob_start();
        ?>
        <div class="bytemash-quote-cart-container" id="bytemash-quote-cart-app">
            <div id="bytemash-quote-cart-loading"><?php esc_html_e('Loading quote cart...', 'bytemash-woo-sync'); ?></div>
            
            <div id="bytemash-quote-cart-content" style="display: none;">
                <div class="bytemash-quote-cart-items-container" id="bytemash-quote-cart-items">
                    <!-- Items rendered via JS/AJAX -->
                </div>
                
                <div class="bytemash-quote-cart-form">
                    <h3><?php esc_html_e('Request Details', 'bytemash-woo-sync'); ?></h3>
                    <form id="bytemash-submit-quote-form" enctype="multipart/form-data">
                        <div class="form-row form-row-first">
                            <label for="quote_name"><?php esc_html_e('Name', 'bytemash-woo-sync'); ?> <abbr class="required" title="required">*</abbr></label>
                            <input type="text" class="input-text" name="quote_name" id="quote_name" required>
                        </div>
                        <div class="form-row form-row-last">
                            <label for="quote_email"><?php esc_html_e('Email Address', 'bytemash-woo-sync'); ?> <abbr class="required" title="required">*</abbr></label>
                            <input type="email" class="input-text" name="quote_email" id="quote_email" required>
                        </div>
                        <div class="clear"></div>
                        
                        <div class="form-row form-row-wide">
                            <label for="quote_instructions"><?php esc_html_e('Special Instructions', 'bytemash-woo-sync'); ?></label>
                            <textarea name="quote_instructions" class="input-text" id="quote_instructions" rows="4"></textarea>
                        </div>
                        
                        <div class="form-row form-row-wide">
                            <label><strong><?php esc_html_e('Upload Logos/Artwork', 'bytemash-woo-sync'); ?></strong></label>
                            <div class="bytemash-file-uploads" id="bytemash-file-uploads">
                                <div class="bytemash-file-upload-row">
                                    <input type="text" name="file_labels[]" placeholder="<?php esc_attr_e('Label (e.g. Front Logo)', 'bytemash-woo-sync'); ?>" class="input-text" style="width: 40%; display: inline-block;">
                                    <input type="file" name="quote_files[]" class="input-text bytemash-quote-file-input" accept=".jpg,.jpeg,.png,.pdf,.ai,.eps,.svg" style="width: 40%; display: inline-block;">
                                    <div class="bytemash-file-preview" style="display:inline-block; vertical-align:middle; margin-left:10px; width:40px; height:40px; border-radius:4px; overflow:hidden; border:1px solid #ddd; background:#f9f9f9; text-align:center;">
                                        <span style="display:block; line-height:40px; color:#aaa; font-size:12px;">No img</span>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="button button-small" id="bytemash-add-file-row" style="margin-top: 10px;"><?php esc_html_e('+ Add Another File', 'bytemash-woo-sync'); ?></button>
                            <p class="description"><small><?php esc_html_e('Accepted file types: JPG, PNG, PDF, AI, EPS, SVG. Max size: 5MB per file.', 'bytemash-woo-sync'); ?></small></p>
                        </div>
                        
                        <div class="form-row">
                            <div id="bytemash-quote-submit-message" style="display: none; margin-bottom: 20px;"></div>
                            <button type="submit" class="button alt wp-element-button" id="bytemash-submit-quote-btn"><?php esc_html_e('Send Quote Request', 'bytemash-woo-sync'); ?></button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div id="bytemash-quote-cart-empty" style="display: none;">
                <p class="cart-empty woocommerce-info"><?php esc_html_e('Your quote cart is currently empty.', 'bytemash-woo-sync'); ?></p>
                <p class="return-to-shop">
                    <a class="button wc-backward wp-element-button" href="<?php echo esc_url(apply_filters('woocommerce_return_to_shop_redirect', wc_get_page_permalink('shop'))); ?>">
                        <?php esc_html_e('Return to shop', 'bytemash-woo-sync'); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: Get HTML for the cart items table
     */
    public function ajax_get_cart_html() {
        check_ajax_referer('bytemash_quote_cart_nonce', 'nonce');
        
        $items_json = isset($_POST['items']) ? stripslashes($_POST['items']) : '[]';
        $items = json_decode($items_json, true);
        
        if (!is_array($items) || empty($items)) {
            wp_send_json_success(array('html' => '', 'empty' => true));
        }

        ob_start();
        
        foreach ($items as $index => $item) {
            $product_id = intval($item['product_id']);
            $variation_id = isset($item['variation_id']) ? intval($item['variation_id']) : 0;
            $quantity = intval($item['quantity']);
            $id = esc_attr($item['id']);
            
            $product = wc_get_product($variation_id ? $variation_id : $product_id);
            if (!$product) continue;

            $thumbnail = $product->get_image(array(80, 80));
            if (empty($thumbnail)) {
                $parent = wc_get_product($product_id);
                $thumbnail = $parent ? $parent->get_image(array(80, 80)) : '';
            }
            
            $product_name = $product->get_name();
            $permalink = $product->get_permalink();
            
            // Fetch full brandings meta to render the dropdowns
            $all_brandings = get_post_meta($product_id, '_amrod_brandings', true);
            $brandings_html = '';

            // Selected brandings from LocalStorage format: { "pos_0": ["code1"], "pos_1": ["code2"] }
            $selected_brandings = !empty($item['brandings']) && is_array($item['brandings']) ? $item['brandings'] : array();

            if (!empty($all_brandings) && is_array($all_brandings)) {
                $options_html = '<option value="">' . esc_html__('— Select Branding —', 'bytemash-woo-sync') . '</option>';
                foreach ($all_brandings as $idx => $pos) {
                    $posName = esc_html($pos['positionName'] ?? '');
                    $posCode = esc_attr($pos['positionCode'] ?? ('pos_' . $idx));
                    if (!empty($pos['method']) && is_array($pos['method'])) {
                        foreach ($pos['method'] as $midx => $method) {
                            $code = esc_attr($method['brandingCode'] ?? '');
                            $name = esc_html($method['brandingName'] ?? '');
                            $dept = esc_html($method['brandingDepartment'] ?? '');
                            $val = $posCode . '|' . $code;
                            $label = $posName . ' - ' . $name . ' (' . $dept . ')';
                            $options_html .= '<option value="' . $val . '">' . esc_html($label) . '</option>';
                        }
                    }
                }

                $brandings_html .= '<div class="bytemash-cart-item-brandings-edit" data-options="' . esc_attr($options_html) . '">';
                $brandings_html .= '<label>' . esc_html__('Branding Options', 'bytemash-woo-sync') . '</label>';
                $brandings_html .= '<div class="bytemash-cart-branding-rows">';
                
                // Flatten selected brandings into rows
                $has_any = false;
                foreach ($selected_brandings as $posCode => $codes) {
                    foreach ($codes as $code) {
                        $has_any = true;
                        $current_val = $posCode . '|' . $code;
                        $brandings_html .= '<div class="bytemash-cart-branding-row">';
                        $brandings_html .= '<select class="bytemash-cart-master-branding-select" data-id="' . $id . '">';
                        
                        // Re-render options with selection
                        $brandings_html .= '<option value="">' . esc_html__('— Select Branding —', 'bytemash-woo-sync') . '</option>';
                        foreach ($all_brandings as $idx => $pos) {
                            $posName = esc_html($pos['positionName'] ?? '');
                            $posCodeInner = esc_attr($pos['positionCode'] ?? ('pos_' . $idx));
                            if (!empty($pos['method']) && is_array($pos['method'])) {
                                foreach ($pos['method'] as $midx => $method) {
                                    $codeInner = esc_attr($method['brandingCode'] ?? '');
                                    $nameInner = esc_html($method['brandingName'] ?? '');
                                    $deptInner = esc_html($method['brandingDepartment'] ?? '');
                                    $valInner = $posCodeInner . '|' . $codeInner;
                                    $labelInner = $posName . ' - ' . $nameInner . ' (' . $deptInner . ')';
                                    $selected = ($current_val === $valInner) ? 'selected' : '';
                                    $brandings_html .= '<option value="' . $valInner . '" ' . $selected . '>' . esc_html($labelInner) . '</option>';
                                }
                            }
                        }
                        $brandings_html .= '</select>';
                        $brandings_html .= '<button type="button" class="bytemash-cart-remove-branding">&times;</button>';
                        $brandings_html .= '</div>';
                    }
                }
                
                // If none, show one empty row
                if (!$has_any) {
                    $brandings_html .= '<div class="bytemash-cart-branding-row">';
                    $brandings_html .= '<select class="bytemash-cart-master-branding-select" data-id="' . $id . '">';
                    $brandings_html .= $options_html;
                    $brandings_html .= '</select>';
                    $brandings_html .= '<button type="button" class="bytemash-cart-remove-branding" style="display:none;">&times;</button>';
                    $brandings_html .= '</div>';
                }

                $brandings_html .= '</div>';
                $brandings_html .= '<button type="button" class="bytemash-cart-add-branding" data-id="' . $id . '">' . esc_html__('+ Add Branding', 'bytemash-woo-sync') . '</button>';
                $brandings_html .= '</div>';
            }
            
            // Format variation attributes as editable dropdowns
            $attributes_html = '';
            $parent_product = wc_get_product($product_id);
            $variations_json = '[]';
            
            if ($parent_product && $parent_product->is_type('variable')) {
                $available_attributes = $parent_product->get_variation_attributes();
                $variations_json = wp_json_encode($parent_product->get_available_variations());
                
                // Get currently selected attributes for this variation
                $current_variation_attributes = $variation_id ? wc_get_product($variation_id)->get_variation_attributes() : array();
                
                foreach ($available_attributes as $attr_name => $options) {
                    $clean_name = str_replace('attribute_', '', $attr_name);
                    $label = wc_attribute_label($attr_name, $parent_product);
                    
                    // Selected value: either from current variation or from the 'color'/'size' keys in item
                    $selected_val = '';
                    if (isset($current_variation_attributes[$attr_name])) {
                        $selected_val = $current_variation_attributes[$attr_name];
                    } elseif ($clean_name === 'pa_color' || $clean_name === 'color') {
                        $selected_val = $item['color'] ?? '';
                    } elseif ($clean_name === 'pa_size' || $clean_name === 'size') {
                        $selected_val = $item['size'] ?? '';
                    }

                    $attributes_html .= '<div class="bytemash-cart-attr-field">';
                    $attributes_html .= '<label>' . esc_html($label) . '</label>';
                    $attributes_html .= '<select class="bytemash-cart-attr-select" data-attribute="' . esc_attr($attr_name) . '" data-id="' . $id . '">';
                    foreach ($options as $option) {
                        $attributes_html .= '<option value="' . esc_attr($option) . '" ' . selected($selected_val, $option, false) . '>' . esc_html($option) . '</option>';
                    }
                    $attributes_html .= '</select>';
                    $attributes_html .= '</div>';
                }
            } elseif (!$variation_id && (!empty($item['color']) || !empty($item['size']))) {
                // Fallback for non-variable products with captured attributes
                $attributes_html .= '<div class="bytemash-cart-item-attributes-static">';
                if (!empty($item['color'])) $attributes_html .= '<span class="bytemash-cart-attr"><strong>Color:</strong> ' . esc_html($item['color']) . '</span>';
                if (!empty($item['size'])) $attributes_html .= '<span class="bytemash-cart-attr"><strong>Size:</strong> ' . esc_html($item['size']) . '</span>';
                $attributes_html .= '</div>';
            }

            // SKU
            $sku = $product->get_sku();
            $sku_html = $sku ? '<div class="bytemash-cart-sku"><strong>SKU:</strong> <span class="sku-val">' . esc_html($sku) . '</span></div>' : '<div class="bytemash-cart-sku" style="display:none;"><strong>SKU:</strong> <span class="sku-val"></span></div>';

            ?>
            <div class="bytemash-quote-cart-card" data-id="<?php echo $id; ?>" data-variations='<?php echo esc_attr($variations_json); ?>'>
                <div class="bytemash-quote-card-main">
                    <div class="bytemash-quote-card-info">
                        <div class="bytemash-quote-card-header">
                            <h3 class="product-name">
                                <a href="<?php echo esc_url($permalink); ?>"><?php echo esc_html($product_name); ?></a>
                            </h3>
                            <a href="#" class="remove bytemash-remove-item" aria-label="<?php esc_attr_e('Remove this item', 'bytemash-woo-sync'); ?>" data-id="<?php echo $id; ?>">&times;</a>
                        </div>
                        <?php echo $sku_html; ?>
                        
                        <div class="bytemash-quote-card-controls">
                            <!-- Quantity First -->
                            <div class="bytemash-quantity-field">
                                <label><?php esc_html_e('Quantity', 'bytemash-woo-sync'); ?></label>
                                <div class="bytemash-quantity-wrapper">
                                    <button type="button" class="bytemash-qty-btn bytemash-qty-minus" data-id="<?php echo $id; ?>">&minus;</button>
                                    <input type="number" class="bytemash-cart-qty" step="1" min="1" max="" name="cart[<?php echo $id; ?>][qty]" value="<?php echo esc_attr($quantity); ?>" title="<?php esc_attr_e('Qty', 'bytemash-woo-sync'); ?>" size="4" placeholder="" inputmode="numeric" data-id="<?php echo $id; ?>">
                                    <button type="button" class="bytemash-qty-btn bytemash-qty-plus" data-id="<?php echo $id; ?>">&plus;</button>
                                </div>
                            </div>
                            
                            <!-- Attributes Second -->
                            <?php echo $attributes_html; ?>

                            <!-- Brandings Third -->
                            <?php echo $brandings_html; ?>
                        </div>
                    </div>
                    
                    <div class="bytemash-quote-card-image">
                        <div class="bytemash-main-image">
                            <a href="<?php echo esc_url($permalink); ?>"><?php echo $thumbnail; ?></a>
                        </div>
                        <?php
                        /**
                         * Variation image resolution – works even when variation_id = 0.
                         * Priority:
                         *  1. Use stored variation_id directly
                         *  2. Find variation by matching color/size from item data
                         *  3. Fall back to WooCommerce product image
                         */
                        $var_img_src = '';
                        $resolve_id  = $variation_id;

                        // If no variation_id stored, try to find one from color/size
                        if (!$resolve_id && !empty($parent_product) && $parent_product->is_type('variable')) {
                            $color_val = $item['color'] ?? '';
                            $size_val  = $item['size'] ?? '';
                            $children  = $parent_product->get_children();
                            foreach ($children as $child_id) {
                                $child = wc_get_product($child_id);
                                if (!$child) continue;
                                $child_attrs = $child->get_variation_attributes();
                                $color_match = !$color_val;
                                $size_match  = !$size_val;
                                foreach ($child_attrs as $attr_key => $attr_val) {
                                    if (!$color_match && stripos($attr_key, 'color') !== false
                                        && strcasecmp($attr_val, $color_val) === 0) {
                                        $color_match = true;
                                    }
                                    if (!$size_match && stripos($attr_key, 'size') !== false
                                        && strcasecmp($attr_val, $size_val) === 0) {
                                        $size_match = true;
                                    }
                                    // empty attr value means "any" - counts as a match
                                    if (!$color_match && stripos($attr_key, 'color') !== false && $attr_val === '') {
                                        $color_match = true;
                                    }
                                    if (!$size_match && stripos($attr_key, 'size') !== false && $attr_val === '') {
                                        $size_match = true;
                                    }
                                }
                                if ($color_match && $size_match) {
                                    $resolve_id = $child_id;
                                    break;
                                }
                            }
                        }

                        if ($resolve_id) {
                            // 1. Amrod external variation image (primary)
                            $ext = get_post_meta($resolve_id, '_amrod_variation_image', true);
                            if ($ext) $var_img_src = esc_url($ext);

                            // 2. Alternative key used by inject_external_variation_images filter
                            if (!$var_img_src) {
                                $ext2 = get_post_meta($resolve_id, '_thumbnail_external_url', true);
                                if ($ext2) $var_img_src = esc_url($ext2);
                            }

                            // 3. Standard WP featured image attachment
                            if (!$var_img_src) {
                                $thumb_id = get_post_thumbnail_id($resolve_id);
                                if ($thumb_id) {
                                    $arr = wp_get_attachment_image_src($thumb_id, 'thumbnail');
                                    if ($arr) $var_img_src = $arr[0];
                                }
                            }

                            // 4. WooCommerce image_id (may differ from post thumbnail in some setups)
                            if (!$var_img_src) {
                                $vobj = wc_get_product($resolve_id);
                                if ($vobj) {
                                    $img_id = $vobj->get_image_id();
                                    if ($img_id) {
                                        $arr = wp_get_attachment_image_src($img_id, 'thumbnail');
                                        if ($arr) $var_img_src = $arr[0];
                                    }
                                }
                            }
                        }
                        ?>
                        <div class="bytemash-variation-image" style="margin-top:10px; display:<?php echo $var_img_src ? 'block' : 'none'; ?>;">
                            <?php if ($var_img_src): ?>
                                <img src="<?php echo esc_url($var_img_src); ?>" alt="" style="border-radius:8px; object-fit:cover; width:100%; height:100%;">
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        }
        
        $html = ob_get_clean();
        
        wp_send_json_success(array('html' => $html, 'empty' => empty($html)));
    }

    /**
     * AJAX: Submit the entire Quote Cart to create an order
     */
    public function ajax_submit_quote_cart() {
        check_ajax_referer('bytemash_quote_cart_nonce', 'security');
        
        $items_json = isset($_POST['items']) ? stripslashes($_POST['items']) : '[]';
        $items = json_decode($items_json, true);
        
        $name = isset($_POST['quote_name']) ? sanitize_text_field($_POST['quote_name']) : '';
        $email = isset($_POST['quote_email']) ? sanitize_email($_POST['quote_email']) : '';
        $instructions = isset($_POST['quote_instructions']) ? sanitize_textarea_field($_POST['quote_instructions']) : '';
        
        if (empty($items) || !is_array($items)) {
            wp_send_json_error(array('message' => __('Cannot submit an empty quote cart.', 'bytemash-woo-sync')));
        }
        
        if (empty($email)) {
            wp_send_json_error(array('message' => __('Email address is required.', 'bytemash-woo-sync')));
        }

        // Process File Uploads
        $uploaded_files = array();
        if (!empty($_FILES['quote_files']['name'])) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            
            $file_labels = isset($_POST['file_labels']) ? wc_clean($_POST['file_labels']) : array();
            
            foreach ($_FILES['quote_files']['name'] as $key => $value) {
                if ($_FILES['quote_files']['name'][$key]) {
                    if ($_FILES['quote_files']['error'][$key] !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    
                    if ($_FILES['quote_files']['size'][$key] > 5 * 1024 * 1024) {
                        wp_send_json_error(array('message' => sprintf(__('File %s exceeds the 5MB limit.', 'bytemash-woo-sync'), $_FILES['quote_files']['name'][$key])));
                    }
                    
                    $file = array(
                        'name'     => $_FILES['quote_files']['name'][$key],
                        'type'     => $_FILES['quote_files']['type'][$key],
                        'tmp_name' => $_FILES['quote_files']['tmp_name'][$key],
                        'error'    => $_FILES['quote_files']['error'][$key],
                        'size'     => $_FILES['quote_files']['size'][$key]
                    );

                    $upload_overrides = array('test_form' => false);
                    // Add allowed mimes for specific vector extensions which WordPress may reject depending on exact setup
                    $upload_overrides['mimes'] = get_allowed_mime_types();
                    
                    $movefile = wp_handle_upload($file, $upload_overrides);

                    if ($movefile && !isset($movefile['error'])) {
                        $label = !empty($file_labels[$key]) ? $file_labels[$key] : $file['name'];
                        $uploaded_files[] = array(
                            'url' => $movefile['url'],
                            'path' => $movefile['file'],
                            'label' => $label
                        );
                    } else {
                        // Throw error if important upload fails
                        wp_send_json_error(array('message' => __('Failed to upload file: ', 'bytemash-woo-sync') . $movefile['error']));
                    }
                }
            }
        }

        try {
            // Create the order
            $order = wc_create_order();
            
            $current_user = wp_get_current_user();
            if ($current_user->exists()) {
                $order->set_customer_id($current_user->ID);
            }
            
            // Name handling (split first/last roughly)
            $name_parts = explode(' ', $name, 2);
            $first_name = $name_parts[0];
            $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
            
            $order->set_billing_first_name($first_name);
            $order->set_billing_last_name($last_name);
            $order->set_billing_email($email);

            // Add items
            foreach ($items as $item) {
                $product_id = intval($item['product_id']);
                $variation_id = isset($item['variation_id']) ? intval($item['variation_id']) : 0;
                $quantity = max(1, intval($item['quantity']));
                
                $product = wc_get_product($variation_id ? $variation_id : $product_id);
                if (!$product) continue;
                
                // For quote requests, temporarily set price to 0.01 to allow add_product, then reset
                $original_price = $product->get_price();
                $product->set_price('0.01'); // Force price to allow order item creation
                
                $item_id = $order->add_product($product, $quantity);
                
                $product->set_price($original_price); // Restore object price
                
                if ($item_id) {
                    $order_item = $order->get_item($item_id);
                    $order_item->set_subtotal(0);
                    $order_item->set_total(0);
                    
                    if (!empty($item['color'])) {
                        $order_item->add_meta_data('Color', sanitize_text_field($item['color']), true);
                    }
                    if (!empty($item['size'])) {
                        $order_item->add_meta_data('Size', sanitize_text_field($item['size']), true);
                    }
                    
                    if (!empty($item['brandings']) && is_array($item['brandings'])) {
                        foreach ($item['brandings'] as $pos => $codes) {
                            if (is_array($codes)) {
                                $order_item->add_meta_data('Branding ' . sanitize_text_field($pos), implode(', ', array_map('sanitize_text_field', $codes)), true);
                            }
                        }
                    }
                    $order_item->save();
                }
            }
            
            $order->add_meta_data('_bytemash_quote_request', 'yes', true);
            $order->add_meta_data('_bytemash_quote_request_date', current_time('mysql'), true);
            
            if ($instructions) {
                $order->add_meta_data('_bytemash_quote_instructions', $instructions, true);
            }
            if (!empty($uploaded_files)) {
                $order->add_meta_data('_bytemash_quote_files', $uploaded_files, true);
            }
            
            $order->set_status('wc-quote-request');
            $order->calculate_totals();
            $order->set_total(0); // Ensure entire order total is 0
            $order->save();

            // Send notification email
            $this->send_notification_email($order, $name, $instructions, $uploaded_files);

            // Generate a summary HTML block for the frontend
            ob_start();
            ?>
            <div class="bytemash-quote-success-details" style="padding:20px; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; margin-bottom:20px;">
                <h3 style="margin-top:0; color:#1e293b;"><?php esc_html_e('Quote Request Submitted', 'bytemash-woo-sync'); ?></h3>
                <p><strong><?php esc_html_e('Reference Number:', 'bytemash-woo-sync'); ?></strong> #<?php echo $order->get_order_number(); ?></p>
                <p><strong><?php esc_html_e('Name:', 'bytemash-woo-sync'); ?></strong> <?php echo esc_html($name); ?></p>
                <p><strong><?php esc_html_e('Email:', 'bytemash-woo-sync'); ?></strong> <?php echo esc_html($email); ?></p>
                <?php if ($instructions): ?>
                    <p><strong><?php esc_html_e('Special Instructions:', 'bytemash-woo-sync'); ?></strong><br/><?php echo nl2br(esc_html($instructions)); ?></p>
                <?php endif; ?>
                <h4 style="margin-top:20px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;"><?php esc_html_e('Requested Items', 'bytemash-woo-sync'); ?></h4>
                <ul style="list-style:none; padding:0; margin:0;">
                    <?php foreach ($order->get_items() as $item_id => $item_obj): ?>
                        <li style="padding:10px 0; border-bottom:1px solid #e2e8f0;">
                            <strong><?php echo $item_obj->get_quantity(); ?>x</strong> <?php echo esc_html($item_obj->get_name()); ?>
                            <?php 
                            $meta = $item_obj->get_formatted_meta_data();
                            if (!empty($meta)) {
                                $meta_strings = [];
                                foreach ($meta as $meta_id => $meta_data) {
                                    $meta_strings[] = wp_kses_post($meta_data->display_key) . ': ' . wp_kses_post(strip_tags($meta_data->display_value));
                                }
                                echo '<br><small style="color:#64748b;">' . implode(' | ', $meta_strings) . '</small>';
                            }
                            ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p style="margin-top:20px; font-weight:bold; color:#10b981;"><?php esc_html_e('We have received your request and will contact you shortly with a quote.', 'bytemash-woo-sync'); ?></p>
            </div>
            <?php
            $details_html = ob_get_clean();

            wp_send_json_success(array(
                'message' => __('Quote request submitted successfully! We will contact you soon.', 'bytemash-woo-sync'),
                'order_id' => $order->get_id(),
                'details_html' => $details_html
            ));

        } catch (Exception $e) {
            wp_send_json_error(array('message' => __('Failed to create quote. Error: ', 'bytemash-woo-sync') . $e->getMessage()));
        }
    }

    private function send_notification_email($order, $customer_name, $instructions, $files) {
        $admin_email = get_option('bytemash_quote_admin_email', get_option('admin_email'));
        if (!is_email($admin_email)) return;

        $subject_tmpl = get_option('bytemash_quote_email_subject', 'New Quote Request #{quote_number}');
        $body_tmpl = get_option('bytemash_quote_email_template', "New quote request received.\n\nCustomer: {customer_name}\nQuote #: {quote_number}\n\nPlease check the admin dashboard for details.");
        
        $replacements = array(
            '{customer_name}' => $customer_name,
            '{quote_number}' => $order->get_order_number(),
            '{site_name}' => get_bloginfo('name')
        );
        
        $subject = str_replace(array_keys($replacements), array_values($replacements), $subject_tmpl);
        $message = str_replace(array_keys($replacements), array_values($replacements), $body_tmpl);
        
        // Append extra details
        $message .= "\n\n--- Request Details ---\n";
        $message .= "Email: " . $order->get_billing_email() . "\n";
        if ($instructions) {
            $message .= "Instructions: \n" . $instructions . "\n";
        }
        if (!empty($files)) {
            $message .= "\nUploaded Files:\n";
            foreach ($files as $f) {
                $message .= "- " . $f['label'] . ": " . $f['url'] . "\n";
            }
        }
        
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        wp_mail($admin_email, $subject, $message, $headers);
    }
}
