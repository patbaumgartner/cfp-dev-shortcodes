<?php
/**
 * CFP.DEV shortcodes
 *
 * Caching: the request-scoped memo store and the versioned transient keys
 * every cached entry is filed under.
 *
 * @package CFP.DEV
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Request-scoped memoisation.
 *
 * Several helpers are called repeatedly while rendering a single page — head
 * meta, JSON-LD, the canonical URL and the shortcode itself all resolve the
 * same entity. They share one in-memory store instead of each keeping its own
 * pair of static variables, which also gives long-running processes (WP-CLI,
 * the offline crawler) a way to drop stale request state.
 *
 * @return array<string,mixed>
 */
function &cfp_dev_request_cache(): array {
	static $cache = [];
	return $cache;
}

/**
 * Returns the memoised value for $key, computing it on first access. A
 * resolver returning null is memoised too — "resolved to nothing" is an answer
 * worth remembering.
 *
 * @param string   $key       Cache key.
 * @param callable $resolver  Computes the value on a miss.
 * @return mixed
 */
function cfp_dev_request_cache_get( string $key, callable $resolver ) {
	$cache = &cfp_dev_request_cache();
	if ( ! array_key_exists( $key, $cache ) ) {
		$cache[ $key ] = $resolver();
	}
	return $cache[ $key ];
}

/** Empties the request-scoped memo store. */
function cfp_dev_flush_request_cache(): void {
	$cache = &cfp_dev_request_cache();
	$cache = [];
}

/** Version suffix appended to every transient key (see cfp_dev_clear_cache()). */
function cfp_dev_cache_salt(): string {
	return '_v' . (int) get_option( 'cfp_dev_cache_version', 1 );
}

/**
 * Versioned transient key for a named cache group.
 *
 * @param string $name  Base key name, e.g. 'cfp_schedule_Tuesday'.
 */
function cfp_dev_group_cache_key( string $name ): string {
	return $name . cfp_dev_cache_salt();
}

/**
 * Default attributes for [cfp_speakers] — shared between the shortcode and the
 * admin cache page so both compute the same cache key.
 */
function cfp_dev_speakers_default_atts(): array {
	return [
		'random'      => false,
		'size'        => 300,
		'title'       => '',
		'subtitle'    => '',
		'hide_title'  => false,
		'hide_search' => false,
	];
}

/**
 * Cache key for a [cfp_speakers] rendering. Keyed per attribute set so two
 * pages with different size/title/random no longer serve each other's HTML.
 */
function cfp_dev_speakers_cache_key( array $atts ): string {
	return cfp_dev_group_cache_key( 'speakers_cache_group_' . md5( wp_json_encode( $atts ) ) );
}

/**
 * Cache-key suffix for a shortcode's attribute set: empty for the defaults
 * (so admin tooling can address the standard variant by its plain key),
 * hashed for any customised set.
 *
 * @param array $atts      Normalised attributes.
 * @param array $defaults  The shortcode's default attributes.
 */
function cfp_dev_atts_cache_suffix( array $atts, array $defaults ): string {
	return ( $atts == $defaults ) ? '' : '_' . md5( wp_json_encode( $atts ) ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- order-insensitive array comparison intended
}

/**
 * Versioned transient key for a speaker/talk/photo detail cache.
 *
 * @param string     $type  Entity type: 'speaker', 'talk', or 'photo'.
 * @param string|int $id    Entity id (hashed into the key).
 * @return string
 */
function cfp_dev_detail_cache_key( $type, $id ) {
	switch ( $type ) {
		case 'speaker':
			$key = 'cfp_speaker_details_' . md5( (string) $id );
			break;
		case 'talk':
			$key = 'cfp_talk_details_' . md5( (string) $id );
			break;
		case 'photo':
			$key = 'speaker_photos_' . md5( (string) $id );
			break;
		default:
			$key = 'cfp_' . $type . '_' . md5( (string) $id );
			break;
	}
	return $key . cfp_dev_cache_salt();
}

/**
 * Invalidate every plugin cache in O(1).
 *
 * All transient keys embed the cache version (see cfp_dev_cache_salt()), so
 * bumping the version instantly orphans every cached entry — no API calls, no
 * key enumeration. Orphaned transients expire naturally via their TTL.
 */
function cfp_dev_clear_cache() {
	update_option( 'cfp_dev_cache_version', (int) get_option( 'cfp_dev_cache_version', 1 ) + 1 );
	cfp_dev_log( 'cfp_dev_clear_cache: cache version bumped to ' . get_option( 'cfp_dev_cache_version' ) );
}
