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

		// Asserted on the reader itself: cfp_dev_get_json() turns this path away
		// before the snapshot is ever consulted, so going through it would prove
		// only that the outer guard works.
		$this->assertNull( cfp_dev_read_snapshot_body( 'public/../../secret' ) );
	}

	public function test_malformed_snapshot_json_is_reported_as_a_miss(): void {
		$snapshot = $this->makeSnapshot( '2025-01-01_00-00-00', true );
		$this->writeSnapshotJson( $snapshot, 'public/event', '{broken' );

		$this->assertNull( cfp_dev_read_snapshot_body( 'public/event' ) );
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

	// ─────────────────────────────────────────────────────────────────────────
	// Snapshot pinning
	// ─────────────────────────────────────────────────────────────────────────

	public function test_a_pinned_snapshot_is_served_instead_of_the_latest(): void {
		$old = $this->makeSnapshot( '2025-01-01_00-00-00', true );
		$new = $this->makeSnapshot( '2025-06-01_00-00-00', true );
		$this->writeSnapshotJson( $old, 'public/event', [ 'name' => 'old edition' ] );
		$this->writeSnapshotJson( $new, 'public/event', [ 'name' => 'new edition' ] );
		$this->option( 'cfp_dev_offline_mode', 1 );

		cfp_dev_store_active_snapshot( '2025-01-01_00-00-00' );

		$this->assertSame( $old, cfp_dev_get_serving_snapshot() );
		$this->assertSame( 'old edition', cfp_dev_get_json( 'public/event' )->name );
		$this->assertSame( [], $this->httpLog(), 'a pinned snapshot must not touch the network' );
	}

	public function test_a_pinned_snapshot_that_disappeared_falls_back_to_the_latest(): void {
		$this->makeSnapshot( '2025-06-01_00-00-00', true );
		$this->option( 'cfp_dev_active_snapshot', '2025-01-01_00-00-00' );

		$this->assertSame(
			cfp_dev_offline_dir() . '/2025-06-01_00-00-00',
			cfp_dev_get_serving_snapshot()
		);
	}

	public function test_a_pin_to_an_unknown_snapshot_is_refused(): void {
		$this->makeSnapshot( '2025-01-01_00-00-00', true );

		cfp_dev_store_active_snapshot( '../../evil' );
		$this->assertSame( '', (string) get_option( 'cfp_dev_active_snapshot', '' ) );

		cfp_dev_store_active_snapshot( '2025-12-31_00-00-00' ); // Never crawled.
		$this->assertSame( '', (string) get_option( 'cfp_dev_active_snapshot', '' ) );
	}

	public function test_changing_the_pin_re_renders_cached_pages(): void {
		$this->makeSnapshot( '2025-01-01_00-00-00', true );

		cfp_dev_store_active_snapshot( '2025-01-01_00-00-00' );
		$this->assertSame( 2, (int) get_option( 'cfp_dev_cache_version' ) );

		// Saving the same selection again must not discard every cached page.
		cfp_dev_store_active_snapshot( '2025-01-01_00-00-00' );
		$this->assertSame( 2, (int) get_option( 'cfp_dev_cache_version' ) );
	}

	public function test_a_pinned_snapshot_is_never_pruned(): void {
		foreach ( [ '2025-01-01_00-00-00', '2025-02-01_00-00-00', '2025-03-01_00-00-00' ] as $name ) {
			$this->makeSnapshot( $name, true );
		}
		cfp_dev_store_active_snapshot( '2025-01-01_00-00-00' );

		cfp_dev_prune_snapshots( 1 );

		$remaining = array_map( 'basename', (array) glob( cfp_dev_offline_dir() . '/*', GLOB_ONLYDIR ) );
		sort( $remaining );

		$this->assertSame( [ '2025-01-01_00-00-00', '2025-03-01_00-00-00' ], $remaining );
	}

	public function test_the_offline_form_save_pins_the_selected_snapshot(): void {
		$this->makeSnapshot( '2025-01-01_00-00-00', true );

		cfp_dev_handle_settings_post(
			[
				'cfp_dev_offline_mode_save' => '1',
				'cfp_dev_offline_mode'      => '1',
				'cfp_dev_active_snapshot'   => '2025-01-01_00-00-00',
			]
		);

		$this->assertSame( '2025-01-01_00-00-00', get_option( 'cfp_dev_active_snapshot' ) );
	}

	/**
	 * Retention counts snapshots a read can be served from. Counting every
	 * timestamped directory let two abandoned crawls push the last working
	 * snapshot out of the window and delete it — the opposite of the point.
	 */
	public function test_abandoned_crawls_do_not_displace_a_working_snapshot(): void {
		$this->makeSnapshot( '2025-01-01_00-00-00', true );
		$this->makeSnapshot( '2025-06-01_00-00-00', false );
		$this->makeSnapshot( '2025-07-01_00-00-00', false );

		cfp_dev_prune_snapshots( 2 );

		$this->assertSame(
			cfp_dev_offline_dir() . '/2025-01-01_00-00-00',
			cfp_dev_get_latest_snapshot(),
			'the only completed snapshot was pruned'
		);
	}

	/** A directory newer than the newest snapshot may be a crawl still writing. */
	public function test_pruning_leaves_a_crawl_in_progress_alone(): void {
		$this->makeSnapshot( '2025-01-01_00-00-00', true );
		$this->makeSnapshot( '2025-09-01_00-00-00', false );

		cfp_dev_prune_snapshots( 1 );

		$this->assertDirectoryExists( cfp_dev_offline_dir() . '/2025-09-01_00-00-00' );
	}

	/** But one superseded by a completed crawl is finished with. */
	public function test_pruning_drops_a_crawl_a_later_one_superseded(): void {
		$this->makeSnapshot( '2025-01-01_00-00-00', false );
		$this->makeSnapshot( '2025-02-01_00-00-00', true );

		cfp_dev_prune_snapshots( 2 );

		$this->assertDirectoryDoesNotExist( cfp_dev_offline_dir() . '/2025-01-01_00-00-00' );
		$this->assertDirectoryExists( cfp_dev_offline_dir() . '/2025-02-01_00-00-00' );
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
			// An SVG is a document that can carry script, and the snapshot is
			// served from the site's own origin — the same reason WordPress
			// core refuses SVG uploads.
			'svg'      => [ 'https://cdn.test/x.svg' ],
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
