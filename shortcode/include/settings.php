<?php
/**
 * CFP.DEV shortcodes
 *
 * Settings accessors and writers.
 *
 * Settings live in options (autoloaded, never evicted). Legacy installs stored
 * them in transients — which object caches may evict at any time — so the
 * accessors transparently migrate values from the legacy transient location.
 *
 * @package CFP.DEV
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/** Returns the CFP.DEV instance key (the *.cfp.dev subdomain). */
function cfp_dev_get_key(): string {
	$key = get_option( 'cfp_dev_key', false );
	if ( false === $key ) {
		$key = get_transient( 'CFP_DEV_KEY' ); // Legacy storage location.
		if ( is_string( $key ) && '' !== $key ) {
			update_option( 'cfp_dev_key', $key );
		}
	}
	return is_string( $key ) ? $key : '';
}

/** Returns the event display name used in titles and meta tags. */
function cfp_dev_get_event_name(): string {
	$name = get_option( 'cfp_dev_event_name', false );
	if ( false === $name ) {
		$name = get_transient( 'CFP_DEV_EVENT_NAME' ); // Legacy storage location.
		if ( is_string( $name ) && '' !== $name ) {
			update_option( 'cfp_dev_event_name', $name );
		}
	}
	return is_string( $name ) ? $name : '';
}

/** Returns the cache TTL in seconds (0 = caching disabled). */
function cfp_dev_get_cache_ttl(): int {
	$ttl = get_option( 'cfp_dev_cache_duration', false );
	if ( false === $ttl ) {
		$ttl = get_transient( 'CFP_DEV_CACHE' ); // Legacy storage location.
		if ( false !== $ttl ) {
			update_option( 'cfp_dev_cache_duration', (int) $ttl );
		}
	}
	return max( 0, (int) $ttl );
}

/** Base URL of the CFP.DEV REST API for the configured instance. */
function cfp_dev_api_base(): string {
	return 'https://' . rawurlencode( cfp_dev_get_key() ) . '.cfp.dev/api/';
}

/** Base URL of the semantic search service (the query term is appended by callers). */
function cfp_dev_search_base(): string {
	return 'https://search.cfp.dev?cfp=' . rawurlencode( cfp_dev_get_key() ) . '&accepted=true&total=5&query=';
}

/**
 * Normalises the URL path prefix to slash-separated slugs.
 *
 * The prefix is interpolated into rewrite-rule *regular expressions*, so
 * characters like `.` or `(` would silently change which URLs match.
 *
 * @param mixed $value  Submitted prefix, e.g. ' /Trieste/ '.
 */
function cfp_dev_sanitize_path_prefix( $value ): string {
	$segments = array_filter( array_map( 'sanitize_title', explode( '/', (string) $value ) ) );
	return implode( '/', $segments );
}

/**
 * Whether the light/dark footer toggle is enabled.
 *
 * Migrates the pre-4.5.0 unprefixed `enable_theme_switch` option, which
 * squatted on a name any other plugin could have been using.
 */
function cfp_dev_theme_switch_enabled(): bool {
	$enabled = get_option( 'cfp_dev_enable_theme_switch', false );

	if ( false === $enabled ) {
		$legacy = get_option( 'enable_theme_switch', false );
		if ( false !== $legacy ) {
			$enabled = $legacy;
			update_option( 'cfp_dev_enable_theme_switch', (int) (bool) $legacy );
			delete_option( 'enable_theme_switch' );
		}
	}

	return (bool) $enabled;
}

/**
 * Persists the CFP.DEV instance key, sanitised to safe hostname characters.
 *
 * @param string $key  Raw key from the settings form.
 */
function cfp_dev_store_key( $key ) {
	// The key is a cfp.dev subdomain — restrict to safe hostname characters so it
	// cannot alter the API URL (e.g. via dots or slashes).
	$key = strtolower( preg_replace( '/[^A-Za-z0-9-]/', '', (string) $key ) );
	update_option( 'cfp_dev_key', $key );
	delete_transient( 'CFP_DEV_KEY' ); // Legacy storage location.
}

/**
 * Persists the cache TTL.
 *
 * @param int|string $ttl  TTL in seconds (0 disables caching).
 */
function cfp_dev_store_cache_ttl( $ttl ) {
	update_option( 'cfp_dev_cache_duration', max( 0, (int) $ttl ) );
	delete_transient( 'CFP_DEV_CACHE' ); // Legacy storage location.
}

/**
 * Persists the event display name.
 *
 * @param string $cfp_dev_event_name  Event name from the settings form.
 */
function cfp_dev_store_event_name( $cfp_dev_event_name ) {
	update_option( 'cfp_dev_event_name', sanitize_text_field( (string) $cfp_dev_event_name ) );
	delete_transient( 'CFP_DEV_EVENT_NAME' ); // Legacy storage location.
}
