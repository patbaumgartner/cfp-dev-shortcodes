<?php
/**
 * Plugin Name:       CFP.DEV shortcodes
 * Plugin URI:        https://github.com/patbaumgartner/cfp-dev-shortcodes
 * Description:       Display CFP.DEV conference content on your WordPress site: speakers, talks, schedule, and search — with light/dark theming, caching, and offline mode.
 * Version:           4.5.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Stephan Janssen, Patrick Baumgartner
 * Author URI:        https://x.com/stephan007
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       cfp-dev-shortcodes
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Global constants.
 */

if ( ! defined( 'CFP_DEV_APPLICATION_JSON' ) ) {
	define( 'CFP_DEV_APPLICATION_JSON', 'application/json; charset=utf-8' );
}

// Plugin version.
if ( ! defined( 'CFP_DEV_VERSION' ) ) {
	define( 'CFP_DEV_VERSION', '4.5.0' );
}

if ( ! defined( 'CFP_DEV_NAME' ) ) {
	define( 'CFP_DEV_NAME', trim( dirname( plugin_basename( __FILE__ ) ), '/' ) );
}

if ( ! defined( 'CFP_DEV_DIR' ) ) {
	define( 'CFP_DEV_DIR', WP_PLUGIN_DIR . '/' . CFP_DEV_NAME );
}

if ( ! defined( 'CFP_DEV_URL' ) ) {
	define( 'CFP_DEV_URL', WP_PLUGIN_URL . '/' . CFP_DEV_NAME );
}

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

if ( ! defined( 'CFP_DEV_CSS' ) ) {
	// Stylesheet is versioned by minor release — rename on breaking style changes.
	define( 'CFP_DEV_CSS', 'css/cfp_dev_v4_5.css' );
}

// Single fetch size for full speaker-list lookups (was 300/400/500 in different places).
if ( ! defined( 'CFP_DEV_SPEAKERS_FETCH_SIZE' ) ) {
	define( 'CFP_DEV_SPEAKERS_FETCH_SIZE', 500 );
}

/**
 * Debug-safe logging helper.
 * Only writes to the error log when WP_DEBUG_LOG is enabled,
 * so no diagnostic data leaks on production sites.
 *
 * @param string $message  Message to log (prefixed with "[CFP.DEV]").
 */
function cfp_dev_log( string $message ): void {
	if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		error_log( '[CFP.DEV] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging gated on WP_DEBUG_LOG
	}
}

/**
 * Settings accessors.
 *
 * Settings live in options (autoloaded, never evicted). Legacy installs stored
 * them in transients — which object caches may evict at any time — so the
 * accessors transparently migrate values from the legacy transient location.
 */

/** Returns the CFP.DEV instance key (the *.cfp.dev subdomain). */
function cfp_dev_get_key(): string {
	$key = get_option( 'cfp_dev_key', false );
	if ( false === $key ) {
		$key = get_transient( 'CFP_DEV_KEY' ); // Legacy storage location.
		if ( is_string( $key ) && '' !== $key ) {
			update_option( 'cfp_dev_key', $key );
		}
	}
	return is_string( $key ) ? $key : '';
}

/** Returns the event display name used in titles and meta tags. */
function cfp_dev_get_event_name(): string {
	$name = get_option( 'cfp_dev_event_name', false );
	if ( false === $name ) {
		$name = get_transient( 'CFP_DEV_EVENT_NAME' ); // Legacy storage location.
		if ( is_string( $name ) && '' !== $name ) {
			update_option( 'cfp_dev_event_name', $name );
		}
	}
	return is_string( $name ) ? $name : '';
}

/** Returns the cache TTL in seconds (0 = caching disabled). */
function cfp_dev_get_cache_ttl(): int {
	$ttl = get_option( 'cfp_dev_cache_duration', false );
	if ( false === $ttl ) {
		$ttl = get_transient( 'CFP_DEV_CACHE' ); // Legacy storage location.
		if ( false !== $ttl ) {
			update_option( 'cfp_dev_cache_duration', (int) $ttl );
		}
	}
	return max( 0, (int) $ttl );
}

/** Base URL of the CFP.DEV REST API for the configured instance. */
function cfp_dev_api_base(): string {
	return 'https://' . rawurlencode( cfp_dev_get_key() ) . '.cfp.dev/api/';
}

/** Base URL of the semantic search service (the query term is appended by callers). */
function cfp_dev_search_base(): string {
	return 'https://search.cfp.dev?cfp=' . rawurlencode( cfp_dev_get_key() ) . '&accepted=true&total=5&query=';
}

/**
 * Request-scoped memoisation.
 *
 * Several helpers are called repeatedly while rendering a single page — head
 * meta, JSON-LD, the canonical URL and the shortcode itself all resolve the
 * same entity. They share one in-memory store instead of each keeping its own
 * pair of static variables, which also gives long-running processes (WP-CLI,
 * the offline crawler) a way to drop stale request state.
 *
 * @return array<string,mixed>
 */
function &cfp_dev_request_cache(): array {
	static $cache = [];
	return $cache;
}

/**
 * Returns the memoised value for $key, computing it on first access. A
 * resolver returning null is memoised too — "resolved to nothing" is an answer
 * worth remembering.
 *
 * @param string   $key       Cache key.
 * @param callable $resolver  Computes the value on a miss.
 * @return mixed
 */
function cfp_dev_request_cache_get( string $key, callable $resolver ) {
	$cache = &cfp_dev_request_cache();
	if ( ! array_key_exists( $key, $cache ) ) {
		$cache[ $key ] = $resolver();
	}
	return $cache[ $key ];
}

/** Empties the request-scoped memo store. */
function cfp_dev_flush_request_cache(): void {
	$cache = &cfp_dev_request_cache();
	$cache = [];
}

/**
 * Cache versioning.
 *
 * Every transient key is suffixed with the current cache version, so a full
 * cache flush is a single O(1) option increment — no API calls, no key
 * enumeration. Superseded transients simply expire via their TTL.
 */

/** Version suffix appended to every transient key (see cfp_dev_clear_cache()). */
function cfp_dev_cache_salt(): string {
	return '_v' . (int) get_option( 'cfp_dev_cache_version', 1 );
}

/**
 * Versioned transient key for a named cache group.
 *
 * @param string $name  Base key name, e.g. 'cfp_schedule_Tuesday'.
 */
function cfp_dev_group_cache_key( string $name ): string {
	return $name . cfp_dev_cache_salt();
}

/**
 * Default attributes for [cfp_speakers] — shared between the shortcode and the
 * admin cache page so both compute the same cache key.
 */
function cfp_dev_speakers_default_atts(): array {
	return [
		'random'      => false,
		'size'        => 300,
		'title'       => '',
		'subtitle'    => '',
		'hide_title'  => false,
		'hide_search' => false,
	];
}

/**
 * Cache key for a [cfp_speakers] rendering. Keyed per attribute set so two
 * pages with different size/title/random no longer serve each other's HTML.
 */
function cfp_dev_speakers_cache_key( array $atts ): string {
	return cfp_dev_group_cache_key( 'speakers_cache_group_' . md5( wp_json_encode( $atts ) ) );
}

/**
 * Normalises a shortcode boolean attribute: 'yes'/'true'/'1' → true,
 * 'no'/'false'/'0'/'' → false (any non-empty string is truthy in plain PHP).
 *
 * @param mixed $value  Raw attribute value.
 */
function cfp_dev_attr_bool( $value ): bool {
	return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
}

/**
 * Cache-key suffix for a shortcode's attribute set: empty for the defaults
 * (so admin tooling can address the standard variant by its plain key),
 * hashed for any customised set.
 *
 * @param array $atts      Normalised attributes.
 * @param array $defaults  The shortcode's default attributes.
 */
