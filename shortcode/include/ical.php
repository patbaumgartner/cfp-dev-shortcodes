<?php
/**
 * CFP.DEV shortcodes
 *
 * The event programme as an iCalendar (RFC 5545) download: every session
 * across the event's days in one .ics file a desk or pocket calendar can
 * import, and a single-session .ics per talk.
 *
 * The exports are hidden URLs — /schedule/ical/ for the whole programme and
 * /talk/<slug>/ical/ (or /talk/ical/?id=<talk>) for one session — that answer
 * with an attachment instead of a page. They share the schedule's data path
 * and so its offline mode: the file is built from the day-level schedule
 * endpoints (the talk export from the talk detail endpoint), which a snapshot
 * serves without touching the network. Both calendars are cached as one
 * versioned transient each, invalidated by the same version bump every other
 * cache uses.
 *
 * @package CFP.DEV
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Serves a calendar download when the request carries the plugin's `cfp_ical`
 * query var: one session when it asks for a talk, the whole programme under
 * the schedule page.
 *
 * Any other request — including a download the data cannot describe — renders
 * the page as usual, so a failed export fails softly into HTML instead of
 * serving an empty calendar.
 */
function cfp_dev_maybe_serve_ical() {
	if ( '1' !== get_query_var( 'cfp_ical' ) ) {
		return;
	}

	$talk_slug = sanitize_title( (string) get_query_var( 'talk_slug' ) );
	$talk_id   = absint( get_query_var( 'id' ) );
	$is_talk   = 'talk' === get_query_var( 'pagename' );

	if ( '' !== $talk_slug && $is_talk ) {
		$talk_id = (int) cfp_dev_talk_id_from_slug( $talk_slug );
	}

	if ( $talk_id > 0 ) {
		$ics      = cfp_dev_talk_ics( $talk_id );
		$filename = '' !== $talk_slug ? $talk_slug . '.ics' : 'talk-' . $talk_id . '.ics';
	} else {
		if ( $is_talk ) {
			// A talk URL with no resolvable talk — the detail page will say so.
			cfp_dev_log( 'ical: talk request without a resolvable talk — rendering the detail page instead' );
			return;
		}

		$ics      = cfp_dev_schedule_ics();
		$filename = cfp_dev_ical_filename();
	}

	if ( '' === $ics ) {
		cfp_dev_log( 'ical: nothing to serve — rendering the html page instead' );
		return;
	}

	nocache_headers();
	status_header( 200 );

	header( 'Content-Type: text/calendar; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

	// The file is plain text, not HTML — escaping it here would litter the
	// body an importer parses.
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- .ics is text, not HTML.
	echo $ics;
	exit;
}

/*
 * Priority 8, before redirect_canonical (10): when the schedule page is the
 * site's front page, WordPress otherwise canonicalises /schedule/ical/ to /
 * — a 301 the browser follows instead of starting the download.
 */
add_action( 'template_redirect', 'cfp_dev_maybe_serve_ical', 8 );

/**
 * The full .ics body for the event, served from the transient cache when one
 * is set.
 *
 * @return string  The calendar file contents, or '' when the programme cannot
 *                 be assembled (missing event, timezone or date range).
 */
function cfp_dev_schedule_ics(): string {
	$ttl = cfp_dev_get_cache_ttl();
	$key = cfp_dev_group_cache_key( 'cfp_schedule_ical' );

	if ( $ttl > 0 ) {
		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached;
		}
	}

	$current_event = cfp_dev_get_json( 'public/event' );

	if ( ! is_object( $current_event ) ) {
		cfp_dev_log( 'ical: failed to retrieve current event' );
		return '';
	}

	$time_zone = cfp_dev_timezone( (string) ( $current_event->timezone ?? '' ) );

	if ( null === $time_zone ) {
		cfp_dev_log( 'ical: event has no usable timezone' );
		return '';
	}

	$from_date = cfp_dev_date( (string) ( $current_event->fromDate ?? '' ), $time_zone );
	$to_date   = cfp_dev_event_end_date( $current_event, $from_date, $time_zone );

	if ( null === $from_date || null === $to_date ) {
		cfp_dev_log( 'ical: event has no usable date range' );
		return '';
	}

	/*
	 * One day-level fetch per event day. The offline crawler stores exactly
	 * these endpoints, so a snapshot answers the download without the network.
	 */
	$day_schedules = [];
	$day_cursor    = $from_date->setTimezone( $time_zone )->setTime( 0, 0 );
	$last_day      = $to_date->setTimezone( $time_zone )->setTime( 0, 0 );

	while ( $day_cursor <= $last_day ) {
		$day_name                   = $day_cursor->format( 'l' );
		$day_schedules[ $day_name ] = cfp_dev_get_json( 'public/schedules/' . $day_name );
		$day_cursor                 = $day_cursor->modify( '+1 day' );
	}

	$ical = cfp_dev_build_schedule_ics( $current_event, $day_schedules, $time_zone );

	if ( '' !== $ical && $ttl > 0 ) {
		set_transient( $key, $ical, $ttl );
	}

	return $ical;
}

