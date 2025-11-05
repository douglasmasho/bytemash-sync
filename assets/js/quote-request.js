/**
 * Quote Request Handler for WooCommerce Products
 * 
 * Handles quote request submission with color, size, and branding options
 */

jQuery(document).ready(function($) {
    'use strict';
    
    // Check if quote request data is available
    if (typeof bytemashQuoteRequest === 'undefined') {
        return;
    }
    
    /**
     * Get currently selected variation ID
     */
    function getSelectedVariationId() {
        // For variable products, get the selected variation
        const $variationsForm = $('.variations_form');
        if ($variationsForm.length > 0) {
            const variationId = $variationsForm.find('input[name="variation_id"]').val();
            if (variationId && variationId !== '') {
                return parseInt(variationId);
            }
        }
        return 0;
    }
    
    /**
     * Get selected color
     */
    function getSelectedColor() {
        // Try color attribute select
        const colorSelect = $('select[name="attribute_color"]').val();
        if (colorSelect) {
            return colorSelect;
        }
        
        // Try color swatch
        const selectedSwatch = $('.bytemash-color-swatch.selected');
        if (selectedSwatch.length > 0) {
            return selectedSwatch.attr('data-color-value') || selectedSwatch.attr('data-color-name') || '';
        }
        
        return '';
    }
    
    /**
     * Get selected size
     */
    function getSelectedSize() {
        // Try size attribute select
        const sizeSelect = $('select[name="attribute_size"]').val();
        if (sizeSelect) {
            return sizeSelect;
        }
        
        // Try size button
        const selectedButton = $('.bytemash-size-button.selected');
        if (selectedButton.length > 0) {
            return selectedButton.attr('data-size-value') || selectedButton.text() || '';
        }
        
        return '';
    }
    
    /**
     * Get selected branding options
     */
    function getSelectedBrandings() {
        const brandings = {};
        
        // Get all checked branding checkboxes
        $('input[name^="bytemash_brandings["]:checked').each(function() {
            const $input = $(this);
            const name = $input.attr('name');
            
            // Extract position code from name like "bytemash_brandings[A][]"
            const match = name.match(/bytemash_brandings\[([^\]]+)\]/);
            if (match && match[1]) {
                const posCode = match[1];
                const code = $input.val();
                
                if (!brandings[posCode]) {
                    brandings[posCode] = [];
                }
                brandings[posCode].push(code);
            }
        });
        
        return brandings;
    }
    
    /**
     * Get quantity
     */
    function getQuantity() {
        // Try both regular quantity input and quote-specific quantity
        let quantityInput = $('input[name="quantity"]');
        if (quantityInput.length === 0) {
            quantityInput = $('#bytemash-quote-quantity');
        }
        if (quantityInput.length > 0) {
            const qty = parseInt(quantityInput.val());
            return qty > 0 ? qty : 1;
        }
        return 1;
    }
    
    /**
     * Handle quote request button click
     */
    $('#bytemash-request-quote-btn').on('click', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const $message = $('#bytemash-quote-request-message');
        
        // Get product data
        const productId = bytemashQuoteRequest.product_id;
        const variationId = getSelectedVariationId();
        const quantity = getQuantity();
        const selectedColor = getSelectedColor();
        const selectedSize = getSelectedSize();
        const brandings = getSelectedBrandings();
        
        // Check if variation is required for variable products
        const $variationsForm = $('.variations_form');
        if ($variationsForm.length > 0 && variationId === 0) {
            // Only require variation if there are variations available
            const hasVariations = $variationsForm.find('table.variations').length > 0 || 
                                 $variationsForm.find('.bytemash-variations-container').length > 0;
            if (hasVariations) {
                $message
                    .removeClass('woocommerce-message woocommerce-error')
                    .addClass('woocommerce-error')
                    .html('<p>' + bytemashQuoteRequest.strings.select_variation + '</p>')
                    .show();
                return;
            }
        }
        
        // Disable button and show loading
        $button.prop('disabled', true).text(bytemashQuoteRequest.strings.requesting);
        $message.hide();
        
        // Prepare AJAX data
        const ajaxData = {
            action: 'bytemash_submit_quote_request',
            nonce: bytemashQuoteRequest.nonce,
            product_id: productId,
            variation_id: variationId,
            quantity: quantity,
            color: selectedColor,
            size: selectedSize,
            brandings: brandings,
        };
        
        // Submit AJAX request
        $.ajax({
            url: bytemashQuoteRequest.ajax_url,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                if (response.success) {
                    $message
                        .removeClass('woocommerce-error')
                        .addClass('woocommerce-message')
                        .html('<p>' + (response.data.message || bytemashQuoteRequest.strings.success) + '</p>')
                        .show();
                    
                    // Reset button
                    $button.prop('disabled', false).text($button.data('original-text') || 'Request Quote');
                    
                    // Optionally reset form
                    $('.reset_variations').trigger('click');
                } else {
                    $message
                        .removeClass('woocommerce-message')
                        .addClass('woocommerce-error')
                        .html('<p>' + (response.data.message || bytemashQuoteRequest.strings.error) + '</p>')
                        .show();
                    
                    $button.prop('disabled', false).text($button.data('original-text') || 'Request Quote');
                }
            },
            error: function(xhr, status, error) {
                $message
                    .removeClass('woocommerce-message')
                    .addClass('woocommerce-error')
                    .html('<p>' + bytemashQuoteRequest.strings.error + '</p>')
                    .show();
                
                $button.prop('disabled', false).text($button.data('original-text') || 'Request Quote');
                
                console.error('Quote request error:', error);
            }
        });
    });
    
    // Store original button text
    $('#bytemash-request-quote-btn').data('original-text', $('#bytemash-request-quote-btn').text());
});

