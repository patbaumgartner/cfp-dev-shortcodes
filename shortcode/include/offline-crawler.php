<?php
/**
 * CFP.DEV shortcodes
 *
 * Offline mode — crawler & snapshot manager. Builds local snapshots of all
 * API JSON and CDN images, and serves JSON responses from those snapshots
 * when offline mode is active.
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
 *
 * @package  CFP.DEV
 * @since    4.1.0
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

/**
 * Deletes all but the newest $keep completed snapshots.
 * Prevents unbounded disk growth — every re-crawl creates a full new snapshot
 * (all JSON + all images).
 *
 * @param int $keep  Number of snapshots to retain (newest first).
 */
function cfp_dev_prune_snapshots( int $keep = 2 ): void {
	$base = cfp_dev_offline_dir();
	if ( ! is_dir( $base ) ) {
		return;
	}
	$dirs = glob( $base . '/[0-9]*', GLOB_ONLYDIR );
	if ( empty( $dirs ) || count( $dirs ) <= $keep ) {
		return;
	}
	rsort( $dirs ); // newest first

	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;

	foreach ( array_slice( $dirs, $keep ) as $old_dir ) {
		if ( $wp_filesystem && $wp_filesystem->delete( $old_dir, true ) ) {
			cfp_dev_log( 'crawl: pruned old snapshot ' . basename( $old_dir ) );
		} else {
			cfp_dev_log( 'crawl: failed to prune snapshot ' . basename( $old_dir ) );
		}
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// Offline JSON serving
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Reads the raw JSON body for an API query path from the offline snapshot.
 *
 * The query string is stripped before the file lookup so that
 * `public/speakers?size=500` resolves to `{snapshot}/api/public/speakers.json`.
 *
 * @param string $query_path  API path, e.g. 'public/speakers?size=500' or 'public/schedules/Tuesday'.
 * @return string|null  Raw JSON body, or null when unavailable or malformed.
 */
function cfp_dev_read_snapshot_body( string $query_path ) {
	$snapshot = cfp_dev_get_latest_snapshot();
	if ( empty( $snapshot ) ) {
		cfp_dev_log( 'offline: no completed snapshot available for ' . $query_path );
		return null;
	}

	// Strip query string for file lookup.
	$file_rel  = preg_replace( '/\?.*$/', '', $query_path );
	$file_path = $snapshot . '/api/' . $file_rel . '.json';

	if ( ! file_exists( $file_path ) ) {
		cfp_dev_log( 'offline: snapshot file not found — ' . $file_rel . '.json' );
		return null;
	}

	// Containment check: the resolved path must stay inside the snapshot's api
	// directory — query paths can embed user-supplied ids.
	$real_base = realpath( $snapshot . '/api' );
	$real_file = realpath( $file_path );
	if ( false === $real_base || false === $real_file || ! str_starts_with( $real_file, $real_base . DIRECTORY_SEPARATOR ) ) {
		cfp_dev_log( 'offline: rejected path outside snapshot — ' . $query_path );
		return null;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local snapshot file
	$body = file_get_contents( $real_file );

	json_decode( (string) $body );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		cfp_dev_log( 'offline: JSON decode error for ' . $file_rel . ' — ' . json_last_error_msg() );
		return null;
	}

	return $body;
}

/**
 * Reads and returns decoded JSON from the offline snapshot.
 *
 * @param string $query_path  API path, e.g. 'public/speakers?size=500'.
 * @return mixed  Decoded JSON (object|array) or null on failure.
 */
function cfp_dev_get_json_offline( string $query_path ) {
	$body = cfp_dev_read_snapshot_body( $query_path );
	return is_string( $body ) ? json_decode( $body ) : null;
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
 * Writes the silence files that keep a snapshot from being browsable.
 *
 * Snapshots live under wp-content/uploads, which is web-served. On a host with
 * directory indexing enabled the whole crawl — every API response plus a
 * manifest listing each URL fetched — would otherwise be listable by anyone.
 *
 * @param string $snapshot  Absolute path to the snapshot root.
 */
function cfp_dev_protect_snapshot_dir( string $snapshot ): void {
	$roots = [ cfp_dev_offline_dir(), $snapshot, $snapshot . '/api', $snapshot . '/api/public', $snapshot . '/images' ];

	foreach ( $roots as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local uploads dir
			file_put_contents( $index, "<?php // Silence is golden.\n" );
		}
	}

	$htaccess = cfp_dev_offline_dir() . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local uploads dir
		file_put_contents( $htaccess, "Options -Indexes\n" );
	}
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
	cfp_dev_protect_snapshot_dir( $snapshot );

	update_option(
		'cfp_dev_crawl_state',
		[
			'status'        => 'pending',
			'step'          => 0,
			'step_label'    => __( 'Crawl scheduled, waiting for background job...', 'cfp-dev-shortcodes' ),
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
 * Resolves an API query path to its snapshot file path, or null when the path
 * would escape the snapshot's api/ directory.
 *
 * The query string is stripped first, so `public/speakers?size=500` maps to
 * `{snapshot}/api/public/speakers.json`. Containment is checked on the
 * normalised string rather than realpath() because the file does not exist yet
 * when this runs for a write.
 *
 * @param string $query_path    API path, e.g. 'public/speakers/12'.
 * @param string $snapshot_dir  Absolute path to the snapshot root.
 * @return string|null  Absolute file path, or null when rejected.
 */
function cfp_dev_snapshot_file_path( string $query_path, string $snapshot_dir ): ?string {
	if (
		str_contains( $query_path, '..' )
		|| str_contains( $query_path, "\0" )
		|| str_contains( $query_path, '\\' )
		|| str_starts_with( $query_path, '/' )
	) {
		return null;
	}

	$file_rel = (string) preg_replace( '/\?.*$/', '', $query_path );
	if ( '' === $file_rel ) {
		return null;
	}

	$base      = wp_normalize_path( $snapshot_dir . '/api/' );
	$file_path = wp_normalize_path( $base . $file_rel . '.json' );

	if ( ! str_starts_with( $file_path, $base ) ) {
		return null;
	}

	return $file_path;
}

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
	// Snapshot *reads* are containment-checked; writes must be too. Every id in
	// a query path comes from the upstream API, so a hostile or compromised CFP
	// instance would otherwise choose where the response body lands on disk.
	$file_path = cfp_dev_snapshot_file_path( $query_path, $snapshot_dir );
	if ( null === $file_path ) {
		if ( ! $optional ) {
			++$error_count;
		}
		$fetch_log[] = [
			'url'    => $query_path,
			'status' => 'error',
			'msg'    => 'rejected unsafe query path',
		];
		cfp_dev_log( 'crawl: rejected unsafe query path — ' . $query_path );
		return null;
	}

	$url = cfp_dev_api_base() . $query_path;

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
		$fetch_log[] = [
			'url'    => $url,
			'status' => 'error',
			'msg'    => $response->get_error_message(),
		];
		cfp_dev_log( 'crawl: fetch error for ' . $query_path . ' — ' . $response->get_error_message() );
		return null;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );

	$fetch_log[] = [
		'url'    => $url,
		'status' => $code,
	];

	// 204 No Content means the endpoint is valid but has no data (e.g. room with no sessions).
	// Save an empty JSON array so offline reads return [] instead of null.
	if ( 204 === $code ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local snapshot file
		if ( false === file_put_contents( $file_path, '[]' ) ) {
			++$error_count;
			cfp_dev_log( 'crawl: failed to write ' . $file_path );
			return null;
		}
		cfp_dev_log( 'crawl: HTTP 204 (empty) for ' . $query_path );
		return [];
	}

	if ( 200 !== $code ) {
		if ( ! $optional ) {
			++$error_count;
		}
			cfp_dev_log( 'crawl: HTTP ' . $code . ' for ' . $query_path );
		return null;
	}

	// A silent write failure (full disk, bad permissions) would otherwise leave
	// a gap in the snapshot that only surfaces once offline mode is serving it.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing raw API response to local uploads dir
	if ( false === file_put_contents( $file_path, $body ) ) {
		++$error_count;
		cfp_dev_log( 'crawl: failed to write ' . $file_path );
		return null;
	}

	return json_decode( $body );
}

/** Image extensions a snapshot file is allowed to carry. */
function cfp_dev_allowed_image_extensions(): array {
	return [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg' ];
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
				$url_path = (string) ( wp_parse_url( $v, PHP_URL_PATH ) ?? '' );
				$raw_ext  = strtolower( pathinfo( $url_path, PATHINFO_EXTENSION ) );
				// Allowlist, not a shape check: a '/^[a-z]{2,4}$/' pattern happily
				// accepts 'php', which would land an attacker-controlled response
				// body in uploads/ under a name the web server may execute.
				$ext       = in_array( $raw_ext, cfp_dev_allowed_image_extensions(), true ) ? $raw_ext : 'jpg';
				$map[ $v ] = md5( $v ) . '.' . $ext;
			}
		}
		if ( is_array( $v ) ) {
			cfp_dev_collect_image_urls( $v, $keys, $map );
		}
	}
}

