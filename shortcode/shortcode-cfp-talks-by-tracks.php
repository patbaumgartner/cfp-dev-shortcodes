<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_talks_by_tracks]  Talks grouped by track with filter navigation.
 *
 * @package  CFP.DEV
 * @since    1.0.0
 */
if ( ! function_exists( 'cfp_talks_by_tracks_shortcode' ) ) {

	add_action(
		'plugins_loaded',
		function () {

			if ( ! shortcode_exists( 'cfp_talks_by_tracks' ) ) {
				// Add the shortcode.
				add_shortcode( 'cfp_talks_by_tracks', 'cfp_talks_by_tracks_shortcode' );
			}
		}
	);

	/**
	 * Shortcode CFP talks by tracks
	 * @return string
	 * @since  1.0.0
	 */
	function cfp_talks_by_tracks_shortcode( $atts ) {
		// absint: the id is user input and becomes part of API paths and cache keys.
		$trackId = absint( get_query_var( 'id' ) );

		$ttl = cfp_dev_get_cache_ttl();
		if ( 0 === $ttl ) {
			return cfp_get_talks_by_tracks( $trackId, $atts );
		}

		$cacheGroup = cfp_dev_group_cache_key( 'talks_by_tracks_cache_group_' . $trackId );
		$cache      = get_transient( $cacheGroup );
		if ( false === $cache ) {
			$content = cfp_get_talks_by_tracks( $trackId, $atts );
			set_transient( $cacheGroup, $content, $ttl );
		} else {
			$content = $cache;
		}
		return $content;
	}

	function cfp_get_talks_by_tracks( $trackId, $atts ) {
		// Get the Tracks
		$tracks = getJSON( 'public/tracks' );

		if ( empty( $tracks ) || ! is_array( $tracks ) ) {
			return modifyCfpClasses() . '<main class="cfp-main"><section class="cfp-list">' . displayNoTracksMessage() . '</section></main>';
		}

		$trackDescr = '';

		// Track id was not given
		if ( empty( $trackId ) ) {
			// Save $atts.
			$_atts = shortcode_atts(
				[
					'all' => false,
				],
				$atts
			);

			$showAll = filter_var( $_atts['all'], FILTER_VALIDATE_BOOLEAN );

			if ( $showAll ) {
				$trackId = -1;
			} else {
				// Take the first one from the list
				$trackId    = $tracks[0]->id;
				$trackDescr = $tracks[0]->description ?? '';
			}
		} else {
			// Filter on track
			foreach ( $tracks as $track ) {
				if ( (int) $track->id === (int) $trackId ) {
					$trackDescr = $track->description ?? '';
					break;
				}
			}
		}

		if ( -1 === $trackId ) {
			$talks = getJSON( 'public/talks' );
		} else {
			$talks = getJSON( 'public/talks/track/' . absint( $trackId ) );
		}

		$content  = modifyCfpClasses();
		$content .= '<main class="cfp-main">';
		$content .= '<section class="cfp-list">';

		if ( ! empty( $tracks ) ) {
			usort( $tracks, 'compareName' );
			$content .= displayTalksByTrack( $tracks, $trackId );
		} else {
			$content .= displayNoTracksMessage();
		}

		$content .= '<div class="cfp-group">';
		$content .= '    <div class="cfp-foreword">';
		if ( ! empty( $trackDescr ) ) {
			$content .= '       <div class="cfp-text">' . wp_kses_post( $trackDescr ) . '</div>';
		}
		$content .= '    </div>';

		$content .= generateTableHeading();
		$content .= generateTalkArticles( $talks );

		$content .= '</div>';   // End of cfp-group
		$content .= '</section>';
		$content .= '</div>';    // End main

		$content .= getFooter();
		return $content;
	}

	/**
	/**
	 * Emits the root-element class script for this page type.
	 *
	 * @return string
	 */
	function modifyCfpClasses() {
		return cfp_dev_root_class_script( 'session' );
	}

	/**
	 * Renders the table heading row.
	 *
	 * @return string
	 */
	function generateTableHeading() {
		$content  = '    <div class="cfp-row cfp-headline">';
		$content .= '        <div class="cfp-field">Title</div>';
		$content .= '        <div class="cfp-field cfp-speaker">Speakers</div>';
		$content .= '        <div class="cfp-field">Track</div>';
		$content .= '        <div class="cfp-field"></div>';
		$content .= '    </div>';
		return $content;
	}

	/**
	 * Renders one table row per talk (title, speakers, track image, view link).
	 *
	 * @param array|null $talks  Talks from the API, or null on failure.
	 * @return string
	 */
	function generateTalkArticles( $talks ) {
		if ( empty( $talks ) || ! is_array( $talks ) ) {
			return '';
		}
		$use_slugs = ( 'no' === get_option( 'cfp_dev_content_by_id', 'yes' ) );
		$content   = '';
		foreach ( $talks as $talk ) {
			$content .= '<article class="cfp-article cfp-row cfp-event">';
			$content .= '    <div class="cfp-field">' . esc_html( $talk->title ) . '</div>';
			$content .= '    <div class="cfp-field cfp-speaker">';
			foreach ( $talk->speakers as $speaker ) {
				if ( $use_slugs ) {
					$speaker_slug = generate_slug( $speaker->firstName . '-' . $speaker->lastName );
					$content     .= '<a class="cfp-a" href="' . esc_url( cfp_dev_url( "/speaker/{$speaker_slug}" ) ) . '">' . esc_html( $speaker->firstName ) . '&nbsp;' . esc_html( $speaker->lastName ) . '</a>';
				} else {
					$content .= '<a class="cfp-a" href="' . esc_url( cfp_dev_url( '/speaker?id=' . absint( $speaker->id ) ) ) . '">' . esc_html( $speaker->firstName ) . '&nbsp;' . esc_html( $speaker->lastName ) . '</a>';
				}
			}
			$content .= '    </div>';
			$content .= '    <div class="cfp-field">';
			if ( empty( $talk->trackImageURL ) ) {
				$trackImageURL = $talk->track->imageURL ?? '';
			} else {
				$trackImageURL = $talk->trackImageURL;
			}
			$content .= '        <div class="cfp-track" style="background-image: url(' . esc_url( $trackImageURL ) . ')"></div>';
			$content .= '    </div>';
			$content .= '    <div class="cfp-field">';
			if ( $use_slugs ) {
				$content .= '        <a class="cfp-a" href="' . esc_url( cfp_dev_url( '/talk/' . generate_slug( $talk->title ) ) ) . '">View</a>';
			} else {
				$content .= '        <a class="cfp-a" href="' . esc_url( cfp_dev_url( '/talk?id=' . absint( $talk->id ) ) ) . '">View</a>';
			}
			$content .= '    </div>';
			$content .= '</article>';
		}
		return $content;
	}

	/**
	 * Renders the "no tracks found" placeholder.
	 *
	 * @return string
	 */
	function displayNoTracksMessage() {
		$content  = '<div class="dev-cfp-row">';
		$content .= '    <div class="dev-cfp-column">';
		$content .= '        <p>No tracks found</p>';
		$content .= '    </div>';
		$content .= '</div>';
		return $content;
	}

	/**
	 * Renders the page heading, search form, and track filter navigation.
	 *
	 * @param array $tracks   All tracks from the API.
	 * @param int   $trackId  Currently selected track id.
	 * @return string
	 */
	function displayTalksByTrack( $tracks, $trackId ) {
		$content  = '<div class="cfp-subject">';
		$content .= '    <div class="cfp-primary">';
		$content .= '        <div class="cfp-name">Talks grouped by Track</div>';
		$content .= getSearchForm();
		$content .= '    </div>';
		$content .= '    <nav class="cfp-filter">';
		foreach ( $tracks as $track ) {
			$isActive = ( (int) $track->id === (int) $trackId ) ? 'cfp-active' : '';
			$content .= '<a class="cfp-a ' . $isActive . '" href="' . esc_url( '?id=' . absint( $track->id ) ) . '">';
			$content .= esc_html( $track->name ) . '</a>';
		}
		$content .= '    </nav>';
		$content .= '</div>';
		return $content;
	}
} // End if().