/**
 * One talk's .ics, served from the transient cache when one is set.
 *
 * @param int $talk_id  Talk id.
 * @return string  The calendar file contents, or '' when the talk or its
 *                 programme data cannot be fetched.
 */
function cfp_dev_talk_ics( int $talk_id ): string {
	$ttl = cfp_dev_get_cache_ttl();
	$key = cfp_dev_group_cache_key( 'cfp_talk_ical_' . absint( $talk_id ) );

	if ( $ttl > 0 ) {
		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached;
		}
	}

	$current_event = cfp_dev_get_json( 'public/event' );

	if ( ! is_object( $current_event ) ) {
		cfp_dev_log( 'ical: failed to retrieve current event for talk ' . $talk_id );
		return '';
	}

	$time_zone = cfp_dev_timezone( (string) ( $current_event->timezone ?? '' ) );

	if ( null === $time_zone ) {
		cfp_dev_log( 'ical: event has no usable timezone for talk ' . $talk_id );
		return '';
	}

	$talk = cfp_dev_get_talk_by_id( $talk_id );

	if ( ! is_object( $talk ) ) {
		cfp_dev_log( 'ical: failed to retrieve talk ' . $talk_id );
		return '';
	}

	$ics = cfp_dev_build_talk_ics( $talk, $current_event, $time_zone );

	if ( '' !== $ics && $ttl > 0 ) {
		set_transient( $key, $ics, $ttl );
	}

	return $ics;
}

/**
 * The opening blocks of a VCALENDAR, shared by the whole-event and the
 * single-session exports.
 *
 * @param string      $event_name     Event display name, for X-WR-CALNAME.
 * @param object|null $current_event  Event object, for a stable calendar identity
 *                                    (X-WR-RELCALID) and named timezone.
 * @return string
 */
function cfp_dev_ical_begin( string $event_name, $current_event = null ): string {
	$content  = 'BEGIN:VCALENDAR' . "\r\n";
	$content .= 'VERSION:2.0' . "\r\n";
	$content .= 'PRODID:-//CFP.DEV//CFP.DEV shortcodes//EN' . "\r\n";
	$content .= 'CALSCALE:GREGORIAN' . "\r\n";
	$content .= 'METHOD:PUBLISH' . "\r\n";

	if ( '' !== $event_name ) {
		$content .= cfp_dev_ical_line( 'X-WR-CALNAME', $event_name );
	}

	// One opaque identity per event keeps a URL subscription from churning
	// into a fresh calendar every time the file re-serves with new content.
	if ( is_object( $current_event ) && (int) ( $current_event->id ?? 0 ) > 0 ) {
		$content .= cfp_dev_ical_line( 'X-WR-RELCALID', cfp_dev_ical_relcalid( (int) $current_event->id ) );
	}

	$time_zone = (string) ( $current_event->timezone ?? '' );

	if ( '' !== $time_zone ) {
		$content .= cfp_dev_ical_line( 'X-WR-TIMEZONE', $time_zone );
	}

	return $content;
}

/**
 * A deterministic UUID for one event: the same event always yields the same
 * identifier, so a subscribed calendar keeps its identity across exports.
 *
 * @param int $event_id  Event id.
 * @return string
 */
function cfp_dev_ical_relcalid( int $event_id ): string {
	$hex     = substr( hash( 'sha256', 'cfp-dev-' . $event_id ), 0, 32 );
	$variant = dechex( 8 + ( hexdec( $hex[15] ?? '0' ) % 4 ) );

	return sprintf(
		'%s-%s-4%s-%s%s-%s',
		substr( $hex, 0, 8 ),
		substr( $hex, 8, 4 ),
		substr( $hex, 12, 3 ),
		$variant,
		substr( $hex, 16, 3 ),
		substr( $hex, 19, 12 )
	);
}

