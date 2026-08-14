<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_speakers]  Speaker grid with photos, optional search form.
 *
 * @package  CFP.DEV
 * @since    1.0.0
 */

if ( ! function_exists( 'cfp_dev_speakers_shortcode' ) ) {

	add_action(
		'plugins_loaded',
		function () {

			if ( ! shortcode_exists( 'cfp_speakers' ) ) {
				add_shortcode( 'cfp_speakers', 'cfp_dev_speakers_shortcode' );
			}
		}
	);

	/**
	 * Shortcode handler for [cfp_speakers].
	 *
	 * @param array $atts  Shortcode attributes: size, random, title, subtitle, hide_title, hide_search.
	 * @return string
	 * @since  1.0.0
	 */
	function cfp_dev_speakers_shortcode( $atts = [] ) {
		$_atts = shortcode_atts( cfp_dev_speakers_default_atts(), $atts );

		$_atts['random']      = cfp_dev_attr_bool( $_atts['random'] );
		$_atts['hide_title']  = cfp_dev_attr_bool( $_atts['hide_title'] );
		$_atts['hide_search'] = cfp_dev_attr_bool( $_atts['hide_search'] );
		$_atts['size']        = absint( $_atts['size'] );

		return cfp_dev_cached_markup(
			cfp_dev_speakers_cache_key( $_atts ),
			static function () use ( $_atts ) {
				// An empty list is an answer worth caching; a null is the API
				// having failed to give one.
				$data = cfp_dev_get_json( 'public/speakers?size=' . $_atts['size'] );
				return is_array( $data ) ? cfp_dev_render_speakers( $data, $_atts ) : null;
			},
			'<p>' . esc_html__( 'No speakers found.', 'cfp-dev-shortcodes' ) . '</p>'
		);
	}

	/**
	 * Renders the speaker grid (sorted or shuffled, capped at size).
	 *
	 * @param array|null $data   Speaker list from the API.
	 * @param array      $_atts  Normalised shortcode attributes.
	 * @return string
	 */
	function cfp_dev_render_speakers( $data, $_atts ) {
		if ( empty( $data ) || ! is_array( $data ) ) {
			return '<p>' . esc_html__( 'No speakers found.', 'cfp-dev-shortcodes' ) . '</p>';
		}

		if ( $_atts['random'] ) {
			shuffle( $data );
		} else {
			usort( $data, 'cfp_dev_compare_last_name' );
		}

		// Enforce the size attribute locally: the live API honours ?size=, but
		// the offline snapshot always returns the full speaker list.
		if ( $_atts['size'] > 0 && count( $data ) > $_atts['size'] ) {
			$data = array_slice( $data, 0, $_atts['size'] );
		}

		$content  = cfp_dev_root_class_script( 'speaker' );
		$content .= '<div class="cfp-main">';
		$content .= '<section class="cfp-speaker">';
		$content .= '    <div class="cfp-subject">';

		$_title   = trim( (string) $_atts['title'] );
		$content .= cfp_dev_page_header(
			$_atts['hide_title'] ? '' : ( '' !== $_title ? $_title : __( 'Speakers', 'cfp-dev-shortcodes' ) ),
			trim( (string) $_atts['subtitle'] ),
			! $_atts['hide_search']
		);

		$content .= '    </div>';
		$content .= '    <div class="cfp-block">';

		foreach ( $data as $speaker ) {
			$content .= ' <div class="cfp-person">';
			$first    = (string) ( $speaker->firstName ?? '' );
			$last     = (string) ( $speaker->lastName ?? '' );
			$content .= '<a class="cfp-a" href="' . esc_url( cfp_dev_speaker_url( $speaker ) ) . '">';
			$content .= '           <div class="cfp-picture" style="background-image: url(\'' . esc_url( (string) ( $speaker->imageUrl ?? '' ) ) . '\')"></div>';
			$content .= '        <div class="cfp-name">' . esc_html( trim( $first . ' ' . $last ) ) . '</div>';
			if ( ! empty( $speaker->company ) ) {
				$content .= '        <div class="cfp-company">' . esc_html( $speaker->company ) . '</div>';
			}
			$content .= '    </a>';
			$content .= ' </div>';
		}

		$content .= '</div>';
		$content .= '</section>';
		$content .= '</div>';

		$content .= cfp_dev_footer();

		return $content;
	}
}
