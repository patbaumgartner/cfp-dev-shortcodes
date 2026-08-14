<?php
/**
 * CFP.DEV shortcodes
 *
 * Guarantees about the test harness itself. A suite that silently exercises
 * the wrong files, or shares mutable state with another checkout, reports
 * results that belong to neither.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Unit;

use CfpDev\Tests\PluginTestCase;

final class TestHarnessTest extends PluginTestCase {

	/**
	 * WordPress addresses the plugin through WP_PLUGIN_DIR, which the
	 * bootstrap links at the checkout under test. The link is repointed on
	 * every run rather than only created when missing: one left behind by a
	 * sibling working tree would make this suite load that tree's code and
	 * report its results as ours.
	 */
	public function test_wordpress_is_pointed_at_this_checkout(): void {
		$this->assertSame(
			realpath( dirname( __DIR__, 2 ) ),
			realpath( WP_PLUGIN_DIR . '/cfp-dev-shortcodes' ),
			'WP_PLUGIN_DIR resolves to a different checkout of the plugin'
		);
	}

	/**
	 * The sandbox holds wp-content, and the offline tests delete and rebuild
	 * `uploads/cfp-dev-offline` around every test. A path shared by every
	 * checkout on the machine therefore makes two concurrent working trees
	 * delete each other's snapshots mid-test.
	 */
	public function test_the_sandbox_root_is_scoped_to_this_checkout(): void {
		$this->assertNotSame(
			sys_get_temp_dir() . '/cfp-dev-tests/wordpress/',
			ABSPATH,
			'the sandbox root is a fixed path shared with every other checkout'
		);

		$this->assertStringStartsWith( ABSPATH, cfp_dev_offline_dir() );
	}

	/**
	 * Hooks are global, and nothing removed the ones a test added, so a filter
	 * registered to prove one behaviour went on quietly changing every test
	 * that ran afterwards — a whole suite's worth of results depending on the
	 * order they happened to run in.
	 */
	public function test_a_test_starts_with_only_the_plugin_hooks_registered(): void {
		$this->assertSame( \WP_Test_State::$plugin_hooks, \WP_Test_State::$hooks );

		add_filter( 'cfp_dev_api_timeout', static fn( $timeout ) => $timeout );

		$this->assertNotSame( \WP_Test_State::$plugin_hooks, \WP_Test_State::$hooks, 'the fixture proves nothing if the filter did not register' );
	}

	/** The plugin's own hooks must survive that reset, or nothing renders. */
	public function test_the_plugin_hooks_themselves_survive(): void {
		$this->assertNotSame( [], \WP_Test_State::$plugin_hooks );
		$this->assertArrayHasKey( 'wp_head', \WP_Test_State::$hooks );
		$this->assertTrue( shortcode_exists( 'cfp_speakers' ) );
	}
}
