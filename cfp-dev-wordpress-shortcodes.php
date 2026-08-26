<?php
/**
 * Plugin Name:       CFP.DEV shortcodes
 * Plugin URI:        https://github.com/patbaumgartner/cfp-dev-shortcodes
 * Description:       Display CFP.DEV conference content on your WordPress site: speakers, talks, schedule, and search — with light/dark theming, caching, and offline mode.
 * Version:           4.8.1
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Stephan Janssen, Patrick Baumgartner
 * Author URI:        https://x.com/stephan007
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       cfp-dev-shortcodes
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/*
 * ── Constants ─────────────────────────────────────────────────────
 * Split in two groups: those below depend on nothing, while the ones
 * after the module load read settings and so need those accessors to
 * exist first.
 */

if ( ! defined( 'CFP_DEV_APPLICATION_JSON' ) ) {
	define( 'CFP_DEV_APPLICATION_JSON', 'application/json; charset=utf-8' );
}

// Plugin version.
if ( ! defined( 'CFP_DEV_VERSION' ) ) {
	define( 'CFP_DEV_VERSION', '4.8.1' );
}

/*
 * The main plugin file. Activation/deactivation hooks and plugins_url()
 * resolve against it: the modules below live in a subdirectory, so their
 * own __FILE__ would address a file WordPress does not know as a plugin.
 */
