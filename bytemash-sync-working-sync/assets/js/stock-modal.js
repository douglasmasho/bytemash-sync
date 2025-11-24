(function($){
    function renderTable(data){
        if (!data || !data.rows) return '<p>No stock data available.</p>';
        var html = '';
        html += '<div class="bytemash-stock-summary">';
        html += '<strong>Total Stock on Hand:</strong> ' + (data.totals.stock||0);
        html += ' &nbsp; <strong>Total Incoming Stock:</strong> ' + (data.totals.incoming||0);
        html += '</div>';
        html += '<table class="bytemash-stock-table"><thead><tr>'+
                '<th>Colour / Size</th><th>Code</th><th>Stock on Hand</th><th>Reserved</th><th>Incoming</th><th>Incoming ETA</th>'+
                '</tr></thead><tbody>';
        data.rows.forEach(function(r){
            // Format ETA display - show empty if no incoming stock
            var etaDisplay = '';
            if (r.incoming && r.incoming > 0 && r.eta) {
                etaDisplay = r.eta;
            }
            
            html += '<tr>'+
                '<td>'+ (r.label||'') +'</td>'+
                '<td>'+ (r.sku||'') +'</td>'+
                '<td>'+ (r.stock||0) +'</td>'+
                '<td>'+ (r.reserved||0) +'</td>'+
                '<td>'+ (r.incoming||0) +'</td>'+
                '<td>'+ etaDisplay +'</td>'+
            '</tr>';
        });
        html += '</tbody></table>';
        html += '<p class="bytemash-stock-disclaimer">* Products shown in <span style="color:#d63638">RED</span> are discontinued and will not be repeated once stock is sold out.</p>';
        return html;
    }

    function openModal(){
        var $modal = $('#bytemash-stock-modal');
        $modal.show();
        var $content = $('#bytemash-stock-modal__content').html('<div class="bytemash-spinner"></div>');
        $.post(bytemashStockModal.ajax_url, {
            action: 'bytemash_get_product_stock_table',
            nonce: bytemashStockModal.nonce,
            product_id: bytemashStockModal.product_id
        }).done(function(res){
            if(res && res.success){
                $content.html(renderTable(res.data));
            } else {
                $content.html('<p>Failed to load stock information.</p>');
            }
        }).fail(function(){
            $content.html('<p>Failed to load stock information.</p>');
        });
    }

    function closeModal(){
        $('#bytemash-stock-modal').hide();
    }

    $(document).on('click', '#bytemash-stock-modal-trigger button', function(){
        openModal();
    });

    $(document).on('click', '#bytemash-stock-modal .bytemash-stock-modal__close', function(){
        closeModal();
    });

    $(document).on('click', '#bytemash-stock-modal', function(e){
        if (e.target.id === 'bytemash-stock-modal') {
            closeModal();
        }
    });
})(jQuery);


