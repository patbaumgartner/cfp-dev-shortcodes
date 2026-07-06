<?php
/**
 * Plugin Name:       CFP.DEV shortcodes
 * Plugin URI:        https://github.com/patbaumgartner/cfp-dev-shortcodes
 * Description:       Display CFP.DEV conference content on your WordPress site: speakers, talks, schedule, and search — with light/dark theming, caching, and offline mode.
 * Version:           4.2.3
 * Author:            Stephan Janssen, Patrick Baumgartner
 * Author URI:        https://x.com/stephan007
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
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
	define( 'CFP_DEV_VERSION', '4.2.3' );
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
	define( 'CFP_DEV_CSS', 'css/cfp_dev_v4_0_1.css' );
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
 * @param string $message
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

function cfp_dev_api_base(): string {
	return 'https://' . rawurlencode( cfp_dev_get_key() ) . '.cfp.dev/api/';
}

function cfp_dev_search_base(): string {
	return 'https://search.cfp.dev?cfp=' . rawurlencode( cfp_dev_get_key() ) . '&accepted=true&total=5&query=';
}

/**
 * Cache versioning.
 *
 * Every transient key is suffixed with the current cache version, so a full
 * cache flush is a single O(1) option increment — no API calls, no key
 * enumeration. Superseded transients simply expire via their TTL.
 */
function cfp_dev_cache_salt(): string {
	return '_v' . (int) get_option( 'cfp_dev_cache_version', 1 );
}

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
 * Emits the inline script that swaps the cfp-* classes on the root element.
 * Shared by every shortcode (was duplicated six times).
 *
 * @param string $page  Page key, e.g. 'speaker', 'schedule', 'session', 'search'.
 * @param string $view  Optional view key, e.g. 'detail'.
 */
function cfp_dev_root_class_script( string $page, string $view = '' ): string {
	$classes = [ 'cfp-html', 'cfp-page:' . $page, 'cfp-theme:' . get_option( 'cfp_dev_default_theme', 'dark' ) ];
	if ( '' !== $view ) {
		$classes[] = 'cfp-view:' . $view;
	}

	return '<script>(function () {'
		. 'var root = document.documentElement;'
		. 'Array.prototype.slice.call(root.classList).forEach(function (c) { if (0 === c.indexOf("cfp-")) { root.classList.remove(c); } });'
		. wp_json_encode( $classes ) . '.forEach(function (c) { root.classList.add(c); });'
		. '})();</script>';
}

