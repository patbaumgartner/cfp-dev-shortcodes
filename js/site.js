/**
 * CFP.DEV Shortcodes — Front-end
 *
 * Light/dark theme switching: applies the theme stored in localStorage on
 * load and updates the root cfp-theme:* class when a footer toggle is clicked.
 */
'use strict';

// jQuery(fn) already waits for DOM ready — no nested ready needed.
jQuery(function ($) {

	const savedTheme = localStorage.getItem('cfp-theme');
	if (savedTheme) {
		$('html').attr('class', function (i, c) {
			return c.replace(/cfp-theme:\w+/g, `cfp-theme:${savedTheme}`);
		});
	}

	$(document).on('click', '.cfp-theme a', function () {
		const themeKey = $(this).data('theme-key');

		$('html').attr('class', function (i, c) {
			return c.replace(/cfp-theme:\w+/g, `cfp-theme:${themeKey}`);
		});

		localStorage.setItem('cfp-theme', themeKey);
	});

});
