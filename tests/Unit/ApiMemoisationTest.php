<?php
/**
 * CFP.DEV shortcodes
 *
 * Tests for request-level API memoisation and the shared-object hazards it
 * would otherwise expose.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

namespace CfpDev\Tests\Unit;

use CfpDev\Tests\Fixtures;
use CfpDev\Tests\PluginTestCase;

final class ApiMemoisationTest extends PluginTestCase {

	public function test_the_same_endpoint_is_fetched_once_per_request(): void {
		$this->api( 'public/talks', Fixtures::talks() );

		getJSON( 'public/talks' );
		getJSON( 'public/talks' );
		getJSON( 'public/talks' );

		$this->assertSame( 1, $this->apiCallCount( 'public/talks' ) );
	}

	public function test_distinct_endpoints_are_cached_separately(): void {
		$this->api( 'public/talks', Fixtures::talks() );
		$this->api( 'public/tracks', Fixtures::tracks() );

		$this->assertCount( 2, getJSON( 'public/talks' ) );
		$this->assertCount( 2, getJSON( 'public/tracks' ) );
		$this->assertSame( 'Java', getJSON( 'public/tracks' )[0]->name );
	}

	public function test_a_failed_lookup_is_not_retried_within_the_request(): void {
		$this->api( 'public/talks/404', null, 404 );

		$this->assertNull( getJSON( 'public/talks/404' ) );
		$this->assertNull( getJSON( 'public/talks/404' ) );
		$this->assertSame( 1, $this->apiCallCount( 'public/talks/404' ) );
	}

	public function test_flushing_the_request_cache_allows_a_refetch(): void {
		$this->api( 'public/talks', Fixtures::talks() );

		getJSON( 'public/talks' );
		cfp_dev_flush_request_cache();
		getJSON( 'public/talks' );

		$this->assertSame( 2, $this->apiCallCount( 'public/talks' ) );
	}

	public function test_rendering_a_talk_does_not_consume_its_own_time_slots(): void {
		// Rendering used to array_pop() the slots off the decoded object; with a
		// shared instance the second render would find none left.
		$this->registerDefaultApi();
		$this->search( 'Modern Java in Practice A talk about Java.', [] );
		$this->queryVar( 'id', 200 );

		$first  = cfp_talk_details_shortcode();
		$second = cfp_talk_details_shortcode();

		$this->assertStringContainsString( 'Room 4', $first );
		$this->assertSame( $first, $second, 'a second render must produce identical markup' );
	}

	public function test_head_meta_reuses_the_data_the_shortcode_already_fetched(): void {
		$this->registerDefaultApi();
		$this->search( 'Modern Java in Practice A talk about Java.', [] );
		$this->onPage( 'talk' );
		$this->queryVar( 'id', 200 );

		cfp_talk_details_shortcode();
		cfp_dev_page_meta();
		ob_start();
		cfp_dev_output_jsonld();
		ob_end_clean();

		$this->assertSame( 1, $this->apiCallCount( 'public/talks/200' ) );
	}

	public function test_a_speaker_page_fetches_each_talk_detail_once(): void {
		$this->registerDefaultApi();
		$this->queryVar( 'id', 100 );

		cfp_speaker_details_shortcode();

		// The speaker's single proposal needs its detail record for the time
		// slot; the sitemap/head layer must not fetch it again.
		cfp_dev_sitemap_urls();

		$this->assertSame( 1, $this->apiCallCount( 'public/talks/200' ) );
		$this->assertSame( 1, $this->apiCallCount( 'public/talks' ) );
	}

	public function test_memoised_responses_are_isolated_from_caller_mutation(): void {
		$this->api( 'public/talks/200', Fixtures::talkDetail( 200 ) );

		$first = getJSON( 'public/talks/200' );
		array_pop( $first->timeSlots );
		$first->title = 'Mutated';

		$second = getJSON( 'public/talks/200' );

		$this->assertCount( 1, $second->timeSlots );
		$this->assertSame( 'Modern Java in Practice', $second->title );
	}
}