/**
 * A multi-line LOCATION for a session: the room, then the venue and city the
 * event object describes, each line skipped when empty. Calendar apps render
 * the folded lines as one address block.
 *
 * @param object $current_event  Event object from the API.
 * @param string $room           The session's room name.
 * @return string
 */
function cfp_dev_ical_location( $current_event, string $room ): string {
	$lines = [];

	if ( '' !== trim( $room ) ) {
		$lines[] = trim( $room );
	}

	if ( ! is_object( $current_event ) ) {
		return implode( "\n", $lines );
	}

	$venue   = trim( (string) ( $current_event->locationName ?? '' ) );
	$city    = trim( (string) ( $current_event->locationCity ?? '' ) );
	$country = trim( (string) ( $current_event->locationCountry ?? '' ) );

	if ( '' !== $venue ) {
		$lines[] = $venue;
	}

	if ( '' !== $city && '' !== $country ) {
		$lines[] = $city . ', ' . $country;
	} elseif ( '' !== $city ) {
		$lines[] = $city;
	} elseif ( '' !== $country ) {
		$lines[] = $country;
	}

	return implode( "\n", $lines );
}

/**
 * Assembles the calendar file from the event and its day schedules.
 *
 * Pure towards the API data so unit tests can call it directly. A session is
 * a schedule item tied to a proposal; breaks and overflow slots render as
 * recesses in the grid and are left out of the calendar for the same reason.
 *
 * @param object       $current_event  Event object from the API.
 * @param array        $day_schedules  Day name → list of schedule items.
 * @param DateTimeZone $time_zone      Event timezone.
 * @return string
 */
function cfp_dev_build_schedule_ics( $current_event, array $day_schedules, DateTimeZone $time_zone ): string {
	$event_id   = absint( $current_event->id ?? 0 );
	$event_name = (string) ( $current_event->name ?? cfp_dev_get_event_name() );

	$content = cfp_dev_ical_begin( $event_name, $current_event );

	foreach ( $day_schedules as $items ) {
		if ( ! is_array( $items ) ) {
			continue;
		}
		foreach ( $items as $item ) {
			$content .= cfp_dev_ical_vevent( $item, $current_event, $time_zone );
		}
	}

	$content .= 'END:VCALENDAR' . "\r\n";

	return $content;
}

/**
 * One VEVENT for a session slot, or '' for a slot that is not a session.
 *
 * @param mixed        $item           Schedule item from a day schedule.
 * @param object       $current_event  Event object, for the talk URL and LOCATION.
 * @param DateTimeZone $time_zone      Event timezone.
 * @return string
 */
function cfp_dev_ical_vevent( $item, $current_event, DateTimeZone $time_zone ): string {
	if ( ! is_object( $item ) ) {
		return '';
	}

	$event_id = absint( $current_event->id ?? 0 );

	$proposal = $item->proposal ?? null;

	if ( '' === (string) ( $proposal->title ?? '' ) || ! empty( $item->overflow ) ) {
		return '';
	}

	$start = cfp_dev_date( (string) ( $item->fromDate ?? '' ), $time_zone );
	$end   = cfp_dev_date( (string) ( $item->toDate ?? '' ), $time_zone );

	if ( null === $start || null === $end || $end <= $start ) {
		return '';
	}

	// The API sends UTC instants; go back to UTC so the calendar app converts
	// them into the viewer's own clock.
	$utc   = new DateTimeZone( 'UTC' );
	$start = $start->setTimezone( $utc );
	$end   = $end->setTimezone( $utc );

	$uid   = 'cfp-' . $event_id . '-' . absint( $item->id ?? 0 ) . '@cfp.dev';
	$title = (string) ( $proposal->title ?? '' );
	$room  = (string) ( $item->room->name ?? '' );
	$track = (string) ( $proposal->track->name ?? '' );

	$content  = 'BEGIN:VEVENT' . "\r\n";
	$content .= cfp_dev_ical_line( 'UID', $uid );
	$content .= cfp_dev_ical_line( 'DTSTAMP', gmdate( 'Ymd\THis\Z' ) );
	$content .= cfp_dev_ical_line( 'SEQUENCE', '0' );
	$content .= cfp_dev_ical_line( 'STATUS', 'CONFIRMED' );
	$content .= cfp_dev_ical_line( 'DTSTART', $start->format( 'Ymd\THis\Z' ) );
	$content .= cfp_dev_ical_line( 'DTEND', $end->format( 'Ymd\THis\Z' ) );
	$content .= cfp_dev_ical_line( 'SUMMARY', $title );
	$content .= cfp_dev_ical_line( 'DESCRIPTION', cfp_dev_ical_description( $proposal ) );
	$content .= cfp_dev_ical_line( 'URL', home_url( cfp_dev_talk_url( $proposal ) ) );

	$location = cfp_dev_ical_location( $current_event, $room );

	if ( '' !== $location ) {
		$content .= cfp_dev_ical_line( 'LOCATION', $location );
	}

	if ( '' !== $track ) {
		$content .= cfp_dev_ical_line( 'CATEGORIES', $track );
	}

	$content .= 'END:VEVENT' . "\r\n";

	return $content;
}

