<?php
/**
 * CFP.DEV shortcodes
 *
 * Tests for the rendered Settings → CFP.DEV screen.
 *
 * The submission handler is covered by SettingsTest; this covers the page it
 * is submitted from, which lists the cache entries that exist and therefore
 * reads the same API records the front end does.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Integration;

use CfpDev\Tests\Fixtures;
use CfpDev\Tests\PluginTestCase;

final class AdminSettingsPageTest extends PluginTestCase {

	public function test_the_page_offers_a_delete_button_for_every_cache_that_exists(): void {
		$this->registerDefaultApi();
		set_transient( cfp_dev_detail_cache_key( 'speaker', 100 ), 'html', 60 );
		set_transient( cfp_dev_detail_cache_key( 'talk', 200 ), 'html', 60 );

		$html = $this->render();

		$this->assertStringContainsString( 'Jane Doe', $html );
		$this->assertStringContainsString( 'Modern Java in Practice', $html );
		$this->assertStringContainsString( 'name="delete_cache" value="speaker"', $html );
		$this->assertStringContainsString( 'name="delete_cache" value="talk"', $html );
		// Only the cached entries are listed — 101 and 201 have no transient.
		$this->assertStringNotContainsString( 'Šumailov', $html );
		$this->assertStringNotContainsString( 'Architecture Without Tears', $html );
	}

	public function test_the_page_says_so_when_there_is_nothing_cached(): void {
		$this->registerDefaultApi();

		$html = $this->render();

		$this->assertStringContainsString( 'No speaker detail caches available.', $html );
		$this->assertStringContainsString( 'No talk detail caches available.', $html );
		$this->assertStringContainsString( 'No schedule caches available.', $html );
	}

	/**
	 * The cache tables read id, firstName, lastName and title straight off the
	 * API records. A record missing one turned the settings screen into a page
	 * of PHP warnings — and this screen is where an administrator goes when
	 * something is already wrong.
	 */
	public function test_the_cache_tables_survive_records_with_no_id_or_name(): void {
		$this->registerDefaultApi();
		$this->api(
			'public/speakers?size=' . CFP_DEV_SPEAKERS_FETCH_SIZE,
			[
				[
					'id'        => 100,
					'firstName' => 'Prince',
				],
				[ 'firstName' => 'No Id' ],
			]
		);
		$this->api( 'public/talks', [ [ 'id' => 200 ], [ 'title' => 'No Id' ] ] );
		set_transient( cfp_dev_detail_cache_key( 'speaker', 100 ), 'html', 60 );
		set_transient( cfp_dev_detail_cache_key( 'talk', 200 ), 'html', 60 );

		$html = $this->render();

		$this->assertStringContainsString( 'Prince', $html );
		$this->assertStringContainsString( 'name="delete_cache" value="speaker"', $html );
		$this->assertStringContainsString( 'name="delete_cache" value="talk"', $html );
	}

	/** An unreachable API must not stop the screen that configures it. */
	public function test_the_page_renders_when_the_api_is_unreachable(): void {
		$this->api( 'public/speakers?size=' . CFP_DEV_SPEAKERS_FETCH_SIZE, null, 503 );
		$this->api( 'public/talks', null, 503 );

		$html = $this->render();

		$this->assertStringContainsString( 'CFP.DEV Settings', $html );
		$this->assertStringContainsString( 'No speaker detail caches available.', $html );
		$this->assertStringContainsString( 'No talk detail caches available.', $html );
	}

	public function test_the_offline_section_names_the_active_snapshot(): void {
		$this->registerDefaultApi();
		$this->option(
			'cfp_dev_crawl_state',
			[
				'status'        => 'done',
				'snapshot_name' => '2025-10-06_09-00-00',
			]
		);

		$this->assertStringContainsString( '2025-10-06_09-00-00', $this->render() );
	}

	// ─────────────────────────────────────────────────────────────────────────

	/** Renders the settings screen and returns its markup. */
	private function render(): string {
		ob_start();
		cfp_dev_plugin_options();
		return (string) ob_get_clean();
	}
}