function cfp_dev_atts_cache_suffix( array $atts, array $defaults ): string {
	return ( $atts == $defaults ) ? '' : '_' . md5( wp_json_encode( $atts ) ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- order-insensitive array comparison intended
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

// Load the offline crawler and all shortcode modules.
$cfp_dev_modules = [
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
	if ( file_exists( CFP_DEV_DIR . '/' . $cfp_dev_module ) ) {
		require_once CFP_DEV_DIR . '/' . $cfp_dev_module;
	}
}
unset( $cfp_dev_modules, $cfp_dev_module );

/**
 * Whether the current request renders a page that uses a plugin shortcode.
 *
 * The stylesheet is large and the script is only useful on plugin pages, so
 * they are not loaded across the rest of the site. Shortcodes rendered from
 * somewhere other than the post content (a widget, a template part) are not
 * detectable here — themes can force the assets on with the
 * `cfp_dev_enqueue_assets` filter.
 */
function cfp_dev_page_uses_shortcodes(): bool {
	$post = is_admin() ? null : get_post();
	$uses = false;

	if ( $post instanceof WP_Post ) {
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
 * Enqueues the front-end script and stylesheet on pages that use a shortcode.
 */
function cfp_dev_enqueue_front_end_assets() {
	if ( ! cfp_dev_page_uses_shortcodes() ) {
		return;
	}
	wp_enqueue_script( 'site-cfp', plugin_dir_url( __FILE__ ) . 'js/site.js', [], CFP_DEV_VERSION, true );
	wp_enqueue_style( 'cfp-dev-style', plugin_dir_url( __FILE__ ) . 'shortcode/' . CFP_DEV_CSS, [], CFP_DEV_VERSION );
}

add_action( 'wp_enqueue_scripts', 'cfp_dev_enqueue_front_end_assets' );

/**
 * Registers the Settings → CFP.DEV admin page.
 */
function cfp_dev_plugin_menu() {
	add_options_page( 'CFP.DEV Settings', 'CFP.DEV', 'manage_options', 'cfp-dev-settings', 'cfp_dev_plugin_options' );
}

add_action( 'admin_menu', 'cfp_dev_plugin_menu' );

/**
 * Returns $value when it is one of $allowed, otherwise $fallback.
 *
 * Settings that feed URLs, CSS classes or API paths must never store an
 * arbitrary string just because an administrator submitted one.
 *
 * @param mixed    $value     Submitted value.
 * @param string[] $allowed   Accepted values (lowercase).
 * @param string   $fallback  Value to use when the input is not accepted.
 */
function cfp_dev_option_choice( $value, array $allowed, string $fallback ): string {
	$value = strtolower( trim( (string) $value ) );
	return in_array( $value, $allowed, true ) ? $value : $fallback;
}

/**
 * Normalises the URL path prefix to slash-separated slugs.
 *
 * The prefix is interpolated into rewrite-rule *regular expressions*, so
 * characters like `.` or `(` would silently change which URLs match.
 *
 * @param mixed $value  Submitted prefix, e.g. ' /Trieste/ '.
 */
function cfp_dev_sanitize_path_prefix( $value ): string {
	$segments = array_filter( array_map( 'sanitize_title', explode( '/', (string) $value ) ) );
	return implode( '/', $segments );
}

/**
 * Whether the light/dark footer toggle is enabled.
 *
 * Migrates the pre-4.5.0 unprefixed `enable_theme_switch` option, which
 * squatted on a name any other plugin could have been using.
 */
function cfp_dev_theme_switch_enabled(): bool {
	$enabled = get_option( 'cfp_dev_enable_theme_switch', false );

	if ( false === $enabled ) {
		$legacy = get_option( 'enable_theme_switch', false );
		if ( false !== $legacy ) {
			$enabled = $legacy;
			update_option( 'cfp_dev_enable_theme_switch', (int) (bool) $legacy );
			delete_option( 'enable_theme_switch' );
		}
	}

	return (bool) $enabled;
}

/**
 * Applies a settings-page submission.
 *
 * @param array $post  Unslashed POST payload (nonce and capability already verified).
 * @return string  Admin notice to display, or '' when there is nothing to report.
 */
function cfp_dev_handle_settings_post( array $post ): string {
	if ( isset( $post['delete_cache'] ) ) {
		return cfp_dev_handle_cache_deletion( $post );
	}

	if ( isset( $post['cfp_dev_offline_mode_save'] ) ) {
		cfp_dev_handle_offline_mode( isset( $post['cfp_dev_offline_mode'] ) );
		return '';
	}

	if ( ! isset( $post['cfp_dev_key'] ) ) {
		return '';
	}

	cfp_dev_store_key( sanitize_text_field( $post['cfp_dev_key'] ) );
	cfp_dev_store_event_name( sanitize_text_field( $post['cfp_dev_event_name'] ?? '' ) );
	cfp_dev_store_cache_ttl( sanitize_text_field( $post['cfp_dev_cache'] ?? '0' ) );

	update_option( 'cfp_dev_default_theme', cfp_dev_option_choice( $post['cfp_dev_default_theme'] ?? '', [ 'light', 'dark' ], 'dark' ) );
	update_option( 'cfp_dev_content_by_id', cfp_dev_option_choice( $post['cfp_dev_content_by_id'] ?? '', [ 'yes', 'no' ], 'yes' ) );
	update_option( 'cfp_dev_show_rooms', cfp_dev_option_choice( $post['cfp_dev_show_rooms'] ?? '', [ 'yes', 'no' ], 'yes' ) );

	// An unchecked checkbox is absent from the payload, so it is read off the
	// form as a whole rather than tested for its own presence.
	update_option( 'cfp_dev_enable_theme_switch', isset( $post['enable_theme_switch'] ) ? 1 : 0 );
	delete_option( 'enable_theme_switch' );

	$new_prefix = cfp_dev_sanitize_path_prefix( $post['cfp_dev_path_prefix'] ?? '' );
	if ( get_option( 'cfp_dev_path_prefix', '' ) !== $new_prefix ) {
		update_option( 'cfp_dev_path_prefix', $new_prefix );
		// Rewrite rules embed the prefix — rebuild them right away.
		cfp_dev_add_rewrite_rules();
		flush_rewrite_rules();
	}

	cfp_dev_clear_cache();
	return 'Settings saved.';
}

/**
 * Deletes the cache entry addressed by a "Delete Cache" form.
 *
 * @param array $post  Unslashed POST payload.
 * @return string  Admin notice.
 */
function cfp_dev_handle_cache_deletion( array $post ): string {
	$cache_type = sanitize_key( $post['delete_cache'] );

	if ( 'speakers' === $cache_type ) {
		delete_transient( cfp_dev_speakers_cache_key( cfp_dev_speakers_default_atts() ) );
		return 'Speakers cache deleted.';
	}

	if ( 'schedule' === $cache_type ) {
		$day = sanitize_key( $post['cache_day'] ?? '' );
		if ( ! in_array( $day, [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ], true ) ) {
			return '';
		}
		delete_transient( cfp_dev_group_cache_key( 'cfp_schedule_' . ucfirst( $day ) ) );
		return 'Schedule cache for ' . ucfirst( $day ) . ' deleted.';
	}

	if ( in_array( $cache_type, [ 'speaker', 'talk' ], true ) && isset( $post['cache_id'] ) ) {
		$cache_id = sanitize_text_field( $post['cache_id'] );
		delete_transient( cfp_dev_detail_cache_key( $cache_type, $cache_id ) );
		delete_transient( cfp_dev_detail_cache_key( 'photo', $cache_id ) );
		return 'Cache deleted for ' . $cache_type . ' with ID: ' . $cache_id . ' (including any photo cache).';
	}

	return '';
}

/**
 * Applies the offline-mode checkbox. Enabling starts a crawl; offline mode
 * itself is switched on when that crawl completes.
 *
 * @param bool $enable  Whether the checkbox was submitted as checked.
 */
function cfp_dev_handle_offline_mode( bool $enable ): void {
	$was_enabled  = 1 === (int) get_option( 'cfp_dev_offline_mode', 0 );
	$crawl_status = get_option( 'cfp_dev_crawl_state', [] )['status'] ?? 'idle';
	$crawling     = in_array( $crawl_status, [ 'running', 'pending' ], true );

	if ( ! $enable ) {
		update_option( 'cfp_dev_offline_mode', 0 );
		if ( $was_enabled ) {
			// Rendered HTML still points at snapshot URLs — re-render from live API.
			cfp_dev_clear_cache();
		}
		return;
	}

	if ( ! $was_enabled && ! $crawling ) {
		cfp_dev_start_crawl();
	}
}

/** Renders the CFP.DEV settings page. */
function cfp_dev_plugin_options() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'cfp-dev-shortcodes' ) );
	}

	// Verify nonce for any POST action on this page.
	if ( ! empty( $_POST ) && ( ! isset( $_POST['cfp_dev_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cfp_dev_nonce'] ) ), 'cfp_dev_options' ) ) ) {
		wp_die( esc_html__( 'Security check failed.', 'cfp-dev-shortcodes' ) );
	}

	// Nonce and capability are verified above; every field is sanitised inside
	// the handler, which takes the payload as an argument so it stays testable.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$cache_notice = empty( $_POST ) ? '' : cfp_dev_handle_settings_post( wp_unslash( $_POST ) );

	echo '<div class="wrap">';
	echo '<h1>CFP.DEV Settings</h1>';

	if ( '' !== $cache_notice ) {
		echo '<div class="updated"><p>' . esc_html( $cache_notice ) . '</p></div>';
	}

	// General Settings Section
	echo '<hr style="border-color: black">';
	echo '<h3>General Settings</h3>';
	echo '<form name="form1" method="post" action="">';
	wp_nonce_field( 'cfp_dev_options', 'cfp_dev_nonce' );
	echo '<table class="form-table">';
	echo '<tr>
			<th scope="row"><label>CFP.DEV Key</label></th>
			<td><input name="cfp_dev_key" size=20 value="' . esc_attr( cfp_dev_get_key() ) . '" minlength="3" pattern="[A-Za-z0-9-]+" required="true">
			<br><small>Only letters, digits and dashes (the subdomain of your CFP.DEV instance).</small></td>
		  </tr>';
	echo '<tr>
			<th scope="row"><label>Event name</label></th>
			<td><input name="cfp_dev_event_name" size=50 value="' . esc_attr( cfp_dev_get_event_name() ) . '" minlength="3" required="true"></td>
		  </tr>';
	echo '<tr>
			<th scope="row"><label>URL Path Prefix</label></th>
			<td><input name="cfp_dev_path_prefix" size=20 value="' . esc_attr( get_option( 'cfp_dev_path_prefix', '' ) ) . '"><br>
			<small>For example https://voxxeddays.com/trieste would have "trieste" as url path prefix</small>
			</td>
		  </tr>';
	echo '<tr>
			<th scope="row"><label>Permalinks with Id</label></th>
			<td>
			  <select name="cfp_dev_content_by_id">
					<option value="yes" ' . selected( get_option( 'cfp_dev_content_by_id' ), 'yes', false ) . '>Yes</option>
					<option value="no" ' . selected( get_option( 'cfp_dev_content_by_id' ), 'no', false ) . '>No</option>
			  </select>
			  <br>
			  <strong>Must be "Yes" for multisite WordPress installs.</strong>
			  <small>When "Yes" the content links look as follows https://voxxeddays.com/trieste/speaker?id=123</small>
			</td>
		  </tr>';
	echo '<tr>
			<th scope="row"><label>Show Rooms</label></th>
			<td>
			  <select name="cfp_dev_show_rooms">
					<option value="yes" ' . selected( get_option( 'cfp_dev_show_rooms' ), 'yes', false ) . '>Yes</option>
					<option value="no" ' . selected( get_option( 'cfp_dev_show_rooms' ), 'no', false ) . '>No</option>
			  </select>
			  <br>
			  <small>When "No" rooms will not be displayed on any page</small>
			</td>
		  </tr>';
	echo '<tr>
			<th scope="row"><label>Cache Duration</label></th>
			<td>
				<select name="cfp_dev_cache">
					<option value="0" ' . selected( cfp_dev_get_cache_ttl(), 0, false ) . '>No Cache</option>
					<option value="3600" ' . selected( cfp_dev_get_cache_ttl(), 3600, false ) . '>One Hour</option>
					<option value="86400" ' . selected( cfp_dev_get_cache_ttl(), 86400, false ) . '>One Day</option>
					<option value="604800" ' . selected( cfp_dev_get_cache_ttl(), 604800, false ) . '>One Week</option>
					<option value="2592000" ' . selected( cfp_dev_get_cache_ttl(), 2592000, false ) . '>One Month</option>
				</select>
			</td>
		  </tr>';
	echo '<tr>
			<th scope="row"><label>Default Theme</label></th>
			<td>
				<select name="cfp_dev_default_theme">
					<option value="light" ' . selected( get_option( 'cfp_dev_default_theme' ), 'light', false ) . '>Light</option>
					<option value="dark" ' . selected( get_option( 'cfp_dev_default_theme' ), 'dark', false ) . '>Dark</option>
				</select>
			</td>
		  </tr>';
	echo '<tr>
			<th scope="row"><label>Enable Theme Switching</label></th>
			<td><input type="checkbox" name="enable_theme_switch" value="1" ' . checked( true, cfp_dev_theme_switch_enabled(), false ) . ' /></td>
		  </tr>';
	echo '</table>';
	echo '<p class="submit"><input type="submit" name="Submit" class="button-primary" value="Save Changes" /></p>';
	echo '</form>';

	// Cache Management Section
	echo '<hr style="border-color: black">';
	echo '<h3>Manage Caches</h3>';
	echo '<p>Here you can view and delete various caches used by the plugin.</p>';

	// Speakers cache
	echo '<h4>Speakers Cache</h4>';
	$speakers_cache = get_transient( cfp_dev_speakers_cache_key( cfp_dev_speakers_default_atts() ) );
	if ( false !== $speakers_cache ) {
		echo '<form method="post" action="">';
		wp_nonce_field( 'cfp_dev_options', 'cfp_dev_nonce' );
		echo '<input type="hidden" name="delete_cache" value="speakers">
				<input type="submit" class="button" value="Delete Speakers Cache">
			  </form>';
	} else {
		echo '<p>No speakers cache available.</p>';
	}

	// Schedule caches
	echo '<h4>Schedule Caches</h4>';
	$days                  = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];
	$schedule_caches_exist = false;

	echo '<table class="wp-list-table widefat fixed striped">
			<thead><tr><th>Day</th><th>Action</th></tr></thead>
			<tbody>';

	foreach ( $days as $day ) {
		// Schedule transients are keyed by the capitalised day name (DateTime 'l' format).
		$cache_key = cfp_dev_group_cache_key( 'cfp_schedule_' . ucfirst( $day ) );
		if ( get_transient( $cache_key ) !== false ) {
			$schedule_caches_exist = true;
			echo '<tr>
					<td>' . esc_html( ucfirst( $day ) ) . '</td>
					<td>
						<form method="post" action="">';
			wp_nonce_field( 'cfp_dev_options', 'cfp_dev_nonce' );
			echo '<input type="hidden" name="delete_cache" value="schedule">
							<input type="hidden" name="cache_day" value="' . esc_attr( $day ) . '">
							<input type="submit" class="button button-small" value="Delete Cache">
						</form>
					</td>
				  </tr>';
		}
	}

	echo '</tbody></table>';

	if ( ! $schedule_caches_exist ) {
		echo '<p>No schedule caches available.</p>';
	}

	// Speaker detail caches
	echo '<h4>Speaker Detail Caches</h4>';
	$speakers             = cfp_dev_get_json( 'public/speakers?size=' . CFP_DEV_SPEAKERS_FETCH_SIZE );
	$speaker_caches_exist = false;

	if ( is_array( $speakers ) || is_object( $speakers ) ) {
		echo '<table class="wp-list-table widefat fixed striped">
			<thead><tr><th>Speaker ID</th><th>Name</th><th>Action</th></tr></thead>
			<tbody>';

		foreach ( $speakers as $speaker ) {
			$transient_key = cfp_dev_detail_cache_key( 'speaker', $speaker->id );
			if ( get_transient( $transient_key ) !== false ) {
				$speaker_caches_exist = true;
				echo '<tr id="speaker-row-' . esc_attr( $speaker->id ) . '">
					<td>' . esc_html( $speaker->id ) . '</td>
					<td>' . esc_html( $speaker->firstName . ' ' . $speaker->lastName ) . '</td>
					<td>
						<form method="post" action="" class="delete-cache-form">';
				wp_nonce_field( 'cfp_dev_options', 'cfp_dev_nonce' );
				echo '<input type="hidden" name="delete_cache" value="speaker">
							<input type="hidden" name="cache_id" value="' . esc_attr( $speaker->id ) . '">
							<input type="submit" class="button button-small delete-cache-button" value="Delete Cache">
						</form>
					</td>
				  </tr>';
			}
		}

		echo '</tbody></table>';
	}

	if ( ! $speaker_caches_exist ) {
		echo '<p>No speaker detail caches available.</p>';
	}

	// Talk detail caches
	echo '<h4>Talk Detail Caches</h4>';
	$talks             = cfp_dev_get_json( 'public/talks' );
	$talk_caches_exist = false;

	if ( is_array( $talks ) || is_object( $talks ) ) {
		echo '<table class="wp-list-table widefat fixed striped">
				<thead><tr><th>Talk ID</th><th>Title</th><th>Action</th></tr></thead>
				<tbody>';

		foreach ( $talks as $talk ) {
			$transient_key = cfp_dev_detail_cache_key( 'talk', $talk->id );
			if ( get_transient( $transient_key ) !== false ) {
				$talk_caches_exist = true;
				echo '<tr>
						<td>' . esc_html( $talk->id ) . '</td>
						<td>' . esc_html( $talk->title ) . '</td>
						<td>
							<form method="post" action="">';
				wp_nonce_field( 'cfp_dev_options', 'cfp_dev_nonce' );
				echo '<input type="hidden" name="delete_cache" value="talk">
								<input type="hidden" name="cache_id" value="' . esc_attr( $talk->id ) . '">
								<input type="submit" class="button button-small" value="Delete Cache">
							</form>
						</td>
					  </tr>';
			}
		}

		echo '</tbody></table>';
	}

	if ( ! $talk_caches_exist ) {
		echo '<p>No talk detail caches available.</p>';
	}

	echo '</div>';

	// ─────────────────────────────────────────────────────────────────────────
	// Offline Mode Section
	// ─────────────────────────────────────────────────────────────────────────
	$offline_mode    = (int) get_option( 'cfp_dev_offline_mode', 0 );
	$crawl_state     = get_option( 'cfp_dev_crawl_state', [] );
	$crawl_status    = $crawl_state['status'] ?? 'idle';
	$latest_snapshot = cfp_dev_get_latest_snapshot();

	// Auto-disable offline mode when the snapshot folder has been removed.
	if ( 1 === $offline_mode && empty( $latest_snapshot ) && ! in_array( $crawl_status, [ 'running', 'pending' ], true ) ) {
		update_option( 'cfp_dev_offline_mode', 0 );
		$offline_mode = 0;
	}

	// Keep the checkbox checked while a crawl is in progress — offline mode
	// only flips to 1 when the crawl finishes, but the user intent is already set.
	if ( 0 === $offline_mode && in_array( $crawl_status, [ 'running', 'pending' ], true ) ) {
		$offline_mode = 1;
	}

	echo '<div class="wrap">';
	echo '<hr style="border-color: black">';
	echo '<h3>Offline Mode</h3>';
	echo '<p>When enabled, all API data and images are served from a local snapshot — no external requests are made.</p>';
	echo '<p><em>Checking the box starts a fresh crawl. Unchecking disables offline mode but keeps the snapshot data. Re-checking creates a new snapshot.</em></p>';

	echo '<form name="cfp_offline_form" method="post" action="">';
	wp_nonce_field( 'cfp_dev_options', 'cfp_dev_nonce' );
	echo '<input type="hidden" name="cfp_dev_offline_mode_save" value="1">';
	echo '<table class="form-table">';
	echo '<tr>
			<th scope="row"><label for="cfp_dev_offline_mode">Enable Offline Mode</label></th>
			<td>
				<input type="checkbox" id="cfp_dev_offline_mode" name="cfp_dev_offline_mode" value="1" ' . checked( 1, $offline_mode, false ) . '>
				<span class="description">Check to start a new crawl. Offline mode activates automatically when the crawl finishes.</span>
			</td>
		  </tr>';
	echo '</table>';
	echo '<p class="submit"><input type="submit" name="Submit" class="button-primary" value="Save Offline Mode"></p>';
	echo '</form>';

	// Snapshot status box (populated / updated by admin-offline-crawler.js)
	echo '<h4>Snapshot Status</h4>';
	echo '<div id="cfp-crawl-status">';

	if ( 'running' === $crawl_status || 'pending' === $crawl_status ) {
		echo '<p>Status: <strong>' . esc_html( ucfirst( $crawl_status ) ) . '</strong> — ' . esc_html( $crawl_state['step_label'] ?? '' ) . '</p>';
		if ( ! empty( $crawl_state['items_total'] ) && $crawl_state['items_total'] > 0 ) {
			$pct = intval( $crawl_state['items_done'] / $crawl_state['items_total'] * 100 );
			echo '<progress value="' . esc_attr( $crawl_state['items_done'] ) . '" max="' . esc_attr( $crawl_state['items_total'] ) . '"></progress> '
				. esc_html( $pct . '% (' . $crawl_state['items_done'] . '/' . $crawl_state['items_total'] . ')' );
		}
	} elseif ( 'done' === $crawl_status ) {
		echo '<p>Status: <strong>Complete</strong></p>';
		if ( ! empty( $crawl_state['snapshot_name'] ) ) {
			echo '<p>Active snapshot: <code>' . esc_html( $crawl_state['snapshot_name'] ) . '</code></p>';
		}
		if ( ! empty( $crawl_state['errors'] ) ) {
			echo '<p style="color:orange;">Warnings: ' . esc_html( $crawl_state['errors'] ) . ' item(s) had errors (see manifest.json).</p>';
		}
	} elseif ( 'error' === $crawl_status ) {
		echo '<p style="color:red;">Status: <strong>Error</strong> — ' . esc_html( $crawl_state['step_label'] ?? '' ) . '</p>';
	} elseif ( ! empty( $latest_snapshot ) ) {
		echo '<p>Last snapshot: <code>' . esc_html( basename( $latest_snapshot ) ) . '</code></p>';
	} else {
		echo '<p>No snapshot available. Enable offline mode or click <strong>Re-crawl Now</strong> to create one.</p>';
	}

	echo '</div>';
	echo '<p><button type="button" id="cfp-recrawl-btn" class="button">Re-crawl Now</button></p>';

	echo '</div>';
}

