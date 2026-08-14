<?php
/**
 * CFP.DEV shortcodes
 *
 * Small shared helpers: logging, value normalisation, slugs and text
 * trimming. No WordPress state of its own.
 *
 * @package CFP.DEV
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Debug-safe logging helper.
 * Only writes to the error log when WP_DEBUG_LOG is enabled,
 * so no diagnostic data leaks on production sites.
 *
 * @param string $message  Message to log (prefixed with "[CFP.DEV]").
 */
function cfp_dev_log( string $message ): void {
	if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		error_log( '[CFP.DEV] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging gated on WP_DEBUG_LOG
	}
}

/**
 * Normalises a shortcode boolean attribute: 'yes'/'true'/'1' → true,
 * 'no'/'false'/'0'/'' → false (any non-empty string is truthy in plain PHP).
 *
 * @param mixed $value  Raw attribute value.
 */
function cfp_dev_attr_bool( $value ): bool {
	return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
}

/**
 * Returns $value when it is one of $allowed, otherwise $fallback.
 *
 * Settings that feed URLs, CSS classes or API paths must never store an
 * arbitrary string just because an administrator submitted one.
 *
 * @param mixed    $value     Submitted value.
 * @param string[] $allowed   Accepted values (lowercase).
 * @param string   $fallback  Value to use when the input is not accepted.
 */
function cfp_dev_option_choice( $value, array $allowed, string $fallback ): string {
	$value = strtolower( trim( (string) $value ) );
	return in_array( $value, $allowed, true ) ? $value : $fallback;
}

/**
 * usort() comparator: orders speakers by last name, accent-insensitively
 * (transliterated to ASCII so e.g. Šumailov sorts under S).
 */
function cfp_dev_compare_last_name( $x, $y ) {
	return iconv( 'utf-8', 'ascii//TRANSLIT', $x->lastName ) <=> iconv( 'utf-8', 'ascii//TRANSLIT', $y->lastName );
}

/**
 * usort() comparator: orders objects by their name property, accent-insensitively.
 */
function cfp_dev_compare_name( $x, $y ) {
	return iconv( 'utf-8', 'ascii//TRANSLIT', $x->name ) <=> iconv( 'utf-8', 'ascii//TRANSLIT', $y->name );
}

/**
 * Formats a UTC time string in the given timezone.
 *
 * @param string       $time      UTC date/time string.
 * @param DateTimeZone $timezone  Target timezone.
 * @param string       $format    date() format string.
 * @return string
 */
function cfp_dev_format_time( $time, $timezone, $format ) {
	$dt = new DateTime( $time, new DateTimeZone( 'UTC' ) );
	$dt->setTimezone( $timezone );
	return $dt->format( $format );
}

/**
 * Normalises arbitrary text into a URL slug (lowercase, dash-separated).
 *
 * Accented characters are transliterated (Š→s, ü→u) so the result survives
 * WordPress' sanitize_title() on the lookup side — turning them into dashes
 * produced double-dash slugs that sanitize_title() collapses, so speakers
 * with non-ASCII names could never be resolved.
 *
 * @param string $input  Text to slugify, e.g. a speaker name or talk title.
 * @return string
 */
function cfp_dev_generate_slug( $input ) {
	$slug = strtolower( trim( preg_replace( '/[^A-Za-z0-9-]+/', '-', remove_accents( (string) $input ) ), '-' ) );
	return preg_replace( '/-{2,}/', '-', $slug );
}

/**
 * Collapses whitespace and trims text to a meta-description-sized excerpt.
 *
 * @param string $text    Raw text (may contain HTML).
 * @param int    $length  Maximum length in characters.
 * @return string
 */
function cfp_dev_meta_excerpt( $text, $length = 160 ) {
	$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
	if ( mb_strlen( $text ) <= $length ) {
		return $text;
	}
	$cut   = mb_substr( $text, 0, $length );
	$space = mb_strrpos( $cut, ' ' );
	return ( false !== $space ? mb_substr( $cut, 0, $space ) : $cut ) . '…';
}

/**
 * Rejects image URLs that are unusable for social cards — cfp.dev track
 * images are often tiny Google-cache thumbnails (~90px), which produce
 * blurry share previews. Returning '' lets pages fall back to the site's
 * default social image.
 *
 * @param string $url  Candidate image URL.
 * @return string
 */
function cfp_dev_usable_image( $url ) {
	$url = (string) $url;
	if ( '' === $url || str_contains( $url, 'gstatic.com/images' ) ) {
		return '';
	}
	return $url;
}
