<?php
/**
 * CFP.DEV shortcodes
 *
 * Tests for the API client: getJSON()/searchJSON() success, failure and
 * offline-mode behaviour.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Unit;

use CfpDev\Tests\PluginTestCase;
use WP_Test_State;

final class ApiClientTest extends PluginTestCase {

	public function test_get_json_decodes_a_successful_response(): void {
		$this->api( 'public/event', [ 'name' => 'Devoxx' ] );

		$this->assertSame( 'Devoxx', getJSON( 'public/event' )->name );
	}

	public function test_get_json_returns_null_on_a_transport_error(): void {
		$this->assertNull( getJSON( 'public/never-registered' ) );
	}

	public function test_get_json_returns_null_on_a_non_200_response(): void {
		$this->api( 'public/talks', [ 'a' ], 503 );

		$this->assertNull( getJSON( 'public/talks' ) );
	}

	public function test_get_json_returns_null_on_malformed_json(): void {
		$this->api( 'public/talks', '{not json' );

		$this->assertNull( getJSON( 'public/talks' ) );
	}

	/**
	 * @dataProvider traversalProvider
	 */
	public function test_get_json_rejects_traversal_and_absolute_paths( string $path ): void {
		$this->assertNull( getJSON( $path ) );
		$this->assertSame( [], $this->httpLog(), 'a rejected path must not reach the network' );
	}

	public static function traversalProvider(): array {
		return [
			'dot dot'       => [ 'public/../../wp-config' ],
			'nested'        => [ 'public/talks/..%2f..' ],
			'absolute path' => [ '/etc/passwd' ],
		];
	}

	public function test_search_json_sorts_nothing_but_returns_the_decoded_array(): void {
		$this->search( 'java', [ [ 'title' => 'A' ] ] );

		$results = searchJSON( 'java' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'A', $results[0]->title );
	}

	public function test_search_json_returns_an_empty_array_on_failure(): void {
		$this->assertSame( [], searchJSON( 'nothing-registered' ) );
	}

	public function test_search_json_is_disabled_in_offline_mode(): void {
		$this->option( 'cfp_dev_offline_mode', 1 );

		$this->assertSame( [], searchJSON( 'java' ) );
		$this->assertSame( [], $this->httpLog() );
	}

	public function test_offline_mode_without_a_snapshot_falls_back_to_the_live_api(): void {
		$this->option( 'cfp_dev_offline_mode', 1 );
		$this->api( 'public/event', [ 'name' => 'Devoxx' ] );

		$this->assertSame( 'Devoxx', getJSON( 'public/event' )->name );
		$this->assertSame( 0, (int) get_option( 'cfp_dev_offline_mode' ), 'offline mode should switch itself off' );
		$this->assertSame( 2, (int) get_option( 'cfp_dev_cache_version' ), 'stale rendered HTML should be invalidated' );
	}

	public function test_clear_cache_bumps_the_cache_version(): void {
		clearCache();
		clearCache();

		$this->assertSame( 3, (int) get_option( 'cfp_dev_cache_version' ) );
	}

	public function test_get_time_converts_utc_into_the_target_timezone(): void {
		$formatted = getTime( '2025-10-06T08:30:00Z', new \DateTimeZone( 'Europe/Brussels' ), 'H:i' );

		$this->assertSame( '10:30', $formatted );
	}

	public function test_social_links_are_omitted_when_no_handle_is_set(): void {
		$speaker = (object) [
			'twitterHandle'    => null,
			'linkedInUsername' => null,
			'blueskyUsername'  => null,
			'mastodonUsername' => null,
		];

		$this->assertSame( '', getSocialLinks( $speaker ) );
	}

	public function test_social_links_neutralise_a_javascript_mastodon_url(): void {
		$speaker = (object) [
			'twitterHandle'    => null,
			'linkedInUsername' => null,
			'blueskyUsername'  => null,
			'mastodonUsername' => 'javascript:alert(1)',
		];

		$this->assertStringNotContainsString( 'javascript:', getSocialLinks( $speaker ) );
	}

	public function test_footer_is_only_rendered_when_theme_switching_is_enabled(): void {
		$this->assertSame( '', getFooter() );

		WP_Test_State::$options['enable_theme_switch'] = 1;
		$this->assertStringContainsString( 'data-theme-key="light"', getFooter() );
	}
}
