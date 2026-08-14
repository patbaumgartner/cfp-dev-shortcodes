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

	public function test_a_cached_speaker_page_is_served_without_calling_the_api(): void {
		$this->registerDefaultApi();
		$this->option( 'cfp_dev_cache_duration', HOUR_IN_SECONDS );
		$this->queryVar( 'id', 100 );

		$first = cfp_dev_speaker_details_shortcode();
		$this->assertStringContainsString( 'Jane Doe', $first );

		\WP_Test_State::$http_log = [];
		cfp_dev_flush_request_cache();

		$this->assertSame( $first, cfp_dev_speaker_details_shortcode() );
		$this->assertSame( [], $this->httpLog(), 'a cached speaker page must not touch the API' );
	}

	public function test_an_outage_is_not_cached_as_a_missing_speaker(): void {
		$this->registerDefaultApi();
		$this->option( 'cfp_dev_cache_duration', WEEK_IN_SECONDS );
		$this->queryVar( 'id', 100 );

		$this->api( 'public/speakers/100', null, 503 );
		$this->assertSame( 'Speaker not found.', cfp_dev_speaker_details_shortcode() );

		$this->api( 'public/speakers/100', Fixtures::speakerDetail( 100 ) );
		cfp_dev_flush_request_cache();

		$this->assertStringContainsString(
			'Jane Doe',
			cfp_dev_speaker_details_shortcode(),
			'the outage was cached and outlived itself'
		);
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

	public function test_talk_details_publishes_the_talk_timing_for_themes(): void {
		$this->registerDefaultApi();
		$this->search( 'Modern Java in Practice A talk about Java.', [] );
		$this->queryVar( 'id', 200 );

		$html = cfp_dev_talk_details_shortcode();

		// 2025-10-06T08:30:00Z … 09:20:00Z, in the event's own timezone.
		$this->assertStringContainsString( 'id="cfpTimezone" value="Europe/Brussels"', $html );
		$this->assertStringContainsString( 'id="cfpTalkFrom" value="1759739400"', $html );
		$this->assertStringContainsString( 'id="cfpTalkExpiry" value="1759742400"', $html );
	}

	public function test_talk_details_reports_an_unknown_talk(): void {
		$this->queryVar( 'id', 999 );
		$this->api( 'public/talks/999', null, 404 );

		$this->assertSame( 'Talk not found.', cfp_dev_talk_details_shortcode() );
	}

	/**
	 * The filter nav is ordered by track name, so "the first track" has to mean
	 * the first tab the reader sees. Choosing it from the API's own order
	 * highlighted one tab and listed another tab's talks.
	 */
	public function test_talks_by_tracks_defaults_to_the_first_track_shown(): void {
		$this->registerDefaultApi();
		$this->api( 'public/talks/track/11', [ Fixtures::talks()[1] ] );

		$html = cfp_dev_talks_by_tracks_shortcode( [] );

		$this->assertHtmlBalanced( $html, '[cfp_talks_by_tracks]' );

		// Architecture sorts before Java, so it is both the first tab and the
		// active one, and its talks are the ones listed.
		preg_match_all( '#<a class="cfp-a ([^"]*)" href="\?id=(\d+)">([^<]+)</a>#', $html, $tabs, PREG_SET_ORDER );
		$this->assertSame( 'Architecture', $tabs[0][3] );
		$this->assertStringContainsString( 'cfp-active', $tabs[0][1], 'the first tab must be the active one' );

		$this->assertStringContainsString( 'Architecture Without Tears', $html );
		$this->assertStringNotContainsString( 'Modern Java in Practice', $html );
	}

	public function test_talks_by_tracks_honours_an_explicitly_selected_track(): void {
		$this->registerDefaultApi();
		$this->api( 'public/talks/track/10', [ Fixtures::talks()[0] ] );
		$this->queryVar( 'id', 10 );

		$html = cfp_dev_talks_by_tracks_shortcode( [] );

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

	/**
	 * The fallback page is what a visitor sees when the list cannot be
	 * fetched, so it has to be a page — laid out with the classes the
	 * stylesheet knows, not the dev-cfp-* ones it has no rules for.
	 *
	 * @dataProvider emptyListPageProvider
	 */
	public function test_a_list_page_falls_back_to_a_laid_out_message( string $shortcode, string $path, string $message ): void {
		$this->api( $path, null, 500 );

		$html = $shortcode( [] );

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( $message, $html );
		$this->assertStringContainsString( '<div class="cfp-main">', $html );
		$this->assertStringContainsString( 'class="cfp-text"', $html );
		$this->assertStringNotContainsString( 'dev-cfp-', $html, 'the stylesheet has no rules for these classes' );
	}

	public static function emptyListPageProvider(): array {
		return [
			'tracks'        => [ 'cfp_dev_talks_by_tracks_shortcode', 'public/tracks', 'No tracks found' ],
			'session types' => [ 'cfp_dev_talks_by_sessions_shortcode', 'public/session-types', 'No session types found' ],
		];
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
	 * The time-slot block carries fixed element ids for themes that localise
	 * the displayed times. A speaker page renders one block per scheduled
	 * talk, so those ids appeared once per talk and getElementById() resolved
	 * every one of them to the first talk's times.
	 */
	public function test_a_speaker_with_two_scheduled_talks_repeats_no_element_id(): void {
		$this->registerDefaultApi();
		$speaker                = Fixtures::speakerDetail( 100 );
		$speaker['proposals'][] = [
			'id'    => 201,
			'title' => 'Architecture Without Tears',
			'track' => [
				'id'   => 11,
				'name' => 'Architecture',
			],
		];
		$this->api( 'public/speakers/100', $speaker );

		// Both talks are scheduled, so both render a time-slot block.
		$second              = Fixtures::talkDetail( 201 );
		$second['timeSlots'] = Fixtures::talkDetail( 200 )['timeSlots'];
		$this->api( 'public/talks/201', $second );

		$this->queryVar( 'id', 100 );

		$html = cfp_dev_speaker_details_shortcode();

		$this->assertSame( 2, substr_count( $html, 'class="cfp-datetime"' ), 'both talks must show their slot' );
		$this->assertUniqueElementIds( $html, '[cfp_speaker_details] with two scheduled talks' );
	}

	/** The same guarantee for every page a visitor can land on. */
	public function test_every_shortcode_page_uses_each_element_id_once(): void {
		$this->registerDefaultApi();
		$this->registerScheduleApi();
		$this->search( 'x', [] );
		$this->search( 'Modern Java in Practice A talk about Java.', [] );
		$this->queryVar( 'id', 200 );

		$this->assertUniqueElementIds( cfp_dev_talk_details_shortcode(), '[cfp_talk_details]' );
		$this->assertUniqueElementIds( cfp_dev_talks_by_tracks_shortcode( [] ), '[cfp_talks_by_tracks]' );
		$this->assertUniqueElementIds( cfp_dev_talks_by_sessions_shortcode( [] ), '[cfp_talks_by_sessions]' );
		$this->assertUniqueElementIds( cfp_dev_speakers_shortcode( [] ), '[cfp_speakers]' );
		$this->assertUniqueElementIds( cfp_dev_schedule_shortcode( [] ), '[cfp_schedule]' );
		$this->assertUniqueElementIds( cfp_dev_search_results_shortcode(), '[cfp_search_results]' );
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
			'by track'        => [ 'cfp_dev_talks_by_tracks_shortcode', 'public/talks/track/11' ],
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

	/**
	 * A single-day event carries only fromDate. The crawler has always read an
	 * absent end that way; the schedule page called it "Event dates are not
	 * set." and rendered nothing at all.
	 */
	public function test_schedule_renders_a_single_day_event_with_no_end_date(): void {
		$event = Fixtures::event();
		unset( $event['toDate'] );
		$this->registerScheduleApi();
		$this->api( 'public/event', $event );

		$html = cfp_dev_schedule_shortcode( [] );

		$this->assertHtmlBalanced( $html, '[cfp_schedule] for a one-day event' );
		$this->assertStringContainsString( '?id=Monday', $html );
		$this->assertStringNotContainsString( '?id=Tuesday', $html, 'a one-day event has one day' );
	}

	/**
	 * The grid stops at --hour-finish, so a session running past the hour it
	 * ends in spills out of the rows the stylesheet laid down. The fixture's
	 * last session ends 11:20, which needs the ruler to reach 12:00.
	 */
	public function test_the_schedule_grid_reaches_past_the_last_session(): void {
		$this->registerScheduleApi();

		$html = cfp_dev_schedule_shortcode( [] );

		preg_match( '/--hour-start:(\d+); --hour-finish:(\d+);/', $html, $bounds );

		$this->assertSame( '9', $bounds[1] );
		$this->assertSame( '12', $bounds[2], 'a session ending 11:20 needs the 11:00-12:00 row' );
	}

	/** The bounds come from the day, not from whichever slot the API sent first. */
	public function test_the_schedule_grid_bounds_do_not_depend_on_api_ordering(): void {
		$this->registerScheduleApi();
		$this->api( 'public/schedules/Monday', array_reverse( Fixtures::daySchedule() ) );

		$html = cfp_dev_schedule_shortcode( [] );

		preg_match( '/--hour-start:(\d+); --hour-finish:(\d+);/', $html, $bounds );

		$this->assertSame( '9', $bounds[1] );
		$this->assertSame( '12', $bounds[2] );
	}

	/** One broken slot must not cost the whole grid its bounds. */
	public function test_the_schedule_grid_survives_one_unusable_slot(): void {
		$this->registerScheduleApi();
		$slots                = Fixtures::daySchedule();
		$slots[0]['fromDate'] = 'whenever';
		$this->api( 'public/schedules/Monday', $slots );

		$html = cfp_dev_schedule_shortcode( [] );

		$this->assertStringContainsString( '--hour-start', $html, 'the readable slots still describe a day' );
	}

	/**
	 * The stylesheet turns data-event-duration into a row span through rules in
	 * five-minute steps, so a figure it has no rule for gets no height and the
	 * session disappears. sessionType.duration is optional, and 0 is exactly
	 * such a figure — the slot's own timestamps always know the answer.
	 *
	 * @dataProvider gridDurationProvider
	 */
	public function test_a_session_gets_a_height_the_stylesheet_can_render( $api_duration, string $expected ): void {
		$this->registerScheduleApi();
		$session = Fixtures::roomSchedule()[0];
		if ( null === $api_duration ) {
			unset( $session['sessionType']['duration'] );
		} else {
			$session['sessionType']['duration'] = $api_duration;
		}
		$this->api( 'public/schedules/Monday/1', [ $session ] );

		$html = cfp_dev_schedule_shortcode( [] );

		$this->assertStringContainsString( 'data-event-duration="' . $expected . '"', $html );
	}

	public static function gridDurationProvider(): array {
		return [
			// 08:30-09:20 UTC is a 50 minute session; the API's own figure wins.
			'as sent'           => [ 50, '50' ],
			'omitted'           => [ null, '50' ],
			'zero'              => [ 0, '50' ],
			// Snapped to a step the stylesheet defines.
			'off the five-grid' => [ 47, '45' ],
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

	/**
	 * A failure and an absence look the same to a reader but must not be
	 * cached the same. Storing the failure page meant one minute of API
	 * downtime replaced a real talk with "Talk not found" for the whole cache
	 * period — up to a month on the longest TTL, with nothing a visitor could
	 * do to clear it.
	 *
	 * @dataProvider outageProvider
	 */
	public function test_an_api_outage_is_not_cached_as_a_missing_page(
		string $shortcode,
		string $failing_path,
		array $payload,
		string $failure_text,
		string $recovered_text
	): void {
		$this->registerDefaultApi();
		$this->option( 'cfp_dev_cache_duration', WEEK_IN_SECONDS );
		$this->queryVar( 'id', 200 );

		$this->api( $failing_path, null, 503 );
		$this->assertStringContainsString( $failure_text, $shortcode( [] ) );

		// The API comes back.
		$this->api( $failing_path, $payload );
		cfp_dev_flush_request_cache();

		$this->assertStringContainsString(
			$recovered_text,
			$shortcode( [] ),
			'the outage was cached and outlived itself'
		);
	}

	public static function outageProvider(): array {
		return [
			'talk detail'    => [ 'cfp_dev_talk_details_shortcode', 'public/talks/200', Fixtures::talkDetail( 200 ), 'Talk not found.', 'Modern Java in Practice' ],
			'speaker grid'   => [ 'cfp_dev_speakers_shortcode', 'public/speakers?size=300', Fixtures::speakers(), 'No speakers found.', 'Jane Doe' ],
			'talks by track' => [ 'cfp_dev_talks_by_tracks_shortcode', 'public/tracks', Fixtures::tracks(), 'No tracks found', 'Java' ],
		];
	}

	/** An event with genuinely no speakers is an answer, and answers cache. */
	public function test_an_empty_speaker_list_is_cached_like_any_other_answer(): void {
		$this->option( 'cfp_dev_cache_duration', HOUR_IN_SECONDS );
		$this->api( 'public/speakers?size=300', [] );

		$this->assertStringContainsString( 'No speakers found.', cfp_dev_speakers_shortcode( [] ) );

		\WP_Test_State::$http_log = [];
		cfp_dev_flush_request_cache();

		cfp_dev_speakers_shortcode( [] );
		$this->assertSame( [], $this->httpLog(), 'an empty list is a real answer and should be served from cache' );
	}

	/**
	 * An <iframe src> is not a link: the framed origin runs its own code in
	 * the visitor's browser, and this URL comes straight from the API — with
	 * autoplay and encrypted-media already granted by the allow attribute.
	 *
	 * @dataProvider videoUrlProvider
	 */
	public function test_only_video_from_a_supported_host_is_embedded( string $url, bool $embedded ): void {
		$talk             = Fixtures::talkDetail( 200 );
		$talk['videoURL'] = $url;
		$this->api( 'public/talks/200', $talk );
		$this->queryVar( 'id', 200 );

		$html = cfp_dev_talk_details_shortcode();

		// The podcast embed is always present on this fixture, so assert on the
		// video iframe specifically rather than on any iframe.
		if ( $embedded ) {
			$this->assertStringContainsString( 'title="Video:', $html );
			$this->assertStringContainsString( $url, $html );
		} else {
			$this->assertStringNotContainsString( 'title="Video:', $html, 'framed a video from ' . $url );
			$this->assertStringNotContainsString( $url, $html );
		}
	}

	public static function videoUrlProvider(): array {
		return [
			'youtube'          => [ 'https://www.youtube.com/embed/abc123', true ],
			'youtube-nocookie' => [ 'https://www.youtube-nocookie.com/embed/abc123', true ],
			'vimeo'            => [ 'https://player.vimeo.com/video/123', true ],
			'foreign host'     => [ 'https://evil.test/embed/abc123', false ],
			'lookalike host'   => [ 'https://youtube.com.evil.test/embed/x', false ],
			'javascript uri'   => [ 'javascript:alert(1)', false ],
		];
	}

	/** The same rule the podcast embed has always had, now shared. */
	public function test_a_podcast_url_with_a_query_string_keeps_resolving(): void {
		$talk               = Fixtures::talkDetail( 200 );
		$talk['podcastURL'] = 'https://open.spotify.com/embed/episode/xyz?theme=0';
		$this->api( 'public/talks/200', $talk );
		$this->queryVar( 'id', 200 );

		$html = cfp_dev_talk_details_shortcode();

		$this->assertStringContainsString( 'utm_source=WordPress', $html );
		$this->assertStringNotContainsString( 'xyz?theme=0?utm_source', $html, 'a second ? was appended' );
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
