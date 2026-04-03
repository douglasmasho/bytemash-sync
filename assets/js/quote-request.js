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
    function getSelectedAttributes() {
        const attrs = {};
        $('select[name^="attribute_"]').each(function() {
            const name = $(this).attr('name');
            const val = $(this).val();
            if (val) {
                attrs[name] = val;
            }
        });
        return attrs;
    }

    function getSelectedColor() {
        const attrs = getSelectedAttributes();
        for (let name in attrs) {
            if (name.toLowerCase().includes('color')) return attrs[name];
        }
        
        // Try color swatch as backup
        const selectedSwatch = $('.bytemash-color-swatch.selected');
        if (selectedSwatch.length > 0) {
            return selectedSwatch.attr('data-color-value') || selectedSwatch.attr('data-color-name') || '';
        }
        return '';
    }
    
    function getSelectedSize() {
        const attrs = getSelectedAttributes();
        for (let name in attrs) {
            if (name.toLowerCase().includes('size')) return attrs[name];
        }
        
        // Try size button as backup
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
        
        // Get all master branding dropdowns
        $('select.bytemash-master-branding-select').each(function() {
            const masterVal = $(this).val();
            if (masterVal) {
                // masterVal is "posCode|brandingCode"
                const parts = masterVal.split('|');
                if (parts.length === 2) {
                    const posCode = parts[0];
                    const brandingCode = parts[1];
                    
                    if (!brandings[posCode]) {
                        brandings[posCode] = [];
                    }
                    if (!brandings[posCode].includes(brandingCode)) {
                        brandings[posCode].push(brandingCode);
                    }
                }
            }
        });
        
        console.log('[Quote Request] Selected brandings:', brandings);
        return brandings;
    }
    
    /**
     * Get quantity
     */
    function getQuantity() {
        // Try quote-specific quantity first, then regular quantity input
        let $quantityInput = $('#bytemash-quote-quantity');
        
        if ($quantityInput.length === 0 || !$quantityInput.val()) {
            $quantityInput = $('input[name="quantity"]');
        }
        
        if ($quantityInput.length > 0) {
            const qty = parseInt($quantityInput.val());
            const finalQty = (qty && qty > 0) ? qty : 1;
            console.log('[Quote Request] Quantity found:', finalQty, 'from element:', $quantityInput.attr('id') || $quantityInput.attr('name'));
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
        
        // Clean up theme-injected cart link inside the button if AJAX cart triggers
        $button.find('.added_to_cart').remove();
        $button.removeClass('added loading');

        // Find the message div - always place strictly after the button
        let $message = $('#bytemash-quote-request-message');
        if ($message.length === 0) {
            $message = $button.siblings('[id*="quote-request-message"]').first();
        }
        if ($message.length === 0) {
            // Create message div if it doesn't exist
            $message = $('<div id="bytemash-quote-request-message" style="margin-top: 15px; display: none; width: 100%; clear: both;"></div>');
            $button.after($message);
            console.log('[Quote Request] Created message div after button');
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
        
        // Disable button to prevent double clicks (temporarily)
        const originalText = $button.text();
        console.log('[Quote Request] Disabling button, original text:', originalText);
        $button.prop('disabled', true).text(bytemashQuoteRequest.strings.requesting);
        $message.hide();
        
        // Instead of AJAX, save to localStorage
        try {
            // Get existing cart
            let quoteCart = [];
            const existingCart = localStorage.getItem('bytemash_quote_cart');
            if (existingCart) {
                quoteCart = JSON.parse(existingCart);
            }
            
            // Create cart item
            const cartItem = {
                id: 'item_' + new Date().getTime() + '_' + Math.random().toString(36).substr(2, 9),
                product_id: productId,
                variation_id: variationId,
                quantity: quantity,
                color: selectedColor,
                size: selectedSize,
                brandings: brandings,
                timestamp: new Date().getTime()
            };
            
            // Check if similar item exists (same product, variation, color, size, brandings)
            let itemUpdated = false;
            for (let i = 0; i < quoteCart.length; i++) {
                const item = quoteCart[i];
                if (item.product_id === productId && 
                    item.variation_id === variationId && 
                    item.color === selectedColor && 
                    item.size === selectedSize && 
                    JSON.stringify(item.brandings) === JSON.stringify(brandings)) {
                    
                    // Update quantity
                    quoteCart[i].quantity += quantity;
                    itemUpdated = true;
                    break;
                }
            }
            
            if (!itemUpdated) {
                quoteCart.push(cartItem);
            }
            
            // Save back to localStorage
            localStorage.setItem('bytemash_quote_cart', JSON.stringify(quoteCart));
            
            // Update UI
            console.log('[Quote Request] Added to quote cart', quoteCart);
            
            const cartUrl = bytemashQuoteRequest.cart_url || '/quote-cart'; // Will be set by PHP via settings ideally
            const successMsg = bytemashQuoteRequest.strings.added_to_cart || 'Added to quote cart!';
            const viewCartMsg = bytemashQuoteRequest.strings.view_cart || 'View Quote Cart';
            
            $message
                .removeClass('woocommerce-error')
                .addClass('woocommerce-message')
                .html(`<p style="display:flex; justify-content:space-between; align-items:center; margin:0;">
                    <span>${successMsg}</span>
                    <a href="${cartUrl}" class="button alt" style="margin-left: 10px;">${viewCartMsg}</a>
                </p>`)
                .show();
            
            // Reset button
            $button.prop('disabled', false).text(originalText);
            
            // Fire custom event so theme can update mini-cart if we create one later
            $(document.body).trigger('bytemash_quote_cart_updated', [quoteCart]);
            
        } catch (e) {
            console.error('[Quote Request] Failed to save to localStorage', e);
            $message
                .removeClass('woocommerce-message')
                .addClass('woocommerce-error')
                .html('<p>Failed to add to quote cart. Please ensure cookies/localStorage are enabled.</p>')
                .show();
            $button.prop('disabled', false).text(originalText);
        }
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

    // BRANDING REPEATER LOGIC
    $(document).on('click', '#bytemash-add-branding-option', function(e) {
        e.preventDefault();
        console.log('[Quote Request] Adding branding row');
        
        const $container = $('#bytemash-branding-repeater-container');
        const templateHtml = $('#bytemash-branding-row-template').html();
        
        if (!templateHtml) {
            console.error('[Quote Request] Branding row template not found');
            return;
        }
        
        const $row = $(templateHtml);
        $container.append($row);
        
        // Performance: Use slideDown for smooth entry
        $row.slideDown(200);
        
        // Show all remove buttons when multiple rows exist
        $('.bytemash-remove-branding').show();
        
        // Toggle 'initial-row' class if needed for styling
        updateBrandingRowStates();
    });

    $(document).on('click', '.bytemash-remove-branding', function(e) {
        e.preventDefault();
        const $row = $(this).closest('.bytemash-branding-row');
        
        $row.slideUp(200, function() {
            $(this).remove();
            updateBrandingRowStates();
        });
    });
    
    /**
     * Update branding row states (e.g. hiding remove button on single row)
     */
    function updateBrandingRowStates() {
        const $rows = $('.bytemash-branding-row');
        console.log('[Quote Request] Total branding rows:', $rows.length);
        
        if ($rows.length <= 1) {
            $('.bytemash-remove-branding').hide();
            $rows.addClass('initial-row');
        } else {
            $('.bytemash-remove-branding').show();
            // Optional: remove initial-row class from all but the first if desired
        }
    }
    
    // Run once on load to ensure correct state
    updateBrandingRowStates();
});

