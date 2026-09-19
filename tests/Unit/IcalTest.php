<?php
/**
 * CFP.DEV shortcodes
 *
 * Unit tests for the iCalendar export: what becomes a VEVENT, how session
 * data is escaped and folded into RFC 5545 lines, and how the download is
 * routed and cached.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Unit;

use CfpDev\Tests\Fixtures;
use CfpDev\Tests\PluginTestCase;
use WP_Test_State;

final class IcalTest extends PluginTestCase {

	public function test_the_calendar_header_and_calname_introduce_the_event(): void {
		$ics = $this->ics();

		$this->assertStringStartsWith( 'BEGIN:VCALENDAR', $ics );
		$this->assertStringContainsString( 'VERSION:2.0', $ics );
		$this->assertStringContainsString( 'CALSCALE:GREGORIAN', $ics );
		$this->assertStringContainsString( 'X-WR-CALNAME:Devoxx Belgium 2025', $ics );
		$this->assertStringContainsString( 'X-WR-TIMEZONE:' . Fixtures::EVENT_TIMEZONE, $ics );
		$this->assertMatchesRegularExpression( '#X-WR-RELCALID:[0-9a-f-]{36}#', $ics );
		$this->assertStringEndsWith( 'END:VCALENDAR' . "\r\n", $ics );
	}

	public function test_the_calendar_identity_is_stable_across_exports(): void {
		$relcalid = cfp_dev_ical_relcalid( 42 );

		$this->assertMatchesRegularExpression(
			'#^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$#',
			$relcalid
		);

		$this->assertStringContainsString( 'X-WR-RELCALID:' . $relcalid, $this->ics() );
		$this->assertStringContainsString( 'X-WR-RELCALID:' . $relcalid, $this->talkIcs() );
	}

	public function test_each_event_carries_status_sequence_and_track_categories(): void {
		$ics = $this->ics();

		$this->assertStringContainsString( 'SEQUENCE:0', $ics );
		$this->assertStringContainsString( 'STATUS:CONFIRMED', $ics );
		$this->assertStringContainsString( 'CATEGORIES:Java', $ics );
	}

	public function test_the_location_composes_room_venue_and_city(): void {
		$event = $this->objs(
			array_merge(
				Fixtures::event(),
				[
					'locationName'    => 'Kinepolis',
					'locationCity'    => 'Antwerp',
					'locationCountry' => 'Belgium',
				]
			)
		);
		$tz    = new \DateTimeZone( Fixtures::EVENT_TIMEZONE );
		$ics   = cfp_dev_build_schedule_ics( $event, [ 'Monday' => [ $this->sessionSlot() ] ], $tz );

		$this->assertStringContainsString( 'LOCATION:Room 4\nKinepolis\nAntwerp\, Belgium', $ics );
	}

	public function test_breaks_and_overflow_slots_are_left_out_of_the_calendar(): void {
		$overflow = $this->sessionSlot(
			[
				'id'       => 888,
				'overflow' => true,
				'proposal' => [
					'id'    => 888,
					'title' => 'Overflow filler',
				],
			]
		);

		$ics = $this->ics( [ $this->breakSlot(), $this->sessionSlot(), $overflow ] );

		$this->assertSame( 1, substr_count( $ics, 'BEGIN:VEVENT' ) );
		$this->assertStringContainsString( 'SUMMARY:Modern Java in Practice', $ics );
		$this->assertStringNotContainsString( 'Coffee time', $ics );
		$this->assertStringNotContainsString( 'Overflow filler', $ics );
	}

	public function test_dates_are_emitted_as_utc_instants(): void {
		$ics = $this->ics();

		$this->assertStringContainsString( 'DTSTART:20251006T083000Z', $ics );
		$this->assertStringContainsString( 'DTEND:20251006T092000Z', $ics );
		$this->assertMatchesRegularExpression( '#DTSTAMP:\d{8}T\d{6}Z#', $ics );
	}

	public function test_uid_namespaces_the_event_and_the_slot(): void {
		$this->assertStringContainsString( 'UID:cfp-42-2007@cfp.dev', $this->ics() );
	}

	public function test_summary_description_location_and_url_carry_the_session(): void {
		$ics = $this->ics();

		$this->assertStringContainsString( 'SUMMARY:Modern Java in Practice', $ics );
		$this->assertStringContainsString( 'LOCATION:Room 4', $ics );
		$this->assertStringContainsString( 'DESCRIPTION:Java for everyone.', $ics );
		$this->assertStringContainsString( 'Speakers: Jane Doe', $ics );
		$this->assertStringContainsString( 'Track: Java', $ics );
		$this->assertStringContainsString( 'URL:https://example.test/talk/modern-java-in-practice/', $ics );
	}

	public function test_the_description_is_plain_text_with_ics_reserved_characters_escaped(): void {
		$slot = $this->sessionSlot( [ 'proposal' => [ 'description' => '<p>From <em>C:\data</em> — a talk; come, enjoy.</p>' ] ] );

		$ics = $this->ics( [ $slot ] );

		$this->assertStringContainsString( 'DESCRIPTION:From C:\\\\data — a talk\\; come\\, enjoy.', $ics );
		$this->assertStringNotContainsString( '<em>', $ics );
	}

	public function test_an_unusable_slot_yields_no_event(): void {
		$broken = $this->sessionSlot( [ 'fromDate' => 'not-a-date' ] );

		$ics = $this->ics( [ $broken ] );

		$this->assertSame( 0, substr_count( $ics, 'BEGIN:VEVENT' ) );
	}

	public function test_lines_are_folded_at_75_octets_without_splitting_a_multibyte_character(): void {
		$half  = str_repeat( 'a', 37 );
		$title = $half . 'é' . $half . str_repeat( 'b', 3 );

		foreach ( explode( "\r\n", cfp_dev_ical_fold( 'SUMMARY:' . $title ) ) as $i => $line ) {
			$this->assertLessThanOrEqual( 75, strlen( $line ), 'physical line ' . $i . ' is too long' );
			if ( $i > 0 ) {
				$this->assertStringStartsWith( ' ', $line );
			}
		}

		// The é must survive whole, never a lone leading byte.
		$this->assertStringContainsString( 'é', cfp_dev_ical_fold( 'SUMMARY:' . $title ) );
	}

	public function test_the_ics_is_rebuilt_and_cached_as_one_transient(): void {
		$this->option( 'cfp_dev_cache_duration', HOUR_IN_SECONDS );
		$this->registerOneDayEvent();

		$first = cfp_dev_schedule_ics();
		cfp_dev_flush_request_cache();

		$this->assertNotEmpty( $first );
		$this->assertSame( 1, $this->apiCallCount( 'public/event' ) );

		$second = cfp_dev_schedule_ics();

		$this->assertSame( $first, $second );
		$this->assertSame( 1, $this->apiCallCount( 'public/event' ), 'a cached calendar must not call the API again' );
	}

	public function test_schedule_ics_returns_empty_when_the_event_cannot_be_fetched(): void {
		$this->assertSame( '', cfp_dev_schedule_ics() );
	}

	/** A failed programme must not be pinned into the cache. */
	public function test_a_failed_build_is_not_cached(): void {
		$this->option( 'cfp_dev_cache_duration', HOUR_IN_SECONDS );

		$this->assertSame( '', cfp_dev_schedule_ics() );
		$this->assertSame( false, get_transient( cfp_dev_group_cache_key( 'cfp_schedule_ical' ) ) );
	}

	public function test_the_ical_filename_comes_from_the_event_name(): void {
		$this->assertSame( 'devoxx-belgium-2025-sessions.ics', cfp_dev_ical_filename() );
	}

	public function test_the_ical_filename_falls_back_to_the_instance_key(): void {
		$this->option( 'cfp_dev_event_name', '' );

		$this->assertSame( 'dvbe25-sessions.ics', cfp_dev_ical_filename() );
	}

	public function test_the_ical_filename_gives_up_gracefully(): void {
		$this->option( 'cfp_dev_event_name', '' );
		$this->option( 'cfp_dev_key', '' );

		$this->assertSame( 'event-sessions.ics', cfp_dev_ical_filename() );
	}

	public function test_cfp_ical_is_a_registered_public_query_var(): void {
		$this->assertContains( 'cfp_ical', cfp_dev_add_query_vars( [ 'id' ] ) );
	}

	public function test_the_calendar_runs_before_the_canonical_redirect(): void {
		$pending = WP_Test_State::$hooks['template_redirect'] ?? [];
		foreach ( $pending as $entry ) {
			if ( 'cfp_dev_maybe_serve_ical' === $entry['callback'] ) {
				$this->assertLessThan(
					10,
					$entry['priority'],
					'a front-page schedule would canonicalise /schedule/ical/ to / before the download'
				);
				return;
			}
		}
		$this->fail( 'cfp_dev_maybe_serve_ical is not registered on template_redirect' );
	}

	public function test_the_calendar_download_has_a_rewrite_rule(): void {
		cfp_dev_add_rewrite_rules();

		$rules = WP_Test_State::$env['rewrite_rules'] ?? [];

		$this->assertArrayHasKey( '^schedule/ical/?$', $rules );
		$this->assertSame( 'index.php?pagename=schedule&cfp_ical=1', $rules['^schedule/ical/?$'] );
	}

	public function test_each_session_download_has_a_rewrite_rule(): void {
		cfp_dev_add_rewrite_rules();

		$rules = WP_Test_State::$env['rewrite_rules'] ?? [];

		$this->assertArrayHasKey( '^talk/([^/]+)/ical/?$', $rules );
		$this->assertSame( 'index.php?pagename=talk&talk_slug=$matches[1]&cfp_ical=1', $rules['^talk/([^/]+)/ical/?$'] );

		$this->assertArrayHasKey( '^talk/ical/?$', $rules );
		$this->assertSame( 'index.php?pagename=talk&cfp_ical=1', $rules['^talk/ical/?$'] );
	}

	public function test_the_id_based_ical_rule_wins_over_the_bare_talk_rule(): void {
		cfp_dev_add_rewrite_rules();

		$rules   = array_keys( WP_Test_State::$env['rewrite_rules'] ?? [] );
		$ical    = array_search( '^talk/ical/?$', $rules, true );
		$generic = array_search( '^talk/([^/]+)/?$', $rules, true );

		$this->assertIsInt( $ical );
		$this->assertIsInt( $generic );
		$this->assertLessThan(
			$generic,
			$ical,
			'^talk/ical/?$ must be evaluated before ^talk/([^/]+)/?$, or "ical" is swallowed as a talk slug'
		);
	}

	public function test_a_single_talk_is_its_own_calendar_with_one_event(): void {
		$ics = $this->talkIcs();

		$this->assertStringStartsWith( 'BEGIN:VCALENDAR', $ics );
		$this->assertStringEndsWith( 'END:VCALENDAR' . "\r\n", $ics );
		$this->assertSame( 1, substr_count( $ics, 'BEGIN:VEVENT' ) );
		$this->assertStringContainsString( 'X-WR-CALNAME:Devoxx Belgium 2025', $ics );
		$this->assertStringContainsString( 'SUMMARY:Modern Java in Practice', $ics );
		$this->assertStringContainsString( 'DTSTART:20251006T083000Z', $ics );
		$this->assertStringContainsString( 'DTEND:20251006T092000Z', $ics );
		$this->assertStringContainsString( 'LOCATION:Room 4', $ics );
		$this->assertStringContainsString( 'URL:https://example.test/talk/modern-java-in-practice/', $ics );
	}

	public function test_the_talk_description_carries_speakers_and_track(): void {
		$this->assertStringContainsString(
			"DESCRIPTION:A talk about Java.\\nSpeakers: Jane Doe\\nTrack: Java",
			$this->talkIcs()
		);
	}

	public function test_a_talk_without_a_time_slot_yields_no_calendar(): void {
		$detail              = Fixtures::talkDetail( 200 );
		$detail['timeSlots'] = [];

		$this->assertSame( '', $this->buildTalkIcs( $this->objs( $detail ) ) );
	}

	public function test_a_talk_with_an_overflow_slot_is_left_out(): void {
		$detail                             = Fixtures::talkDetail( 200 );
		$detail['timeSlots'][0]['id']       = 2195;
		$detail['timeSlots'][0]['overflow'] = true;

		$this->assertSame( '', $this->buildTalkIcs( $this->objs( $detail ) ) );
	}

	public function test_the_talk_uid_shares_the_slots_identity_with_the_event_calendar(): void {
		$detail                       = Fixtures::talkDetail( 200 );
		$detail['timeSlots'][0]['id'] = 2195;

		$this->assertStringContainsString( 'UID:cfp-42-2195@cfp.dev', $this->buildTalkIcs( $this->objs( $detail ) ) );
	}

	public function test_talk_ics_returns_empty_when_the_event_cannot_be_fetched(): void {
		$this->assertSame( '', cfp_dev_talk_ics( 200 ) );
	}

	public function test_talk_ics_is_cached_under_its_own_transient(): void {
		$this->option( 'cfp_dev_cache_duration', HOUR_IN_SECONDS );
		$this->api( 'public/event', Fixtures::event() );
		$this->api( 'public/talks/200', Fixtures::talkDetail( 200 ) );

		$first = cfp_dev_talk_ics( 200 );
		cfp_dev_flush_request_cache();

		$this->assertNotEmpty( $first );
		$this->assertSame( 1, $this->apiCallCount( 'public/event' ) );

		$second = cfp_dev_talk_ics( 200 );

		$this->assertSame( $first, $second );
		$this->assertSame(
			1,
			$this->apiCallCount( 'public/talks/200' ),
			'a cached talk calendar must not call the API again'
		);
	}

	public function test_the_session_download_url_sits_under_the_talk_permalink(): void {
		$talk = $this->objs( Fixtures::talkDetail( 200 ) );

		$this->assertSame( cfp_dev_url( '/talk/modern-java-in-practice/ical/' ), cfp_dev_talk_ical_url( $talk ) );
	}

	public function test_the_session_download_url_falls_back_to_the_talk_id(): void {
		$this->option( 'cfp_dev_content_by_id', 'yes' );
		$talk = $this->objs( Fixtures::talkDetail( 200 ) );

		$this->assertSame( 'https://example.test/talk/ical/?id=200', cfp_dev_talk_ical_url( $talk ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Fixtures & helpers
	// ─────────────────────────────────────────────────────────────────────────

	/** Builds the full calendar for one Monday with the given slots. */
	private function ics( array $slots = [] ): string {
		$tz    = new \DateTimeZone( Fixtures::EVENT_TIMEZONE );
		$event = $this->objs( Fixtures::event() );

		if ( [] === $slots ) {
			$slots = [ $this->sessionSlot() ];
		}

		return cfp_dev_build_schedule_ics( $event, [ 'Monday' => $slots ], $tz );
	}

	/** The calendar for the detail-page fixture talk (id 200). */
	private function talkIcs(): string {
		return $this->buildTalkIcs( $this->objs( Fixtures::talkDetail( 200 ) ) );
	}

	/** Builds one talk's calendar from the given detail object. */
	private function buildTalkIcs( $talk ): string {
		$tz = new \DateTimeZone( Fixtures::EVENT_TIMEZONE );

		return cfp_dev_build_talk_ics( $talk, $this->objs( Fixtures::event() ), $tz );
	}

	/** Registers the event and its day schedules with one session each. */
	private function registerOneDayEvent(): void {
		$this->api( 'public/event', Fixtures::event() );

		foreach ( [ 'Monday', 'Tuesday', 'Wednesday' ] as $day ) {
			$this->api( 'public/schedules/' . $day, [ $this->sessionSlot() ] );
		}
	}

	/** A full day-schedule session slot, as the API serves it. */
	private function sessionSlot( array $dimensional = [] ): object {
		$layout = [
			'id'          => 2007,
			'fromDate'    => '2025-10-06T08:30:00Z',
			'toDate'      => '2025-10-06T09:20:00Z',
			'overflow'    => false,
			'room'        => [ 'name' => 'Room 4' ],
			'sessionType' => [ 'name' => 'Conference' ],
			'proposal'    => [
				'id'          => 200,
				'title'       => 'Modern Java in Practice',
				'description' => '<p>Java for <strong>everyone</strong>.</p>',
				'roomName'    => 'Room 4',
				'track'       => [ 'name' => 'Java' ],
				'speakers'    => [
					[
						'firstName' => 'Jane',
						'lastName'  => 'Doe',
					],
				],
			],
		];

		return $this->objs( $this->mergeRecursive( $layout, $dimensional ) );
	}

	/** An afternoon break: a slot with no proposal at all. */
	private function breakSlot(): object {
		return $this->objs(
			[
				'id'          => 3000,
				'fromDate'    => '2025-10-06T10:00:00Z',
				'toDate'      => '2025-10-06T10:20:00Z',
				'overflow'    => false,
				'room'        => [ 'name' => '' ],
				'sessionType' => [ 'name' => 'Break' ],
				'proposal'    => null,
			]
		);
	}

	/** Recursively merges overrides into the layout without replacing arrays. */
	private function mergeRecursive( array $base, array $overrides ): array {
		foreach ( $overrides as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = $this->mergeRecursive( $base[ $key ], $value );
				continue;
			}
			$base[ $key ] = $value;
		}
		return $base;
	}

	/** Decodes a fixture array into the object graph the API hands back. */
	private function objs( $data ) {
		return json_decode( (string) json_encode( $data ) );
	}
}