/**
 * Versioned transient key for a speaker/talk/photo detail cache.
 *
 * @param string     $type  Entity type: 'speaker', 'talk', or 'photo'.
 * @param string|int $id    Entity id (hashed into the key).
 * @return string
 */
function cfp_dev_detail_cache_key( $type, $id ) {
	switch ( $type ) {
		case 'speaker':
			$key = 'cfp_speaker_details_' . md5( (string) $id );
			break;
		case 'talk':
			$key = 'cfp_talk_details_' . md5( (string) $id );
			break;
		case 'photo':
			$key = 'speaker_photos_' . md5( (string) $id );
			break;
		default:
			$key = 'cfp_' . $type . '_' . md5( (string) $id );
			break;
	}
	return $key . cfp_dev_cache_salt();
}

/**
 * Persists the CFP.DEV instance key, sanitised to safe hostname characters.
 *
 * @param string $key  Raw key from the settings form.
 */
function cfp_dev_store_key( $key ) {
	// The key is a cfp.dev subdomain — restrict to safe hostname characters so it
	// cannot alter the API URL (e.g. via dots or slashes).
	$key = strtolower( preg_replace( '/[^A-Za-z0-9-]/', '', (string) $key ) );
	update_option( 'cfp_dev_key', $key );
	delete_transient( 'CFP_DEV_KEY' ); // Legacy storage location.
}

