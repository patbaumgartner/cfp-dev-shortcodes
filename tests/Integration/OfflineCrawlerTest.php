<?php
/**
 * CFP.DEV shortcodes
 *
 * Tests for the offline crawler: the fetch/save primitives and the full
 * five-step pipeline that builds a snapshot and activates offline mode.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Integration;

use CfpDev\Tests\PluginTestCase;
use WP_Test_State;

final class OfflineCrawlerTest extends PluginTestCase {

	private const IMAGE_URL = 'https://cdn.test/jane.jpg';

	protected function setUp(): void {
		parent::setUp();
		$this->removeDirectory( cfp_dev_offline_dir() );
	}

	protected function tearDown(): void {
		$this->removeDirectory( cfp_dev_offline_dir() );
		parent::tearDown();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Primitives
	// ─────────────────────────────────────────────────────────────────────────

	public function test_a_fetched_endpoint_is_saved_under_its_path_without_the_query_string(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$errors   = 0;
		$this->api( 'public/speakers?size=9999', [ [ 'id' => 1 ] ] );

		$decoded = cfp_dev_fetch_and_save( 'public/speakers?size=9999', $snapshot, $log, $errors );

		$this->assertSame( 1, $decoded[0]->id );
		$this->assertFileExists( $snapshot . '/api/public/speakers.json' );
		$this->assertSame( 0, $errors );
		$this->assertSame( 200, $log[0]['status'] );
	}

	public function test_an_empty_endpoint_is_saved_as_an_empty_array(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$errors   = 0;
		$this->api( 'public/schedules/Monday/9', '', 204 );

		// A room with no sessions is valid; offline reads must return [] rather
		// than null, which callers treat as "endpoint unavailable".
		$this->assertSame( [], cfp_dev_fetch_and_save( 'public/schedules/Monday/9', $snapshot, $log, $errors ) );
		$this->assertSame( '[]', file_get_contents( $snapshot . '/api/public/schedules/Monday/9.json' ) );
		$this->assertSame( 0, $errors );
	}

	public function test_a_failed_endpoint_is_counted_and_writes_no_file(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$errors   = 0;
		$this->api( 'public/tracks', null, 500 );

		$this->assertNull( cfp_dev_fetch_and_save( 'public/tracks', $snapshot, $log, $errors ) );
		$this->assertSame( 1, $errors );
		$this->assertFileDoesNotExist( $snapshot . '/api/public/tracks.json' );
	}

	public function test_an_optional_endpoint_failure_is_logged_but_not_counted(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$errors   = 0;
		$this->api( 'public/album/7', null, 404 );

		// Most speakers have no photo album; that is normal, not an error.
		cfp_dev_fetch_and_save( 'public/album/7', $snapshot, $log, $errors, 10, true );

		$this->assertSame( 0, $errors );
		$this->assertNotEmpty( $log );
	}

	public function test_a_downloaded_image_is_written_to_disk(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$errors   = 0;
		$this->image( self::IMAGE_URL, 'JPEG-BYTES' );

		$this->assertTrue( cfp_dev_download_image( self::IMAGE_URL, $snapshot . '/images/a.jpg', $log, $errors ) );
		$this->assertSame( 'JPEG-BYTES', file_get_contents( $snapshot . '/images/a.jpg' ) );
		$this->assertSame( 0, $errors );
	}

	public function test_a_non_image_response_is_rejected(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$errors   = 0;
		// A hostile upstream can point an image field anywhere; the served
		// content type decides, not the URL.
		$this->image( 'https://cdn.test/payload.jpg', '<?php echo 1;', 'text/html' );

		$this->assertFalse( cfp_dev_download_image( 'https://cdn.test/payload.jpg', $snapshot . '/images/p.jpg', $log, $errors ) );
		$this->assertFileDoesNotExist( $snapshot . '/images/p.jpg' );
		$this->assertSame( 1, $errors );
	}

	public function test_an_image_on_a_private_address_is_not_fetched(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$errors   = 0;
		$this->image( 'http://127.0.0.1/secret.jpg', 'INTERNAL' );

		$this->assertFalse( cfp_dev_download_image( 'http://127.0.0.1/secret.jpg', $snapshot . '/images/s.jpg', $log, $errors ) );
		$this->assertFileDoesNotExist( $snapshot . '/images/s.jpg' );
		$this->assertSame( [], $this->httpLog(), 'a loopback URL must never be requested' );
	}

	public function test_a_non_http_image_scheme_is_rejected(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$errors   = 0;

		$this->assertFalse( cfp_dev_download_image( 'file:///etc/passwd', $snapshot . '/images/f.jpg', $log, $errors ) );
		$this->assertSame( 1, $errors );
	}

	public function test_an_unreachable_image_is_counted_as_an_error(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$errors   = 0;

		$this->assertFalse( cfp_dev_download_image( 'https://cdn.test/gone.jpg', $snapshot . '/images/g.jpg', $log, $errors ) );
		$this->assertSame( 1, $errors );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Scheduling
	// ─────────────────────────────────────────────────────────────────────────

	public function test_starting_a_crawl_creates_a_snapshot_and_schedules_the_job(): void {
		cfp_dev_start_crawl();

		$state = get_option( 'cfp_dev_crawl_state' );

		$this->assertSame( 'pending', $state['status'] );
		$this->assertDirectoryExists( $state['snapshot'] . '/api/public' );
		$this->assertDirectoryExists( $state['snapshot'] . '/images' );
		$this->assertSame( 'cfp_dev_do_crawl', WP_Test_State::$env['scheduled'][0]['hook'] );
		$this->assertTrue( WP_Test_State::$env['cron_spawned'] );
	}

	public function test_a_crawl_without_its_snapshot_directory_reports_an_error(): void {
		$this->option(
			'cfp_dev_crawl_state',
			[
				'status'   => 'pending',
				'snapshot' => cfp_dev_offline_dir() . '/does-not-exist',
			]
		);

		cfp_dev_do_crawl();

		$this->assertSame( 'error', get_option( 'cfp_dev_crawl_state' )['status'] );
		$this->assertSame( 0, (int) get_option( 'cfp_dev_offline_mode' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Full pipeline
	// ─────────────────────────────────────────────────────────────────────────

	public function test_a_complete_crawl_snapshots_the_api_and_activates_offline_mode(): void {
		$this->registerCrawlableApi();
		cfp_dev_start_crawl();
		$snapshot = get_option( 'cfp_dev_crawl_state' )['snapshot'];

		cfp_dev_do_crawl();

		$state = get_option( 'cfp_dev_crawl_state' );
		$this->assertSame( 'done', $state['status'] );
		$this->assertSame( 0, $state['errors'] );

		foreach (
			[
				'public/event',
				'public/tracks',
				'public/session-types',
				'public/rooms',
				'public/speakers',
				'public/speakers/100',
				'public/talks',
				'public/talks/200',
				'public/talks/track/10',
				'public/talks/session-type/20',
				'public/schedules/Monday',
				'public/schedules/Monday/1',
			] as $path
		) {
			$this->assertFileExists( $snapshot . '/api/' . $path . '.json', $path . ' was not snapshotted' );
		}

		$this->assertSame( 1, (int) get_option( 'cfp_dev_offline_mode' ) );
		$this->assertGreaterThan( 1, (int) get_option( 'cfp_dev_cache_version' ), 'live-API markup must be re-rendered' );
	}

	public function test_a_crawl_localises_every_image_and_rewrites_the_json(): void {
		$this->registerCrawlableApi();
		cfp_dev_start_crawl();
		$snapshot = get_option( 'cfp_dev_crawl_state' )['snapshot'];

		cfp_dev_do_crawl();

		$local_name = md5( self::IMAGE_URL ) . '.jpg';
		$this->assertFileExists( $snapshot . '/images/' . $local_name );
		$this->assertSame( 'JPEG-BYTES', file_get_contents( $snapshot . '/images/' . $local_name ) );

		$speakers = file_get_contents( $snapshot . '/api/public/speakers.json' );
		$this->assertStringNotContainsString( self::IMAGE_URL, $speakers, 'an external URL survived the rewrite' );
		$this->assertStringContainsString( $local_name, $speakers );
	}

	/**
	 * A rewrite is a promise that the file is there. Made unconditionally it
	 * replaced a working CDN URL with a snapshot path nothing had written, so
	 * every failed download became a permanently broken image with no way back
	 * to the original.
	 */
	public function test_an_image_that_failed_to_download_keeps_its_original_url(): void {
		$this->registerCrawlableApi();
		$this->image( self::IMAGE_URL, '', 'text/html', 500 );
		cfp_dev_start_crawl();
		$snapshot = get_option( 'cfp_dev_crawl_state' )['snapshot'];

		cfp_dev_do_crawl();

		$this->assertFileDoesNotExist( $snapshot . '/images/' . md5( self::IMAGE_URL ) . '.jpg' );

		$speakers = json_decode( (string) file_get_contents( $snapshot . '/api/public/speakers.json' ) );
		$this->assertSame(
			self::IMAGE_URL,
			$speakers[0]->imageUrl,
			'the snapshot must keep the URL it could not localise'
		);
	}

	public function test_after_a_crawl_reads_are_served_from_the_snapshot(): void {
		$this->registerCrawlableApi();
		cfp_dev_start_crawl();
		cfp_dev_do_crawl();

		cfp_dev_flush_request_cache();
		WP_Test_State::$http_log = [];

		$speakers = cfp_dev_get_json( 'public/speakers?size=' . CFP_DEV_SPEAKERS_FETCH_SIZE );

		$this->assertSame( [], $this->httpLog(), 'offline mode must make no external requests' );
		$this->assertStringContainsString( 'cfp-dev-offline', $speakers[0]->imageUrl );
	}

	public function test_a_crawl_writes_a_manifest_describing_what_it_captured(): void {
		$this->registerCrawlableApi();
		cfp_dev_start_crawl();
		$snapshot = get_option( 'cfp_dev_crawl_state' )['snapshot'];

		cfp_dev_do_crawl();

		$manifest = json_decode( (string) file_get_contents( $snapshot . '/manifest.json' ), true );

		$this->assertSame( 1, $manifest['stats']['speakers'] );
		$this->assertSame( 1, $manifest['stats']['talks'] );
		$this->assertSame( 1, $manifest['stats']['images'] );
		$this->assertSame( 0, $manifest['stats']['errors'] );
		$this->assertNotEmpty( $manifest['log'] );
	}

	public function test_a_crawl_prunes_all_but_the_two_newest_snapshots(): void {
		foreach ( [ '2020-01-01_00-00-00', '2020-02-01_00-00-00', '2020-03-01_00-00-00' ] as $old ) {
			mkdir( cfp_dev_offline_dir() . '/' . $old, 0777, true );
			file_put_contents( cfp_dev_offline_dir() . '/' . $old . '/manifest.json', '{}' );
		}

		$this->registerCrawlableApi();
		cfp_dev_start_crawl();
		cfp_dev_do_crawl();

		$remaining = array_map( 'basename', (array) glob( cfp_dev_offline_dir() . '/*', GLOB_ONLYDIR ) );

		$this->assertCount( 2, $remaining, 'snapshots would otherwise grow without bound' );
		$this->assertSame( basename( cfp_dev_get_latest_snapshot() ), max( $remaining ) );
	}

	public function test_a_failing_endpoint_is_reported_without_aborting_the_crawl(): void {
		$this->registerCrawlableApi();
		$this->api( 'public/rooms', null, 503 );
		cfp_dev_start_crawl();

		cfp_dev_do_crawl();

		$state = get_option( 'cfp_dev_crawl_state' );
		$this->assertSame( 'done', $state['status'] );
		$this->assertGreaterThan( 0, $state['errors'] );
	}

	// ─────────────────────────────────────────────────────────────────────────

	/** Registers every endpoint a one-speaker, one-talk, one-day event needs. */
	public function test_a_traversing_query_path_writes_nothing(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$errors   = 0;

		// Ids come from the upstream API, so they choose part of the write path.
		$this->assertNull( cfp_dev_fetch_and_save( 'public/speakers/../../../evil', $snapshot, $log, $errors ) );
		$this->assertSame( 1, $errors );
		$this->assertSame( [], $this->httpLog(), 'a rejected path must not be requested either' );
		$this->assertFileDoesNotExist( dirname( $snapshot ) . '/evil.json' );
	}

	public function test_an_absolute_query_path_writes_nothing(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$errors   = 0;

		$this->assertNull( cfp_dev_fetch_and_save( '/etc/passwd', $snapshot, $log, $errors ) );
		$this->assertSame( 1, $errors );
	}

	public function test_a_crawl_that_captures_nothing_leaves_offline_mode_off(): void {
		// No API responses registered: every fetch fails, exactly as it would
		// with an unreachable API or a missing CFP.DEV key.
		cfp_dev_start_crawl();
		$snapshot = get_option( 'cfp_dev_crawl_state' )['snapshot'];

		cfp_dev_do_crawl();

		$state = get_option( 'cfp_dev_crawl_state' );
		$this->assertSame( 'error', $state['status'] );
		$this->assertSame( 0, (int) get_option( 'cfp_dev_offline_mode' ), 'an empty snapshot must never be served as the site' );
		$this->assertFileDoesNotExist( $snapshot . '/manifest.json', 'a failed crawl must not be marked complete' );
		$this->assertSame( '', cfp_dev_get_latest_snapshot() );
	}

	public function test_a_new_snapshot_is_not_browsable(): void {
		cfp_dev_start_crawl();
		$snapshot = get_option( 'cfp_dev_crawl_state' )['snapshot'];

		// Snapshots sit under wp-content/uploads, which is web-served. Without
		// these, a host with directory indexing on exposes the whole crawl.
		foreach ( [ '', '/api', '/api/public', '/images' ] as $dir ) {
			$this->assertFileExists( $snapshot . $dir . '/index.php', $dir . ' is listable' );
		}
		$this->assertFileExists( cfp_dev_offline_dir() . '/index.php' );
		$this->assertStringContainsString(
			'Options -Indexes',
			(string) file_get_contents( cfp_dev_offline_dir() . '/.htaccess' )
		);
	}

	private function registerCrawlableApi(): void {
		$speaker = [
			'id'        => 100,
			'firstName' => 'Jane',
			'lastName'  => 'Doe',
			'imageUrl'  => self::IMAGE_URL,
		];

		$this->api(
			'public/event',
			[
				'id'       => 42,
				'name'     => 'Devoxx',
				'timezone' => 'Europe/Brussels',
				'fromDate' => '2025-10-06T07:00:00Z',
				'toDate'   => '2025-10-06T17:00:00Z',
			]
		);
		$this->api(
			'public/tracks',
			[
				[
					'id'   => 10,
					'name' => 'Java',
				],
			]
		);
		$this->api(
			'public/session-types',
			[
				[
					'id'   => 20,
					'name' => 'Conference',
				],
			]
		);
		$this->api(
			'public/rooms',
			[
				[
					'id'   => 1,
					'name' => 'Room 4',
				],
			]
		);
		$this->api( 'public/speakers?size=9999', [ $speaker ] );
		$this->api( 'public/speakers/100', $speaker );
		$this->api( 'public/album/100', [] );
		$this->api(
			'public/talks',
			[
				[
					'id'    => 200,
					'title' => 'A Talk',
				],
			]
		);
		$this->api(
			'public/talks/200',
			[
				'id'    => 200,
				'title' => 'A Talk',
			]
		);
		$this->api( 'public/talks/track/10', [] );
		$this->api( 'public/talks/session-type/20', [] );
		$this->api( 'public/schedules/Monday', [] );
		$this->api( 'public/schedules/Monday/1', [] );

		WP_Test_State::$http_responses[ self::IMAGE_URL ] = [
			'code'    => 200,
			'body'    => 'JPEG-BYTES',
			'headers' => [ 'content-type' => 'image/jpeg' ],
		];
	}

	private function makeSnapshotDir(): string {
		$snapshot = cfp_dev_offline_dir() . '/2025-01-01_00-00-00';
		mkdir( $snapshot . '/api/public', 0777, true );
		mkdir( $snapshot . '/images', 0777, true );
		return $snapshot;
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
