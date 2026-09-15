jQuery(document).ready(function($) {
    'use strict';

    // Global Icon Update Logic (runs on any page where assets are enqueued)
    function updateQuoteCartIconCount(cart) {
        if (!cart) {
            const cartStr = localStorage.getItem('bytemash_quote_cart');
            try {
                cart = cartStr ? JSON.parse(cartStr) : [];
            } catch (e) {
                cart = [];
            }
        }
        
        const count = Array.isArray(cart) ? cart.length : 0;
        $('.bytemash-quote-cart-count').text(count);
        
        // Optionally hide if zero, but user usually wants to see 0
        if (count > 0) {
            $('.bytemash-quote-cart-count').addClass('has-items');
        } else {
            $('.bytemash-quote-cart-count').removeClass('has-items');
        }
    }

    // Initial count update
    updateQuoteCartIconCount();

    // Listen for updates from other scripts (like single product page)
    $(document.body).on('bytemash_quote_cart_updated', function(e, cart) {
        updateQuoteCartIconCount(cart);
    });

    // Cart Page Logic (only runs on the actual cart page)
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

    // Handle Quantity Change (Direct Input)
    $(document).on('change', '.bytemash-cart-qty', function() {
        const id = $(this).data('id');
        const newQty = parseInt($(this).val());
        if (newQty < 1) {
            $(this).val(1);
            return;
        }

        updateItemQuantity(id, newQty);
    });

    // Handle Quantity Plus/Minus Buttons
    $(document).on('click', '.bytemash-qty-btn', function() {
        const id = $(this).data('id');
        const $input = $(this).siblings('.bytemash-cart-qty');
        let currentQty = parseInt($input.val()) || 1;
        
        if ($(this).hasClass('bytemash-qty-plus')) {
            currentQty++;
        } else if ($(this).hasClass('bytemash-qty-minus')) {
            currentQty--;
            if (currentQty < 1) currentQty = 1;
        }
        
        $input.val(currentQty);
        updateItemQuantity(id, currentQty);
    });

    function updateItemQuantity(id, newQty) {
        const itemIndex = quoteCart.findIndex(i => i.id === id);
        if (itemIndex > -1) {
            quoteCart[itemIndex].quantity = newQty;
            localStorage.setItem('bytemash_quote_cart', JSON.stringify(quoteCart));
        }
    }

    // Handle Master Branding Dropdown Changes in Cart
    $(document).on('change', '.bytemash-cart-master-branding-select', function() {
        const id = $(this).data('id');
        const $card = $(this).closest('.bytemash-quote-cart-card');
        const itemIndex = quoteCart.findIndex(i => i.id === id);
        
        if (itemIndex > -1) {
            const newBrandings = {};
            $card.find('.bytemash-cart-master-branding-select').each(function() {
                const val = $(this).val();
                if (val) {
                    const parts = val.split('|');
                    if (parts.length === 2) {
                        const pos = parts[0];
                        const code = parts[1];
                        if (!newBrandings[pos]) newBrandings[pos] = [];
                        if (!newBrandings[pos].includes(code)) {
                            newBrandings[pos].push(code);
                        }
                    }
                }
            });
            
            quoteCart[itemIndex].brandings = newBrandings;
            localStorage.setItem('bytemash_quote_cart', JSON.stringify(quoteCart));
            
            // Re-check for merges
            if (checkForMerges(itemIndex)) {
                loadCart();
            }
        }
    });

    // Branding Repeater in Cart
    $(document).on('click', '.bytemash-cart-add-branding', function() {
        const id = $(this).data('id');
        const $editDiv = $(this).closest('.bytemash-cart-item-brandings-edit');
        const optionsHtml = $editDiv.data('options');
        const $rows = $editDiv.find('.bytemash-cart-branding-rows');
        
        const newRow = $('<div class="bytemash-cart-branding-row">' +
            '<select class="bytemash-cart-master-branding-select" data-id="' + id + '">' +
            optionsHtml +
            '</select>' +
            '<button type="button" class="bytemash-cart-remove-branding">&times;</button>' +
            '</div>');
            
        $rows.append(newRow);
        $rows.find('.bytemash-cart-remove-branding').show();
    });

    $(document).on('click', '.bytemash-cart-remove-branding', function() {
        const $row = $(this).closest('.bytemash-cart-branding-row');
        const $editDiv = $(this).closest('.bytemash-cart-item-brandings-edit');
        const $select = $row.find('select');
        
        $row.remove();
        
        // Trigger change to update localStorage if it had a value
        if ($select.val()) {
            $editDiv.find('.bytemash-cart-master-branding-select').first().trigger('change');
        }

        // Hide remove if only 1 left
        const $remaining = $editDiv.find('.bytemash-cart-branding-row');
        if ($remaining.length === 1) {
            $remaining.find('.bytemash-cart-remove-branding').hide();
        }
    });

    // Handle Item Removal
    $(document).on('click', '.bytemash-remove-item', function(e) {
        e.preventDefault();
        const id = $(this).data('id');
        
        quoteCart = quoteCart.filter(i => i.id !== id);
        localStorage.setItem('bytemash_quote_cart', JSON.stringify(quoteCart));
        
        // Remove row/card
        $(this).closest('.bytemash-quote-cart-card, tr').fadeOut(300, function() {
            $(this).remove();
            if (quoteCart.length === 0) {
                showEmptyCart();
            }
        });
    });

    // Handle Variation Attribute Changes in Cart
    $(document).on('change', '.bytemash-cart-attr-select', function() {
        const id = $(this).data('id');
        const $card = $(this).closest('.bytemash-quote-cart-card');
        const variations = $card.data('variations') || [];
        
        // Gather all current attributes for this card
        const selectedAttrs = {};
        $card.find('.bytemash-cart-attr-select').each(function() {
            selectedAttrs[$(this).data('attribute')] = $(this).val();
        });

        // Find matching variation ID
        let matchingVariationId = 0;
        if (variations.length > 0) {
            variations.forEach(function(v) {
                let match = true;
                for (let attr in v.attributes) {
                    let vVal = (v.attributes[attr] || '').toLowerCase();
                    
                    // Normalize attribute key to handle 'attribute_color' vs 'attribute_pa_color'
                    let cleanKey = attr.replace('attribute_pa_', '').replace('attribute_', '');
                    
                    // Find matching selected value
                    let selectedVal = '';
                    for (let sKey in selectedAttrs) {
                        let cleanSKey = sKey.replace('attribute_pa_', '').replace('attribute_', '');
                        if (cleanSKey === cleanKey) {
                            selectedVal = (selectedAttrs[sKey] || '').toLowerCase();
                            break;
                        }
                    }

                    // WooCommerce variation attributes map can have empty values for "any"
                    if (vVal !== "" && vVal !== selectedVal) {
                        match = false;
                        break;
                    }
                }
                if (match) {
                    matchingVariationId = v.variation_id;
                }
            });
        }

        const itemIndex = quoteCart.findIndex(i => i.id === id);
        if (itemIndex > -1) {
            quoteCart[itemIndex].variation_id = matchingVariationId;
            
            // Update semantic labels
            const colorAttr = selectedAttrs['attribute_pa_color'] || selectedAttrs['attribute_color'];
            const sizeAttr = selectedAttrs['attribute_pa_size'] || selectedAttrs['attribute_size'];
            if (colorAttr) quoteCart[itemIndex].color = colorAttr;
            if (sizeAttr) quoteCart[itemIndex].size = sizeAttr;
            
            localStorage.setItem('bytemash_quote_cart', JSON.stringify(quoteCart));
            
            // Real-time UI updates
            if (matchingVariationId > 0) {
                const varData = variations.find(v => v.variation_id === matchingVariationId);
                if (varData) {
                    // Update SKU
                    if (varData.sku) {
                        $card.find('.bytemash-cart-sku').show().find('.sku-val').text(varData.sku);
                    }
                    // Update variation image
                    if (varData.image && varData.image.src) {
                        $card.find('.bytemash-variation-image').show().html('<img src="' + varData.image.src + '" alt="" style="border-radius:8px; object-fit:cover; width:100%; height:100%;">');
                    } else {
                        $card.find('.bytemash-variation-image').hide();
                    }
                }
            } else {
                $card.find('.bytemash-variation-image').hide();
            }

            // Check for merges
            if (checkForMerges(itemIndex)) {
                loadCart();
            }
        }
    });

    function checkForMerges(currentIndex) {
        const current = quoteCart[currentIndex];
        if (!current) return false;

        for (let i = 0; i < quoteCart.length; i++) {
            if (i === currentIndex) continue;
            
            const other = quoteCart[i];
            if (other.product_id === current.product_id && 
                other.variation_id === current.variation_id && 
                other.color === current.color && 
                other.size === current.size && 
                JSON.stringify(other.brandings) === JSON.stringify(current.brandings)) {
                
                // Merge into the other one
                other.quantity += current.quantity;
                quoteCart.splice(currentIndex, 1);
                localStorage.setItem('bytemash_quote_cart', JSON.stringify(quoteCart));
                return true;
            }
        }
        return false;
    }

    // Add File Row
    $('#bytemash-add-file-row').on('click', function() {
        $('#bytemash-file-uploads').append(
            '<div class="bytemash-file-upload-row">' +
                '<input type="text" name="file_labels[]" placeholder="Label (e.g. Front Logo)" class="input-text" style="width: 40%; display: inline-block;"> ' +
                '<input type="file" name="quote_files[]" class="input-text bytemash-quote-file-input" accept=".jpg,.jpeg,.png,.pdf,.ai,.eps,.svg" style="width: 40%; display: inline-block;">' +
                '<div class="bytemash-file-preview" style="display:inline-block; vertical-align:middle; margin-left:10px; width:40px; height:40px; border-radius:4px; overflow:hidden; border:1px solid #ddd; background:#f9f9f9; text-align:center;">' +
                    '<span style="display:block; line-height:40px; color:#aaa; font-size:12px;">No img</span>' +
                '</div>' +
            '</div>'
        );
    });

    // File input preview handler
    $(document).on('change', '.bytemash-quote-file-input', function() {
        const file = this.files[0];
        const $preview = $(this).siblings('.bytemash-file-preview');
        if (file) {
            if (file.type.startsWith('image/')) {
                const url = URL.createObjectURL(file);
                $preview.html('<img src="' + url + '" style="width:100%; height:100%; object-fit:cover;">');
            } else {
                $preview.html('<span style="display:block; line-height:40px; font-size:10px; color:#555; text-transform:uppercase;">' + file.name.split('.').pop() + '</span>');
            }
        } else {
            $preview.html('<span style="display:block; line-height:40px; color:#aaa; font-size:12px;">No img</span>');
        }
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
                    // Move message outside form before hiding form so it stays visible
                    $msg.detach().insertBefore($form);
                    
                    $msg.html(res.data.details_html || '<div class="woocommerce-message"><p>' + res.data.message + '</p></div>').show();
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
