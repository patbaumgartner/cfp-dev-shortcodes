/* global cfp_dev_offline_ajax */

/**
 * CFP.DEV Offline Mode — Admin UI
 *
 * Handles:
 *   - Polling the crawl progress endpoint while a crawl is running / pending.
 *   - Updating the #cfp-crawl-status box in real time.
 *   - Wiring the "Re-crawl Now" button.
 *   - Reloading the page when a crawl completes so the checkbox reflects the new state.
 */
(function ($) {
    'use strict';

    var poller = null;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function escHtml(str) {
        return $('<div>').text(String(str)).html();
    }

    function updateStatusBox(state) {
        var $box = $('#cfp-crawl-status');
        if (!$box.length) {
            return;
        }

        var html = '';
        var status = state.status || 'idle';

        if ('running' === status || 'pending' === status) {
            html += '<p>Status: <strong>' + escHtml('running' === status ? 'Running' : 'Starting\u2026') + '</strong>';
            if (state.step_label) {
                html += ' &mdash; ' + escHtml(state.step_label);
            }
            html += '</p>';

            if (state.items_total > 0) {
                var pct = Math.round((state.items_done / state.items_total) * 100);
                html += '<progress value="' + escHtml(state.items_done) + '" max="' + escHtml(state.items_total) + '"></progress> ';
                html += '<span>' + escHtml(pct + '% (' + state.items_done + '\u202f/\u202f' + state.items_total + ')') + '</span>';
            }

            if (state.errors > 0) {
                html += '<p style="color:orange;">' + escHtml(state.errors + ' error(s) so far') + '</p>';
            }

        } else if ('done' === status) {
            html += '<p>Status: <strong>Complete</strong></p>';
            if (state.snapshot_name) {
                html += '<p>Active snapshot: <code>' + escHtml(state.snapshot_name) + '</code></p>';
            }
            if (state.finished_at) {
                html += '<p>Finished: ' + escHtml(new Date(state.finished_at * 1000).toLocaleString()) + '</p>';
            }
            if (state.errors > 0) {
                html += '<p style="color:orange;">Warnings: ' + escHtml(state.errors) + ' item(s) had errors (see manifest.json).</p>';
            }

        } else if ('error' === status) {
            html += '<p style="color:red;">Status: <strong>Error</strong>';
            if (state.step_label) {
                html += ' &mdash; ' + escHtml(state.step_label);
            }
            html += '</p>';

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
            if (!window.confirm('Start a new crawl? This will fetch all API data and images from the live API and create a new snapshot.')) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true).text('Starting\u2026');

            $.post(
                cfp_dev_offline_ajax.ajaxurl,
                {
                    action: 'cfp_dev_start_crawl_ajax',
                    nonce: cfp_dev_offline_ajax.nonce,
                },
                function (response) {
                    $btn.prop('disabled', false).text('Re-crawl Now');

                    if (response.success) {
                        startPolling();
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : 'Unknown error.';
                        window.alert('Failed to start crawl: ' + msg);
                    }
                }
            ).fail(function () {
                $btn.prop('disabled', false).text('Re-crawl Now');
                window.alert('Request failed. Please try again.');
            });
        });
    });

}(jQuery));
