<?php
/**
 * CFP.DEV shortcodes
 *
 * Tests for the activation lifecycle: the pages a fresh install depends on,
 * and the rewrite rules that route their pretty URLs.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Integration;

use CfpDev\Tests\PluginTestCase;
use WP_Test_State;

final class ActivationTest extends PluginTestCase {

	/** Slug => the shortcode that page must contain. */
	private const REQUIRED_PAGES = [
		'speakers'          => '[cfp_speakers]',
		'speaker'           => '[cfp_speaker_details]',
		'talk'              => '[cfp_talk_details]',
		'schedule'          => '[cfp_schedule]',
		'search-results'    => '[cfp_search_results]',
		'talks-by-tracks'   => '[cfp_talks_by_tracks]',
		'talks-by-sessions' => '[cfp_talks_by_sessions]',
	];

	protected function setUp(): void {
		parent::setUp();
		WP_Test_State::$env['pages']          = [];
		WP_Test_State::$env['inserted_posts'] = [];
	}

	public function test_activation_creates_every_page_the_plugin_needs(): void {
		cfp_dev_create_required_pages();

		$created = [];
		foreach ( WP_Test_State::$env['inserted_posts'] as $post ) {
			$created[ $post['post_name'] ] = $post;
		}

		$this->assertSame(
			array_keys( self::REQUIRED_PAGES ),
			array_keys( $created ),
			'a missing page makes the corresponding shortcode unreachable on a fresh install'
		);

		foreach ( self::REQUIRED_PAGES as $slug => $shortcode ) {
			$this->assertSame( $shortcode, $created[ $slug ]['post_content'], $slug . ' has the wrong shortcode' );
			$this->assertSame( 'page', $created[ $slug ]['post_type'] );
			$this->assertSame( 'publish', $created[ $slug ]['post_status'] );
		}
	}

	public function test_every_created_page_matches_a_registered_shortcode(): void {
		cfp_dev_create_required_pages();

		foreach ( WP_Test_State::$env['inserted_posts'] as $post ) {
			preg_match( '/\[([a-z_]+)\]/', $post['post_content'], $matches );
			$this->assertContains(
				$matches[1],
				cfp_dev_shortcode_tags(),
				$post['post_name'] . ' embeds a shortcode the plugin does not register'
			);
		}
	}

	public function test_reactivation_does_not_duplicate_existing_pages(): void {
		// get_page_by_path() also finds drafts and private pages, so a page the
		// site owner unpublished must not be silently recreated alongside.
		WP_Test_State::$env['pages']['speakers'] = (object) [ 'ID' => 5 ];
		WP_Test_State::$env['pages']['talk']     = (object) [ 'ID' => 6 ];

		cfp_dev_create_required_pages();

		$created = array_column( WP_Test_State::$env['inserted_posts'], 'post_name' );
		$this->assertNotContains( 'speakers', $created );
		$this->assertNotContains( 'talk', $created );
		$this->assertCount( count( self::REQUIRED_PAGES ) - 2, $created );
	}

	public function test_activation_registers_the_detail_page_rewrite_rules(): void {
		cfp_dev_flush_rewrite_rules();

		$patterns = array_keys( WP_Test_State::$env['rewrite_rules'] ?? [] );
		$this->assertNotEmpty( $patterns, 'without rewrite rules the pretty /speaker/<slug> URLs 404' );

		$this->assertTrue(
			(bool) array_filter( $patterns, static fn( $p ) => str_contains( $p, 'speaker/' ) ),
			'no speaker slug rule registered'
		);
		$this->assertTrue(
			(bool) array_filter( $patterns, static fn( $p ) => str_contains( $p, 'talk/' ) ),
			'no talk slug rule registered'
		);
	}

	public function test_the_slug_rules_route_to_the_matching_query_vars(): void {
		cfp_dev_add_rewrite_rules();

		$rules = WP_Test_State::$env['rewrite_rules'] ?? [];
		foreach ( $rules as $regex => $query ) {
			if ( str_contains( $regex, 'speaker/' ) ) {
				$this->assertStringContainsString( 'speaker_slug=', $query );
			}
		}
		$this->assertContains( 'index.php?pagename=speaker&speaker_slug=$matches[1]', array_values( $rules ) );
	}

	public function test_the_path_prefix_is_applied_to_the_rewrite_rules(): void {
		$this->option( 'cfp_dev_path_prefix', 'trieste' );

		cfp_dev_add_rewrite_rules();

		$patterns = array_keys( WP_Test_State::$env['rewrite_rules'] ?? [] );
		$this->assertTrue(
			(bool) array_filter( $patterns, static fn( $p ) => str_contains( $p, 'trieste/speaker/' ) ),
			'subdirectory installs would 404 on every speaker URL'
		);
	}

	/**
	 * A prefixed install also gets an alias for talk URLs carrying one leading
	 * path segment. See the note in rewrite.php: the analysis says it is
	 * redundant, but it has not been checked against the deployment it was
	 * written for, so it is pinned here rather than removed on reasoning
	 * alone. Any change to it should be a deliberate one.
	 */
	public function test_a_prefixed_install_keeps_its_extra_talk_alias(): void {
		$this->option( 'cfp_dev_path_prefix', 'trieste' );

		cfp_dev_add_rewrite_rules();

		$rules = WP_Test_State::$env['rewrite_rules'] ?? [];

		$this->assertArrayHasKey( '([^/]+)/trieste/talk/([^/]+)/?$', $rules );
		$this->assertSame( 'index.php?pagename=talk&talk_slug=$matches[2]', $rules['([^/]+)/trieste/talk/([^/]+)/?$'] );
	}

	/** Without a prefix there is nothing to alias, and no such rule is added. */
	public function test_an_unprefixed_install_adds_no_alias(): void {
		cfp_dev_add_rewrite_rules();

		foreach ( array_keys( WP_Test_State::$env['rewrite_rules'] ?? [] ) as $pattern ) {
			$this->assertStringStartsWith( '^', $pattern, 'every rule should be anchored at the site root' );
		}
	}

	/**
	 * The crawl runs from a cron event whose callback disappears with the
	 * plugin, and the rewrite rules keep capturing /talk/ and /speaker/ URLs
	 * that nothing renders any more. Deactivation is not uninstallation, so
	 * settings, caches and snapshots stay.
	 */
	public function test_deactivating_leaves_no_scheduled_crawl_behind(): void {
		wp_schedule_single_event( time() + 60, 'cfp_dev_do_crawl' );
		$this->option( 'cfp_dev_key', 'dvbe25' );
		set_transient( cfp_dev_detail_cache_key( 'talk', 200 ), 'markup', 3600 );

		cfp_dev_deactivate();

		$this->assertFalse( wp_next_scheduled( 'cfp_dev_do_crawl' ) );
		$this->assertSame( 'dvbe25', get_option( 'cfp_dev_key' ), 'deactivation is not uninstallation' );
		$this->assertNotFalse( get_transient( cfp_dev_detail_cache_key( 'talk', 200 ) ) );
	}

	/**
	 * The sitemap lists slug URLs, so on a site addressing content by id it
	 * would advertise a permalink form that site does not link to.
	 *
	 * @dataProvider sitemapModeProvider
	 */
	public function test_the_sitemap_provider_registers_only_in_slug_mode( string $by_id, bool $expected ): void {
		$this->option( 'cfp_dev_content_by_id', $by_id );

		$registered = [];
		$sitemaps   = new class( $registered ) {
			public object $registry;

			public function __construct( array &$registered ) {
				$this->registry = new class( $registered ) {
					/** @var array<string,object> */
					private array $seen;

					public function __construct( array &$registered ) {
						$this->seen = &$registered;
					}

					public function add_provider( string $name, object $provider ): void {
						$this->seen[ $name ] = $provider;
					}
				};
			}
		};

		cfp_dev_register_sitemap_provider( $sitemaps );

		$this->assertSame( $expected, isset( $registered['cfp'] ) );
	}

	public static function sitemapModeProvider(): array {
		return [
			'slug mode' => [ 'no', true ],
			'id mode'   => [ 'yes', false ],
		];
	}
}
