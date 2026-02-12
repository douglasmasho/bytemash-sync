<?php
/**
 * Quote System Admin Handler
 * 
 * Handles Admin UI for Quote Requests, including List View, Details View, and Settings.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ByteMash_Quote_Admin {

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
        // Register AJAX handlers for email
        add_action('wp_ajax_bytemash_send_quote_email', array($this, 'ajax_send_quote_email'));
    }

    /**
     * Render the main Quote Requests List Page
     */
    public function render_quote_list_page() {
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'wc-quote-request';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $posts_per_page = 20;

        $args = array(
            'post_type'      => 'shop_order',
            'post_status'    => $status,
            'posts_per_page' => $posts_per_page,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        // If specific status 'any' or empty, look for all quotes
        if ($status === 'all') {
            $args['post_status'] = 'wc-quote-request'; // Or multiple if we have processed statuses
        }

        $query = new WP_Query($args);
        $total_posts = $query->found_posts;
        $total_pages = ceil($total_posts / $posts_per_page);

        ?>
        <div class="wrap bytemash-quote-admin">
            <h1 class="wp-heading-inline"><?php esc_html_e('Quote Requests', 'bytemash-woo-sync'); ?></h1>
            
            <div class="bytemash-quote-filters">
                <ul class="subsubsub">
                    <li class="all"><a href="?page=bytemash-quote-requests&status=wc-quote-request" class="current"><?php esc_html_e('All Quotes', 'bytemash-woo-sync'); ?> <span class="count">(<?php echo esc_html($total_posts); ?>)</span></a></li>
                </ul>
            </div>

            <div class="bytemash-quote-table-container">
                <table class="wp-list-table widefat fixed striped table-view-list posts bytemash-quote-table">
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column column-cb check-column"><input id="cb-select-all-1" type="checkbox"></th>
                            <th scope="col" class="manage-column column-primary"><?php esc_html_e('Quote #', 'bytemash-woo-sync'); ?></th>
                            <th scope="col" class="manage-column"><?php esc_html_e('Date', 'bytemash-woo-sync'); ?></th>
                            <th scope="col" class="manage-column"><?php esc_html_e('Customer', 'bytemash-woo-sync'); ?></th>
                            <th scope="col" class="manage-column"><?php esc_html_e('Items', 'bytemash-woo-sync'); ?></th>
                            <th scope="col" class="manage-column"><?php esc_html_e('Total Stock', 'bytemash-woo-sync'); ?></th>
                            <th scope="col" class="manage-column"><?php esc_html_e('Actions', 'bytemash-woo-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($query->have_posts()) : ?>
                            <?php while ($query->have_posts()) : $query->the_post(); 
                                $order = wc_get_order(get_the_ID());
                                if (!$order) continue;
                                
                                $customer_name = $order->get_formatted_billing_full_name();
                                $customer_email = $order->get_billing_email();
                                $item_count = $order->get_item_count();
                                $date_created = $order->get_date_created()->date_i18n(get_option('date_format') . ' ' . get_option('time_format'));
                                
                                // Get first item for preview
                                $items = $order->get_items();
                                $first_item = reset($items);
                                $product_name = $first_item ? $first_item->get_name() : __('Unknown Product', 'bytemash-woo-sync');
                                if (count($items) > 1) {
                                    $product_name .= sprintf(' (+%d more)', count($items) - 1);
                                }
                            ?>
                                <tr>
                                    <th scope="row" class="check-column"><input type="checkbox" name="post[]" value="<?php echo esc_attr(get_the_ID()); ?>"></th>
                                    <td class="my-column-primary">
                                        <strong><a href="?page=bytemash-quote-requests&action=view&id=<?php echo esc_attr(get_the_ID()); ?>" class="row-title">#<?php echo esc_attr($order->get_order_number()); ?></a></strong>
                                    </td>
                                    <td><?php echo esc_html($date_created); ?></td>
                                    <td>
                                        <strong><?php echo esc_html($customer_name); ?></strong><br>
                                        <a href="mailto:<?php echo esc_attr($customer_email); ?>"><?php echo esc_html($customer_email); ?></a>
                                    </td>
                                    <td><?php echo esc_html($product_name); ?></td>
                                    <td>
                                        <!-- Stock check placeholder -->
                                        <span class="bytemash-badge stock-badge"><?php esc_html_e('Check', 'bytemash-woo-sync'); ?></span>
                                    </td>
                                    <td>
                                        <a href="?page=bytemash-quote-requests&action=view&id=<?php echo esc_attr(get_the_ID()); ?>" class="button button-small action-btn"><?php esc_html_e('View Quote', 'bytemash-woo-sync'); ?></a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="7"><?php esc_html_e('No quote requests found.', 'bytemash-woo-sync'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php
            if ($total_pages > 1) {
                $page_links = paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo;', 'bytemash-woo-sync'),
                    'next_text' => __('&raquo;', 'bytemash-woo-sync'),
                    'total' => $total_pages,
                    'current' => $paged,
                ));
                if ($page_links) {
                    echo '<div class="tablenav bottom"><div class="tablenav-pages">' . $page_links . '</div></div>';
                }
            }
            ?>
        </div>
        <?php
        wp_reset_postdata();
    }

    /**
     * Render the Single Quote Details Page
     */
    public function render_quote_details_page($quote_id) {
        $order = wc_get_order($quote_id);
        if (!$order) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Quote not found.', 'bytemash-woo-sync') . '</p></div>';
            return;
        }

        ?>
        <div class="wrap bytemash-quote-details">
            <h1 class="wp-heading-inline">
                <?php printf(esc_html__('Quote #%s', 'bytemash-woo-sync'), $order->get_order_number()); ?>
                <span class="bytemash-status-badge"><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></span>
            </h1>
            <a href="?page=bytemash-quote-requests" class="page-title-action"><?php esc_html_e('Back to List', 'bytemash-woo-sync'); ?></a>
            
            <div class="bytemash-details-grid">
                <!-- Left Column: Quote Items & Branding -->
                <div class="bytemash-details-main">
                    <div class="bytemash-card product-card">
                        <h2><?php esc_html_e('Requested Items', 'bytemash-woo-sync'); ?></h2>
                        <table class="bytemash-items-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Product', 'bytemash-woo-sync'); ?></th>
                                    <th><?php esc_html_e('Variation Details', 'bytemash-woo-sync'); ?></th>
                                    <th><?php esc_html_e('Branding Options', 'bytemash-woo-sync'); ?></th>
                                    <th><?php esc_html_e('Quantity', 'bytemash-woo-sync'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($order->get_items() as $item_id => $item) : 
                                    $product = $item->get_product();
                                    $image = $product ? $product->get_image(array(50, 50)) : '';
                                    
                                    // Branding metda data
                                    $branding_data = array();
                                    foreach ($item->get_formatted_meta_data() as $meta_id => $meta) {
                                        // Filter for branding keys
                                        if (strpos($meta->key, 'Branding') !== false) {
                                            $branding_data[] = '<strong>' . $meta->display_key . ':</strong> ' . wp_strip_all_tags($meta->display_value);
                                        }
                                    }
                                ?>
                                <tr>
                                    <td class="product-col">
                                        <div class="product-thumb"><?php echo $image; ?></div>
                                        <div class="product-name">
                                            <a href="<?php echo $product ? esc_url($product->get_permalink()) : '#'; ?>" target="_blank">
                                                <?php echo esc_html($item->get_name()); ?>
                                            </a>
                                            <div class="sku"><?php echo $product ? esc_html($product->get_sku()) : ''; ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($product && $product->is_type('variation')) : ?>
                                            <?php echo wc_get_formatted_variation($product, true); ?>
                                        <?php else: ?>
                                            <span class="na">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="branding-col">
                                        <?php if (!empty($branding_data)) : ?>
                                            <ul class="branding-list">
                                                <?php foreach ($branding_data as $b) { echo '<li>' . $b . '</li>'; } ?>
                                            </ul>
                                        <?php else: ?>
                                            <span class="na"><?php esc_html_e('No branding selected', 'bytemash-woo-sync'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo esc_html($item->get_quantity()); ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Email History / Reply -->
                     <div class="bytemash-card email-card">
                        <h2><?php esc_html_e('Communication', 'bytemash-woo-sync'); ?></h2>
                        
                        <div class="email-composer">
                            <h3><?php esc_html_e('Reply to Customer', 'bytemash-woo-sync'); ?></h3>
                            <form id="bytemash-quote-reply-form">
                                <input type="hidden" name="quote_id" value="<?php echo esc_attr($quote_id); ?>">
                                <?php wp_nonce_field('bytemash_quote_email_nonce', 'email_nonce'); ?>
                                
                                <div class="form-group">
                                    <label><?php esc_html_e('To:', 'bytemash-woo-sync'); ?></label>
                                    <input type="text" class="regular-text" value="<?php echo esc_attr($order->get_billing_email()); ?>" readonly>
                                </div>
                                <div class="form-group">
                                    <label><?php esc_html_e('Subject:', 'bytemash-woo-sync'); ?></label>
                                    <input type="text" name="email_subject" class="regular-text" value="<?php printf(esc_attr__('Re: Quote Request #%s', 'bytemash-woo-sync'), $order->get_order_number()); ?>">
                                </div>
                                <div class="form-group">
                                    <label><?php esc_html_e('Message:', 'bytemash-woo-sync'); ?></label>
                                    <?php 
                                    // Simple Template Loading
                                    $template = get_option('bytemash_quote_email_template', "Dear {customer_name},\n\nThank you for your quote request #{quote_number}.\n\nWe have reviewed your requirements and...\n\nBest regards,\n{site_name}");
                                    $replacements = array(
                                        '{customer_name}' => $order->get_billing_first_name(),
                                        '{quote_number}' => $order->get_order_number(),
                                        '{site_name}' => get_bloginfo('name')
                                    );
                                    $message_val = str_replace(array_keys($replacements), array_values($replacements), $template);
                                    
                                    wp_editor($message_val, 'quote_message', array(
                                        'textarea_name' => 'email_message',
                                        'media_buttons' => false,
                                        'textarea_rows' => 10,
                                        'teeny' => true
                                    )); 
                                    ?>
                                </div>
                                <div class="form-actions">
                                    <button type="submit" class="button button-primary button-large"><?php esc_html_e('Send Email', 'bytemash-woo-sync'); ?></button>
                                    <span class="spinner"></span>
                                    <span class="response-msg"></span>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Customer & Info -->
                <div class="bytemash-details-sidebar">
                    <div class="bytemash-card customer-card">
                        <h3><?php esc_html_e('Customer Details', 'bytemash-woo-sync'); ?></h3>
                        <div class="customer-avatar">
                            <?php echo get_avatar($order->get_customer_id(), 64); ?>
                        </div>
                        <p><strong><?php echo esc_html($order->get_formatted_billing_full_name()); ?></strong></p>
                        <p><a href="mailto:<?php echo esc_attr($order->get_billing_email()); ?>"><?php echo esc_html($order->get_billing_email()); ?></a></p>
                        <p><?php echo esc_html($order->get_billing_phone()); ?></p>
                        
                        <?php if ($order->get_customer_id()) : ?>
                            <hr>
                            <p><a href="<?php echo esc_url(get_edit_user_link($order->get_customer_id())); ?>"><?php esc_html_e('View User Profile', 'bytemash-woo-sync'); ?></a></p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="bytemash-card meta-card">
                        <h3><?php esc_html_e('Request Meta', 'bytemash-woo-sync'); ?></h3>
                        <p><strong><?php esc_html_e('Date:', 'bytemash-woo-sync'); ?></strong> <?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format') . ' ' . get_option('time_format'))); ?></p>
                        <p><strong><?php esc_html_e('Status:', 'bytemash-woo-sync'); ?></strong> <?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></p>
                        <p><strong><?php esc_html_e('IP:', 'bytemash-woo-sync'); ?></strong> <?php echo esc_html($order->get_customer_ip_address()); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Settings Page for Email Configuration
     */
    public function render_settings_page() {
        if (isset($_POST['bytemash_save_quote_settings'])) {
            check_admin_referer('bytemash_quote_settings_action');
            update_option('bytemash_quote_admin_email', sanitize_email($_POST['admin_email']));
            update_option('bytemash_quote_email_subject', sanitize_text_field($_POST['email_subject']));
            update_option('bytemash_quote_email_template', wp_kses_post($_POST['email_template']));
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'bytemash-woo-sync') . '</p></div>';
        }

        $admin_email = get_option('bytemash_quote_admin_email', get_option('admin_email'));
        $subject = get_option('bytemash_quote_email_subject', 'New Quote Request from {site_name}');
        $template = get_option('bytemash_quote_email_template', "Dear {customer_name},\n\nThank you for your quote request #{quote_number}.\n\nWe have reviewed your requirements and...\n\nBest regards,\n{site_name}");
        ?>
        <div class="wrap bytemash-quote-settings">
            <h1><?php esc_html_e('Quote System Settings', 'bytemash-woo-sync'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('bytemash_quote_settings_action'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="admin_email"><?php esc_html_e('Notification Email', 'bytemash-woo-sync'); ?></label></th>
                        <td>
                            <input type="email" name="admin_email" id="admin_email" value="<?php echo esc_attr($admin_email); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Email address to receive new quote notifications.', 'bytemash-woo-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email_subject"><?php esc_html_e('Email Subject (Default)', 'bytemash-woo-sync'); ?></label></th>
                        <td>
                            <input type="text" name="email_subject" id="email_subject" value="<?php echo esc_attr($subject); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email_template"><?php esc_html_e('Email Body Template', 'bytemash-woo-sync'); ?></label></th>
                        <td>
                            <textarea name="email_template" id="email_template" rows="10" class="large-text code"><?php echo esc_textarea($template); ?></textarea>
                            <p class="description">
                                <?php esc_html_e('Available placeholders: {customer_name}, {quote_number}, {site_name}, {product_table}', 'bytemash-woo-sync'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" name="bytemash_save_quote_settings" class="button button-primary"><?php esc_html_e('Save Settings', 'bytemash-woo-sync'); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * AJAX Handler: Send Quote Email
     */
    public function ajax_send_quote_email() {
        check_ajax_referer('bytemash_quote_email_nonce', 'email_nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied', 'bytemash-woo-sync')));
        }

        $quote_id = isset($_POST['quote_id']) ? intval($_POST['quote_id']) : 0;
        $subject = isset($_POST['email_subject']) ? sanitize_text_field($_POST['email_subject']) : '';
        $message = isset($_POST['email_message']) ? wp_kses_post($_POST['email_message']) : '';

        if (!$quote_id || empty($message)) {
            wp_send_json_error(array('message' => __('Missing required fields', 'bytemash-woo-sync')));
        }

        $order = wc_get_order($quote_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Quote not found', 'bytemash-woo-sync')));
        }

        $to = $order->get_billing_email();
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $sent = wp_mail($to, $subject, $message, $headers);

        if ($sent) {
            $order->add_order_note(sprintf(__('Email sent to customer: %s', 'bytemash-woo-sync'), $subject));
            wp_send_json_success(array('message' => __('Email sent successfully', 'bytemash-woo-sync')));
        } else {
            wp_send_json_error(array('message' => __('Failed to send email. Check server logs.', 'bytemash-woo-sync')));
        }
    }
}
