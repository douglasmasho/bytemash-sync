/**
 * Color Swatches and Size Buttons for WooCommerce Variations
 * 
 * Replaces color dropdown with visual swatches
 * Replaces size dropdown with visual buttons
 */

jQuery(document).ready(function($) {
    'use strict';
    
    /**
     * Restructure variation form from table to div layout
     */
    function restructureVariationForm() {
        // Check if already restructured
        if ($('.bytemash-variations-container').length > 0) {
            return;
        }
        
        // Find the variations table - try multiple selectors
        let $variationsTable = $('.variations table');
        if ($variationsTable.length === 0) {
            $variationsTable = $('.variations_form table');
        }
        if ($variationsTable.length === 0) {
            $variationsTable = $('table.variations');
        }
        if ($variationsTable.length === 0) {
            // Try to find table inside variations_form
            $variationsTable = $('.variations_form .variations table');
        }
        
        if ($variationsTable.length === 0) {
            return;
        }
        
        // Mark as processed to prevent double processing
        if ($variationsTable.hasClass('bytemash-restructuring')) {
            return;
        }
        $variationsTable.addClass('bytemash-restructuring');
        
        // Get parent container (usually .variations)
        const $parentContainer = $variationsTable.closest('.variations');
        if ($parentContainer.length === 0) {
            $parentContainer = $variationsTable.closest('.variations_form');
        }
        
        // Create new container div
        const $newContainer = $('<div class="bytemash-variations-container"></div>');
        
        // Process each row (attribute)
        $variationsTable.find('tr').each(function() {
            const $row = $(this);
            const $label = $row.find('.label');
            const $value = $row.find('.value');
            
            if ($label.length === 0 || $value.length === 0) {
                return;
            }
            
            const labelText = $label.text().trim();
            
            // Create new div structure
            const $attrContainer = $('<div class="bytemash-variation-attribute"></div>');
            const $attrLabel = $('<div class="bytemash-attribute-label"></div>').text(labelText + ':');
            const $attrValue = $('<div class="bytemash-attribute-value"></div>');
            
            // Move all content from .value to new container (including selects)
            $value.children().appendTo($attrValue);
            $value.contents().filter(function() {
                return this.nodeType === 3 && $.trim(this.textContent).length > 0;
            }).appendTo($attrValue);
            
            // Assemble
            $attrContainer.append($attrLabel);
            $attrContainer.append($attrValue);
            $newContainer.append($attrContainer);
        });
        
        // Replace table with new div structure
        if ($parentContainer.length > 0) {
            // If we found a parent, insert before and remove table
            $variationsTable.before($newContainer);
            $variationsTable.remove();
        } else {
            // Fallback: replace table directly
            $variationsTable.replaceWith($newContainer);
        }
        
        // Also handle reset button if it exists
        const $resetBtn = $('.reset_variations');
        if ($resetBtn.length > 0 && !$resetBtn.prev('.bytemash-variations-container').length) {
            $newContainer.after($resetBtn);
        }
    }
    
    /**
     * Initialize color swatches for all products
     */
    function initColorSwatches() {
        // First restructure the form if needed
        restructureVariationForm();
        
        // Find all color attribute selects
        $('.variations select[name="attribute_color"], .bytemash-variations-container select[name="attribute_color"]').each(function() {
            const $select = $(this);
            const $attrValue = $select.closest('.bytemash-attribute-value');
            const $attrContainer = $select.closest('.bytemash-variation-attribute');
            
            // Fallback to old structure if new one doesn't exist
            const $row = $attrValue.length ? $attrValue : $select.closest('.value');
            
            // Skip if already converted
            if ($row.find('.bytemash-color-swatches').length > 0) {
                return;
            }
            
            // Create swatch container
            const $swatchContainer = $('<div class="bytemash-color-swatches"></div>');
            
            // Get all color options
            const options = $select.find('option');
            
            options.each(function() {
                const $option = $(this);
                const colorValue = $option.val();
                const colorName = $option.text();
                
                // Skip empty option (Choose an option)
                if (!colorValue || colorValue === '') {
                    return;
                }
                
                // Get swatch data from product meta
                const swatchData = getSwatchData(colorValue);
                
                if (!swatchData) {
                    console.warn('No swatch data found for color:', colorValue);
                    return;
                }
                
                // Create swatch element
                const $swatch = $('<div class="bytemash-color-swatch"></div>');
                $swatch.attr('data-color-value', colorValue);
                $swatch.attr('data-color-name', swatchData.name);
                $swatch.attr('data-color-code', swatchData.code);
                $swatch.css({
                    'backgroundColor': swatchData.hexValue,
                    '--tick-color': swatchData.tickColour || '#fff'
                });
                
                // Handle swatch click
                $swatch.on('click', function() {
                    if ($(this).hasClass('disabled')) {
                        return;
                    }
                    
                    // Update select value
                    $select.val(colorValue).trigger('change');
                    
                    // Update visual state
                    $swatchContainer.find('.bytemash-color-swatch').removeClass('selected');
                    $(this).addClass('selected');
                    
                    // Update selected color display
                    updateSelectedColorDisplay($row, swatchData);
                });
                
                $swatchContainer.append($swatch);
            });
            
            // Insert swatches before the select
            $select.before($swatchContainer);
            
            // Add selected color display
            const $selectedDisplay = $('<div class="bytemash-selected-color"></div>');
            $swatchContainer.after($selectedDisplay);
            
            // Select the first swatch if a value is already selected
            const currentValue = $select.val();
            if (currentValue) {
                $swatchContainer.find(`[data-color-value="${currentValue}"]`).addClass('selected');
                const swatchData = getSwatchData(currentValue);
                if (swatchData) {
                    updateSelectedColorDisplay($row, swatchData);
                }
            }
            
            // Listen for variation changes to update available swatches
            $select.on('change', function() {
                updateSwatchAvailability($swatchContainer, $select);
            });
            
            // Initial availability update
            updateSwatchAvailability($swatchContainer, $select);
        });
    }
    
    /**
     * Get swatch data from global variable (set by PHP)
     */
    function getSwatchData(colorValue) {
        // colorValue might be the color name like "Navy" or "Red"
        // We need to match it to the swatch code
        
        if (typeof bytemashColorSwatches === 'undefined') {
            console.error('Color swatches data not loaded');
            return null;
        }
        
        // Try to find matching swatch by name (case-insensitive)
        const colorKey = colorValue.toLowerCase();
        
        for (const code in bytemashColorSwatches) {
            const swatch = bytemashColorSwatches[code];
            if (swatch.name.toLowerCase() === colorKey) {
                return swatch;
            }
        }
        
        // If no exact match, try partial match
        for (const code in bytemashColorSwatches) {
            const swatch = bytemashColorSwatches[code];
            if (swatch.name.toLowerCase().includes(colorKey) || 
                colorKey.includes(swatch.name.toLowerCase())) {
                return swatch;
            }
        }
        
        console.warn('No matching swatch found for:', colorValue);
        return null;
    }
    
    /**
     * Update which swatches are available/disabled based on other selections
     */
    function updateSwatchAvailability($swatchContainer, $select) {
        const availableOptions = $select.find('option:not(:disabled)').map(function() {
            return $(this).val();
        }).get();
        
        $swatchContainer.find('.bytemash-color-swatch').each(function() {
            const colorValue = $(this).attr('data-color-value');
            
            if (availableOptions.includes(colorValue)) {
                $(this).removeClass('disabled');
            } else {
                $(this).addClass('disabled');
            }
        });
    }
    
    /**
     * Update the selected color display text
     */
    function updateSelectedColorDisplay($row, swatchData) {
        const $display = $row.find('.bytemash-selected-color');
        $display.html('Selected: <strong>' + swatchData.name + '</strong>');
    }
    
    /**
     * Initialize size buttons for all products
     */
    function initSizeButtons() {
        // Find all size attribute selects
        $('.variations select[name="attribute_size"], .bytemash-variations-container select[name="attribute_size"]').each(function() {
            const $select = $(this);
            const $attrValue = $select.closest('.bytemash-attribute-value');
            const $attrContainer = $select.closest('.bytemash-variation-attribute');
            
            // Fallback to old structure if new one doesn't exist
            const $row = $attrValue.length ? $attrValue : $select.closest('.value');
            
            // Skip if already converted
            if ($row.find('.bytemash-size-buttons').length > 0) {
                return;
            }
            
            // Create button container
            const $buttonContainer = $('<div class="bytemash-size-buttons"></div>');
            
            // Get all size options
            const options = $select.find('option');
            
            options.each(function() {
                const $option = $(this);
                const sizeValue = $option.val();
                const sizeName = $option.text();
                
                // Skip empty option (Choose an option)
                if (!sizeValue || sizeValue === '') {
                    return;
                }
                
                // Create size button element
                const $button = $('<button type="button" class="bytemash-size-button"></button>');
                $button.attr('data-size-value', sizeValue);
                $button.text(sizeName);
                
                // Handle button click
                $button.on('click', function() {
                    if ($(this).hasClass('disabled')) {
                        return;
                    }
                    
                    // Update select value
                    $select.val(sizeValue).trigger('change');
                    
                    // Update visual state - only one can be selected
                    $buttonContainer.find('.bytemash-size-button').removeClass('selected');
                    $(this).addClass('selected');
                    
                    // Update selected size display
                    updateSelectedSizeDisplay($row, sizeName);
                });
                
                $buttonContainer.append($button);
            });
            
            // Insert buttons before the select
            $select.before($buttonContainer);
            
            // Add selected size display
            const $selectedDisplay = $('<div class="bytemash-selected-size"></div>');
            $buttonContainer.after($selectedDisplay);
            
            // Select the first button if a value is already selected
            const currentValue = $select.val();
            if (currentValue) {
                $buttonContainer.find(`[data-size-value="${currentValue}"]`).addClass('selected');
                const currentOption = $select.find(`option[value="${currentValue}"]`);
                if (currentOption.length) {
                    updateSelectedSizeDisplay($row, currentOption.text());
                }
            }
            
            // Listen for variation changes to update available buttons
            $select.on('change', function() {
                updateSizeButtonAvailability($buttonContainer, $select);
            });
            
            // Initial availability update
            updateSizeButtonAvailability($buttonContainer, $select);
        });
    }
    
    /**
     * Update which size buttons are available/disabled based on other selections
     */
    function updateSizeButtonAvailability($buttonContainer, $select) {
        const availableOptions = $select.find('option:not(:disabled)').map(function() {
            return $(this).val();
        }).get();
        
        $buttonContainer.find('.bytemash-size-button').each(function() {
            const sizeValue = $(this).attr('data-size-value');
            
            if (availableOptions.includes(sizeValue)) {
                $(this).removeClass('disabled');
            } else {
                $(this).addClass('disabled');
            }
        });
    }
    
    /**
     * Update the selected size display text
     */
    function updateSelectedSizeDisplay($row, sizeName) {
        const $display = $row.find('.bytemash-selected-size');
        $display.html('Selected: <strong>' + sizeName + '</strong>');
    }
    
    /**
     * Reinitialize on AJAX complete (for dynamic content)
     */
    $(document.body).on('woocommerce_update_variation_values', function() {
        // Small delay to ensure DOM is ready
        setTimeout(function() {
            restructureVariationForm();
            initColorSwatches();
            initSizeButtons();
        }, 50);
    });
    
    // Initial load - wait for DOM ready
    $(document).ready(function() {
        // Small delay to ensure WooCommerce has rendered the form
        setTimeout(function() {
            restructureVariationForm();
            initColorSwatches();
            initSizeButtons();
        }, 100);
    });
    
    // Also reinit on variation form found/reset
    $(document).on('woocommerce_variation_select_change', '.variations_form', function() {
        setTimeout(function() {
            restructureVariationForm();
            initColorSwatches();
            initSizeButtons();
        }, 50);
    });
    
    // Reinit when variation form is loaded (for AJAX-loaded content)
    $(document).on('found_variation', function() {
        setTimeout(function() {
            restructureVariationForm();
            initColorSwatches();
            initSizeButtons();
        }, 50);
    });
    
    // Watch for variations table being added to DOM using MutationObserver
    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver(function(mutations) {
            let shouldRestructure = false;
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1) { // Element node
                        const $node = $(node);
                        if ($node.is('.variations table') || 
                            $node.find('.variations table').length > 0 ||
                            $node.is('.variations_form') ||
                            $node.find('.variations_form').length > 0) {
                            shouldRestructure = true;
                        }
                    }
                });
            });
            
            if (shouldRestructure) {
                setTimeout(function() {
                    restructureVariationForm();
                    initColorSwatches();
                    initSizeButtons();
                }, 100);
            }
        });
        
        // Start observing
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
    
    // Handle reset button
    $('.reset_variations').on('click', function() {
        setTimeout(function() {
            $('.bytemash-color-swatches .bytemash-color-swatch').removeClass('selected');
            $('.bytemash-selected-color').html('');
            $('.bytemash-size-buttons .bytemash-size-button').removeClass('selected');
            $('.bytemash-selected-size').html('');
        }, 100);
    });
});