/**
 * Persists the cache TTL.
 *
 * @param int|string $ttl  TTL in seconds (0 disables caching).
 */
function cfp_dev_store_cache_ttl( $ttl ) {
	update_option( 'cfp_dev_cache_duration', max( 0, (int) $ttl ) );
	delete_transient( 'CFP_DEV_CACHE' ); // Legacy storage location.
}

/**
 * Persists the event display name.
 *
 * @param string $cfpDevEventName  Event name from the settings form.
 */
function cfp_dev_store_event_name( $cfpDevEventName ) {
	update_option( 'cfp_dev_event_name', sanitize_text_field( (string) $cfpDevEventName ) );
	delete_transient( 'CFP_DEV_EVENT_NAME' ); // Legacy storage location.
}

/**
 * Invalidate every plugin cache in O(1).
 *
 * All transient keys embed the cache version (see cfp_dev_cache_salt()), so
 * bumping the version instantly orphans every cached entry — no API calls, no
 * key enumeration. Orphaned transients expire naturally via their TTL.
 */
function cfp_dev_clear_cache() {
	update_option( 'cfp_dev_cache_version', (int) get_option( 'cfp_dev_cache_version', 1 ) + 1 );
	cfp_dev_log( 'cfp_dev_clear_cache: cache version bumped to ' . get_option( 'cfp_dev_cache_version' ) );
}

/**
 * Theme-switch footer (empty string when switching is disabled).
 */
function cfp_dev_footer() {
	if ( cfp_dev_theme_switch_enabled() ) {
		$content  = '<footer class="cfp-footer">';
		$content .= '	<div class="cfp-theme">';
		$content .= '    	<a id="lightTheme" class="cfp-a cfp-light" data-theme-key="light">Light</a>';
		$content .= '    	<a id="darkTheme" class="cfp-a cfp-dark" data-theme-key="dark">Dark</a>';
		$content .= '</div>';
		$content .= '</footer>';
		return $content;
	}
	return '';
}

/**
 * usort() comparator: orders speakers by last name, accent-insensitively
 * (transliterated to ASCII so e.g. Šumailov sorts under S).
 */
function cfp_dev_compare_last_name( $x, $y ) {
	return iconv( 'utf-8', 'ascii//TRANSLIT', $x->lastName ) <=> iconv( 'utf-8', 'ascii//TRANSLIT', $y->lastName );
}

/**
 * usort() comparator: orders objects by their name property, accent-insensitively.
 */
function cfp_dev_compare_name( $x, $y ) {
	return iconv( 'utf-8', 'ascii//TRANSLIT', $x->name ) <=> iconv( 'utf-8', 'ascii//TRANSLIT', $y->name );
}

/**
 * Fetches and decodes JSON from the CFP.DEV API — or from the local snapshot
 * when offline mode is active. Rejects path traversal in the query path.
 *
 * Responses are memoised for the rest of the request: rendering one page can
 * ask for the same endpoint from the shortcode, the head metadata, the
 * canonical URL and the sitemap. Each caller is handed a freshly decoded
 * object graph, so one caller cannot disturb another's copy.
 *
 * @param string $queryPath  Relative API path, e.g. 'public/speakers?size=500'.
 * @return mixed  Decoded JSON (object|array) or null on failure.
 */
function cfp_dev_get_json( $queryPath ) {
	// Reject path traversal — query paths are relative API routes and several
	// callers interpolate user-supplied ids (also guards offline file lookups).
	if ( str_contains( $queryPath, '..' ) || str_starts_with( $queryPath, '/' ) ) {
		cfp_dev_log( 'cfp_dev_get_json: rejected suspicious query path — ' . $queryPath );
		return null;
	}

	$body = cfp_dev_request_cache_get(
		'api:' . $queryPath,
		static function () use ( $queryPath ) {
			return cfp_dev_fetch_json_body( $queryPath );
		}
	);

	if ( ! is_string( $body ) ) {
		return null;
	}

	$decoded = json_decode( $body );

	if ( JSON_ERROR_NONE !== json_last_error() ) {
		cfp_dev_log( 'cfp_dev_get_json: JSON decode error for ' . $queryPath . ' — ' . json_last_error_msg() );
		return null;
	}

	return $decoded;
}

/**
 * Returns the raw JSON body for an API path, from the offline snapshot when
 * offline mode is active and from the live API otherwise.
 *
 * @param string $queryPath  Relative API path (already validated).
 * @return string|null  Raw JSON body, or null when the lookup failed.
 */
function cfp_dev_fetch_json_body( $queryPath ) {
	// Offline mode: serve from local snapshot instead of the live API.
	if ( get_option( 'cfp_dev_offline_mode', 0 ) ) {
		if ( '' !== cfp_dev_get_latest_snapshot() ) {
			// A snapshot exists — stay offline. A null here means this specific
			// resource is not in the snapshot (unknown id, uncrawlable endpoint
			// like public/search): treat it as "not found" rather than falling
			// back to the live API and silently leaving offline mode.
			return cfp_dev_read_snapshot_body( $queryPath );
		}
		// No completed snapshot at all — fall back to live API and disable offline mode.
		cfp_dev_log( 'cfp_dev_get_json: no offline snapshot available, falling back to live API and disabling offline mode.' );
		update_option( 'cfp_dev_offline_mode', 0 );
		cfp_dev_clear_cache();
	}

	if ( '' === cfp_dev_get_key() ) {
		cfp_dev_log( 'cfp_dev_get_json: no CFP.DEV key configured, skipping ' . $queryPath );
		return null;
	}

	$response = wp_remote_get(
		cfp_dev_api_base() . $queryPath,
		[
			'timeout' => 30,
			'headers' => [
				'Accept'     => CFP_DEV_APPLICATION_JSON,
				'Connection' => 'keep-alive',
			],
		]
	);

	if ( is_wp_error( $response ) ) {
		cfp_dev_log( 'cfp_dev_get_json: error for ' . $queryPath . ' — ' . $response->get_error_message() );
		return null;
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $status_code ) {
		cfp_dev_log( 'cfp_dev_get_json: HTTP ' . $status_code . ' for ' . $queryPath );
		return null;
	}

	cfp_dev_log( 'cfp_dev_get_json: OK ' . $queryPath );
	return wp_remote_retrieve_body( $response );
}