/**
 * Replaces external image URLs with their local equivalents in a decoded JSON
 * value, walking objects and arrays.
 *
 * This runs on the decoded structure rather than the raw text: a textual
 * search-and-replace only matches when the API's JSON encoding of the URL is
 * byte-identical to the collected string, so escaped forward slashes (or one
 * URL being a prefix of another) would leave images pointing at the CDN —
 * silently defeating offline mode, which promises no external requests.
 *
 * @param mixed $data  Decoded JSON value (object, array or scalar).
 * @param array $keys  Image field names to rewrite.
 * @param array $map   [ externalUrl => localUrl ].
 * @return mixed  The value with image URLs replaced.
 */
function cfp_dev_rewrite_image_urls( $data, array $keys, array $map ) {
	if ( is_array( $data ) ) {
		foreach ( $data as $index => $value ) {
			$data[ $index ] = cfp_dev_rewrite_image_urls( $value, $keys, $map );
		}
		return $data;
	}

	if ( $data instanceof stdClass ) {
		foreach ( get_object_vars( $data ) as $field => $value ) {
			if ( in_array( $field, $keys, true ) && is_string( $value ) && isset( $map[ $value ] ) ) {
				$data->$field = $map[ $value ];
				continue;
			}
			$data->$field = cfp_dev_rewrite_image_urls( $value, $keys, $map );
		}
	}

	return $data;
}

