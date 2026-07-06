'use strict';

// jQuery(fn) already waits for DOM ready — no nested ready needed.
jQuery(function ($) {

	// Load saved theme
	const savedTheme = localStorage.getItem('cfp-theme');
	if (savedTheme) {
		$('html').attr('class', function (i, c) {
			return c.replace(/cfp-theme:\w+/g, `cfp-theme:${savedTheme}`);
		});
	}

	// Click event to switch themes
	$(document).on('click', '.cfp-theme a', function () {
		const themeKey = $(this).data('theme-key');

		// Update HTML class attribute
		$('html').attr('class', function (i, c) {
			return c.replace(/cfp-theme:\w+/g, `cfp-theme:${themeKey}`);
		});

		// Save theme to local storage
		localStorage.setItem('cfp-theme', themeKey);
	});

});
