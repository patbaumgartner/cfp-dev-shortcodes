<?php
/**
 * CFP.DEV shortcodes
 *
 * Tests for API payloads that omit a field the plugin reads.
 *
 * The existing sparse fixtures cover records whose *optional* fields are
 * absent. These cover the fields the plugin treats as always-there — a name, a
 * last name, an id. A CFP.DEV instance that leaves one out turns every read of
 * it into a PHP warning, and a warning inside a shortcode is printed into the
 * middle of the page it was rendering.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Integration;

use CfpDev\Tests\Fixtures;
use CfpDev\Tests\PluginTestCase;

final class PartialApiDataTest extends PluginTestCase {

	/** The grid sorts on lastName, so a mononymous speaker used to warn. */
	public function test_the_speaker_grid_sorts_a_speaker_who_has_no_last_name(): void {
		$speakers   = Fixtures::speakers();
		$speakers[] = [
			'id'        => 102,
			'firstName' => 'Prince',
			'imageUrl'  => 'https://cdn.test/prince.jpg',
		];
		$this->api( 'public/speakers?size=300', $speakers );

		$html = cfp_dev_speakers_shortcode( [] );

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( 'Prince', $html );
		// Sorted under the empty string, i.e. before Doe.
		$this->assertLessThan( strpos( $html, 'Jane Doe' ), (int) strpos( $html, 'Prince' ) );
	}

	/** The same comparator, reached through the speaker detail page's talks. */
	public function test_a_talk_lists_a_speaker_who_has_no_last_name(): void {
		$this->registerDefaultApi();
		$talk                = Fixtures::talkDetail( 200 );
		$talk['speakers'][0] = [
			'id'        => 102,
			'firstName' => 'Prince',
			'imageUrl'  => 'https://cdn.test/prince.jpg',
		];
		$this->api( 'public/talks/200', $talk );
		$this->search( 'Modern Java in Practice A talk about Java.', [] );
		$this->queryVar( 'id', 200 );

		$html = cfp_dev_talk_details_shortcode();

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( 'Prince', $html );
	}

	/** A nameless track still needs a clickable, correctly-targeted tab. */
	public function test_the_track_filter_renders_a_track_with_no_name(): void {
		$this->registerDefaultApi();
		$this->api( 'public/tracks', [ [ 'id' => 10 ] ] );
		$this->api( 'public/talks/track/10', Fixtures::talks() );

		$html = cfp_dev_talks_by_tracks_shortcode( [] );

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( 'href="?id=10"', $html );
	}

	/** Without an id there is no tab target and no talk list to fetch. */
	public function test_the_track_filter_renders_a_track_with_no_id(): void {
		$this->registerDefaultApi();
		$this->api( 'public/tracks', [ [ 'name' => 'Java' ] ] );
		$this->api( 'public/talks/track/0', [] );

		$html = cfp_dev_talks_by_tracks_shortcode( [] );

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( 'Java', $html );
	}

	/** Tag names become both a search query and link text. */
	public function test_talk_details_renders_a_tag_with_no_name(): void {
		$this->registerDefaultApi();
		$talk         = Fixtures::talkDetail( 200 );
		$talk['tags'] = [ [ 'id' => 5 ] ];
		$this->api( 'public/talks/200', $talk );
		$this->search( 'Modern Java in Practice A talk about Java.', [] );
		$this->queryVar( 'id', 200 );

		$html = cfp_dev_talk_details_shortcode();

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( 'Modern Java in Practice', $html );
	}

	/** The head metadata reads the same track list the tabs do. */
	public function test_the_track_page_description_survives_a_nameless_track(): void {
		$this->registerDefaultApi();
		$this->api( 'public/tracks', [ [ 'id' => 10 ] ] );
		$this->onPage( 'talks-by-tracks' );
		$this->queryVar( 'id', 10 );

		$this->assertNotSame( '', cfp_dev_page_meta()['description'] );
	}

	/** Likewise for session types, which are listed by name. */
	public function test_the_session_page_description_survives_a_nameless_session_type(): void {
		$this->registerDefaultApi();
		$this->api( 'public/session-types', [ [ 'id' => 20 ] ] );
		$this->onPage( 'talks-by-sessions' );

		$this->assertNotSame( '', cfp_dev_page_meta()['description'] );
	}

	/** A speaker URL is built from both names; only the first is required. */
	public function test_the_speaker_page_and_sitemap_handle_a_missing_last_name(): void {
		$this->registerDefaultApi();
		$speaker = Fixtures::speakerDetail( 100 );
		unset( $speaker['lastName'] );
		$this->api( 'public/speakers/100', $speaker );
		$this->api( 'public/speakers?size=' . CFP_DEV_SPEAKERS_FETCH_SIZE, [ $speaker ] );
		$this->onPage( 'speaker' );
		$this->queryVar( 'id', 100 );

		$meta = cfp_dev_page_meta();

		$this->assertStringStartsWith( 'Jane', $meta['title'] );
		$this->assertSame( 'https://example.test/speaker/jane/', $meta['url'] );
		$this->assertContains( 'https://example.test/speaker/jane/', array_column( cfp_dev_sitemap_urls(), 'loc' ) );
	}

	/** The speaker page also renders that record, and links its photo album. */
	public function test_the_speaker_page_renders_a_profile_with_no_id(): void {
		$this->registerDefaultApi();
		$speaker = Fixtures::speakerDetail( 100 );
		unset( $speaker['id'] );
		$this->api( 'public/speakers/100', $speaker );
		$this->queryVar( 'id', 100 );

		$html = cfp_dev_speaker_details_shortcode();

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( 'Jane Doe', $html );
	}

	/** The schedule heading falls back to the event's own name. */
	public function test_the_schedule_renders_an_event_with_no_name(): void {
		$event = Fixtures::event();
		unset( $event['name'] );
		$this->api( 'public/event', $event );
		$this->api( 'public/rooms', Fixtures::rooms() );
		$this->api( 'public/schedules/Monday', Fixtures::daySchedule() );
		$this->api( 'public/schedules/Monday/1', Fixtures::roomSchedule() );
		$this->api( 'public/schedules/Monday/2', [] );

		$html = cfp_dev_schedule_shortcode( [] );

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( '?id=Monday', $html );
	}
}
