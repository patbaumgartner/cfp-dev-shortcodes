<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_schedule]  Time-grid schedule per day with room columns.
 *
 * @package  CFP.DEV
 * @since    1.0.0
 */

if ( ! function_exists( 'cfp_schedule_shortcode' ) ) {

	add_action(
		'plugins_loaded',
		function () {

			if ( ! shortcode_exists( 'cfp_schedule' ) ) {
				add_shortcode( 'cfp_schedule', 'cfp_schedule_shortcode' );
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
	function cfp_schedule_shortcode( $atts = [] ) {
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
		$dayName    = ucfirst( strtolower( (string) get_query_var( 'id' ) ) );
		if ( ! in_array( $dayName, $valid_days, true ) ) {
			$dayName = '';
		}

		// Get the current event — its timezone and date range drive everything below.
		$currentEvent = getJSON( 'public/event' );

		if ( is_null( $currentEvent ) ) {
			cfp_dev_log( 'schedule: failed to retrieve current event' );
			return 'Failed to retrieve current event';
		}

		cfp_dev_log( 'schedule: event=' . $currentEvent->id );

		if ( is_object( $currentEvent ) && ! empty( $currentEvent->timezone ) ) {
			try {
				$timeZone = new DateTimeZone( $currentEvent->timezone );
				cfp_dev_log( 'schedule: timezone=' . $timeZone->getName() );
			} catch ( Exception $e ) {
				cfp_dev_log( 'schedule: error creating DateTimeZone — ' . $e->getMessage() );
				return 'Invalid event timezone.';
			}
		} else {
			cfp_dev_log( 'schedule: timezone not set on event' );
			return 'Event timezone is not set.';
		}

		$rooms = getJSON( 'public/rooms' );

		if ( is_null( $rooms ) ) {
			cfp_dev_log( 'schedule: failed to retrieve rooms' );
			return 'Failed to retrieve rooms';
		}

		$timeZone = new DateTimeZone( $currentEvent->timezone );
		$fromDate = new DateTime( $currentEvent->fromDate );
		$fromDate->setTimezone( $timeZone );

		if ( '' === $dayName ) {
			$dayName = $fromDate->format( 'l' );
		}

		$day_schedule = getJSON( 'public/schedules/' . $dayName );

		if ( empty( $day_schedule ) || ! is_array( $day_schedule ) ) {
			cfp_dev_log( 'schedule: failed to retrieve schedule for day=' . $dayName );
			return 'No schedule available for ' . esc_html( $dayName ) . '.';
		}

		$_cache_group = cfp_dev_group_cache_key( 'cfp_schedule_' . $dayName . cfp_dev_atts_cache_suffix( $_atts, $defaults ) );

		$ttl = cfp_dev_get_cache_ttl();
		if ( 0 === $ttl ) {
			cfp_dev_log( 'schedule: cache disabled, generating content for day=' . $dayName );
			$content = generate_schedule_content( $day_schedule, $rooms, $timeZone, $fromDate, $currentEvent, $dayName, $_atts );
		} else {
			$cache = get_transient( $_cache_group );
			if ( false === $cache ) {
					$content = generate_schedule_content( $day_schedule, $rooms, $timeZone, $fromDate, $currentEvent, $dayName, $_atts );
					set_transient( $_cache_group, $content, $ttl );
			} else {
				$content = $cache;
			}
		}
		return $content;
	}

	/**
	 * Renders the full schedule grid for one day: day-tab navigation, time
	 * column, and one column of session articles per room.
	 *
	 * @param array        $day_schedule  Time slots for the day.
	 * @param array        $rooms         All rooms from the API.
	 * @param DateTimeZone $timeZone      Event timezone.
	 * @param DateTime     $fromDate      Event start date (mutated while building the day tabs).
	 * @param object       $currentEvent  Event object from the API.
	 * @param string       $dayName       Selected day, e.g. 'Tuesday'.
	 * @param array        $_atts         Normalised shortcode attributes (title, hide_title, hide_search).
	 * @return string
	 */
	function generate_schedule_content( $day_schedule, $rooms, $timeZone, $fromDate, $currentEvent, $dayName, $_atts = [] ) {
		// Tabs are per calendar day, so both ends are normalised to midnight in
		// the event timezone: comparing the raw timestamps dropped the closing
		// day whenever the event ended earlier in the day than it started.
		$day_cursor = DateTimeImmutable::createFromInterface( $fromDate )->setTimezone( $timeZone )->setTime( 0, 0 );
		$last_day   = ( new DateTimeImmutable( $currentEvent->toDate ) )->setTimezone( $timeZone )->setTime( 0, 0 );

		$content = cfp_dev_root_class_script( 'schedule' );

		$content .= '<div class="cfp-main">';

		if ( ! empty( $rooms ) ) {

			$content .= '<section id="cfp-schedule" class="cfp-schedule cfp-general">';
			$content .= '    <div class="cfp-subject">';

			$title    = empty( $_atts['hide_title'] )
				? ( ! empty( $_atts['title'] ) ? $_atts['title'] : $currentEvent->name )
				: '';
			$content .= cfp_dev_page_header( (string) $title, '', empty( $_atts['hide_search'] ) );

			// Day-tab navigation bar.
			$content .= '	<div class="cfp-secondary">';
			$content .= '		<nav class="cfp-tab">';
			while ( $day_cursor <= $last_day ) {
				$isActive   = ( $day_cursor->format( 'l' ) === $dayName ) ? 'cfp-active' : '';
				$content   .= '		<a class="cfp-a ' . $isActive . '" href="' . esc_url( '?id=' . $day_cursor->format( 'l' ) ) . '">' .
					esc_html( $day_cursor->format( 'l' ) . ' ' . $day_cursor->format( 'j' ) ) . '<sup>' .
					esc_html( $day_cursor->format( 'S' ) ) . '</sup> ' . esc_html( $day_cursor->format( 'M' ) ) . '</a>';
				$day_cursor = $day_cursor->modify( '+1 day' );
			}
			$content .= '		</nav>';
			$content .= '		<a class="cfp-button" style="color:white" href="' . esc_url( 'https://mobile.devoxx.com/events/' . cfp_dev_get_key() . '/schedule' ) . '">Mobile Schedule</a>';
			$content .= '	</div>';

			$content .= '</div>';

			// Grid start/end hours from the first and last time slot of the day.
			$count = count( $day_schedule );

			$hour_start  = getTime( $day_schedule[0]->fromDate, $timeZone, 'H' );
			$hour_finish = getTime( $day_schedule[ $count - 1 ]->toDate, $timeZone, 'H' );

			// The three grid wrappers are opened and closed as a pair so they
			// cannot drift apart again (they used to be left unclosed).
			$grid_open  = '<div class="cfp-area" style="--hour-start:' . $hour_start . '; --hour-finish:' . $hour_finish . ';">'
				. '<div class="cfp-scroll">'
				. '<div class="cfp-scope">';
			$grid_close = '</div></div></div>';

			$content .= $grid_open;

			// The ruler labels the event's own clock, so they are built from
			// midnight of the day being viewed *in the event timezone* — not
			// from "today" in the site timezone, which shifted every label by
			// the site's UTC offset and dated them to the wrong day.
			$day_start = ( new DateTimeImmutable( $day_schedule[0]->fromDate ) )
				->setTimezone( $timeZone )
				->setTime( 0, 0 );

			// Time labels in the left column (10-minute steps).
			$content .= '		<div class="cfp-column cfp-datetime">';

			for ( $minutes = (int) $hour_start * 60; $minutes <= (int) $hour_finish * 60; $minutes += 10 ) {
				$label    = $day_start->setTime( intdiv( $minutes, 60 ), $minutes % 60 );
				$content .= '<time class="cfp-time" datetime="' . esc_attr( $label->format( 'c' ) ) . '">' . esc_html( $label->format( 'H:i' ) ) . '</time>';
			}

			$content .= '		</div>';

			// One column per room — the grid layout expects sessions sequentially per room.
			foreach ( $rooms as $room ) {

				$schedule_items = getJSON( 'public/schedules/' . $dayName . '/' . $room->id );

				if ( ! empty( $schedule_items ) ) {
					$content .= '<div class="cfp-column cfp-event">';

					foreach ( $schedule_items as $item ) {

						if ( ! empty( $item ) ) {

							try {
								$startSession = new DateTime( $item->fromDate );
								$startSession->setTimezone( $timeZone );

								$endSession = new DateTime( $item->toDate );
								$endSession->setTimezone( $timeZone );
							} catch ( Exception $e ) {
								cfp_dev_log( 'schedule: skipping item with invalid dates — ' . $e->getMessage() );
								continue;
							}

							$event_start  = $startSession->format( 'H:i' );
							$event_finish = $endSession->format( 'H:i' );

							$hasProposal = false;
							$overflow    = ! empty( $item->overflow );

							if ( ! empty( $item->proposal->title ) && ! $overflow ) {
								$hasProposal = true;
								$sessionType = 'cfp-session';
							} else {
								$sessionType = 'cfp-recess';
							}

							$duration = isset( $item->sessionType->duration ) ? absint( $item->sessionType->duration ) : 0;
							$content .= '<article class="cfp-article ' . $sessionType . '" data-event-start="' . esc_attr( $event_start ) . '" data-event-finish="' . esc_attr( $event_finish ) . '" data-event-duration="' . esc_attr( $duration ) . '">';

							if ( $hasProposal ) {
								if ( 'no' === get_option( 'cfp_dev_content_by_id', 'yes' ) ) {
									$talk_slug = generate_slug( $item->proposal->title );
									$content  .= '        <a class="cfp-a" href="' . esc_url( cfp_dev_url( '/talk/' . $talk_slug ) ) . '">';
								} else {
									$content .= '        <a class="cfp-a" href="' . esc_url( cfp_dev_url( '/talk?id=' . absint( $item->proposal->id ) ) ) . '">';
								}
							}

							$content .= '            <div class="cfp-content">';
							$content .= '                <div class="cfp-meta">';

							if ( $hasProposal ) {
								if ( ! empty( $item->proposal->totalFavourites ) && $item->proposal->totalFavourites > 0 ) {
									$content .= '        <div id="dev-cfp-talk-' . absint( $item->proposal->id ) . '" class="cfp-favourite">' . absint( $item->proposal->totalFavourites ) . '</div>';
								}
								if ( ! empty( $item->proposal->track->imageURL ) ) {
									$content .= '        <div class="cfp-track" style="background-image: url(\'' . esc_url( $item->proposal->track->imageURL ) . '\');filter: grayscale(100%);"></div>';
								}
							}

							$content .= '                </div>';
							if ( $hasProposal && 'yes' === get_option( 'cfp_dev_show_rooms', 'yes' ) && ! empty( $item->room->name ) ) {
								$content .= '                <div class="cfp-room">' . esc_html( $item->room->name ) . '</div>';
							}
							if ( ! empty( $item->sessionType->name ) ) {
								$content .= '                <div class="cfp-name">' . esc_html( $item->sessionType->name ) . '</div>';
							}
							$content .= '                <div class="cfp-datetime">';

							$content .= '                    <time class="cfp-time" datetime="' . esc_attr( $startSession->format( 'c' ) ) . '">' . esc_html( $event_start ) . '</time>';
							$content .= '                    <time class="cfp-time" datetime="' . esc_attr( $endSession->format( 'c' ) ) . '">' . esc_html( $event_finish ) . '</time>';
							$content .= '                </div>';
							if ( $hasProposal ) {
								$content .= '                <div class="cfp-name">' . esc_html( $item->proposal->title ) . '</div>';
							}
							if ( $overflow ) {
								$content .= '                <div class="cfp-name">OVERFLOW</div>';
							}

							if ( ! empty( $item->proposal->speakers ) && ( is_array( $item->proposal->speakers ) || is_object( $item->proposal->speakers ) ) ) {
								foreach ( $item->proposal->speakers as $speaker ) {
									$content .= '<div class="cfp-speaker">' . esc_html( trim( ( $speaker->firstName ?? '' ) . ' ' . ( $speaker->lastName ?? '' ) ) ) . '</div>';
								}
							}

							$content .= '            </div>';
							if ( $hasProposal ) {
								$content .= '        </a>';
							}
							$content .= '</article>';
						}
					}

					$content .= '</div>';
				}
			}

			$content .= $grid_close;
			$content .= '</section>';
		}

		$content .= '</div>';
		$content .= getFooter();
		return $content;
	}
}
