(function($){
  $(function(){
    var $trigger = $('#bytemash-brandings-modal-trigger');
    var $modal = $('#bytemash-brandings-modal');
    var $content = $('#bytemash-brandings-modal__content');

    if (!$trigger.length || !$modal.length) return;

    $trigger.on('click', 'button', function(){
      $modal.show();
      $content.html('<div class="bytemash-spinner"></div>');
      $.post(bytemashBrandingsModal.ajax_url, {
        action: 'bytemash_get_product_brandings',
        nonce: bytemashBrandingsModal.nonce,
        product_id: bytemashBrandingsModal.product_id
      }).done(function(resp){
        if (resp && resp.success && resp.data && resp.data.html){
          $content.html(resp.data.html);
        } else {
          $content.html('<p>Unable to load branding options.</p>');
        }
      }).fail(function(){
        $content.html('<p>Failed to load branding options.</p>');
      });
    });

    $modal.on('click', '.bytemash-stock-modal__close', function(){
      $modal.hide();
    });

    $(document).on('keyup', function(e){
      if (e.key === 'Escape') { $modal.hide(); }
    });
  });
})(jQuery);




