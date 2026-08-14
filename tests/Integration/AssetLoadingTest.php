<?php
/**
 * CFP.DEV shortcodes
 *
 * Tests for front-end asset loading and the accessibility/performance
 * attributes on embedded media.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Integration;

use CfpDev\Tests\Fixtures;
use CfpDev\Tests\PluginTestCase;
use WP_Test_State;

final class AssetLoadingTest extends PluginTestCase {

	public function test_assets_are_not_loaded_on_pages_without_a_shortcode(): void {
		$this->queriedPost( '<p>An ordinary blog post.</p>' );

		cfp_ajax_load_scripts();

		$this->assertSame( [], WP_Test_State::$enqueued );
	}

	public function test_assets_are_loaded_on_a_page_that_uses_a_shortcode(): void {
		$this->queriedPost( 'Intro text [cfp_speakers size=20] outro' );

		cfp_ajax_load_scripts();

		$this->assertSame( [ 'site-cfp', 'cfp-dev-style' ], WP_Test_State::$enqueued );
	}

	public function test_every_registered_shortcode_triggers_asset_loading(): void {
		foreach ( cfp_dev_shortcode_tags() as $tag ) {
			WP_Test_State::$enqueued = [];
			$this->queriedPost( '[' . $tag . ']' );

			cfp_ajax_load_scripts();

			$this->assertContains( 'cfp-dev-style', WP_Test_State::$enqueued, $tag . ' did not enqueue the stylesheet' );
		}
	}

	public function test_a_theme_can_force_the_assets_on(): void {
		$this->queriedPost( '<p>Shortcode lives in a widget.</p>' );
		add_filter( 'cfp_dev_enqueue_assets', '__return_true' );

		cfp_ajax_load_scripts();

		$this->assertContains( 'cfp-dev-style', WP_Test_State::$enqueued );
	}

	public function test_the_front_end_script_has_no_library_dependency(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/js/site.js' );

		$this->assertStringNotContainsString( 'jQuery', $source );
		$this->assertStringNotContainsString( '$(', $source );
	}

	public function test_the_stored_theme_is_applied_before_first_paint(): void {
		$this->option( 'cfp_dev_enable_theme_switch', 1 );

		$script = cfp_dev_root_class_script( 'speaker' );

		$this->assertStringContainsString( 'localStorage.getItem("cfp-theme")', $script );
		$this->assertStringContainsString( 'cfp-theme:" + saved', $script );
	}

	public function test_no_storage_lookup_is_emitted_when_switching_is_disabled(): void {
		$this->assertStringNotContainsString( 'localStorage', cfp_dev_root_class_script( 'speaker' ) );
	}

	public function test_embedded_media_is_titled_and_lazy(): void {
		$this->registerDefaultApi();
		$this->search( 'Modern Java in Practice A talk about Java.', [] );
		$this->queryVar( 'id', 200 );

		$html = cfp_talk_details_shortcode();

		preg_match_all( '#<iframe\b[^>]*>#', $html, $matches );
		$this->assertNotEmpty( $matches[0] );

		foreach ( $matches[0] as $iframe ) {
			$this->assertStringContainsString( 'title="', $iframe, 'an iframe without a title is unlabelled for screen readers' );
		}
		$this->assertStringContainsString( 'loading="lazy"', $html );
	}

	// ─────────────────────────────────────────────────────────────────────────

	private function queriedPost( string $content ): void {
		WP_Test_State::$env['post'] = new \WP_Post( $content );
	}
}