/**
 * Queries the semantic search service (search.cfp.dev). Returns an empty
 * array in offline mode — live search needs the external API.
 *
 * @param string $query  Free-text search term.
 * @return array  Result objects sorted by the service, or [] on failure.
 */
function cfp_dev_search_json( $query ) {
	// Offline mode: live search is not available without the API.
	if ( get_option( 'cfp_dev_offline_mode', 0 ) ) {
		return [];
	}

	$safe_query = rawurlencode( sanitize_text_field( $query ) );
	$response   = wp_remote_get( cfp_dev_search_base() . $safe_query, [ 'timeout' => 30 ] );
	if ( is_wp_error( $response ) ) {
		cfp_dev_log( 'cfp_dev_search_json: error — ' . $response->get_error_message() );
		return [];
	}
	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		cfp_dev_log( 'cfp_dev_search_json: HTTP ' . wp_remote_retrieve_response_code( $response ) );
		return [];
	}
	$decoded = json_decode( wp_remote_retrieve_body( $response ) );
	return is_array( $decoded ) ? $decoded : [];
}

/**
 * Formats a UTC time string in the given timezone.
 *
 * @param string       $time      UTC date/time string.
 * @param DateTimeZone $timezone  Target timezone.
 * @param string       $format    date() format string.
 * @return string
 */
function cfp_dev_format_time( $time, $timezone, $format ) {
	$dt = new DateTime( $time, new DateTimeZone( 'UTC' ) );
	$dt->setTimezone( $timezone );
	return $dt->format( $format );
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
	$content  = '<form class="cfp-search" action="' . esc_url( home_url( cfp_dev_url( '/search-results/' ) ) ) . '" method="GET"'
		. ' toolname="search_conference_programme"'
		. ' tooldescription="Searches the conference programme for talks and speakers matching a keyword (e.g. a technology, topic or speaker name)">';
	$content .= '   <input class="cfp-input" id="dev-cfp-search-term" type="search" minlength="3" name="query" placeholder="Full search..." toolparamdescription="Search keyword, minimum 3 characters" autofocus>';
	$content .= '</form>';
	return $content;
}

/**
 * Enqueues the admin scripts (cache management, offline crawler) on the
 * plugin settings page only.
 *
 * @param string $hook  Current admin page hook.
 */
function cfp_dev_enqueue_admin_scripts( $hook ) {
	if ( 'settings_page_cfp-dev-settings' !== $hook ) {
		return;
	}
	wp_enqueue_script( 'cfp-dev-admin-cache', plugins_url( 'js/admin-cache-management.js', __FILE__ ), [ 'jquery' ], CFP_DEV_VERSION, true );
	wp_localize_script(
		'cfp-dev-admin-cache',
		'cfp_dev_ajax',
		[
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'cfp_dev_delete_cache' ),
		]
	);

	$crawl_state = get_option( 'cfp_dev_crawl_state', [] );
	wp_enqueue_script( 'cfp-dev-admin-offline', plugins_url( 'js/admin-offline-crawler.js', __FILE__ ), [ 'jquery' ], CFP_DEV_VERSION, true );
	wp_localize_script(
		'cfp-dev-admin-offline',
		'cfp_dev_offline_ajax',
		[
			'ajaxurl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'cfp_dev_offline_nonce' ),
			'initial_status' => $crawl_state['status'] ?? 'idle',
		]
	);
}
add_action( 'admin_enqueue_scripts', 'cfp_dev_enqueue_admin_scripts' );

/**
 * AJAX: delete a single speaker/talk detail cache (admin cache table).
 * Requires the manage_options capability and a valid nonce.
 */
function cfp_dev_delete_cache_handler() {

	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cfp_dev_delete_cache' ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed.' ] );
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'You do not have permission to perform this action.' ] );
		return;
	}

	if ( ! isset( $_POST['delete_cache'] ) || ! isset( $_POST['cache_id'] ) ) {
		wp_send_json_error( [ 'message' => 'Missing required parameters' ] );
		return;
	}

	$cache_type = sanitize_key( wp_unslash( $_POST['delete_cache'] ) );
	$cache_id   = sanitize_text_field( wp_unslash( $_POST['cache_id'] ) );

	if ( 'speaker' === $cache_type || 'talk' === $cache_type ) {
		// Use the shared key generator — raw string keys silently miss (keys are hashed + versioned).
		$deleted_main  = delete_transient( cfp_dev_detail_cache_key( $cache_type, $cache_id ) );
		$deleted_photo = delete_transient( cfp_dev_detail_cache_key( 'photo', $cache_id ) );

		cfp_dev_log( 'delete_cache: ' . $cache_type . ' transient deleted=' . ( $deleted_main ? 'true' : 'false' ) );
		cfp_dev_log( 'delete_cache: photo transient deleted=' . ( $deleted_photo ? 'true' : 'false' ) );

		wp_send_json_success( [ 'message' => 'Cache deleted for ' . $cache_type . ' with ID: ' . $cache_id ] );
	} else {
		wp_send_json_error( [ 'message' => 'Invalid cache type' ] );
	}
}
add_action( 'wp_ajax_cfp_dev_delete_cache', 'cfp_dev_delete_cache_handler' );
// Note: cache deletion requires manage_options capability; not exposed to non-authenticated users.

/**
 * AJAX: return the current crawl state as JSON (polled by admin-offline-crawler.js).
 */
function cfp_dev_crawl_progress_handler() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cfp_dev_offline_nonce' ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed.' ] );
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
		return;
	}
	$state = get_option( 'cfp_dev_crawl_state', [] );
	wp_send_json_success( $state );
}
add_action( 'wp_ajax_cfp_dev_crawl_progress', 'cfp_dev_crawl_progress_handler' );

/**
 * AJAX: start a new crawl immediately (used by the Re-crawl Now button).
 */
function cfp_dev_start_crawl_ajax_handler() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cfp_dev_offline_nonce' ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed.' ] );
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
		return;
	}
	cfp_dev_start_crawl();
	wp_send_json_success( [ 'message' => 'Crawl started.' ] );
}
add_action( 'wp_ajax_cfp_dev_start_crawl_ajax', 'cfp_dev_start_crawl_ajax_handler' );

/**
 * Registers the rewrite rules for the pretty /speaker/<slug> and /talk/<slug>
 * URLs, honouring the configured URL path prefix.
 */
function cfp_dev_add_rewrite_rules() {
	$prefix = get_option( 'cfp_dev_path_prefix', '' );
	$prefix = $prefix ? $prefix . '/' : '';

	add_rewrite_rule(
		'^' . $prefix . 'speaker/([^/]+)/?$',
		'index.php?pagename=speaker&speaker_slug=$matches[1]',
		'top'
	);

	add_rewrite_rule(
		'^' . $prefix . 'talk/([^/]+)/?$',
		'index.php?pagename=talk&talk_slug=$matches[1]',
		'top'
	);

	add_rewrite_rule(
		'^' . $prefix . 'schedule/?$',
		'index.php?pagename=schedule',
		'top'
	);

	// Handle subdirectory before talk URL with slug (fix for subdirectory redirect)
	if ( ! empty( $prefix ) ) {
		add_rewrite_rule(
			'([^/]+)/' . $prefix . 'talk/([^/]+)/?$',
			'index.php?pagename=talk&talk_slug=$matches[2]',
			'top'
		);
	}
}
add_action( 'init', 'cfp_dev_add_rewrite_rules' );

/**
 * Prepends the configured URL path prefix (e.g. '/trieste') to a plugin path.
 *
 * @param string $path  Site-relative path, e.g. '/talk/my-talk'.
 * @return string
 */
function cfp_dev_url( $path ) {
	$prefix = get_option( 'cfp_dev_path_prefix', '' );
	$prefix = $prefix ? '/' . $prefix : '';
	$url    = $prefix . $path;
	// Match the site's permalink style — un-slashed URLs bounce through redirect_canonical's 301,
	// and canonicals pointing at a redirect form a loop Google reports as "alternate page".
	if ( ! str_contains( $url, '?' ) && ! str_contains( $url, '#' ) ) {
		$url = user_trailingslashit( $url );
	}
	return $url;
}

/**
 * Registers the query vars used by the plugin pages.
 *
 * @param array $vars  Public query vars.
 * @return array
 */
function cfp_dev_add_query_vars( $vars ) {
	$vars[] = 'speaker_slug';
	$vars[] = 'talk_slug';
	$vars[] = 'id';
	$vars[] = 'query';
	return $vars;
}
add_filter( 'query_vars', 'cfp_dev_add_query_vars' );

