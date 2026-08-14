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

	/**
	 * A crawl killed mid-run leaves "running" behind for ever. The screen, the
	 * status the admin script starts from, and the endpoint it polls all have
	 * to agree it stopped — when they disagree the poller wins, because it
	 * repaints the box a second after the page renders.
	 */
	public function test_a_crawl_that_never_finished_is_reported_as_stopped(): void {
		$this->registerDefaultApi();
		$this->option(
			'cfp_dev_crawl_state',
			[
				'status'     => 'running',
				'step_label' => 'Fetching talk details...',
				'started_at' => time() - DAY_IN_SECONDS,
			]
		);

		$this->assertSame( 'stopped', cfp_dev_crawl_display_status() );

		$html = $this->render();
		$this->assertStringContainsString( 'Stopped', $html );
		$this->assertStringNotContainsString( '<progress', $html, 'a bar that will never move again' );
	}

	/** The endpoint the admin script polls must say the same thing. */
	public function test_the_progress_endpoint_agrees_that_a_dead_crawl_stopped(): void {
		$this->option(
			'cfp_dev_crawl_state',
			[
				'status'     => 'running',
				'started_at' => time() - DAY_IN_SECONDS,
			]
		);
		$_POST = [ 'nonce' => 'valid-nonce-cfp_dev_offline_nonce' ];

		try {
			cfp_dev_crawl_progress_handler();
		} catch ( \CfpDev\Tests\JsonResponseSent $sent ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $sent );
		}

		$this->assertSame( 'stopped', \WP_Test_State::$json_responses[0]['data']['status'] );
		$_POST = [];
	}

	/** A crawl that really is running still reports itself as running. */
	public function test_a_live_crawl_is_still_reported_as_running(): void {
		$this->option(
			'cfp_dev_crawl_state',
			[
				'status'     => 'running',
				'started_at' => time(),
			]
		);

		$this->assertSame( 'running', cfp_dev_crawl_display_status() );
	}

	/**
	 * Both admin scripts write text the operator reads, and the crawler one
	 * overwrites the server-rendered status box outright — so a string it
	 * carries in English undoes the translation of the screen around it.
	 * They are handed their strings from PHP instead, which also puts them in
	 * the .pot the drift guard checks.
	 *
	 * @dataProvider adminScriptProvider
	 */
	public function test_the_admin_scripts_are_handed_their_strings( string $handle, string $js_object, array $expected ): void {
		cfp_dev_enqueue_admin_scripts( 'settings_page_cfp-dev-settings' );

		$payload = \WP_Test_State::$env['localized'][ $handle ][ $js_object ] ?? [];

		$this->assertArrayHasKey( 'i18n', $payload, $handle . ' carries no strings' );
		foreach ( $expected as $key ) {
			$this->assertArrayHasKey( $key, $payload['i18n'] );
			$this->assertNotSame( '', $payload['i18n'][ $key ] );
		}
	}

	public static function adminScriptProvider(): array {
		return [
			'cache management' => [ 'cfp-dev-admin-cache', 'cfp_dev_ajax', [ 'deleting', 'errorWith', 'unknownError', 'requestFailed' ] ],
			'offline crawler'  => [
				'cfp-dev-admin-offline',
				'cfp_dev_offline_ajax',
				[ 'statusLabel', 'running', 'pending', 'complete', 'error', 'stopped', 'stoppedHint', 'progress', 'warnings', 'confirmCrawl', 'recrawl', 'startFailed' ],
			],
		];
	}

	/** The script starts from the status the screen showed, not the stored one. */
	public function test_the_crawler_script_starts_from_the_reported_status(): void {
		$this->option(
			'cfp_dev_crawl_state',
			[
				'status'     => 'running',
				'started_at' => time() - DAY_IN_SECONDS,
			]
		);

		cfp_dev_enqueue_admin_scripts( 'settings_page_cfp-dev-settings' );

		$payload = \WP_Test_State::$env['localized']['cfp-dev-admin-offline']['cfp_dev_offline_ajax'];

		$this->assertSame( 'stopped', $payload['initial_status'], 'the script would poll a crawl that is not running' );
	}

	public function test_no_admin_script_loads_outside_the_settings_screen(): void {
		cfp_dev_enqueue_admin_scripts( 'options-general.php' );

		$this->assertSame( [], \WP_Test_State::$env['localized'] ?? [] );
	}

	// ─────────────────────────────────────────────────────────────────────────

	/** Renders the settings screen and returns its markup. */
	private function render(): string {
		ob_start();
		cfp_dev_plugin_options();
		return (string) ob_get_clean();
	}
}
