/**
 * Stock Details Modal Handler
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Click handler for stock badge
        $('.bytemash-stock-display.has-details').on('click', function() {
            const stockData = $(this).data('stock-details');
            openStockModal(stockData);
        });
        
        // Click handler for Check Stock button
        $('.bytemash-check-stock-btn').on('click', function() {
            console.log('Check Stock button clicked');
            const stockData = $(this).data('stock-details');
            console.log('Stock data:', stockData);
            openStockModal(stockData);
        });
        
        // Close modal handlers
        $('.bytemash-stock-modal-close, .bytemash-stock-modal-overlay').on('click', function() {
            closeStockModal();
        });
        
        // Close on ESC key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $('#bytemash-stock-modal').is(':visible')) {
                closeStockModal();
            }
        });
        
        /**
         * Open stock modal with data
         */
        function openStockModal(stockData) {
            if (!stockData) {
                return;
            }
            
            // Calculate total incoming stock
            let totalIncoming = 0;
            let incomingEta = 'To Be Confirmed';
            
            if (stockData.incoming && stockData.incoming.length > 0) {
                stockData.incoming.forEach(function(item) {
                    totalIncoming += parseInt(item.total) || 0;
                });
                
                // Get the earliest date
                const dates = stockData.incoming
                    .map(item => new Date(item.date))
                    .filter(date => !isNaN(date.getTime()))
                    .sort((a, b) => a - b);
                
                if (dates.length > 0) {
                    incomingEta = formatDate(dates[0]);
                }
            }
            
            // Populate summary
            $('#modal-total-stock').text(formatNumber(stockData.total));
            $('#modal-total-incoming').text(formatNumber(totalIncoming));
            
            // Populate table
            $('#modal-stock-on-hand').text(formatNumber(stockData.total));
            $('#modal-reserved-stock').text(formatNumber(stockData.reserved));
            $('#modal-incoming-stock').text(formatNumber(totalIncoming));
            $('#modal-incoming-eta').text(incomingEta);
            
            // Show modal
            $('#bytemash-stock-modal').fadeIn(200);
            $('body').addClass('bytemash-modal-open');
        }
        
        /**
         * Close stock modal
         */
        function closeStockModal() {
            $('#bytemash-stock-modal').fadeOut(200);
            $('body').removeClass('bytemash-modal-open');
        }
        
        /**
         * Format number with commas
         */
        function formatNumber(num) {
            if (num === null || num === undefined) {
                return '0';
            }
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
        
        /**
         * Format date to readable format
         */
        function formatDate(dateStr) {
            if (!dateStr) {
                return 'To Be Confirmed';
            }
            
            const date = new Date(dateStr);
            
            if (isNaN(date.getTime())) {
                return 'To Be Confirmed';
            }
            
            const options = { 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric' 
            };
            
            return date.toLocaleDateString(undefined, options);
        }
    });
})(jQuery);

