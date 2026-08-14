<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_talks_by_sessions]  Talks grouped by session type with filter navigation.
 *
 * @package  CFP.DEV
 * @since    1.0.0
 */
if ( ! function_exists( 'cfp_dev_talks_by_sessions_shortcode' ) ) {

	add_action(
		'plugins_loaded',
		function () {

			if ( ! shortcode_exists( 'cfp_talks_by_sessions' ) ) {
				add_shortcode( 'cfp_talks_by_sessions', 'cfp_dev_talks_by_sessions_shortcode' );
			}
		}
	);

	/**
	 * Shortcode handler for [cfp_talks_by_sessions].
	 *
	 * @param array $atts  Shortcode attributes: title, hide_title, hide_search.
	 * @return string
	 * @since  1.0.0
	 */
	function cfp_dev_talks_by_sessions_shortcode( $atts = [] ) {
		$defaults = [
			'title'       => __( 'Talks grouped by Session Types', 'cfp-dev-shortcodes' ),
			'hide_title'  => false,
			'hide_search' => false,
		];
		$_atts    = shortcode_atts( $defaults, $atts );

		$_atts['title']       = trim( (string) $_atts['title'] );
		$_atts['hide_title']  = cfp_dev_attr_bool( $_atts['hide_title'] );
		$_atts['hide_search'] = cfp_dev_attr_bool( $_atts['hide_search'] );

		// absint: the id is user input and becomes part of API paths and cache keys.
		$session_id = absint( get_query_var( 'id' ) );

		$ttl = cfp_dev_get_cache_ttl();
		if ( 0 === $ttl ) {
			return cfp_dev_render_talks_by_sessions( $session_id, $_atts );
		}

		$_cache_group = cfp_dev_group_cache_key( 'talks_by_sessions_cache_group_' . $session_id . cfp_dev_atts_cache_suffix( $_atts, $defaults ) );
		$cache        = get_transient( $_cache_group );
		if ( false === $cache ) {
			$content = cfp_dev_render_talks_by_sessions( $session_id, $_atts );
			set_transient( $_cache_group, $content, $ttl );
		} else {
			$content = $cache;
		}
		return $content;
	}

	/**
	 * Renders the talks-by-session-type page: filter navigation, session
	 * description, and one table row per talk.
	 *
	 * @param int   $session_id  Selected session-type id (0 → first non-pause type).
	 * @param array $_atts      Normalised shortcode attributes (title, hide_title, hide_search).
	 * @return string
	 */
	function cfp_dev_render_talks_by_sessions( $session_id, $_atts = [] ) {
		$sessions = cfp_dev_get_json( 'public/session-types' );

		$session_descr = '';

		if ( ! empty( $sessions ) && is_array( $sessions ) ) {
			if ( empty( $session_id ) ) {
				foreach ( $sessions as $session ) {
					if ( ! $session->pause ) {
						$session_id    = $session->id;
						$session_descr = $session->description ?? '';
						break;
					}
				}
			} else {
				foreach ( $sessions as $session ) {
					if ( absint( $session->id ) === $session_id ) {
						$session_descr = $session->description ?? '';
						break;
					}
				}
			}
		}

		// Get talks by session type.
		$talks = ! empty( $session_id ) ? cfp_dev_get_json( 'public/talks/session-type/' . absint( $session_id ) ) : null;

		$content = cfp_dev_root_class_script( 'session' );

		$content .= '<div class="cfp-main">';

		$content .= '<section class="cfp-list">';

		if ( ! empty( $sessions ) ) {

			$title = empty( $_atts['hide_title'] ) ? (string) ( $_atts['title'] ?? __( 'Talks grouped by Session Types', 'cfp-dev-shortcodes' ) ) : '';

			$content .= '<div class="cfp-subject">';
			$content .= cfp_dev_page_header( $title, '', empty( $_atts['hide_search'] ) );
			$content .= '    <nav class="cfp-filter">';
			foreach ( $sessions as $session ) {

				if ( $session->pause ) {
					continue;
				}

				$is_active = ( absint( $session->id ) === absint( $session_id ) ) ? 'cfp-active' : '';

				$content .= '<a class="cfp-a ' . $is_active . '" href="' . esc_url( '?id=' . absint( $session->id ) ) . '">';
				$content .= esc_html( $session->name ) . '</a>';
			}
			$content .= '    </nav>';

		} else {
			$content .= '<div class="dev-cfp-row">';
			$content .= '    <div class="dev-cfp-column">';
			$content .= '        <p>' . esc_html__( 'No session types found', 'cfp-dev-shortcodes' ) . '</p>';
			$content .= '    </div>';
		}
		$content .= '</div>';

		$content .= '<div class="cfp-group">';
		$content .= '    <div class="cfp-foreword">';
		$content .= '       <div class="cfp-text">' . wp_kses_post( (string) $session_descr ) . '</div>';
		$content .= '    </div>';

		$content .= cfp_dev_talk_table_heading();
		$content .= cfp_dev_talk_table_rows( $talks );

		$content .= '</div>';

		$content .= '</section>';
		$content .= '</div>';

		$content .= cfp_dev_footer();

		return $content;
	}
}
