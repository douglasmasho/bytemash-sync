/* ==========================================================================
   BrandFlow Mega Menu Interaction
   ========================================================================== */

   jQuery(document).ready(function($) {
    // Mobile navigation toggle
    $('.bf-mobile-toggle').on('click', function(e) {
        e.preventDefault();
        $(this).toggleClass('active');
        $(this).siblings('.brandflow-mega-menu').slideToggle(300);
    });

    function alignDropdowns() {
        if ($(window).width() >= 992) {
            var windowWidth = $(window).width();
            $('.bf-menu-item').each(function() {
                var $dropdown = $(this).find('.bf-mega-dropdown');
                if ($dropdown.length) {
                    var itemRect = this.getBoundingClientRect();
                    // If the item is on the right half of the screen, anchor to the right
                    if (itemRect.left > (windowWidth / 2)) {
                        $dropdown.css({ 'left': 'auto', 'right': '0' });
                    } else {
                        // Otherwise anchor to the left
                        $dropdown.css({ 'left': '0', 'right': 'auto' });
                    }
                }
            });
        }
    }

    // Run positioning proactively to prevent hidden dropdowns from triggering document X-scrolling
    alignDropdowns();
    $(window).on('resize', alignDropdowns);
    
    // Also run on mouseenter just in case layout shifted asynchronously
    $('.bf-menu-item').on('mouseenter', alignDropdowns);

    // Mobile and Accordion submenu toggle
    $('.bf-toggle-btn').on('click', function(e) {
        if ($(window).width() < 992 || $(this).closest('.bf-accordion-mode').length > 0) {
            e.preventDefault();
            e.stopPropagation();
            var $parent = $(this).closest('.bf-menu-item');
            
            // Toggle open class for arrow rotation
            $parent.toggleClass('open');
            
            // Toggle submenu visibility
            $parent.find('.bf-mega-dropdown').first().slideToggle(300);
            
            // Close other open submenus
            $parent.siblings('.bf-menu-item.open').removeClass('open').find('.bf-mega-dropdown').slideUp(300);
        }
    });

    // Handle window resize to reset mobile states
    $(window).on('resize', function() {
        if ($(window).width() >= 992) {
            $('.brandflow-mega-menu').css('display', '');
            $('.bf-mega-dropdown').css('display', '');
            $('.bf-menu-item').removeClass('open');
            $('.bf-mobile-toggle').removeClass('active');
        }
    });
});
