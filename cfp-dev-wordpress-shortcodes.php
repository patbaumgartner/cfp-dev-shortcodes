<?php
/**
 * Plugin Name:       CFP.DEV shortcodes
 * Plugin URI:        https://gitlab.com/voxxed/cfp.dev/wikis/Wordpress-Plugin
 * Description:       The CFP.DEV WordPress shortcodes (DARK MODE). This version supports the new PWA mobile app! (MySchedule and Home shortcodes have been removed)
 * Version:           4.1.0
 * Author:            Stephan Janssen, Patrick Baumgartner
 * Author URI:        https://x.com/stephan007
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Define global constants.
 *
 * @since 2.0.1
 */

if ( ! defined( 'CFP_DEV_APPLICATION_JSON' ) ) {
	define( 'CFP_DEV_APPLICATION_JSON', 'application/json; charset=utf-8' );
}

// Plugin version.
if ( ! defined( 'CFP_DEV_VERSION' ) ) {
	define( 'CFP_DEV_VERSION', '4.1.0' );
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
	define( 'CFP_DEV_KEY', get_transient( 'CFP_DEV_KEY' ) );
}

if ( ! defined( 'CFP_DEV_CACHE' ) ) {
	define( 'CFP_DEV_CACHE', get_transient( 'CFP_DEV_CACHE' ) );
}

if ( ! defined( 'CFP_DEV_EVENT_NAME' ) ) {
	define( 'CFP_DEV_EVENT_NAME', get_transient( 'CFP_DEV_EVENT_NAME' ) );
}

if ( ! defined( 'CFP_DEV_URL_DOMAIN' ) ) {
	define( 'CFP_DEV_URL_DOMAIN', 'https://' . CFP_DEV_KEY . '.cfp.dev/api/' );
}

if ( ! defined( 'CFP_DEV_SEARCH_DOMAIN' ) ) {
	define( 'CFP_DEV_SEARCH_DOMAIN', 'https://search.cfp.dev?cfp=' . CFP_DEV_KEY . '&accepted=true&total=5&query=' );
}

