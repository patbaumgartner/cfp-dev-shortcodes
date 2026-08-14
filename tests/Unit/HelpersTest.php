<?php
/**
 * CFP.DEV shortcodes
 *
 * Unit tests for the plugin's pure helpers: slugs, excerpts, URL building,
 * attribute coercion and cache keys.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Unit;

use CfpDev\Tests\PluginTestCase;

final class HelpersTest extends PluginTestCase {

	/**
	 * @dataProvider slugProvider
	 */
	public function test_generate_slug_produces_url_safe_lowercase_slugs( string $input, string $expected ): void {
		$this->assertSame( $expected, cfp_dev_generate_slug( $input ) );
	}

	public static function slugProvider(): array {
		return [
			'plain'                 => [ 'Modern Java in Practice', 'modern-java-in-practice' ],
			'punctuation'           => [ 'Kafka: the good parts!', 'kafka-the-good-parts' ],
			'accents transliterate' => [ 'Ilya-Šumailov', 'ilya-sumailov' ],
			'umlauts'               => [ 'Jürgen Höller', 'jurgen-holler' ],
			'collapses dashes'      => [ 'A -- B', 'a-b' ],
			'trims dashes'          => [ '  --Hello--  ', 'hello' ],
			'digits kept'           => [ 'Java 21 & beyond', 'java-21-beyond' ],
			'empty'                 => [ '', '' ],
		];
	}

	public function test_generate_slug_survives_sanitize_title_round_trip(): void {
		// Slugs are generated on the render side and re-sanitised on the lookup
		// side; a slug that changes under sanitize_title() can never be resolved.
		foreach ( [ 'Ilya-Šumailov', 'Jürgen Höller', 'Kafka: the good parts!' ] as $name ) {
			$slug = cfp_dev_generate_slug( $name );
			$this->assertSame( $slug, sanitize_title( $slug ), 'slug is not stable for ' . $name );
		}
	}

	public function test_meta_excerpt_strips_markup_and_collapses_whitespace(): void {
		$this->assertSame(
			'Hello brave world',
			cfp_dev_meta_excerpt( "<p>Hello   <strong>brave</strong>\n\tworld</p>" )
		);
	}

	public function test_meta_excerpt_truncates_on_a_word_boundary(): void {
		$excerpt = cfp_dev_meta_excerpt( str_repeat( 'word ', 60 ), 20 );

		$this->assertStringEndsWith( '…', $excerpt );
		$this->assertLessThanOrEqual( 21, mb_strlen( $excerpt ) );
		$this->assertStringNotContainsString( 'wor…', $excerpt, 'truncation cut a word in half' );
	}

	public function test_meta_excerpt_keeps_short_text_verbatim(): void {
		$this->assertSame( 'Short text', cfp_dev_meta_excerpt( 'Short text' ) );
	}

	/**
	 * @dataProvider boolAttrProvider
	 */
	public function test_attr_bool_normalises_shortcode_booleans( $input, bool $expected ): void {
		$this->assertSame( $expected, cfp_dev_attr_bool( $input ) );
	}

	public static function boolAttrProvider(): array {
		return [
			[ 'yes', true ],
			[ 'true', true ],
			[ '1', true ],
			[ true, true ],
			[ 'no', false ],
			[ 'false', false ],
			[ '0', false ],
			[ '', false ],
			[ false, false ],
		];
	}

	public function test_cfp_dev_url_applies_the_path_prefix(): void {
		$this->option( 'cfp_dev_path_prefix', 'trieste' );

		$this->assertSame( '/trieste/talk/my-talk/', cfp_dev_url( '/talk/my-talk' ) );
	}

	public function test_cfp_dev_url_adds_a_trailing_slash_only_to_plain_paths(): void {
		$this->assertSame( '/speakers/', cfp_dev_url( '/speakers' ) );
		$this->assertSame( '/talk?id=7', cfp_dev_url( '/talk?id=7' ) );
		$this->assertSame( '/schedule#day', cfp_dev_url( '/schedule#day' ) );
	}

	public function test_cache_keys_are_versioned_and_change_on_clear_cache(): void {
		$before = cfp_dev_group_cache_key( 'cfp_schedule_Monday' );
		$this->assertSame( 'cfp_schedule_Monday_v1', $before );

		cfp_dev_clear_cache();

		$this->assertSame( 'cfp_schedule_Monday_v2', cfp_dev_group_cache_key( 'cfp_schedule_Monday' ) );
		$this->assertNotSame( $before, cfp_dev_group_cache_key( 'cfp_schedule_Monday' ) );
	}

	public function test_detail_cache_keys_are_distinct_per_type_and_id(): void {
		$keys = [
			cfp_dev_detail_cache_key( 'speaker', 1 ),
			cfp_dev_detail_cache_key( 'talk', 1 ),
			cfp_dev_detail_cache_key( 'photo', 1 ),
			cfp_dev_detail_cache_key( 'speaker', 2 ),
		];

		$this->assertSame( $keys, array_unique( $keys ) );
		foreach ( $keys as $key ) {
			$this->assertStringEndsWith( '_v1', $key );
			$this->assertLessThanOrEqual( 172, strlen( $key ), 'transient keys must fit WordPress limits' );
		}
	}

	public function test_speakers_cache_key_differs_per_attribute_set(): void {
		$defaults = cfp_dev_speakers_default_atts();
		$custom   = array_merge( $defaults, [ 'size' => 20 ] );

		$this->assertNotSame(
			cfp_dev_speakers_cache_key( $defaults ),
			cfp_dev_speakers_cache_key( $custom )
		);
	}

	public function test_atts_cache_suffix_is_empty_for_the_default_attribute_set(): void {
		$defaults = [
			'title'      => 'Talks',
			'hide_title' => false,
		];

		$this->assertSame( '', cfp_dev_atts_cache_suffix( $defaults, $defaults ) );
		$this->assertNotSame( '', cfp_dev_atts_cache_suffix( [ 'title' => 'Other' ] + $defaults, $defaults ) );
	}

	public function test_store_key_strips_anything_that_is_not_a_hostname_label(): void {
		cfp_dev_store_key( 'DvBe25/../evil.com' );

		$this->assertSame( 'dvbe25evilcom', cfp_dev_get_key() );
		$this->assertSame( 'https://dvbe25evilcom.cfp.dev/api/', cfp_dev_api_base() );
	}

	public function test_cache_ttl_never_goes_negative(): void {
		cfp_dev_store_cache_ttl( '-500' );
		$this->assertSame( 0, cfp_dev_get_cache_ttl() );

		cfp_dev_store_cache_ttl( '3600' );
		$this->assertSame( 3600, cfp_dev_get_cache_ttl() );
	}

	public function test_usable_image_rejects_google_cache_thumbnails(): void {
		$this->assertSame( '', cfp_dev_usable_image( 'https://encrypted-tbn0.gstatic.com/images?q=tbn:x' ) );
		$this->assertSame( '', cfp_dev_usable_image( '' ) );
		$this->assertSame( 'https://cdn.test/a.png', cfp_dev_usable_image( 'https://cdn.test/a.png' ) );
	}

	public function test_search_base_encodes_the_instance_key(): void {
		$this->option( 'cfp_dev_key', 'dv be' );

		$this->assertStringContainsString( 'cfp=dv%20be', cfp_dev_search_base() );
		$this->assertStringEndsWith( 'query=', cfp_dev_search_base() );
	}

	public function test_page_header_escapes_the_title_and_can_hide_the_search_form(): void {
		$header = cfp_dev_page_header( '<script>x</script>Speakers', 'Sub & title', false );

		$this->assertStringNotContainsString( '<script>', $header );
		$this->assertStringContainsString( 'Sub &amp; title', $header );
		$this->assertStringNotContainsString( '<form', $header );

		$this->assertStringContainsString( '<form', cfp_dev_page_header( 'Speakers', '', true ) );
	}

	public function test_root_class_script_encodes_the_page_and_theme_classes(): void {
		$this->option( 'cfp_dev_default_theme', 'light' );

		$script = cfp_dev_root_class_script( 'speaker', 'detail' );

		$this->assertStringContainsString( '"cfp-page:speaker"', $script );
		$this->assertStringContainsString( '"cfp-view:detail"', $script );
		$this->assertStringContainsString( '"cfp-theme:light"', $script );
	}

	public function test_slug_lookups_honour_the_no_cache_setting(): void {
		$this->api( 'public/talks', [ [ 'id' => 200, 'title' => 'Modern Java in Practice' ] ] );
		$this->assertSame( 0, cfp_dev_get_cache_ttl(), 'guard: this test covers the No Cache setting' );

		$this->assertSame( 200, cfp_dev_talk_id_from_slug( 'modern-java-in-practice' ) );

		$this->assertFalse(
			get_transient( cfp_dev_group_cache_key( 'cfp_talk_slug_' . md5( 'modern-java-in-practice' ) ) ),
			'a resolved slug must not be pinned for a day when caching is switched off'
		);
	}

	public function test_slug_lookups_cache_hits_for_the_configured_duration(): void {
		$this->option( 'cfp_dev_cache_duration', 3600 );
		$this->api( 'public/talks', [ [ 'id' => 200, 'title' => 'Modern Java in Practice' ] ] );

		cfp_dev_talk_id_from_slug( 'modern-java-in-practice' );

		$this->assertSame(
			200,
			get_transient( cfp_dev_group_cache_key( 'cfp_talk_slug_' . md5( 'modern-java-in-practice' ) ) )
		);
	}

	public function test_unknown_slugs_are_cached_even_when_caching_is_off(): void {
		$this->api( 'public/talks', [ [ 'id' => 200, 'title' => 'Modern Java in Practice' ] ] );

		// Resolving a slug costs a full list fetch, so an unauthenticated loop
		// over made-up slugs must not refetch it every time.
		$this->assertNull( cfp_dev_talk_id_from_slug( 'no-such-talk' ) );

		$this->assertSame(
			0,
			get_transient( cfp_dev_group_cache_key( 'cfp_talk_slug_' . md5( 'no-such-talk' ) ) )
		);
	}

	public function test_request_cache_memoises_until_flushed(): void {
		$calls    = 0;
		$resolver = static function () use ( &$calls ) {
			++$calls;
			return null; // Null must be memoised too.
		};

		cfp_dev_request_cache_get( 'unit-test', $resolver );
		cfp_dev_request_cache_get( 'unit-test', $resolver );
		$this->assertSame( 1, $calls );

		cfp_dev_flush_request_cache();
		cfp_dev_request_cache_get( 'unit-test', $resolver );
		$this->assertSame( 2, $calls );
	}
}
