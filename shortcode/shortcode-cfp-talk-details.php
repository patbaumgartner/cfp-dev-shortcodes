<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_talk_details]  Talk detail page: description, speakers, schedule, video, podcast, related talks.
 *
 * @package  CFP.DEV
 * @since    1.0.0
 */

if ( ! function_exists( 'cfp_dev_talk_details_shortcode' ) ) {

	add_action(
		'plugins_loaded',
		function () {
			if ( ! shortcode_exists( 'cfp_talk_details' ) ) {
				add_shortcode( 'cfp_talk_details', 'cfp_dev_talk_details_shortcode' );
			}
		}
	);

	/**
	 * Shortcode handler for [cfp_talk_details].
	 *
	 * Reads talk_slug or id from the URL, resolves the talk, and returns the
	 * (transient-cached) rendered detail page.
	 *
	 * @return string
	 * @since  1.0.0
	 */
	function cfp_dev_talk_details_shortcode() {
		cfp_dev_shortcode_assets();
		$talk_slug = get_query_var( 'talk_slug' );
		$talk_id   = absint( get_query_var( 'id' ) );

		if ( ! empty( $talk_slug ) ) {
			cfp_dev_log( 'talk-details: resolving slug=' . $talk_slug );
			$talk_id = cfp_dev_talk_id_from_slug( sanitize_title( $talk_slug ) );
		}

		if ( empty( $talk_id ) ) {
			return esc_html__( 'Talk not found.', 'cfp-dev-shortcodes' );
		}

		return cfp_dev_cached_markup(
			cfp_dev_detail_cache_key( 'talk', $talk_id ),
			static function () use ( $talk_id ) {
				$talk = cfp_dev_get_talk_by_id( $talk_id );
				return empty( $talk ) ? null : cfp_dev_render_talk_details( $talk );
			},
			esc_html__( 'Talk not found.', 'cfp-dev-shortcodes' )
		);
	}

	/**
	 * Renders the full talk detail page: track, title, schedule, tags, related
	 * talks, video, description, podcast, and speaker cards.
	 *
	 * @param object $talk  Talk detail object from the API.
	 * @return string
	 */
	function cfp_dev_render_talk_details( $talk ) {
		/* translators: %s: audience level. */
		$audience_level = sprintf( __( '%s level', 'cfp-dev-shortcodes' ), (string) ( $talk->audienceLevel ?? '' ) );

		$content  = cfp_dev_root_class_script( 'session', 'detail' );
		$content .= '<div class="cfp-main">';
		$content .= '<section class="cfp-session">';

		$content .= '    <div class="cfp-foreword">';
		$content .= '		<a class="cfp-a" href="' . esc_url( cfp_dev_url( '/talks-by-tracks/?id=' . absint( $talk->trackId ?? 0 ) ) ) . '">';
		$content .= '			<div class="cfp-track" title="' . esc_attr( (string) ( $talk->trackName ?? '' ) ) . '"  style="background-image: url(\'' . esc_url( (string) ( $talk->trackImageURL ?? '' ) ) . '\')"></div>';
		$content .= '		</a>';
		$content .= '		<div class="cfp-name"' . cfp_dev_heading( 2 ) . '>' . esc_html( (string) ( $talk->title ?? '' ) ) . '</div>';
		$content .= '       <div class="cfp-type">';
		$content .= '			<a href="' . esc_url( cfp_dev_url( '/talks-by-sessions/?id=' . absint( $talk->sessionTypeId ?? 0 ) ) ) . '">'
			. esc_html( (string) ( $talk->sessionTypeName ?? '' ) ) . '</a> <em>(' . esc_html( $audience_level ) . ')</em>';
		$content .= '       </div>';
		$content .= cfp_dev_render_talk_schedule( $talk );
		$content .= cfp_dev_render_tags( $talk );
		$content .= cfp_dev_render_related_talks( $talk );
		$content .= '</div>';

		$content .= '<div class="cfp-content">';
		$content .= cfp_dev_render_talk_video( $talk );
		$content .= '<div class="cfp-text">';
		$content .= wp_kses_post( (string) ( $talk->description ?? '' ) );
		$content .= '</div>';
		$content .= cfp_dev_render_podcast( $talk );
		$content .= cfp_dev_render_talk_speakers( $talk );
		$content .= '   </div>';

		$content .= '</section>';
		$content .= '</div>';
		$content .= cfp_dev_footer();

		return $content;
	}

	/**
	 * Renders the tag pills linking to search results.
	 *
	 * @param object $talk  Talk detail object.
	 * @return string
	 */
	function cfp_dev_render_tags( $talk ) {
		$content = '        <div class="cfp-category">';
		if ( ! empty( $talk->tags ) && is_array( $talk->tags ) ) {
			foreach ( $talk->tags as $tag ) {
				$tag_name = (string) ( $tag->name ?? '' );
				$content .= '<span class="cfp-span">';
				$content .= '	<a href="' . esc_url( cfp_dev_url( '/search-results/?query=' . rawurlencode( $tag_name ) ) ) . '">' . esc_html( ucwords( $tag_name ) ) . '</a>';
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
	function cfp_dev_render_podcast( $talk ) {
		$podcast = cfp_dev_embed_url( $talk->podcastURL ?? '', [ 'open.spotify.com', 'spotify.com' ] );
		if ( '' === $podcast ) {
			return '';
		}

		// add_query_arg, not concatenation: a podcastURL that already carries a
		// query string would otherwise gain a second '?' and stop resolving.
		$podcast = add_query_arg( 'utm_source', 'WordPress', $podcast );

		$content  = '<div class="cfp-podcast">';
		$content .= '<iframe style="border-radius:12px" title="' . esc_attr(
			/* translators: %s: talk title. */
			sprintf( __( 'Podcast: %s', 'cfp-dev-shortcodes' ), (string) ( $talk->title ?? '' ) )
		) . '" src="' . esc_url( $podcast ) . '"
					 width="100%" height="80"
					 frameBorder="0" allowfullscreen=""
					 allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture"
					 loading="lazy"></iframe>';
		$content .= '<div class="cfp-text"><small>' . esc_html__( 'AI-generated (Experimental): may contain inaccuracies, please verify facts.', 'cfp-dev-shortcodes' ) . '</small></div>';
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
	function cfp_dev_render_talk_schedule( $talk ) {
		if ( empty( $talk->timeSlots ) || ! is_array( $talk->timeSlots ) ) {
			return '';
		}

		// end() on a local copy: array_pop() would consume the slots on the
		// shared, memoised talk object.
		$slots = (array) $talk->timeSlots;
		$slot  = end( $slots );

		// This page is about one talk, so it is the one that carries the hidden
		// timing inputs a theme can read.
		return cfp_dev_render_time_slot( $slot, true );
	}

	/**
	 * Renders the video embed for the talk, when it has one from a host the
	 * plugin will frame.
	 *
	 * @param object $talk  Talk detail object.
	 * @return string
	 */
	function cfp_dev_render_talk_video( $talk ) {
		$video = cfp_dev_embed_url( $talk->videoURL ?? '', cfp_dev_video_embed_hosts() );
		if ( '' === $video ) {
			return '';
		}

		$content  = '<div class="cfp-text">';
		$content .= '	<iframe width="560" height="315" src="' . esc_url( $video ) . '" title="' . esc_attr(
			/* translators: %s: talk title. */
			sprintf( __( 'Video: %s', 'cfp-dev-shortcodes' ), (string) ( $talk->title ?? '' ) )
		) . '" loading="lazy" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
		$content .= '	<br>';
		$content .= '</div>';

		return $content;
	}

	/**
	 * Renders the "Related" list via semantic search on title + description.
	 *
	 * @param object $talk  Talk detail object.
	 * @return string
	 */
	function cfp_dev_render_related_talks( $talk ) {
		$content = '';

		// Fetch the semantic results first (truncate the query — full descriptions
		// produce excessively long URLs).
		$semantic_result = cfp_dev_search_json( mb_substr( trim( ( $talk->title ?? '' ) . ' ' . wp_strip_all_tags( (string) ( $talk->description ?? '' ) ) ), 0, 500 ) );

		if ( ! empty( $semantic_result ) && count( $semantic_result ) > 0 ) {
			// Sort the fetched results by score, best (lowest) first.
			usort( $semantic_result, fn( $a, $b ) => ( $a->score ?? 0 ) <=> ( $b->score ?? 0 ) );

			$content .= '<div class="cfp-related-title"' . cfp_dev_heading( 3 ) . '>' . esc_html__( 'Related', 'cfp-dev-shortcodes' ) . '</div>';
			foreach ( $semantic_result as $item ) {
				$item_title = (string) ( $item->title ?? '' );
				if ( absint( $item->id ?? 0 ) !== absint( $talk->id ?? 0 ) && ! str_contains( strtolower( $item_title ), 'overflow' ) ) {
					$content .= '    <div class="cfp-related">';
					$content .= '       <a href="' . esc_url( cfp_dev_talk_url( $item ) ) . '">' . esc_html( $item_title ) . '</a>';
					$content .= '    </div>';
				}
			}
		}
		return $content;
	}

	/**
	 * Renders a profile card (photo, socials, bio) for every speaker of the talk.
	 *
	 * @param object $talk  Talk detail object.
	 * @return string
	 */
	function cfp_dev_render_talk_speakers( $talk ) {
		$content = '';

		foreach ( (array) ( $talk->speakers ?? [] ) as $speaker ) {
			$content .= '		<div class="cfp-profile">';
			$content .= '<a class="cfp-a" href="' . esc_url( cfp_dev_speaker_url( $speaker ) ) . '">';
			if ( empty( $speaker->imageUrl ) ) {
				$content .= '			<div class="cfp-picture" title="' . esc_attr( (string) ( $speaker->company ?? '' ) ) . '" style="background-image: url(\'' . esc_url( plugins_url( 'shortcode/gfx/avatar.jpg', __DIR__ ) ) . '\')"></div>';
			} else {
				$content .= '			<div class="cfp-picture" title="' . esc_attr( (string) ( $speaker->company ?? '' ) ) . '" style="background-image: url(\'' . esc_url( (string) ( $speaker->imageUrl ?? '' ) ) . '\')"></div>';
			}
			$content .= '		</a>';
			$content .= '		<div class="cfp-detail">';
			$content .= '		<div class="cfp-name"' . cfp_dev_heading( 3 ) . '>' . esc_html( trim( ( $speaker->firstName ?? '' ) . ' ' . ( $speaker->lastName ?? '' ) ) ) . '</div>';
			$content .= cfp_dev_social_links( $speaker );
			$content .= '          </div>';
			if ( ! empty( $speaker->company ) ) {
				$content .= '		<div class="cfp-detail" style="margin-top: 1.25rem;">' . esc_html( $speaker->company ) . '</div>';
			}
			$content .= '          <div class="cfp-text">';

			$content .= wp_kses_post( (string) ( $speaker->bio ?? '' ) );

			$content .= '           </div>';
			$content .= '       </div>';
		}
		return $content;
	}
}
