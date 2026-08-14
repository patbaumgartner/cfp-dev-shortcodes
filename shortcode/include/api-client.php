<?php
/**
 * CFP.DEV shortcodes
 *
 * CFP.DEV API client: fetching, decoding and slug resolution, offline-aware.
 *
 * @package CFP.DEV
 */

if ( ! defined( 'WPINC' ) ) {
	die;
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
 * @param string $query_path  Relative API path, e.g. 'public/speakers?size=500'.
 * @return mixed  Decoded JSON (object|array) or null on failure.
 */
function cfp_dev_get_json( $query_path ) {
	// Reject path traversal — query paths are relative API routes and several
	// callers interpolate user-supplied ids (also guards offline file lookups).
	if ( str_contains( $query_path, '..' ) || str_starts_with( $query_path, '/' ) ) {
		cfp_dev_log( 'cfp_dev_get_json: rejected suspicious query path — ' . $query_path );
		return null;
	}

	$body = cfp_dev_request_cache_get(
		'api:' . $query_path,
		static function () use ( $query_path ) {
			return cfp_dev_fetch_json_body( $query_path );
		}
	);

	if ( ! is_string( $body ) ) {
		return null;
	}

	$decoded = json_decode( $body );

	if ( JSON_ERROR_NONE !== json_last_error() ) {
		cfp_dev_note_api_failure();
		cfp_dev_log( 'cfp_dev_get_json: JSON decode error for ' . $query_path . ' — ' . json_last_error_msg() );
		return null;
	}

	return $decoded;
}

/**
 * Timeout in seconds for a live API request.
 *
 * Shorter in the admin: the settings screen fetches the speaker and talk lists
 * only to show which caches exist, so an unreachable API would otherwise hold
 * the page for a full 30 seconds per list before rendering anything.
 *
 * Shorter again for search. What justifies waiting 30 seconds elsewhere is
 * that the answer populates a cache, so at most one visitor pays it. Search
 * results are never cached — the query space is unbounded — so every request
 * pays in full, on a public URL, and a site with ten PHP workers is taken down
 * by ten slow searches. A search that has not answered in a few seconds is of
 * no use to the person waiting for it anyway.
 *
 * @param string $query_path  Relative API path the timeout is for.
 * @return int
 */
function cfp_dev_api_timeout( string $query_path = '' ): int {
	if ( str_starts_with( $query_path, CFP_DEV_SEARCH_PATH ) ) {
		$timeout = 8;
	} elseif ( is_admin() ) {
		$timeout = 10;
	} else {
		$timeout = 30;
	}

	/**
	 * Filters the HTTP timeout used for CFP.DEV API requests.
	 *
	 * @param int    $timeout     Timeout in seconds.
	 * @param string $query_path  Relative API path the timeout is for.
	 */
	return max( 1, (int) apply_filters( 'cfp_dev_api_timeout', $timeout, $query_path ) );
}

/**
 * Returns the raw JSON body for an API path, from the offline snapshot when
 * offline mode is active and from the live API otherwise.
 *
 * @param string $query_path  Relative API path (already validated).
 * @return string|null  Raw JSON body, or null when the lookup failed.
 */
function cfp_dev_fetch_json_body( $query_path ) {
	// Offline mode: serve from local snapshot instead of the live API.
	if ( get_option( 'cfp_dev_offline_mode', 0 ) ) {
		if ( '' !== cfp_dev_get_latest_snapshot() ) {
			// A snapshot exists — stay offline. A null here means this specific
			// resource is not in the snapshot (unknown id, uncrawlable endpoint
			// like public/search): treat it as "not found" rather than falling
			// back to the live API and silently leaving offline mode.
			return cfp_dev_read_snapshot_body( $query_path );
		}
		/*
		 * No completed snapshot at all — serve live so the site keeps working.
		 *
		 * The setting is deliberately left alone. Turning it off here meant an
		 * anonymous page view rewrote site configuration and bumped the cache
		 * version, discarding every cached page — from a read path, on every
		 * racing request, for a condition the admin screen already detects and
		 * reports. The operator's choice survives until they change it.
		 */
		cfp_dev_log( 'cfp_dev_get_json: offline mode is on but no snapshot exists — serving live for ' . $query_path );
	}

	if ( '' === cfp_dev_get_key() ) {
		cfp_dev_note_api_failure();
		cfp_dev_log( 'cfp_dev_get_json: no CFP.DEV key configured, skipping ' . $query_path );
		return null;
	}

	$response = wp_remote_get(
		cfp_dev_api_base() . $query_path,
		[
			'timeout' => cfp_dev_api_timeout( $query_path ),
			'headers' => [
				'Accept'     => CFP_DEV_APPLICATION_JSON,
				'Connection' => 'keep-alive',
			],
		]
	);

	if ( is_wp_error( $response ) ) {
		cfp_dev_note_api_failure();
		cfp_dev_log( 'cfp_dev_get_json: error for ' . $query_path . ' — ' . $response->get_error_message() );
		return null;
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $status_code ) {
		// A 404 is an answer: the resource is not there. Anything else is the
		// API failing to give one, which callers must not read as absence.
		if ( 404 !== (int) $status_code ) {
			cfp_dev_note_api_failure();
		}
		cfp_dev_log( 'cfp_dev_get_json: HTTP ' . $status_code . ' for ' . $query_path );
		return null;
	}

	cfp_dev_log( 'cfp_dev_get_json: OK ' . $query_path );
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

	// Timed out as the search path it is: this runs on the uncached public
	// search page, and again for the related-talks list on a talk page.
	$safe_query = rawurlencode( sanitize_text_field( $query ) );
	$response   = wp_remote_get( cfp_dev_search_base() . $safe_query, [ 'timeout' => cfp_dev_api_timeout( CFP_DEV_SEARCH_PATH ) ] );
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
 * Extracts the positive integer ids from a decoded API list response.
 *
 * Ids reach filesystem paths and API URLs, so they are narrowed to integers
 * here rather than trusted as the API sent them.
 *
 * @param mixed $records  Decoded list response.
 * @return int[]
 */
function cfp_dev_collect_ids( $records ): array {
	if ( empty( $records ) || ! is_array( $records ) ) {
		return [];
	}

	$ids = [];
	foreach ( $records as $record ) {
		$id = absint( $record->id ?? 0 );
		if ( $id > 0 ) {
			$ids[] = $id;
		}
	}
	return $ids;
}

/**
 * The ids of every speaker the configured event publishes, cached for the
 * configured TTL and memoised for the request.
 *
 * @return int[]
 */
function cfp_dev_known_speaker_ids(): array {
	return cfp_dev_request_cache_get(
		'known_speaker_ids',
		static function () {
			$ttl = cfp_dev_get_cache_ttl();
			$key = cfp_dev_group_cache_key( 'cfp_known_speaker_ids' );

			if ( $ttl > 0 ) {
				$cached = get_transient( $key );
				if ( is_array( $cached ) ) {
					return $cached;
				}
			}

			$ids = cfp_dev_collect_ids( cfp_dev_get_json( 'public/speakers?size=' . CFP_DEV_SPEAKERS_FETCH_SIZE ) );

			if ( $ttl > 0 && ! empty( $ids ) ) {
				set_transient( $key, $ids, $ttl );
			}

			return $ids;
		}
	);
}

/**
 * Whether $id identifies a speaker of the configured event.
 *
 * Returns false when the speaker list cannot be fetched: no list means no
 * speaker page was rendered either, so there is no legitimate request to serve.
 *
 * @param int $id  Speaker id.
 */
function cfp_dev_speaker_exists( $id ): bool {
	return in_array( absint( $id ), cfp_dev_known_speaker_ids(), true );
}

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
 * Resolves a slug to an entity id by scanning a list endpoint.
 *
 * Resolving a slug costs a full list fetch, so results are cached — hits for
 * the configured TTL, and misses always for at least a few minutes even when
 * caching is switched off: these lookups run on public URLs, so a loop over
 * made-up slugs would otherwise refetch the whole list on every request.
 *
 * @param string   $cache_prefix  Transient key prefix, e.g. 'cfp_speaker_slug_'.
 * @param string   $endpoint      List endpoint to scan.
 * @param string   $slug          Slug to resolve.
 * @param callable $slug_of       Returns the slug for one record.
 * @return int|null  Entity id, or null when the slug is unknown.
 */
function cfp_dev_resolve_slug( string $cache_prefix, string $endpoint, string $slug, callable $slug_of ) {
	$cache_key = cfp_dev_group_cache_key( $cache_prefix . md5( $slug ) );
	$id        = get_transient( $cache_key );

	if ( false === $id ) {
		$id      = 0;
		$records = cfp_dev_get_json( $endpoint );

		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( $slug_of( $record ) === $slug ) {
					$id = (int) ( $record->id ?? 0 );
					break;
				}
			}

			$ttl = cfp_dev_get_cache_ttl();
			if ( ! $id ) {
				set_transient( $cache_key, 0, max( $ttl, 5 * MINUTE_IN_SECONDS ) );
			} elseif ( $ttl > 0 ) {
				// set_transient() with 0 would cache forever — skip when disabled.
				set_transient( $cache_key, $id, $ttl );
			}
		}
	}

	return $id ? (int) $id : null;
}

/**
 * Resolves a speaker slug to its id.
 *
 * @param string $slug  Speaker slug, e.g. 'jane-doe'.
 * @return int|null  Speaker id, or null when the slug is unknown.
 */
function cfp_dev_speaker_id_from_slug( $slug ) {
	return cfp_dev_resolve_slug(
		'cfp_speaker_slug_',
		'public/speakers?size=' . CFP_DEV_SPEAKERS_FETCH_SIZE,
		(string) $slug,
		static function ( $speaker ) {
			return cfp_dev_generate_slug( ( $speaker->firstName ?? '' ) . '-' . ( $speaker->lastName ?? '' ) );
		}
	);
}

/**
 * Resolves a talk slug to its id.
 *
 * @param string $slug  Talk slug, e.g. 'my-great-talk'.
 * @return int|null  Talk id, or null when the slug is unknown.
 */
function cfp_dev_talk_id_from_slug( $slug ) {
	return cfp_dev_resolve_slug(
		'cfp_talk_slug_',
		'public/talks',
		(string) $slug,
		static function ( $talk ) {
			return cfp_dev_generate_slug( (string) ( $talk->title ?? '' ) );
		}
	);
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
