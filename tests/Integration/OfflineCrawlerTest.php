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
		$tally    = cfp_dev_new_error_tally();
		$this->api( 'public/speakers?size=9999', [ [ 'id' => 1 ] ] );

		$decoded = cfp_dev_fetch_and_save( 'public/speakers?size=9999', $snapshot, $log, $tally );

		$this->assertSame( 1, $decoded[0]->id );
		$this->assertFileExists( $snapshot . '/api/public/speakers.json' );
		$this->assertSame( cfp_dev_new_error_tally(), $tally );
		$this->assertSame( 200, $log[0]['status'] );
	}

	public function test_an_empty_endpoint_is_saved_as_an_empty_array(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$tally    = cfp_dev_new_error_tally();
		$this->api( 'public/schedules/Monday/9', '', 204 );

		// A room with no sessions is valid; offline reads must return [] rather
		// than null, which callers treat as "endpoint unavailable".
		$this->assertSame( [], cfp_dev_fetch_and_save( 'public/schedules/Monday/9', $snapshot, $log, $tally ) );
		$this->assertSame( '[]', file_get_contents( $snapshot . '/api/public/schedules/Monday/9.json' ) );
		$this->assertSame( cfp_dev_new_error_tally(), $tally );
	}

	public function test_a_failed_endpoint_is_counted_and_writes_no_file(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$tally    = cfp_dev_new_error_tally();
		$this->api( 'public/tracks', null, 500 );

		$this->assertNull( cfp_dev_fetch_and_save( 'public/tracks', $snapshot, $log, $tally ) );
		$this->assertSame( 1, $tally['required'], 'a missing endpoint makes the snapshot unfit to serve' );
		$this->assertSame( 1, $tally['total'] );
		$this->assertFileDoesNotExist( $snapshot . '/api/public/tracks.json' );
	}

	/**
	 * A 200 is not a promise of JSON. Storing a proxy error page or a truncated
	 * response as though it were data hides the failure until offline mode is
	 * serving it, on a live page.
	 */
	public function test_a_two_hundred_that_is_not_json_writes_no_file(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$tally    = cfp_dev_new_error_tally();
		$this->api( 'public/tracks', '<html>Gateway Timeout</html>' );

		$this->assertNull( cfp_dev_fetch_and_save( 'public/tracks', $snapshot, $log, $tally ) );
		$this->assertSame( 1, $tally['required'] );
		$this->assertFileDoesNotExist( $snapshot . '/api/public/tracks.json' );
	}

	public function test_an_optional_endpoint_failure_is_counted_but_does_not_spoil_the_snapshot(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$tally    = cfp_dev_new_error_tally();
		$this->api( 'public/album/7', null, 404 );

		// Most speakers have no photo album; that is normal, not a reason to
		// throw the whole crawl away.
		cfp_dev_fetch_and_save( 'public/album/7', $snapshot, $log, $tally, 10, true );

		$this->assertSame( 0, $tally['required'] );
		$this->assertSame( 1, $tally['total'] );
		$this->assertSame( 1, $tally['albums'], 'an optional miss must be reported as a harmless album miss' );
		$this->assertNotEmpty( $log );
	}

	/**
	 * The status box tells the harmless from the actionable, so an image
	 * failure must land in the images bucket — not the albums one.
	 */
	public function test_an_image_failure_is_categorised_as_an_image(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$tally    = cfp_dev_new_error_tally();

		$this->assertFalse( cfp_dev_download_image( 'https://cdn.test/gone2.jpg', $snapshot . '/images/g2.jpg', $log, $tally ) );
		$this->assertSame( 1, $tally['total'] );
		$this->assertSame( 1, $tally['images'] );
		$this->assertSame( 0, $tally['albums'] );
		$this->assertSame( 0, $tally['required'] );
	}

	public function test_the_manifest_failure_list_names_each_failed_url_once_with_its_reason(): void {
		$snapshot = $this->makeSnapshotDir();
		file_put_contents(
			$snapshot . '/manifest.json',
			(string) wp_json_encode(
				[
					'log' => [
						[
							'url'    => 'https://x.cfp.dev/api/public/event',
							'status' => 200,
						],
						[
							'url'    => 'https://x.cfp.dev/api/public/schedules/Monday/9',
							'status' => 204,
						],
						[
							'url'    => 'https://cdn.test/cached.jpg',
							'status' => 'cached',
						],
						// A failed endpoint is logged twice: status code, then reason.
						[
							'url'    => 'https://x.cfp.dev/api/public/album/7',
							'status' => 404,
						],
						[
							'url'    => 'https://x.cfp.dev/api/public/album/7',
							'status' => 'error',
							'msg'    => 'HTTP 404',
						],
						[
							'url'    => 'https://cdn.test/broken.jpg',
							'status' => 500,
						],
					],
				]
			)
		);

		$failures = cfp_dev_snapshot_fetch_errors( $snapshot );

		$this->assertCount( 2, $failures, 'successes, empties, cache hits and duplicates must not be listed' );
		$this->assertSame( 'https://x.cfp.dev/api/public/album/7', $failures[0]['url'] );
		$this->assertSame( 'HTTP 404', $failures[0]['reason'] );
		$this->assertSame( 'https://cdn.test/broken.jpg', $failures[1]['url'] );
		$this->assertSame( 'HTTP 500', $failures[1]['reason'] );
	}

	public function test_the_failure_list_is_empty_when_the_manifest_is_missing_or_malformed(): void {
		$snapshot = $this->makeSnapshotDir();

		$this->assertSame( [], cfp_dev_snapshot_fetch_errors( $snapshot ) );

		file_put_contents( $snapshot . '/manifest.json', '{broken' );
		$this->assertSame( [], cfp_dev_snapshot_fetch_errors( $snapshot ) );
	}

	public function test_a_downloaded_image_is_written_to_disk(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$tally    = cfp_dev_new_error_tally();
		$this->image( self::IMAGE_URL, 'JPEG-BYTES' );

		$this->assertTrue( cfp_dev_download_image( self::IMAGE_URL, $snapshot . '/images/a.jpg', $log, $tally ) );
		$this->assertSame( 'JPEG-BYTES', file_get_contents( $snapshot . '/images/a.jpg' ) );
		$this->assertSame( 0, $tally['total'] );
	}

	public function test_a_non_image_response_is_rejected(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$tally    = cfp_dev_new_error_tally();
		// A hostile upstream can point an image field anywhere; the served
		// content type decides, not the URL.
		$this->image( 'https://cdn.test/payload.jpg', '<?php echo 1;', 'text/html' );

		$this->assertFalse( cfp_dev_download_image( 'https://cdn.test/payload.jpg', $snapshot . '/images/p.jpg', $log, $tally ) );
		$this->assertFileDoesNotExist( $snapshot . '/images/p.jpg' );
		$this->assertSame( 1, $tally['images'] );
	}

	/**
	 * S3 serves files uploaded without a content type as binary/octet-stream —
	 * every speaker photo on such a bucket. The header carries no information
	 * then, so the bytes decide, against the same allow-list.
	 */
	public function test_a_real_image_behind_a_generic_content_type_is_accepted(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$tally    = cfp_dev_new_error_tally();
		$gif      = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a 1x1 GIF fixture, binary bytes have no readable form
		$this->image( 'https://s3.test/profile-1.jpg', $gif, 'binary/octet-stream' );

		$this->assertTrue( cfp_dev_download_image( 'https://s3.test/profile-1.jpg', $snapshot . '/images/a.jpg', $log, $tally ) );
		$this->assertSame( $gif, file_get_contents( $snapshot . '/images/a.jpg' ) );
		$this->assertSame( 0, $tally['total'] );
	}

	public function test_non_image_bytes_behind_a_generic_content_type_are_rejected(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$tally    = cfp_dev_new_error_tally();
		$this->image( 'https://s3.test/payload.jpg', '<?php echo 1;', 'application/octet-stream' );

		$this->assertFalse( cfp_dev_download_image( 'https://s3.test/payload.jpg', $snapshot . '/images/p.jpg', $log, $tally ) );
		$this->assertFileDoesNotExist( $snapshot . '/images/p.jpg' );
		$this->assertSame( 1, $tally['images'] );
	}

	/** The sniff must not become an SVG smuggling path: markup is not a raster image. */
	public function test_svg_bytes_behind_a_generic_content_type_are_rejected(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$tally    = cfp_dev_new_error_tally();
		$this->image( 'https://s3.test/logo.jpg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'binary/octet-stream' );

		$this->assertFalse( cfp_dev_download_image( 'https://s3.test/logo.jpg', $snapshot . '/images/l.jpg', $log, $tally ) );
		$this->assertFileDoesNotExist( $snapshot . '/images/l.jpg' );
		$this->assertSame( 1, $tally['images'] );
	}

	/**
	 * The snapshot is served from the site's own origin, so an SVG published
	 * there is same-origin script waiting for a visit — which is why
	 * WordPress core refuses SVG uploads. The URL comes from a service the
	 * plugin does not control, so the served type is not a reason to trust it.
	 */
	public function test_an_svg_response_is_not_published_under_the_site_origin(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$tally    = cfp_dev_new_error_tally();
		$this->image( 'https://cdn.test/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml' );

		$this->assertFalse( cfp_dev_download_image( 'https://cdn.test/logo.svg', $snapshot . '/images/l.svg', $log, $tally ) );
		$this->assertFileDoesNotExist( $snapshot . '/images/l.svg' );
		$this->assertSame( 1, $tally['images'] );
	}

	public function test_an_image_on_a_private_address_is_not_fetched(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$tally    = cfp_dev_new_error_tally();
		$this->image( 'http://127.0.0.1/secret.jpg', 'INTERNAL' );

		$this->assertFalse( cfp_dev_download_image( 'http://127.0.0.1/secret.jpg', $snapshot . '/images/s.jpg', $log, $tally ) );
		$this->assertFileDoesNotExist( $snapshot . '/images/s.jpg' );
		$this->assertSame( [], $this->httpLog(), 'a loopback URL must never be requested' );
	}

	public function test_a_non_http_image_scheme_is_rejected(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$tally    = cfp_dev_new_error_tally();

		$this->assertFalse( cfp_dev_download_image( 'file:///etc/passwd', $snapshot . '/images/f.jpg', $log, $tally ) );
		$this->assertSame( 1, $tally['images'] );
	}

	public function test_an_unreachable_image_is_counted_as_an_error(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$tally    = cfp_dev_new_error_tally();

		$this->assertFalse( cfp_dev_download_image( 'https://cdn.test/gone.jpg', $snapshot . '/images/g.jpg', $log, $tally ) );
		$this->assertSame( 1, $tally['images'] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Scheduling
	// ─────────────────────────────────────────────────────────────────────────

	public function test_starting_a_crawl_creates_a_snapshot_and_schedules_the_job(): void {
		$this->assertTrue( cfp_dev_start_crawl() );

		$state = get_option( 'cfp_dev_crawl_state' );

		$this->assertSame( 'pending', $state['status'] );
		$this->assertDirectoryExists( $state['snapshot'] . '/api/public' );
		$this->assertDirectoryExists( $state['snapshot'] . '/images' );
		$this->assertSame( 'cfp_dev_do_crawl', WP_Test_State::$env['scheduled'][0]['hook'] );
		$this->assertTrue( WP_Test_State::$env['cron_spawned'] );
	}

	/**
	 * The crawl state is shared, so a second crawl would interleave its
	 * progress with the first and both would race to publish a snapshot.
	 */
	public function test_a_second_crawl_is_refused_while_one_is_running(): void {
		cfp_dev_start_crawl();
		$first = get_option( 'cfp_dev_crawl_state' )['snapshot'];

		$this->assertFalse( cfp_dev_start_crawl() );
		$this->assertSame( $first, get_option( 'cfp_dev_crawl_state' )['snapshot'] );
	}

	/**
	 * `doing_cron` is WordPress' own site-wide lock, not this plugin's.
	 * Deleting it to force a spawn also lets every unrelated cron job start
	 * again alongside the ones already running.
	 */
	public function test_starting_a_crawl_leaves_the_site_cron_lock_alone(): void {
		set_transient( 'doing_cron', '1755000000.0', 60 );

		cfp_dev_start_crawl();

		$this->assertNotFalse( get_transient( 'doing_cron' ), 'the shared cron lock was deleted' );
		$this->assertSame( 'cfp_dev_do_crawl', WP_Test_State::$env['scheduled'][0]['hook'] );
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

	/**
	 * Offline mode promises the site works with no external requests, so a
	 * snapshot missing an endpoint is a snapshot that would serve a broken
	 * page for as long as it stayed active. This used to finish as "done" and
	 * switch offline mode on with, in this case, no rooms — which is the whole
	 * schedule.
	 */
	public function test_a_missing_required_endpoint_leaves_offline_mode_off(): void {
		$this->registerCrawlableApi();
		$this->api( 'public/rooms', null, 503 );
		cfp_dev_start_crawl();
		$snapshot = get_option( 'cfp_dev_crawl_state' )['snapshot'];

		cfp_dev_do_crawl();

		$state = get_option( 'cfp_dev_crawl_state' );
		$this->assertSame( 'error', $state['status'] );
		$this->assertStringContainsString( 'required endpoint', $state['step_label'] );
		$this->assertSame( 0, (int) get_option( 'cfp_dev_offline_mode' ) );
		$this->assertFileDoesNotExist( $snapshot . '/manifest.json' );
	}

	/** Same rule for a 200 carrying something that is not JSON. */
	public function test_a_non_json_response_leaves_offline_mode_off(): void {
		$this->registerCrawlableApi();
		$this->api( 'public/tracks', '<html>Gateway Timeout</html>' );
		cfp_dev_start_crawl();

		cfp_dev_do_crawl();

		$this->assertSame( 'error', get_option( 'cfp_dev_crawl_state' )['status'] );
		$this->assertSame( 0, (int) get_option( 'cfp_dev_offline_mode' ) );
	}

	/** An album nobody has, or an image behind a 500, is not a broken snapshot. */
	public function test_optional_failures_still_produce_a_usable_snapshot(): void {
		$this->registerCrawlableApi();
		$this->api( 'public/album/100', null, 404 );
		$this->image( self::IMAGE_URL, '', 'text/html', 500 );
		cfp_dev_start_crawl();

		cfp_dev_do_crawl();

		$state = get_option( 'cfp_dev_crawl_state' );
		$this->assertSame( 'done', $state['status'] );
		$this->assertGreaterThan( 0, $state['errors'], 'the operator should still see what failed' );
		$this->assertSame( 1, (int) get_option( 'cfp_dev_offline_mode' ) );
	}

	/** A broken crawl must not cost the operator the snapshot they were serving. */
	public function test_a_failed_crawl_leaves_the_previous_snapshot_serving(): void {
		$this->registerCrawlableApi();
		cfp_dev_start_crawl();
		cfp_dev_do_crawl();
		$good = cfp_dev_get_latest_snapshot();
		$this->assertNotSame( '', $good );

		// A second crawl, this time against a failing API.
		$this->api( 'public/rooms', null, 503 );
		cfp_dev_start_crawl();
		$this->assertNotSame( $good, get_option( 'cfp_dev_crawl_state' )['snapshot'], 'the new crawl reused the good snapshot directory' );
		cfp_dev_do_crawl();

		$this->assertSame( $good, cfp_dev_get_latest_snapshot(), 'the working snapshot was replaced by a broken one' );
		$this->assertFileExists( $good . '/manifest.json' );
	}

	/**
	 * Snapshot directories are named by the second, so a crawl started in the
	 * same second as the last one wrote into its directory — overwriting a
	 * good snapshot's files while leaving its manifest in place, which marks
	 * the wreckage as complete.
	 */
	public function test_two_crawls_in_the_same_second_get_their_own_directories(): void {
		$this->registerCrawlableApi();
		cfp_dev_start_crawl();
		$first = get_option( 'cfp_dev_crawl_state' )['snapshot'];
		cfp_dev_do_crawl();

		cfp_dev_start_crawl();
		$second = get_option( 'cfp_dev_crawl_state' )['snapshot'];

		$this->assertNotSame( $first, $second );
		$this->assertGreaterThan( basename( $first ), basename( $second ), 'the newer snapshot must still sort last' );
	}

	/**
	 * The day names are the ones the schedule page asks for, and that page
	 * resolves them in the event's own timezone. Deriving them in UTC meant an
	 * event starting late in the local evening was crawled as the previous
	 * day, and the page then asked for a day the snapshot did not contain.
	 */
	public function test_days_are_crawled_in_the_event_timezone(): void {
		$this->registerCrawlableApi();
		// 23:30 UTC on Monday is 01:30 on Tuesday in Europe/Brussels.
		$this->api(
			'public/event',
			[
				'id'       => 42,
				'name'     => 'Devoxx',
				'timezone' => 'Europe/Brussels',
				'fromDate' => '2025-10-06T23:30:00Z',
				'toDate'   => '2025-10-07T03:00:00Z',
			]
		);
		$this->api( 'public/schedules/Tuesday', [] );
		$this->api( 'public/schedules/Tuesday/1', [] );

		cfp_dev_start_crawl();
		$snapshot = get_option( 'cfp_dev_crawl_state' )['snapshot'];

		cfp_dev_do_crawl();

		$this->assertSame( 'done', get_option( 'cfp_dev_crawl_state' )['status'] );
		$this->assertFileExists( $snapshot . '/api/public/schedules/Tuesday.json', 'the day the page will ask for' );
		$this->assertFileDoesNotExist( $snapshot . '/api/public/schedules/Monday.json' );
	}

	/** A single-day event carries only fromDate. */
	public function test_an_event_with_no_end_date_is_crawled_as_one_day(): void {
		$this->registerCrawlableApi();
		$this->api(
			'public/event',
			[
				'id'       => 42,
				'name'     => 'Devoxx',
				'timezone' => 'Europe/Brussels',
				'fromDate' => '2025-10-06T07:00:00Z',
			]
		);

		cfp_dev_start_crawl();
		$snapshot = get_option( 'cfp_dev_crawl_state' )['snapshot'];

		cfp_dev_do_crawl();

		$this->assertFileExists( $snapshot . '/api/public/schedules/Monday.json' );
		$this->assertFileDoesNotExist( $snapshot . '/api/public/schedules/Tuesday.json' );
	}

	// ─────────────────────────────────────────────────────────────────────────

	/** Registers every endpoint a one-speaker, one-talk, one-day event needs. */
	public function test_a_traversing_query_path_writes_nothing(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$tally    = cfp_dev_new_error_tally();

		// Ids come from the upstream API, so they choose part of the write path.
		$this->assertNull( cfp_dev_fetch_and_save( 'public/speakers/../../../evil', $snapshot, $log, $tally ) );
		$this->assertSame( 1, $tally['required'] );
		$this->assertSame( [], $this->httpLog(), 'a rejected path must not be requested either' );
		$this->assertFileDoesNotExist( dirname( $snapshot ) . '/evil.json' );
	}

	public function test_an_absolute_query_path_writes_nothing(): void {
		$snapshot = $this->makeSnapshotDir();
		$log      = [];
		$tally    = cfp_dev_new_error_tally();

		$this->assertNull( cfp_dev_fetch_and_save( '/etc/passwd', $snapshot, $log, $tally ) );
		$this->assertSame( 1, $tally['required'] );
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
