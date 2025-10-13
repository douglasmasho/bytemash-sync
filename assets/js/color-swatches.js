/**
 * Color Swatches for WooCommerce Variations
 * 
 * Replaces color dropdown with visual swatches
 */

jQuery(document).ready(function($) {
    'use strict';
    
    /**
     * Initialize color swatches for all products
     */
    function initColorSwatches() {
        // Find all color attribute selects
        $('.variations select[name="attribute_color"]').each(function() {
            const $select = $(this);
            const $row = $select.closest('.value');
            
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
     * Reinitialize on AJAX complete (for dynamic content)
     */
    $(document.body).on('woocommerce_update_variation_values', function() {
        initColorSwatches();
    });
    
    // Initial load
    initColorSwatches();
    
    // Also reinit on variation form found/reset
    $('.variations_form').on('woocommerce_variation_select_change', function() {
        initColorSwatches();
    });
    
    $('.reset_variations').on('click', function() {
        setTimeout(function() {
            $('.bytemash-color-swatches .bytemash-color-swatch').removeClass('selected');
            $('.bytemash-selected-color').html('');
        }, 100);
    });
});