// Load the offline crawler and all shortcode modules.
$cfp_dev_modules = [
	'shortcode/include/offline-crawler.php',
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
 * Enqueues the front-end script and stylesheet shared by all shortcodes.
 */
function cfp_ajax_load_scripts() {
	wp_enqueue_script( 'site-cfp', plugin_dir_url( __FILE__ ) . 'js/site.js', [ 'jquery' ], CFP_DEV_VERSION, true );
	wp_enqueue_style( 'cfp-dev-style', plugin_dir_url( __FILE__ ) . 'shortcode/' . CFP_DEV_CSS, [], CFP_DEV_VERSION );
}

add_action( 'wp_enqueue_scripts', 'cfp_ajax_load_scripts' );

/**
 * Registers the Settings → CFP.DEV admin page.
 */
function cfp_dev_plugin_menu() {
	add_options_page( 'CFP.DEV Settings', 'CFP.DEV', 'manage_options', 'cfp-dev-settings', 'cfp_dev_plugin_options' );
}

add_action( 'admin_menu', 'cfp_dev_plugin_menu' );

/** Renders the CFP.DEV settings page. */
function cfp_dev_plugin_options() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'cfp-dev-shortcodes' ) );
	}

	// Verify nonce for any POST action on this page.
	if ( ! empty( $_POST ) && ( ! isset( $_POST['cfp_dev_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cfp_dev_nonce'] ) ), 'cfp_dev_options' ) ) ) {
		wp_die( esc_html__( 'Security check failed.', 'cfp-dev-shortcodes' ) );
	}

	$hidden_field_name = 'cfp_dev_clear_cache';

	if ( isset( $_POST[ $hidden_field_name ] ) && 'Y' === $_POST[ $hidden_field_name ] ) {
		clearCache();
	}

	if ( isset( $_POST['cfp_dev_key'] ) ) {
		storeCfpDevKey( sanitize_text_field( wp_unslash( $_POST['cfp_dev_key'] ) ) );
		clearCache();
	}

	if ( isset( $_POST['cfp_dev_event_name'] ) ) {
		storeCfpDevEventName( sanitize_text_field( wp_unslash( $_POST['cfp_dev_event_name'] ) ) );
		clearCache();
	}

	if ( isset( $_POST['cfp_dev_cache'] ) ) {
		storeCfpDevCache( sanitize_text_field( wp_unslash( $_POST['cfp_dev_cache'] ) ) );
		clearCache();
	}

	if ( isset( $_POST['cfp_dev_default_theme'] ) ) {
		update_option( 'cfp_dev_default_theme', sanitize_text_field( wp_unslash( $_POST['cfp_dev_default_theme'] ) ) );
	}

	// Checkbox: only present in POST when checked — key off the main form marker so unchecking persists too.
	if ( isset( $_POST['cfp_dev_key'] ) ) {
		update_option( 'enable_theme_switch', isset( $_POST['enable_theme_switch'] ) ? 1 : 0 );
	}

	if ( isset( $_POST['cfp_dev_path_prefix'] ) ) {
		$new_prefix = sanitize_text_field( wp_unslash( $_POST['cfp_dev_path_prefix'] ) );
		if ( get_option( 'cfp_dev_path_prefix', '' ) !== $new_prefix ) {
			update_option( 'cfp_dev_path_prefix', $new_prefix );
			// Rewrite rules embed the prefix — rebuild them right away.
			cfp_dev_add_rewrite_rules();
			flush_rewrite_rules();
		}
	}

	if ( isset( $_POST['cfp_dev_content_by_id'] ) ) {
		update_option( 'cfp_dev_content_by_id', sanitize_text_field( wp_unslash( $_POST['cfp_dev_content_by_id'] ) ) );
	}

	if ( isset( $_POST['cfp_dev_show_rooms'] ) ) {
		update_option( 'cfp_dev_show_rooms', sanitize_text_field( wp_unslash( $_POST['cfp_dev_show_rooms'] ) ) );
	}

	// Handle offline mode form submission.
	if ( isset( $_POST['cfp_dev_offline_mode_save'] ) ) {
		$new_offline  = isset( $_POST['cfp_dev_offline_mode'] ) ? 1 : 0;
		$old_offline  = get_option( 'cfp_dev_offline_mode', 0 );
		$crawl_status = ( get_option( 'cfp_dev_crawl_state', [] )['status'] ) ?? 'idle';

		if ( 0 === $new_offline ) {
			// Unchecked → disable offline mode (keep snapshot data).
			update_option( 'cfp_dev_offline_mode', 0 );
		} elseif ( 1 === $new_offline && 0 === (int) $old_offline && ! in_array( $crawl_status, [ 'running', 'pending' ], true ) ) {
			// Newly checked and no crawl already running → start a fresh crawl.
			// Offline mode is activated automatically when the crawl completes.
			cfp_dev_start_crawl();
		}
	}

	// Process cache deletion BEFORE rendering the tables so the page reflects the new state.
	$cache_notice = '';
	if ( isset( $_POST['delete_cache'] ) ) {
		$cache_type = sanitize_key( wp_unslash( $_POST['delete_cache'] ) );

		switch ( $cache_type ) {
			case 'speakers':
				delete_transient( cfp_dev_speakers_cache_key( cfp_dev_speakers_default_atts() ) );
				$cache_notice = 'Speakers cache deleted.';
				break;
			case 'schedule':
				if ( isset( $_POST['cache_day'] ) ) {
					$day = sanitize_key( wp_unslash( $_POST['cache_day'] ) );
					if ( in_array( $day, [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ], true ) ) {
						delete_transient( cfp_dev_group_cache_key( 'cfp_schedule_' . ucfirst( $day ) ) );
						$cache_notice = 'Schedule cache for ' . ucfirst( $day ) . ' deleted.';
					}
				}
				break;
			case 'speaker':
			case 'talk':
				if ( isset( $_POST['cache_id'] ) ) {
					$cache_id = sanitize_text_field( wp_unslash( $_POST['cache_id'] ) );

					// Delete speaker or talk cache.
					delete_transient( generate_cfp_cache_key( $cache_type, $cache_id ) );

					// Delete photo speaker cache.
					delete_transient( generate_cfp_cache_key( 'photo', $cache_id ) );

					$cache_notice = 'Cache deleted for ' . $cache_type . ' with ID: ' . $cache_id . ' (including any photo cache).';
				}
				break;
		}
	}

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
			  <strong>Must be "Yes" for multisite worpdress installs.</strong>
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
			<td><input type="checkbox" name="enable_theme_switch" value="1" ' . checked( 1, get_option( 'enable_theme_switch' ), false ) . ' /></td>
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
	$speakers             = getJSON( 'public/speakers?size=' . CFP_DEV_SPEAKERS_FETCH_SIZE );
	$speaker_caches_exist = false;

	if ( is_array( $speakers ) || is_object( $speakers ) ) {
		echo '<table class="wp-list-table widefat fixed striped">
			<thead><tr><th>Speaker ID</th><th>Name</th><th>Action</th></tr></thead>
			<tbody>';

		foreach ( $speakers as $speaker ) {
			$transient_key = generate_cfp_cache_key( 'speaker', $speaker->id );
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
	$talks             = getJSON( 'public/talks' );
	$talk_caches_exist = false;

	if ( is_array( $talks ) || is_object( $talks ) ) {
		echo '<table class="wp-list-table widefat fixed striped">
				<thead><tr><th>Talk ID</th><th>Title</th><th>Action</th></tr></thead>
				<tbody>';

		foreach ( $talks as $talk ) {
			$transient_key = generate_cfp_cache_key( 'talk', $talk->id );
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

	echo '</div>'; // Close the wrap div

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

	echo '</div>'; // Close offline section wrap
}

function generate_cfp_cache_key( $type, $id ) {
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

function storeCfpDevKey( $key ) {
	// The key is a cfp.dev subdomain — restrict to safe hostname characters so it
	// cannot alter the API URL (e.g. via dots or slashes).
	$key = strtolower( preg_replace( '/[^A-Za-z0-9-]/', '', (string) $key ) );
	update_option( 'cfp_dev_key', $key );
	delete_transient( 'CFP_DEV_KEY' ); // Legacy storage location.
}

function storeCfpDevCache( $ttl ) {
	update_option( 'cfp_dev_cache_duration', max( 0, (int) $ttl ) );
	delete_transient( 'CFP_DEV_CACHE' ); // Legacy storage location.
}

function storeCfpDevEventName( $cfpDevEventName ) {
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
function clearCache() {
	update_option( 'cfp_dev_cache_version', (int) get_option( 'cfp_dev_cache_version', 1 ) + 1 );
	cfp_dev_log( 'clearCache: cache version bumped to ' . get_option( 'cfp_dev_cache_version' ) );
}

/**
 * Theme-switch footer (empty string when switching is disabled).
 */
function getFooter() {
	if ( get_option( 'enable_theme_switch', false ) ) {
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

function embedSocialSpeakerCard( $speaker ) {
	$content = '<meta name="twitter:card" content="summary_large_image">';
	if ( ! empty( $speaker->twitterHandle ) ) {
		$content .= '<meta name="twitter:site" content="' . esc_attr( $speaker->twitterHandle ) . '">';
	}

	if ( ! empty( $speaker->imageUrl ) ) {
		$content .= '<meta name="twitter:image" content="' . esc_url( $speaker->imageUrl ) . '">';
	}
	$speakerInfo = $speaker->firstName . ' ' . $speaker->lastName . ' at ' . cfp_dev_get_event_name();
	// Strip tags BEFORE truncating so we never cut inside an HTML tag; mb-safe.
	$description = mb_substr( wp_strip_all_tags( (string) ( $speaker->bio ?? '' ) ), 0, 260 );
	$content    .= '<meta property="og:title" content="' . esc_attr( $speakerInfo ) . '">';
	$content    .= '<meta name="twitter:title" content="' . esc_attr( $speakerInfo ) . '">';
	$content    .= '<meta name="twitter:description" content="' . esc_attr( $description ) . '">';

	return $content;
}

function embedSocialTalkCard( $talk ) {
	$title       = wp_strip_all_tags( $talk->title ) . ' at ' . cfp_dev_get_event_name();
	$description = mb_substr( wp_strip_all_tags( (string) ( $talk->description ?? '' ) ), 0, 260 );

	$content  = '<meta name="twitter:card" content="summary">';
	$content .= '<meta name="twitter:image" content="' . esc_url( $talk->trackImageURL ) . '">';
	$content .= '<meta property="og:title" content="' . esc_attr( $title ) . '">';
	$content .= '<meta property="og:url" content="' . esc_url( 'https://' . cfp_dev_get_key() . '.cfp.dev/talk?id=' . absint( $talk->id ) ) . '">';
	$content .= '<meta name="twitter:title" content="' . esc_attr( $title ) . '">';
	$content .= '<meta name="twitter:description" content="' . esc_attr( $description ) . '">';

	return $content;
}

function compareLastName( $x, $y ) {
	return iconv( 'utf-8', 'ascii//TRANSLIT', $x->lastName ) <=> iconv( 'utf-8', 'ascii//TRANSLIT', $y->lastName );
}

function compareName( $x, $y ) {
	return iconv( 'utf-8', 'ascii//TRANSLIT', $x->name ) <=> iconv( 'utf-8', 'ascii//TRANSLIT', $y->name );
}

function getJSON( $queryPath ) {
	// Reject path traversal — query paths are relative API routes and several
	// callers interpolate user-supplied ids (also guards offline file lookups).
	if ( str_contains( $queryPath, '..' ) || str_starts_with( $queryPath, '/' ) ) {
		cfp_dev_log( 'getJSON: rejected suspicious query path: ' . $queryPath );
		return null;
	}

	// Offline mode: serve from local snapshot instead of the live API.
	if ( get_option( 'cfp_dev_offline_mode', 0 ) ) {
		$offline_result = cfp_dev_get_json_offline( $queryPath );
		if ( null !== $offline_result ) {
			return $offline_result;
		}
		// Snapshot missing or incomplete — fall back to live API and disable offline mode.
		cfp_dev_log( 'getJSON: offline snapshot unavailable for ' . $queryPath . ', falling back to live API and disabling offline mode.' );
		update_option( 'cfp_dev_offline_mode', 0 );
	}

	$query_url = cfp_dev_api_base() . $queryPath;

	$response = wp_remote_get(
		$query_url,
		[
			'timeout' => 30,
			'headers' => [
				'Accept'     => CFP_DEV_APPLICATION_JSON,
				'Connection' => 'keep-alive',
			],
		]
	);

	if ( is_wp_error( $response ) ) {
		cfp_dev_log( 'getJSON error for ' . $queryPath . ': ' . $response->get_error_message() );
		return null;
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $status_code ) {
		cfp_dev_log( 'getJSON returned HTTP ' . $status_code . ' for: ' . $queryPath );
		return null;
	}

	$body    = wp_remote_retrieve_body( $response );
	$decoded = json_decode( $body );

	if ( json_last_error() !== JSON_ERROR_NONE ) {
		cfp_dev_log( 'getJSON JSON decode error for ' . $queryPath . ': ' . json_last_error_msg() );
		return null;
	}

	cfp_dev_log( 'getJSON OK: ' . $queryPath );
	return $decoded;
}

function searchJSON( $query ) {
	// Offline mode: live search is not available without the API.
	if ( get_option( 'cfp_dev_offline_mode', 0 ) ) {
		return [];
	}

	$safe_query = rawurlencode( sanitize_text_field( $query ) );
	$response   = wp_remote_get( cfp_dev_search_base() . $safe_query, [ 'timeout' => 30 ] );
	if ( is_wp_error( $response ) ) {
		cfp_dev_log( 'searchJSON error: ' . $response->get_error_message() );
		return [];
	}
	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		cfp_dev_log( 'searchJSON returned HTTP ' . wp_remote_retrieve_response_code( $response ) );
		return [];
	}
	$decoded = json_decode( wp_remote_retrieve_body( $response ) );
	return is_array( $decoded ) ? $decoded : [];
}

function getTime( $time, $timezone, $format ) {
	$dt = new DateTime( $time, new DateTimeZone( 'UTC' ) );
	$dt->setTimezone( $timezone );
	return $dt->format( $format );
}

function getSearchForm() {
	// Absolute action URL — a relative one resolves against the current path
	// (e.g. /talks-by-tracks/search-results) and 404s.
	$content  = '<form class="cfp-search" action="' . esc_url( home_url( cfp_dev_url( '/search-results/' ) ) ) . '" method="GET">';
	$content .= '   <input class="cfp-input" id="dev-cfp-search-term" type="search" minlength="3" name="query" placeholder="Full search..." autofocus>';
	$content .= '</form>';
	return $content;
}

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

function cfp_dev_delete_cache_handler() {

	// Check for nonce for security
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
		$deleted_main  = delete_transient( generate_cfp_cache_key( $cache_type, $cache_id ) );
		$deleted_photo = delete_transient( generate_cfp_cache_key( 'photo', $cache_id ) );

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

function cfp_dev_add_rewrite_rules() {
	$prefix = get_option( 'cfp_dev_path_prefix', '' );
	$prefix = $prefix ? $prefix . '/' : '';

	// Handle speaker URL with slug
	add_rewrite_rule(
		'^' . $prefix . 'speaker/([^/]+)/?$',
		'index.php?pagename=speaker&speaker_slug=$matches[1]',
		'top'
	);

	// Handle talk URL with slug
	add_rewrite_rule(
		'^' . $prefix . 'talk/([^/]+)/?$',
		'index.php?pagename=talk&talk_slug=$matches[1]',
		'top'
	);

	// Handle schedule URL
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

function cfp_dev_url( $path ) {
	$prefix = get_option( 'cfp_dev_path_prefix', '' );
	$prefix = $prefix ? '/' . $prefix : '';
	return $prefix . $path;
}

function cfp_dev_add_query_vars( $vars ) {
	$vars[] = 'speaker_slug';
	$vars[] = 'talk_slug';
	$vars[] = 'id';
	$vars[] = 'query';
	return $vars;
}
add_filter( 'query_vars', 'cfp_dev_add_query_vars' );

function cfp_dev_flush_rewrite_rules() {
	cfp_dev_add_rewrite_rules();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'cfp_dev_flush_rewrite_rules' );

function get_speaker_by_id( $id ) {
	return getJSON( 'public/speakers/' . $id );
}

function get_talk_by_id( $id ) {
	return getJSON( 'public/talks/' . $id );
}

function get_speaker_id_from_slug( $slug ) {
	$cache_key  = cfp_dev_group_cache_key( 'cfp_speaker_slug_' . md5( $slug ) );
	$speaker_id = get_transient( $cache_key );

	if ( false === $speaker_id ) {
		$speaker_id = 0;
		$speakers   = getJSON( 'public/speakers?size=' . CFP_DEV_SPEAKERS_FETCH_SIZE );
		if ( is_array( $speakers ) ) {
			foreach ( $speakers as $speaker ) {
				$current_slug = generate_slug( $speaker->firstName . '-' . $speaker->lastName );
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

function get_talk_id_from_slug( $slug ) {
	$cache_key = cfp_dev_group_cache_key( 'cfp_talk_slug_' . md5( $slug ) );
	$talk_id   = get_transient( $cache_key );

	if ( false === $talk_id ) {
		$talk_id = 0;
		$talks   = getJSON( 'public/talks' );
		if ( is_array( $talks ) ) {
			foreach ( $talks as $talk ) {
				if ( generate_slug( $talk->title ) === $slug ) {
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
 * @param string $input  Text to slugify, e.g. a speaker name or talk title.
 * @return string
 */
function generate_slug( $input ) {
	return strtolower( trim( preg_replace( '/[^A-Za-z0-9-]+/', '-', $input ) ) );
}

/**
 * Creates the required shortcode pages on plugin activation.
 * Existing pages (any status) are left untouched.
 */
function cfp_create_required_pages() {
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

	// Flush rewrite rules after creating new pages
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'cfp_create_required_pages' );


function add_speaker_title_script() {
	if ( is_page( 'speaker' ) ) {
		?>
		<script>
			document.addEventListener('DOMContentLoaded', function() {
				const ogTitle = document.querySelector('meta[property="og:title"], meta[name="og:title"]');
				if (ogTitle) {
					document.title = ogTitle.getAttribute('content');
				}
			});
		</script>
		<?php
	}
}
add_action( 'wp_footer', 'add_speaker_title_script' );


function add_meta_description() {
	$event_name = cfp_dev_get_event_name();
	if ( is_page( 'speakers' ) ) {
		echo '<meta name="description" content="Browse our lineup of expert speakers at ' . esc_attr( $event_name ) . '.">';
	} elseif ( is_page( 'schedule' ) ) {
		echo '<meta name="description" content="View the full schedule for ' . esc_attr( $event_name ) . '.">';
	} elseif ( is_page( 'talks-by-tracks' ) ) {
		echo '<meta name="description" content="Browse talks by track at ' . esc_attr( $event_name ) . '.">';
	} elseif ( is_page( 'talks-by-sessions' ) ) {
		echo '<meta name="description" content="Browse talks by session type at ' . esc_attr( $event_name ) . '.">';
	} elseif ( is_page( 'search-results' ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET param for meta description tag
		$query_val = isset( $_GET['query'] ) ? sanitize_text_field( wp_unslash( $_GET['query'] ) ) : '';
		echo '<meta name="description" content="Search results for ' . esc_attr( $query_val ) . ' at ' . esc_attr( $event_name ) . '.">';
	}
}
add_action( 'wp_head', 'add_meta_description' );

function getSocialLinks( $speaker ) {
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
