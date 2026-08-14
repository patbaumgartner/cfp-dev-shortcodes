<?php
/**
 * CFP.DEV shortcodes
 *
 * Tests for the public speaker-photo AJAX endpoint.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Integration;

use CfpDev\Tests\Fixtures;
use CfpDev\Tests\JsonResponseSent;
use CfpDev\Tests\PluginTestCase;
use CfpDev\Tests\WpDieException;
use WP_Test_State;

final class SpeakerPhotosAjaxTest extends PluginTestCase {

	protected function setUp(): void {
		parent::setUp();
		$_GET = [];
	}

	protected function tearDown(): void {
		$_GET = [];
		parent::tearDown();
	}

	public function test_a_missing_speaker_id_is_rejected(): void {
		$this->request( [] );

		$this->assertFalse( WP_Test_State::$json_responses[0]['success'] );
	}

	public function test_the_gallery_links_and_alt_text_come_from_the_api(): void {
		$this->registerDefaultApi();
		$this->registerAlbum( 100 );

		$html = $this->request( [ 'speaker_id' => '100' ] );

		$this->assertStringContainsString( 'flickr.com/photos/bejug/9001/in/album-77/', $html );
		$this->assertStringContainsString( 'alt="Jane Doe speaking at Devoxx Belgium 2025"', $html );
	}

	public function test_the_alt_text_cannot_be_poisoned_through_the_query_string(): void {
		$this->registerDefaultApi();
		$this->registerAlbum( 100 );

		// The rendered gallery is cached under the speaker id alone, so a
		// caller-supplied name would be served to every later visitor.
		$html = $this->request(
			[
				'speaker_id'   => '100',
				'speaker_name' => 'Totally Not Jane',
			]
		);

		$this->assertStringNotContainsString( 'Totally Not Jane', $html );
		$this->assertStringContainsString( 'Jane Doe speaking at', $html );
	}

	public function test_an_unknown_speaker_is_rejected_without_touching_the_api(): void {
		$this->registerDefaultApi();
		WP_Test_State::$http_log = [];

		$this->request( [ 'speaker_id' => '999' ] );

		// This endpoint is unauthenticated: an id that no speaker page could
		// have linked to must cost neither an upstream request nor a transient.
		$this->assertFalse( WP_Test_State::$json_responses[0]['success'] );
		$this->assertSame( 0, $this->apiCallCount( 'public/album/999' ) );
		$this->assertSame( 0, $this->apiCallCount( 'public/speakers/999' ) );
		$this->assertFalse( get_transient( cfp_dev_detail_cache_key( 'photo', 999 ) ) );
	}

	public function test_a_speaker_with_no_album_still_renders_the_empty_gallery(): void {
		$this->registerDefaultApi();
		$this->api( 'public/album/100', null, 404 );

		$html = $this->request( [ 'speaker_id' => '100' ] );

		$this->assertStringContainsString( 'No photos found', $html );
	}

	public function test_photos_without_a_thumbnail_are_skipped(): void {
		$this->registerDefaultApi();
		$this->api(
			'public/album/100',
			[
				[
					'photoId'      => 1,
					'albumId'      => 77,
					'thumbnailUrl' => '',
				],
				[
					'photoId'      => 2,
					'albumId'      => 77,
					'thumbnailUrl' => 'https://cdn.test/p2.jpg',
				],
			]
		);

		$html = $this->request( [ 'speaker_id' => '100' ] );

		$this->assertSame( 1, substr_count( $html, '<img' ) );
		$this->assertStringContainsString( 'p2.jpg', $html );
	}

	public function test_an_empty_album_is_cached_even_when_caching_is_disabled(): void {
		$this->registerDefaultApi();
		$this->api( 'public/album/100', [] );
		$this->assertSame( 0, cfp_dev_get_cache_ttl(), 'guard: this test covers the No Cache setting' );

		$this->request( [ 'speaker_id' => '100' ] );
		$calls_after_first = $this->apiCallCount( 'public/album/100' );

		$this->request( [ 'speaker_id' => '100' ] );

		$this->assertSame(
			$calls_after_first,
			$this->apiCallCount( 'public/album/100' ),
			'a public endpoint must not re-query the API on every anonymous request'
		);
	}

	public function test_a_populated_album_honours_the_no_cache_setting(): void {
		$this->registerDefaultApi();
		$this->registerAlbum( 100 );

		$this->request( [ 'speaker_id' => '100' ] );
		$this->request( [ 'speaker_id' => '100' ] );

		$this->assertSame( 2, $this->apiCallCount( 'public/album/100' ) );
	}

	public function test_a_cached_gallery_is_served_without_touching_the_api(): void {
		$this->option( 'cfp_dev_cache_duration', 3600 );
		$this->registerDefaultApi();
		$this->registerAlbum( 100 );

		$first                   = $this->request( [ 'speaker_id' => '100' ] );
		WP_Test_State::$http_log = [];

		$second = $this->request( [ 'speaker_id' => '100' ] );

		$this->assertSame( $first, $second );
		$this->assertSame( [], $this->httpLog() );
	}

	public function test_the_detail_page_calls_an_action_that_is_actually_registered(): void {
		$this->registerDefaultApi();
		$this->queryVar( 'id', 100 );

		$html = cfp_dev_speaker_details_shortcode();

		$this->assertSame( 1, preg_match( '/action=([a-z_]+)/', $html, $matches ) );
		$action = $matches[1];

		$this->assertTrue( has_action( 'wp_ajax_' . $action ), 'no handler registered for ' . $action );
		$this->assertTrue( has_action( 'wp_ajax_nopriv_' . $action ), 'anonymous visitors cannot reach ' . $action );
	}

	public function test_the_detail_page_no_longer_sends_the_speaker_name_to_the_endpoint(): void {
		$this->registerDefaultApi();
		$this->queryVar( 'id', 100 );

		$html = cfp_dev_speaker_details_shortcode();

		$this->assertStringContainsString( 'action=cfp_dev_speaker_photos', $html );
		$this->assertStringNotContainsString( 'speaker_name', $html );
	}

	// ─────────────────────────────────────────────────────────────────────────

	/** Invokes the AJAX handler as a fresh HTTP request and returns its output. */
	private function request( array $params ): string {
		// Each call stands for a separate PHP process, so the request-scoped
		// API memo must not leak between them.
		cfp_dev_flush_request_cache();

		$_GET = $params;
		ob_start();
		try {
			cfp_dev_speaker_photos_handler();
		} catch ( WpDieException | JsonResponseSent $expected ) {
			unset( $expected );
		}
		return (string) ob_get_clean();
	}

	private function registerAlbum( int $speaker_id ): void {
		$this->api(
			'public/album/' . $speaker_id,
			[
				[
					'photoId'      => 9001,
					'albumId'      => 77,
					'thumbnailUrl' => 'https://cdn.test/p1.jpg',
				],
			]
		);
	}
}
