<?php
/**
 * CFP.DEV shortcodes
 *
 * Tests for the settings-page submission handler and the option accessors
 * that guard what may be stored.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Unit;

use CfpDev\Tests\PluginTestCase;
use WP_Test_State;

final class SettingsTest extends PluginTestCase {

	/** A complete General Settings submission. */
	private const GENERAL_FORM = [
		'cfp_dev_key'           => 'dvbe25',
		'cfp_dev_event_name'    => 'Devoxx Belgium 2025',
		'cfp_dev_cache'         => '3600',
		'cfp_dev_default_theme' => 'light',
		'cfp_dev_content_by_id' => 'no',
		'cfp_dev_show_rooms'    => 'yes',
		'cfp_dev_path_prefix'   => '',
	];

	public function test_general_settings_are_stored(): void {
		$notice = cfp_dev_handle_settings_post( self::GENERAL_FORM );

		$this->assertSame( 'Settings saved.', $notice );
		$this->assertSame( 'dvbe25', cfp_dev_get_key() );
		$this->assertSame( 'Devoxx Belgium 2025', cfp_dev_get_event_name() );
		$this->assertSame( 3600, cfp_dev_get_cache_ttl() );
		$this->assertSame( 'light', get_option( 'cfp_dev_default_theme' ) );
	}

	/**
	 * @dataProvider hostileChoiceProvider
	 */
	public function test_choice_settings_reject_values_outside_their_allow_list( string $field, string $expected ): void {
		cfp_dev_handle_settings_post( array_merge( self::GENERAL_FORM, [ $field => 'dark" onload=alert(1) x' ] ) );

		$this->assertSame( $expected, get_option( $field ) );
	}

	public static function hostileChoiceProvider(): array {
		return [
			[ 'cfp_dev_default_theme', 'dark' ],
			[ 'cfp_dev_content_by_id', 'yes' ],
			[ 'cfp_dev_show_rooms', 'yes' ],
		];
	}

	public function test_an_out_of_range_theme_never_reaches_the_root_class_script(): void {
		// Defence in depth: a value written before validation existed.
		$this->option( 'cfp_dev_default_theme', 'evil theme' );

		$this->assertStringContainsString( '"cfp-theme:dark"', cfp_dev_root_class_script( 'speaker' ) );
		$this->assertStringNotContainsString( 'evil theme', cfp_dev_root_class_script( 'speaker' ) );
	}

	/**
	 * @dataProvider pathPrefixProvider
	 */
	public function test_path_prefix_is_reduced_to_url_slugs( string $input, string $expected ): void {
		$this->assertSame( $expected, cfp_dev_sanitize_path_prefix( $input ) );
	}

	public static function pathPrefixProvider(): array {
		return [
			'plain'            => [ 'trieste', 'trieste' ],
			'cased and padded' => [ '  /Trieste/ ', 'trieste' ],
			'nested'           => [ 'events/trieste', 'events/trieste' ],
			'regex metachars'  => [ 'a.*(b)|c', 'abc' ],
			'traversal'        => [ '../../etc', 'etc' ],
			'empty'            => [ '', '' ],
		];
	}

	public function test_changing_the_path_prefix_rebuilds_the_rewrite_rules(): void {
		cfp_dev_handle_settings_post( array_merge( self::GENERAL_FORM, [ 'cfp_dev_path_prefix' => 'Trieste' ] ) );

		$this->assertSame( 'trieste', get_option( 'cfp_dev_path_prefix' ) );
		$this->assertSame( 1, WP_Test_State::$env['rewrite_flushes'] ?? 0 );
		$this->assertArrayHasKey( '^trieste/speaker/([^/]+)/?$', WP_Test_State::$env['rewrite_rules'] );
	}

	public function test_an_unchanged_path_prefix_does_not_flush_rewrite_rules(): void {
		$this->option( 'cfp_dev_path_prefix', 'trieste' );

		cfp_dev_handle_settings_post( array_merge( self::GENERAL_FORM, [ 'cfp_dev_path_prefix' => 'trieste' ] ) );

		$this->assertSame( 0, WP_Test_State::$env['rewrite_flushes'] ?? 0 );
	}

	public function test_saving_settings_invalidates_rendered_html(): void {
		// "Show Rooms" and "Permalinks with Id" are baked into cached markup.
		cfp_dev_handle_settings_post( array_merge( self::GENERAL_FORM, [ 'cfp_dev_show_rooms' => 'no' ] ) );

		$this->assertSame( 2, (int) get_option( 'cfp_dev_cache_version' ) );
	}

	public function test_the_theme_switch_checkbox_persists_when_unchecked(): void {
		cfp_dev_handle_settings_post( array_merge( self::GENERAL_FORM, [ 'enable_theme_switch' => '1' ] ) );
		$this->assertTrue( cfp_dev_theme_switch_enabled() );

		cfp_dev_handle_settings_post( self::GENERAL_FORM );
		$this->assertFalse( cfp_dev_theme_switch_enabled() );
	}

	public function test_the_legacy_unprefixed_theme_switch_option_is_migrated(): void {
		WP_Test_State::$options['enable_theme_switch'] = 1;

		$this->assertTrue( cfp_dev_theme_switch_enabled() );
		$this->assertSame( 1, get_option( 'cfp_dev_enable_theme_switch' ) );
		$this->assertFalse( get_option( 'enable_theme_switch' ), 'the squatted option name must be released' );
	}

	public function test_a_disabled_legacy_theme_switch_stays_disabled_after_migration(): void {
		WP_Test_State::$options['enable_theme_switch'] = 0;

		$this->assertFalse( cfp_dev_theme_switch_enabled() );
		$this->assertSame( 0, get_option( 'cfp_dev_enable_theme_switch' ) );
	}

	public function test_deleting_a_named_cache_reports_what_was_removed(): void {
		set_transient( cfp_dev_group_cache_key( 'cfp_schedule_Tuesday' ), 'html', 60 );

		$notice = cfp_dev_handle_settings_post(
			[
				'delete_cache' => 'schedule',
				'cache_day'    => 'tuesday',
			]
		);

		$this->assertSame( 'Schedule cache for Tuesday deleted.', $notice );
		$this->assertFalse( get_transient( cfp_dev_group_cache_key( 'cfp_schedule_Tuesday' ) ) );
	}

	public function test_a_day_outside_the_week_deletes_nothing(): void {
		$notice = cfp_dev_handle_settings_post(
			[
				'delete_cache' => 'schedule',
				'cache_day'    => 'someday',
			]
		);

		$this->assertSame( '', $notice );
	}

	public function test_deleting_a_talk_cache_also_drops_its_photo_cache(): void {
		set_transient( generate_cfp_cache_key( 'talk', 7 ), 'html', 60 );
		set_transient( generate_cfp_cache_key( 'photo', 7 ), 'html', 60 );

		cfp_dev_handle_settings_post(
			[
				'delete_cache' => 'talk',
				'cache_id'     => '7',
			]
		);

		$this->assertFalse( get_transient( generate_cfp_cache_key( 'talk', 7 ) ) );
		$this->assertFalse( get_transient( generate_cfp_cache_key( 'photo', 7 ) ) );
	}

	public function test_disabling_offline_mode_re_renders_snapshot_backed_html(): void {
		$this->option( 'cfp_dev_offline_mode', 1 );

		cfp_dev_handle_settings_post( [ 'cfp_dev_offline_mode_save' => '1' ] );

		$this->assertSame( 0, (int) get_option( 'cfp_dev_offline_mode' ) );
		$this->assertSame( 2, (int) get_option( 'cfp_dev_cache_version' ) );
	}

	public function test_enabling_offline_mode_schedules_a_crawl(): void {
		cfp_dev_handle_settings_post(
			[
				'cfp_dev_offline_mode_save' => '1',
				'cfp_dev_offline_mode'      => '1',
			]
		);

		$this->assertSame( 'cfp_dev_do_crawl', WP_Test_State::$env['scheduled'][0]['hook'] );
		$this->assertSame( 'pending', get_option( 'cfp_dev_crawl_state' )['status'] );
	}

	public function test_a_second_enable_does_not_start_a_competing_crawl(): void {
		$this->option( 'cfp_dev_crawl_state', [ 'status' => 'running' ] );

		cfp_dev_handle_settings_post(
			[
				'cfp_dev_offline_mode_save' => '1',
				'cfp_dev_offline_mode'      => '1',
			]
		);

		$this->assertSame( [], WP_Test_State::$env['scheduled'] ?? [] );
	}

	public function test_updating_the_plugin_invalidates_cached_markup(): void {
		$this->option( 'cfp_dev_installed_version', '0.0.1' );

		cfp_dev_maybe_upgrade();

		$this->assertSame( CFP_DEV_VERSION, get_option( 'cfp_dev_installed_version' ) );
		$this->assertSame( 2, (int) get_option( 'cfp_dev_cache_version' ) );
	}

	public function test_an_unchanged_version_leaves_caches_alone(): void {
		$this->option( 'cfp_dev_installed_version', CFP_DEV_VERSION );

		cfp_dev_maybe_upgrade();

		$this->assertSame( 1, (int) get_option( 'cfp_dev_cache_version' ) );
	}

	public function test_an_unrelated_post_changes_nothing(): void {
		$before = WP_Test_State::$options;

		$this->assertSame( '', cfp_dev_handle_settings_post( [ 'something_else' => '1' ] ) );
		$this->assertSame( $before, WP_Test_State::$options );
	}
}