if ( ! defined( 'CFP_DEV_CSS' ) ) {
	define( 'CFP_DEV_CSS', 'css/cfp_dev_v4_0_1.css' );
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
 * Offline mode crawler & snapshot manager.
 */
if ( file_exists( CFP_DEV_DIR . '/shortcode/include/offline-crawler.php' ) ) {
	require_once CFP_DEV_DIR . '/shortcode/include/offline-crawler.php';
}

/**
 * CFP Speakers list.
 *
 * @since 1.0.0
 */
if ( file_exists( CFP_DEV_DIR . '/shortcode/shortcode-cfp-speakers.php' ) ) {
	require_once CFP_DEV_DIR . '/shortcode/shortcode-cfp-speakers.php';
}

/**
 * CFP Speaker details.
 *
 * @since 1.0.0
 */
if ( file_exists( CFP_DEV_DIR . '/shortcode/shortcode-cfp-speaker-details.php' ) ) {
	require_once CFP_DEV_DIR . '/shortcode/shortcode-cfp-speaker-details.php';
}

/**
 * CFP Schedule
 *
 * @since 1.0.0
 */
if ( file_exists( CFP_DEV_DIR . '/shortcode/shortcode-cfp-schedule.php' ) ) {
	require_once CFP_DEV_DIR . '/shortcode/shortcode-cfp-schedule.php';
}

/**
 * CFP Talk details
 *
 * @since 1.0.0
 */
if ( file_exists( CFP_DEV_DIR . '/shortcode/shortcode-cfp-talk-details.php' ) ) {
	require_once CFP_DEV_DIR . '/shortcode/shortcode-cfp-talk-details.php';
}

/**
 * CFP Talks by Tracks
 *
 * @since 1.0.0
 */
if ( file_exists( CFP_DEV_DIR . '/shortcode/shortcode-cfp-talks-by-tracks.php' ) ) {
	require_once CFP_DEV_DIR . '/shortcode/shortcode-cfp-talks-by-tracks.php';
}

/**
 * CFP Talks by Session Types
 *
 * @since 1.0.0
 */
if ( file_exists( CFP_DEV_DIR . '/shortcode/shortcode-cfp-talks-by-sessions.php' ) ) {
	require_once CFP_DEV_DIR . '/shortcode/shortcode-cfp-talks-by-sessions.php';
}

/**
 * CFP Search results
 *
 * @since 1.0.0
 */
if ( file_exists( CFP_DEV_DIR . '/shortcode/shortcode-cfp-search-results.php' ) ) {
	require_once CFP_DEV_DIR . '/shortcode/shortcode-cfp-search-results.php';
}
//
// *******************************************************************************************************************
//

function cfp_ajax_load_scripts() {
	wp_enqueue_script( 'site-cfp', plugin_dir_url( __FILE__ ) . 'js/site.js', [], CFP_DEV_VERSION, false );
}

add_action( 'wp_enqueue_scripts', 'cfp_ajax_load_scripts' );



//
// *******************************************************************************************************************
//

function cfp_dev_plugin_menu() {
	add_options_page( 'My Plugin Options', 'CFP.DEV', 'manage_options', 'my-unique-identifier', 'cfp_dev_plugin_options' );
}

add_action( 'admin_menu', 'cfp_dev_plugin_menu' );

/** Step 3. */
function cfp_dev_plugin_options() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.' ) );
	}

	// Verify nonce for any POST action on this page.
	if ( ! empty( $_POST ) && ( ! isset( $_POST['cfp_dev_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cfp_dev_nonce'] ) ), 'cfp_dev_options' ) ) ) {
		wp_die( esc_html__( 'Security check failed.' ) );
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

	if ( isset( $_POST['enable_theme_switch'] ) ) {
		update_option( 'enable_theme_switch', absint( $_POST['enable_theme_switch'] ) );
	}

	if ( isset( $_POST['cfp_dev_path_prefix'] ) ) {
		update_option( 'cfp_dev_path_prefix', sanitize_text_field( wp_unslash( $_POST['cfp_dev_path_prefix'] ) ) );
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

	echo '<div class="wrap">';
	echo '<h1>CFP.DEV Settings</h1>';

	// General Settings Section
	echo '<hr style="border-color: black">';
	echo '<h3>General Settings</h3>';
	echo '<form name="form1" method="post" action="">';
	wp_nonce_field( 'cfp_dev_options', 'cfp_dev_nonce' );
	echo '<table class="form-table">';
	echo '<tr>
			<th scope="row"><label>CFP.DEV Key</label></th>
			<td><input name="cfp_dev_key" size=20 value="' . esc_attr( CFP_DEV_KEY ) . '" minlength="3" required="true"></td>
		  </tr>';
	echo '<tr>
			<th scope="row"><label>Event name</label></th>
			<td><input name="cfp_dev_event_name" size=50 value="' . esc_attr( CFP_DEV_EVENT_NAME ) . '" minlength="3" required="true"></td>
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
					<option value="0" ' . selected( CFP_DEV_CACHE, 0, false ) . '>No Cache</option>
					<option value="3600" ' . selected( CFP_DEV_CACHE, 3600, false ) . '>One Hour</option>
					<option value="86400" ' . selected( CFP_DEV_CACHE, 86400, false ) . '>One Day</option>
					<option value="604800" ' . selected( CFP_DEV_CACHE, 604800, false ) . '>One Week</option>
					<option value="2592000" ' . selected( CFP_DEV_CACHE, 2592000, false ) . '>One Month</option>
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
	$speakers_cache = get_transient( 'speakers_cache_group' );
	if ( false !== $speakers_cache ) {
		echo '<form method="post" action="">
				<input type="hidden" name="delete_cache" value="speakers">
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
		$cache_key = 'cfp_schedule_' . $day;
		if ( get_transient( $cache_key ) !== false ) {
			$schedule_caches_exist = true;
			echo '<tr>
					<td>' . esc_html( ucfirst( $day ) ) . '</td>
					<td>
						<form method="post" action="">
							<input type="hidden" name="delete_cache" value="schedule">
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
	$speakers             = getJSON( 'public/speakers?size=500' );
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
						<form method="post" action="" class="delete-cache-form">
							<input type="hidden" name="delete_cache" value="speaker">
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
							<form method="post" action="">
								<input type="hidden" name="delete_cache" value="talk">
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

	// Process cache deletion
	if ( isset( $_POST['delete_cache'] ) ) {
		$cache_type = $_POST['delete_cache'];

		switch ( $cache_type ) {
			case 'speakers':
				delete_transient( 'speakers_cache_group' );
				echo '<div class="updated"><p>Speakers cache deleted.</p></div>';
				break;
			case 'schedule':
				if ( isset( $_POST['cache_day'] ) ) {
					$day = $_POST['cache_day'];
					delete_transient( 'cfp_schedule_' . $day );
					echo '<div class="updated"><p>Schedule cache for ' . esc_html( ucfirst( $day ) ) . ' deleted.</p></div>';
				}
				break;
			case 'speaker':
			case 'talk':
				if ( isset( $_POST['cache_id'] ) ) {
					$cache_id = $_POST['cache_id'];

					// Delete speaker or talk cache
					delete_transient( ( 'speaker' === $cache_type ) ? generate_cfp_cache_key( 'speaker', $cache_id ) : generate_cfp_cache_key( 'talk', $cache_id ) );

					// Delete photo speaker cache
					delete_transient( generate_cfp_cache_key( 'photo', $cache_id ) );

					echo '<div class="updated"><p>Cache deleted for speaker with ID: ' . esc_html( $cache_id ) . ' (including any photo cache).</p></div>';
				}
				break;
		}
	}

	echo '</div>'; // Close the wrap div

	// ─────────────────────────────────────────────────────────────────────────
	// Offline Mode Section
	// ─────────────────────────────────────────────────────────────────────────
	$offline_mode    = (int) get_option( 'cfp_dev_offline_mode', 0 );
	$crawl_state     = get_option( 'cfp_dev_crawl_state', [] );
	$crawl_status    = $crawl_state['status'] ?? 'idle';
	$latest_snapshot = cfp_dev_get_latest_snapshot();

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
			return 'cfp_speaker_details_' . md5( $id );
		case 'talk':
			return 'cfp_talk_details_' . md5( $id );
		case 'photo':
			return 'speaker_photos_' . md5( $id );
		default:
			return 'cfp_' . $type . '_' . md5( $id );
	}
}

function storeCfpDevKey( $key ) {
	# Check Constant CFP_DEV_KEY already defined
	if ( ! defined( 'CFP_DEV_KEY' ) ) {
		define( 'CFP_DEV_KEY', $key );
	}
	set_transient( 'CFP_DEV_KEY', $key );
}

function storeCfpDevCache( $key ) {
	if ( ! defined( 'CFP_DEV_CACHE' ) ) {
		define( 'CFP_DEV_CACHE', $key );
	}
	set_transient( 'CFP_DEV_CACHE', $key );
}

function storeCfpDevEventName( $cfpDevEventName ) {
	if ( ! defined( 'CFP_DEV_EVENT_NAME' ) ) {
		define( 'CFP_DEV_EVENT_NAME', $cfpDevEventName );
	}
	set_transient( 'CFP_DEV_EVENT_NAME', $cfpDevEventName );
}

/**
 * Clear cache by day per page type
 * @return void
 */
function clearCache() {
	$transientNames = [ 'speakers_cache_group', 'talks_by_tracks_cache_group_', 'talks_by_sessions_cache_group_' ];
	array_map( 'delete_transient', $transientNames );

	$days = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];

	foreach ( $days as $day ) {
		deleteCacheByDay( $day );
	}

	$speakers = getJSON( 'public/speakers?size=500' );
	if ( is_array( $speakers ) || is_object( $speakers ) ) {
		foreach ( $speakers as $speaker ) {
			$slug      = generate_slug( $speaker->firstName . '-' . $speaker->lastName );
			$cache_key = 'cfp_speaker_slug_' . md5( $slug );
			delete_transient( $cache_key );

			// Remove speaker and photo cache
			$transient_key = generate_cfp_cache_key( 'speaker', $speaker->id );
			if ( get_transient( $transient_key ) !== false ) {
				delete_transient( $transient_key );
			}
			$transient_key = generate_cfp_cache_key( 'photo', $speaker->id );
			if ( get_transient( $transient_key ) !== false ) {
				delete_transient( $transient_key );
			}
		}
	}

	$talks = getJSON( 'public/talks' );
	if ( is_array( $talks ) || is_object( $talks ) ) {
		foreach ( $talks as $talk ) {
			$slug      = generate_slug( $talk->title );
			$cache_key = 'cfp_talk_slug_' . md5( $slug );
			delete_transient( $cache_key );

			$transient_key = generate_cfp_cache_key( 'talk', $talk->id );
			if ( get_transient( $transient_key ) !== false ) {
				delete_transient( $transient_key );
			}
		}
	}
}

/**
 * Delete transients
 * @param $transientName string
 * @param $dataArray array
 */
function deleteTransients( $transientName, $dataArray ) {
	foreach ( $dataArray as $data ) {
		$id = property_exists( $data, 'id' ) ? $data->id : null;

		if ( ! empty( $id ) ) {
			$cachedName = $transientName . $id;
			if ( get_transient( $cachedName ) ) {
				delete_transient( $cachedName );
			}
		}
	}
}

/**
 * Delete cache by day
 * @param $day string
 */
function deleteCacheByDay( $day ) {
	delete_transient( 'cfp_schedule_' . $day );

	$sessionTypes = getJSON( 'public/session-types' );
	! empty( $sessionTypes ) && deleteTransients( 'talks_by_sessions_cache_group_', $sessionTypes );

	$tracks = getJSON( 'public/tracks' );
	! empty( $tracks ) && deleteTransients( 'talks_by_tracks_cache_group_', $tracks );

	$data = getJSON( 'public/schedules/' . $day );

	if ( ! empty( $data ) ) {
		foreach ( $data as $timeSlot ) {
			if ( ! empty( $timeSlot->proposal->title ) ) {
				deleteTalkAndSpeakerDetails( $timeSlot );
			}
		}
	}
}

/**
 * @param $timeSlot
 * @return void
 */
function deleteTalkAndSpeakerDetails( $timeSlot ) {
	delete_transient( 'cfp_talk_details_' . $timeSlot->proposal->id );

	foreach ( $timeSlot->proposal->speakers as $speaker ) {
		delete_transient( 'cfp_speaker_details_' . $speaker->id );
	}
}

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
}

