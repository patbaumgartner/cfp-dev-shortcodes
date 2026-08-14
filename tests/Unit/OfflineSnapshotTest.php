<?php
/**
 * CFP.DEV shortcodes
 *
 * Tests for offline mode: snapshot discovery, snapshot-backed JSON reads,
 * path containment and retention pruning.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Unit;

use CfpDev\Tests\PluginTestCase;

final class OfflineSnapshotTest extends PluginTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->removeDirectory( cfp_dev_offline_dir() );
	}

	protected function tearDown(): void {
		$this->removeDirectory( cfp_dev_offline_dir() );
		parent::tearDown();
	}

	public function test_no_snapshot_is_reported_when_the_directory_is_missing(): void {
		$this->assertSame( '', cfp_dev_get_latest_snapshot() );
	}

	public function test_only_completed_snapshots_count_as_the_latest(): void {
		$this->makeSnapshot( '2025-01-01_00-00-00', true );
		$this->makeSnapshot( '2025-06-01_00-00-00', false ); // Newer but incomplete.

		$this->assertSame(
			cfp_dev_offline_dir() . '/2025-01-01_00-00-00',
			cfp_dev_get_latest_snapshot()
		);
	}

	public function test_offline_reads_strip_the_query_string_before_resolving_the_file(): void {
		$snapshot = $this->makeSnapshot( '2025-01-01_00-00-00', true );
		$this->writeSnapshotJson( $snapshot, 'public/speakers', [ [ 'id' => 1 ] ] );
		$this->option( 'cfp_dev_offline_mode', 1 );

		$speakers = cfp_dev_get_json( 'public/speakers?size=500' );

		$this->assertCount( 1, $speakers );
		$this->assertSame( [], $this->httpLog(), 'offline mode must not touch the network' );
	}

	public function test_a_resource_missing_from_the_snapshot_stays_offline(): void {
		$this->makeSnapshot( '2025-01-01_00-00-00', true );
		$this->option( 'cfp_dev_offline_mode', 1 );
		$this->api( 'public/talks', [ [ 'id' => 1 ] ] );

		$this->assertNull( cfp_dev_get_json( 'public/talks' ) );
		$this->assertSame( [], $this->httpLog(), 'a snapshot miss must not silently fall back to the live API' );
		$this->assertSame( 1, (int) get_option( 'cfp_dev_offline_mode' ) );
	}

	public function test_offline_reads_cannot_escape_the_snapshot_directory(): void {
		$snapshot = $this->makeSnapshot( '2025-01-01_00-00-00', true );
		file_put_contents( dirname( $snapshot ) . '/secret.json', '{"secret":true}' );

		$this->assertNull( cfp_dev_get_json_offline( 'public/../../secret' ) );
	}

	public function test_malformed_snapshot_json_is_reported_as_a_miss(): void {
		$snapshot = $this->makeSnapshot( '2025-01-01_00-00-00', true );
		$this->writeSnapshotJson( $snapshot, 'public/event', '{broken' );

		$this->assertNull( cfp_dev_get_json_offline( 'public/event' ) );
	}

	public function test_pruning_keeps_only_the_newest_snapshots(): void {
		foreach ( [ '2025-01-01_00-00-00', '2025-02-01_00-00-00', '2025-03-01_00-00-00' ] as $name ) {
			$this->makeSnapshot( $name, true );
		}

		cfp_dev_prune_snapshots( 2 );

		$remaining = array_map( 'basename', (array) glob( cfp_dev_offline_dir() . '/*', GLOB_ONLYDIR ) );
		sort( $remaining );

		$this->assertSame( [ '2025-02-01_00-00-00', '2025-03-01_00-00-00' ], $remaining );
	}

	public function test_image_urls_are_collected_recursively_and_deduplicated(): void {
		$data = [
			'imageUrl' => 'https://cdn.test/a.jpg',
			'track'    => [ 'imageURL' => 'https://cdn.test/b.png' ],
			'talks'    => [
				[ 'trackImageURL' => 'https://cdn.test/b.png' ], // Duplicate.
				[ 'thumbnailUrl' => 'https://cdn.test/c.webp' ],
				[ 'unrelatedUrl' => 'https://cdn.test/d.gif' ],  // Not an image field.
			],
		];

		$map = [];
		cfp_dev_collect_image_urls( $data, [ 'imageUrl', 'imageURL', 'trackImageURL', 'thumbnailUrl' ], $map );

		$this->assertCount( 3, $map );
		$this->assertSame( md5( 'https://cdn.test/a.jpg' ) . '.jpg', $map['https://cdn.test/a.jpg'] );
		$this->assertSame( md5( 'https://cdn.test/c.webp' ) . '.webp', $map['https://cdn.test/c.webp'] );
		$this->assertArrayNotHasKey( 'https://cdn.test/d.gif', $map );
	}

	public function test_image_extensions_outside_the_allow_list_fall_back_to_jpg(): void {
		$map = [];
		cfp_dev_collect_image_urls(
			[ 'imageUrl' => 'https://cdn.test/photo.php?x=1' ],
			[ 'imageUrl' ],
			$map
		);

		// The snapshot lives under wp-content/uploads. Honouring the URL's own
		// extension would let the upstream API name a file '.php' there.
		$this->assertSame( md5( 'https://cdn.test/photo.php?x=1' ) . '.jpg', reset( $map ) );
	}

	/**
	 * @dataProvider executableExtensionProvider
	 */
	public function test_executable_extensions_never_reach_the_filesystem( string $url ): void {
		$map = [];
		cfp_dev_collect_image_urls( [ 'imageUrl' => $url ], [ 'imageUrl' ], $map );

		$this->assertStringEndsWith( '.jpg', (string) reset( $map ) );
	}

	public static function executableExtensionProvider(): array {
		return [
			'php'      => [ 'https://cdn.test/x.php' ],
			'phtml'    => [ 'https://cdn.test/x.phtml' ],
			'phar'     => [ 'https://cdn.test/x.phar' ],
			'html'     => [ 'https://cdn.test/x.html' ],
			'htaccess' => [ 'https://cdn.test/.htaccess' ],
		];
	}

	// ─────────────────────────────────────────────────────────────────────────

	private function makeSnapshot( string $name, bool $complete ): string {
		$snapshot = cfp_dev_offline_dir() . '/' . $name;
		mkdir( $snapshot . '/api/public', 0777, true );
		if ( $complete ) {
			file_put_contents( $snapshot . '/manifest.json', '{}' );
		}
		return $snapshot;
	}

	private function writeSnapshotJson( string $snapshot, string $path, $data ): void {
		$file = $snapshot . '/api/' . $path . '.json';
		if ( ! is_dir( dirname( $file ) ) ) {
			mkdir( dirname( $file ), 0777, true );
		}
		file_put_contents( $file, is_string( $data ) ? $data : (string) wp_json_encode( $data ) );
	}

	private function removeDirectory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}
}