/** Activation hook: registers rewrite rules and flushes them once. */
function cfp_dev_flush_rewrite_rules() {
	cfp_dev_add_rewrite_rules();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'cfp_dev_flush_rewrite_rules' );

/**
 * Invalidates cached markup after the plugin is updated.
 *
 * Shortcode output is cached as rendered HTML, so a release that changes
 * that HTML would keep serving the previous version's markup until every
 * transient happened to expire — up to a month on the longest TTL.
 */
function cfp_dev_maybe_upgrade() {
	if ( CFP_DEV_VERSION === get_option( 'cfp_dev_installed_version' ) ) {
		return;
	}

	update_option( 'cfp_dev_installed_version', CFP_DEV_VERSION );
	cfp_dev_clear_cache();
	cfp_dev_log( 'upgrade: caches invalidated for version ' . CFP_DEV_VERSION );
}
add_action( 'init', 'cfp_dev_maybe_upgrade' );

/**
 * Fetches one speaker by id from the API (offline-aware).
 *
 * @param int|string $id  Speaker id.
 * @return object|null
 */
function cfp_dev_get_speaker_by_id( $id ) {
	return cfp_dev_get_json( 'public/speakers/' . $id );
}

/**
 * Fetches one talk by id from the API (offline-aware).
 *
 * @param int|string $id  Talk id.
 * @return object|null
 */
function cfp_dev_get_talk_by_id( $id ) {
	return cfp_dev_get_json( 'public/talks/' . $id );
}

/**
 * Resolves a speaker slug to its id by scanning the full speaker list.
 * Hits and misses are transient-cached (misses with a short TTL).
 *
 * @param string $slug  Speaker slug, e.g. 'jane-doe'.
 * @return int|null  Speaker id, or null when the slug is unknown.
 */
function cfp_dev_speaker_id_from_slug( $slug ) {
	$cache_key  = cfp_dev_group_cache_key( 'cfp_speaker_slug_' . md5( $slug ) );
	$speaker_id = get_transient( $cache_key );

	if ( false === $speaker_id ) {
		$speaker_id = 0;
		$speakers   = cfp_dev_get_json( 'public/speakers?size=' . CFP_DEV_SPEAKERS_FETCH_SIZE );
		if ( is_array( $speakers ) ) {
			foreach ( $speakers as $speaker ) {
				$current_slug = cfp_dev_generate_slug( $speaker->firstName . '-' . $speaker->lastName );
				if ( $current_slug === $slug ) {
					$speaker_id = $speaker->id;
					break;
				}
			}
			// Cache misses too (shorter TTL) so unknown slugs don't refetch the full list every hit.
			set_transient( $cache_key, $speaker_id, $speaker_id ? DAY_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		}
	}

	return $speaker_id ? $speaker_id : null;
}

/**
 * Resolves a talk slug to its id by scanning the full talk list.
 * Hits and misses are transient-cached (misses with a short TTL).
 *
 * @param string $slug  Talk slug, e.g. 'my-great-talk'.
 * @return int|null  Talk id, or null when the slug is unknown.
 */
function cfp_dev_talk_id_from_slug( $slug ) {
	$cache_key = cfp_dev_group_cache_key( 'cfp_talk_slug_' . md5( $slug ) );
	$talk_id   = get_transient( $cache_key );

	if ( false === $talk_id ) {
		$talk_id = 0;
		$talks   = cfp_dev_get_json( 'public/talks' );
		if ( is_array( $talks ) ) {
			foreach ( $talks as $talk ) {
				if ( cfp_dev_generate_slug( $talk->title ) === $slug ) {
					$talk_id = $talk->id;
					break;
				}
			}
			set_transient( $cache_key, $talk_id, $talk_id ? DAY_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		}
	}

	return $talk_id ? $talk_id : null;
}

/**
 * Normalises arbitrary text into a URL slug (lowercase, dash-separated).
 *
 * Accented characters are transliterated (Š→s, ü→u) so the result survives
 * WordPress' sanitize_title() on the lookup side — turning them into dashes
 * produced double-dash slugs that sanitize_title() collapses, so speakers
 * with non-ASCII names could never be resolved.
 *
 * @param string $input  Text to slugify, e.g. a speaker name or talk title.
 * @return string
 */
function cfp_dev_generate_slug( $input ) {
	$slug = strtolower( trim( preg_replace( '/[^A-Za-z0-9-]+/', '-', remove_accents( (string) $input ) ), '-' ) );
	return preg_replace( '/-{2,}/', '-', $slug );
}

/**
 * Creates the required shortcode pages on plugin activation.
 * Existing pages (any status) are left untouched.
 */
function cfp_dev_create_required_pages() {
	$pages = [
		'speakers'          => [
			'title'   => 'Speakers',
			'content' => '[cfp_speakers]',
		],
		'speaker'           => [
			'title'   => 'Speaker',
			'content' => '[cfp_speaker_details]',
		],
		'talk'              => [
			'title'   => 'Talks',
			'content' => '[cfp_talk_details]',
		],
		'schedule'          => [
			'title'   => 'Schedule',
			'content' => '[cfp_schedule]',
		],
		'search-results'    => [
			'title'   => 'Search Results',
			'content' => '[cfp_search_results]',
		],
		'talks-by-tracks'   => [
			'title'   => 'Talks by Tracks',
			'content' => '[cfp_talks_by_tracks]',
		],
		'talks-by-sessions' => [
			'title'   => 'Talks by Sessions',
			'content' => '[cfp_talks_by_sessions]',
		],
	];

	foreach ( $pages as $slug => $page_data ) {
		// get_page_by_path also finds drafts/private pages, preventing duplicates.
		if ( null === get_page_by_path( $slug, OBJECT, 'page' ) ) {
			wp_insert_post(
				[
					'post_title'   => $page_data['title'],
					'post_name'    => $slug,
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_content' => $page_data['content'],
				]
			);
		}
	}

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'cfp_dev_create_required_pages' );


/*
 * ── SEO head metadata ─────────────────────────────────────────────
 *
 * Server-side titles, meta descriptions, Open Graph/Twitter tags,
 * canonical URLs and JSON-LD for every plugin page. Detail pages
 * (talk/speaker) resolve the current entity through the same cached,
 * offline-aware helpers the shortcodes use, so metadata keeps working
 * from a local snapshot when offline mode is on.
 *
 * Themes may call add_theme_support( 'cfp-dev-head-meta' ) and render
 * the tags themselves from cfp_dev_page_meta(); the plugin then only
 * contributes the document title, canonical URL and JSON-LD.
 */

/**
 * Collapses whitespace and trims text to a meta-description-sized excerpt.
 *
 * @param string $text    Raw text (may contain HTML).
 * @param int    $length  Maximum length in characters.
 * @return string
 */
function cfp_dev_meta_excerpt( $text, $length = 160 ) {
	$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
	if ( mb_strlen( $text ) <= $length ) {
		return $text;
	}
	$cut   = mb_substr( $text, 0, $length );
	$space = mb_strrpos( $cut, ' ' );
	return ( false !== $space ? mb_substr( $cut, 0, $space ) : $cut ) . '…';
}

/**
 * Fetches a talk/speaker object with a transient cache in front, so the
 * head-meta lookup does not add a second API round-trip on top of the
 * shortcode render (whose own cache stores rendered HTML, not data).
 *
 * @param string $type  'talk' or 'speaker'.
 * @param int    $id    Entity id.
 * @return object|null
 */
function cfp_dev_get_entity_cached( $type, $id ) {
	$ttl = cfp_dev_get_cache_ttl();
	$key = cfp_dev_group_cache_key( 'cfp_entity_' . $type . '_' . md5( (string) $id ) );

	if ( $ttl > 0 ) {
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}
	}

	$data = ( 'talk' === $type ) ? cfp_dev_get_talk_by_id( $id ) : cfp_dev_get_speaker_by_id( $id );

	if ( ! empty( $data ) && $ttl > 0 ) {
		set_transient( $key, $data, $ttl );
	}

	return empty( $data ) ? null : $data;
}

/**
 * Resolves the entity shown on the current request (talk or speaker detail
 * page), once per request. Returns null on all other pages.
 *
 * @return array{type:string,data:object}|null
 */
function cfp_dev_current_entity() {
	return cfp_dev_request_cache_get( 'current_entity', 'cfp_dev_resolve_current_entity' );
}

/**
 * Computes the value memoised by cfp_dev_current_entity().
 *
 * @return array{type:string,data:object}|null
 */
function cfp_dev_resolve_current_entity() {
	if ( is_page( 'talk' ) ) {
		$slug = get_query_var( 'talk_slug' );
		$id   = absint( get_query_var( 'id' ) );
		if ( ! empty( $slug ) ) {
			$id = (int) cfp_dev_talk_id_from_slug( sanitize_title( $slug ) );
		}
		if ( $id ) {
			$talk = cfp_dev_get_entity_cached( 'talk', $id );
			if ( ! empty( $talk->title ) ) {
				return [
					'type' => 'talk',
					'data' => $talk,
				];
			}
		}
	} elseif ( is_page( 'speaker' ) ) {
		$slug = get_query_var( 'speaker_slug' );
		$id   = absint( get_query_var( 'id' ) );
		if ( ! empty( $slug ) ) {
			$id = (int) cfp_dev_speaker_id_from_slug( sanitize_title( $slug ) );
		}
		if ( $id ) {
			$speaker = cfp_dev_get_entity_cached( 'speaker', $id );
			if ( ! empty( $speaker->firstName ) ) {
				return [
					'type' => 'speaker',
					'data' => $speaker,
				];
			}
		}
	}

	return null;
}

/**
 * Meta description for the talks-by-tracks page: names the selected track
 * (?id=N) or lists all track names. Cached per track id.
 *
 * @param string $event_name  Event display name.
 * @return string
 */
function cfp_dev_tracks_meta_description( $event_name ) {
	$track_id = absint( get_query_var( 'id' ) );
	$ttl      = cfp_dev_get_cache_ttl();
	$key      = cfp_dev_group_cache_key( 'cfp_meta_tracks_' . $track_id );

	if ( $ttl > 0 ) {
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}
	}

	$description = 'Browse talks by track at ' . $event_name . '.';
	$tracks      = cfp_dev_get_json( 'public/tracks' );

	if ( is_array( $tracks ) && ! empty( $tracks ) ) {
		if ( $track_id ) {
			foreach ( $tracks as $track ) {
				if ( absint( $track->id ) === $track_id ) {
					$track_descr = cfp_dev_meta_excerpt( $track->description ?? '', 110 );
					$description = wp_strip_all_tags( $track->name ) . ' talks at ' . $event_name
						. ( '' !== $track_descr ? ' — ' . $track_descr : '.' );
					break;
				}
			}
		} else {
			$names       = array_map(
				static function ( $track ) {
					return wp_strip_all_tags( $track->name );
				},
				$tracks
			);
			$description = cfp_dev_meta_excerpt(
				'Browse talks by track at ' . $event_name . ': ' . implode( ', ', $names ) . '.'
			);
		}
	}

	if ( $ttl > 0 ) {
		set_transient( $key, $description, $ttl );
	}

	return $description;
}

