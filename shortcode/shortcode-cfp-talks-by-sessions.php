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
		cfp_dev_shortcode_assets();
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

		return cfp_dev_cached_markup(
			cfp_dev_group_cache_key( 'talks_by_sessions_cache_group_' . $session_id . cfp_dev_atts_cache_suffix( $_atts, $defaults ) ),
			static function () use ( $session_id, $_atts ) {
				return cfp_dev_render_talks_by_sessions( $session_id, $_atts );
			},
			cfp_dev_empty_list_page( __( 'No session types found', 'cfp-dev-shortcodes' ) )
		);
	}

	/**
	 * Returns the non-pause session-type ids attached to talks in the agenda.
	 *
	 * The session-type catalog also contains unused types and breaks. Looking at
	 * the same day-level schedule endpoints as the agenda keeps the filter to
	 * categories a visitor can actually attend, and works from offline snapshots.
	 * Null means the agenda could not be resolved, rather than being empty.
	 *
	 * @return int[]|null
	 */
	function cfp_dev_agenda_session_type_ids(): ?array {
		return cfp_dev_request_cache_get(
			'agenda_session_type_ids',
			static function () {
				$event = cfp_dev_get_json( 'public/event' );
				if ( ! is_object( $event ) ) {
					return null;
				}

				$timezone  = cfp_dev_timezone( $event->timezone ?? '' );
				$from_date = cfp_dev_date( $event->fromDate ?? '', $timezone );
				$to_date   = cfp_dev_event_end_date( $event, $from_date, $timezone );
				if ( null === $timezone || null === $from_date || null === $to_date ) {
					return null;
				}

				$ids      = [];
				$current  = $from_date->setTimezone( $timezone )->setTime( 0, 0 );
				$last_day = $to_date->setTimezone( $timezone )->setTime( 0, 0 );

				while ( $current <= $last_day ) {
					$items = cfp_dev_get_json( 'public/schedules/' . $current->format( 'l' ) );
					if ( ! is_array( $items ) ) {
						if ( cfp_dev_api_had_failure() ) {
							return null;
						}
						$current = $current->modify( '+1 day' );
						continue;
					}

					foreach ( $items as $item ) {
						if (
							! is_object( $item )
							|| ! empty( $item->overflow )
							|| ! empty( $item->sessionType->pause )
							|| empty( $item->proposal->id )
						) {
							continue;
						}

						$id = absint( $item->sessionType->id ?? $item->proposal->sessionType->id ?? 0 );
						if ( $id > 0 ) {
							$ids[ $id ] = $id;
						}
					}
					$current = $current->modify( '+1 day' );
				}

				return array_values( $ids );
			}
		);
	}

	/**
	 * Renders the talks-by-session-type page: filter navigation, session
	 * description, and one table row per talk.
	 *
	 * @param int   $session_id  Selected session-type id (0 → first agenda type).
	 * @param array $_atts      Normalised shortcode attributes (title, hide_title, hide_search).
	 * @return string|null  Null when the session-type list could not be fetched.
	 */
	function cfp_dev_render_talks_by_sessions( $session_id, $_atts = [] ) {
		$sessions = cfp_dev_get_json( 'public/session-types' );

		if ( empty( $sessions ) || ! is_array( $sessions ) ) {
			return null;
		}

		$agenda_ids = cfp_dev_agenda_session_type_ids();
		if ( null === $agenda_ids ) {
			return null;
		}

		$sessions = array_values(
			array_filter(
				$sessions,
				static function ( $session ) use ( $agenda_ids ) {
					return empty( $session->pause ) && in_array( absint( $session->id ?? 0 ), $agenda_ids, true );
				}
			)
		);
		if ( empty( $sessions ) ) {
			return null;
		}

		$available_ids = array_map(
			static function ( $session ) {
				return absint( $session->id ?? 0 );
			},
			$sessions
		);
		$session_id    = absint( $session_id );
		if ( ! in_array( $session_id, $available_ids, true ) ) {
			$session_id = 0;
		}

		// Without a usable selection, the first agenda type is shown. The
		// catalog has already been filtered, so no separate pause check is
		// needed here.
		$session_descr = '';
		foreach ( $sessions as $session ) {
			$is_candidate = empty( $session_id )
				? empty( $session->pause )
				: absint( $session->id ?? 0 ) === absint( $session_id );

			if ( $is_candidate ) {
				$session_id    = absint( $session->id ?? 0 );
				$session_descr = (string) ( $session->description ?? '' );
				break;
			}
		}

		$talks = $session_id ? cfp_dev_get_json( 'public/talks/session-type/' . absint( $session_id ) ) : null;
		$title = empty( $_atts['hide_title'] ) ? (string) ( $_atts['title'] ?? __( 'Talks grouped by Session Types', 'cfp-dev-shortcodes' ) ) : '';

		$content  = cfp_dev_root_class_script( 'session' );
		$content .= '<div class="cfp-main">';
		$content .= '<section class="cfp-list">';

		$content .= '<div class="cfp-subject">';
		$content .= cfp_dev_page_header( $title, '', empty( $_atts['hide_search'] ) );
		$content .= '    <nav class="cfp-filter" aria-label="' . esc_attr__( 'Session types', 'cfp-dev-shortcodes' ) . '">';
		foreach ( $sessions as $session ) {
			$is_active = ( absint( $session->id ?? 0 ) === absint( $session_id ) ) ? 'cfp-active' : '';

			$content .= '<a class="cfp-a ' . $is_active . '" href="' . esc_url( '?id=' . absint( $session->id ?? 0 ) ) . '">';
			$content .= esc_html( (string) ( $session->name ?? '' ) ) . '</a>';
		}
		$content .= '    </nav>';
		$content .= '</div>';

		$content .= '<div class="cfp-group">';
		if ( '' !== $session_descr ) {
			$content .= '    <div class="cfp-foreword">';
			$content .= '       <div class="cfp-text">' . wp_kses_post( $session_descr ) . '</div>';
			$content .= '    </div>';
		}

		$content .= cfp_dev_talk_table_heading();
		$content .= cfp_dev_talk_table_rows( $talks );

		$content .= '</div>';
		$content .= '</section>';
		$content .= '</div>';
		$content .= cfp_dev_footer();

		return $content;
	}
}
