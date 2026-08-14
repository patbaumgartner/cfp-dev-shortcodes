/* global cfp_dev_ajax */

/**
 * CFP.DEV Cache Management — Admin UI
 *
 * Submits the per-item "Delete Cache" forms on the settings page via AJAX
 * (action: cfp_dev_delete_cache) and removes the table row on success,
 * so the page does not reload for every deletion.
 *
 * Its strings arrive translated from PHP; see cfp_dev_enqueue_admin_scripts().
 */
jQuery(document).ready(function ($) {
	var i18n = cfp_dev_ajax.i18n || {};

	function format(template, value) {
		return String(template).replace('%s', value);
	}

	$('.delete-cache-form').on('submit', function (e) {
		e.preventDefault();
		var $form = $(this);
		var $button = $form.find('.delete-cache-button');

		// An <input type="submit"> shows its value, not its text content, so
		// .text() set a label nobody could see — including the errors below.
		$button.prop('disabled', true).val(i18n.deleting);

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
					return;
				}
				var message = (response.data && response.data.message) ? response.data.message : i18n.unknownError;
				$button.prop('disabled', false).val(format(i18n.errorWith, message));
			},
			error: function () {
				$button.prop('disabled', false).val(i18n.requestFailed);
			}
		});
	});
});
