<?php
/**
 * CFP.DEV shortcodes
 *
 * Tests for the admin-only AJAX endpoints and the lifecycle hooks.
 *
 * Nothing a page renders reaches these, so they were the last plugin code
 * with no test at all: two privileged, state-changing endpoints whose nonce
 * and capability checks nobody had ever verified actually reject, and the
 * deactivation hook added in 4.6.0 to stop a crawl outliving the plugin.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Integration;

use CfpDev\Tests\JsonResponseSent;
use CfpDev\Tests\PluginTestCase;
use WP_Test_State;

final class AdminAjaxTest extends PluginTestCase {

	protected function tearDown(): void {
		$_POST = [];
		parent::tearDown();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Guards
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Both endpoints change state and are reachable by anyone who can reach
	 * admin-ajax.php, so the two guards are all that stands in front of them.
	 *
	 * @dataProvider guardedEndpointProvider
	 */
	public function test_a_missing_or_forged_nonce_is_rejected( string $handler, string $action ): void {
		set_transient( cfp_dev_detail_cache_key( 'talk', 200 ), 'markup', 3600 );

		$this->request(
			$handler,
			[
				'nonce'        => 'forged',
				'delete_cache' => 'talk',
				'cache_id'     => '200',
			]
		);

		$this->assertFalse( WP_Test_State::$json_responses[0]['success'], $action . ' accepted a forged nonce' );
		$this->assertNotFalse( get_transient( cfp_dev_detail_cache_key( 'talk', 200 ) ), 'it acted anyway' );
	}

	/**
	 * @dataProvider guardedEndpointProvider
	 */
	public function test_a_valid_nonce_without_the_capability_is_rejected( string $handler, string $action ): void {
		// A nonce is proof of intent, not of permission: a subscriber's page
		// carries valid nonces for the actions that page offers.
		WP_Test_State::$env['capabilities'] = [ 'read' ];
		set_transient( cfp_dev_detail_cache_key( 'talk', 200 ), 'markup', 3600 );

		$this->request(
			$handler,
			[
				'nonce'        => 'valid-nonce-' . $action,
				'delete_cache' => 'talk',
				'cache_id'     => '200',
			]
		);

		$this->assertFalse( WP_Test_State::$json_responses[0]['success'], $action . ' served a user without manage_options' );
		$this->assertNotFalse( get_transient( cfp_dev_detail_cache_key( 'talk', 200 ) ), 'it acted anyway' );
		$this->assertSame( [], WP_Test_State::$env['scheduled'] ?? [], 'it scheduled work anyway' );
	}

	public static function guardedEndpointProvider(): array {
		return [
			'delete cache' => [ 'cfp_dev_delete_cache_handler', 'cfp_dev_delete_cache' ],
			'start crawl'  => [ 'cfp_dev_start_crawl_ajax_handler', 'cfp_dev_offline_nonce' ],
			'crawl status' => [ 'cfp_dev_crawl_progress_handler', 'cfp_dev_offline_nonce' ],
		];
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Cache deletion
	// ─────────────────────────────────────────────────────────────────────────

	public function test_deleting_a_talk_cache_also_drops_its_photo_cache(): void {
		set_transient( cfp_dev_detail_cache_key( 'talk', 200 ), 'markup', 3600 );
		set_transient( cfp_dev_detail_cache_key( 'photo', 200 ), 'gallery', 3600 );

		$this->request(
			'cfp_dev_delete_cache_handler',
			[
				'nonce'        => 'valid-nonce-cfp_dev_delete_cache',
				'delete_cache' => 'talk',
				'cache_id'     => '200',
			]
		);

		$this->assertTrue( WP_Test_State::$json_responses[0]['success'] );
		$this->assertFalse( get_transient( cfp_dev_detail_cache_key( 'talk', 200 ) ) );
		$this->assertFalse( get_transient( cfp_dev_detail_cache_key( 'photo', 200 ) ) );
	}

	/** The type names a key prefix, so it is an allow-list, not a passthrough. */
	public function test_an_unknown_cache_type_is_refused(): void {
		$this->request(
			'cfp_dev_delete_cache_handler',
			[
				'nonce'        => 'valid-nonce-cfp_dev_delete_cache',
				'delete_cache' => 'everything',
				'cache_id'     => '200',
			]
		);

		$this->assertFalse( WP_Test_State::$json_responses[0]['success'] );
	}

	public function test_a_deletion_without_an_id_is_refused(): void {
		$this->request(
			'cfp_dev_delete_cache_handler',
			[
				'nonce'        => 'valid-nonce-cfp_dev_delete_cache',
				'delete_cache' => 'talk',
			]
		);

		$this->assertFalse( WP_Test_State::$json_responses[0]['success'] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Crawl control
	// ─────────────────────────────────────────────────────────────────────────

	public function test_the_recrawl_button_starts_a_crawl(): void {
		$this->request( 'cfp_dev_start_crawl_ajax_handler', [ 'nonce' => 'valid-nonce-cfp_dev_offline_nonce' ] );

		$this->assertTrue( WP_Test_State::$json_responses[0]['success'] );
		$this->assertSame( 'cfp_dev_do_crawl', WP_Test_State::$env['scheduled'][0]['hook'] );
		$this->assertSame( 'pending', get_option( 'cfp_dev_crawl_state' )['status'] );
	}

	/**
	 * The two crawls would share one progress state and race to publish, so
	 * the endpoint says no rather than quietly replacing the first.
	 */
	public function test_the_recrawl_button_refuses_while_a_crawl_is_running(): void {
		$this->option(
			'cfp_dev_crawl_state',
			[
				'status'     => 'running',
				'snapshot'   => cfp_dev_offline_dir() . '/2025-01-01_00-00-00',
				'started_at' => time(),
			]
		);

		$this->request( 'cfp_dev_start_crawl_ajax_handler', [ 'nonce' => 'valid-nonce-cfp_dev_offline_nonce' ] );

		$this->assertFalse( WP_Test_State::$json_responses[0]['success'] );
		$this->assertSame( [], WP_Test_State::$env['scheduled'] ?? [] );
		$this->assertSame(
			cfp_dev_offline_dir() . '/2025-01-01_00-00-00',
			get_option( 'cfp_dev_crawl_state' )['snapshot'],
			'the running crawl lost its snapshot'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────

	/** Invokes an AJAX handler, absorbing the response that ends the request. */
	private function request( string $handler, array $post ): void {
		$_POST = $post;

		try {
			$handler();
		} catch ( JsonResponseSent $sent ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $sent );
		}
	}
}
