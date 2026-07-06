<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_talks_by_sessions]  Talks grouped by session type with filter navigation.
 *
 * @package  CFP.DEV
 * @since    1.0.0
 */
if ( ! function_exists( 'cfp_talks_by_sessions_shortcode' ) ) {

	add_action(
		'plugins_loaded',
		function () {

			if ( ! shortcode_exists( 'cfp_talks_by_sessions' ) ) {
				// Add the shortcode.
				add_shortcode( 'cfp_talks_by_sessions', 'cfp_talks_by_sessions_shortcode' );
			}
		}
	);

	/**
	 * Shortcode CFP talks by session types
	 *
	 * @param array $atts  Shortcode attributes: title, hide_title, hide_search.
	 * @return string
	 * @since  1.0.0
	 */
	function cfp_talks_by_sessions_shortcode( $atts = [] ) {
		$defaults = [
			'title'       => 'Talks grouped by Session Types',
			'hide_title'  => false,
			'hide_search' => false,
		];
		$_atts    = shortcode_atts( $defaults, $atts );

		$_atts['title']       = trim( (string) $_atts['title'] );
		$_atts['hide_title']  = cfp_dev_attr_bool( $_atts['hide_title'] );
		$_atts['hide_search'] = cfp_dev_attr_bool( $_atts['hide_search'] );

		// absint: the id is user input and becomes part of API paths and cache keys.
		$sessionId = absint( get_query_var( 'id' ) );

		$ttl = cfp_dev_get_cache_ttl();
		if ( 0 === $ttl ) {
			return get_talks_by_sessions( $sessionId, $_atts );
		}

		$_cache_group = cfp_dev_group_cache_key( 'talks_by_sessions_cache_group_' . $sessionId . cfp_dev_atts_cache_suffix( $_atts, $defaults ) );
		$cache        = get_transient( $_cache_group );
		if ( false === $cache ) {
			$content = get_talks_by_sessions( $sessionId, $_atts );
			set_transient( $_cache_group, $content, $ttl );
		} else {
			$content = $cache;
		}
		return $content;
	}

	function get_talks_by_sessions( $sessionId, $_atts = [] ) {
		// The Session Types
		$sessions = getJSON( 'public/session-types' );

		$sessionDescr = '';

		if ( ! empty( $sessions ) && is_array( $sessions ) ) {
			if ( empty( $sessionId ) ) {
				foreach ( $sessions as $session ) {
					if ( ! $session->pause ) {
						$sessionId    = $session->id;
						$sessionDescr = $session->description ?? '';
						break;
					}
				}
			} else {
				foreach ( $sessions as $session ) {
					if ( absint( $session->id ) === $sessionId ) {
						$sessionDescr = $session->description ?? '';
						break;
					}
				}
			}
		}

		// Get talks by session type.
		$talks = ! empty( $sessionId ) ? getJSON( 'public/talks/session-type/' . absint( $sessionId ) ) : null;

		$content = cfp_dev_root_class_script( 'session' );

		$content .= '<main class="cfp-main">';

		$content .= '<section class="cfp-list">';

		if ( ! empty( $sessions ) ) {

			$title = empty( $_atts['hide_title'] ) ? (string) ( $_atts['title'] ?? 'Talks grouped by Session Types' ) : '';

			$content .= '<div class="cfp-subject">';
			$content .= cfp_dev_page_header( $title, '', empty( $_atts['hide_search'] ) );
			$content .= '    <nav class="cfp-filter">';
			foreach ( $sessions as $session ) {

				if ( $session->pause ) {
					continue;
				}

				$isActive = ( absint( $session->id ) === absint( $sessionId ) ) ? 'cfp-active' : '';

				$content .= '<a class="cfp-a ' . $isActive . '" href="' . esc_url( '?id=' . absint( $session->id ) ) . '">';
				$content .= esc_html( $session->name ) . '</a>';
			}
			$content .= '    </nav>';

		} else {
			$content .= '<div class="dev-cfp-row">';
			$content .= '    <div class="dev-cfp-column">';
			$content .= '        <p>No session types found</p>';
			$content .= '    </div>';
		}
		$content .= '</div>';

		$content .= '<div class="cfp-group">';
		$content .= '    <div class="cfp-foreword">';
		$content .= '       <div class="cfp-text">' . wp_kses_post( $sessionDescr ) . '</div>';
		$content .= '    </div>';

		// Table heading
		$content .= '    <div class="cfp-row cfp-headline">';
		$content .= '        <div class="cfp-field">Title</div>';
		$content .= '        <div class="cfp-field cfp-speaker">Speakers</div>';
		$content .= '        <div class="cfp-field">Track</div>';
		$content .= '        <div class="cfp-field"></div>';
		$content .= '    </div>';

		if ( ! empty( $talks ) && is_array( $talks ) ) {
			$use_slugs = ( 'no' === get_option( 'cfp_dev_content_by_id', 'yes' ) );
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
				$content .= '        <div class="cfp-track" style="background-image: url(' . esc_url( $talk->trackImageURL ) . ')"></div>';
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
		}

		$content .= '</div>';   // End of cfp-group

		$content .= '</section>';
		$content .= '</div>';    // End main

		$content .= getFooter();

		return $content;
	}
}
