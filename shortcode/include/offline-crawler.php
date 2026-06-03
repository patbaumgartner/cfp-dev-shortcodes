<?php
/**
 * CFP.DEV Offline Mode — Crawler & Snapshot Manager.
 *
 * Provides functions for:
 * - Building local snapshots of all API JSON and CDN images.
 * - Serving JSON responses from those snapshots when offline mode is active.
 *
 * Snapshot structure:
 *   wp-content/uploads/cfp-dev-offline/
 *     {YYYY-MM-DD_HH-MM-SS}/
 *       api/
 *         public/event.json
 *         public/speakers.json
 *         public/speakers/{id}.json
 *         public/album/{id}.json
 *         public/talks.json
 *         public/talks/{id}.json
 *         public/talks/track/{id}.json
 *         public/talks/session-type/{id}.json
 *         public/tracks.json
 *         public/session-types.json
 *         public/rooms.json
 *         public/schedules/{Day}.json
 *         public/schedules/{Day}/{roomId}.json
 *       images/
 *         {md5(url)}.{ext}   (all CDN images keyed by URL hash)
 *       manifest.json        (fetch log, error count, metadata)
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// Register the WP Cron callback for the background crawl.
add_action( 'cfp_dev_do_crawl', 'cfp_dev_do_crawl' );

// ─────────────────────────────────────────────────────────────────────────────
// Path / URL helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns the absolute filesystem path to the offline snapshots root directory.
 */
function cfp_dev_offline_dir(): string {
	return WP_CONTENT_DIR . '/uploads/cfp-dev-offline';
}

/**
 * Returns the public URL to the offline snapshots root directory.
 */
function cfp_dev_offline_url(): string {
	return content_url( 'uploads/cfp-dev-offline' );
}

/**
 * Returns the absolute path to the latest *completed* snapshot (has manifest.json),
 * or an empty string when no completed snapshot exists.
 */
function cfp_dev_get_latest_snapshot(): string {
	$base = cfp_dev_offline_dir();
	if ( ! is_dir( $base ) ) {
		return '';
	}
	$dirs = glob( $base . '/[0-9]*', GLOB_ONLYDIR );
	if ( empty( $dirs ) ) {
		return '';
	}
	rsort( $dirs ); // newest first (lexicographic sort works for YYYY-MM-DD_HH-MM-SS)
	foreach ( $dirs as $dir ) {
		if ( file_exists( $dir . '/manifest.json' ) ) {
			return $dir;
		}
	}
	return '';
}

// ─────────────────────────────────────────────────────────────────────────────
// Offline JSON serving
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Reads and returns decoded JSON from the offline snapshot for the given API query path.
 *
 * The query string is stripped before the file lookup so that
 * `public/speakers?size=500` resolves to `{snapshot}/api/public/speakers.json`.
 *
 * @param string $queryPath  API path, e.g. 'public/speakers?size=500' or 'public/schedules/Tuesday'.
 * @return mixed  Decoded JSON (object|array) or null on failure.
 */
