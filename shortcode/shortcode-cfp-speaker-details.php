<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_speaker_details]  Speaker detail page: profile, bio, social links, talks, photo gallery.
 *
 * @package  CFP.DEV
 * @since    1.0.0
 */

if ( ! function_exists( 'cfp_dev_speaker_details_shortcode' ) ) {
	add_action(
		'plugins_loaded',
		function () {
			if ( ! shortcode_exists( 'cfp_speaker_details' ) ) {
				add_shortcode( 'cfp_speaker_details', 'cfp_dev_speaker_details_shortcode' );
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
	function cfp_dev_speaker_details_shortcode() {
		$speaker_slug = get_query_var( 'speaker_slug' );
		$speaker_id   = absint( get_query_var( 'id' ) );

		if ( ! empty( $speaker_slug ) ) {
			cfp_dev_log( 'speaker-details: resolving slug=' . $speaker_slug );
			$speaker_id = cfp_dev_speaker_id_from_slug( sanitize_title( $speaker_slug ) );
		}

		if ( empty( $speaker_id ) ) {
			return esc_html__( 'Speaker not found.', 'cfp-dev-shortcodes' );
		}

		$ttl = cfp_dev_get_cache_ttl();

		if ( 0 === $ttl ) {
			cfp_dev_log( 'speaker-details: cache disabled for id=' . $speaker_id );
			$speaker_info = cfp_dev_get_speaker_by_id( $speaker_id );
			if ( empty( $speaker_info ) ) {
				cfp_dev_log( 'speaker-details: speaker not found for id=' . $speaker_id );
				return esc_html__( 'Speaker not found.', 'cfp-dev-shortcodes' );
			}
			return cfp_dev_render_speaker_page( $speaker_info );
		}

		$speaker_cache_key = cfp_dev_detail_cache_key( 'speaker', $speaker_id );

		$cache = get_transient( $speaker_cache_key );
		if ( false !== $cache ) {
			cfp_dev_log( 'speaker-details: cache hit for id=' . $speaker_id );
			return $cache;
		}

		cfp_dev_log( 'speaker-details: cache miss for id=' . $speaker_id );
		$speaker_info = cfp_dev_get_speaker_by_id( $speaker_id );
		if ( empty( $speaker_info ) ) {
			// Do not cache failures — the API may just be temporarily unavailable.
			cfp_dev_log( 'speaker-details: speaker not found for id=' . $speaker_id );
			return esc_html__( 'Speaker not found.', 'cfp-dev-shortcodes' );
		}

		$content = cfp_dev_render_speaker_page( $speaker_info );
		set_transient( $speaker_cache_key, $content, $ttl );
		return $content;
	}

	/**
	 * Renders the full speaker page: profile, talks, and the async photo
	 * gallery placeholder (populated by an AJAX fetch on the client).
	 *
	 * @param object $speaker  Speaker detail object from the API.
	 * @return string
	 */
	function cfp_dev_render_speaker_page( $speaker ) {
		$content = cfp_dev_root_class_script( 'speaker', 'detail' );

		$content .= '<div class="cfp-main">';

		$content .= cfp_dev_render_speaker_profile( $speaker );

		// Photo album placeholder — filled asynchronously by the fetch below.
		$spinner_file = CFP_DEV_DIR . '/images/loading-spinner.svg';
		$content     .= '<div id="speaker-photo-album">';
		$content     .= '    <div id="loading-container">';
		$content     .= '        <div id="loading-spinner">';
		if ( file_exists( $spinner_file ) ) {
			$content .= '    ' . file_get_contents( $spinner_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, not remote URL
		}
		$content .= '        </div>';
		$content .= '        <p id="photo-loading-message">' . esc_html__( 'Searching for speaker images...', 'cfp-dev-shortcodes' ) . '</p>';
		$content .= '    </div>';
		$content .= '</div>';

		// Build the AJAX URL server-side and hand it to JS as a JSON literal so a
		// speaker name containing quotes or </script> can never break the script.
		$photos_url = add_query_arg(
			[
				'action'     => 'cfp_dev_speaker_photos',
				'speaker_id' => absint( $speaker->id ),
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
									 photoAlbum.innerHTML = ' . wp_json_encode( '<p>' . esc_html__( 'Couldn\'t find any photos', 'cfp-dev-shortcodes' ) . '</p>' ) . ';
								 } else {
									 photoAlbum.innerHTML = data;
								 }
							 })
							 .catch(error => {
								 loadingMessage.style.display = "none";
								 loadingSpinner.style.display = "none";
								photoAlbum.innerHTML = ' . wp_json_encode( '<p>' . esc_html__( 'Error loading speaker photos', 'cfp-dev-shortcodes' ) . '</p>' ) . ';
							 });
					 });
				 </script>';

		$content .= '</div>';
		$content .= cfp_dev_footer();
		return $content;
	}

	/**
	 * Renders the profile section (photo, name, socials, company, bio) followed
	 * by one talk card per proposal.
	 *
	 * @param object $speaker  Speaker detail object from the API.
	 * @return string
	 */
	function cfp_dev_render_speaker_profile( $speaker ) {
		$content  = '<section class="cfp-profile">';
		$content .= '    <div class="cfp-picture" style="background-image: url(\'' . esc_url( (string) ( $speaker->imageUrl ?? '' ) ) . '\')"></div>';
		$content .= '    <div class="cfp-content">';
		$content .= '        <div class="cfp-detail">';
		$content .= '            <div class="cfp-name">' . esc_html( trim( ( $speaker->firstName ?? '' ) . ' ' . ( $speaker->lastName ?? '' ) ) ) . '</div>';
		$content .= cfp_dev_social_links( $speaker );
		$content .= '        </div>';
		if ( ! empty( $speaker->company ) ) {
			$content .= '        <div class="cfp-company cfp-company-left">' . esc_html( $speaker->company ) . '</div>';
		}
		$content .= '        <div class="cfp-text">';
		$content .= wp_kses_post( (string) ( $speaker->bio ?? '' ) );
		$content .= '        </div>';
		$content .= '    </div>';
		$content .= '</section>';

		if ( ! empty( $speaker->proposals ) ) {
			foreach ( $speaker->proposals as $talk ) {
				$content .= cfp_dev_render_speaker_talk( $talk );
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
	function cfp_dev_render_speaker_talk( $talk ) {
		$talk_url = cfp_dev_talk_url( $talk );

		$content  = '<section class="cfp-session">';
		$content .= '    <div class="cfp-foreword">';
		$content .= '        <a class="cfp-a" href="' . esc_url( cfp_dev_url( '/talks-by-tracks/?id=' . absint( $talk->track->id ?? 0 ) ) ) . '">';
		$content .= '            <div class="cfp-track" title="' . esc_attr( (string) ( $talk->track->name ?? '' ) ) . '" style="background-image: url(\'' . esc_url( (string) ( $talk->track->imageURL ?? '' ) ) . '\')"></div>';
		$content .= '        </a>';
		$content .= '        <a class="cfp-a" href="' . esc_url( $talk_url ) . '">';
		$content .= '            <div class="cfp-name">' . esc_html( (string) ( $talk->title ?? '' ) ) . '</div>';
		$content .= '        </a>';
		$content .= '        <div class="cfp-type">';
		/* translators: %s: audience level. */
		$content .= '            <a href="' . esc_url( cfp_dev_url( '/talks-by-sessions/?id=' . absint( $talk->sessionType->id ?? 0 ) ) ) . '">' . esc_html( (string) ( $talk->sessionType->name ?? '' ) ) . '</a> <em>(' . esc_html( sprintf( __( '%s level', 'cfp-dev-shortcodes' ), (string) ( $talk->audienceLevel ?? '' ) ) ) . ')</em>';
		$content .= '        </div>';

		$content .= cfp_dev_render_proposal_schedule( $talk );
		$content .= cfp_dev_render_keywords( $talk );

		$content .= '    </div>';
		$content .= '    <div class="cfp-content">';
		$content .= '        <div class="cfp-text">';
		$content .= wp_kses_post( cfp_dev_clean_description( (string) ( $talk->description ?? '' ) ) );
		$content .= '        </div>';
		$content .= '        <a class="cfp-a" href="' . esc_url( $talk_url ) . '">' . esc_html__( 'More', 'cfp-dev-shortcodes' ) . '</a>';

		$content .= '    </div>';

		$content .= cfp_dev_render_speaker_talk_video( $talk );

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
	function cfp_dev_render_proposal_schedule( $talk ) {
		$talk_details = cfp_dev_get_json( 'public/talks/' . absint( $talk->id ?? 0 ) );
		if ( empty( $talk_details->timeSlots ) || ! is_array( $talk_details->timeSlots ) ) {
			return '';
		}

		// end() on a local copy: array_pop() would consume the slots on the
		// shared, memoised talk object.
		$slots = (array) $talk_details->timeSlots;

		return cfp_dev_render_time_slot( end( $slots ) );
	}

	/**
	 * Renders the keyword pills linking to search results.
	 *
	 * @param object $talk  Talk object with an optional keywords list.
	 * @return string
	 */
	function cfp_dev_render_keywords( $talk ) {
		$content = '        <div class="cfp-category">';
		if ( empty( $talk->keywords ) ) {
			$content .= '        </div>';
			return $content;
		}
		foreach ( $talk->keywords as $keyword ) {
			$name     = (string) ( $keyword->name ?? '' );
			$content .= '<span class="cfp-span">';
			$content .= '    <a href="' . esc_url( cfp_dev_url( '/search-results/?query=' . rawurlencode( $name ) ) ) . '">' . esc_html( ucwords( $name ) ) . '</a>';
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
	function cfp_dev_clean_description( $description ) {
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
	function cfp_dev_render_speaker_talk_video( $talk ) {
		$video = cfp_dev_embed_url( $talk->videoURL ?? '', cfp_dev_video_embed_hosts() );
		if ( '' === $video ) {
			return '';
		}

		$content  = '    <div class="cfp-video">';
		$content .= '        <div class="cfp-picture"></div>';
		$content .= '        <iframe width="560" height="315" style="z-index: 9999999;" src="' . esc_url( $video ) .
			'" title="' . esc_attr(
				/* translators: %s: talk title. */
				sprintf( __( 'Video: %s', 'cfp-dev-shortcodes' ), (string) ( $talk->title ?? '' ) )
			) . '" loading="lazy" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
		$content .= '        <div class="cfp-player"></div>';
		$content .= '    </div>';

		return $content;
	}

	/**
	 * AJAX (public): returns the rendered photo gallery HTML for a speaker.
	 * Read-only endpoint — results are transient-cached per speaker.
	 */
	function cfp_dev_speaker_photos_handler() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only AJAX endpoint, no state change
		$speaker_id = isset( $_GET['speaker_id'] ) ? absint( wp_unslash( $_GET['speaker_id'] ) ) : 0;
		if ( 0 === $speaker_id ) {
			wp_send_json_error( __( 'Invalid speaker ID', 'cfp-dev-shortcodes' ) );
			return;
		}

		// Unauthenticated endpoint: without this check, walking speaker_id from
		// 1 upwards mints one upstream request and one transient per value.
		// A nonce is the usual answer but is wrong here — the gallery URL is
		// embedded in page HTML that full-page caches serve to everyone, so the
		// nonce would be stale or foreign. Bounding the id to speakers that
		// actually exist costs one cached list lookup and caps the blast radius
		// at the real speaker count.
		if ( ! cfp_dev_speaker_exists( $speaker_id ) ) {
			wp_send_json_error( __( 'Unknown speaker ID', 'cfp-dev-shortcodes' ) );
			return;
		}

		$cache_key      = cfp_dev_detail_cache_key( 'photo', $speaker_id );
		$cached_content = get_transient( $cache_key );

		if ( false !== $cached_content ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin-generated HTML, escaped at build time
			echo $cached_content;
			wp_die();
		}

		// The speaker name is resolved from the API rather than taken from the
		// request: it is baked into the gallery markup, and that markup is
		// cached under the speaker id alone — so a caller-supplied name would
		// be served to every later visitor of this speaker's page.
		$speaker      = cfp_dev_get_speaker_by_id( $speaker_id );
		$speaker_name = ! empty( $speaker->firstName )
			? trim( $speaker->firstName . ' ' . ( $speaker->lastName ?? '' ) )
			: '';

		$photos  = '' !== $speaker_name ? cfp_dev_get_json( 'public/album/' . $speaker_id ) : null;
		$content = empty( $photos )
			? '<p>' . esc_html__( 'No photos found', 'cfp-dev-shortcodes' ) . '</p>'
			: cfp_dev_render_photo_gallery( $photos, $speaker_name );

		$ttl = cfp_dev_get_cache_ttl();
		if ( empty( $photos ) ) {
			// This endpoint is unauthenticated, so an uncached "no photos"
			// answer turns every page refresh into two upstream requests.
			// Misses are cached even when caching is switched off.
			set_transient( $cache_key, $content, max( $ttl, 5 * MINUTE_IN_SECONDS ) );
		} elseif ( $ttl > 0 ) {
			// set_transient() with 0 would cache forever — skip caching when disabled.
			set_transient( $cache_key, $content, $ttl );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin-generated HTML, escaped at build time
		echo $content;
		wp_die();
	}

	/**
	 * Renders the Flickr photo gallery for a speaker.
	 *
	 * @param array  $photos       Album photos from the API.
	 * @param string $speaker_name  Display name, resolved from the API.
	 * @return string
	 */
	function cfp_dev_render_photo_gallery( $photos, $speaker_name ) {
		/* translators: 1: speaker name, 2: event name. */
		$speaker_image_alt = sprintf( __( '%1$s speaking at %2$s', 'cfp-dev-shortcodes' ), $speaker_name, cfp_dev_get_event_name() );

		$content  = '<section class="cfp-gallery">';
		$content .= '    <div class="cfp-frame">';
		foreach ( $photos as $photo ) {
			if ( empty( $photo->thumbnailUrl ) ) {
				continue;
			}
			$content .= '<a href="' . esc_url( 'https://www.flickr.com/photos/bejug/' . absint( $photo->photoId ?? 0 ) . '/in/album-' . absint( $photo->albumId ?? 0 ) . '/' ) . '" target="_blank" rel="noopener noreferrer">';
			$content .= '<img class="cfp-picture" src="' . esc_url( $photo->thumbnailUrl ) . '" alt="' . esc_attr( $speaker_image_alt ) . '" loading="lazy">';
			$content .= '</a>';
		}
		$content .= '    </div>';
		$content .= '</section>';
		return $content;
	}

	add_action( 'wp_ajax_cfp_dev_speaker_photos', 'cfp_dev_speaker_photos_handler' );
	add_action( 'wp_ajax_nopriv_cfp_dev_speaker_photos', 'cfp_dev_speaker_photos_handler' );
}
