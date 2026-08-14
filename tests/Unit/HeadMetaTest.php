<?php
/**
 * CFP.DEV shortcodes
 *
 * Tests for the server-rendered SEO layer: page metadata, document title,
 * canonical URL, Open Graph/Twitter tags, JSON-LD, robots and the sitemap
 * provider.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Unit;

use CfpDev\Tests\Fixtures;
use CfpDev\Tests\PluginTestCase;

final class HeadMetaTest extends PluginTestCase {

	public function test_page_meta_is_null_outside_plugin_pages(): void {
		$this->assertNull( cfp_dev_page_meta() );
	}

	public function test_talk_pages_get_an_entity_aware_title_description_and_canonical(): void {
		$this->registerDefaultApi();
		$this->onPage( 'talk' );
		$this->queryVar( 'id', 200 );

		$meta = cfp_dev_page_meta();

		$this->assertSame( 'Modern Java in Practice - Devoxx Belgium 2025', $meta['title'] );
		$this->assertSame( 'A talk about Java.', $meta['description'] );
		$this->assertSame( 'https://example.test/talk/modern-java-in-practice/', $meta['url'] );
		$this->assertSame( 'article', $meta['og_type'] );

		$this->assertSame( $meta['title'], cfp_dev_document_title( 'fallback' ) );
		$this->assertSame( $meta['url'], cfp_dev_canonical_url( 'https://example.test/talk/', null ) );
	}

	public function test_speaker_pages_fall_back_to_a_generated_description(): void {
		$this->registerDefaultApi();
		$this->onPage( 'speaker' );
		$this->queryVar( 'id', 101 );

		$meta = cfp_dev_page_meta();

		$this->assertSame( 'Ilya Šumailov - Devoxx Belgium 2025', $meta['title'] );
		$this->assertSame( 'Ilya Šumailov speaks at Devoxx Belgium 2025.', $meta['description'] );
		$this->assertSame( 'profile', $meta['og_type'] );
	}

	public function test_speaker_pages_reject_unusable_social_images(): void {
		$this->registerDefaultApi();
		$speaker             = Fixtures::speakerDetail( 100 );
		$speaker['imageUrl'] = 'https://encrypted-tbn0.gstatic.com/images?q=tbn:x';
		$this->api( 'public/speakers/100', $speaker );
		$this->onPage( 'speaker' );
		$this->queryVar( 'id', 100 );

		// A ~90px Google cache thumbnail makes a blurry share card; talk pages
		// already fell back to the site default, speaker pages did not.
		$this->assertSame( '', cfp_dev_page_meta()['image'] );
	}

	public function test_list_pages_keep_the_wordpress_title_and_get_a_description(): void {
		$this->registerDefaultApi();
		$this->onPage( 'speakers' );

		$meta = cfp_dev_page_meta();

		$this->assertSame( '', $meta['title'] );
		$this->assertStringContainsString( 'Devoxx Belgium 2025', $meta['description'] );
		$this->assertSame( 'fallback', cfp_dev_document_title( 'fallback' ) );
	}

	public function test_track_page_description_names_the_selected_track(): void {
		$this->registerDefaultApi();
		$this->onPage( 'talks-by-tracks' );
		$this->queryVar( 'id', 10 );

		$this->assertStringContainsString( 'Java talks at Devoxx Belgium 2025', cfp_dev_page_meta()['description'] );
	}

	public function test_session_page_description_deduplicates_repeated_type_names(): void {
		$this->registerDefaultApi();
		$this->onPage( 'talks-by-sessions' );

		$description = cfp_dev_page_meta()['description'];

		$this->assertSame( 1, substr_count( $description, 'Conference' ), 'duplicate session type names must be listed once' );
		$this->assertStringNotContainsString( 'Coffee Break', $description );
	}

	public function test_search_result_pages_are_noindex_follow(): void {
		$this->onPage( 'search-results' );

		$robots = cfp_dev_robots( [ 'index' => true ] );

		$this->assertArrayNotHasKey( 'index', $robots );
		$this->assertTrue( $robots['noindex'] );
		$this->assertTrue( $robots['follow'] );
	}

	public function test_other_pages_keep_their_robots_directives(): void {
		$this->onPage( 'speakers' );

		$this->assertSame( [ 'index' => true ], cfp_dev_robots( [ 'index' => true ] ) );
	}

	public function test_head_meta_emits_open_graph_and_twitter_tags(): void {
		$this->registerDefaultApi();
		$this->onPage( 'talk' );
		$this->queryVar( 'id', 200 );

		$html = $this->capture( 'cfp_dev_output_head_meta' );

		$this->assertStringContainsString( '<meta name="description" content="A talk about Java.">', $html );
		$this->assertStringContainsString( '<meta property="og:type" content="article">', $html );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image">', $html );
		$this->assertStringContainsString( 'og:url" content="https://example.test/talk/modern-java-in-practice/"', $html );
	}

	public function test_head_meta_defers_to_a_theme_that_declares_support(): void {
		$this->registerDefaultApi();
		$this->onPage( 'talk' );
		$this->queryVar( 'id', 200 );
		add_theme_support( 'cfp-dev-head-meta' );

		$this->assertSame( '', $this->capture( 'cfp_dev_output_head_meta' ) );
	}

	public function test_json_ld_describes_a_talk_as_an_event(): void {
		$this->registerDefaultApi();
		$this->onPage( 'talk' );
		$this->queryVar( 'id', 200 );

		$schema = $this->extractJsonLd( $this->capture( 'cfp_dev_output_jsonld' ) );

		$this->assertSame( 'Event', $schema['@type'] );
		$this->assertSame( 'Modern Java in Practice', $schema['name'] );
		$this->assertSame( 'Jane Doe', $schema['performer'][0]['name'] );
		$this->assertSame( 'Room 4', $schema['location']['name'] );
		$this->assertSame( '2025-10-06T08:30:00Z', $schema['startDate'] );
	}

	public function test_json_ld_describes_a_speaker_as_a_person(): void {
		$this->registerDefaultApi();
		$this->onPage( 'speaker' );
		$this->queryVar( 'id', 100 );

		$schema = $this->extractJsonLd( $this->capture( 'cfp_dev_output_jsonld' ) );

		$this->assertSame( 'Person', $schema['@type'] );
		$this->assertSame( 'Jane Doe', $schema['name'] );
		$this->assertSame( 'Acme', $schema['worksFor']['name'] );
	}

	public function test_unresolvable_detail_pages_return_a_real_404(): void {
		$this->registerDefaultApi();
		$this->onPage( 'talk' );
		$this->queryVar( 'talk_slug', 'a-talk-that-was-removed' );

		$GLOBALS['wp_query'] = new class() {
			public bool $is_404 = false;

			public function set_404(): void {
				$this->is_404 = true;
			}
		};

		cfp_dev_404_unresolved_detail();

		$this->assertTrue( $GLOBALS['wp_query']->is_404 );
		$this->assertSame( 404, \WP_Test_State::$env['status_header'] );
	}

	public function test_resolvable_detail_pages_are_not_turned_into_404s(): void {
		$this->registerDefaultApi();
		$this->onPage( 'talk' );
		$this->queryVar( 'talk_slug', 'modern-java-in-practice' );

		$GLOBALS['wp_query'] = new class() {
			public bool $is_404 = false;

			public function set_404(): void {
				$this->is_404 = true;
			}
		};

		cfp_dev_404_unresolved_detail();

		$this->assertFalse( $GLOBALS['wp_query']->is_404 );
	}

	/**
	 * A removed talk and an unreachable API both render "not found", but they
	 * must not answer the same. Returning 404 whenever the lookup came back
	 * empty meant a minute of API downtime told Google that every talk and
	 * speaker on the site was gone.
	 *
	 * @dataProvider outageLookupProvider
	 */
	public function test_an_unreachable_api_does_not_report_a_detail_page_as_gone( string $page, string $query_var, string $value, string $failing_path ): void {
		$this->registerDefaultApi();
		$this->api( $failing_path, null, 503 );
		$this->onPage( $page );
		$this->queryVar( $query_var, $value );

		$GLOBALS['wp_query'] = $this->spyQuery();

		cfp_dev_404_unresolved_detail();

		$this->assertFalse( $GLOBALS['wp_query']->is_404, 'an outage was reported as a removed page' );
		$this->assertSame( 503, \WP_Test_State::$env['status_header'], 'a crawler needs to be told to come back' );
	}

	public static function outageLookupProvider(): array {
		return [
			'talk by slug'    => [ 'talk', 'talk_slug', 'modern-java-in-practice', 'public/talks' ],
			'talk by id'      => [ 'talk', 'id', '200', 'public/talks/200' ],
			'speaker by slug' => [ 'speaker', 'speaker_slug', 'jane-doe', 'public/speakers?size=' . CFP_DEV_SPEAKERS_FETCH_SIZE ],
			'speaker by id'   => [ 'speaker', 'id', '100', 'public/speakers/100' ],
		];
	}

	/** A 404 from the API is an answer, and the answer is that it is gone. */
	public function test_an_api_404_still_produces_a_404(): void {
		$this->registerDefaultApi();
		$this->api( 'public/talks/999', null, 404 );
		$this->onPage( 'talk' );
		$this->queryVar( 'id', '999' );

		$GLOBALS['wp_query'] = $this->spyQuery();

		cfp_dev_404_unresolved_detail();

		$this->assertTrue( $GLOBALS['wp_query']->is_404 );
		$this->assertSame( 404, \WP_Test_State::$env['status_header'] );
	}

	/**
	 * The canonical must name a URL the site is configured to serve. In id
	 * mode — which multisite installs are required to use — it advertised a
	 * slug URL the operator had turned off, and that is the URL a search
	 * engine indexes.
	 *
	 * @dataProvider canonicalModeProvider
	 */
	public function test_the_canonical_follows_the_configured_permalink_mode( string $by_id, string $page, string $expected ): void {
		$this->registerDefaultApi();
		$this->option( 'cfp_dev_content_by_id', $by_id );
		$this->onPage( $page );
		$this->queryVar( 'id', 'talk' === $page ? 200 : 100 );

		$this->assertSame( $expected, cfp_dev_page_meta()['url'] );
	}

	public static function canonicalModeProvider(): array {
		return [
			'talk by slug'    => [ 'no', 'talk', 'https://example.test/talk/modern-java-in-practice/' ],
			'talk by id'      => [ 'yes', 'talk', 'https://example.test/talk?id=200' ],
			'speaker by slug' => [ 'no', 'speaker', 'https://example.test/speaker/jane-doe/' ],
			'speaker by id'   => [ 'yes', 'speaker', 'https://example.test/speaker?id=100' ],
		];
	}

	public function test_sitemap_lists_every_talk_and_speaker_url_once(): void {
		$this->registerDefaultApi();

		$urls = array_column( cfp_dev_sitemap_urls(), 'loc' );

		$this->assertSame( $urls, array_unique( $urls ) );
		$this->assertContains( 'https://example.test/talk/modern-java-in-practice/', $urls );
		$this->assertContains( 'https://example.test/speaker/ilya-sumailov/', $urls );
	}

	public function test_sitemap_provider_reports_a_single_page(): void {
		$this->registerDefaultApi();

		$provider = new \CFP_Dev_Sitemaps_Provider();

		$this->assertSame( 1, $provider->get_max_num_pages() );
		$this->assertNotEmpty( $provider->get_url_list( 1 ) );
		$this->assertSame( [], $provider->get_url_list( 2 ) );
	}

	// ─────────────────────────────────────────────────────────────────────────

	/** A stand-in for $wp_query that records whether it was marked as a 404. */
	private function spyQuery(): object {
		return new class() {
			public bool $is_404 = false;

			public function set_404(): void {
				$this->is_404 = true;
			}
		};
	}

	/** Runs a function that echoes into the head and returns its output. */
	private function capture( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	/** Extracts the decoded JSON-LD payload from rendered head output. */
	private function extractJsonLd( string $html ): array {
		$this->assertMatchesRegularExpression( '#<script type="application/ld\+json">.+</script>#s', $html );
		preg_match( '#<script type="application/ld\+json">(.+?)</script>#s', $html, $matches );
		return json_decode( $matches[1], true );
	}
}