function cfp_dev_get_json_offline( string $queryPath ) {
	$snapshot = cfp_dev_get_latest_snapshot();
	if ( empty( $snapshot ) ) {
		cfp_dev_log( 'offline: no completed snapshot available for ' . $queryPath );
		return null;
	}

	// Strip query string for file lookup.
	$file_rel  = preg_replace( '/\?.*$/', '', $queryPath );
	$file_path = $snapshot . '/api/' . $file_rel . '.json';

	if ( ! file_exists( $file_path ) ) {
		cfp_dev_log( 'offline: snapshot file not found: ' . $file_rel . '.json' );
		return null;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local snapshot file
	$body    = file_get_contents( $file_path );
	$decoded = json_decode( $body );

	if ( json_last_error() !== JSON_ERROR_NONE ) {
		cfp_dev_log( 'offline: JSON decode error for ' . $file_rel . ': ' . json_last_error_msg() );
		return null;
	}

	return $decoded;
}

// ─────────────────────────────────────────────────────────────────────────────
// Crawl state management
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Merges the given key-value pairs into the cfp_dev_crawl_state option.
 *
 * @param array $updates  Associative array of fields to set/overwrite.
 */
function cfp_dev_update_crawl_state( array $updates ): void {
	$state = get_option( 'cfp_dev_crawl_state', [] );
	foreach ( $updates as $key => $value ) {
		$state[ $key ] = $value;
	}
	update_option( 'cfp_dev_crawl_state', $state );
}

/**
 * Creates a new dated snapshot directory, saves the initial crawl state, and
 * schedules the cfp_dev_do_crawl WP Cron event to fire in ~5 seconds.
 */
function cfp_dev_start_crawl(): void {
	$snapshot_name = gmdate( 'Y-m-d_H-i-s' );
	$snapshot      = cfp_dev_offline_dir() . '/' . $snapshot_name;

	wp_mkdir_p( $snapshot . '/api/public' );
	wp_mkdir_p( $snapshot . '/images' );

	update_option(
		'cfp_dev_crawl_state',
		[
			'status'        => 'pending',
			'step'          => 0,
			'step_label'    => 'Crawl scheduled, waiting for background job...',
			'items_done'    => 0,
			'items_total'   => 0,
			'snapshot'      => $snapshot,
			'snapshot_name' => $snapshot_name,
			'errors'        => 0,
			'finished_at'   => 0,
		]
	);

	// Remove any previously scheduled instance to avoid duplicates.
	wp_clear_scheduled_hook( 'cfp_dev_do_crawl' );
	wp_schedule_single_event( time() + 5, 'cfp_dev_do_crawl' );

	// wp_schedule_single_event() only ksorts within a given timestamp bucket,
	// not the outer timestamp level.  wp_get_ready_cron_jobs() iterates keys in
	// array insertion order and breaks on the first future timestamp it
	// encounters — meaning our past-due event is invisible if it happens to sit
	// after any future entry in the serialised array.  Explicitly re-sort the
	// outer level here to guarantee correct ordering.
	$cron = _get_cron_array();
	if ( is_array( $cron ) ) {
		ksort( $cron );
		_set_cron_array( $cron );
	}

	// spawn_cron() returns early (without spawning) when a fresh doing_cron
	// transient is already present from a previous failed attempt.  Delete any
	// stale lock first so the spawn always proceeds.
	delete_transient( 'doing_cron' );

	// Trigger wp-cron immediately so it doesn't wait for the next HTTP request.
	spawn_cron();

	cfp_dev_log( 'crawl: scheduled — snapshot=' . $snapshot_name );
}

// ─────────────────────────────────────────────────────────────────────────────
// Low-level crawl helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fetches one API endpoint and saves the raw response body as a JSON file
 * inside the snapshot directory.
 *
 * The query string is stripped from the path when determining the filename, so
 * `public/speakers?size=9999` is saved as `{snapshot}/api/public/speakers.json`.
 *
 * @param string $query_path   API path, e.g. 'public/speakers?size=9999'.
 * @param string $snapshot_dir Absolute path to the snapshot root.
 * @param array  $fetch_log    Log array, passed by reference.
 * @param int    $error_count  Error counter, passed by reference.
 * @param int    $timeout      HTTP timeout in seconds (default 30; use lower for optional endpoints like albums).
 * @param bool   $optional     When true, failures are logged but do not increment $error_count (used for albums where no content is normal).
 * @return mixed               Decoded JSON or null on failure.
 */
function cfp_dev_fetch_and_save( string $query_path, string $snapshot_dir, array &$fetch_log, int &$error_count, int $timeout = 30, bool $optional = false ) {
	$url       = CFP_DEV_URL_DOMAIN . $query_path;
	$file_rel  = preg_replace( '/\?.*$/', '', $query_path );
	$file_path = $snapshot_dir . '/api/' . $file_rel . '.json';

	wp_mkdir_p( dirname( $file_path ) );

	$response = wp_remote_get(
		$url,
		[
			'timeout' => $timeout,
			'headers' => [
				'Accept'     => CFP_DEV_APPLICATION_JSON,
				'Connection' => 'keep-alive',
			],
		]
	);

	if ( is_wp_error( $response ) ) {
		if ( ! $optional ) {
			++$error_count;
		}
		$fetch_log[] = [ 'url' => $url, 'status' => 'error', 'msg' => $response->get_error_message() ];
		cfp_dev_log( 'crawl fetch_error: ' . $query_path . ' — ' . $response->get_error_message() );
		return null;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );

	$fetch_log[] = [ 'url' => $url, 'status' => $code ];

	// 204 No Content means the endpoint is valid but has no data (e.g. room with no sessions).
	// Save an empty JSON array so offline reads return [] instead of null.
	if ( 204 === $code ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local snapshot file
		file_put_contents( $file_path, '[]' );
		cfp_dev_log( 'crawl HTTP 204 (empty): ' . $query_path );
		return [];
	}

	if ( 200 !== $code ) {
		if ( ! $optional ) {
			++$error_count;
		}
		cfp_dev_log( 'crawl HTTP ' . $code . ': ' . $query_path );
		return null;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing raw API response to local uploads dir
	file_put_contents( $file_path, $body );
	return json_decode( $body );
}

/**
 * Recursively walks a decoded JSON value (associative array form, from json_decode with true)
 * and collects unique external image URLs keyed by the known image field names.
 *
 * Populates $map with [ externalUrl => localFilename ] entries.
 * Field names are case-sensitive as documented from live API validation:
 *   - imageUrl      (lowercase 'l') — speaker profile photos
 *   - imageURL      (uppercase 'URL') — track images (nested and direct)
 *   - trackImageURL (uppercase 'URL') — flat denormalised field on talk detail
 *   - thumbnailUrl  (camelCase) — Flickr album thumbnails
 *
 * @param array  $data  A level of decoded JSON (PHP associative arrays from json_decode true).
 * @param array  $keys  The field names to collect (case-sensitive).
 * @param array  $map   Output map, passed by reference.
 */
function cfp_dev_collect_image_urls( array $data, array $keys, array &$map ): void {
	foreach ( $data as $k => $v ) {
		if ( is_string( $k ) && in_array( $k, $keys, true ) && is_string( $v ) && strlen( $v ) > 0 ) {
			if ( ! isset( $map[ $v ] ) ) {
				$url_path = parse_url( $v, PHP_URL_PATH ) ?? '';
				$raw_ext  = strtolower( pathinfo( $url_path, PATHINFO_EXTENSION ) );
				// Keep only safe 2-4 character extensions; fall back to jpg.
				$ext       = preg_match( '/^[a-z]{2,4}$/', $raw_ext ) ? $raw_ext : 'jpg';
				$map[ $v ] = md5( $v ) . '.' . $ext;
			}
		}
		if ( is_array( $v ) ) {
			cfp_dev_collect_image_urls( $v, $keys, $map );
		}
	}
}

/**
 * Downloads a single image URL to a local file path using wp_remote_get.
 *
 * @param string $url          External image URL.
 * @param string $dest_path    Absolute destination file path.
 * @param array  $fetch_log    Log array, passed by reference.
 * @param int    $error_count  Error counter, passed by reference.
 * @return bool                True on success, false on failure.
 */
function cfp_dev_download_image( string $url, string $dest_path, array &$fetch_log, int &$error_count ): bool {
	$response = wp_remote_get( $url, [ 'timeout' => 30 ] );

	if ( is_wp_error( $response ) ) {
		++$error_count;
		$fetch_log[] = [ 'url' => $url, 'status' => 'error', 'msg' => $response->get_error_message() ];
		cfp_dev_log( 'crawl image_error: ' . $url . ' — ' . $response->get_error_message() );
		return false;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		++$error_count;
		$fetch_log[] = [ 'url' => $url, 'status' => $code ];
		cfp_dev_log( 'crawl image HTTP ' . $code . ': ' . $url );
		return false;
	}

	wp_mkdir_p( dirname( $dest_path ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- binary image to local uploads dir
	file_put_contents( $dest_path, wp_remote_retrieve_body( $response ) );
	$fetch_log[] = [ 'url' => $url, 'status' => 200 ];
	return true;
}

// ─────────────────────────────────────────────────────────────────────────────
// Main crawl function (WP Cron callback)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Main WP Cron callback.  Runs a full 5-step crawl:
 *
 *   1. Fetch all API JSON endpoints and save raw responses to the snapshot.
 *   2. Walk every saved JSON file and build a deduplicated map of external image URLs.
 *   3. Download every image in that map to {snapshot}/images/.
 *   4. Rewrite every external image URL in every saved JSON file to its local equivalent.
 *   5. Write manifest.json and activate offline mode.
 *
 * Progress is written to the cfp_dev_crawl_state option so the admin JS
 * can poll it in real time.
 */
function cfp_dev_do_crawl(): void {
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- intentional long-running background process
	@set_time_limit( 600 );
	ignore_user_abort( true );

	$state    = get_option( 'cfp_dev_crawl_state', [] );
	$snapshot = $state['snapshot'] ?? '';

	if ( empty( $snapshot ) || ! is_dir( $snapshot ) ) {
		cfp_dev_update_crawl_state( [ 'status' => 'error', 'step_label' => 'Snapshot directory missing. Please start a new crawl.' ] );
		return;
	}

	$snapshot_name = basename( $snapshot );
	$error_count   = 0;
	$fetch_log     = [];

	// =========================================================================
	// STEP 1 — Fetch all API JSON, save raw bodies.
	// =========================================================================
	cfp_dev_update_crawl_state(
		[
			'status'      => 'running',
			'step'        => 1,
			'step_label'  => 'Fetching event metadata...',
			'items_done'  => 0,
			'items_total' => 0,
			'errors'      => 0,
		]
	);

	$event         = cfp_dev_fetch_and_save( 'public/event',         $snapshot, $fetch_log, $error_count );
	$tracks        = cfp_dev_fetch_and_save( 'public/tracks',        $snapshot, $fetch_log, $error_count );
	$session_types = cfp_dev_fetch_and_save( 'public/session-types', $snapshot, $fetch_log, $error_count );
	$rooms         = cfp_dev_fetch_and_save( 'public/rooms',         $snapshot, $fetch_log, $error_count );

	// --- Speakers -----------------------------------------------------------
	cfp_dev_update_crawl_state( [ 'step_label' => 'Fetching speakers list...' ] );
	$speakers    = cfp_dev_fetch_and_save( 'public/speakers?size=9999', $snapshot, $fetch_log, $error_count );
	$speaker_ids = [];
	if ( ! empty( $speakers ) && is_array( $speakers ) ) {
		foreach ( $speakers as $s ) {
			if ( ! empty( $s->id ) ) {
				$speaker_ids[] = $s->id;
			}
		}
	}

	$total_speakers = count( $speaker_ids );
	cfp_dev_update_crawl_state(
		[
			'step_label'  => 'Fetching speaker details...',
			'items_done'  => 0,
			'items_total' => $total_speakers * 2, // detail + album per speaker
		]
	);

	$done = 0;
	foreach ( $speaker_ids as $i => $sid ) {
		cfp_dev_fetch_and_save( 'public/speakers/' . $sid, $snapshot, $fetch_log, $error_count );
		++$done;
		if ( 0 === $i % 5 || $i === $total_speakers - 1 ) {
			cfp_dev_update_crawl_state( [ 'items_done' => $done ] );
		}
	}

	cfp_dev_update_crawl_state( [ 'step_label' => 'Fetching speaker photo albums...' ] );
	foreach ( $speaker_ids as $i => $sid ) {
		// 10 s timeout, optional: speakers without a Flickr album return nothing and would hang for 30 s each.
		cfp_dev_fetch_and_save( 'public/album/' . $sid, $snapshot, $fetch_log, $error_count, 10, true );
		++$done;
		if ( 0 === $i % 5 || $i === $total_speakers - 1 ) {
			cfp_dev_update_crawl_state( [ 'items_done' => $done ] );
		}
	}

	// --- Talks --------------------------------------------------------------
	cfp_dev_update_crawl_state(
		[
			'step_label'  => 'Fetching talks list...',
			'items_done'  => 0,
			'items_total' => 0,
		]
	);
	$talks    = cfp_dev_fetch_and_save( 'public/talks', $snapshot, $fetch_log, $error_count );
	$talk_ids = [];
	if ( ! empty( $talks ) && is_array( $talks ) ) {
		foreach ( $talks as $t ) {
			if ( ! empty( $t->id ) ) {
				$talk_ids[] = $t->id;
			}
		}
	}

	$total_talks = count( $talk_ids );
	cfp_dev_update_crawl_state(
		[
			'step_label'  => 'Fetching talk details...',
			'items_done'  => 0,
			'items_total' => $total_talks,
		]
	);

	$done = 0;
	foreach ( $talk_ids as $i => $tid ) {
		cfp_dev_fetch_and_save( 'public/talks/' . $tid, $snapshot, $fetch_log, $error_count );
		++$done;
		if ( 0 === $i % 5 || $i === $total_talks - 1 ) {
			cfp_dev_update_crawl_state( [ 'items_done' => $done ] );
		}
	}

	// --- Talks by track & session type --------------------------------------
	cfp_dev_update_crawl_state( [ 'step_label' => 'Fetching talks by track & session type...', 'items_done' => 0, 'items_total' => 0 ] );
	if ( ! empty( $tracks ) && is_array( $tracks ) ) {
		foreach ( $tracks as $track ) {
			if ( ! empty( $track->id ) ) {
				cfp_dev_fetch_and_save( 'public/talks/track/' . $track->id, $snapshot, $fetch_log, $error_count );
			}
		}
	}
	if ( ! empty( $session_types ) && is_array( $session_types ) ) {
		foreach ( $session_types as $st ) {
			if ( ! empty( $st->id ) ) {
				cfp_dev_fetch_and_save( 'public/talks/session-type/' . $st->id, $snapshot, $fetch_log, $error_count );
			}
		}
	}

	// --- Schedules (all days × all rooms) -----------------------------------
	cfp_dev_update_crawl_state( [ 'step_label' => 'Fetching schedules...' ] );

	$event_days = [];
	if ( ! empty( $event->fromDate ) ) {
		// Extract date portion only ("2025-03-25T07:30:00Z" → "2025-03-25").
		$from_str = substr( $event->fromDate, 0, 10 );
		$to_str   = ! empty( $event->toDate ) ? substr( $event->toDate, 0, 10 ) : $from_str;
		$current  = new DateTime( $from_str );
		$end      = new DateTime( $to_str );
		while ( $current <= $end ) {
			$event_days[] = $current->format( 'l' ); // e.g. "Tuesday" — matches shortcode format
			$current->modify( '+1 day' );
		}
	}

	$room_ids = [];
	if ( ! empty( $rooms ) && is_array( $rooms ) ) {
		foreach ( $rooms as $room ) {
			if ( ! empty( $room->id ) ) {
				$room_ids[] = $room->id;
			}
		}
	}

	foreach ( $event_days as $day ) {
		cfp_dev_fetch_and_save( 'public/schedules/' . $day, $snapshot, $fetch_log, $error_count );
		foreach ( $room_ids as $rid ) {
			cfp_dev_fetch_and_save( 'public/schedules/' . $day . '/' . $rid, $snapshot, $fetch_log, $error_count );
		}
	}

	cfp_dev_update_crawl_state( [ 'errors' => $error_count ] );

	// =========================================================================
	// STEP 2 — Collect all external image URLs from every saved JSON file.
	// =========================================================================
	cfp_dev_update_crawl_state(
		[
			'step'        => 2,
			'step_label'  => 'Collecting image URLs from saved JSON...',
			'items_done'  => 0,
			'items_total' => 0,
		]
	);

	$json_files = [];
	$api_dir    = $snapshot . '/api';
	if ( is_dir( $api_dir ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $api_dir ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() && 'json' === strtolower( $file->getExtension() ) ) {
				$json_files[] = $file->getPathname();
			}
		}
	}

	// Image field names — case-sensitive as validated from live API.
	$image_keys    = [ 'imageUrl', 'imageURL', 'trackImageURL', 'thumbnailUrl' ];
	$image_url_map = []; // [ externalUrl => localFilename ]

	foreach ( $json_files as $json_file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local snapshot file
		$content = file_get_contents( $json_file );
		$data    = json_decode( $content, true ); // assoc arrays for recursive walk
		if ( is_array( $data ) ) {
			cfp_dev_collect_image_urls( $data, $image_keys, $image_url_map );
		}
	}

	cfp_dev_log( 'crawl: found ' . count( $image_url_map ) . ' unique image URLs' );

	// =========================================================================
	// STEP 3 — Download every image to {snapshot}/images/.
	// =========================================================================
	cfp_dev_update_crawl_state(
		[
			'step'        => 3,
			'step_label'  => 'Downloading images...',
			'items_done'  => 0,
			'items_total' => count( $image_url_map ),
		]
	);

	wp_mkdir_p( $snapshot . '/images' );

	$image_url_rewrite = []; // [ externalUrl => fullLocalUrl ] used in step 4
	$done              = 0;

	foreach ( $image_url_map as $ext_url => $local_filename ) {
		$dest      = $snapshot . '/images/' . $local_filename;
		$local_url = cfp_dev_offline_url() . '/' . $snapshot_name . '/images/' . $local_filename;

		if ( ! file_exists( $dest ) ) {
			cfp_dev_download_image( $ext_url, $dest, $fetch_log, $error_count );
		} else {
			$fetch_log[] = [ 'url' => $ext_url, 'status' => 'cached' ];
		}

		$image_url_rewrite[ $ext_url ] = $local_url;
		++$done;
		if ( 0 === $done % 5 ) {
			cfp_dev_update_crawl_state( [ 'items_done' => $done, 'errors' => $error_count ] );
		}
	}
	cfp_dev_update_crawl_state( [ 'items_done' => $done, 'errors' => $error_count ] );

	// =========================================================================
	// STEP 4 — Rewrite external image URLs in every saved JSON file.
	// =========================================================================
	cfp_dev_update_crawl_state(
		[
			'step'        => 4,
			'step_label'  => 'Rewriting image URLs in JSON files...',
			'items_done'  => 0,
			'items_total' => count( $json_files ),
		]
	);

	$done            = 0;
	$ext_url_strings = array_keys( $image_url_rewrite );
	$local_url_strings = array_values( $image_url_rewrite );

	foreach ( $json_files as $json_file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local snapshot file
		$content = file_get_contents( $json_file );
		$content = str_replace( $ext_url_strings, $local_url_strings, $content );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local snapshot file
		file_put_contents( $json_file, $content );
		++$done;
		if ( 0 === $done % 10 ) {
			cfp_dev_update_crawl_state( [ 'items_done' => $done ] );
		}
	}
	cfp_dev_update_crawl_state( [ 'items_done' => $done ] );

	// =========================================================================
	// STEP 5 — Write manifest.json and activate offline mode.
	// =========================================================================
	cfp_dev_update_crawl_state(
		[
			'step'       => 5,
			'step_label' => 'Finalizing snapshot...',
		]
	);

	$manifest = [
		'created_at'    => gmdate( 'c' ),
		'snapshot_name' => $snapshot_name,
		'stats'         => [
			'speakers' => count( $speaker_ids ),
			'talks'    => count( $talk_ids ),
			'images'   => count( $image_url_map ),
			'errors'   => $error_count,
		],
		'log'           => $fetch_log,
	];

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local snapshot file
	file_put_contents( $snapshot . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

	// Activate offline mode — from this point getJSON() serves from snapshot.
	update_option( 'cfp_dev_offline_mode', 1 );

	cfp_dev_update_crawl_state(
		[
			'status'      => 'done',
			'step'        => 5,
			'step_label'  => 'Crawl complete! Offline mode is now active.',
			'errors'      => $error_count,
			'finished_at' => time(),
		]
	);

	cfp_dev_log( 'crawl: complete — snapshot=' . $snapshot_name . ', speakers=' . count( $speaker_ids ) . ', talks=' . count( $talk_ids ) . ', images=' . count( $image_url_map ) . ', errors=' . $error_count );
}