function embedSocialSpeakerCard( $speaker ) {
	$content = '<meta name="twitter:card" content="summary_large_image">';
	if ( ! empty( $speaker->twitterHandle ) ) {
		$content .= '<meta name="twitter:site" content="' . esc_attr( $speaker->twitterHandle ) . '">';
	}

	if ( ! empty( $speaker->imageUrl ) ) {
		$content .= '<meta name="twitter:image" content="' . esc_url( $speaker->imageUrl ) . '">';
	}
	$speakerInfo = esc_attr( $speaker->firstName . ' ' . $speaker->lastName . ' at ' . CFP_DEV_EVENT_NAME );
	$content    .= '<meta name="og:title" content="' . esc_attr( $speakerInfo ) . '">';
	$content    .= '<meta name="twitter:title" content="' . esc_attr( $speaker->firstName . ' ' . $speaker->lastName . ' at ' . CFP_DEV_EVENT_NAME ) . '">';
	$content    .= '<meta name="twitter:description" content="' . esc_attr( wp_strip_all_tags( substr( $speaker->bio, 0, 260 ) ) ) . '">';

	return $content;
}

function embedSocialTalkCard( $talk ) {
	$content  = '<meta name="twitter:card" content="summary">';
	$content .= '<meta name="twitter:image" content="' . esc_url( $talk->trackImageURL ) . '">';
	$content .= '<meta name="og:title" content="' . esc_attr( wp_strip_all_tags( $talk->title ) . ' at ' . CFP_DEV_EVENT_NAME ) . '">';
	$content .= '<meta name="og:url" content="' . esc_url( 'https://' . CFP_DEV_KEY . '.cfp.dev/talk?id=' . $talk->id ) . '">';
	$content .= '<meta name="twitter:title" content="' . esc_attr( wp_strip_all_tags( $talk->title ) . ' at ' . CFP_DEV_EVENT_NAME ) . '">';
	$content .= '<meta name="twitter:description" content="' . esc_attr( wp_strip_all_tags( substr( $talk->description, 0, 260 ) ) ) . '">';

	return $content;
}