/**
 * One talk as its own calendar file: the detail page's slot, not the slot a
 * day schedule happens to repeat it in.
 *
 * The talk API reports its last time slot, exactly as the detail page shows
 * it, so the two always agree. The UID falls back on the talk id only when
 * the slot carries none, keeping it aligned with the whole-event exporter.
 *
 * @param object       $talk           Talk detail object from the API.
 * @param object       $current_event  Event object from the API.
 * @param DateTimeZone $time_zone      Event timezone.
 * @return string
 */
function cfp_dev_build_talk_ics( $talk, $current_event, DateTimeZone $time_zone ): string {
	if ( ! is_object( $talk ) || '' === (string) ( $talk->title ?? '' ) ) {
		return '';
	}

	if ( empty( $talk->timeSlots ) || ! is_array( $talk->timeSlots ) ) {
		return '';
	}

	$slots = (array) $talk->timeSlots;
	$slot  = end( $slots );

	// An overflow slot is a rehearsal of where the session would be, not the
	// session itself — the schedule exporter leaves them out for the same reason.
	if ( ! empty( $slot->overflow ) ) {
		return '';
	}

	$start = cfp_dev_date( (string) ( $slot->fromDate ?? '' ), $time_zone );
	$end   = cfp_dev_date( (string) ( $slot->toDate ?? '' ), $time_zone );

	if ( null === $start || null === $end || $end <= $start ) {
		return '';
	}

	// The API sends UTC instants; go back to UTC so the calendar app converts
	// them into the viewer's own clock.
	$utc   = new DateTimeZone( 'UTC' );
	$start = $start->setTimezone( $utc );
	$end   = $end->setTimezone( $utc );

	$event_id = absint( $current_event->id ?? 0 );
	$talk_id  = absint( $talk->id ?? 0 );
	$slot_id  = absint( $slot->id ?? 0 );
	$uid      = 'cfp-' . $event_id . '-' . ( $slot_id > 0 ? $slot_id : $talk_id ) . '@cfp.dev';

	// The proposal shape cfp_dev_ical_description() reads the detail page's
	// fields into.
	$proposal = (object) [
		'description' => (string) ( $talk->description ?? '' ),
		'speakers'    => $talk->speakers ?? [],
		'track'       => (object) [ 'name' => (string) ( $talk->trackName ?? '' ) ],
	];

	$room = (string) ( $slot->roomName ?? '' );

	if ( '' === $room ) {
		$room = (string) ( $slot->room->name ?? '' );
	}

	$content  = cfp_dev_ical_begin( (string) ( $current_event->name ?? cfp_dev_get_event_name() ), $current_event );
	$content .= 'BEGIN:VEVENT' . "\r\n";
	$content .= cfp_dev_ical_line( 'UID', $uid );
	$content .= cfp_dev_ical_line( 'DTSTAMP', gmdate( 'Ymd\THis\Z' ) );
	$content .= cfp_dev_ical_line( 'SEQUENCE', '0' );
	$content .= cfp_dev_ical_line( 'STATUS', 'CONFIRMED' );
	$content .= cfp_dev_ical_line( 'DTSTART', $start->format( 'Ymd\THis\Z' ) );
	$content .= cfp_dev_ical_line( 'DTEND', $end->format( 'Ymd\THis\Z' ) );
	$content .= cfp_dev_ical_line( 'SUMMARY', (string) ( $talk->title ?? '' ) );
	$content .= cfp_dev_ical_line( 'DESCRIPTION', cfp_dev_ical_description( $proposal ) );
	$content .= cfp_dev_ical_line( 'URL', home_url( cfp_dev_talk_url( $talk ) ) );

	$location = cfp_dev_ical_location( $current_event, $room );

	if ( '' !== $location ) {
		$content .= cfp_dev_ical_line( 'LOCATION', $location );
	}

	if ( '' !== trim( (string) $proposal->track->name ) ) {
		$content .= cfp_dev_ical_line( 'CATEGORIES', (string) $proposal->track->name );
	}

	$content .= 'END:VEVENT' . "\r\n";
	$content .= 'END:VCALENDAR' . "\r\n";

	return $content;
}

