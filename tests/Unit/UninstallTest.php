<?php
/**
 * CFP.DEV shortcodes
 *
 * Tests for uninstall.php — the one code path that deletes user data, and the
 * one that never runs during normal development.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Unit;

use CfpDev\Tests\PluginTestCase;
use WP_Test_State;
use WP_Test_Wpdb;

final class UninstallTest extends PluginTestCase {

	private const PLUGIN_OPTIONS = [
		'cfp_dev_key',
		'cfp_dev_event_name',
		'cfp_dev_cache_duration',
		'cfp_dev_cache_version',
		'cfp_dev_installed_version',
		'cfp_dev_default_theme',
		'cfp_dev_enable_theme_switch',
		'cfp_dev_path_prefix',
		'cfp_dev_content_by_id',
		'cfp_dev_show_rooms',
		'cfp_dev_offline_mode',
		'cfp_dev_crawl_state',
	];

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'cfp-dev-shortcodes/cfp-dev-wordpress-shortcodes.php' );
		}

		$GLOBALS['wp_filesystem'] = null;
		$GLOBALS['wpdb']          = new WP_Test_Wpdb();
	}

	protected function tearDown(): void {
		WP_Test_State::$env['multisite'] = false;
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_every_plugin_option_is_removed(): void {
		foreach ( self::PLUGIN_OPTIONS as $option ) {
			update_option( $option, 'set' );
		}
		update_option( 'enable_theme_switch', 1 );

		$this->runUninstall();

		foreach ( array_merge( self::PLUGIN_OPTIONS, [ 'enable_theme_switch' ] ) as $option ) {
			$this->assertFalse( get_option( $option ), $option . ' survived uninstall' );
		}
	}

	public function test_options_belonging_to_other_plugins_are_left_alone(): void {
		update_option( 'some_other_plugin_setting', 'keep me' );
		update_option( 'blogname', 'My Site' );

		$this->runUninstall();

		$this->assertSame( 'keep me', get_option( 'some_other_plugin_setting' ) );
		$this->assertSame( 'My Site', get_option( 'blogname' ) );
	}

	public function test_plugin_transients_are_removed_and_others_kept(): void {
		set_transient( 'cfp_talk_details_abc_v1', 'markup', 3600 );
		set_transient( 'speaker_photos_abc_v1', 'gallery', 3600 );
		set_transient( 'speakers_cache_group_abc_v1', 'grid', 3600 );
		set_transient( 'unrelated_plugin_cache', 'keep me', 3600 );

		$this->runUninstall();

		$this->assertFalse( get_transient( 'cfp_talk_details_abc_v1' ) );
		$this->assertFalse( get_transient( 'speaker_photos_abc_v1' ) );
		$this->assertFalse( get_transient( 'speakers_cache_group_abc_v1' ) );
		$this->assertSame( 'keep me', get_transient( 'unrelated_plugin_cache' ) );
	}

	/**
	 * Uninstall deletes cache entries by matching name prefixes in SQL, which
	 * only works for the prefixes somebody remembered to list. The keys here
	 * are built by the plugin's own key functions rather than written out, so
	 * a cache group whose name does not match what uninstall looks for is
	 * caught here instead of being left behind on every site that removes the
	 * plugin.
	 *
	 * @dataProvider cacheKeyProvider
	 */
	public function test_every_key_the_plugin_writes_is_removed( string $key ): void {
		set_transient( $key, 'cached', 3600 );

		$this->runUninstall();

		$this->assertFalse( get_transient( $key ), $key . ' survived uninstall' );
	}

	public static function cacheKeyProvider(): array {
		$keys = [
			'entity'            => cfp_dev_group_cache_key( 'cfp_entity_talk_' . md5( '200' ) ),
			'known speaker ids' => cfp_dev_group_cache_key( 'cfp_known_speaker_ids' ),
			'track meta'        => cfp_dev_group_cache_key( 'cfp_meta_tracks_10' ),
			'session meta'      => cfp_dev_group_cache_key( 'cfp_meta_sessions_20' ),
			'sitemap'           => cfp_dev_group_cache_key( 'cfp_sitemap_urls' ),
			'speaker slug'      => cfp_dev_group_cache_key( 'cfp_speaker_slug_' . md5( 'jane-doe' ) ),
			'talk slug'         => cfp_dev_group_cache_key( 'cfp_talk_slug_' . md5( 'a-talk' ) ),
			'talks by track'    => cfp_dev_group_cache_key( 'talks_by_tracks_cache_group_10' ),
			'talks by session'  => cfp_dev_group_cache_key( 'talks_by_sessions_cache_group_20' ),
			'schedule day'      => cfp_dev_schedule_cache_key( 'Monday', [], [] ),
			'speaker grid'      => cfp_dev_speakers_cache_key( cfp_dev_speakers_default_atts() ),
			'speaker detail'    => cfp_dev_detail_cache_key( 'speaker', 100 ),
			'talk detail'       => cfp_dev_detail_cache_key( 'talk', 200 ),
			'photo gallery'     => cfp_dev_detail_cache_key( 'photo', 100 ),
		];

		return array_map( static fn( string $key ): array => [ $key ], $keys );
	}

	public function test_legacy_settings_transients_are_removed(): void {
		set_transient( 'CFP_DEV_KEY', 'dvbe23', 0 );
		set_transient( 'CFP_DEV_CACHE', 3600, 0 );
		set_transient( 'CFP_DEV_EVENT_NAME', 'Devoxx', 0 );

		$this->runUninstall();

		$this->assertFalse( get_transient( 'CFP_DEV_KEY' ) );
		$this->assertFalse( get_transient( 'CFP_DEV_CACHE' ) );
		$this->assertFalse( get_transient( 'CFP_DEV_EVENT_NAME' ) );
	}

	public function test_a_pending_crawl_is_unscheduled(): void {
		wp_schedule_single_event( time() + 60, 'cfp_dev_do_crawl' );
		$this->assertNotFalse( wp_next_scheduled( 'cfp_dev_do_crawl' ) );

		$this->runUninstall();

		$this->assertFalse(
			wp_next_scheduled( 'cfp_dev_do_crawl' ),
			'the crawl callback disappears with the plugin; the event must not outlive it'
		);
	}

	public function test_on_multisite_every_site_is_cleaned(): void {
		WP_Test_State::$env['multisite'] = true;
		WP_Test_State::$env['site_ids']  = [ 1, 2, 3 ];
		update_option( 'cfp_dev_key', 'dvbe23' );

		$this->runUninstall();

		// The stub shares one option store, so the assertion that matters is
		// that every site was visited rather than only the current one.
		$this->assertSame( 1, WP_Test_State::$env['current_blog'] );
		$this->assertFalse( get_option( 'cfp_dev_key' ) );
	}

	private function runUninstall(): void {
		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}
}