/**
 * Downloads a single image URL to a local file path.
 *
 * Uses wp_safe_remote_get(), which refuses private and loopback addresses:
 * these URLs come from the upstream API, so a compromised or hostile CFP
 * instance would otherwise turn every crawl into a server-side request
 * generator pointed at the host's internal network — with the response body
 * published under a predictable uploads URL.
 *
 * @param string $url          External image URL.
 * @param string $dest_path    Absolute destination file path.
 * @param array  $fetch_log    Log array, passed by reference.
 * @param int    $error_count  Error counter, passed by reference.
 * @return bool                True on success, false on failure.
 */
function cfp_dev_download_image( string $url, string $dest_path, array &$fetch_log, int &$error_count ): bool {
	$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
		++$error_count;
		$fetch_log[] = [
			'url'    => $url,
			'status' => 'error',
			'msg'    => 'unsupported scheme',
		];
		cfp_dev_log( 'crawl: rejected non-HTTP image URL — ' . $url );
		return false;
	}

	$response = wp_safe_remote_get(
		$url,
		[
			'timeout'             => 30,
			'limit_response_size' => 10 * MB_IN_BYTES,
		]
	);

	if ( is_wp_error( $response ) ) {
		++$error_count;
		$fetch_log[] = [
			'url'    => $url,
			'status' => 'error',
			'msg'    => $response->get_error_message(),
		];
			cfp_dev_log( 'crawl: image error for ' . $url . ' — ' . $response->get_error_message() );
		return false;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		++$error_count;
		$fetch_log[] = [
			'url'    => $url,
			'status' => $code,
		];
			cfp_dev_log( 'crawl: image HTTP ' . $code . ' for ' . $url );
		return false;
	}

	// Trust the served content type over the URL's extension — the filename was
	// derived from a string the API controls.
	$content_type = strtolower( trim( (string) strtok( (string) wp_remote_retrieve_header( $response, 'content-type' ), ';' ) ) );
	$by_type      = [
		'image/jpeg'    => 'jpg',
		'image/png'     => 'png',
		'image/gif'     => 'gif',
		'image/webp'    => 'webp',
		'image/avif'    => 'avif',
		'image/svg+xml' => 'svg',
	];

	if ( ! isset( $by_type[ $content_type ] ) ) {
		++$error_count;
		$fetch_log[] = [
			'url'    => $url,
			'status' => 'error',
			'msg'    => 'non-image content type: ' . $content_type,
		];
		cfp_dev_log( 'crawl: rejected non-image response for ' . $url . ' — ' . $content_type );
		return false;
	}

	wp_mkdir_p( dirname( $dest_path ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- binary image to local uploads dir
	$written = file_put_contents( $dest_path, wp_remote_retrieve_body( $response ) );

	if ( false === $written ) {
		++$error_count;
		$fetch_log[] = [
			'url'    => $url,
			'status' => 'error',
			'msg'    => 'write failed',
		];
		cfp_dev_log( 'crawl: failed to write image ' . $dest_path );
		return false;
	}

	$fetch_log[] = [
		'url'    => $url,
		'status' => 200,
	];
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
		cfp_dev_update_crawl_state(
			[
				'status'     => 'error',
				'step_label' => __( 'Snapshot directory missing. Please start a new crawl.', 'cfp-dev-shortcodes' ),
			]
		);
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
			'step_label'  => __( 'Fetching event metadata...', 'cfp-dev-shortcodes' ),
			'items_done'  => 0,
			'items_total' => 0,
			'errors'      => 0,
		]
	);

	$event         = cfp_dev_fetch_and_save( 'public/event', $snapshot, $fetch_log, $error_count );
	$tracks        = cfp_dev_fetch_and_save( 'public/tracks', $snapshot, $fetch_log, $error_count );
	$session_types = cfp_dev_fetch_and_save( 'public/session-types', $snapshot, $fetch_log, $error_count );
	$rooms         = cfp_dev_fetch_and_save( 'public/rooms', $snapshot, $fetch_log, $error_count );

	// --- Speakers -----------------------------------------------------------
	cfp_dev_update_crawl_state( [ 'step_label' => __( 'Fetching speakers list...', 'cfp-dev-shortcodes' ) ] );
	$speakers    = cfp_dev_fetch_and_save( 'public/speakers?size=9999', $snapshot, $fetch_log, $error_count );
	$speaker_ids = cfp_dev_collect_ids( $speakers );

	$total_speakers = count( $speaker_ids );
	cfp_dev_update_crawl_state(
		[
			'step_label'  => __( 'Fetching speaker details...', 'cfp-dev-shortcodes' ),
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

	cfp_dev_update_crawl_state( [ 'step_label' => __( 'Fetching speaker photo albums...', 'cfp-dev-shortcodes' ) ] );
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
			'step_label'  => __( 'Fetching talks list...', 'cfp-dev-shortcodes' ),
			'items_done'  => 0,
			'items_total' => 0,
		]
	);
	$talks    = cfp_dev_fetch_and_save( 'public/talks', $snapshot, $fetch_log, $error_count );
	$talk_ids = cfp_dev_collect_ids( $talks );

	$total_talks = count( $talk_ids );
	cfp_dev_update_crawl_state(
		[
			'step_label'  => __( 'Fetching talk details...', 'cfp-dev-shortcodes' ),
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
	cfp_dev_update_crawl_state(
		[
			'step_label'  => __( 'Fetching talks by track & session type...', 'cfp-dev-shortcodes' ),
			'items_done'  => 0,
			'items_total' => 0,
		]
	);
	foreach ( cfp_dev_collect_ids( $tracks ) as $track_id ) {
		cfp_dev_fetch_and_save( 'public/talks/track/' . $track_id, $snapshot, $fetch_log, $error_count );
	}
	foreach ( cfp_dev_collect_ids( $session_types ) as $session_type_id ) {
		cfp_dev_fetch_and_save( 'public/talks/session-type/' . $session_type_id, $snapshot, $fetch_log, $error_count );
	}

	// --- Schedules (all days × all rooms) -----------------------------------
	cfp_dev_update_crawl_state( [ 'step_label' => __( 'Fetching schedules...', 'cfp-dev-shortcodes' ) ] );

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

	$room_ids = cfp_dev_collect_ids( $rooms );

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
			'step_label'  => __( 'Collecting image URLs from saved JSON...', 'cfp-dev-shortcodes' ),
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
			'step_label'  => __( 'Downloading images...', 'cfp-dev-shortcodes' ),
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
			$fetch_log[] = [
				'url'    => $ext_url,
				'status' => 'cached',
			];
		}

		$image_url_rewrite[ $ext_url ] = $local_url;
		++$done;
		if ( 0 === $done % 5 ) {
			cfp_dev_update_crawl_state(
				[
					'items_done' => $done,
					'errors'     => $error_count,
				]
			);
		}
	}
	cfp_dev_update_crawl_state(
		[
			'items_done' => $done,
			'errors'     => $error_count,
		]
	);

	// =========================================================================
	// STEP 4 — Rewrite external image URLs in every saved JSON file.
	// =========================================================================
	cfp_dev_update_crawl_state(
		[
			'step'        => 4,
			'step_label'  => __( 'Rewriting image URLs in JSON files...', 'cfp-dev-shortcodes' ),
			'items_done'  => 0,
			'items_total' => count( $json_files ),
		]
	);

	$done = 0;

	foreach ( $json_files as $json_file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local snapshot file
		$decoded = json_decode( (string) file_get_contents( $json_file ) );

		if ( JSON_ERROR_NONE === json_last_error() ) {
			$rewritten = wp_json_encode( cfp_dev_rewrite_image_urls( $decoded, $image_keys, $image_url_rewrite ) );
			if ( is_string( $rewritten ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local snapshot file
				file_put_contents( $json_file, $rewritten );
			}
		}

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
			'step_label' => __( 'Finalizing snapshot...', 'cfp-dev-shortcodes' ),
		]
	);

	// A snapshot with no talks and no speakers is not a snapshot — it is the
	// record of a crawl that failed (no API key, API unreachable, disk full).
	// Writing manifest.json would mark it "complete", and activating offline
	// mode would then serve that emptiness as the site's content.
	if ( empty( $speaker_ids ) && empty( $talk_ids ) ) {
		cfp_dev_update_crawl_state(
			[
				'status'      => 'error',
				'step_label'  => __( 'Crawl produced no talks or speakers — offline mode was left off. Check the CFP.DEV key and that the API is reachable.', 'cfp-dev-shortcodes' ),
				'errors'      => $error_count,
				'finished_at' => time(),
			]
		);
		cfp_dev_log( 'crawl: aborted — snapshot empty, offline mode not activated (errors=' . $error_count . ')' );
		return;
	}

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

	// manifest.json is what marks a snapshot complete and selectable, so a
	// failed write must not be mistaken for a finished crawl.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local snapshot file
	if ( false === file_put_contents( $snapshot . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) ) ) {
		cfp_dev_update_crawl_state(
			[
				'status'      => 'error',
				'step_label'  => __( 'Could not write manifest.json — offline mode was left off. Check filesystem permissions on wp-content/uploads.', 'cfp-dev-shortcodes' ),
				'errors'      => $error_count + 1,
				'finished_at' => time(),
			]
		);
		cfp_dev_log( 'crawl: failed to write manifest.json, offline mode not activated' );
		return;
	}

	// Activate offline mode — from this point cfp_dev_get_json() serves from snapshot.
	update_option( 'cfp_dev_offline_mode', 1 );

	// Invalidate all rendered-HTML transients: they were generated from live
	// API data and still embed external image URLs. Bumping the cache version
	// forces every shortcode to re-render against the snapshot.
	cfp_dev_clear_cache();

	// Retention: drop everything but the newest snapshots.
	cfp_dev_prune_snapshots( 2 );

	cfp_dev_update_crawl_state(
		[
			'status'      => 'done',
			'step'        => 5,
			'step_label'  => __( 'Crawl complete! Offline mode is now active.', 'cfp-dev-shortcodes' ),
			'errors'      => $error_count,
			'finished_at' => time(),
		]
	);

	cfp_dev_log( 'crawl: complete — snapshot=' . $snapshot_name . ', speakers=' . count( $speaker_ids ) . ', talks=' . count( $talk_ids ) . ', images=' . count( $image_url_map ) . ', errors=' . $error_count );
}