function compareLastName( $x, $y ) {
	return iconv( 'utf-8', 'ascii//TRANSLIT', $x->lastName ) <=> iconv( 'utf-8', 'ascii//TRANSLIT', $y->lastName );
}

function compareName( $x, $y ) {
	return iconv( 'utf-8', 'ascii//TRANSLIT', $x->name ) <=> iconv( 'utf-8', 'ascii//TRANSLIT', $y->name );
}

function getJSON( $queryPath ) {
	// Offline mode: serve from local snapshot instead of the live API.
	if ( get_option( 'cfp_dev_offline_mode', 0 ) ) {
		return cfp_dev_get_json_offline( $queryPath );
	}

	$query_url = CFP_DEV_URL_DOMAIN . $queryPath;

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
	$response   = wp_remote_get( CFP_DEV_SEARCH_DOMAIN . $safe_query, [ 'timeout' => 30 ] );
	if ( is_wp_error( $response ) ) {
		return 'REST error:' . $response->get_error_message();
	}
	return json_decode( wp_remote_retrieve_body( $response ) );
}

function getTime( $time, $timezone, $format ) {
	$dt = new DateTime( $time, new DateTimeZone( 'UTC' ) );
	$dt->setTimezone( $timezone );
	return $dt->format( $format );
}

function getSearchForm() {
	$content  = '<form class="cfp-search" action="search-results" method="GET">';
	$content .= '   <input class="cfp-input" id="dev-cfp-search-term" type="search" minlength="3" name="query" placeholder="Full search..." autofocus>';
	$content .= '</form>';
	return $content;
}