/**
 * Meta description for the talks-by-sessions page: names the selected
 * session type (?id=N) or lists all non-pause session types. Cached per id.
 *
 * @param string $event_name  Event display name.
 * @return string
 */
function cfp_dev_sessions_meta_description( $event_name ) {
	$session_id = absint( get_query_var( 'id' ) );
	$ttl        = cfp_dev_get_cache_ttl();
	$key        = cfp_dev_group_cache_key( 'cfp_meta_sessions_' . $session_id );

	if ( $ttl > 0 ) {
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}
	}

	$description = 'Browse talks by session type at ' . $event_name . '.';
	$sessions    = cfp_dev_get_json( 'public/session-types' );

	if ( is_array( $sessions ) && ! empty( $sessions ) ) {
		if ( $session_id ) {
			foreach ( $sessions as $session ) {
				if ( absint( $session->id ) === $session_id ) {
					$session_descr = cfp_dev_meta_excerpt( $session->description ?? '', 110 );
					$description   = wp_strip_all_tags( $session->name ) . ' sessions at ' . $event_name
						. ( '' !== $session_descr ? ' — ' . $session_descr : '.' );
					break;
				}
			}
		} else {
			$names = [];
			foreach ( $sessions as $session ) {
				if ( empty( $session->pause ) ) {
					$names[] = wp_strip_all_tags( $session->name );
				}
			}
			// Events may define several session types with the same display
			// name (e.g. three "Keynote" slots) — list each name once.
			$names = array_unique( $names );
			if ( ! empty( $names ) ) {
				$description = cfp_dev_meta_excerpt(
					'Browse talks by session type at ' . $event_name . ': ' . implode( ', ', $names ) . '.'
				);
			}
		}
	}

	if ( $ttl > 0 ) {
		set_transient( $key, $description, $ttl );
	}

	return $description;
}

/**
 * Page metadata for the current request, or null when the current page is
 * not one of the plugin's pages. Computed once per request.
 *
 * @return array{title:string,description:string,url:string,image:string,og_type:string}|null
 */
function cfp_dev_page_meta() {
	return cfp_dev_request_cache_get( 'page_meta', 'cfp_dev_resolve_page_meta' );
}

/**
 * Computes the value memoised by cfp_dev_page_meta().
 *
 * @return array{title:string,description:string,url:string,image:string,og_type:string}|null
 */
function cfp_dev_resolve_page_meta() {
	if ( ! is_page( [ 'talk', 'speaker', 'speakers', 'schedule', 'talks-by-tracks', 'talks-by-sessions', 'search-results' ] ) ) {
		return null;
	}

	$event_name = cfp_dev_get_event_name();
	$entity     = cfp_dev_current_entity();

	if ( $entity && 'talk' === $entity['type'] ) {
		$talk        = $entity['data'];
		$title       = wp_strip_all_tags( $talk->title );
		$description = cfp_dev_meta_excerpt( $talk->description ?? '' );
		if ( '' === $description ) {
			$description = $title . ' — a ' . wp_strip_all_tags( $talk->sessionTypeName ?? 'session' ) . ' at ' . $event_name . '.';
		}
		return [
			'title'       => $title . ' - ' . $event_name,
			'description' => $description,
			'url'         => home_url( cfp_dev_url( '/talk/' . cfp_dev_generate_slug( $talk->title ) ) ),
			'image'       => cfp_dev_usable_image( $talk->trackImageURL ?? '' ),
			'og_type'     => 'article',
		];
	}

	if ( $entity && 'speaker' === $entity['type'] ) {
		$speaker     = $entity['data'];
		$name        = trim( $speaker->firstName . ' ' . $speaker->lastName );
		$description = cfp_dev_meta_excerpt( $speaker->bio ?? '' );
		if ( '' === $description ) {
			$description = $name
				. ( ! empty( $speaker->company ) ? ' (' . wp_strip_all_tags( $speaker->company ) . ')' : '' )
				. ' speaks at ' . $event_name . '.';
		}
		return [
			'title'       => $name . ' - ' . $event_name,
			'description' => $description,
			'url'         => home_url( cfp_dev_url( '/speaker/' . cfp_dev_generate_slug( $speaker->firstName . '-' . $speaker->lastName ) ) ),
			'image'       => (string) ( $speaker->imageUrl ?? '' ),
			'og_type'     => 'profile',
		];
	}

	$description = '';
	if ( is_page( 'speakers' ) ) {
		$description = 'Browse our lineup of expert speakers at ' . $event_name . '.';
	} elseif ( is_page( 'schedule' ) ) {
		$description = 'View the full conference schedule for ' . $event_name . ' — sessions, times, rooms and speakers.';
	} elseif ( is_page( 'talks-by-tracks' ) ) {
		$description = cfp_dev_tracks_meta_description( $event_name );
	} elseif ( is_page( 'talks-by-sessions' ) ) {
		$description = cfp_dev_sessions_meta_description( $event_name );
	} elseif ( is_page( 'search-results' ) ) {
		// The query var is registered by cfp_dev_add_query_vars(), so this is
		// the same value the shortcode renders — no need to touch $_GET.
		$query_val   = sanitize_text_field( (string) get_query_var( 'query' ) );
		$description = '' !== $query_val
			? 'Search results for “' . $query_val . '” at ' . $event_name . '.'
			: 'Search talks and speakers at ' . $event_name . '.';
	}

	return [
		'title'       => '', // Empty: keep the WordPress-generated page title.
		'description' => $description,
		'url'         => (string) get_permalink(),
		'image'       => '',
		'og_type'     => 'website',
	];
}

/**
 * Server-side document title for talk/speaker detail pages.
 *
 * @param string $title  Pre-computed title (empty by default).
 * @return string
 */
function cfp_dev_document_title( $title ) {
	$meta = cfp_dev_page_meta();
	if ( $meta && ! empty( $meta['title'] ) ) {
		return $meta['title'];
	}
	return $title;
}
add_filter( 'pre_get_document_title', 'cfp_dev_document_title', 20 );

/**
 * Slug-aware canonical URL — without this every talk/speaker canonicalizes
 * to the bare /talk/ or /speaker/ page.
 *
 * @param string  $canonical_url  Default canonical URL.
 * @param WP_Post $post           Queried post.
 * @return string
 */
function cfp_dev_canonical_url( $canonical_url, $post ) {
	unset( $post );
	$meta = cfp_dev_page_meta();
	if ( $meta && ! empty( $meta['url'] ) ) {
		return $meta['url'];
	}
	return $canonical_url;
}
add_filter( 'get_canonical_url', 'cfp_dev_canonical_url', 10, 2 );

/**
 * Emits description/Open Graph/Twitter tags in <head> for plugin pages.
 * Skipped entirely when the active theme declares
 * add_theme_support( 'cfp-dev-head-meta' ) and renders the tags itself.
 */
function cfp_dev_output_head_meta() {
	$meta = cfp_dev_page_meta();
	if ( empty( $meta ) || current_theme_supports( 'cfp-dev-head-meta' ) ) {
		return;
	}

	$title = ! empty( $meta['title'] ) ? $meta['title'] : wp_get_document_title();

	echo "\n";
	if ( ! empty( $meta['description'] ) ) {
		echo '<meta name="description" content="' . esc_attr( $meta['description'] ) . '">' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( $meta['description'] ) . '">' . "\n";
		echo '<meta name="twitter:description" content="' . esc_attr( $meta['description'] ) . '">' . "\n";
	}
	echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
	echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
	echo '<meta property="og:type" content="' . esc_attr( $meta['og_type'] ) . '">' . "\n";
	if ( ! empty( $meta['url'] ) ) {
		echo '<meta property="og:url" content="' . esc_url( $meta['url'] ) . '">' . "\n";
	}
	if ( ! empty( $meta['image'] ) ) {
		echo '<meta property="og:image" content="' . esc_url( $meta['image'] ) . '">' . "\n";
		echo '<meta name="twitter:image" content="' . esc_url( $meta['image'] ) . '">' . "\n";
		echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
	} else {
		echo '<meta name="twitter:card" content="summary">' . "\n";
	}
}
add_action( 'wp_head', 'cfp_dev_output_head_meta', 2 );