if ( ! defined( 'CFP_DEV_PLUGIN_FILE' ) ) {
	define( 'CFP_DEV_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'CFP_DEV_NAME' ) ) {
	define( 'CFP_DEV_NAME', trim( dirname( plugin_basename( __FILE__ ) ), '/' ) );
}

// Derived from this file rather than WP_PLUGIN_DIR/NAME so the plugin also
// resolves correctly when its directory is symlinked or renamed.
if ( ! defined( 'CFP_DEV_DIR' ) ) {
	define( 'CFP_DEV_DIR', rtrim( plugin_dir_path( __FILE__ ), '/' ) );
}

if ( ! defined( 'CFP_DEV_URL' ) ) {
	define( 'CFP_DEV_URL', rtrim( plugin_dir_url( __FILE__ ), '/' ) );
}

if ( ! defined( 'CFP_DEV_CSS' ) ) {
	// Stylesheet is versioned by minor release — rename on breaking style changes.
	define( 'CFP_DEV_CSS', 'css/cfp_dev_v4_5.css' );
}

// Single fetch size for full speaker-list lookups (was 300/400/500 in different places).
if ( ! defined( 'CFP_DEV_SPEAKERS_FETCH_SIZE' ) ) {
	define( 'CFP_DEV_SPEAKERS_FETCH_SIZE', 500 );
}

// The one API path whose results are never cached, and so is timed out sooner.
if ( ! defined( 'CFP_DEV_SEARCH_PATH' ) ) {
	define( 'CFP_DEV_SEARCH_PATH', 'public/search' );
}

/*
 * ── Module load ───────────────────────────────────────────────────
 * Core modules first — later ones call into earlier ones — then the
 * feature modules that render the shortcodes.
 */

$cfp_dev_modules = [
	// Core.
	'shortcode/include/helpers.php',
	'shortcode/include/settings.php',
	'shortcode/include/cache.php',
	'shortcode/include/api-client.php',
	'shortcode/include/ui.php',
	'shortcode/include/rewrite.php',
	'shortcode/include/admin.php',
	'shortcode/include/seo.php',

	// Features.
	'shortcode/include/offline-crawler.php',
	'shortcode/include/class-cfp-dev-sitemaps-provider.php',
	'shortcode/include/talk-table.php',
	'shortcode/shortcode-cfp-speakers.php',
	'shortcode/shortcode-cfp-speaker-details.php',
	'shortcode/shortcode-cfp-schedule.php',
	'shortcode/shortcode-cfp-talk-details.php',
	'shortcode/shortcode-cfp-talks-by-tracks.php',
	'shortcode/shortcode-cfp-talks-by-sessions.php',
	'shortcode/shortcode-cfp-search-results.php',
];
foreach ( $cfp_dev_modules as $cfp_dev_module ) {
	// Deliberately unguarded: a module missing from an install is a corrupt
	// deployment, and skipping it silently starts a plugin that half works and
	// fails later, far from the cause.
	require_once CFP_DEV_DIR . '/' . $cfp_dev_module;
}
unset( $cfp_dev_modules, $cfp_dev_module );

/*
 * Settings-derived constants, kept for installs and themes that read
 * them. Plugin code calls the accessors instead — these snapshot their
 * values at load time and go stale the moment a setting is saved.
 */

if ( ! defined( 'CFP_DEV_KEY' ) ) {
	define( 'CFP_DEV_KEY', cfp_dev_get_key() );
}

if ( ! defined( 'CFP_DEV_CACHE' ) ) {
	define( 'CFP_DEV_CACHE', cfp_dev_get_cache_ttl() );
}

if ( ! defined( 'CFP_DEV_EVENT_NAME' ) ) {
	define( 'CFP_DEV_EVENT_NAME', cfp_dev_get_event_name() );
}

if ( ! defined( 'CFP_DEV_URL_DOMAIN' ) ) {
	define( 'CFP_DEV_URL_DOMAIN', cfp_dev_api_base() );
}

if ( ! defined( 'CFP_DEV_SEARCH_DOMAIN' ) ) {
	define( 'CFP_DEV_SEARCH_DOMAIN', cfp_dev_search_base() );
}

/*
 * ── Translations ──────────────────────────────────────────────────
 */

/** Loads the plugin's translations from /languages. */
function cfp_dev_load_textdomain() {
	load_plugin_textdomain( 'cfp-dev-shortcodes', false, CFP_DEV_NAME . '/languages' );
}
add_action( 'init', 'cfp_dev_load_textdomain' );

/*
 * ── Front-end assets ──────────────────────────────────────────────
 */

/** The shortcode tags this plugin registers. */
function cfp_dev_shortcode_tags(): array {
	return [
		'cfp_speakers',
		'cfp_speaker_details',
		'cfp_talk_details',
		'cfp_schedule',
		'cfp_talks_by_tracks',
		'cfp_talks_by_sessions',
		'cfp_search_results',
	];
}

/** The slugs of the pages this plugin creates and owns. */
function cfp_dev_page_slugs(): array {
	return [
		'speakers',
		'speaker',
		'talk',
		'schedule',
		'talks-by-tracks',
		'talks-by-sessions',
		'search-results',
	];
}

/**
 * Whether the current request renders a page that uses a plugin shortcode.
 *
 * The stylesheet is large and the script is only useful on plugin pages, so
 * they are not loaded across the rest of the site. Two things can say a page
 * needs them: the shortcode being present in the post content, and the request
 * being for one of the plugin's own pages.
 *
 * The second test is not redundant. Those pages exist to host the shortcodes,
 * but a theme is free to render them from a template instead of the page
 * content — and several do, leaving the content empty. `has_shortcode()`
 * cannot see that, so on those sites the assets silently stopped loading and
 * the pages rendered unstyled.
 *
 * Anything rendered from somewhere else entirely — a widget, a page builder,
 * a template part on a page the plugin does not own — is still undetectable;
 * themes can force the assets on with the `cfp_dev_enqueue_assets` filter.
 */
function cfp_dev_page_uses_shortcodes(): bool {
	$post = is_admin() ? null : get_post();
	$uses = false;

	if ( ! is_admin() && is_page( cfp_dev_page_slugs() ) ) {
		$uses = true;
	}

	if ( ! $uses && $post instanceof WP_Post ) {
		foreach ( cfp_dev_shortcode_tags() as $tag ) {
			if ( has_shortcode( $post->post_content, $tag ) ) {
				$uses = true;
				break;
			}
		}
	}

	/**
	 * Filters whether the CFP.DEV front-end assets are enqueued.
	 *
	 * @param bool         $uses  Whether a plugin shortcode was found in the post content.
	 * @param WP_Post|null $post  The queried post, when there is one.
	 */
	return (bool) apply_filters( 'cfp_dev_enqueue_assets', $uses, $post );
}

/**
 * Enqueues the front-end script and stylesheet.
 *
 * Safe to call more than once, and safe to call late: a style enqueued after
 * `wp_head` has run is printed in the footer instead, which is how a shortcode
 * rendered from a template still gets its stylesheet.
 */
function cfp_dev_enqueue_assets(): void {
	wp_enqueue_script( 'site-cfp', plugin_dir_url( __FILE__ ) . 'js/site.js', [], CFP_DEV_VERSION, true );
	wp_enqueue_style( 'cfp-dev-style', plugin_dir_url( __FILE__ ) . 'shortcode/' . CFP_DEV_CSS, [], CFP_DEV_VERSION );
}

/**
 * Enqueues the front-end assets early, for the requests where the plugin can
 * tell in advance that they will be needed — which puts them in `<head>`.
 *
 * Every shortcode also enqueues them itself when it runs, so a shortcode this
 * cannot predict still gets styled; see cfp_dev_shortcode_assets().
 */
function cfp_dev_enqueue_front_end_assets() {
	if ( ! cfp_dev_page_uses_shortcodes() ) {
		return;
	}
	cfp_dev_enqueue_assets();
}

add_action( 'wp_enqueue_scripts', 'cfp_dev_enqueue_front_end_assets' );

/**
 * Called by every shortcode as it renders, so that the assets follow the
 * markup wherever it is produced.
 *
 * Detecting shortcodes ahead of time means reading the post content, and a
 * theme is free to render them from somewhere that content never mentions — a
 * template, a widget, a block, the front page. On those requests the early
 * pass finds nothing and the markup arrived unstyled. Asking at render time
 * cannot be wrong: the shortcode is running, so the page needs the assets.
 */
function cfp_dev_shortcode_assets(): void {
	if ( is_admin() ) {
		return;
	}
	cfp_dev_enqueue_assets();
}