function cfp_dev_enqueue_admin_scripts( $hook ) {
	if ( 'settings_page_my-unique-identifier' !== $hook ) {
		return;
	}
	wp_enqueue_script( 'cfp-dev-admin-cache', plugins_url( 'js/admin-cache-management.js', __FILE__ ), [ 'jquery' ], '1.0', true );
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
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'cfp_dev_delete_cache' ) ) {
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

	$cache_type = sanitize_text_field( $_POST['delete_cache'] );
	$cache_id   = sanitize_text_field( $_POST['cache_id'] );

	if ( 'speaker' === $cache_type ) {
		$transient_key       = 'cfp_speaker_details_' . $cache_id;
		$photo_transient_key = 'speaker_photos_' . $cache_id;

		$deleted_speaker = delete_transient( $transient_key );
		$deleted_photo   = delete_transient( $photo_transient_key );

		cfp_dev_log( 'delete_cache: speaker transient deleted=' . ( $deleted_speaker ? 'true' : 'false' ) );
		cfp_dev_log( 'delete_cache: photo transient deleted=' . ( $deleted_photo ? 'true' : 'false' ) );

		wp_send_json_success( [ 'message' => 'Cache deleted for speaker with ID: ' . $cache_id ] );
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

	// Handle talk URL with ID parameter
	add_rewrite_rule(
		'^' . $prefix . 'talk/?$',
		'index.php?pagename=talk&id=$matches[1]',
		'top'
	);

	// Handle subdirectory before talk URL with slug (fix for subdirectory redirect)
	if ( ! empty( $prefix ) ) {
		add_rewrite_rule(
			'([^/]+)/' . $prefix . 'talk/([^/]+)/?$',
			'index.php?pagename=talk&talk_slug=$matches[2]',
			'top'
		);

		// Handle subdirectory before talk URL with ID
		add_rewrite_rule(
			'([^/]+)/' . $prefix . 'talk/?$',
			'index.php?pagename=talk&id=$matches[2]',
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
	$cache_key  = 'cfp_speaker_slug_' . md5( $slug );
	$speaker_id = get_transient( $cache_key );

	if ( false === $speaker_id ) {
		$speakers = getJSON( 'public/speakers?size=400' );
		foreach ( $speakers as $speaker ) {
			$current_slug = generate_slug( $speaker->firstName . '-' . $speaker->lastName );
			if ( $current_slug === $slug ) {
				$speaker_id = $speaker->id;
				set_transient( $cache_key, $speaker_id, DAY_IN_SECONDS );
				break;
			}
		}
	}

	return $speaker_id;
}

function get_talk_by_slug( $slug ) {
	$talks = getJSON( 'public/talks' );
	foreach ( $talks as $talk ) {
		$talk_slug = generate_slug( $talk->title );
		if ( $talk_slug === $slug ) {
			return $talk;
		}
	}
	return null;
}

// Add this function to generate a slug
function generate_slug( $input ) {
	return strtolower( trim( preg_replace( '/[^A-Za-z0-9-]+/', '-', $input ) ) );
}

/**
 * Create required CFP.DEV enabled shortcode pages on plugin activation
 * @param $atts array
 * @return string HTML content
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
		$existing_pages = get_posts(
			[
				'name'        => $slug,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'numberposts' => 1,
			]
		);
		$existing_page  = ! empty( $existing_pages ) ? $existing_pages[0] : null;
		if ( null === $existing_page ) {
			$page_id = wp_insert_post(
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
				const ogTitle = document.querySelector('meta[name="og:title"]');
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
	if ( is_page( 'speakers' ) ) {
		echo '<meta name="description" content="Browse our lineup of expert speakers at ' . esc_html( CFP_DEV_EVENT_NAME ) . '.">';
	} elseif ( is_page( 'schedule' ) ) {
		echo '<meta name="description" content="View the full schedule for ' . esc_html( CFP_DEV_EVENT_NAME ) . '.">';
	} elseif ( is_page( 'talks-by-tracks' ) ) {
		echo '<meta name="description" content="Browse talks by track at ' . esc_html( CFP_DEV_EVENT_NAME ) . '.">';
	} elseif ( is_page( 'talks-by-sessions' ) ) {
		echo '<meta name="description" content="Browse talks by session type at ' . esc_html( CFP_DEV_EVENT_NAME ) . '.">';
	} elseif ( is_page( 'search-results' ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET param for meta description tag
		$query_val = isset( $_GET['query'] ) ? esc_html( sanitize_text_field( wp_unslash( $_GET['query'] ) ) ) : '';
		echo '<meta name="description" content="Search results for ' . esc_html( $query_val ) . ' at ' . esc_html( CFP_DEV_EVENT_NAME ) . '.">';
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
			$content .= '<a class="cfp-a cfp-linkedIn" href="https://www.linkedin.com/in/' . esc_attr( $speaker->linkedInUsername ) . '" target="_blank"></a>';
		}
		if ( ! empty( $speaker->blueskyUsername ) ) {
			$content .= '<a class="cfp-a cfp-bluesky" href="https://bsky.app/profile/' . esc_attr( $speaker->blueskyUsername ) . '" target="_blank"></a>';
		}
		if ( ! empty( $speaker->mastodonUsername ) ) {
			$content .= '<a class="cfp-a cfp-mastodon" href="' . esc_attr( $speaker->mastodonUsername ) . '" target="_blank"></a>';
		}
		if ( ! empty( $speaker->twitterHandle ) ) {
			$content .= '<a class="cfp-a cfp-twitter" href="https://x.com/' . esc_attr( $speaker->twitterHandle ) . '" target="_blank"></a>';
		}
		$content .= '</nav>';
	}
	return $content;
}
