jQuery(document).ready(function($) {
  $('.delete-cache-form').on('submit', function(e) {
    e.preventDefault();
    var $form = $(this);
    var $button = $form.find('.delete-cache-button');

    $button.prop('disabled', true).text('Deleting...');

    $.ajax({
      url: cfp_dev_ajax.ajaxurl,
      type: 'POST',
      data: {
        action: 'cfp_dev_delete_cache',
        nonce: cfp_dev_ajax.nonce, // Add this line
        delete_cache: $form.find('input[name="delete_cache"]').val(),
        cache_id: $form.find('input[name="cache_id"]').val()
      },
      success: function(response) {
        if (response.success) {
          $form.closest('tr').fadeOut();
        } else {
          $button.text('Error: ' + (response.data ? response.data.message : 'Unknown error'));
        }
      },
      error: function(jqXHR, textStatus, errorThrown) {
        console.error('AJAX error:', textStatus, errorThrown);
        $button.text('Error occurred');
      },
      complete: function() {
        $button.prop('disabled', false);
      }
    });
  });
});
