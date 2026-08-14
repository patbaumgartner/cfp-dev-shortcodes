<?php
/**
 * CFP.DEV shortcodes
 *
 * Tests that the shipped translation template still describes the shipped
 * strings.
 *
 * The .pot is generated, so it drifts silently: a new string simply never
 * reaches translators, and nobody finds out until a translated site shows
 * English. This checks the template against the source it was generated from.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TranslationTemplateTest extends TestCase {

	private const POT = CFP_DEV_DIR . '/languages/cfp-dev-shortcodes.pot';

	/** The gettext wrappers whose first argument is a translatable string. */
	private const SINGULAR_CALLS = [ '__', '_e', '_x', 'esc_html__', 'esc_html_e', 'esc_html_x', 'esc_attr__', 'esc_attr_e' ];

	public function test_every_translatable_string_is_in_the_template(): void {
		$missing = array_diff( $this->sourceStrings(), $this->templateStrings() );

		$this->assertSame( [], array_values( $missing ), 'run `composer i18n` to refresh the template' );
	}

	public function test_the_template_describes_no_string_the_plugin_stopped_using(): void {
		$stale = array_diff( $this->templateStrings(), $this->sourceStrings() );

		$this->assertSame( [], array_values( $stale ), 'run `composer i18n` to refresh the template' );
	}

	public function test_the_template_declares_the_current_plugin_version(): void {
		$this->assertStringContainsString(
			'Project-Id-Version: CFP.DEV shortcodes ' . CFP_DEV_VERSION,
			(string) file_get_contents( self::POT )
		);
	}

	/** Translators need somewhere to report a bad string. */
	public function test_the_template_points_at_the_project_issue_tracker(): void {
		$this->assertStringContainsString(
			'Report-Msgid-Bugs-To: https://github.com/patbaumgartner/cfp-dev-shortcodes/issues',
			(string) file_get_contents( self::POT )
		);
	}

	// ─────────────────────────────────────────────────────────────────────────

	/** Every literal passed to a gettext wrapper in the shipped PHP. */
	private function sourceStrings(): array {
		$pattern = '/\b(?:' . implode( '|', self::SINGULAR_CALLS ) . ")\(\s*'((?:[^'\\\\]|\\\\.)*)'/";
		$strings = [];

		foreach ( $this->shippedFiles() as $file ) {
			preg_match_all( $pattern, (string) file_get_contents( $file ), $matches );
			foreach ( $matches[1] as $literal ) {
				$strings[] = stripslashes( $literal );
			}
		}

		// The plugin header is translatable too, and WP-CLI extracts it from
		// the file rather than from a gettext call.
		foreach ( [ 'Plugin Name', 'Plugin URI', 'Description', 'Author', 'Author URI' ] as $header ) {
			$strings[] = $this->pluginHeader( $header );
		}

		sort( $strings );
		return array_values( array_unique( $strings ) );
	}

	/** Reads one field out of the main plugin file's header block. */
	private function pluginHeader( string $field ): string {
		preg_match(
			'/^ \* ' . preg_quote( $field, '/' ) . ':\s*(.+)$/m',
			(string) file_get_contents( CFP_DEV_DIR . '/cfp-dev-wordpress-shortcodes.php' ),
			$matches
		);

		return trim( $matches[1] ?? '' );
	}

	/** Every msgid in the template, excluding its own empty header entry. */
	private function templateStrings(): array {
		preg_match_all( '/^msgid "((?:[^"\\\\]|\\\\.)*)"$/m', (string) file_get_contents( self::POT ), $matches );

		$strings = array_filter(
			array_map(
				static fn( string $msgid ): string => stripcslashes( $msgid ),
				$matches[1]
			),
			static fn( string $msgid ): bool => '' !== $msgid
		);

		sort( $strings );
		return array_values( array_unique( $strings ) );
	}

	/** The PHP files that ship in the release ZIP. */
	private function shippedFiles(): array {
		$files    = [ CFP_DEV_DIR . '/cfp-dev-wordpress-shortcodes.php', CFP_DEV_DIR . '/uninstall.php' ];
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( CFP_DEV_DIR . '/shortcode' ) );

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}
}
