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

		$html = cfp_speakers_shortcode( [] );

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

		$html = cfp_speakers_shortcode( [ 'size' => '1' ] );

		$this->assertStringContainsString( 'Jane Doe', $html );
		$this->assertStringNotContainsString( 'Šumailov', $html );
	}

	public function test_speakers_reports_an_empty_api_result_without_breaking_markup(): void {
		$this->api( 'public/speakers?size=300', [] );

		$html = cfp_speakers_shortcode( [] );

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( 'No speakers found', $html );
	}

	public function test_speakers_can_hide_the_title_and_search_form(): void {
		$this->api( 'public/speakers?size=300', Fixtures::speakers() );

		$html = cfp_speakers_shortcode(
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

		$html = cfp_speaker_details_shortcode();

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

		$html = cfp_speaker_details_shortcode();

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( 'Ilya Šumailov', $html );
	}

	public function test_speaker_details_reports_an_unknown_speaker(): void {
		$this->registerDefaultApi();
		$this->queryVar( 'id', 999 );
		$this->api( 'public/speakers/999', null, 404 );

		$this->assertSame( 'Speaker not found.', cfp_speaker_details_shortcode() );
	}

	public function test_talk_details_renders_description_schedule_video_and_speakers(): void {
		$this->registerDefaultApi();
		$this->search( 'x', [] );
		$this->queryVar( 'id', 200 );

		$html = cfp_talk_details_shortcode();

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

		$html = cfp_talk_details_shortcode();

		$this->assertStringNotContainsString( 'evil.test', $html );
		$this->assertStringNotContainsString( 'cfp-podcast', $html );
	}

	public function test_talk_details_reports_an_unknown_talk(): void {
		$this->queryVar( 'id', 999 );
		$this->api( 'public/talks/999', null, 404 );

		$this->assertSame( 'Talk not found.', cfp_talk_details_shortcode() );
	}

	public function test_talks_by_tracks_defaults_to_the_first_track(): void {
		$this->registerDefaultApi();
		$this->api( 'public/talks/track/10', [ Fixtures::talks()[0] ] );

		$html = cfp_talks_by_tracks_shortcode( [] );

		$this->assertHtmlBalanced( $html, '[cfp_talks_by_tracks]' );
		$this->assertStringContainsString( 'Modern Java in Practice', $html );
		$this->assertStringContainsString( 'All things Java', $html );
		$this->assertStringNotContainsString( 'Architecture Without Tears', $html );
	}

	public function test_talks_by_tracks_all_attribute_lists_every_talk(): void {
		$this->registerDefaultApi();

		$html = cfp_talks_by_tracks_shortcode( [ 'all' => 'true' ] );

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( 'Modern Java in Practice', $html );
		$this->assertStringContainsString( 'Architecture Without Tears', $html );
	}

	public function test_talks_by_tracks_handles_a_failing_api(): void {
		$this->api( 'public/tracks', null, 500 );

		$html = cfp_talks_by_tracks_shortcode( [] );

		$this->assertHtmlBalanced( $html );
		$this->assertStringContainsString( 'No tracks found', $html );
	}

	public function test_talks_by_sessions_skips_pause_session_types(): void {
		$this->registerDefaultApi();
		$this->api( 'public/talks/session-type/20', [ Fixtures::talks()[0] ] );

		$html = cfp_talks_by_sessions_shortcode( [] );

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

		$html = cfp_search_results_shortcode();

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

		$html = cfp_search_results_shortcode();

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
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
}
