<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_speaker_details]  Speaker detail page: profile, bio, social links, talks, photo gallery.
 *
 * @package  CFP.DEV
 * @since    1.0.0
 */

if ( ! function_exists( 'cfp_speaker_details_shortcode' ) ) {
	add_action(
		'plugins_loaded',
		function () {
			if ( ! shortcode_exists( 'cfp_speaker_details' ) ) {
				add_shortcode( 'cfp_speaker_details', 'cfp_speaker_details_shortcode' );
			}
		}
	);

	/**
	 * Shortcode handler for [cfp_speaker_details].
	 *
	 * Reads speaker_slug or id from the URL, resolves the speaker, and returns
	 * the (transient-cached) rendered profile page.
	 *
	 * @return string
	 * @since  1.0.0
	 */
	function cfp_speaker_details_shortcode() {
		$speaker_slug = get_query_var( 'speaker_slug' );
		$speaker_id   = absint( get_query_var( 'id' ) );

		if ( ! empty( $speaker_slug ) ) {
			cfp_dev_log( 'speaker-details: resolving slug=' . $speaker_slug );
			$speaker_id = get_speaker_id_from_slug( sanitize_title( $speaker_slug ) );
		}

		if ( empty( $speaker_id ) ) {
			return 'Speaker not found.';
		}

		$ttl = cfp_dev_get_cache_ttl();

		if ( 0 === $ttl ) {
			cfp_dev_log( 'speaker-details: cache disabled for id=' . $speaker_id );
			$speaker_info = get_speaker_by_id( $speaker_id );
			if ( empty( $speaker_info ) ) {
				cfp_dev_log( 'speaker-details: speaker not found for id=' . $speaker_id );
				return 'Speaker not found.';
			}
			return generateSpeakerPage( $speaker_info );
		}

		$speakerCacheKey = generate_cfp_cache_key( 'speaker', $speaker_id );

		$cache = get_transient( $speakerCacheKey );
		if ( false !== $cache ) {
			cfp_dev_log( 'speaker-details: cache hit for id=' . $speaker_id );
			return $cache;
		}

		cfp_dev_log( 'speaker-details: cache miss for id=' . $speaker_id );
		$speaker_info = get_speaker_by_id( $speaker_id );
		if ( empty( $speaker_info ) ) {
			// Do not cache failures — the API may just be temporarily unavailable.
			cfp_dev_log( 'speaker-details: speaker not found for id=' . $speaker_id );
			return 'Speaker not found.';
		}

		$content = generateSpeakerPage( $speaker_info );
		set_transient( $speakerCacheKey, $content, $ttl );
		return $content;
	}

	/**
	 * Renders the full speaker page: profile, talks, and the async photo
	 * gallery placeholder (populated by an AJAX fetch on the client).
	 *
	 * @param object $speaker  Speaker detail object from the API.
	 * @return string
	 */
	function generateSpeakerPage( $speaker ) {
		$content = cfp_dev_root_class_script( 'speaker', 'detail' );

		$content .= '<div class="cfp-main">';

		$content .= generateSpeakerContent( $speaker );

		// Photo album placeholder — filled asynchronously by the fetch below.
		$spinner_file = CFP_DEV_DIR . '/images/loading-spinner.svg';
		$content     .= '<div id="speaker-photo-album">';
		$content     .= '    <div id="loading-container">';
		$content     .= '        <div id="loading-spinner">';
		if ( file_exists( $spinner_file ) ) {
			$content .= '    ' . file_get_contents( $spinner_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, not remote URL
		}
		$content .= '        </div>';
		$content .= '        <p id="photo-loading-message">Searching for speaker images...</p>';
		$content .= '    </div>';
		$content .= '</div>';

		// Build the AJAX URL server-side and hand it to JS as a JSON literal so a
		// speaker name containing quotes or </script> can never break the script.
		$photos_url = add_query_arg(
			[
				'action'       => 'get_speaker_photos',
				'speaker_id'   => absint( $speaker->id ),
				'speaker_name' => rawurlencode( $speaker->firstName . ' ' . $speaker->lastName ),
			],
			admin_url( 'admin-ajax.php' )
		);

		$content .= '<script>
					 document.addEventListener("DOMContentLoaded", function() {
						 const photoAlbum = document.getElementById("speaker-photo-album");
						 const loadingMessage = document.getElementById("photo-loading-message");
						 const loadingSpinner = document.getElementById("loading-spinner");

						 loadingSpinner.style.display = "block";

						 fetch(' . wp_json_encode( $photos_url ) . ')
							 .then(response => response.text())
							 .then(data => {
								 loadingSpinner.style.display = "none";
								 loadingMessage.style.display = "none";

 								if (data.trim() === "") {
									 photoAlbum.innerHTML = "<p>Couldn\'t find any photos</p>";
								 } else {
									 photoAlbum.innerHTML = data;
								 }
							 })
							 .catch(error => {
								 loadingMessage.style.display = "none";
								 loadingSpinner.style.display = "none";
 								photoAlbum.innerHTML = "<p>Error loading speaker photos</p>";
							 });
					 });
				 </script>';

		$content .= '</div>';
		$content .= getFooter();
		return $content;
	}

	/**
	 * Renders the profile section (photo, name, socials, company, bio) followed
	 * by one talk card per proposal.
	 *
	 * @param object $speaker  Speaker detail object from the API.
	 * @return string
	 */
	function generateSpeakerContent( $speaker ) {
		$content  = '<section class="cfp-profile">';
		$content .= '    <div class="cfp-picture" style="background-image: url(' . esc_url( $speaker->imageUrl ) . ')"></div>';
		$content .= '    <div class="cfp-content">';
		$content .= '        <div class="cfp-detail">';
		$content .= '            <div class="cfp-name">' . esc_html( $speaker->firstName . ' ' . $speaker->lastName ) . '</div>';
		$content .= getSocialLinks( $speaker );
		$content .= '        </div>';
		if ( ! empty( $speaker->company ) ) {
			$content .= '        <div class="cfp-company cfp-company-left">' . esc_html( $speaker->company ) . '</div>';
		}
		$content .= '        <div class="cfp-text">';
		$content .= wp_kses_post( $speaker->bio );
		$content .= '        </div>';
		$content .= '    </div>';
		$content .= '</section>';

		if ( ! empty( $speaker->proposals ) ) {
			foreach ( $speaker->proposals as $talk ) {
				$content .= generateTalkContent( $talk );
			}
		}

		return $content;
	}

	/**
	 * Renders one talk card (track, title, schedule, keywords, description, video)
	 * for the speaker page.
	 *
	 * @param object $talk  Talk object from the speaker's proposals list.
	 * @return string
	 */
	function generateTalkContent( $talk ) {
		$use_slugs = ( 'no' === get_option( 'cfp_dev_content_by_id', 'yes' ) );
		$talk_url  = $use_slugs
			? cfp_dev_url( '/talk/' . generate_slug( $talk->title ) )
			: cfp_dev_url( '/talk?id=' . absint( $talk->id ) );

		$content  = '<section class="cfp-session">';
		$content .= '    <div class="cfp-foreword">';
		$content .= '        <a class="cfp-a" href="' . esc_url( cfp_dev_url( '/talks-by-tracks/?id=' . absint( $talk->track->id ) ) ) . '">';
		$content .= '            <div class="cfp-track" title="' . esc_attr( $talk->track->name ) . '" style="background-image: url(' . esc_url( $talk->track->imageURL ) . ')"></div>';
		$content .= '        </a>';
		$content .= '        <a class="cfp-a" href="' . esc_url( $talk_url ) . '">';
		$content .= '            <div class="cfp-name">' . esc_html( $talk->title ) . '</div>';
		$content .= '        </a>';
		$content .= '        <div class="cfp-type">';
		$content .= '            <a href="' . esc_url( cfp_dev_url( '/talks-by-sessions/?id=' . absint( $talk->sessionType->id ) ) ) . '">' . esc_html( $talk->sessionType->name ) . '</a> <em>(' . esc_html( $talk->audienceLevel ) . ' level)</em>';
		$content .= '        </div>';

		$content .= generateTalkScheduleInfo( $talk );
		$content .= getTalkKeywords( $talk );

		$content .= '    </div>';
		$content .= '    <div class="cfp-content">';
		$content .= '        <div class="cfp-text">';
		$content .= wp_kses_post( cleanupDescription( $talk->description ) );
		$content .= '        </div>';
		$content .= '        <a class="cfp-a" href="' . esc_url( $talk_url ) . '">More</a>';

		$content .= '    </div>';

		$content .= generateTalkVideo( $talk );

		$content .= '</section>';

		return $content;
	}

	/**
	 * Renders the date/time/room block for a talk (fetches the talk detail for
	 * its time slots). Returns an empty string when no slot is scheduled.
	 *
	 * @param object $talk  Talk object with at least an id.
	 * @return string
	 */
	function generateTalkScheduleInfo( $talk ) {
		$content     = '';
		$talkDetails = getJSON( 'public/talks/' . absint( $talk->id ) );
		if ( empty( $talkDetails ) || empty( $talkDetails->timeSlots ) || ! is_array( $talkDetails->timeSlots ) ) {
			return $content;
		}

		$slot = array_pop( $talkDetails->timeSlots );
		if ( ! empty( $slot->fromDate ) && ! empty( $slot->toDate ) ) {
			try {
				$timeZone = new DateTimeZone( $slot->timezone );
				$fromDate = new DateTime( $slot->fromDate, $timeZone );
				$fromDate->setTimezone( $timeZone );
				$toDate = new DateTime( $slot->toDate, $timeZone );
				$toDate->setTimezone( $timeZone );
			} catch ( Exception $e ) {
				cfp_dev_log( 'speaker-details: invalid slot date/timezone — ' . $e->getMessage() );
				return $content;
			}

			$content .= '        <div class="cfp-datetime">';
			$content .= '            <time class="cfp-time" datetime="' . esc_attr( $fromDate->format( 'c' ) ) . '">' . esc_html( $fromDate->format( 'l' ) . ' from ' . $fromDate->format( 'H:i' ) ) . '</time>';
			$content .= '            <time class="cfp-time" datetime="' . esc_attr( $toDate->format( 'c' ) ) . '">' . esc_html( $toDate->format( 'H:i' ) ) . '</time>';
			$content .= '        </div>';

			if ( 'yes' === get_option( 'cfp_dev_show_rooms', 'yes' ) && ! empty( $slot->roomName ) ) {
				$content .= '        <div class="cfp-room">' . esc_html( $slot->roomName ) . '</div>';
			}

			$content .= '        <input type="hidden" id="cfpTimezone" value="' . esc_attr( $slot->timezone ) . '">';
			$content .= '        <input type="hidden" id="cfpTalkFrom" value="' . esc_attr( $fromDate->getTimestamp() ) . '">';
			$content .= '        <input type="hidden" id="cfpTalkExpiry" value="' . esc_attr( $toDate->getTimestamp() ) . '">';
		}
		return $content;
	}

	/**
	 * Renders the keyword pills linking to search results.
	 *
	 * @param object $talk  Talk object with an optional keywords list.
	 * @return string
	 */
	function getTalkKeywords( $talk ) {
		$content = '        <div class="cfp-category">';
		if ( empty( $talk->keywords ) ) {
			$content .= '        </div>';
			return $content;
		}
		foreach ( $talk->keywords as $keyword ) {
			$content .= '<span class="cfp-span">';
			$content .= '    <a href="' . esc_url( cfp_dev_url( '/search-results/?query=' . rawurlencode( $keyword->name ) ) ) . '">' . esc_html( ucwords( $keyword->name ) ) . '</a>';
			$content .= '</span>';
		}
		$content .= '        </div>';
		return $content;
	}

	/**
	 * Strips empty Quill-editor paragraphs and span wrappers from API-supplied
	 * rich-text descriptions.
	 *
	 * @param string $description  Raw HTML description.
	 * @return string
	 */
	function cleanupDescription( $description ) {
		$pattern     = '/<p(?: class="ql-align-justify")?><br><\/p>/';
		$description = preg_replace( $pattern, '', $description );
		return preg_replace( '~<span[^>]*>|</span>~', '', $description );
	}

	/**
	 * Renders the YouTube video embed for a talk card, when available.
	 *
	 * @param object $talk  Talk object with an optional videoURL.
	 * @return string
	 */
	function generateTalkVideo( $talk ) {
		$content = '';
		if ( ! empty( $talk->videoURL ) ) {
			$content .= '    <div class="cfp-video">';
			$content .= '        <div class="cfp-picture"></div>';
			$content .= '        <iframe width="560" height="315" style="z-index: 9999999;" src="' . esc_url( $talk->videoURL ) .
				'" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
			$content .= '        <div class="cfp-player"></div>';
			$content .= '    </div>';
		}
		return $content;
	}

	/**
	 * AJAX (public): returns the rendered photo gallery HTML for a speaker.
	 * Read-only endpoint — results are transient-cached per speaker.
	 */
	function get_speaker_photos() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only AJAX endpoint, no state change
		$speakerId = isset( $_GET['speaker_id'] ) ? intval( $_GET['speaker_id'] ) : 0;
		if ( 0 === $speakerId ) {
			wp_send_json_error( 'Invalid speaker ID' );
			return;
		}

		$speakerName = sanitize_text_field( wp_unslash( $_GET['speaker_name'] ?? '' ) );
		// phpcs:enable
		if ( '' === $speakerName ) {
			wp_send_json_error( 'Invalid speaker name' );
			return;
		}

		$cache_key = generate_cfp_cache_key( 'photo', $speakerId );

		$cached_content = get_transient( $cache_key );

		if ( false !== $cached_content ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin-generated HTML, escaped at build time
			echo $cached_content;
			wp_die();
		}

		$photos = getJSONWithRetry( 'public/album/' . $speakerId );

		$content = '';
		if ( empty( $photos ) ) {
			$content = '<p>No photos found</p>';
		} else {
			$content = displaySpeakerPhotos( $content, $photos, $speakerName );
		}

		// set_transient() with 0 would cache forever — skip caching when disabled.
		$ttl = cfp_dev_get_cache_ttl();
		if ( $ttl > 0 ) {
			set_transient( $cache_key, $content, $ttl );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin-generated HTML, escaped at build time
		echo $content;
		wp_die();
	}

	/**
	 * getJSON() with a bounded retry — the album endpoint is occasionally flaky.
	 *
	 * @param string $queryPath    Relative API path.
	 * @param int    $maxAttempts  Maximum number of attempts (brief pause between).
	 * @return mixed  Decoded JSON or null when all attempts fail.
	 */
	function getJSONWithRetry( $queryPath, $maxAttempts = 2 ) {
		for ( $attempt = 1; $attempt <= $maxAttempts; $attempt++ ) {
			$result = getJSON( $queryPath );
			if ( ! empty( $result ) ) {
				return $result;
			}
			if ( $attempt < $maxAttempts ) {
				// Brief pause only — sleep()ing seconds here would pin a PHP-FPM
				// worker per anonymous request (trivial DoS amplifier).
				usleep( 250000 );
			}
		}
		return null;
	}

	/**
	 * Renders the Flickr photo gallery for a speaker.
	 *
	 * @param string $content      Accumulated HTML to append to.
	 * @param array  $photos       Album photos from the API.
	 * @param string $speakerName  Display name (user input — escaped on output).
	 * @return string
	 */
	function displaySpeakerPhotos( $content, $photos, $speakerName ) {
		$content .= '<section class="cfp-gallery">';
		$content .= '    <div class="cfp-frame">';
		foreach ( $photos as $photo ) {
			if ( empty( $photo->thumbnailUrl ) ) {
				continue;
			}
			$content        .= '<a href="' . esc_url( 'https://www.flickr.com/photos/bejug/' . $photo->photoId . '/in/album-' . $photo->albumId . '/' ) . '" target="_blank" rel="noopener noreferrer">';
			$speakerImageAlt = $speakerName . ' speaking at ' . cfp_dev_get_event_name();
			// esc_attr is critical: $speakerName arrives from a public GET parameter.
			$content .= '<img class="cfp-picture" src="' . esc_url( $photo->thumbnailUrl ) . '" alt="' . esc_attr( $speakerImageAlt ) . '">';
			$content .= '</a>';
		}
		$content .= '    </div>';
		$content .= '</section>';
		return $content;
	}

	add_action( 'wp_ajax_get_speaker_photos', 'get_speaker_photos' );
	add_action( 'wp_ajax_nopriv_get_speaker_photos', 'get_speaker_photos' );
}
