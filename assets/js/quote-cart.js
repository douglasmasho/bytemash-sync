jQuery(document).ready(function($) {
    'use strict';

    if (typeof bytemashQuoteCart === 'undefined' || $('#bytemash-quote-cart-app').length === 0) {
        return;
    }

    const $cartContent = $('#bytemash-quote-cart-content');
    const $cartEmpty = $('#bytemash-quote-cart-empty');
    const $cartLoading = $('#bytemash-quote-cart-loading');
    const $cartItemsTbody = $('#bytemash-quote-cart-items');
    const $form = $('#bytemash-submit-quote-form');
    let quoteCart = [];

    // Load Cart
    function loadCart() {
        const cartStr = localStorage.getItem('bytemash_quote_cart');
        if (cartStr) {
            try {
                quoteCart = JSON.parse(cartStr);
            } catch (e) {
                quoteCart = [];
            }
        }

        if (quoteCart.length === 0) {
            showEmptyCart();
            return;
        }

        // Fetch HTML
        $.ajax({
            url: bytemashQuoteCart.ajax_url,
            type: 'POST',
            data: {
                action: 'bytemash_get_cart_html',
                nonce: bytemashQuoteCart.nonce,
                items: JSON.stringify(quoteCart)
            },
            success: function(res) {
                $cartLoading.hide();
                if (res.success && !res.data.empty) {
                    $cartItemsTbody.html(res.data.html);
                    $cartContent.show();
                    
                    // Pre-fill fields if empty
                    if ($('#quote_name').val() === '') {
                        $('#quote_name').val(bytemashQuoteCart.default_name);
                    }
                    if ($('#quote_email').val() === '') {
                        $('#quote_email').val(bytemashQuoteCart.default_email);
                    }
                } else {
                    showEmptyCart();
                }
            },
            error: function() {
                $cartLoading.text('Failed to load cart. Please refresh.').addClass('woocommerce-error');
            }
        });
    }

    function showEmptyCart() {
        $cartContent.hide();
        $cartLoading.hide();
        $cartEmpty.show();
    }

    // Initialize
    loadCart();

    // Handle Quantity Change
    $(document).on('change', '.bytemash-cart-qty', function() {
        const id = $(this).data('id');
        const newQty = parseInt($(this).val());
        if (newQty < 1) {
            $(this).val(1);
            return;
        }

        const itemIndex = quoteCart.findIndex(i => i.id === id);
        if (itemIndex > -1) {
            quoteCart[itemIndex].quantity = newQty;
            localStorage.setItem('bytemash_quote_cart', JSON.stringify(quoteCart));
        }
    });

    // Handle Item Removal
    $(document).on('click', '.bytemash-remove-item', function(e) {
        e.preventDefault();
        const id = $(this).data('id');
        
        quoteCart = quoteCart.filter(i => i.id !== id);
        localStorage.setItem('bytemash_quote_cart', JSON.stringify(quoteCart));
        
        // Remove row
        $(this).closest('tr').fadeOut(300, function() {
            $(this).remove();
            if (quoteCart.length === 0) {
                showEmptyCart();
            }
        });
    });

    // Add File Row
    $('#bytemash-add-file-row').on('click', function() {
        $('#bytemash-file-uploads').append(
            '<div class="bytemash-file-upload-row">' +
                '<input type="text" name="file_labels[]" placeholder="Label (e.g. Front Logo)" class="input-text" style="width: 48%; display: inline-block;"> ' +
                '<input type="file" name="quote_files[]" class="input-text" accept=".jpg,.jpeg,.png,.pdf,.ai,.eps,.svg" style="width: 48%; display: inline-block;">' +
            '</div>'
        );
    });

    // Submit Form
    $form.on('submit', function(e) {
        e.preventDefault();
        
        const $btn = $('#bytemash-submit-quote-btn');
        const originalText = $btn.text();
        const $msg = $('#bytemash-quote-submit-message');
        
        $btn.prop('disabled', true).text(bytemashQuoteCart.submitting);
        $msg.hide().removeClass('woocommerce-error woocommerce-message');

        const formData = new FormData(this);
        formData.append('action', 'bytemash_submit_quote_cart');
        formData.append('security', bytemashQuoteCart.nonce);
        formData.append('items', JSON.stringify(quoteCart));

        $.ajax({
            url: bytemashQuoteCart.ajax_url,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            success: function(res) {
                if (res.success) {
                    $msg.addClass('woocommerce-message').html('<p>' + res.data.message + '</p>').show();
                    // Clear cart
                    localStorage.removeItem('bytemash_quote_cart');
                    $form.hide();
                    $cartItemsTbody.closest('table').hide();
                    $btn.hide();
                    
                    // Optional redirect or just show success page inside the shortcode.
                } else {
                    $msg.addClass('woocommerce-error').html('<p>' + res.data.message + '</p>').show();
                    $btn.prop('disabled', false).text(originalText);
                }
            },
            error: function(xhr) {
                $msg.addClass('woocommerce-error').html('<p>Failed to submit quote request. Please try again.</p>').show();
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });
});
