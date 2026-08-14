<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_schedule]  Time-grid schedule per day with room columns.
 *
 * @package  CFP.DEV
 * @since    1.0.0
 */

if ( ! function_exists( 'cfp_dev_schedule_shortcode' ) ) {

	add_action(
		'plugins_loaded',
		function () {

			if ( ! shortcode_exists( 'cfp_schedule' ) ) {
				add_shortcode( 'cfp_schedule', 'cfp_dev_schedule_shortcode' );
			}
		}
	);

	/**
	 * Shortcode handler for [cfp_schedule].
	 *
	 * @param array $atts  Shortcode attributes: title, hide_title, hide_search.
	 * @return string
	 * @since  1.0.0
	 */
	function cfp_dev_schedule_shortcode( $atts = [] ) {
		$defaults = [
			'title'       => '', // Empty → the event name from the API.
			'hide_title'  => false,
			'hide_search' => false,
		];
		$_atts    = shortcode_atts( $defaults, $atts );

		$_atts['title']       = trim( (string) $_atts['title'] );
		$_atts['hide_title']  = cfp_dev_attr_bool( $_atts['hide_title'] );
		$_atts['hide_search'] = cfp_dev_attr_bool( $_atts['hide_search'] );

		// Whitelist the day name — it is user input and becomes part of API paths
		// and cache keys (arbitrary values would create unbounded transients).
		$valid_days = [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ];
		$day_name   = ucfirst( strtolower( (string) get_query_var( 'id' ) ) );
		if ( ! in_array( $day_name, $valid_days, true ) ) {
			$day_name = '';
		}

		$ttl = cfp_dev_get_cache_ttl();

		/*
		 * The cache stores rendered HTML, so a hit needs no API data at all —
		 * only the key. An explicit ?id=<day> settles the key on its own, which
		 * is the case every day tab links to. This lookup used to sit below the
		 * event, rooms and day-schedule fetches, so serving a cached day still
		 * cost three round trips to an API the answer did not depend on.
		 */
		if ( $ttl > 0 && '' !== $day_name ) {
			$cache = get_transient( cfp_dev_schedule_cache_key( $day_name, $_atts, $defaults ) );
			if ( false !== $cache ) {
				return $cache;
			}
		}

		// Get the current event — its timezone and date range drive everything below.
		$current_event = cfp_dev_get_json( 'public/event' );

		if ( ! is_object( $current_event ) ) {
			cfp_dev_log( 'schedule: failed to retrieve current event' );
			return esc_html__( 'Failed to retrieve current event', 'cfp-dev-shortcodes' );
		}

		cfp_dev_log( 'schedule: event=' . ( $current_event->id ?? '?' ) );

		$time_zone = cfp_dev_timezone( $current_event->timezone ?? '' );
		if ( null === $time_zone ) {
			cfp_dev_log( 'schedule: event has no usable timezone' );
			return esc_html__( 'Event timezone is not set.', 'cfp-dev-shortcodes' );
		}

		// The event's own dates decide which days exist; an unparseable one
		// would otherwise throw out of the shortcode and blank the whole page.
		$from_date = cfp_dev_date( $current_event->fromDate ?? '', $time_zone );
		$to_date   = cfp_dev_date( $current_event->toDate ?? '', $time_zone );
		if ( null === $from_date || null === $to_date ) {
			cfp_dev_log( 'schedule: event has no usable date range' );
			return esc_html__( 'Event dates are not set.', 'cfp-dev-shortcodes' );
		}

		// Without an explicit day the event itself names the default, so the
		// key is only knowable here — one fetch rather than three.
		if ( '' === $day_name ) {
			$day_name = $from_date->format( 'l' );
			if ( $ttl > 0 ) {
				$cache = get_transient( cfp_dev_schedule_cache_key( $day_name, $_atts, $defaults ) );
				if ( false !== $cache ) {
					return $cache;
				}
			}
		}

		$rooms = cfp_dev_get_json( 'public/rooms' );

		if ( is_null( $rooms ) ) {
			cfp_dev_log( 'schedule: failed to retrieve rooms' );
			return esc_html__( 'Failed to retrieve rooms', 'cfp-dev-shortcodes' );
		}

		$day_schedule = cfp_dev_get_json( 'public/schedules/' . $day_name );

		if ( empty( $day_schedule ) || ! is_array( $day_schedule ) ) {
			cfp_dev_log( 'schedule: failed to retrieve schedule for day=' . $day_name );
			/* translators: %s: day name. */
			return esc_html( sprintf( __( 'No schedule available for %s.', 'cfp-dev-shortcodes' ), $day_name ) );
		}

		$content = cfp_dev_render_schedule( $day_schedule, $rooms, $time_zone, $from_date, $to_date, $current_event, $day_name, $_atts );

		if ( $ttl > 0 ) {
			set_transient( cfp_dev_schedule_cache_key( $day_name, $_atts, $defaults ), $content, $ttl );
		}

		return $content;
	}

	/**
	 * Cache key for one rendered schedule day.
	 *
	 * @param string $day_name  Weekday name, e.g. 'Tuesday'.
	 * @param array  $_atts     Normalised shortcode attributes.
	 * @param array  $defaults  The shortcode's default attributes.
	 * @return string
	 */
	function cfp_dev_schedule_cache_key( string $day_name, array $_atts, array $defaults ): string {
		return cfp_dev_group_cache_key( 'cfp_schedule_' . $day_name . cfp_dev_atts_cache_suffix( $_atts, $defaults ) );
	}

	/**
	 * Renders the full schedule grid for one day: day-tab navigation, time
	 * column, and one column of session articles per room.
	 *
	 * @param array              $day_schedule  Time slots for the day.
	 * @param array              $rooms         All rooms from the API.
	 * @param DateTimeZone       $time_zone      Event timezone.
	 * @param DateTimeImmutable  $from_date      Event start date.
	 * @param DateTimeImmutable  $to_date        Event end date.
	 * @param object             $current_event  Event object from the API.
	 * @param string             $day_name       Selected day, e.g. 'Tuesday'.
	 * @param array              $_atts         Normalised shortcode attributes (title, hide_title, hide_search).
	 * @return string
	 */
	function cfp_dev_render_schedule( $day_schedule, $rooms, $time_zone, $from_date, $to_date, $current_event, $day_name, $_atts = [] ) {
		// Tabs are per calendar day, so both ends are normalised to midnight in
		// the event timezone: comparing the raw timestamps dropped the closing
		// day whenever the event ended earlier in the day than it started.
		$day_cursor = $from_date->setTimezone( $time_zone )->setTime( 0, 0 );
		$last_day   = $to_date->setTimezone( $time_zone )->setTime( 0, 0 );

		$content = cfp_dev_root_class_script( 'schedule' );

		$content .= '<div class="cfp-main">';

		if ( ! empty( $rooms ) ) {

			$content .= '<section id="cfp-schedule" class="cfp-schedule cfp-general">';
			$content .= '    <div class="cfp-subject">';

			$title    = empty( $_atts['hide_title'] )
				? ( ! empty( $_atts['title'] ) ? $_atts['title'] : $current_event->name )
				: '';
			$content .= cfp_dev_page_header( (string) $title, '', empty( $_atts['hide_search'] ) );

			// Day-tab navigation bar.
			$content .= '	<div class="cfp-secondary">';
			$content .= '		<nav class="cfp-tab">';
			while ( $day_cursor <= $last_day ) {
				$is_active  = ( $day_cursor->format( 'l' ) === $day_name ) ? 'cfp-active' : '';
				$content   .= '		<a class="cfp-a ' . $is_active . '" href="' . esc_url( '?id=' . $day_cursor->format( 'l' ) ) . '">' .
					esc_html( $day_cursor->format( 'l' ) . ' ' . $day_cursor->format( 'j' ) ) . '<sup>' .
					esc_html( $day_cursor->format( 'S' ) ) . '</sup> ' . esc_html( $day_cursor->format( 'M' ) ) . '</a>';
				$day_cursor = $day_cursor->modify( '+1 day' );
			}
			$content .= '		</nav>';
			$content .= '		<a class="cfp-button" style="color:white" href="' . esc_url( 'https://mobile.devoxx.com/events/' . cfp_dev_get_key() . '/schedule' ) . '">' . esc_html__( 'Mobile Schedule', 'cfp-dev-shortcodes' ) . '</a>';
			$content .= '	</div>';

			$content .= '</div>';

			// Grid start/end hours from the first and last time slot of the day.
			// The ruler labels the event's own clock, so it is built from
			// midnight of the day being viewed *in the event timezone* — not
			// from "today" in the site timezone, which shifted every label by
			// the site's UTC offset and dated them to the wrong day.
			$count      = count( $day_schedule );
			$first_slot = cfp_dev_date( $day_schedule[0]->fromDate ?? '' );
			$last_slot  = cfp_dev_date( $day_schedule[ $count - 1 ]->toDate ?? '' );

			if ( null !== $first_slot && null !== $last_slot ) {
				$day_start   = $first_slot->setTimezone( $time_zone )->setTime( 0, 0 );
				$hour_start  = (int) $first_slot->setTimezone( $time_zone )->format( 'H' );
				$hour_finish = (int) $last_slot->setTimezone( $time_zone )->format( 'H' );

				// The three grid wrappers are opened and closed as a pair so they
				// cannot drift apart again (they used to be left unclosed).
				$content .= '<div class="cfp-area" style="--hour-start:' . esc_attr( (string) $hour_start ) . '; --hour-finish:' . esc_attr( (string) $hour_finish ) . ';">'
					. '<div class="cfp-scroll">'
					. '<div class="cfp-scope">';

				// Time labels in the left column (10-minute steps).
				$content .= '		<div class="cfp-column cfp-datetime">';

				for ( $minutes = $hour_start * 60; $minutes <= $hour_finish * 60; $minutes += 10 ) {
					$label    = $day_start->setTime( intdiv( $minutes, 60 ), $minutes % 60 );
					$content .= '<time class="cfp-time" datetime="' . esc_attr( $label->format( 'c' ) ) . '">' . esc_html( $label->format( 'H:i' ) ) . '</time>';
				}

				$content .= '		</div>';

				$content .= cfp_dev_render_schedule_rooms( $rooms, $day_name, $time_zone );
				$content .= '</div></div></div>';
			}

			$content .= '</section>';
		}

		$content .= '</div>';
		$content .= cfp_dev_footer();
		return $content;
	}

	/**
	 * Renders one grid column per room for the given day.
	 *
	 * @param array        $rooms      All rooms from the API.
	 * @param string       $day_name   Selected day, e.g. 'Tuesday'.
	 * @param DateTimeZone $time_zone  Event timezone.
	 * @return string
	 */
	function cfp_dev_render_schedule_rooms( $rooms, $day_name, $time_zone ) {
		$content = '';

		// One column per room — the grid layout expects sessions sequentially per room.
		foreach ( $rooms as $room ) {

			$schedule_items = cfp_dev_get_json( 'public/schedules/' . $day_name . '/' . absint( $room->id ?? 0 ) );

			if ( empty( $schedule_items ) || ! is_array( $schedule_items ) ) {
				continue;
			}

			$content .= '<div class="cfp-column cfp-event">';
			foreach ( $schedule_items as $item ) {
				if ( is_object( $item ) ) {
					$content .= cfp_dev_render_schedule_item( $item, $time_zone );
				}
			}
			$content .= '</div>';
		}

		return $content;
	}

	/**
	 * Renders one session article in a room column.
	 *
	 * @param object       $item       Schedule item from the API.
	 * @param DateTimeZone $time_zone  Event timezone.
	 * @return string
	 */
	function cfp_dev_render_schedule_item( $item, $time_zone ) {
		$start_session = cfp_dev_date( $item->fromDate ?? '', $time_zone );
		$end_session   = cfp_dev_date( $item->toDate ?? '', $time_zone );

		if ( null === $start_session || null === $end_session ) {
			cfp_dev_log( 'schedule: skipping item with unusable dates' );
			return '';
		}

		$event_start  = $start_session->format( 'H:i' );
		$event_finish = $end_session->format( 'H:i' );

		$overflow     = ! empty( $item->overflow );
		$has_proposal = ! empty( $item->proposal->title ) && ! $overflow;
		$session_type = $has_proposal ? 'cfp-session' : 'cfp-recess';
		$duration     = absint( $item->sessionType->duration ?? 0 );

		$content = '<article class="cfp-article ' . $session_type . '" data-event-start="' . esc_attr( $event_start ) . '" data-event-finish="' . esc_attr( $event_finish ) . '" data-event-duration="' . esc_attr( (string) $duration ) . '">';

		if ( $has_proposal ) {
			$content .= '        <a class="cfp-a" href="' . esc_url( cfp_dev_talk_url( $item->proposal ) ) . '">';
		}

		$content .= '            <div class="cfp-content">';
		$content .= '                <div class="cfp-meta">';

		if ( $has_proposal ) {
			if ( ! empty( $item->proposal->totalFavourites ) ) {
				$content .= '        <div id="dev-cfp-talk-' . absint( $item->proposal->id ?? 0 ) . '" class="cfp-favourite">' . absint( $item->proposal->totalFavourites ) . '</div>';
			}
			if ( ! empty( $item->proposal->track->imageURL ) ) {
				$content .= '        <div class="cfp-track" style="background-image: url(\'' . esc_url( $item->proposal->track->imageURL ) . '\');filter: grayscale(100%);"></div>';
			}
		}

		$content .= '                </div>';
		if ( $has_proposal && 'yes' === get_option( 'cfp_dev_show_rooms', 'yes' ) && ! empty( $item->room->name ) ) {
			$content .= '                <div class="cfp-room">' . esc_html( $item->room->name ) . '</div>';
		}
		if ( ! empty( $item->sessionType->name ) ) {
			$content .= '                <div class="cfp-name">' . esc_html( $item->sessionType->name ) . '</div>';
		}
		$content .= '                <div class="cfp-datetime">';
		$content .= '                    <time class="cfp-time" datetime="' . esc_attr( $start_session->format( 'c' ) ) . '">' . esc_html( $event_start ) . '</time>';
		$content .= '                    <time class="cfp-time" datetime="' . esc_attr( $end_session->format( 'c' ) ) . '">' . esc_html( $event_finish ) . '</time>';
		$content .= '                </div>';
		if ( $has_proposal ) {
			$content .= '                <div class="cfp-name">' . esc_html( (string) $item->proposal->title ) . '</div>';
		}
		if ( $overflow ) {
			$content .= '                <div class="cfp-name">' . esc_html__( 'OVERFLOW', 'cfp-dev-shortcodes' ) . '</div>';
		}

		foreach ( (array) ( $item->proposal->speakers ?? [] ) as $speaker ) {
			$content .= '<div class="cfp-speaker">' . esc_html( trim( ( $speaker->firstName ?? '' ) . ' ' . ( $speaker->lastName ?? '' ) ) ) . '</div>';
		}

		$content .= '            </div>';
		if ( $has_proposal ) {
			$content .= '        </a>';
		}
		$content .= '</article>';

		return $content;
	}
}
