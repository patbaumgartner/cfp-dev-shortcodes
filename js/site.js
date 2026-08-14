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

		var toggle = target.closest('.cfp-theme a[data-theme-key]');
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
}());
