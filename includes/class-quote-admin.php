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
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Enqueue admin assets for Quote pages
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'bytemash-quote') === false) {
            return;
        }

        $css_file = BYTEMASH_WOO_SYNC_PLUGIN_DIR . 'assets/css/quote-admin.css';
        $css_version = BYTEMASH_WOO_SYNC_VERSION . '.' . (file_exists($css_file) ? filemtime($css_file) : time());

        wp_enqueue_style(
            'bytemash-quote-admin-style',
            BYTEMASH_WOO_SYNC_PLUGIN_URL . 'assets/css/quote-admin.css',
            array(),
            $css_version
        );
    }

    /**
     * Render the main Quote Requests List Page
     */
    public function render_quote_list_page() {
        // Handle actions like delete before rendering
        $this->handle_quote_actions();
        
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'wc-quote-request';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $posts_per_page = 20;

        $args = array(
            'status'   => $status === 'all' ? 'wc-quote-request' : $status,
            'limit'    => $posts_per_page,
            'paged'    => $paged,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'paginate' => true,
        );

        $results = wc_get_orders($args);
        $orders = $results->orders;
        $total_posts = $results->total;
        $total_pages = $results->max_num_pages;

        ?>
        <div class="wrap bytemash-quote-admin bytemash-modern-admin">
            <div class="bytemash-admin-header">
                <h1 class="wp-heading-inline"><?php esc_html_e('Quote Requests', 'bytemash-woo-sync'); ?></h1>
            </div>

            <?php if (isset($_GET['deleted']) && $_GET['deleted'] == '1') : ?>
                <div class="notice notice-success is-dismissible" style="border-radius: 8px; border-left-color: #10b981;">
                    <p><strong><?php esc_html_e('Quote request deleted successfully.', 'bytemash-woo-sync'); ?></strong></p>
                </div>
            <?php endif; ?>
            
            <div class="bytemash-quote-filters" style="margin-bottom: 15px; margin-top: 10px;">
                <a href="?page=bytemash-quote-requests&status=wc-quote-request" style="font-size: 14px; text-decoration: none; color: #0f172a; font-weight: 500;">
                    <?php esc_html_e('All Quotes', 'bytemash-woo-sync'); ?> 
                    <span style="color: #64748b; font-weight: normal;">(<?php echo esc_html($total_posts); ?>)</span>
                </a>
            </div>

            <div class="bytemash-quote-table-container">
                <table class="bytemash-modern-table">
                    <thead>
                        <tr>
                            <th scope="col" class="check-column"><input id="cb-select-all-1" type="checkbox" style="border-radius: 4px;"></th>
                            <th scope="col"><?php esc_html_e('Quote ID', 'bytemash-woo-sync'); ?></th>
                            <th scope="col"><?php esc_html_e('Product Info', 'bytemash-woo-sync'); ?></th>
                            <th scope="col"><?php esc_html_e('Customer Name', 'bytemash-woo-sync'); ?></th>
                            <th scope="col"><?php esc_html_e('Quantity', 'bytemash-woo-sync'); ?></th>
                            <th scope="col"><?php esc_html_e('Date', 'bytemash-woo-sync'); ?></th>
                            <th scope="col"><?php esc_html_e('Status', 'bytemash-woo-sync'); ?></th>
                            <th scope="col"><?php esc_html_e('Action', 'bytemash-woo-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($orders)) : ?>
                            <?php foreach ($orders as $order) : 
                                $customer_name = $order->get_formatted_billing_full_name();
                                $customer_email = $order->get_billing_email();
                                
                                // Format Date precisely
                                $date_created = $order->get_date_created() ? $order->get_date_created()->date_i18n('M d, Y') : '';
                                
                                // Get first item for preview
                                $items = $order->get_items();
                                $first_item = reset($items);
                                $product_name = $first_item ? $first_item->get_name() : __('Unknown Product', 'bytemash-woo-sync');
                                $quantity = 0;
                                foreach($items as $item) { $quantity += $item->get_quantity(); }
                                
                                $product = $first_item ? $first_item->get_product() : false;
                                $image_html = $product ? $product->get_image(array(32, 32)) : '';
                            ?>
                                <tr>
                                    <td class="check-column"><input type="checkbox" name="post[]" value="<?php echo esc_attr($order->get_id()); ?>" style="border-radius: 4px;"></td>
                                    <td class="quote-id-col">
                                        <strong><a href="?page=bytemash-quote-requests&action=view&id=<?php echo esc_attr($order->get_id()); ?>">#<?php echo esc_attr($order->get_order_number()); ?></a></strong>
                                    </td>
                                    <td class="product-info-col">
                                        <div class="product-info-flex">
                                            <?php if ($image_html) echo '<div class="product-img-tiny">' . $image_html . '</div>'; ?>
                                            <span>
                                                <?php echo esc_html($product_name); ?>
                                                <?php if (count($items) > 1) echo '<span class="more-items">(+' . (count($items) - 1) . ' items)</span>'; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="customer-col">
                                        <div class="customer-info-flex">
                                            <?php echo get_avatar($customer_email, 28); ?>
                                            <div class="customer-text">
                                                <strong><?php echo esc_html($customer_name); ?></strong>
                                                <span class="customer-email-sub"><?php echo esc_html($customer_email); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html($quantity); ?> pcs</td>
                                    <td class="date-col"><?php echo esc_html($date_created); ?></td>
                                    <td>
                                        <?php 
                                            // Ensure we always have a status pill
                                            $status_name = wc_get_order_status_name($order->get_status());
                                            $status_class = 'status-' . str_replace('wc-', '', $order->get_status());
                                        ?>
                                        <span class="bytemash-status-pill <?php echo esc_attr($status_class); ?>">
                                            <span class="dot"></span>
                                            <?php echo esc_html($status_name); ?>
                                        </span>
                                    </td>
                                    <td class="actions-col">
                                        <div class="bytemash-row-actions">
                                            <a href="?page=bytemash-quote-requests&action=view&id=<?php echo esc_attr($order->get_id()); ?>" class="action-btn view-btn" title="<?php esc_attr_e('View', 'bytemash-woo-sync'); ?>">
                                                <span class="dashicons dashicons-visibility"></span>
                                            </a>
                                            <a href="<?php echo wp_nonce_url('?page=bytemash-quote-requests&action=delete&id=' . esc_attr($order->get_id()), 'delete_quote_' . $order->get_id()); ?>" class="action-btn delete-btn" title="<?php esc_attr_e('Delete', 'bytemash-woo-sync'); ?>" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this quote request? This cannot be undone.', 'bytemash-woo-sync'); ?>');">
                                                <span class="dashicons dashicons-trash"></span>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px; color: #64748b;">
                                    <?php esc_html_e('No quote requests found.', 'bytemash-woo-sync'); ?>
                                </td>
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
     * Handle custom actions from the admin list (e.g., delete)
     */
    private function handle_quote_actions() {
        if (!isset($_GET['action']) || !isset($_GET['id'])) {
            return;
        }

        $action = sanitize_text_field($_GET['action']);
        $quote_id = intval($_GET['id']);

        if ($action === 'delete') {
            check_admin_referer('delete_quote_' . $quote_id);
            if (current_user_can('manage_woocommerce')) {
                $order = wc_get_order($quote_id);
                if ($order) {
                    $order->delete(true); // Force delete
                    
                    // Redirect back to list page with success message
                    $redirect_url = add_query_arg(array(
                        'page' => 'bytemash-quote-requests',
                        'deleted' => 1
                    ), admin_url('admin.php'));
                    
                    wp_redirect($redirect_url);
                    exit;
                }
            }
        }
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
                        <table class="bytemash-modern-table">
                            <thead>
                                <tr>
                                    <th style="width: 40px;"></th>
                                    <th><?php esc_html_e('Product', 'bytemash-woo-sync'); ?></th>
                                    <th><?php esc_html_e('Variation Details', 'bytemash-woo-sync'); ?></th>
                                    <th><?php esc_html_e('Branding Options', 'bytemash-woo-sync'); ?></th>
                                    <th><?php esc_html_e('Quantity', 'bytemash-woo-sync'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($order->get_items() as $item_id => $item) : 
                                    $product = $item->get_product();
                                    $image_tiny = $product ? $product->get_image(array(40, 40)) : '';
                                    $image_full = $product ? $product->get_image('full') : '';
                                    
                                    // Fetch all available brandings for this product to map codes to names
                                    $parent_id = $product ? ($product->is_type('variation') ? $product->get_parent_id() : $product->get_id()) : 0;
                                    $amrod_brandings = $parent_id ? get_post_meta($parent_id, '_amrod_brandings', true) : array();
                                    
                                    // Branding metda data
                                    $branding_data = array();
                                    foreach ($item->get_formatted_meta_data() as $meta_id => $meta) {
                                        // Filter for branding keys
                                        if (strpos($meta->key, 'Branding') !== false) {
                                            $raw_value = wp_strip_all_tags($meta->display_value);
                                            $full_name = $raw_value;
                                            
                                            // Try to find the full branding name from the Amrod data
                                            if (!empty($amrod_brandings) && is_array($amrod_brandings)) {
                                                foreach ($amrod_brandings as $pos) {
                                                    if (!empty($pos['method']) && is_array($pos['method'])) {
                                                        foreach ($pos['method'] as $method) {
                                                            if ($method['brandingCode'] === $raw_value) {
                                                                $full_name = esc_html($method['brandingName']) . ' (' . $raw_value . ')';
                                                                break 2;
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                            
                                            $branding_data[] = array(
                                                'label' => $meta->display_key,
                                                'value' => $full_name
                                            );
                                        }
                                    }
                                ?>
                                <tr class="bytemash-item-main-row">
                                    <td style="text-align:center;">
                                        <button type="button" class="bytemash-expand-toggle" title="<?php esc_attr_e('View Details', 'bytemash-woo-sync'); ?>">
                                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                                        </button>
                                    </td>
                                    <td class="product-info-col">
                                        <div class="product-info-flex">
                                            <?php if ($image_tiny) echo '<div class="product-img-tiny">' . $image_tiny . '</div>'; ?>
                                            <div class="product-text">
                                                <a href="<?php echo $product ? esc_url($product->get_permalink()) : '#'; ?>" target="_blank" style="font-weight: 600; color: #1e293b; text-decoration: none;">
                                                    <?php echo esc_html($item->get_name()); ?>
                                                </a>
                                                <?php if ($product && $product->get_sku()) : ?>
                                                    <div class="sku-subtext" style="font-size: 12px; color: #64748b;"><?php echo esc_html($product->get_sku()); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($product && $product->is_type('variation')) : ?>
                                            <?php echo wc_get_formatted_variation($product, true); ?>
                                        <?php else: ?>
                                            <span class="na" style="color:#cbd5e1;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="branding-col">
                                        <?php if (!empty($branding_data)) : ?>
                                            <span class="branding-badge"><?php echo count($branding_data); ?> <?php esc_html_e('Option(s)', 'bytemash-woo-sync'); ?></span>
                                        <?php else: ?>
                                            <span class="na" style="color:#cbd5e1;"><?php esc_html_e('No branding selected', 'bytemash-woo-sync'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong style="font-size: 16px; color: #0f172a;"><?php echo esc_html($item->get_quantity()); ?></strong></td>
                                </tr>
                                
                                <!-- Expanded Details Row -->
                                <tr class="bytemash-expanded-row" style="display: none;">
                                    <td colspan="5">
                                        <div class="bytemash-expanded-content">
                                            <div class="expanded-images">
                                                <div class="expanded-image-card">
                                                    <h4><?php esc_html_e('Product Image', 'bytemash-woo-sync'); ?></h4>
                                                    <?php if ($image_full) { echo $image_full; } else { echo '<div class="no-image">No Image</div>'; } ?>
                                                </div>
                                            </div>
                                            <div class="expanded-details">
                                                <h4><?php esc_html_e('Full Branding Specifics', 'bytemash-woo-sync'); ?></h4>
                                                <?php if (!empty($branding_data)) : ?>
                                                    <div class="expanded-branding-list">
                                                        <?php foreach ($branding_data as $b) : ?>
                                                            <div class="branding-line-item">
                                                                <span class="branding-value"><?php echo esc_html($b['label'] . ' - ' . $b['value']); ?></span>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <p style="color: #64748b; font-style: italic;"><?php esc_html_e('Customer did not select any specific branding options for this item.', 'bytemash-woo-sync'); ?></p>
                                                <?php endif; ?>
                                                
                                                <?php if ($product && $product->is_type('variation')) : ?>
                                                    <h4 style="margin-top: 20px;"><?php esc_html_e('Full Configuration', 'bytemash-woo-sync'); ?></h4>
                                                    <div style="background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0; color: #475569;">
                                                        <?php echo wc_get_formatted_variation($product, true); ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php 
                                                if ($product) {
                                                    $parent_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
                                                    $full_guide = get_post_meta($parent_id, '_amrod_full_branding_guide', true);
                                                    $logo24_guide = get_post_meta($parent_id, '_amrod_logo24_branding_guide', true);
                                                    
                                                    if ($full_guide || $logo24_guide) :
                                                ?>
                                                    <h4 style="margin-top: 20px;"><?php esc_html_e('Product Branding Guides', 'bytemash-woo-sync'); ?></h4>
                                                    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                                                        <?php if ($full_guide) : ?>
                                                            <a href="<?php echo esc_url($full_guide); ?>" target="_blank" download class="bytemash-guide-btn">
                                                                <span class="dashicons dashicons-pdf"></span> <?php esc_html_e('Full Branding Guide', 'bytemash-woo-sync'); ?>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($logo24_guide) : ?>
                                                            <a href="<?php echo esc_url($logo24_guide); ?>" target="_blank" download class="bytemash-guide-btn logo24">
                                                                <span class="dashicons dashicons-pdf"></span> <?php esc_html_e('Logo24 Guide', 'bytemash-woo-sync'); ?>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php 
                                                    endif;
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <script>
                        jQuery(document).ready(function($) {
                            $('.bytemash-expand-toggle').on('click', function(e) {
                                e.preventDefault();
                                var $btn = $(this);
                                var $row = $btn.closest('tr');
                                var $expandedRow = $row.next('.bytemash-expanded-row');
                                var $expandedContent = $expandedRow.find('.bytemash-expanded-content');
                                
                                if ($expandedRow.is(':visible')) {
                                    $btn.removeClass('active').find('.dashicons').removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
                                    $expandedContent.slideUp(300, function() {
                                        $expandedRow.hide();
                                        $row.find('td').css('border-bottom', '');
                                    });
                                } else {
                                    $btn.addClass('active').find('.dashicons').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                                    $row.find('td').css('border-bottom', 'none');
                                    $expandedRow.show();
                                    $expandedContent.hide().slideDown(300);
                                }
                            });
                        });
                        </script>
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
                                    <span class="response-msg" style="margin-left: 10px; font-weight: 500;"></span>
                                </div>
                            </form>
                            
                            <script>
                            jQuery(document).ready(function($) {
                                $('#bytemash-quote-reply-form').on('submit', function(e) {
                                    e.preventDefault();
                                    
                                    var $form = $(this);
                                    var $btn = $form.find('button[type="submit"]');
                                    var $spinner = $form.find('.spinner');
                                    var $msg = $form.find('.response-msg');
                                    
                                    $btn.prop('disabled', true);
                                    $spinner.addClass('is-active');
                                    $msg.text('').removeClass('error success');
                                    
                                    // Make sure TinyMCE content is saved back to textarea
                                    if (typeof tinyMCE !== 'undefined' && tinyMCE.get('email_message')) {
                                        tinyMCE.get('email_message').save();
                                    }
                                    
                                    var formData = $form.serialize();
                                    formData += '&action=bytemash_send_quote_email';
                                    
                                    $.post(ajaxurl, formData, function(res) {
                                        $spinner.removeClass('is-active');
                                        if (res.success) {
                                            $msg.css('color', '#10b981').text(res.data.message);
                                            setTimeout(function() {
                                                window.location.reload();
                                            }, 1500);
                                        } else {
                                            $btn.prop('disabled', false);
                                            $msg.css('color', '#ef4444').text(res.data.message || 'An error occurred.');
                                        }
                                    }).fail(function() {
                                        $spinner.removeClass('is-active');
                                        $btn.prop('disabled', false);
                                        $msg.css('color', '#ef4444').text('Server error. Please try again.');
                                    });
                                });
                            });
                            </script>
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

                    <?php 
                    $instructions = $order->get_meta('_bytemash_quote_instructions');
                    $files = $order->get_meta('_bytemash_quote_files');
                    if ($instructions || !empty($files)) :
                    ?>
                    <div class="bytemash-card request-details-card">
                        <h3><?php esc_html_e('Extra Details', 'bytemash-woo-sync'); ?></h3>
                        
                        <?php if ($instructions) : ?>
                            <h4><?php esc_html_e('Special Instructions', 'bytemash-woo-sync'); ?></h4>
                            <div style="background: #f9f9f9; padding: 10px; border-left: 3px solid #00a0d2;">
                                <?php echo wpautop(wp_kses_post($instructions)); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($files) && is_array($files)) : ?>
                            <h4 style="margin-top: 20px;"><?php esc_html_e('Uploaded Files', 'bytemash-woo-sync'); ?></h4>
                            <div class="bytemash-files-grid">
                                <?php foreach ($files as $file) : 
                                    $is_img = preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file['url']);
                                ?>
                                    <div class="bytemash-file-item">
                                        <div class="bytemash-file-preview">
                                            <?php if ($is_img) : ?>
                                                <img src="<?php echo esc_url($file['url']); ?>" alt="File Preview">
                                            <?php else : ?>
                                                <span class="dashicons dashicons-media-document bytemash-file-icon"></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="bytemash-file-actions">
                                            <a href="<?php echo esc_url($file['url']); ?>" target="_blank" download>
                                                <span class="dashicons dashicons-download"></span>
                                                <?php echo esc_html($file['label'] ? $file['label'] : basename($file['url'])); ?>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
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
            update_option('bytemash_quote_from_email', sanitize_email($_POST['from_email']));
            update_option('bytemash_quote_admin_email', sanitize_text_field($_POST['admin_email']));
            update_option('bytemash_quote_email_subject', sanitize_text_field($_POST['email_subject']));
            update_option('bytemash_quote_email_template', wp_kses_post($_POST['email_template']));
            update_option('bytemash_quote_customer_subject', sanitize_text_field($_POST['customer_subject']));
            update_option('bytemash_quote_customer_template', wp_kses_post($_POST['customer_template']));
            update_option('bytemash_quote_cart_page_id', intval($_POST['quote_cart_page_id']));
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'bytemash-woo-sync') . '</p></div>';
        }

        $admin_email = get_option('bytemash_quote_admin_email', get_option('admin_email'));
        $from_email  = get_option('bytemash_quote_from_email', get_option('admin_email'));
        $subject = get_option('bytemash_quote_email_subject', 'New Quote Request from {site_name}');
        $template = get_option('bytemash_quote_email_template', "New quote request received.\n\nCustomer: {customer_name}\nQuote #: {quote_number}\n\nPlease check the admin dashboard for details.");
        
        $customer_subject = get_option('bytemash_quote_customer_subject', 'Quote Request Received - #{quote_number}');
        $customer_template = get_option('bytemash_quote_customer_template', "Dear {customer_name},\n\nWe have received your quote request #{quote_number}.\n\nOur team is currently reviewing your requirements and will get back to you shortly with pricing and availability.\n\nBest regards,\nThe {site_name} Team");
        
        $quote_cart_page_id = get_option('bytemash_quote_cart_page_id', '');
        ?>
        <div class="wrap bytemash-quote-settings">
            <h1><?php esc_html_e('Quote System Settings', 'bytemash-woo-sync'); ?></h1>
            
            <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
                <h2><?php esc_html_e('How to set up the Quote Cart', 'bytemash-woo-sync'); ?></h2>
                <p><?php esc_html_e('To enable customers to view their quote cart and submit a request, you need to create a dedicated Quote Cart page.', 'bytemash-woo-sync'); ?></p>
                <ol>
                    <li><?php esc_html_e('Go to ', 'bytemash-woo-sync'); ?><a href="<?php echo admin_url('post-new.php?post_type=page'); ?>"><?php esc_html_e('Pages > Add New', 'bytemash-woo-sync'); ?></a>.</li>
                    <li><?php esc_html_e('Name the page "Quote Cart" (or similar).', 'bytemash-woo-sync'); ?></li>
                    <li><?php esc_html_e('Enter this shortcode into the page content: ', 'bytemash-woo-sync'); ?><code>[bytemash_quote_cart]</code></li>
                    <li><?php esc_html_e('Publish the page.', 'bytemash-woo-sync'); ?></li>
                    <li><?php esc_html_e('Select that page in the "Quote Cart Page" dropdown below and save settings.', 'bytemash-woo-sync'); ?></li>
                </ol>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('bytemash_quote_settings_action'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="quote_cart_page_id"><?php esc_html_e('Quote Cart Page', 'bytemash-woo-sync'); ?></label></th>
                        <td>
                            <?php 
                            wp_dropdown_pages(array(
                                'name' => 'quote_cart_page_id',
                                'id' => 'quote_cart_page_id',
                                'show_option_none' => __('— Select Page —', 'bytemash-woo-sync'),
                                'option_none_value' => '0',
                                'selected' => $quote_cart_page_id
                            )); 
                            ?>
                            <p class="description"><?php esc_html_e('Select the page that contains the [bytemash_quote_cart] shortcode. This is where users will be redirected to view their cart.', 'bytemash-woo-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" colspan="2">
                            <h3 style="margin: 20px 0 0 0; padding-bottom: 10px; border-bottom: 1px solid #ccc;"><?php esc_html_e('Global Email Settings', 'bytemash-woo-sync'); ?></h3>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row"><label for="from_email"><?php esc_html_e('From Email Address', 'bytemash-woo-sync'); ?></label></th>
                        <td>
                            <input type="email" name="from_email" id="from_email" value="<?php echo esc_attr($from_email); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('The email address that all quote emails will be sent FROM (e.g. sales@yourdomain.com).', 'bytemash-woo-sync'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row" colspan="2">
                            <h3 style="margin: 20px 0 0 0; padding-bottom: 10px; border-bottom: 1px solid #ccc;"><?php esc_html_e('Admin Notifications', 'bytemash-woo-sync'); ?></h3>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row"><label for="admin_email"><?php esc_html_e('Notification Emails', 'bytemash-woo-sync'); ?></label></th>
                        <td>
                            <input type="text" name="admin_email" id="admin_email" value="<?php echo esc_attr($admin_email); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Email addresses to receive new quote notifications. Separate multiple emails with commas.', 'bytemash-woo-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email_subject"><?php esc_html_e('Admin Email Subject', 'bytemash-woo-sync'); ?></label></th>
                        <td>
                            <input type="text" name="email_subject" id="email_subject" value="<?php echo esc_attr($subject); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="email_template"><?php esc_html_e('Admin Email Template', 'bytemash-woo-sync'); ?></label></th>
                        <td>
                            <textarea name="email_template" id="email_template" rows="6" class="large-text code"><?php echo esc_textarea($template); ?></textarea>
                            <p class="description">
                                <?php esc_html_e('Available placeholders: {customer_name}, {quote_number}, {site_name}', 'bytemash-woo-sync'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row" colspan="2">
                            <h3 style="margin: 20px 0 0 0; padding-bottom: 10px; border-bottom: 1px solid #ccc;"><?php esc_html_e('Customer Confirmation Email', 'bytemash-woo-sync'); ?></h3>
                            <p class="description"><?php esc_html_e('This email is sent to the customer automatically right after they submit a quote request.', 'bytemash-woo-sync'); ?></p>
                        </th>
                    </tr>
                    <tr>
                        <th scope="row"><label for="customer_subject"><?php esc_html_e('Customer Email Subject', 'bytemash-woo-sync'); ?></label></th>
                        <td>
                            <input type="text" name="customer_subject" id="customer_subject" value="<?php echo esc_attr($customer_subject); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="customer_template"><?php esc_html_e('Customer Email Template', 'bytemash-woo-sync'); ?></label></th>
                        <td>
                            <textarea name="customer_template" id="customer_template" rows="6" class="large-text code"><?php echo esc_textarea($customer_template); ?></textarea>
                            <p class="description">
                                <?php esc_html_e('Available placeholders: {customer_name}, {quote_number}, {site_name}', 'bytemash-woo-sync'); ?>
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
        $from_email  = get_option('bytemash_quote_from_email', get_option('admin_email'));
        $site_name   = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . $from_email . '>'
        );
        
        $sent = wp_mail($to, $subject, wpautop($message), $headers);

        if ($sent) {
            $order->add_order_note(sprintf(__('Email sent to customer: %s', 'bytemash-woo-sync'), $subject));
            wp_send_json_success(array('message' => __('Email sent successfully', 'bytemash-woo-sync')));
        } else {
            wp_send_json_error(array('message' => __('Failed to send email. Check server logs.', 'bytemash-woo-sync')));
        }
    }
}
