<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_speakers]  Speaker grid with photos, optional search form.
 *
 * @package  CFP.DEV
 * @since    1.0.0
 */

if ( ! function_exists( 'cfp_speakers_shortcode' ) ) {

	add_action(
		'plugins_loaded',
		function () {

			if ( ! shortcode_exists( 'cfp_speakers' ) ) {
				add_shortcode( 'cfp_speakers', 'cfp_speakers_shortcode' );
			}
		}
	);

	function cfp_speakers_shortcode( $atts ) {
		$_atts = shortcode_atts( cfp_dev_speakers_default_atts(), $atts );

		// Normalise: 'no'/'false'/'0' must be falsy (any non-empty string is truthy in PHP).
		$_atts['random']      = filter_var( $_atts['random'], FILTER_VALIDATE_BOOLEAN );
		$_atts['hide_search'] = filter_var( $_atts['hide_search'], FILTER_VALIDATE_BOOLEAN );
		$_atts['size']        = absint( $_atts['size'] );

		$ttl = cfp_dev_get_cache_ttl();

		if ( 0 === $ttl ) {
			$data    = getJSON( 'public/speakers?size=' . $_atts['size'] );
			$content = generate_speakers_content( $data, $_atts );
		} else {
			$cache_key = cfp_dev_speakers_cache_key( $_atts );
			$cache     = get_transient( $cache_key );
			if ( false === $cache ) {
				$data    = getJSON( 'public/speakers?size=' . $_atts['size'] );
				$content = generate_speakers_content( $data, $_atts );
				set_transient( $cache_key, $content, $ttl );
			} else {
				$content = $cache;
			}
		}

		return $content;
	}

	function generate_speakers_content( $data, $_atts ) {
		if ( empty( $data ) || ! is_array( $data ) ) {
			return '<p>No speakers found.</p>';
		}

		if ( $_atts['random'] ) {
			shuffle( $data );
		} else {
			usort( $data, 'compareLastName' );
		}

		$content  = cfp_dev_root_class_script( 'speaker' );
		$content .= '<main class="cfp-main">';
		$content .= '<section class="cfp-speaker">';
		$content .= '    <div class="cfp-subject">';
		$content .= '        <div class="cfp-primary">';

		$_title   = trim( (string) $_atts['title'] );
		$content .= '            <div class="cfp-name">' . esc_html( '' !== $_title ? $_title : 'Speakers' ) . '</div>';

		$_subtitle = trim( (string) $_atts['subtitle'] );
		if ( '' !== $_subtitle ) {
			$content .= '            <div class="cfp-company">' . esc_html( $_subtitle ) . '</div>';
		}

		if ( ! $_atts['hide_search'] ) {
			$content .= getSearchForm();
		}

		$content .= '        </div>';
		$content .= '    </div>';
		$content .= '    <div class="cfp-block">';

		$use_slugs = ( 'no' === get_option( 'cfp_dev_content_by_id', 'yes' ) );

		foreach ( $data as $speaker ) {
			$content .= ' <div class="cfp-person">';
			if ( $use_slugs ) {
				$speaker_slug = generate_slug( $speaker->firstName . '-' . $speaker->lastName );
				$content     .= '<a class="cfp-a" href="' . esc_url( cfp_dev_url( "/speaker/{$speaker_slug}" ) ) . '">';
			} else {
				$content .= '<a class="cfp-a" href="' . esc_url( cfp_dev_url( '/speaker?id=' . absint( $speaker->id ) ) ) . '">';
			}
			$content .= '           <div class="cfp-picture" style="background-image: url(' . esc_url( $speaker->imageUrl ) . ')"></div>';
			$content .= '        <div class="cfp-name">' . esc_html( $speaker->firstName . ' ' . $speaker->lastName ) . '</div>';
			if ( ! empty( $speaker->company ) ) {
				$content .= '        <div class="cfp-company">' . esc_html( $speaker->company ) . '</div>';
			}
			$content .= '    </a>';
			$content .= ' </div>';
		}

		$content .= '</div>';
		$content .= '</section>';
		$content .= '</main>';

		$content .= getFooter();

		return $content;
	}
} // End if().
