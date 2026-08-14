/**
 * CFP.DEV Shortcodes — Front-end
 *
 * Light/dark theme switching. The stored preference is applied before first
 * paint by the inline script each shortcode emits; this file only handles the
 * footer toggle, so it needs no library and no DOM-ready wait.
 */
'use strict';

(function () {
	var STORAGE_KEY = 'cfp-theme';

	function applyTheme(theme) {
		var root = document.documentElement;
		root.className = root.className.replace(/cfp-theme:\w+/g, 'cfp-theme:' + theme);

		// Keep the toggle's accessible state in sync with what is displayed.
		var buttons = document.querySelectorAll('.cfp-theme [data-theme-key]');
		Array.prototype.forEach.call(buttons, function (button) {
			button.setAttribute('aria-pressed', button.getAttribute('data-theme-key') === theme ? 'true' : 'false');
		});
	}

	function storeTheme(theme) {
		try {
			window.localStorage.setItem(STORAGE_KEY, theme);
			return true;
		} catch (error) {
			// Storage is unavailable (private mode, blocked cookies). The theme
			// still applies to this page view, it just will not be remembered.
			return false;
		}
	}

	document.addEventListener('click', function (event) {
		var target = event.target;
		if (!(target instanceof Element)) {
			return;
		}

		var toggle = target.closest('.cfp-theme [data-theme-key]');
		if (!toggle) {
			return;
		}

		var theme = toggle.getAttribute('data-theme-key');
		if ('light' !== theme && 'dark' !== theme) {
			return;
		}

		applyTheme(theme);
		storeTheme(theme);
	});

	// The inline head script applies the stored preference to the root element
	// before first paint, but the toggle is rendered later — reflect the stored
	// choice on the buttons once the DOM is available.
	document.addEventListener('DOMContentLoaded', function () {
		var saved = null;
		try {
			saved = window.localStorage.getItem(STORAGE_KEY);
		} catch (error) {
			saved = null;
		}
		if ('light' === saved || 'dark' === saved) {
			applyTheme(saved);
		}
	});
}());
