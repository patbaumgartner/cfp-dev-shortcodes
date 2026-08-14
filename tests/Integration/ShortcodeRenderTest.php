<?php
/**
 * CFP.DEV shortcodes
 *
 * Integration tests: render every shortcode against fixture API data and
 * assert on the produced markup.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Integration;

use CfpDev\Tests\Fixtures;
use CfpDev\Tests\PluginTestCase;

final class ShortcodeRenderTest extends PluginTestCase {

	public function test_speakers_renders_a_sorted_grid_of_linked_speakers(): void {
		$this->registerDefaultApi();
		$this->api( 'public/speakers?size=300', Fixtures::speakers() );

		$html = cfp_dev_speakers_shortcode( [] );

		$this->assertHtmlBalanced( $html, '[cfp_speakers]' );
		$this->assertStringContainsString( 'Jane Doe', $html );
		$this->assertStringContainsString( 'Ilya Šumailov', $html );
		$this->assertStringContainsString( '/speaker/jane-doe/', $html );
		$this->assertStringContainsString( '/speaker/ilya-sumailov/', $html );
		// Sorted by last name: Doe before Šumailov.
		$this->assertLessThan( strpos( $html, 'Šumailov' ), (int) strpos( $html, 'Jane Doe' ) );
	}

	public function test_speakers_honours_the_size_attribute_even_when_the_api_over_delivers(): void {
		$this->api( 'public/speakers?size=1', Fixtures::speakers() );

		$html = cfp_dev_speakers_shortcode( [ 'size' => '1' ] );

		$this->assertStringContainsString( 'Jane Doe', $html );
		$this->assertStringNotContainsString( 'Šumailov', $html );
	}

	public function test_speakers_reports_an_empty_api_result_without_breaking_markup(): void {
		$this->api( 'public/speakers?size=300', [] );

		$html = cfp_dev_speakers_shortcode( [] );

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( 'No speakers found', $html );
	}

	public function test_speakers_can_hide_the_title_and_search_form(): void {
		$this->api( 'public/speakers?size=300', Fixtures::speakers() );

		$html = cfp_dev_speakers_shortcode(
			[
				'hide_title'  => 'yes',
				'hide_search' => 'yes',
			]
		);

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( '>Speakers<', $html );
	}

	public function test_speaker_details_renders_profile_socials_and_talks(): void {
		$this->registerDefaultApi();
		$this->queryVar( 'id', 100 );

		$html = cfp_dev_speaker_details_shortcode();

		$this->assertHtmlBalanced( $html, '[cfp_speaker_details]' );
		$this->assertStringContainsString( 'Jane Doe', $html );
		$this->assertStringContainsString( 'https://www.linkedin.com/in/janedoe', $html );
		$this->assertStringContainsString( 'https://bsky.app/profile/jane.bsky.social', $html );
		$this->assertStringContainsString( 'https://x.com/janedoe', $html );
		$this->assertStringContainsString( 'Modern Java in Practice', $html );
	}

	public function test_speaker_details_resolves_a_slug_to_an_id(): void {
		$this->registerDefaultApi();
		$this->queryVar( 'speaker_slug', 'ilya-sumailov' );

		$html = cfp_dev_speaker_details_shortcode();

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( 'Ilya Šumailov', $html );
	}

	public function test_speaker_details_reports_an_unknown_speaker(): void {
		$this->registerDefaultApi();
		$this->queryVar( 'id', 999 );
		$this->api( 'public/speakers/999', null, 404 );

		$this->assertSame( 'Speaker not found.', cfp_dev_speaker_details_shortcode() );
	}

	public function test_talk_details_renders_description_schedule_video_and_speakers(): void {
		$this->registerDefaultApi();
		$this->search( 'x', [] );
		$this->queryVar( 'id', 200 );

		$html = cfp_dev_talk_details_shortcode();

		$this->assertHtmlBalanced( $html, '[cfp_talk_details]' );
		$this->assertStringContainsString( 'Modern Java in Practice', $html );
		$this->assertStringContainsString( 'Room 4', $html );
		$this->assertStringContainsString( 'youtube.com/embed/abc123', $html );
		$this->assertStringContainsString( 'Jane Doe', $html );
		// 08:30 UTC is 10:30 in Europe/Brussels.
		$this->assertStringContainsString( '10:30', $html );
	}

	public function test_talk_details_embeds_only_spotify_hosted_podcasts(): void {
		$this->registerDefaultApi();
		$detail               = Fixtures::talkDetail( 200 );
		$detail['podcastURL'] = 'https://evil.test/?open.spotify.com';
		$this->api( 'public/talks/200', $detail );
		$this->queryVar( 'id', 200 );

		$html = cfp_dev_talk_details_shortcode();

		$this->assertStringNotContainsString( 'evil.test', $html );
		$this->assertStringNotContainsString( 'cfp-podcast', $html );
	}

	public function test_talk_details_reports_an_unknown_talk(): void {
		$this->queryVar( 'id', 999 );
		$this->api( 'public/talks/999', null, 404 );

		$this->assertSame( 'Talk not found.', cfp_dev_talk_details_shortcode() );
	}

	public function test_talks_by_tracks_defaults_to_the_first_track(): void {
		$this->registerDefaultApi();
		$this->api( 'public/talks/track/10', [ Fixtures::talks()[0] ] );

		$html = cfp_dev_talks_by_tracks_shortcode( [] );

		$this->assertHtmlBalanced( $html, '[cfp_talks_by_tracks]' );
		$this->assertStringContainsString( 'Modern Java in Practice', $html );
		$this->assertStringContainsString( 'All things Java', $html );
		$this->assertStringNotContainsString( 'Architecture Without Tears', $html );
	}

	public function test_talks_by_tracks_all_attribute_lists_every_talk(): void {
		$this->registerDefaultApi();

		$html = cfp_dev_talks_by_tracks_shortcode( [ 'all' => 'true' ] );

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( 'Modern Java in Practice', $html );
		$this->assertStringContainsString( 'Architecture Without Tears', $html );
	}

	public function test_talks_by_tracks_handles_a_failing_api(): void {
		$this->api( 'public/tracks', null, 500 );

		$html = cfp_dev_talks_by_tracks_shortcode( [] );

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( 'No tracks found', $html );
	}

	public function test_talks_by_sessions_skips_pause_session_types(): void {
		$this->registerDefaultApi();
		$this->api( 'public/talks/session-type/20', [ Fixtures::talks()[0] ] );

		$html = cfp_dev_talks_by_sessions_shortcode( [] );

		$this->assertHtmlBalanced( $html, '[cfp_talks_by_sessions]' );
		$this->assertStringContainsString( 'Conference', $html );
		$this->assertStringNotContainsString( 'Coffee Break', $html );
		$this->assertStringContainsString( 'Modern Java in Practice', $html );
	}

	public function test_search_results_renders_exact_and_semantic_matches(): void {
		$this->registerDefaultApi();
		$this->queryVar( 'query', 'java' );
		$this->api(
			'public/search?query=java',
			[
				'proposals' => [ Fixtures::talks()[0] ],
				'speakers'  => [ Fixtures::speakers()[0] ],
			]
		);
		$this->search(
			'java',
			[
				[
					'id'    => 201,
					'title' => 'Architecture Without Tears',
					'score' => 0.42,
				],
			]
		);

		$html = cfp_dev_search_results_shortcode();

		$this->assertHtmlBalanced( $html, '[cfp_search_results]' );
		$this->assertStringContainsString( 'Search results for', $html );
		$this->assertStringContainsString( 'Modern Java in Practice', $html );
		$this->assertStringContainsString( 'Architecture Without Tears', $html );
		$this->assertStringContainsString( '0.42', $html );
	}

	public function test_search_results_escapes_the_query(): void {
		$this->queryVar( 'query', '<img src=x onerror=alert(1)>' );
		$this->api( 'public/search?query=' . rawurlencode( 'img srcx onerroralert1' ), [] );
		$this->search( 'img srcx onerroralert1', [] );

		$html = cfp_dev_search_results_shortcode();

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
	}

	public function test_search_results_without_a_query_still_offers_the_search_form(): void {
		$html = cfp_dev_search_results_shortcode();

		$this->assertHtmlBalanced( $html, '[cfp_search_results] without a query' );
		$this->assertStringContainsString( '<form', $html, 'visitors need a way to start a search' );
		$this->assertSame( [], $this->httpLog(), 'an empty query must not hit the search API' );
	}

	public function test_talk_details_survives_a_talk_with_no_optional_fields(): void {
		$this->registerDefaultApi();
		$this->api( 'public/talks/202', Fixtures::sparseTalk() );
		$this->search( 'Bare Minimum Talk ', [] );
		$this->queryVar( 'id', 202 );

		$html = cfp_dev_talk_details_shortcode();

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( 'Bare Minimum Talk', $html );
	}

	public function test_related_talks_are_ordered_by_score_and_exclude_the_current_talk(): void {
		$this->registerDefaultApi();
		$this->queryVar( 'id', 200 );
		$this->search(
			'Modern Java in Practice A talk about Java.',
			[
				[
					'id'    => 201,
					'title' => 'Far Match',
					'score' => 0.9,
				],
				[
					'id'    => 200,
					'title' => 'Modern Java in Practice',
					'score' => 0.0,
				],
				[
					'id'    => 203,
					'title' => 'Close Match',
					'score' => 0.1,
				],
				[
					'id'    => 204,
					'title' => 'Overflow Room',
					'score' => 0.2,
				],
			]
		);

		$html = cfp_dev_talk_details_shortcode();

		preg_match_all( '#<div class="cfp-related">(.*?)</div>#s', $html, $matches );
		$related = implode( "\n", $matches[1] );

		$this->assertStringNotContainsString( 'Overflow Room', $related, 'overflow entries are not real talks' );
		$this->assertStringNotContainsString( 'Modern Java in Practice', $related, 'the current talk must not link to itself' );
		$this->assertLessThan(
			(int) strpos( $related, 'Far Match' ),
			(int) strpos( $related, 'Close Match' ),
			'related talks must be ordered best match first'
		);
	}

	public function test_an_api_image_url_cannot_break_out_of_a_css_url_value(): void {
		$this->registerDefaultApi();
		$this->api( 'public/speakers?size=300', Fixtures::speakers() );

		$this->assertNoCssBreakout( cfp_dev_speakers_shortcode( [] ) );
	}

	public function test_speaker_details_survives_a_profile_with_no_optional_fields(): void {
		$this->registerDefaultApi();
		$this->queryVar( 'id', 101 );

		$html = cfp_dev_speaker_details_shortcode();

		$this->assertHtmlBalanced( $html );
		$this->assertNoCssBreakout( $html );
		$this->assertStringContainsString( 'Ilya Šumailov', $html );
	}

	/**
	 * @dataProvider sparseTalkListProvider
	 */
	public function test_talk_lists_survive_a_talk_with_no_optional_fields( string $shortcode, string $path ): void {
		$this->registerDefaultApi();
		$this->api( $path, [ Fixtures::sparseTalk() ] );

		$html = $shortcode( [] );

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( 'Bare Minimum Talk', $html );
	}

	public static function sparseTalkListProvider(): array {
		return [
			'by track'        => [ 'cfp_dev_talks_by_tracks_shortcode', 'public/talks/track/10' ],
			'by session type' => [ 'cfp_dev_talks_by_sessions_shortcode', 'public/talks/session-type/20' ],
		];
	}

	public function test_search_results_survive_a_result_with_no_optional_fields(): void {
		$this->queryVar( 'query', 'bare' );
		$this->api(
			'public/search?query=bare',
			[
				'proposals' => [ Fixtures::sparseTalk() + [ 'sessionType' => [ 'name' => 'Conference' ] ] ],
				'speakers'  => [],
			]
		);
		$this->search( 'bare', [ [ 'title' => 'Bare Minimum Talk' ] ] );

		$html = cfp_dev_search_results_shortcode();

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( 'Bare Minimum Talk', $html );
	}

	public function test_schedule_renders_day_tabs_rooms_and_sessions(): void {
		$this->registerScheduleApi();

		$html = cfp_dev_schedule_shortcode( [] );

		$this->assertHtmlBalanced( $html, '[cfp_schedule]' );
		$this->assertStringContainsString( 'Modern Java in Practice', $html );
		$this->assertStringContainsString( 'Room 4', $html );
		$this->assertStringContainsString( 'Jane Doe', $html );
		$this->assertStringContainsString( '?id=Monday', $html );
		$this->assertStringContainsString( '?id=Wednesday', $html );
	}

	public function test_schedule_time_ruler_uses_the_event_timezone_and_the_viewed_day(): void {
		$this->registerScheduleApi();
		// A site in another timezone must not shift the schedule's own clock.
		$this->siteTimezone( 'America/New_York' );

		$html = cfp_dev_schedule_shortcode( [] );

		// Sessions run 07:00–09:20 UTC → 09:00–11:20 in Europe/Brussels.
		preg_match_all( '#<time class="cfp-time" datetime="([^"]+)">([^<]+)</time>#', $html, $matches, PREG_SET_ORDER );
		$labels = array_column( $matches, 2 );

		$this->assertSame( '09:00', $labels[0] );
		$this->assertSame( '09:10', $labels[1] );
		$this->assertSame( '11:00', $labels[12] );

		// The machine-readable timestamp must point at the day being viewed.
		$this->assertSame( '2025-10-06T09:00:00+02:00', $matches[0][1] );
	}

	public function test_schedule_shows_a_tab_for_the_closing_day_even_when_it_ends_early(): void {
		$event           = Fixtures::event();
		$event['toDate'] = '2025-10-08T06:00:00Z'; // Ends before the daily start time.
		$this->registerScheduleApi();
		$this->api( 'public/event', $event );

		$html = cfp_dev_schedule_shortcode( [] );

		$this->assertStringContainsString( '?id=Wednesday', $html, 'the closing day must still be listed' );
	}

	public function test_schedule_rejects_a_day_that_is_not_a_weekday_name(): void {
		$this->registerScheduleApi();
		$this->queryVar( 'id', '../../etc/passwd' );

		$html = cfp_dev_schedule_shortcode( [] );

		// Falls back to the event's first day rather than building an API path
		// (and a transient key) out of arbitrary input.
		$this->assertStringContainsString( 'cfp-active', $html );
		$this->assertSame( 0, $this->apiCallCount( 'public/schedules/../../etc/passwd' ) );
	}

	public function test_schedule_reports_a_failing_event_endpoint(): void {
		$this->api( 'public/event', null, 500 );

		$this->assertSame( 'Failed to retrieve current event', cfp_dev_schedule_shortcode( [] ) );
	}

	public function test_schedule_reports_a_missing_event_timezone(): void {
		$event = Fixtures::event();
		unset( $event['timezone'] );
		$this->api( 'public/event', $event );

		$this->assertSame( 'Event timezone is not set.', cfp_dev_schedule_shortcode( [] ) );
	}

	/**
	 * Every date on this page is a string from a service the plugin does not
	 * control, and DateTime throws on anything it cannot parse. Uncaught, that
	 * is a blank page rather than a blank schedule.
	 *
	 * @dataProvider unusableEventDateProvider
	 */
	public function test_schedule_reports_an_unusable_event_date_range( string $field, string $value ): void {
		$this->registerScheduleApi();
		$event           = Fixtures::event();
		$event[ $field ] = $value;
		$this->api( 'public/event', $event );

		$this->assertSame( 'Event dates are not set.', cfp_dev_schedule_shortcode( [] ) );
	}

	public static function unusableEventDateProvider(): array {
		return [
			'unparseable start' => [ 'fromDate', 'not a date' ],
			'unparseable end'   => [ 'toDate', 'yesterday-ish' ],
			'empty start'       => [ 'fromDate', '' ],
		];
	}

	/** A single broken session must not cost the reader the rest of the day. */
	public function test_schedule_skips_only_the_sessions_with_unusable_dates(): void {
		$this->registerScheduleApi();

		$broken             = Fixtures::roomSchedule()[0];
		$broken['fromDate'] = 'whenever';
		$this->api( 'public/schedules/Monday/1', [ $broken ] );

		$html = cfp_dev_schedule_shortcode( [] );

		$this->assertHtmlBalanced( $html, '[cfp_schedule] with a broken session' );
		$this->assertStringNotContainsString( 'Modern Java in Practice', $html );
		$this->assertStringContainsString( '?id=Monday', $html, 'the rest of the page must still render' );
	}

	/** The ruler is built from the day's own slots, which are API data too. */
	public function test_schedule_omits_the_grid_when_the_day_has_unusable_slot_times(): void {
		$this->registerScheduleApi();
		$this->api(
			'public/schedules/Monday',
			[
				[
					'fromDate' => 'nope',
					'toDate'   => 'nope',
				],
			]
		);

		$html = cfp_dev_schedule_shortcode( [] );

		$this->assertHtmlBalanced( $html, '[cfp_schedule] with unusable slot times' );
		$this->assertStringNotContainsString( '--hour-start', $html );
	}

	/** A slot the API cannot describe is dropped, not rendered half-formed. */
	public function test_talk_details_omits_a_time_slot_with_an_unusable_date(): void {
		$talk                           = Fixtures::talkDetail( 200 );
		$talk['timeSlots'][0]['toDate'] = 'sometime';
		$this->api( 'public/talks/200', $talk );
		$this->queryVar( 'id', 200 );

		$html = cfp_dev_talk_details_shortcode();

		$this->assertHtmlBalanced( $html, '[cfp_talk_details] with an unusable slot' );
		$this->assertStringContainsString( 'Modern Java in Practice', $html );
		$this->assertStringNotContainsString( 'cfp-datetime', $html );
	}

	/**
	 * The cache stores rendered HTML, so a hit does not depend on any API
	 * data. The lookup used to sit below the event, rooms and day-schedule
	 * fetches, which meant serving a cached day still cost three round trips.
	 */
	public function test_a_cached_schedule_day_is_served_without_calling_the_api(): void {
		$this->registerScheduleApi();
		$this->option( 'cfp_dev_cache_duration', HOUR_IN_SECONDS );
		$this->queryVar( 'id', 'Monday' );

		$first = cfp_dev_schedule_shortcode( [] );
		$this->assertStringContainsString( 'Modern Java in Practice', $first );
		$this->assertNotSame( [], $this->httpLog(), 'the first render must populate the cache from the API' );

		// A second request: same day, nothing memoised, cache already warm.
		\WP_Test_State::$http_log = [];
		cfp_dev_flush_request_cache();

		$this->assertSame( $first, cfp_dev_schedule_shortcode( [] ) );
		$this->assertSame( [], $this->httpLog(), 'a cached day must not touch the API' );
	}

	/** Without ?id= the default day is only knowable from the event itself. */
	public function test_a_cached_default_schedule_day_costs_only_the_event_lookup(): void {
		$this->registerScheduleApi();
		$this->option( 'cfp_dev_cache_duration', HOUR_IN_SECONDS );

		cfp_dev_schedule_shortcode( [] );

		\WP_Test_State::$http_log = [];
		cfp_dev_flush_request_cache();

		cfp_dev_schedule_shortcode( [] );

		$this->assertSame(
			[ cfp_dev_api_base() . 'public/event' ],
			$this->httpLog(),
			'only the lookup that names the default day is needed'
		);
	}

	public function test_shortcodes_are_registered_on_plugins_loaded(): void {
		foreach (
			[
				'cfp_speakers',
				'cfp_speaker_details',
				'cfp_talk_details',
				'cfp_schedule',
				'cfp_talks_by_tracks',
				'cfp_talks_by_sessions',
				'cfp_search_results',
			] as $tag
		) {
			$this->assertTrue( shortcode_exists( $tag ), $tag . ' is not registered' );
		}
	}

	/** Registers the event, rooms and per-day/per-room schedule endpoints. */
	private function registerScheduleApi(): void {
		$this->api( 'public/event', Fixtures::event() );
		$this->api( 'public/rooms', Fixtures::rooms() );

		foreach ( [ 'Monday', 'Tuesday', 'Wednesday' ] as $day ) {
			$this->api( 'public/schedules/' . $day, Fixtures::daySchedule() );
			$this->api( 'public/schedules/' . $day . '/1', Fixtures::roomSchedule() );
			$this->api( 'public/schedules/' . $day . '/2', [] );
		}
	}
}
