/* global cfp_dev_offline_ajax */

/**
 * CFP.DEV Offline Mode — Admin UI
 *
 * Handles:
 *   - Polling the crawl progress endpoint while a crawl is running / pending.
 *   - Updating the #cfp-crawl-status box in real time.
 *   - Wiring the "Re-crawl Now" button.
 *   - Reloading the page when a crawl completes so the checkbox reflects the new state.
 *
 * This box is server-rendered first and then repainted here, so both sides
 * report the same status and use the same strings; see
 * cfp_dev_crawl_display_status() and cfp_dev_enqueue_admin_scripts().
 */
(function ($) {
	'use strict';

	var poller = null;
	var i18n = cfp_dev_offline_ajax.i18n || {};

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	function escHtml(str) {
		return $('<div>').text(String(str)).html();
	}

	/** Fills %s / %1$s-style placeholders in a translated string. */
	function format(template, values) {
		var text = String(template);
		values.forEach(function (value, index) {
			text = text.split('%' + (index + 1) + '$s').join(value);
		});
		return text.replace('%s', values[0]).split('%%').join('%');
	}

	function statusLine(label, detail, colour) {
		var style = colour ? ' style="color:' + colour + ';"' : '';
		var html = '<p' + style + '>' + escHtml(i18n.statusLabel) + ' <strong>' + escHtml(label) + '</strong>';
		if (detail) {
			html += ' &mdash; ' + escHtml(detail);
		}
		return html + '</p>';
	}

	function updateStatusBox(state) {
		var $box = $('#cfp-crawl-status');
		if (!$box.length) {
			return;
		}

		var html = '';
		var status = state.status || 'idle';

		if ('running' === status || 'pending' === status) {
			html += statusLine('running' === status ? i18n.running : i18n.pending, state.step_label);

			if (state.items_total > 0) {
				var pct = Math.round((state.items_done / state.items_total) * 100);
				html += '<progress value="' + escHtml(state.items_done) + '" max="' + escHtml(state.items_total) + '"></progress> ';
				html += '<span>' + escHtml(format(i18n.progress, [pct, state.items_done, state.items_total])) + '</span>';
			}

			if (state.errors > 0) {
				html += '<p style="color:orange;">' + escHtml(format(i18n.errorsSoFar, [state.errors])) + '</p>';
			}

		} else if ('done' === status) {
			html += statusLine(i18n.complete);
			if (state.snapshot_name) {
				html += '<p>' + escHtml(i18n.activeSnapshot) + ' <code>' + escHtml(state.snapshot_name) + '</code></p>';
			}
			if (state.finished_at) {
				html += '<p>' + escHtml(i18n.finished) + ' ' + escHtml(new Date(state.finished_at * 1000).toLocaleString()) + '</p>';
			}
			if (state.errors > 0) {
				html += '<p style="color:orange;">' + escHtml(format(i18n.warnings, [state.errors])) + '</p>';
			}

		} else if ('error' === status) {
			html += statusLine(i18n.error, state.step_label, 'red');

		} else if ('stopped' === status) {
			// Repainting this as "Running" is how the server's own message used
			// to be lost, a second after the page rendered.
			html += statusLine(i18n.stopped, i18n.stoppedHint, 'red');

		} else {
			// idle — keep server-rendered content
			return;
		}

		$box.html(html);
	}

	// -------------------------------------------------------------------------
	// Polling
	// -------------------------------------------------------------------------

	function stopPolling() {
		if (poller) {
			clearInterval(poller);
			poller = null;
		}
	}

	function pollOnce() {
		$.post(
			cfp_dev_offline_ajax.ajaxurl,
			{
				action: 'cfp_dev_crawl_progress',
				nonce: cfp_dev_offline_ajax.nonce,
			},
			function (response) {
				if (!response.success) {
					return;
				}
				var state = response.data;
				var status = state.status || 'idle';

				updateStatusBox(state);

				if ('running' !== status && 'pending' !== status) {
					stopPolling();
					if ('done' === status) {
						// Reload so the offline-mode checkbox reflects the activated state.
						setTimeout(function () {
							window.location.reload();
						}, 1200);
					}
				}
			}
		);
	}

	function startPolling() {
		stopPolling();
		pollOnce(); // immediate first tick
		poller = setInterval(pollOnce, 3000);
	}

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------

	$(document).ready(function () {
		// If a crawl was already running when the page loaded, start polling now.
		var initialStatus = cfp_dev_offline_ajax.initial_status || 'idle';
		if ('running' === initialStatus || 'pending' === initialStatus) {
			startPolling();
		}

		// Re-crawl Now button.
		$(document).on('click', '#cfp-recrawl-btn', function () {
			if (!window.confirm(i18n.confirmCrawl)) {
				return;
			}

			var $btn = $(this);
			$btn.prop('disabled', true).text(i18n.starting);

			$.post(
				cfp_dev_offline_ajax.ajaxurl,
				{
					action: 'cfp_dev_start_crawl_ajax',
					nonce: cfp_dev_offline_ajax.nonce,
				},
				function (response) {
					$btn.prop('disabled', false).text(i18n.recrawl);

					if (response.success) {
						startPolling();
					} else {
						var msg = (response.data && response.data.message) ? response.data.message : i18n.unknownError;
						window.alert(format(i18n.startFailed, [msg]));
					}
				}
			).fail(function () {
				$btn.prop('disabled', false).text(i18n.recrawl);
				window.alert(i18n.requestFailed);
			});
		});
	});

}(jQuery));
