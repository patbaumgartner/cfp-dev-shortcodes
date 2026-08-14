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
	function cfp_dev_talks_by_tracks_shortcode( $atts = [] ) {
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

		return cfp_dev_cached_markup(
			cfp_dev_group_cache_key( 'talks_by_tracks_cache_group_' . $track_id . cfp_dev_atts_cache_suffix( $_atts, $defaults ) ),
			static function () use ( $track_id, $_atts ) {
				return cfp_dev_render_talks_by_tracks( $track_id, $_atts );
			},
			cfp_dev_empty_list_page( __( 'No tracks found', 'cfp-dev-shortcodes' ) )
		);
	}

	/**
	 * Renders the talks-by-track page: filter navigation, track description,
	 * and one table row per talk.
	 *
	 * @param int   $track_id  Selected track id (0 → first track, -1 → all tracks).
	 * @param array $_atts    Normalised shortcode attributes (all, title, hide_title, hide_search).
	 * @return string|null  Null when the track list could not be fetched.
	 */
	function cfp_dev_render_talks_by_tracks( $track_id, $_atts ) {
		$tracks = cfp_dev_get_json( 'public/tracks' );

		if ( empty( $tracks ) || ! is_array( $tracks ) ) {
			return null;
		}

		// Sort first: the filter nav below is ordered by name, so choosing the
		// default from the API's own order highlighted a tab that was usually
		// not the one the reader saw first.
		usort( $tracks, 'cfp_dev_compare_name' );

		$track_descr = '';

		if ( empty( $track_id ) ) {
			if ( ! empty( $_atts['all'] ) ) {
				$track_id = -1;
			} else {
				$track_id    = absint( $tracks[0]->id ?? 0 );
				$track_descr = $tracks[0]->description ?? '';
			}
		} else {
			foreach ( $tracks as $track ) {
				if ( absint( $track->id ?? 0 ) === (int) $track_id ) {
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

		$content  = cfp_dev_root_class_script( 'session' );
		$content .= '<div class="cfp-main">';
		$content .= '<section class="cfp-list">';

		$content .= cfp_dev_render_track_filter( $tracks, $track_id, $_atts );

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
		$content .= '    <nav class="cfp-filter" aria-label="' . esc_attr__( 'Tracks', 'cfp-dev-shortcodes' ) . '">';
		foreach ( $tracks as $track ) {
			$is_active = ( absint( $track->id ?? 0 ) === (int) $track_id ) ? 'cfp-active' : '';
			$content  .= '<a class="cfp-a ' . $is_active . '" href="' . esc_url( '?id=' . absint( $track->id ?? 0 ) ) . '">';
			$content  .= esc_html( (string) ( $track->name ?? '' ) ) . '</a>';
		}
		$content .= '    </nav>';
		$content .= '</div>';
		return $content;
	}
}
