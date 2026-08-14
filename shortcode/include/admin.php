<?php
/**
 * CFP.DEV shortcodes
 *
 * Settings → CFP.DEV admin screen, its form handling and its AJAX endpoints.
 *
 * @package CFP.DEV
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Registers the Settings → CFP.DEV admin page.
 */
function cfp_dev_plugin_menu() {
	add_options_page( __( 'CFP.DEV Settings', 'cfp-dev-shortcodes' ), __( 'CFP.DEV', 'cfp-dev-shortcodes' ), 'manage_options', 'cfp-dev-settings', 'cfp_dev_plugin_options' );
}

add_action( 'admin_menu', 'cfp_dev_plugin_menu' );

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
	return __( 'Settings saved.', 'cfp-dev-shortcodes' );
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
		return __( 'Speakers cache deleted.', 'cfp-dev-shortcodes' );
	}

	if ( 'schedule' === $cache_type ) {
		$day = sanitize_key( $post['cache_day'] ?? '' );
		if ( ! in_array( $day, [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ], true ) ) {
			return '';
		}
		delete_transient( cfp_dev_group_cache_key( 'cfp_schedule_' . ucfirst( $day ) ) );
		/* translators: %s: weekday name. */
		return sprintf( __( 'Schedule cache for %s deleted.', 'cfp-dev-shortcodes' ), ucfirst( $day ) );
	}

	if ( in_array( $cache_type, [ 'speaker', 'talk' ], true ) && isset( $post['cache_id'] ) ) {
		$cache_id = sanitize_text_field( $post['cache_id'] );
		delete_transient( cfp_dev_detail_cache_key( $cache_type, $cache_id ) );
		delete_transient( cfp_dev_detail_cache_key( 'photo', $cache_id ) );
		/* translators: 1: cache type, 2: cache ID. */
		return sprintf( __( 'Cache deleted for %1$s with ID: %2$s (including any photo cache).', 'cfp-dev-shortcodes' ), $cache_type, $cache_id );
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
	$was_enabled = 1 === (int) get_option( 'cfp_dev_offline_mode', 0 );
	$crawling    = cfp_dev_crawl_in_progress();

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
	echo '<h1>' . esc_html__( 'CFP.DEV Settings', 'cfp-dev-shortcodes' ) . '</h1>';

	if ( '' !== $cache_notice ) {
		echo '<div class="updated"><p>' . esc_html( $cache_notice ) . '</p></div>';
	}

	// General Settings Section
	echo '<hr style="border-color: black">';
	echo '<h3>' . esc_html__( 'General Settings', 'cfp-dev-shortcodes' ) . '</h3>';
	echo '<form name="form1" method="post" action="">';
	wp_nonce_field( 'cfp_dev_options', 'cfp_dev_nonce' );
	echo '<table class="form-table">';
	echo '<tr>
			<th scope="row"><label>' . esc_html__( 'CFP.DEV Key', 'cfp-dev-shortcodes' ) . '</label></th>
			<td><input name="cfp_dev_key" size=20 value="' . esc_attr( cfp_dev_get_key() ) . '" minlength="3" pattern="[A-Za-z0-9-]+" required="true">
			<br><small>' . esc_html__( 'Only letters, digits and dashes (the subdomain of your CFP.DEV instance).', 'cfp-dev-shortcodes' ) . '</small></td>
		  </tr>';
	echo '<tr>
			<th scope="row"><label>' . esc_html__( 'Event name', 'cfp-dev-shortcodes' ) . '</label></th>
			<td><input name="cfp_dev_event_name" size=50 value="' . esc_attr( cfp_dev_get_event_name() ) . '" minlength="3" required="true"></td>
		  </tr>';
	echo '<tr>
			<th scope="row"><label>' . esc_html__( 'URL Path Prefix', 'cfp-dev-shortcodes' ) . '</label></th>
			<td><input name="cfp_dev_path_prefix" size=20 value="' . esc_attr( get_option( 'cfp_dev_path_prefix', '' ) ) . '"><br>
			<small>' . esc_html__( 'For example https://voxxeddays.com/trieste would have "trieste" as url path prefix', 'cfp-dev-shortcodes' ) . '</small>
			</td>
		  </tr>';
	echo '<tr>
			<th scope="row"><label>' . esc_html__( 'Permalinks with Id', 'cfp-dev-shortcodes' ) . '</label></th>
			<td>
			  <select name="cfp_dev_content_by_id">
					<option value="yes" ' . selected( get_option( 'cfp_dev_content_by_id' ), 'yes', false ) . '>' . esc_html__( 'Yes', 'cfp-dev-shortcodes' ) . '</option>
					<option value="no" ' . selected( get_option( 'cfp_dev_content_by_id' ), 'no', false ) . '>' . esc_html__( 'No', 'cfp-dev-shortcodes' ) . '</option>
			  </select>
			  <br>
			  <strong>' . esc_html__( 'Must be "Yes" for multisite WordPress installs.', 'cfp-dev-shortcodes' ) . '</strong>
			  <small>' . esc_html__( 'When "Yes" the content links look as follows https://voxxeddays.com/trieste/speaker?id=123', 'cfp-dev-shortcodes' ) . '</small>
			</td>
		  </tr>';
	echo '<tr>
			<th scope="row"><label>' . esc_html__( 'Show Rooms', 'cfp-dev-shortcodes' ) . '</label></th>
			<td>
			  <select name="cfp_dev_show_rooms">
					<option value="yes" ' . selected( get_option( 'cfp_dev_show_rooms' ), 'yes', false ) . '>' . esc_html__( 'Yes', 'cfp-dev-shortcodes' ) . '</option>
					<option value="no" ' . selected( get_option( 'cfp_dev_show_rooms' ), 'no', false ) . '>' . esc_html__( 'No', 'cfp-dev-shortcodes' ) . '</option>
			  </select>
			  <br>
			  <small>' . esc_html__( 'When "No" rooms will not be displayed on any page', 'cfp-dev-shortcodes' ) . '</small>
			</td>
		  </tr>';
	echo '<tr>
			<th scope="row"><label>' . esc_html__( 'Cache Duration', 'cfp-dev-shortcodes' ) . '</label></th>
			<td>
				<select name="cfp_dev_cache">
					<option value="0" ' . selected( cfp_dev_get_cache_ttl(), 0, false ) . '>' . esc_html__( 'No Cache', 'cfp-dev-shortcodes' ) . '</option>
					<option value="3600" ' . selected( cfp_dev_get_cache_ttl(), 3600, false ) . '>' . esc_html__( 'One Hour', 'cfp-dev-shortcodes' ) . '</option>
					<option value="86400" ' . selected( cfp_dev_get_cache_ttl(), 86400, false ) . '>' . esc_html__( 'One Day', 'cfp-dev-shortcodes' ) . '</option>
					<option value="604800" ' . selected( cfp_dev_get_cache_ttl(), 604800, false ) . '>' . esc_html__( 'One Week', 'cfp-dev-shortcodes' ) . '</option>
					<option value="2592000" ' . selected( cfp_dev_get_cache_ttl(), 2592000, false ) . '>' . esc_html__( 'One Month', 'cfp-dev-shortcodes' ) . '</option>
				</select>
			</td>
		  </tr>';
	echo '<tr>
			<th scope="row"><label>' . esc_html__( 'Default Theme', 'cfp-dev-shortcodes' ) . '</label></th>
			<td>
				<select name="cfp_dev_default_theme">
					<option value="light" ' . selected( get_option( 'cfp_dev_default_theme' ), 'light', false ) . '>' . esc_html__( 'Light', 'cfp-dev-shortcodes' ) . '</option>
					<option value="dark" ' . selected( get_option( 'cfp_dev_default_theme' ), 'dark', false ) . '>' . esc_html__( 'Dark', 'cfp-dev-shortcodes' ) . '</option>
				</select>
			</td>
		  </tr>';
	echo '<tr>
			<th scope="row"><label>' . esc_html__( 'Enable Theme Switching', 'cfp-dev-shortcodes' ) . '</label></th>
			<td><input type="checkbox" name="enable_theme_switch" value="1" ' . checked( true, cfp_dev_theme_switch_enabled(), false ) . ' /></td>
		  </tr>';
	echo '</table>';
	echo '<p class="submit"><input type="submit" name="Submit" class="button-primary" value="' . esc_attr__( 'Save Changes', 'cfp-dev-shortcodes' ) . '" /></p>';
	echo '</form>';

	// Cache Management Section
	echo '<hr style="border-color: black">';
	echo '<h3>' . esc_html__( 'Manage Caches', 'cfp-dev-shortcodes' ) . '</h3>';
	echo '<p>' . esc_html__( 'Here you can view and delete various caches used by the plugin.', 'cfp-dev-shortcodes' ) . '</p>';

	// Speakers cache
	echo '<h4>' . esc_html__( 'Speakers Cache', 'cfp-dev-shortcodes' ) . '</h4>';
	$speakers_cache = get_transient( cfp_dev_speakers_cache_key( cfp_dev_speakers_default_atts() ) );
	if ( false !== $speakers_cache ) {
		echo '<form method="post" action="">';
		wp_nonce_field( 'cfp_dev_options', 'cfp_dev_nonce' );
		echo '<input type="hidden" name="delete_cache" value="speakers">
				<input type="submit" class="button" value="' . esc_attr__( 'Delete Speakers Cache', 'cfp-dev-shortcodes' ) . '">
			  </form>';
	} else {
		echo '<p>' . esc_html__( 'No speakers cache available.', 'cfp-dev-shortcodes' ) . '</p>';
	}

	// Schedule caches
	echo '<h4>' . esc_html__( 'Schedule Caches', 'cfp-dev-shortcodes' ) . '</h4>';
	$days                  = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];
	$display_days          = [
		'monday'    => __( 'Monday', 'cfp-dev-shortcodes' ),
		'tuesday'   => __( 'Tuesday', 'cfp-dev-shortcodes' ),
		'wednesday' => __( 'Wednesday', 'cfp-dev-shortcodes' ),
		'thursday'  => __( 'Thursday', 'cfp-dev-shortcodes' ),
		'friday'    => __( 'Friday', 'cfp-dev-shortcodes' ),
		'saturday'  => __( 'Saturday', 'cfp-dev-shortcodes' ),
		'sunday'    => __( 'Sunday', 'cfp-dev-shortcodes' ),
	];
	$schedule_caches_exist = false;

	echo '<table class="wp-list-table widefat fixed striped">
			<thead><tr><th>' . esc_html__( 'Day', 'cfp-dev-shortcodes' ) . '</th><th>' . esc_html__( 'Action', 'cfp-dev-shortcodes' ) . '</th></tr></thead>
			<tbody>';

	foreach ( $days as $day ) {
		// Schedule transients are keyed by the capitalised day name (DateTime 'l' format).
		$cache_key = cfp_dev_group_cache_key( 'cfp_schedule_' . ucfirst( $day ) );
		if ( get_transient( $cache_key ) !== false ) {
			$schedule_caches_exist = true;
			echo '<tr>
					<td>' . esc_html( $display_days[ $day ] ) . '</td>
					<td>
						<form method="post" action="">';
			wp_nonce_field( 'cfp_dev_options', 'cfp_dev_nonce' );
			echo '<input type="hidden" name="delete_cache" value="schedule">
							<input type="hidden" name="cache_day" value="' . esc_attr( $day ) . '">
							<input type="submit" class="button button-small" value="' . esc_attr__( 'Delete Cache', 'cfp-dev-shortcodes' ) . '">
						</form>
					</td>
				  </tr>';
		}
	}

	echo '</tbody></table>';

	if ( ! $schedule_caches_exist ) {
		echo '<p>' . esc_html__( 'No schedule caches available.', 'cfp-dev-shortcodes' ) . '</p>';
	}

	// Speaker detail caches
	echo '<h4>' . esc_html__( 'Speaker Detail Caches', 'cfp-dev-shortcodes' ) . '</h4>';
	$speakers             = cfp_dev_get_json( 'public/speakers?size=' . CFP_DEV_SPEAKERS_FETCH_SIZE );
	$speaker_caches_exist = false;

	if ( is_array( $speakers ) || is_object( $speakers ) ) {
		echo '<table class="wp-list-table widefat fixed striped">
			<thead><tr><th>' . esc_html__( 'Speaker ID', 'cfp-dev-shortcodes' ) . '</th><th>' . esc_html__( 'Name', 'cfp-dev-shortcodes' ) . '</th><th>' . esc_html__( 'Action', 'cfp-dev-shortcodes' ) . '</th></tr></thead>
			<tbody>';

		foreach ( $speakers as $speaker ) {
			// Every field here is read straight off an API record, and a record
			// that omits one must not turn this screen into a page of warnings —
			// it is where an administrator goes when something is already wrong.
			$speaker_id    = absint( $speaker->id ?? 0 );
			$speaker_name  = trim( ( $speaker->firstName ?? '' ) . ' ' . ( $speaker->lastName ?? '' ) );
			$transient_key = cfp_dev_detail_cache_key( 'speaker', $speaker_id );
			if ( get_transient( $transient_key ) !== false ) {
				$speaker_caches_exist = true;
				echo '<tr id="speaker-row-' . esc_attr( (string) $speaker_id ) . '">
					<td>' . esc_html( (string) $speaker_id ) . '</td>
					<td>' . esc_html( $speaker_name ) . '</td>
					<td>
						<form method="post" action="" class="delete-cache-form">';
				wp_nonce_field( 'cfp_dev_options', 'cfp_dev_nonce' );
				echo '<input type="hidden" name="delete_cache" value="speaker">
							<input type="hidden" name="cache_id" value="' . esc_attr( (string) $speaker_id ) . '">
							<input type="submit" class="button button-small delete-cache-button" value="' . esc_attr__( 'Delete Cache', 'cfp-dev-shortcodes' ) . '">
						</form>
					</td>
				  </tr>';
			}
		}

		echo '</tbody></table>';
	}

	if ( ! $speaker_caches_exist ) {
		echo '<p>' . esc_html__( 'No speaker detail caches available.', 'cfp-dev-shortcodes' ) . '</p>';
	}

	// Talk detail caches
	echo '<h4>' . esc_html__( 'Talk Detail Caches', 'cfp-dev-shortcodes' ) . '</h4>';
	$talks             = cfp_dev_get_json( 'public/talks' );
	$talk_caches_exist = false;

	if ( is_array( $talks ) || is_object( $talks ) ) {
		echo '<table class="wp-list-table widefat fixed striped">
				<thead><tr><th>' . esc_html__( 'Talk ID', 'cfp-dev-shortcodes' ) . '</th><th>' . esc_html__( 'Title', 'cfp-dev-shortcodes' ) . '</th><th>' . esc_html__( 'Action', 'cfp-dev-shortcodes' ) . '</th></tr></thead>
				<tbody>';

		foreach ( $talks as $talk ) {
			$talk_id       = absint( $talk->id ?? 0 );
			$transient_key = cfp_dev_detail_cache_key( 'talk', $talk_id );
			if ( get_transient( $transient_key ) !== false ) {
				$talk_caches_exist = true;
				echo '<tr>
						<td>' . esc_html( (string) $talk_id ) . '</td>
						<td>' . esc_html( (string) ( $talk->title ?? '' ) ) . '</td>
						<td>
							<form method="post" action="">';
				wp_nonce_field( 'cfp_dev_options', 'cfp_dev_nonce' );
				echo '<input type="hidden" name="delete_cache" value="talk">
								<input type="hidden" name="cache_id" value="' . esc_attr( (string) $talk_id ) . '">
								<input type="submit" class="button button-small" value="' . esc_attr__( 'Delete Cache', 'cfp-dev-shortcodes' ) . '">
							</form>
						</td>
					  </tr>';
			}
		}

		echo '</tbody></table>';
	}

	if ( ! $talk_caches_exist ) {
		echo '<p>' . esc_html__( 'No talk detail caches available.', 'cfp-dev-shortcodes' ) . '</p>';
	}

	echo '</div>';

	// ─────────────────────────────────────────────────────────────────────────
	// Offline Mode Section
	// ─────────────────────────────────────────────────────────────────────────
	$offline_mode    = (int) get_option( 'cfp_dev_offline_mode', 0 );
	$crawl_state     = get_option( 'cfp_dev_crawl_state', [] );
	$crawl_status    = $crawl_state['status'] ?? 'idle';
	$latest_snapshot = cfp_dev_get_latest_snapshot();

	// A crawl that was killed mid-run never writes a terminal state, so its
	// status still reads "running". Past its deadline it is not.
	$crawling = cfp_dev_crawl_in_progress();

	// Auto-disable offline mode when the snapshot folder has been removed.
	if ( 1 === $offline_mode && empty( $latest_snapshot ) && ! $crawling ) {
		update_option( 'cfp_dev_offline_mode', 0 );
		$offline_mode = 0;
	}

	// Keep the checkbox checked while a crawl is in progress — offline mode
	// only flips to 1 when the crawl finishes, but the user intent is already set.
	if ( 0 === $offline_mode && $crawling ) {
		$offline_mode = 1;
	}

	echo '<div class="wrap">';
	echo '<hr style="border-color: black">';
	echo '<h3>' . esc_html__( 'Offline Mode', 'cfp-dev-shortcodes' ) . '</h3>';
	echo '<p>' . esc_html__( 'When enabled, all API data and images are served from a local snapshot — no external requests are made.', 'cfp-dev-shortcodes' ) . '</p>';
	echo '<p><em>' . esc_html__( 'Checking the box starts a fresh crawl. Unchecking disables offline mode but keeps the snapshot data. Re-checking creates a new snapshot.', 'cfp-dev-shortcodes' ) . '</em></p>';

	echo '<form name="cfp_offline_form" method="post" action="">';
	wp_nonce_field( 'cfp_dev_options', 'cfp_dev_nonce' );
	echo '<input type="hidden" name="cfp_dev_offline_mode_save" value="1">';
	echo '<table class="form-table">';
	echo '<tr>
			<th scope="row"><label for="cfp_dev_offline_mode">' . esc_html__( 'Enable Offline Mode', 'cfp-dev-shortcodes' ) . '</label></th>
			<td>
				<input type="checkbox" id="cfp_dev_offline_mode" name="cfp_dev_offline_mode" value="1" ' . checked( 1, $offline_mode, false ) . '>
				<span class="description">' . esc_html__( 'Check to start a new crawl. Offline mode activates automatically when the crawl finishes.', 'cfp-dev-shortcodes' ) . '</span>
			</td>
		  </tr>';
	echo '</table>';
	echo '<p class="submit"><input type="submit" name="Submit" class="button-primary" value="' . esc_attr__( 'Save Offline Mode', 'cfp-dev-shortcodes' ) . '"></p>';
	echo '</form>';

	// Snapshot status box (populated / updated by admin-offline-crawler.js)
	echo '<h4>' . esc_html__( 'Snapshot Status', 'cfp-dev-shortcodes' ) . '</h4>';
	echo '<div id="cfp-crawl-status">';

	if ( $crawling ) {
		echo '<p>' . esc_html__( 'Status:', 'cfp-dev-shortcodes' ) . ' <strong>' . esc_html( 'running' === $crawl_status ? __( 'Running', 'cfp-dev-shortcodes' ) : __( 'Pending', 'cfp-dev-shortcodes' ) ) . '</strong> — ' . esc_html( $crawl_state['step_label'] ?? '' ) . '</p>';
		if ( ! empty( $crawl_state['items_total'] ) && $crawl_state['items_total'] > 0 ) {
			$pct = intval( $crawl_state['items_done'] / $crawl_state['items_total'] * 100 );
			echo '<progress value="' . esc_attr( $crawl_state['items_done'] ) . '" max="' . esc_attr( $crawl_state['items_total'] ) . '"></progress> '
				. esc_html( $pct . '% (' . $crawl_state['items_done'] . '/' . $crawl_state['items_total'] . ')' );
		}
	} elseif ( 'done' === $crawl_status ) {
		echo '<p>' . esc_html__( 'Status:', 'cfp-dev-shortcodes' ) . ' <strong>' . esc_html__( 'Complete', 'cfp-dev-shortcodes' ) . '</strong></p>';
		if ( ! empty( $crawl_state['snapshot_name'] ) ) {
			echo '<p>' . esc_html__( 'Active snapshot:', 'cfp-dev-shortcodes' ) . ' <code>' . esc_html( $crawl_state['snapshot_name'] ) . '</code></p>';
		}
		if ( ! empty( $crawl_state['errors'] ) ) {
			echo '<p style="color:orange;">' . esc_html(
				sprintf(
					/* translators: %s: number of items with errors. */
					__( 'Warnings: %s item(s) had errors (see manifest.json).', 'cfp-dev-shortcodes' ),
					$crawl_state['errors']
				)
			) . '</p>';
		}
	} elseif ( 'error' === $crawl_status ) {
		echo '<p style="color:red;">' . esc_html__( 'Status:', 'cfp-dev-shortcodes' ) . ' <strong>' . esc_html__( 'Error', 'cfp-dev-shortcodes' ) . '</strong> — ' . esc_html( $crawl_state['step_label'] ?? '' ) . '</p>';
	} elseif ( in_array( $crawl_status, [ 'running', 'pending' ], true ) ) {
		// Still says "running" but is past its deadline: the process was killed
		// before it could record how it ended. Say so, rather than showing a
		// progress bar that will never move again.
		echo '<p style="color:red;">' . esc_html__( 'Status:', 'cfp-dev-shortcodes' ) . ' <strong>' . esc_html__( 'Stopped', 'cfp-dev-shortcodes' ) . '</strong> — '
			. esc_html__( 'the last crawl did not finish. Use Re-crawl Now to try again.', 'cfp-dev-shortcodes' ) . '</p>';
	} elseif ( ! empty( $latest_snapshot ) ) {
		echo '<p>' . esc_html__( 'Last snapshot:', 'cfp-dev-shortcodes' ) . ' <code>' . esc_html( basename( $latest_snapshot ) ) . '</code></p>';
	} else {
		echo '<p>' . wp_kses_post(
			sprintf(
				/* translators: %s: re-crawl button label. */
				__( 'No snapshot available. Enable offline mode or click <strong>%s</strong> to create one.', 'cfp-dev-shortcodes' ),
				__( 'Re-crawl Now', 'cfp-dev-shortcodes' )
			)
		) . '</p>';
	}

	echo '</div>';
	echo '<p><button type="button" id="cfp-recrawl-btn" class="button">' . esc_html__( 'Re-crawl Now', 'cfp-dev-shortcodes' ) . '</button></p>';

	echo '</div>';
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
	wp_enqueue_script( 'cfp-dev-admin-cache', plugins_url( 'js/admin-cache-management.js', CFP_DEV_PLUGIN_FILE ), [ 'jquery' ], CFP_DEV_VERSION, true );
	wp_localize_script(
		'cfp-dev-admin-cache',
		'cfp_dev_ajax',
		[
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'cfp_dev_delete_cache' ),
		]
	);

	$crawl_state = get_option( 'cfp_dev_crawl_state', [] );
	wp_enqueue_script( 'cfp-dev-admin-offline', plugins_url( 'js/admin-offline-crawler.js', CFP_DEV_PLUGIN_FILE ), [ 'jquery' ], CFP_DEV_VERSION, true );
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
		wp_send_json_error( [ 'message' => __( 'Security check failed.', 'cfp-dev-shortcodes' ) ] );
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action.', 'cfp-dev-shortcodes' ) ] );
		return;
	}

	if ( ! isset( $_POST['delete_cache'] ) || ! isset( $_POST['cache_id'] ) ) {
		wp_send_json_error( [ 'message' => __( 'Missing required parameters', 'cfp-dev-shortcodes' ) ] );
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

		/* translators: 1: cache type, 2: cache ID. */
		wp_send_json_success( [ 'message' => sprintf( __( 'Cache deleted for %1$s with ID: %2$s', 'cfp-dev-shortcodes' ), $cache_type, $cache_id ) ] );
	} else {
		wp_send_json_error( [ 'message' => __( 'Invalid cache type', 'cfp-dev-shortcodes' ) ] );
	}
}
add_action( 'wp_ajax_cfp_dev_delete_cache', 'cfp_dev_delete_cache_handler' );
// Note: cache deletion requires manage_options capability; not exposed to non-authenticated users.

/**
 * AJAX: return the current crawl state as JSON (polled by admin-offline-crawler.js).
 */
function cfp_dev_crawl_progress_handler() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cfp_dev_offline_nonce' ) ) {
		wp_send_json_error( [ 'message' => __( 'Security check failed.', 'cfp-dev-shortcodes' ) ] );
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'cfp-dev-shortcodes' ) ] );
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
		wp_send_json_error( [ 'message' => __( 'Security check failed.', 'cfp-dev-shortcodes' ) ] );
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'cfp-dev-shortcodes' ) ] );
		return;
	}
	if ( ! cfp_dev_start_crawl() ) {
		wp_send_json_error( [ 'message' => __( 'A crawl is already running. Wait for it to finish.', 'cfp-dev-shortcodes' ) ] );
		return;
	}
	wp_send_json_success( [ 'message' => __( 'Crawl started.', 'cfp-dev-shortcodes' ) ] );
}
add_action( 'wp_ajax_cfp_dev_start_crawl_ajax', 'cfp_dev_start_crawl_ajax_handler' );
