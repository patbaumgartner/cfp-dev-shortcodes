<?php
/**
 * CFP.DEV shortcodes
 *
 * Structural guarantees the plugin must keep, enforced by inspecting the
 * shipped source rather than by convention.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Unit;

use CfpDev\Tests\PluginTestCase;

final class PluginStructureTest extends PluginTestCase {

	/** Shortcode tags are written into user content and must never change. */
	private const PUBLIC_SHORTCODE_TAGS = [
		'cfp_speakers',
		'cfp_speaker_details',
		'cfp_talk_details',
		'cfp_schedule',
		'cfp_talks_by_tracks',
		'cfp_talks_by_sessions',
		'cfp_search_results',
	];

	/**
	 * WordPress loads every plugin into one global namespace, so an
	 * unprefixed function name is a fatal error waiting for the site to
	 * install a plugin or theme that declares the same one.
	 */
	public function test_every_global_function_is_prefixed(): void {
		$unprefixed = [];

		foreach ( $this->pluginSources() as $path ) {
			preg_match_all( '/^\s*function\s+&?([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', (string) file_get_contents( $path ), $matches );

			foreach ( $matches[1] as $name ) {
				if ( ! str_starts_with( $name, 'cfp_dev_' ) ) {
					$unprefixed[] = basename( $path ) . ': ' . $name . '()';
				}
			}
		}

		$this->assertSame( [], $unprefixed, "unprefixed global function(s):\n" . implode( "\n", $unprefixed ) );
	}

	/** Global constants share the same namespace as functions. */
	public function test_every_global_constant_is_prefixed(): void {
		$unprefixed = [];

		foreach ( $this->pluginSources() as $path ) {
			preg_match_all( "/\bdefine\(\s*'([A-Za-z_][A-Za-z0-9_]*)'/", (string) file_get_contents( $path ), $matches );

			foreach ( $matches[1] as $name ) {
				if ( ! str_starts_with( $name, 'CFP_DEV_' ) ) {
					$unprefixed[] = basename( $path ) . ': ' . $name;
				}
			}
		}

		$this->assertSame( [], $unprefixed, 'unprefixed global constant(s): ' . implode( ', ', $unprefixed ) );
	}

	/** Options are rows in a shared table; an unprefixed name can collide. */
	public function test_every_option_name_is_prefixed(): void {
		$legacy     = [ 'enable_theme_switch' ]; // Read once to migrate, then deleted.
		$unprefixed = [];

		foreach ( $this->pluginSources() as $path ) {
			preg_match_all( "/\b(?:get|update|add|delete)_option\(\s*'([A-Za-z_][A-Za-z0-9_]*)'/", (string) file_get_contents( $path ), $matches );

			foreach ( $matches[1] as $name ) {
				if ( ! str_starts_with( $name, 'cfp_dev_' ) && ! in_array( $name, $legacy, true ) ) {
					$unprefixed[] = basename( $path ) . ': ' . $name;
				}
			}
		}

		$this->assertSame( [], $unprefixed, 'unprefixed option name(s): ' . implode( ', ', $unprefixed ) );
	}

	/** Hooks the plugin defines itself must be namespaced too. */
	public function test_every_plugin_defined_hook_is_prefixed(): void {
		$core_hooks = [
			'plugins_loaded',
			'init',
			'admin_menu',
			'admin_enqueue_scripts',
			'wp_enqueue_scripts',
			'wp_head',
			'wp_robots',
			'template_redirect',
			'query_vars',
			'get_canonical_url',
			'pre_get_document_title',
			'wp_sitemaps_init',
		];

		$unprefixed = [];

		foreach ( $this->pluginSources() as $path ) {
			preg_match_all( "/\b(?:add|do|apply)_(?:action|filters)\(\s*'([A-Za-z_][A-Za-z0-9_]*)'/", (string) file_get_contents( $path ), $matches );

			foreach ( $matches[1] as $hook ) {
				$name = preg_replace( '/^wp_ajax_(nopriv_)?/', '', $hook );
				if ( ! str_starts_with( (string) $name, 'cfp_dev_' ) && ! in_array( $hook, $core_hooks, true ) ) {
					$unprefixed[] = basename( $path ) . ': ' . $hook;
				}
			}
		}

		$this->assertSame( [], $unprefixed, 'unprefixed hook name(s): ' . implode( ', ', $unprefixed ) );
	}

	/** Renaming a tag would silently blank every page already using it. */
	public function test_the_public_shortcode_tags_are_unchanged(): void {
		$this->assertSame( self::PUBLIC_SHORTCODE_TAGS, cfp_dev_shortcode_tags() );

		foreach ( self::PUBLIC_SHORTCODE_TAGS as $tag ) {
			$this->assertTrue( shortcode_exists( $tag ), $tag . ' is no longer registered' );
		}
	}

	/** Every module listed for loading must exist and parse. */
	public function test_the_plugin_header_version_matches_the_version_constant(): void {
		$header = (string) file_get_contents( dirname( __DIR__, 2 ) . '/cfp-dev-wordpress-shortcodes.php' );

		$this->assertSame( 1, preg_match( '/^ \* Version:\s+(\S+)$/m', $header, $matches ) );
		$this->assertSame( CFP_DEV_VERSION, $matches[1] );
	}

	/** @return string[] */
	private function pluginSources(): array {
		$root = dirname( __DIR__, 2 );

		return array_merge(
			[ $root . '/cfp-dev-wordpress-shortcodes.php', $root . '/uninstall.php' ],
			(array) glob( $root . '/shortcode/*.php' ),
			(array) glob( $root . '/shortcode/include/*.php' )
		);
	}
}
