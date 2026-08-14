<?php
/**
 * CFP.DEV shortcodes
 *
 * Shared markup fragments used by more than one shortcode.
 *
 * @package CFP.DEV
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Renders the shared page header block (title, optional subtitle, optional
 * search form) — the '.cfp-primary' element used by every list shortcode.
 *
 * @param string $title        Heading text; pass '' to render no heading.
 * @param string $subtitle     Optional sub-heading.
 * @param bool   $show_search  Whether to render the search form.
 */
function cfp_dev_page_header( string $title, string $subtitle = '', bool $show_search = true ): string {
	$content = '<div class="cfp-primary">';
	if ( '' !== $title ) {
		$content .= '<div class="cfp-name">' . esc_html( $title ) . '</div>';
	}
	if ( '' !== $subtitle ) {
		$content .= '<div class="cfp-company">' . esc_html( $subtitle ) . '</div>';
	}
	if ( $show_search ) {
		$content .= cfp_dev_search_form();
	}
	$content .= '</div>';
	return $content;
}

/**
 * Emits the inline script that swaps the cfp-* classes on the root element.
 * Shared by every shortcode (was duplicated six times).
 *
 * When the theme toggle is enabled the script also applies the visitor's
 * stored preference here, before first paint — applying it later from the
 * footer script made every page flash the default theme first.
 *
 * @param string $page  Page key, e.g. 'speaker', 'schedule', 'session', 'search'.
 * @param string $view  Optional view key, e.g. 'detail'.
 */
function cfp_dev_root_class_script( string $page, string $view = '' ): string {
	$theme   = cfp_dev_option_choice( get_option( 'cfp_dev_default_theme', 'dark' ), [ 'light', 'dark' ], 'dark' );
	$classes = [ 'cfp-html', 'cfp-page:' . $page, 'cfp-theme:' . $theme ];
	if ( '' !== $view ) {
		$classes[] = 'cfp-view:' . $view;
	}

	$restore_theme = '';
	if ( cfp_dev_theme_switch_enabled() ) {
		$restore_theme = 'var saved = null;'
			. 'try { saved = window.localStorage.getItem("cfp-theme"); } catch (error) { saved = null; }'
			. 'if ("light" === saved || "dark" === saved) {'
			. 'root.classList.remove(' . wp_json_encode( 'cfp-theme:' . $theme ) . ');'
			. 'root.classList.add("cfp-theme:" + saved);'
			. '}';
	}

	return '<script>(function () {'
		. 'var root = document.documentElement;'
		. 'Array.prototype.slice.call(root.classList).forEach(function (c) { if (0 === c.indexOf("cfp-")) { root.classList.remove(c); } });'
		. wp_json_encode( $classes ) . '.forEach(function (c) { root.classList.add(c); });'
		. $restore_theme
		. '})();</script>';
}

/**
 * Theme-switch footer (empty string when switching is disabled).
 *
 * Rendered as <button>s rather than <a>s: these controls change state on the
 * current page, they do not navigate. An <a> without href is not focusable
 * and exposes no role, so the toggle was unreachable by keyboard entirely.
 */
function cfp_dev_footer() {
	if ( ! cfp_dev_theme_switch_enabled() ) {
		return '';
	}

	$active = cfp_dev_option_choice( get_option( 'cfp_dev_default_theme', 'dark' ), [ 'light', 'dark' ], 'dark' );

	$content  = '<footer class="cfp-footer">';
	$content .= '<div class="cfp-theme" role="group" aria-label="' . esc_attr__( 'Colour theme', 'cfp-dev-shortcodes' ) . '">';
	$content .= '<button type="button" id="lightTheme" class="cfp-a cfp-light" data-theme-key="light" aria-pressed="' . ( 'light' === $active ? 'true' : 'false' ) . '">'
		. esc_html__( 'Light', 'cfp-dev-shortcodes' ) . '</button>';
	$content .= '<button type="button" id="darkTheme" class="cfp-a cfp-dark" data-theme-key="dark" aria-pressed="' . ( 'dark' === $active ? 'true' : 'false' ) . '">'
		. esc_html__( 'Dark', 'cfp-dev-shortcodes' ) . '</button>';
	$content .= '</div>';
	$content .= '</footer>';

	return $content;
}

