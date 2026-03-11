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
                <table class="bytemash-quote-cart-table shop_table shop_table_responsive cart">
                    <thead>
                        <tr>
                            <th class="product-remove">&nbsp;</th>
                            <th class="product-thumbnail">&nbsp;</th>
                            <th class="product-name"><?php esc_html_e('Product', 'bytemash-woo-sync'); ?></th>
                            <th class="product-quantity"><?php esc_html_e('Quantity', 'bytemash-woo-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="bytemash-quote-cart-items">
                        <!-- Items rendered via JS/AJAX -->
                    </tbody>
                </table>
                
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
                            <label><?php esc_html_e('Upload Logos/Artwork', 'bytemash-woo-sync'); ?></label>
                            <div class="bytemash-file-uploads" id="bytemash-file-uploads">
                                <div class="bytemash-file-upload-row">
                                    <input type="text" name="file_labels[]" placeholder="<?php esc_attr_e('Label (e.g. Front Logo)', 'bytemash-woo-sync'); ?>" class="input-text" style="width: 48%; display: inline-block;">
                                    <input type="file" name="quote_files[]" class="input-text" accept=".jpg,.jpeg,.png,.pdf,.ai,.eps,.svg" style="width: 48%; display: inline-block;">
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
            
            // Format brandings
            $brandings_html = '';
            if (!empty($item['brandings']) && is_array($item['brandings'])) {
                $brandings_html .= '<div class="bytemash-cart-item-brandings" style="font-size: 0.9em; margin-top: 5px; color: #666;">';
                foreach ($item['brandings'] as $pos => $codes) {
                    if (is_array($codes)) {
                        $brandings_html .= '<strong>Branding ' . esc_html($pos) . ':</strong> ' . esc_html(implode(', ', $codes)) . '<br>';
                    }
                }
                $brandings_html .= '</div>';
            }
            
            // Format custom variation attributes (if single product without formal variations but with color/size selected)
            if (!$variation_id && (!empty($item['color']) || !empty($item['size']))) {
                $brandings_html .= '<div class="bytemash-cart-item-attributes" style="font-size: 0.9em; margin-top: 5px; color: #666;">';
                if (!empty($item['color'])) $brandings_html .= '<strong>Color:</strong> ' . esc_html($item['color']) . '<br>';
                if (!empty($item['size'])) $brandings_html .= '<strong>Size:</strong> ' . esc_html($item['size']) . '<br>';
                $brandings_html .= '</div>';
            }

            ?>
            <tr class="woocommerce-cart-form__cart-item cart_item" data-id="<?php echo $id; ?>">
                <td class="product-remove">
                    <a href="#" class="remove bytemash-remove-item" aria-label="<?php esc_attr_e('Remove this item', 'bytemash-woo-sync'); ?>" data-id="<?php echo $id; ?>">&times;</a>
                </td>
                <td class="product-thumbnail">
                    <a href="<?php echo esc_url($permalink); ?>"><?php echo $thumbnail; ?></a>
                </td>
                <td class="product-name" data-title="<?php esc_attr_e('Product', 'bytemash-woo-sync'); ?>">
                    <a href="<?php echo esc_url($permalink); ?>"><?php echo esc_html($product_name); ?></a>
                    <?php echo $brandings_html; ?>
                </td>
                <td class="product-quantity" data-title="<?php esc_attr_e('Quantity', 'bytemash-woo-sync'); ?>">
                    <div class="quantity">
                        <input type="number" class="input-text qty text bytemash-cart-qty" step="1" min="1" max="" name="cart[<?php echo $id; ?>][qty]" value="<?php echo esc_attr($quantity); ?>" title="<?php esc_attr_e('Qty', 'bytemash-woo-sync'); ?>" size="4" placeholder="" inputmode="numeric" data-id="<?php echo $id; ?>">
                    </div>
                </td>
            </tr>
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

            wp_send_json_success(array(
                'message' => __('Quote request submitted successfully! We will contact you soon.', 'bytemash-woo-sync'),
                'order_id' => $order->get_id()
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
