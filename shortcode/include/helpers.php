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
 * Collation key for a name: transliterated to ASCII so that e.g. Šumailov
 * sorts under S rather than after Z.
 *
 * @param mixed $value  Name as the API sent it, possibly absent.
 */
function cfp_dev_sort_key( $value ): string {
	return (string) iconv( 'utf-8', 'ascii//TRANSLIT', (string) $value );
}

/** usort() comparator: orders speakers by last name, accent-insensitively. */
function cfp_dev_compare_last_name( $x, $y ) {
	return cfp_dev_sort_key( $x->lastName ?? '' ) <=> cfp_dev_sort_key( $y->lastName ?? '' );
}

/** usort() comparator: orders objects by their name property, accent-insensitively. */
function cfp_dev_compare_name( $x, $y ) {
	return cfp_dev_sort_key( $x->name ?? '' ) <=> cfp_dev_sort_key( $y->name ?? '' );
}

/**
 * Builds a timezone from an API-supplied name, or null when it is unusable.
 *
 * Dates and zones arrive as free-form strings from a service this plugin does
 * not control, and both constructors throw on anything they cannot parse. An
 * uncaught throw here is a white screen on a public page, so every such value
 * is turned into null and handled as missing data instead.
 *
 * @param mixed $name  Timezone identifier, e.g. 'Europe/Brussels'.
 */
function cfp_dev_timezone( $name ): ?DateTimeZone {
	if ( ! is_string( $name ) || '' === $name ) {
		return null;
	}
	try {
		return new DateTimeZone( $name );
	} catch ( Exception $e ) {
		cfp_dev_log( 'unusable timezone from API (' . $name . ') — ' . $e->getMessage() );
		return null;
	}
}

/**
 * Builds a date from an API-supplied value, or null when it is unusable.
 *
 * @param mixed             $value     Date/time string, e.g. '2025-10-06T08:30:00Z'.
 * @param DateTimeZone|null $timezone  Zone to assume when the value carries no
 *                                     offset, and to convert the result into.
 *                                     Defaults to UTC, which is what the API sends.
 */
function cfp_dev_date( $value, ?DateTimeZone $timezone = null ): ?DateTimeImmutable {
	if ( ! is_string( $value ) || '' === $value ) {
		return null;
	}
	try {
		$date = new DateTimeImmutable( $value, $timezone ?? new DateTimeZone( 'UTC' ) );
	} catch ( Exception $e ) {
		cfp_dev_log( 'unusable date from API (' . $value . ') — ' . $e->getMessage() );
		return null;
	}
	return null === $timezone ? $date : $date->setTimezone( $timezone );
}

/**
 * Formats a UTC time string in the given timezone.
 *
 * @param mixed        $time      UTC date/time string.
 * @param DateTimeZone $timezone  Target timezone.
 * @param string       $format    date() format string.
 * @return string  Formatted time, or '' when the value is unusable.
 */
function cfp_dev_format_time( $time, $timezone, $format ) {
	$date = cfp_dev_date( $time );
	return null === $date ? '' : $date->setTimezone( $timezone )->format( $format );
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