/**
 * Renders the programme search form.
 *
 * The form carries declarative WebMCP tool metadata (toolname,
 * tooldescription, toolparamdescription) so agentic browsers and AI agents
 * can invoke the search as a structured tool. Regular browsers ignore the
 * extra attributes.
 */
function cfp_dev_search_form() {
	// Absolute action URL — a relative one resolves against the current path
	// (e.g. /talks-by-tracks/search-results) and 404s.
	// toolname/tooldescription = declarative WebMCP metadata for AI agents.
	//
	// The input carries a real <label>: a placeholder is not an accessible
	// name, so the field announced as "search" with no purpose. The label is
	// visually hidden rather than absent so sighted layout is unchanged.
	// `autofocus` is deliberately not set — the form renders on every list
	// page, and moving focus on load scrolls past the content the visitor
	// actually asked for and disorients screen-reader users.
	$label = __( 'Search the conference programme', 'cfp-dev-shortcodes' );

	$content  = '<form class="cfp-search" role="search" action="' . esc_url( home_url( cfp_dev_url( '/search-results/' ) ) ) . '" method="GET"'
		. ' toolname="search_conference_programme"'
		. ' tooldescription="Searches the conference programme for talks and speakers matching a keyword (e.g. a technology, topic or speaker name)">';
	$content .= '<label class="cfp-visually-hidden" for="dev-cfp-search-term">' . esc_html( $label ) . '</label>';
	$content .= '<input class="cfp-input" id="dev-cfp-search-term" type="search" minlength="3" name="query"'
		. ' placeholder="' . esc_attr__( 'Full search...', 'cfp-dev-shortcodes' ) . '"'
		. ' toolparamdescription="Search keyword, minimum 3 characters">';
	$content .= '</form>';
	return $content;
}

/**
 * Renders the social-link icons (LinkedIn, Bluesky, Mastodon, X/Twitter) for
 * a speaker. Returns an empty string when no handle is set.
 *
 * @param object $speaker  Speaker object from the API.
 * @return string
 */
function cfp_dev_social_links( $speaker ) {
	// The icons are CSS background images, so the links have no text content.
	// Without an accessible name a screen reader announces only "link", which
	// is why each entry carries an explicit aria-label.
	$networks = [
		[
			'field'  => 'linkedInUsername',
			'class'  => 'cfp-linkedIn',
			'prefix' => 'https://www.linkedin.com/in/',
			/* translators: %s: speaker name. */
			'label'  => __( 'LinkedIn profile of %s', 'cfp-dev-shortcodes' ),
		],
		[
			'field'  => 'blueskyUsername',
			'class'  => 'cfp-bluesky',
			'prefix' => 'https://bsky.app/profile/',
			/* translators: %s: speaker name. */
			'label'  => __( 'Bluesky profile of %s', 'cfp-dev-shortcodes' ),
		],
		[
			// Full URL from the API — esc_url() also neutralises javascript: URIs (esc_attr does not).
			'field'  => 'mastodonUsername',
			'class'  => 'cfp-mastodon',
			'prefix' => '',
			/* translators: %s: speaker name. */
			'label'  => __( 'Mastodon profile of %s', 'cfp-dev-shortcodes' ),
		],
		[
			'field'  => 'twitterHandle',
			'class'  => 'cfp-twitter',
			'prefix' => 'https://x.com/',
			/* translators: %s: speaker name. */
			'label'  => __( 'X (Twitter) profile of %s', 'cfp-dev-shortcodes' ),
		],
	];

	$name  = trim( ( $speaker->firstName ?? '' ) . ' ' . ( $speaker->lastName ?? '' ) );
	$links = '';

	foreach ( $networks as $network ) {
		$handle = $speaker->{$network['field']} ?? '';
		if ( empty( $handle ) ) {
			continue;
		}
		$links .= '<a class="cfp-a ' . esc_attr( $network['class'] ) . '"'
			. ' href="' . esc_url( $network['prefix'] . $handle ) . '"'
			. ' aria-label="' . esc_attr( sprintf( $network['label'], $name ) ) . '"'
			. ' target="_blank" rel="noopener noreferrer"></a>';
	}

	return '' === $links
		? ''
		: '<nav class="cfp-social" aria-label="' . esc_attr__( 'Social media profiles', 'cfp-dev-shortcodes' ) . '">' . $links . '</nav>';
}
