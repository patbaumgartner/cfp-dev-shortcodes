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
