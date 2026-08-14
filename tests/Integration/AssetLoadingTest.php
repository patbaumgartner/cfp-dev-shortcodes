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

		cfp_dev_enqueue_front_end_assets();

		$this->assertSame( [], WP_Test_State::$enqueued );
	}

	public function test_assets_are_loaded_on_a_page_that_uses_a_shortcode(): void {
		$this->queriedPost( 'Intro text [cfp_speakers size=20] outro' );

		cfp_dev_enqueue_front_end_assets();

		$this->assertSame( [ 'site-cfp', 'cfp-dev-style' ], WP_Test_State::$enqueued );
	}

	/**
	 * A theme may render the shortcode from a template and leave the page
	 * content empty — several do. has_shortcode() cannot see that, so the
	 * assets silently stopped loading and the plugin's pages, which exist for
	 * no other purpose, rendered unstyled on those sites.
	 *
	 * @dataProvider pluginPageProvider
	 */
	public function test_the_plugins_own_pages_load_the_assets_however_the_theme_renders_them( string $slug ): void {
		$this->queriedPost( '' );
		$this->onPage( $slug );

		cfp_dev_enqueue_front_end_assets();

		$this->assertSame( [ 'site-cfp', 'cfp-dev-style' ], WP_Test_State::$enqueued, $slug . ' rendered without its stylesheet' );
	}

	public static function pluginPageProvider(): array {
		return array_map( static fn( string $slug ): array => [ $slug ], cfp_dev_page_slugs() );
	}

	/** A page the plugin does not own still has to say so in its content. */
	public function test_an_unrelated_empty_page_still_loads_nothing(): void {
		$this->queriedPost( '' );
		$this->onPage( 'about' );

		cfp_dev_enqueue_front_end_assets();

		$this->assertSame( [], WP_Test_State::$enqueued );
	}

	public function test_the_front_end_script_is_registered_without_jquery_and_version_busted(): void {
		$this->queriedPost( '[cfp_speakers]' );

		cfp_dev_enqueue_front_end_assets();

		$script = WP_Test_State::$enqueued_assets['site-cfp'];
		$this->assertStringEndsWith( 'js/site.js', $script['src'] );
		$this->assertSame( [], $script['deps'], 'the front-end script must stay dependency-free' );
		$this->assertSame( CFP_DEV_VERSION, $script['ver'], 'without a version bump browsers serve stale JS after an update' );
		$this->assertTrue( (bool) $script['args'], 'the script belongs in the footer' );
	}

	public function test_the_stylesheet_is_registered_with_the_plugin_version(): void {
		$this->queriedPost( '[cfp_speakers]' );

		cfp_dev_enqueue_front_end_assets();

		$style = WP_Test_State::$enqueued_assets['cfp-dev-style'];
		$this->assertStringEndsWith( CFP_DEV_CSS, $style['src'] );
		$this->assertSame( CFP_DEV_VERSION, $style['ver'] );
	}

	public function test_every_registered_shortcode_triggers_asset_loading(): void {
		foreach ( cfp_dev_shortcode_tags() as $tag ) {
			WP_Test_State::$enqueued = [];
			$this->queriedPost( '[' . $tag . ']' );

			cfp_dev_enqueue_front_end_assets();

			$this->assertContains( 'cfp-dev-style', WP_Test_State::$enqueued, $tag . ' did not enqueue the stylesheet' );
		}
	}

	public function test_a_theme_can_force_the_assets_on(): void {
		$this->queriedPost( '<p>Shortcode lives in a widget.</p>' );
		add_filter( 'cfp_dev_enqueue_assets', '__return_true' );

		cfp_dev_enqueue_front_end_assets();

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

		$html = cfp_dev_talk_details_shortcode();

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
