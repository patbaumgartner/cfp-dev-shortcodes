<?php
/**
 * CFP.DEV shortcodes
 *
 * Server-rendered head metadata, JSON-LD, canonical URLs and the XML sitemap.
 *
 * @package CFP.DEV
 */

if ( ! defined( 'WPINC' ) ) {
	die;
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

	/* translators: %s: event name. */
	$description = sprintf( __( 'Browse talks by track at %s.', 'cfp-dev-shortcodes' ), $event_name );
	$tracks      = cfp_dev_get_json( 'public/tracks' );

	if ( is_array( $tracks ) && ! empty( $tracks ) ) {
		if ( $track_id ) {
			foreach ( $tracks as $track ) {
				if ( absint( $track->id ?? 0 ) === $track_id ) {
					$track_name  = wp_strip_all_tags( (string) ( $track->name ?? '' ) );
					$track_descr = cfp_dev_meta_excerpt( $track->description ?? '', 110 );
					if ( '' !== $track_descr ) {
						/* translators: 1: track name, 2: event name, 3: track description. */
						$description = sprintf( __( '%1$s talks at %2$s — %3$s', 'cfp-dev-shortcodes' ), $track_name, $event_name, $track_descr );
					} else {
						/* translators: 1: track name, 2: event name. */
						$description = sprintf( __( '%1$s talks at %2$s.', 'cfp-dev-shortcodes' ), $track_name, $event_name );
					}
					break;
				}
			}
		} else {
			$names       = array_map(
				static function ( $track ) {
					return wp_strip_all_tags( (string) ( $track->name ?? '' ) );
				},
				$tracks
			);
			$description = cfp_dev_meta_excerpt(
				/* translators: 1: event name, 2: comma-separated track names. */
				sprintf( __( 'Browse talks by track at %1$s: %2$s.', 'cfp-dev-shortcodes' ), $event_name, implode( ', ', $names ) )
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

	/* translators: %s: event name. */
	$description = sprintf( __( 'Browse talks by session type at %s.', 'cfp-dev-shortcodes' ), $event_name );
	$sessions    = cfp_dev_get_json( 'public/session-types' );

	if ( is_array( $sessions ) && ! empty( $sessions ) ) {
		if ( $session_id ) {
			foreach ( $sessions as $session ) {
				if ( absint( $session->id ?? 0 ) === $session_id ) {
					$session_name  = wp_strip_all_tags( (string) ( $session->name ?? '' ) );
					$session_descr = cfp_dev_meta_excerpt( $session->description ?? '', 110 );
					if ( '' !== $session_descr ) {
						/* translators: 1: session type name, 2: event name, 3: session type description. */
						$description = sprintf( __( '%1$s sessions at %2$s — %3$s', 'cfp-dev-shortcodes' ), $session_name, $event_name, $session_descr );
					} else {
						/* translators: 1: session type name, 2: event name. */
						$description = sprintf( __( '%1$s sessions at %2$s.', 'cfp-dev-shortcodes' ), $session_name, $event_name );
					}
					break;
				}
			}
		} else {
			$names = [];
			foreach ( $sessions as $session ) {
				if ( empty( $session->pause ) ) {
					$names[] = wp_strip_all_tags( (string) ( $session->name ?? '' ) );
				}
			}
			// Events may define several session types with the same display
			// name (e.g. three "Keynote" slots) — list each name once.
			$names = array_unique( $names );
			if ( ! empty( $names ) ) {
				$description = cfp_dev_meta_excerpt(
					/* translators: 1: event name, 2: comma-separated session type names. */
					sprintf( __( 'Browse talks by session type at %1$s: %2$s.', 'cfp-dev-shortcodes' ), $event_name, implode( ', ', $names ) )
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
			/* translators: 1: talk title, 2: session type name, 3: event name. */
			$description = sprintf( __( '%1$s — a %2$s at %3$s.', 'cfp-dev-shortcodes' ), $title, wp_strip_all_tags( $talk->sessionTypeName ?? __( 'session', 'cfp-dev-shortcodes' ) ), $event_name );
		}
		return [
			/* translators: 1: talk title, 2: event name. */
			'title'       => sprintf( __( '%1$s - %2$s', 'cfp-dev-shortcodes' ), $title, $event_name ),
			'description' => $description,
			'url'         => home_url( cfp_dev_url( '/talk/' . cfp_dev_generate_slug( $talk->title ) ) ),
			'image'       => cfp_dev_usable_image( $talk->trackImageURL ?? '' ),
			'og_type'     => 'article',
		];
	}

	if ( $entity && 'speaker' === $entity['type'] ) {
		$speaker     = $entity['data'];
		$name        = trim( $speaker->firstName . ' ' . ( $speaker->lastName ?? '' ) );
		$description = cfp_dev_meta_excerpt( $speaker->bio ?? '' );
		if ( '' === $description ) {
			if ( ! empty( $speaker->company ) ) {
				/* translators: 1: speaker name, 2: speaker company, 3: event name. */
				$description = sprintf( __( '%1$s (%2$s) speaks at %3$s.', 'cfp-dev-shortcodes' ), $name, wp_strip_all_tags( $speaker->company ), $event_name );
			} else {
				/* translators: 1: speaker name, 2: event name. */
				$description = sprintf( __( '%1$s speaks at %2$s.', 'cfp-dev-shortcodes' ), $name, $event_name );
			}
		}
		return [
			/* translators: 1: speaker name, 2: event name. */
			'title'       => sprintf( __( '%1$s - %2$s', 'cfp-dev-shortcodes' ), $name, $event_name ),
			'description' => $description,
			'url'         => home_url( cfp_dev_url( '/speaker/' . cfp_dev_generate_slug( $speaker->firstName . '-' . ( $speaker->lastName ?? '' ) ) ) ),
			'image'       => cfp_dev_usable_image( $speaker->imageUrl ?? '' ),
			'og_type'     => 'profile',
		];
	}

	$description = '';
	if ( is_page( 'speakers' ) ) {
		/* translators: %s: event name. */
		$description = sprintf( __( 'Browse our lineup of expert speakers at %s.', 'cfp-dev-shortcodes' ), $event_name );
	} elseif ( is_page( 'schedule' ) ) {
		/* translators: %s: event name. */
		$description = sprintf( __( 'View the full conference schedule for %s — sessions, times, rooms and speakers.', 'cfp-dev-shortcodes' ), $event_name );
	} elseif ( is_page( 'talks-by-tracks' ) ) {
		$description = cfp_dev_tracks_meta_description( $event_name );
	} elseif ( is_page( 'talks-by-sessions' ) ) {
		$description = cfp_dev_sessions_meta_description( $event_name );
	} elseif ( is_page( 'search-results' ) ) {
		// The query var is registered by cfp_dev_add_query_vars(), so this is
		// the same value the shortcode renders — no need to touch $_GET.
		$query_val   = sanitize_text_field( (string) get_query_var( 'query' ) );
		$description = '' !== $query_val
			? sprintf(
				/* translators: 1: search query, 2: event name. */
				__( 'Search results for “%1$s” at %2$s.', 'cfp-dev-shortcodes' ),
				$query_val,
				$event_name
			)
			: sprintf(
				/* translators: %s: event name. */
				__( 'Search talks and speakers at %s.', 'cfp-dev-shortcodes' ),
				$event_name
			);
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
			'name'     => trim( $speaker->firstName . ' ' . ( $speaker->lastName ?? '' ) ),
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

	// JSON_HEX_TAG is load-bearing: without it a '</script>' inside any API
	// string (a speaker name, a talk title) closes this element and everything
	// after it is parsed as markup. JSON_UNESCAPED_SLASHES alone does not help
	// — the HTML tokenizer never sees the JSON, only the raw bytes.
	echo '<script type="application/ld+json">'
		. wp_json_encode(
			$schema,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				| JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		)
		. '</script>' . "\n";
}
add_action( 'wp_head', 'cfp_dev_output_jsonld', 3 );

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
 * Answers a talk/speaker detail request that resolved to nothing with the
 * right status code.
 *
 * A removed talk and an unreachable API look identical on the page — both say
 * "not found" — but they must not look identical to a crawler. Rendering that
 * text with HTTP 200 made Search Console report soft 404s; answering 404
 * whenever the lookup came back empty was worse, because a minute of API
 * downtime then told Google that every talk and speaker on the site was gone.
 *
 * So the status follows what the plugin actually knows: 404 only when a
 * lookup that succeeded proved the entity absent, and 503 while it cannot
 * tell, which is the answer that asks a crawler to come back.
 */
function cfp_dev_404_unresolved_detail() {
	if ( ! is_page( [ 'talk', 'speaker' ] ) || null !== cfp_dev_current_entity() ) {
		return;
	}

	if ( cfp_dev_api_had_failure() ) {
		status_header( 503 );
		if ( ! headers_sent() ) {
			header( 'Retry-After: 300' );
		}
		nocache_headers();
		return;
	}

	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
}
add_action( 'template_redirect', 'cfp_dev_404_unresolved_detail' );

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
				$entries[ '/speaker/' . cfp_dev_generate_slug( $speaker->firstName . '-' . ( $speaker->lastName ?? '' ) ) ] = true;
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
