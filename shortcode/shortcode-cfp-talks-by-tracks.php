<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_talks_by_tracks]  Talks grouped by track with filter navigation.
 *
 * @package  CFP.DEV
 * @since    1.0.0
 */
if ( ! function_exists( 'cfp_dev_talks_by_tracks_shortcode' ) ) {

	add_action(
		'plugins_loaded',
		function () {

			if ( ! shortcode_exists( 'cfp_talks_by_tracks' ) ) {
				add_shortcode( 'cfp_talks_by_tracks', 'cfp_dev_talks_by_tracks_shortcode' );
			}
		}
	);

	/**
	 * Shortcode handler for [cfp_talks_by_tracks].
	 *
	 * @param array $atts  Shortcode attributes: all, title, hide_title, hide_search.
	 * @return string
	 * @since  1.0.0
	 */
	function cfp_dev_talks_by_tracks_shortcode( $atts ) {
		$defaults = [
			'all'         => false,
			'title'       => __( 'Talks grouped by Track', 'cfp-dev-shortcodes' ),
			'hide_title'  => false,
			'hide_search' => false,
		];
		$_atts    = shortcode_atts( $defaults, $atts );

		$_atts['all']         = cfp_dev_attr_bool( $_atts['all'] );
		$_atts['title']       = trim( (string) $_atts['title'] );
		$_atts['hide_title']  = cfp_dev_attr_bool( $_atts['hide_title'] );
		$_atts['hide_search'] = cfp_dev_attr_bool( $_atts['hide_search'] );

		// absint: the id is user input and becomes part of API paths and cache keys.
		$track_id = absint( get_query_var( 'id' ) );

		$ttl = cfp_dev_get_cache_ttl();
		if ( 0 === $ttl ) {
			return cfp_dev_render_talks_by_tracks( $track_id, $_atts );
		}

		$cache_group = cfp_dev_group_cache_key( 'talks_by_tracks_cache_group_' . $track_id . cfp_dev_atts_cache_suffix( $_atts, $defaults ) );
		$cache       = get_transient( $cache_group );
		if ( false === $cache ) {
			$content = cfp_dev_render_talks_by_tracks( $track_id, $_atts );
			set_transient( $cache_group, $content, $ttl );
		} else {
			$content = $cache;
		}
		return $content;
	}

	/**
	 * Renders the talks-by-track page: filter navigation, track description,
	 * and one table row per talk.
	 *
	 * @param int   $track_id  Selected track id (0 → first track, -1 → all tracks).
	 * @param array $_atts    Normalised shortcode attributes (all, title, hide_title, hide_search).
	 * @return string
	 */
	function cfp_dev_render_talks_by_tracks( $track_id, $_atts ) {
		$tracks = cfp_dev_get_json( 'public/tracks' );

		if ( empty( $tracks ) || ! is_array( $tracks ) ) {
			return cfp_dev_session_root_class_script() . '<div class="cfp-main"><section class="cfp-list">' . cfp_dev_render_no_tracks() . '</section></div>';
		}

		$track_descr = '';

		if ( empty( $track_id ) ) {
			if ( ! empty( $_atts['all'] ) ) {
				$track_id = -1;
			} else {
				// Default to the first track.
				$track_id    = $tracks[0]->id;
				$track_descr = $tracks[0]->description ?? '';
			}
		} else {
			foreach ( $tracks as $track ) {
				if ( (int) $track->id === (int) $track_id ) {
					$track_descr = $track->description ?? '';
					break;
				}
			}
		}

		if ( -1 === $track_id ) {
			$talks = cfp_dev_get_json( 'public/talks' );
		} else {
			$talks = cfp_dev_get_json( 'public/talks/track/' . absint( $track_id ) );
		}

		$content  = cfp_dev_session_root_class_script();
		$content .= '<div class="cfp-main">';
		$content .= '<section class="cfp-list">';

		if ( ! empty( $tracks ) ) {
			usort( $tracks, 'cfp_dev_compare_name' );
			$content .= cfp_dev_render_track_filter( $tracks, $track_id, $_atts );
		} else {
			$content .= cfp_dev_render_no_tracks();
		}

		$content .= '<div class="cfp-group">';
		$content .= '    <div class="cfp-foreword">';
		if ( ! empty( $track_descr ) ) {
			$content .= '       <div class="cfp-text">' . wp_kses_post( (string) $track_descr ) . '</div>';
		}
		$content .= '    </div>';

		$content .= cfp_dev_talk_table_heading();
		$content .= cfp_dev_talk_table_rows( $talks );

		$content .= '</div>';
		$content .= '</section>';
		$content .= '</div>';

		$content .= cfp_dev_footer();
		return $content;
	}

	/**
	 * Emits the root-element class script for this page type.
	 *
	 * @return string
	 */
	function cfp_dev_session_root_class_script() {
		return cfp_dev_root_class_script( 'session' );
	}

	/**
	 * Renders the "no tracks found" placeholder.
	 *
	 * @return string
	 */
	function cfp_dev_render_no_tracks() {
		$content  = '<div class="dev-cfp-row">';
		$content .= '    <div class="dev-cfp-column">';
		$content .= '        <p>' . esc_html__( 'No tracks found', 'cfp-dev-shortcodes' ) . '</p>';
		$content .= '    </div>';
		$content .= '</div>';
		return $content;
	}

	/**
	 * Renders the page heading, search form, and track filter navigation.
	 *
	 * @param array $tracks   All tracks from the API.
	 * @param int   $track_id  Currently selected track id.
	 * @param array $_atts    Normalised shortcode attributes (title, hide_title, hide_search).
	 * @return string
	 */
	function cfp_dev_render_track_filter( $tracks, $track_id, $_atts = [] ) {
		$title = empty( $_atts['hide_title'] ) ? (string) ( $_atts['title'] ?? __( 'Talks grouped by Track', 'cfp-dev-shortcodes' ) ) : '';

		$content  = '<div class="cfp-subject">';
		$content .= cfp_dev_page_header( $title, '', empty( $_atts['hide_search'] ) );
		$content .= '    <nav class="cfp-filter">';
		foreach ( $tracks as $track ) {
			$is_active = ( (int) $track->id === (int) $track_id ) ? 'cfp-active' : '';
			$content  .= '<a class="cfp-a ' . $is_active . '" href="' . esc_url( '?id=' . absint( $track->id ) ) . '">';
			$content  .= esc_html( $track->name ) . '</a>';
		}
		$content .= '    </nav>';
		$content .= '</div>';
		return $content;
	}
}