/**
 * JSON-LD structured data for talk (Event) and speaker (Person) pages.
 * Emitted regardless of theme support — themes only render generic meta.
 */
function cfp_dev_output_jsonld() {
	if ( ! is_page( [ 'talk', 'speaker' ] ) ) {
		return;
	}

	$entity = cfp_dev_current_entity();
	if ( empty( $entity ) ) {
		return;
	}
	$meta = cfp_dev_page_meta();

	if ( 'speaker' === $entity['type'] ) {
		$speaker = $entity['data'];
		$schema  = [
			'@context' => 'https://schema.org',
			'@type'    => 'Person',
			'name'     => trim( $speaker->firstName . ' ' . $speaker->lastName ),
			'url'      => $meta['url'],
		];
		if ( ! empty( $speaker->company ) ) {
			$schema['worksFor'] = [
				'@type' => 'Organization',
				'name'  => wp_strip_all_tags( $speaker->company ),
			];
		}
		if ( ! empty( $speaker->imageUrl ) ) {
			$schema['image'] = esc_url_raw( $speaker->imageUrl );
		}
	} else {
		$talk   = $entity['data'];
		$schema = [
			'@context'            => 'https://schema.org',
			'@type'               => 'Event',
			'name'                => wp_strip_all_tags( $talk->title ),
			'url'                 => $meta['url'],
			'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
			'superEvent'          => [
				'@type' => 'Event',
				'name'  => cfp_dev_get_event_name(),
			],
		];
		if ( ! empty( $talk->speakers ) && is_array( $talk->speakers ) ) {
			$performers = [];
			foreach ( $talk->speakers as $speaker ) {
				$performers[] = [
					'@type' => 'Person',
					'name'  => trim( ( $speaker->firstName ?? '' ) . ' ' . ( $speaker->lastName ?? '' ) ),
				];
			}
			$schema['performer'] = $performers;
		}
		if ( ! empty( $talk->timeSlots ) && is_array( $talk->timeSlots ) ) {
			$slot = end( $talk->timeSlots );
			if ( ! empty( $slot->fromDate ) ) {
				$schema['startDate'] = $slot->fromDate;
			}
			if ( ! empty( $slot->toDate ) ) {
				$schema['endDate'] = $slot->toDate;
			}
			if ( ! empty( $slot->roomName ) ) {
				$schema['location'] = [
					'@type' => 'Place',
					'name'  => wp_strip_all_tags( $slot->roomName ),
				];
			}
		}
	}

	if ( ! empty( $meta['description'] ) ) {
		$schema['description'] = $meta['description'];
	}

	echo '<script type="application/ld+json">'
		. wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		. '</script>' . "\n";
}
add_action( 'wp_head', 'cfp_dev_output_jsonld', 3 );

/**
 * Rejects image URLs that are unusable for social cards — cfp.dev track
 * images are often tiny Google-cache thumbnails (~90px), which produce
 * blurry share previews. Returning '' lets pages fall back to the site's
 * default social image.
 *
 * @param string $url  Candidate image URL.
 * @return string
 */
function cfp_dev_usable_image( $url ) {
	$url = (string) $url;
	if ( '' === $url || str_contains( $url, 'gstatic.com/images' ) ) {
		return '';
	}
	return $url;
}

/**
 * Internal search result pages should not be indexed (Google guideline) —
 * they generate unbounded thin/duplicate content.
 *
 * @param array $robots  Directives for wp_robots().
 * @return array
 */
function cfp_dev_robots( $robots ) {
	if ( is_page( 'search-results' ) ) {
		unset( $robots['index'] );
		$robots['noindex'] = true;
		$robots['follow']  = true;
	}
	return $robots;
}
add_filter( 'wp_robots', 'cfp_dev_robots' );

/**
 * Serves a real 404 when a talk/speaker detail request cannot be resolved
 * (removed entities, legacy pre-4.3.4 accent slugs, bare /talk/ or /speaker/
 * without parameters). These rendered "not found" text with HTTP 200, which
 * Search Console flags as soft 404s.
 */
function cfp_dev_404_unresolved_detail() {
	if ( ! is_page( [ 'talk', 'speaker' ] ) || null !== cfp_dev_current_entity() ) {
		return;
	}
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
}
add_action( 'template_redirect', 'cfp_dev_404_unresolved_detail' );

/*
 * ── XML sitemap ───────────────────────────────────────────────────
 * WordPress only lists its own pages — the talk and speaker URLs are
 * rendered from API data and invisible to wp-sitemap.xml. The provider
 * (shortcode/include/class-cfp-dev-sitemaps-provider.php) adds them
 * (slug mode only), using the same cached, offline-aware fetches as
 * the shortcodes.
 */

/**
 * All talk + speaker URLs for the sitemap, transient-cached.
 *
 * @return array[]
 */
function cfp_dev_sitemap_urls() {
	return cfp_dev_request_cache_get( 'sitemap_urls', 'cfp_dev_resolve_sitemap_urls' );
}

/**
 * Computes the value memoised by cfp_dev_sitemap_urls(), with a transient
 * cache in front of the two API list calls.
 *
 * @return array[]
 */
function cfp_dev_resolve_sitemap_urls() {
	$ttl = cfp_dev_get_cache_ttl();
	$key = cfp_dev_group_cache_key( 'cfp_sitemap_urls' );

	if ( $ttl > 0 ) {
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}
	}

	$entries = [];

	$talks = cfp_dev_get_json( 'public/talks' );
	if ( is_array( $talks ) ) {
		foreach ( $talks as $talk ) {
			if ( ! empty( $talk->title ) ) {
				$entries[ '/talk/' . cfp_dev_generate_slug( $talk->title ) ] = true;
			}
		}
	}

	$speakers = cfp_dev_get_json( 'public/speakers?size=' . CFP_DEV_SPEAKERS_FETCH_SIZE );
	if ( is_array( $speakers ) ) {
		foreach ( $speakers as $speaker ) {
			if ( ! empty( $speaker->firstName ) ) {
				$entries[ '/speaker/' . cfp_dev_generate_slug( $speaker->firstName . '-' . $speaker->lastName ) ] = true;
			}
		}
	}

	$urls = [];
	foreach ( array_keys( $entries ) as $path ) {
		$urls[] = [ 'loc' => home_url( cfp_dev_url( $path ) ) ];
	}

	if ( $ttl > 0 && ! empty( $urls ) ) {
		set_transient( $key, $urls, $ttl );
	}

	return $urls;
}

/**
 * Registers the sitemap provider (slug-mode sites on WP 5.5+ only).
 *
 * @param WP_Sitemaps $sitemaps  Core sitemaps server.
 */
function cfp_dev_register_sitemap_provider( $sitemaps ) {
	if ( 'no' !== get_option( 'cfp_dev_content_by_id', 'yes' ) ) {
		return;
	}
	if ( ! class_exists( 'CFP_Dev_Sitemaps_Provider' ) ) {
		return;
	}
	$sitemaps->registry->add_provider( 'cfp', new CFP_Dev_Sitemaps_Provider() );
}
add_action( 'wp_sitemaps_init', 'cfp_dev_register_sitemap_provider' );

/**
 * Renders the social-link icons (LinkedIn, Bluesky, Mastodon, X/Twitter) for
 * a speaker. Returns an empty string when no handle is set.
 *
 * @param object $speaker  Speaker object from the API.
 * @return string
 */
function cfp_dev_social_links( $speaker ) {
	$content = '';
	if ( ! empty( $speaker->twitterHandle ) ||
		! empty( $speaker->linkedInUsername ) ||
		! empty( $speaker->blueskyUsername ) ||
		! empty( $speaker->mastodonUsername ) ) {
		$content .= '<nav class="cfp-social">';
		if ( ! empty( $speaker->linkedInUsername ) ) {
			$content .= '<a class="cfp-a cfp-linkedIn" href="' . esc_url( 'https://www.linkedin.com/in/' . $speaker->linkedInUsername ) . '" target="_blank" rel="noopener noreferrer"></a>';
		}
		if ( ! empty( $speaker->blueskyUsername ) ) {
			$content .= '<a class="cfp-a cfp-bluesky" href="' . esc_url( 'https://bsky.app/profile/' . $speaker->blueskyUsername ) . '" target="_blank" rel="noopener noreferrer"></a>';
		}
		if ( ! empty( $speaker->mastodonUsername ) ) {
			// Full URL from the API — esc_url() also neutralises javascript: URIs (esc_attr does not).
			$content .= '<a class="cfp-a cfp-mastodon" href="' . esc_url( $speaker->mastodonUsername ) . '" target="_blank" rel="noopener noreferrer"></a>';
		}
		if ( ! empty( $speaker->twitterHandle ) ) {
			$content .= '<a class="cfp-a cfp-twitter" href="' . esc_url( 'https://x.com/' . $speaker->twitterHandle ) . '" target="_blank" rel="noopener noreferrer"></a>';
		}
		$content .= '</nav>';
	}
	return $content;
}
