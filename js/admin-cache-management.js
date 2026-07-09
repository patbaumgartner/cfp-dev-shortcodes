/* global cfp_dev_ajax */

/**
 * CFP.DEV Cache Management — Admin UI
 *
 * Submits the per-item "Delete Cache" forms on the settings page via AJAX
 * (action: cfp_dev_delete_cache) and removes the table row on success,
 * so the page does not reload for every deletion.
 */
jQuery(document).ready(function ($) {
	$('.delete-cache-form').on('submit', function (e) {
		e.preventDefault();
		var $form = $(this);
		var $button = $form.find('.delete-cache-button');

		$button.prop('disabled', true).text('Deleting...');

		$.ajax({
			url: cfp_dev_ajax.ajaxurl,
			type: 'POST',
			data: {
				action: 'cfp_dev_delete_cache',
				nonce: cfp_dev_ajax.nonce,
				delete_cache: $form.find('input[name="delete_cache"]').val(),
				cache_id: $form.find('input[name="cache_id"]').val()
			},
			success: function (response) {
				if (response.success) {
					$form.closest('tr').fadeOut();
				} else {
					$button.text('Error: ' + (response.data ? response.data.message : 'Unknown error'));
				}
			},
			error: function (jqXHR, textStatus, errorThrown) {
				console.error('AJAX error:', textStatus, errorThrown);
				$button.text('Error occurred');
			},
			complete: function () {
				$button.prop('disabled', false);
			}
		});
	});
});
