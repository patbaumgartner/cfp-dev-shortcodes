<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_speakers]  List all CFP.DEV speakers.
 * [cfp_speaker_details]  List CFP.DEV speaker details.
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

	add_action(
		'wp_enqueue_scripts',
		function () {
			$plugin_url = plugin_dir_url( __FILE__ );
			wp_enqueue_style( 'style1', $plugin_url . CFP_DEV_CSS, [], CFP_DEV_VERSION );
		}
	);

	/**
	 * @throws Exception
	 */
	function cfp_talk_details_shortcode() {
		$talk_slug = get_query_var( 'talk_slug' );
		$talk_id   = get_query_var( 'id' );

		if ( ! empty( $talk_slug ) ) {
			cfp_dev_log( 'talk-details: resolving slug=' . $talk_slug );
			$talk = get_talk_by_slug( $talk_slug );
		} elseif ( ! empty( $talk_id ) ) {
			cfp_dev_log( 'talk-details: loading id=' . $talk_id );
			$talk = getJSON( 'public/talks/' . $talk_id );
		} else {
			return 'Talk not found.';
		}

		// Check if caching is disabled
		if ( CFP_DEV_CACHE == 0 ) {
			cfp_dev_log( 'talk-details: cache disabled for id=' . $talk->id );
			$content = generate_talk_details_content( $talk->id );
		} else {
			$cacheKey = generate_cfp_cache_key( 'talk', $talk->id );
			$cache    = get_transient( $cacheKey );
			if ( false === $cache ) {
				cfp_dev_log( 'talk-details: cache miss for id=' . $talk->id );
				$content = generate_talk_details_content( $talk->id );
				set_transient( $cacheKey, $content, CFP_DEV_CACHE );
			} else {
				cfp_dev_log( 'talk-details: cache hit for id=' . $talk->id );
				$content = $cache;
			}
		}

		return $content;
	}

	/**
	 * @throws Exception
	 */
	function generate_talk_details_content( $_talkId ) {

		$talk = get_talk_by_id( $_talkId );

		$content = getQueryScript();

		$content .= '<main class="cfp-main">';

		if ( ! empty( $talk ) ) {

			$content .= embedSocialTalkCard( $talk );

			$content .= '<section class="cfp-session">';
			$content .= '    <div class="cfp-foreword">';

			$content .= '		<a class="cfp-a" href="' . cfp_dev_url( '/talks-by-tracks/?id=' . absint( $talk->trackId ) ) . '">';
			$content .= '			<div class="cfp-track" title="' . esc_attr( $talk->trackName ) . '"  style="background-image: url(' . esc_url( $talk->trackImageURL ) . ')"></div>';
			$content .= '		</a>';
			$content .= '		<div class="cfp-name">' . esc_html( $talk->title ) . '</div>';
			$content .= '       <div class="cfp-type">';
			$content .= '			<a href="' . cfp_dev_url( '/talks-by-sessions/?id=' . absint( $talk->sessionTypeId ) ) . '">'
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

			if ( ! empty( $talk->podcastURL ) && str_contains( $talk->podcastURL, 'spotify' ) ) {
				$content .= '<div class="cfp-podcast">';
				$content .= '<iframe style="border-radius:12px" src="' . $talk->podcastURL . '?utm_source=WordPress"
							 width="100%" height="80"
							 frameBorder="0" allowfullscreen=""
							 allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture"
							 loading="lazy"></iframe>';
				$content .= '<div class="cfp-text"><small>AI-generated (Experimental): may contain inaccuracies, please verify facts.</small></div>';
				$content .= '</div>';
			}

			$content = getSpeakerInfo( $talk, $content );

			$content .= '   </div>';
			$content .= '</section>';

			$content .= '</main>';

			$content .= getFooter();
		}

		return $content;
	}

	/**
	 * @param $content
	 * @param $talk
	 * @return string
	 */
	function generateTags( $talk ) {
		$content = '        <div class="cfp-category">';
		foreach ( $talk->tags as $tag ) {
			$content .= '<span class="cfp-span">';
			$content .= '	<a href="' . cfp_dev_url( '/search-results/?query=' . ucwords( $tag->name ) ) . '">' . ucwords( $tag->name ) . '</a>';
			$content .= '</span>';

		}
		$content .= '        </div>';
		return $content;
	}

	/**
	 * @return string
	 */
	function getQueryScript() {
		$content  = '<script>';
		$content .= 'const qs = document.querySelector(":root");';
		$content .= 'qs.classList.forEach(value => {';
		$content .= '   if (value.startsWith("cfp-")) {';
		$content .= '       qs.classList.remove(value);';
		$content .= '   }';
		$content .= '});';
		$content .= 'qs.classList.add("cfp-html");';
		$content .= 'qs.classList.add("cfp-page:session");';
		$content .= 'qs.classList.add("cfp-theme:' . get_option( 'cfp_dev_default_theme', 'dark' ) . '");';
		$content .= 'qs.classList.add("cfp-view:detail");';
		$content .= '</script>';
		return $content;
	}

	/**
	 * @param $talk
	 * @param $content
	 * @return mixed|string
	 * @throws Exception
	 */
	function getScheduleInfo( $talk ) {
		$content = '';
		if ( count( $talk->timeSlots ) > 0 ) {

			$slot = array_pop( $talk->timeSlots );

			if ( ! empty( $slot->fromDate ) && ! empty( $slot->toDate ) ) {
				$timeZone = new DateTimeZone( $slot->timezone );
				$fromDate = new DateTime( $slot->fromDate, new DateTimeZone( $slot->timezone ) );
				$fromDate->setTimezone( $timeZone );

				$toDate = new DateTime( $slot->toDate, new DateTimeZone( $slot->timezone ) );
				$toDate->setTimezone( $timeZone );

				$content  = '        <div class="cfp-datetime">';
				$content .= '            <time class="cfp-time" datetime="' . esc_attr( $fromDate->format( 'c' ) ) . '">' . esc_html( $fromDate->format( 'l' ) . ' from ' . $fromDate->format( 'H:i' ) ) . '</time>';
				$content .= '            <time class="cfp-time" datetime="' . esc_attr( $toDate->format( 'c' ) ) . '">' . esc_html( $toDate->format( 'H:i' ) ) . '</time>';
				$content .= '        </div>';

				if ( get_option( 'cfp_dev_show_rooms', 'yes' ) == 'yes' ) {
					$content .= '        <div class="cfp-room">' . esc_html( $slot->roomName ) . '</div>';
				}

				$content .= '<input type="hidden" id="cfpTimezone" value="' . esc_attr( $slot->timezone ) . '">';
				$content .= '<input type="hidden" id="cfpTalkFrom" value="' . esc_attr( strtotime( $fromDate->format( 'Y-m-d H:i:s' ) ) ) . '">';
				$content .= '<input type="hidden" id="cfpTalkExpiry" value="' . esc_attr( strtotime( $toDate->format( 'Y-m-d H:i:s' ) ) ) . '">';
			}
		}
		return $content;
	}

	/**
	 * Get the YouTube video HTML for the talk.
	 * @param $talk
	 * @param $content
	 * @return mixed|string
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
	 * Search for similar talks based on semantic description value.
	 * @param $talkInfo
	 * @return mixed|string
	 */
	function getSimilarTalks( $talk ) {
		$content = '';

		// Fetch the semantic results first
		$semanticResult = searchJSON( $talk->title . ' ' . wp_strip_all_tags( $talk->description ) );

		if ( ! empty( $semanticResult ) && count( $semanticResult ) > 0 ) {
			// Sort the fetched results by the score in ascending order for PHP < 7.0
			usort( $semanticResult, fn( $a, $b ) => $a->score <=> $b->score );

			$content .= '<div class="cfp-related-title">Related</div>';
			foreach ( $semanticResult as $item ) {
				if ( $item->id != $talk->id && ! str_contains( strtolower( $item->title ), 'overflow' ) ) {
					$content .= '    <div class="cfp-related">';
					if ( get_option( 'cfp_dev_content_by_id', 'yes' ) == 'no' ) {
						$content .= '       <a href="' . cfp_dev_url( '/talk/' . generate_slug( $item->title ) ) . '">' . esc_html( $item->title ) . '</a>';
					} else {
						$content .= '       <a href="' . cfp_dev_url( '/talk?id=' . absint( $item->id ) ) . '">' . esc_html( $item->title ) . '</a>';
					}
					$content .= '    </div>';
				}
			}
		}
		return $content;
	}

	/**
	 * @param $talk
	 * @param $content
	 * @return mixed|string
	 */
	function getSpeakerInfo( $talk, $content ) {
		foreach ( $talk->speakers as $speaker ) {
			$content .= '		<div class="cfp-profile">';
			if ( get_option( 'cfp_dev_content_by_id', 'yes' ) == 'no' ) {
				$speaker_slug = generate_slug( $speaker->firstName . '-' . $speaker->lastName );
				$content     .= '<a class="cfp-a" href="' . cfp_dev_url( "/speaker/{$speaker_slug}" ) . '">';
			} else {
				$content .= '<a class="cfp-a" href="' . cfp_dev_url( "/speaker?id=$speaker->id" ) . '">';
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
