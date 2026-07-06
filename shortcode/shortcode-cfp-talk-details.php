<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_talk_details]  Talk detail page: description, speakers, schedule, video, podcast, related talks.
 *
 * @package  CFP.DEV
 * @since    1.0.0
 */

if ( ! function_exists( 'cfp_talk_details_shortcode' ) ) {

	add_action(
		'plugins_loaded',
		function () {
			if ( ! shortcode_exists( 'cfp_talk_details' ) ) {
				add_shortcode( 'cfp_talk_details', 'cfp_talk_details_shortcode' );
			}
		}
	);

	function cfp_talk_details_shortcode() {
		$talk_slug = get_query_var( 'talk_slug' );
		$talk_id   = absint( get_query_var( 'id' ) );

		if ( ! empty( $talk_slug ) ) {
			cfp_dev_log( 'talk-details: resolving slug=' . $talk_slug );
			$talk_id = get_talk_id_from_slug( sanitize_title( $talk_slug ) );
		}

		if ( empty( $talk_id ) ) {
			return 'Talk not found.';
		}

		$ttl = cfp_dev_get_cache_ttl();

		if ( 0 === $ttl ) {
			cfp_dev_log( 'talk-details: cache disabled for id=' . $talk_id );
			return generate_talk_details_content( $talk_id );
		}

		$cacheKey = generate_cfp_cache_key( 'talk', $talk_id );
		$cache    = get_transient( $cacheKey );
		if ( false !== $cache ) {
			cfp_dev_log( 'talk-details: cache hit for id=' . $talk_id );
			return $cache;
		}

		cfp_dev_log( 'talk-details: cache miss for id=' . $talk_id );
		$content = generate_talk_details_content( $talk_id );
		set_transient( $cacheKey, $content, $ttl );
		return $content;
	}

	function generate_talk_details_content( $_talkId ) {

		$talk = get_talk_by_id( $_talkId );

		if ( empty( $talk ) ) {
			return 'Talk not found.';
		}

		$content = cfp_dev_root_class_script( 'session', 'detail' );

		$content .= '<main class="cfp-main">';

		if ( ! empty( $talk ) ) {

			$content .= embedSocialTalkCard( $talk );

			$content .= '<section class="cfp-session">';
			$content .= '    <div class="cfp-foreword">';

			$content .= '		<a class="cfp-a" href="' . esc_url( cfp_dev_url( '/talks-by-tracks/?id=' . absint( $talk->trackId ) ) ) . '">';
			$content .= '			<div class="cfp-track" title="' . esc_attr( $talk->trackName ) . '"  style="background-image: url(' . esc_url( $talk->trackImageURL ) . ')"></div>';
			$content .= '		</a>';
			$content .= '		<div class="cfp-name">' . esc_html( $talk->title ) . '</div>';
			$content .= '       <div class="cfp-type">';
			$content .= '			<a href="' . esc_url( cfp_dev_url( '/talks-by-sessions/?id=' . absint( $talk->sessionTypeId ) ) ) . '">'
				. esc_html( $talk->sessionTypeName ) . '</a> <em>(' . esc_html( $talk->audienceLevel ) . ' level)</em>';
			$content .= '       </div>';

			$content .= getScheduleInfo( $talk );

			$content .= generateTags( $talk );

			$content .= getSimilarTalks( $talk );

			$content .= '</div>';

			$content .= '<div class="cfp-content">';

			$content = getVideo( $talk, $content );

			$content .= '<div class="cfp-text">';

			$content .= wp_kses_post( $talk->description );

			$content .= '</div>';

			$content .= getPodcast( $talk );

			$content = getSpeakerInfo( $talk, $content );

			$content .= '   </div>';
			$content .= '</section>';

			$content .= '</main>';

			$content .= getFooter();
		}

		return $content;
	}

	/**
	 * Renders the tag pills linking to search results.
	 *
	 * @param object $talk  Talk detail object.
	 * @return string
	 */
	function generateTags( $talk ) {
		$content = '        <div class="cfp-category">';
		if ( ! empty( $talk->tags ) ) {
			foreach ( $talk->tags as $tag ) {
				$content .= '<span class="cfp-span">';
				$content .= '	<a href="' . esc_url( cfp_dev_url( '/search-results/?query=' . rawurlencode( $tag->name ) ) ) . '">' . esc_html( ucwords( $tag->name ) ) . '</a>';
				$content .= '</span>';
			}
		}
		$content .= '        </div>';
		return $content;
	}

	/**
	 * Renders the Spotify podcast embed. Only URLs actually hosted on
	 * open.spotify.com are accepted — a substring check would match
	 * e.g. https://evil.com/?spotify.
	 *
	 * @param object $talk  Talk detail object.
	 * @return string
	 */
	function getPodcast( $talk ) {
		if ( empty( $talk->podcastURL ) ) {
			return '';
		}
		$host = wp_parse_url( $talk->podcastURL, PHP_URL_HOST );
		if ( ! is_string( $host ) || ! in_array( strtolower( $host ), [ 'open.spotify.com', 'spotify.com' ], true ) ) {
			return '';
		}

		$content  = '<div class="cfp-podcast">';
		$content .= '<iframe style="border-radius:12px" src="' . esc_url( $talk->podcastURL . '?utm_source=WordPress' ) . '"
					 width="100%" height="80"
					 frameBorder="0" allowfullscreen=""
					 allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture"
					 loading="lazy"></iframe>';
		$content .= '<div class="cfp-text"><small>AI-generated (Experimental): may contain inaccuracies, please verify facts.</small></div>';
		$content .= '</div>';
		return $content;
	}

	/**
	 * Renders the date/time/room block for the talk's last time slot.
	 * Returns an empty string when nothing is scheduled.
	 *
	 * @param object $talk  Talk detail object with timeSlots.
	 * @return string
	 */
	function getScheduleInfo( $talk ) {
		$content = '';
		if ( ! empty( $talk->timeSlots ) && is_array( $talk->timeSlots ) && count( $talk->timeSlots ) > 0 ) {

			$slot = array_pop( $talk->timeSlots );

			if ( ! empty( $slot->fromDate ) && ! empty( $slot->toDate ) ) {
				try {
					$timeZone = new DateTimeZone( $slot->timezone );
					$fromDate = new DateTime( $slot->fromDate, $timeZone );
					$fromDate->setTimezone( $timeZone );

					$toDate = new DateTime( $slot->toDate, $timeZone );
					$toDate->setTimezone( $timeZone );
				} catch ( Exception $e ) {
					cfp_dev_log( 'talk-details: invalid slot date/timezone — ' . $e->getMessage() );
					return $content;
				}

				$content  = '        <div class="cfp-datetime">';
				$content .= '            <time class="cfp-time" datetime="' . esc_attr( $fromDate->format( 'c' ) ) . '">' . esc_html( $fromDate->format( 'l' ) . ' from ' . $fromDate->format( 'H:i' ) ) . '</time>';
				$content .= '            <time class="cfp-time" datetime="' . esc_attr( $toDate->format( 'c' ) ) . '">' . esc_html( $toDate->format( 'H:i' ) ) . '</time>';
				$content .= '        </div>';

				if ( 'yes' === get_option( 'cfp_dev_show_rooms', 'yes' ) && ! empty( $slot->roomName ) ) {
					$content .= '        <div class="cfp-room">' . esc_html( $slot->roomName ) . '</div>';
				}

				$content .= '<input type="hidden" id="cfpTimezone" value="' . esc_attr( $slot->timezone ) . '">';
				$content .= '<input type="hidden" id="cfpTalkFrom" value="' . esc_attr( $fromDate->getTimestamp() ) . '">';
				$content .= '<input type="hidden" id="cfpTalkExpiry" value="' . esc_attr( $toDate->getTimestamp() ) . '">';
			}
		}
		return $content;
	}

	/**
	 * Appends the YouTube video embed for the talk, when available.
	 *
	 * @param object $talk     Talk detail object.
	 * @param string $content  Accumulated page HTML.
	 * @return string
	 */
	function getVideo( $talk, $content ) {
		if ( ! empty( $talk->videoURL ) ) {
			$content .= '<div class="cfp-text">';
			$content .= '	<iframe width="560" height="315" src="' . esc_url( $talk->videoURL ) . '" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
			$content .= '	<br>';
			$content .= '</div>';
		}
		return $content;
	}

	/**
	 * Renders the "Related" list via semantic search on title + description.
	 *
	 * @param object $talk  Talk detail object.
	 * @return string
	 */
	function getSimilarTalks( $talk ) {
		$content = '';

		// Fetch the semantic results first (truncate the query — full descriptions
		// produce excessively long URLs).
		$semanticResult = searchJSON( mb_substr( $talk->title . ' ' . wp_strip_all_tags( (string) $talk->description ), 0, 500 ) );

		if ( ! empty( $semanticResult ) && count( $semanticResult ) > 0 ) {
			// Sort the fetched results by score, best (lowest) first.
			usort( $semanticResult, fn( $a, $b ) => $a->score <=> $b->score );

			$use_slugs = ( 'no' === get_option( 'cfp_dev_content_by_id', 'yes' ) );

			$content .= '<div class="cfp-related-title">Related</div>';
			foreach ( $semanticResult as $item ) {
				if ( absint( $item->id ) !== absint( $talk->id ) && ! str_contains( strtolower( $item->title ), 'overflow' ) ) {
					$content .= '    <div class="cfp-related">';
					if ( $use_slugs ) {
						$content .= '       <a href="' . esc_url( cfp_dev_url( '/talk/' . generate_slug( $item->title ) ) ) . '">' . esc_html( $item->title ) . '</a>';
					} else {
						$content .= '       <a href="' . esc_url( cfp_dev_url( '/talk?id=' . absint( $item->id ) ) ) . '">' . esc_html( $item->title ) . '</a>';
					}
					$content .= '    </div>';
				}
			}
		}
		return $content;
	}

	/**
	 * Appends a profile card (photo, socials, bio) for every speaker of the talk.
	 *
	 * @param object $talk     Talk detail object.
	 * @param string $content  Accumulated page HTML.
	 * @return string
	 */
	function getSpeakerInfo( $talk, $content ) {
		if ( empty( $talk->speakers ) ) {
			return $content;
		}
		$use_slugs = ( 'no' === get_option( 'cfp_dev_content_by_id', 'yes' ) );
		foreach ( $talk->speakers as $speaker ) {
			$content .= '		<div class="cfp-profile">';
			if ( $use_slugs ) {
				$speaker_slug = generate_slug( $speaker->firstName . '-' . $speaker->lastName );
				$content     .= '<a class="cfp-a" href="' . esc_url( cfp_dev_url( "/speaker/{$speaker_slug}" ) ) . '">';
			} else {
				$content .= '<a class="cfp-a" href="' . esc_url( cfp_dev_url( '/speaker?id=' . absint( $speaker->id ) ) ) . '">';
			}
			if ( empty( $speaker->imageUrl ) ) {
				$content .= '			<div class="cfp-picture" title="' . esc_attr( $speaker->company ) . '" style="background-image: url(' . esc_url( plugins_url( 'shortcode/gfx/avatar.jpg', __DIR__ ) ) . ')"></div>';
			} else {
				$content .= '			<div class="cfp-picture" title="' . esc_attr( $speaker->company ) . '" style="background-image: url(' . esc_url( $speaker->imageUrl ) . ')"></div>';
			}
			$content .= '		</a>';
			$content .= '		<div class="cfp-detail">';
			$content .= '		<div class="cfp-name">' . esc_html( $speaker->firstName . ' ' . $speaker->lastName ) . '</div>';
			$content .= getSocialLinks( $speaker );
			$content .= '          </div>';
			if ( ! empty( $speaker->company ) ) {
				$content .= '		<div class="cfp-detail" style="margin-top: 1.25rem;">' . esc_html( $speaker->company ) . '</div>';
			}
			$content .= '          <div class="cfp-text">';

			$content .= wp_kses_post( $speaker->bio );

			$content .= '           </div>';
			$content .= '       </div>';
		}
		return $content;
	}
}