/**
 * Plain-text DESCRIPTION for a session: stripped description, speaker names
 * and track, each on its own line.
 *
 * @param object $proposal  The session's proposal object.
 * @return string
 */
function cfp_dev_ical_description( $proposal ): string {
	$pieces = [];

	$body = trim( (string) ( $proposal->description ?? '' ) );

	if ( '' !== $body ) {
		$pieces[] = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $body, true ) ) );
	}

	$speakers = [];

	foreach ( (array) ( $proposal->speakers ?? [] ) as $speaker ) {
		if ( ! is_object( $speaker ) ) {
			continue;
		}
		$name = trim( (string) ( $speaker->firstName ?? '' ) . ' ' . (string) ( $speaker->lastName ?? '' ) );
		if ( '' !== $name ) {
			$speakers[] = $name;
		}
	}

	if ( ! empty( $speakers ) ) {
		$pieces[] = __( 'Speakers:', 'cfp-dev-shortcodes' ) . ' ' . implode( ', ', $speakers );
	}

	$track = trim( (string) ( $proposal->track->name ?? '' ) );

	if ( '' !== $track ) {
		$pieces[] = __( 'Track:', 'cfp-dev-shortcodes' ) . ' ' . $track;
	}

	return implode( "\n", $pieces );
}

/**
 * The filename the download is served under, from the configured event name.
 *
 * @return string
 */
function cfp_dev_ical_filename(): string {
	$slug = cfp_dev_generate_slug( cfp_dev_get_event_name() );

	if ( '' === $slug ) {
		$slug = cfp_dev_generate_slug( cfp_dev_get_key() );
	}

	if ( '' === $slug ) {
		$slug = 'event';
	}

	return $slug . '-sessions.ics';
}

/**
 * Escapes a text value for the property it goes into: backslash, semicolon,
 * comma and newline are the characters RFC 5545 reserves.
 *
 * @param string $value  Raw value.
 * @return string
 */
function cfp_dev_ical_escape( string $value ): string {
	return str_replace(
		[ '\\', ';', ',', "\n" ],
		[ '\\\\', '\\;', '\\,', '\\n' ],
		$value
	);
}

/**
 * Folds a content line to the 75-octet limit RFC 5545 imposes, continuing on
 * a line that starts with a single space.
 *
 * The fold point is chosen between whole UTF-8 characters, so a multibyte
 * title or description is never split mid-sequence.
 *
 * @param string $line  Unfolded content line.
 * @return string
 */
function cfp_dev_ical_fold( string $line ): string {
	$chars = preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );

	if ( false === $chars ) {
		return $line;
	}

	$folded  = '';
	$segment = '';
	$length  = 0;

	foreach ( $chars as $char ) {
		$width = strlen( $char );

		if ( 0 < $length && $length + $width > 75 ) {
			$folded .= $segment . "\r\n ";
			$segment = $char;
			$length  = 1 + $width;
			continue;
		}

		$segment .= $char;
		$length  += $width;
	}

	return $folded . $segment;
}

/**
 * Composes one folded name:value content line with CRLF line ending.
 *
 * @param string $name   Property name, e.g. 'SUMMARY'.
 * @param string $value  Property value, escaped for ICS text.
 * @return string
 */
function cfp_dev_ical_line( string $name, string $value ): string {
	return cfp_dev_ical_fold( $name . ':' . cfp_dev_ical_escape( $value ) ) . "\r\n";
}
