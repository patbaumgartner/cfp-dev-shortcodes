<?php
/**
 * CFP.DEV shortcodes
 *
 * Base test case: resets the fake WordPress runtime between tests and offers
 * small helpers for configuring options, query vars and canned API responses.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests;

use PHPUnit\Framework\TestCase;
use WP_Test_State;

abstract class PluginTestCase extends TestCase {

	/** Default plugin options applied to every test. */
	protected const DEFAULT_OPTIONS = [
		'cfp_dev_key'            => 'dvbe25',
		'cfp_dev_event_name'     => 'Devoxx Belgium 2025',
		'cfp_dev_cache_duration' => 0,
		'cfp_dev_cache_version'  => 1,
		'cfp_dev_content_by_id'  => 'no',
		'cfp_dev_show_rooms'     => 'yes',
		'cfp_dev_default_theme'  => 'dark',
		'cfp_dev_path_prefix'    => '',
		'cfp_dev_offline_mode'   => 0,
	];

	protected function setUp(): void {
		parent::setUp();

		WP_Test_State::$options        = self::DEFAULT_OPTIONS;
		WP_Test_State::$transients     = [];
		WP_Test_State::$query_vars     = [];
		WP_Test_State::$current_page   = [];
		WP_Test_State::$http_responses = [];
		WP_Test_State::$http_log       = [];
		WP_Test_State::$enqueued       = [];
		WP_Test_State::$theme_support  = [];
		WP_Test_State::$json_responses = [];
		WP_Test_State::$env            = [ 'capabilities' => [ 'manage_options' ] ];

		cfp_dev_flush_request_cache();
	}

	protected function tearDown(): void {
		cfp_dev_flush_request_cache();
		parent::tearDown();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/** Sets a plugin option for the current test. */
	protected function option( string $name, $value ): void {
		WP_Test_State::$options[ $name ] = $value;
	}

	/** Sets a WordPress query var (`id`, `talk_slug`, `query`, …). */
	protected function queryVar( string $name, $value ): void {
		WP_Test_State::$query_vars[ $name ] = $value;
	}

	/** Marks the current request as being the given plugin page(s). */
	protected function onPage( string ...$slugs ): void {
		WP_Test_State::$current_page = $slugs;
	}

	/** Sets the WordPress site timezone used by wp_date(). */
	protected function siteTimezone( string $timezone ): void {
		WP_Test_State::$env['timezone'] = $timezone;
	}

	/**
	 * Registers a canned JSON response for a relative CFP.DEV API path.
	 *
	 * @param string $path  Relative API path, e.g. 'public/talks'.
	 * @param mixed  $data  Value to JSON-encode as the response body.
	 * @param int    $code  HTTP status code.
	 */
	protected function api( string $path, $data, int $code = 200 ): void {
		WP_Test_State::$http_responses[ cfp_dev_api_base() . $path ] = [
			'code' => $code,
			'body' => is_string( $data ) ? $data : (string) wp_json_encode( $data ),
		];
	}

	/** Registers a canned response for the semantic search service. */
	protected function search( string $query, array $results ): void {
		WP_Test_State::$http_responses[ cfp_dev_search_base() . rawurlencode( $query ) ] = [
			'code' => 200,
			'body' => (string) wp_json_encode( $results ),
		];
	}

	/** Every URL requested so far, in order. */
	protected function httpLog(): array {
		return WP_Test_State::$http_log;
	}

	/** Number of requests made to a relative API path (query string included). */
	protected function apiCallCount( string $path ): int {
		$url = cfp_dev_api_base() . $path;
		return count( array_filter( WP_Test_State::$http_log, static fn( $logged ) => $logged === $url ) );
	}

	/** Registers the full happy-path API fixture set. */
	protected function registerDefaultApi(): void {
		$this->api( 'public/event', Fixtures::event() );
		$this->api( 'public/rooms', Fixtures::rooms() );
		$this->api( 'public/tracks', Fixtures::tracks() );
		$this->api( 'public/session-types', Fixtures::sessionTypes() );
		$this->api( 'public/talks', Fixtures::talks() );
		$this->api( 'public/speakers?size=' . CFP_DEV_SPEAKERS_FETCH_SIZE, Fixtures::speakers() );

		foreach ( Fixtures::talks() as $talk ) {
			$this->api( 'public/talks/' . $talk['id'], Fixtures::talkDetail( (int) $talk['id'] ) );
		}
		foreach ( Fixtures::speakers() as $speaker ) {
			$this->api( 'public/speakers/' . $speaker['id'], Fixtures::speakerDetail( (int) $speaker['id'] ) );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Assertions
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Asserts that every non-void element in the fragment is opened and closed
	 * in the right order — the plugin returns HTML that is spliced into a theme
	 * template, so a stray `</div>` closes the *theme's* markup.
	 */
	protected function assertHtmlBalanced( string $html, string $message = '' ): void {
		$void  = [ 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' ];
		$stack = [];

		preg_match_all( '#<(/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(/?)>#', $this->stripScripts( $html ), $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$closing      = '/' === $match[1];
			$tag          = strtolower( $match[2] );
			$self_closing = '/' === $match[3];

			if ( in_array( $tag, $void, true ) || $self_closing ) {
				continue;
			}

			if ( $closing ) {
				$open = array_pop( $stack );
				$this->assertNotNull( $open, trim( $message . ' — stray closing </' . $tag . '>' ) );
				$this->assertSame( $tag, $open, trim( $message . ' — </' . $tag . '> closes an open <' . $open . '>' ) );
				continue;
			}

			$stack[] = $tag;
		}

		$this->assertSame( [], $stack, trim( $message . ' — unclosed element(s): ' . implode( ', ', $stack ) ) );
	}

	/** Removes `<script>` bodies so inline JS is not parsed as markup. */
	private function stripScripts( string $html ): string {
		return (string) preg_replace( '#<script\b[^>]*>.*?</script>#si', '', $html );
	}
}
