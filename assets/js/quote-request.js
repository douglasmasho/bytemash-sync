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
            console.log('[Quote Request] Variation ID from form:', variationId);
            if (variationId && variationId !== '') {
                return parseInt(variationId);
            }
        }
        console.log('[Quote Request] No variation ID found, returning 0');
        return 0;
    }
    
    /**
     * Get selected color
     */
    function getSelectedColor() {
        // Try color attribute select
        const colorSelect = $('select[name="attribute_color"]').val();
        if (colorSelect) {
            console.log('[Quote Request] Color from select:', colorSelect);
            return colorSelect;
        }
        
        // Try color swatch
        const selectedSwatch = $('.bytemash-color-swatch.selected');
        if (selectedSwatch.length > 0) {
            const color = selectedSwatch.attr('data-color-value') || selectedSwatch.attr('data-color-name') || '';
            console.log('[Quote Request] Color from swatch:', color);
            return color;
        }
        
        console.log('[Quote Request] No color selected');
        return '';
    }
    
    /**
     * Get selected size
     */
    function getSelectedSize() {
        // Try size attribute select
        const sizeSelect = $('select[name="attribute_size"]').val();
        if (sizeSelect) {
            console.log('[Quote Request] Size from select:', sizeSelect);
            return sizeSelect;
        }
        
        // Try size button
        const selectedButton = $('.bytemash-size-button.selected');
        if (selectedButton.length > 0) {
            const size = selectedButton.attr('data-size-value') || selectedButton.text() || '';
            console.log('[Quote Request] Size from button:', size);
            return size;
        }
        
        console.log('[Quote Request] No size selected');
        return '';
    }
    
    /**
     * Get selected branding options
     */
    function getSelectedBrandings() {
        const brandings = {};
        
        // Get all checked branding checkboxes
        const checkedBrandings = $('input[name^="bytemash_brandings["]:checked');
        console.log('[Quote Request] Found branding checkboxes:', checkedBrandings.length);
        
        checkedBrandings.each(function() {
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
        
        console.log('[Quote Request] Selected brandings:', brandings);
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
            const finalQty = qty > 0 ? qty : 1;
            console.log('[Quote Request] Quantity found:', finalQty);
            return finalQty;
        }
        console.log('[Quote Request] No quantity input found, defaulting to 1');
        return 1;
    }
    
    /**
     * Handle quote request button click - use event delegation to work with dynamically added buttons
     */
    $(document).on('click', '#bytemash-request-quote-btn, #bytemash-request-quote-btn-fallback, #bytemash-request-quote-btn-wrapper, #bytemash-request-quote-btn-final', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        console.log('[Quote Request] Button clicked');
        
        const $button = $(this);
        console.log('[Quote Request] Button element:', $button);
        
        // Find the message div - could be in different containers
        let $message = $('#bytemash-quote-request-message');
        if ($message.length === 0) {
            $message = $button.siblings('[id*="quote-request-message"]').first();
        }
        if ($message.length === 0) {
            $message = $button.closest('div').find('[id*="quote-request-message"]').first();
        }
        if ($message.length === 0) {
            // Create message div if it doesn't exist
            $message = $('<div id="bytemash-quote-request-message" style="margin-top: 10px; display: none;"></div>');
            $button.after($message);
            console.log('[Quote Request] Created message div');
        }
        
        // Get product data
        const productId = bytemashQuoteRequest.product_id;
        const variationId = getSelectedVariationId();
        const quantity = getQuantity();
        const selectedColor = getSelectedColor();
        const selectedSize = getSelectedSize();
        const brandings = getSelectedBrandings();
        
        console.log('[Quote Request] Product data:', {
            productId: productId,
            variationId: variationId,
            quantity: quantity,
            selectedColor: selectedColor,
            selectedSize: selectedSize,
            brandings: brandings
        });
        
        // VALIDATION: Check if variation is required for variable products
        const $variationsForm = $('.variations_form');
        console.log('[Quote Request] Variations form found:', $variationsForm.length > 0);
        
        if ($variationsForm.length > 0) {
            // Check if product has variations
            const hasVariations = $variationsForm.find('table.variations').length > 0 || 
                                 $variationsForm.find('.bytemash-variations-container').length > 0 ||
                                 $variationsForm.find('select[name^="attribute_"]').length > 0;
            
            console.log('[Quote Request] Has variations:', hasVariations, 'Variation ID:', variationId);
            
            if (hasVariations && variationId === 0) {
                // Variation is required but not selected
                console.warn('[Quote Request] Validation failed: Variation required but not selected');
                $message
                    .removeClass('woocommerce-message')
                    .addClass('woocommerce-error')
                    .html('<p>' + bytemashQuoteRequest.strings.select_variation + '</p>')
                    .show();
                $button.prop('disabled', false);
                return;
            }
        }
        
        // VALIDATION: Check if branding is required (if product has branding options)
        const $brandingOptions = $('.bytemash-branding-options');
        if ($brandingOptions.length > 0) {
            const hasBrandingSelections = Object.keys(brandings).length > 0;
            console.log('[Quote Request] Branding options found:', hasBrandingSelections);
            // Note: Branding is optional, but we could add validation here if needed
        }
        
        // Disable button and show loading
        const originalText = $button.text();
        console.log('[Quote Request] Disabling button, original text:', originalText);
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
        
        console.log('[Quote Request] Submitting AJAX request:', {
            url: bytemashQuoteRequest.ajax_url,
            data: ajaxData
        });
        
        // Submit AJAX request
        $.ajax({
            url: bytemashQuoteRequest.ajax_url,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                console.log('[Quote Request] AJAX success response:', response);
                
                if (response.success) {
                    console.log('[Quote Request] Quote request successful!', response.data);
                    $message
                        .removeClass('woocommerce-error')
                        .addClass('woocommerce-message')
                        .html('<p>' + (response.data.message || bytemashQuoteRequest.strings.success) + '</p>')
                        .show();
                    
                    // Reset button
                    $button.prop('disabled', false).text(originalText);
                    
                    // Optionally reset form
                    $('.reset_variations').trigger('click');
                } else {
                    console.error('[Quote Request] Quote request failed:', response.data);
                    $message
                        .removeClass('woocommerce-message')
                        .addClass('woocommerce-error')
                        .html('<p>' + (response.data.message || bytemashQuoteRequest.strings.error) + '</p>')
                        .show();
                    
                    $button.prop('disabled', false).text(originalText);
                }
            },
            error: function(xhr, status, error) {
                let errorMessage = bytemashQuoteRequest.strings.error;
                
                // Try to get more detailed error from response
                if (xhr.responseJSON && xhr.responseJSON.data) {
                    if (xhr.responseJSON.data.message) {
                        errorMessage = xhr.responseJSON.data.message;
                    } else if (xhr.responseJSON.data.debug) {
                        // Show debug message in development
                        errorMessage = xhr.responseJSON.data.debug;
                    }
                }
                
                $message
                    .removeClass('woocommerce-message')
                    .addClass('woocommerce-error')
                    .html('<p>' + errorMessage + '</p>')
                    .show();
                
                $button.prop('disabled', false).text(originalText);
                
                console.error('Quote request error:', {
                    status: status,
                    error: error,
                    response: xhr.responseJSON,
                    statusText: xhr.statusText,
                    statusCode: xhr.status
                });
            }
        });
    });
    
    // Consolidate multiple buttons into one - AGGRESSIVE
    function consolidateQuoteButtons() {
        const $buttons = $('#bytemash-request-quote-btn, #bytemash-request-quote-btn-fallback, #bytemash-request-quote-btn-wrapper, #bytemash-request-quote-btn-final');
        
        if ($buttons.length <= 1) {
            return; // Only one or no buttons, nothing to consolidate
        }
        
        // Find the first visible button, or the first one if none are visible
        let $mainBtn = null;
        $buttons.each(function() {
            const $btn = $(this);
            const isVisible = $btn.is(':visible') && $btn.css('display') !== 'none' && $btn.css('visibility') !== 'hidden';
            if (isVisible) {
                $mainBtn = $btn;
                return false; // break
            }
        });
        
        if (!$mainBtn || $mainBtn.length === 0) {
            $mainBtn = $buttons.first();
        }
        
        if ($mainBtn && $mainBtn.length > 0) {
            // Rename to main ID
            $mainBtn.attr('id', 'bytemash-request-quote-btn');
            $mainBtn.show().css({display: 'block', visibility: 'visible', opacity: '1'});
            
            // Find or create message div
            let $msg = $('#bytemash-quote-request-message');
            if ($msg.length === 0) {
                $msg = $mainBtn.siblings('[id*="quote-request-message"]').first();
                if ($msg.length > 0) {
                    $msg.attr('id', 'bytemash-quote-request-message');
                } else {
                    $msg = $('<div id="bytemash-quote-request-message" style="margin-top: 10px; display: none;"></div>');
                    $mainBtn.after($msg);
                }
            }
            
            // AGGRESSIVE: Hide all other buttons and their containers
            $buttons.not($mainBtn).each(function() {
                $(this).closest('div').hide();
                $(this).hide();
            });
        }
    }
    
    // Consolidate buttons on page load and after delays
    $(document).ready(function() {
        consolidateQuoteButtons();
        setTimeout(consolidateQuoteButtons, 100);
        setTimeout(consolidateQuoteButtons, 300);
        setTimeout(consolidateQuoteButtons, 500);
        setTimeout(consolidateQuoteButtons, 1000);
    });
    
    // Also consolidate when variations change (WooCommerce events)
    $(document).on('found_variation', '.variations_form', consolidateQuoteButtons);
    $(document).on('reset_data', '.variations_form', consolidateQuoteButtons);
    $(document).on('woocommerce_variation_has_changed', '.variations_form', consolidateQuoteButtons);
});

