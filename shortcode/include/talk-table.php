<?php
/**
 * CFP.DEV shortcodes
 *
 * Shared renderer for the talk table used by [cfp_talks_by_tracks] and
 * [cfp_talks_by_sessions]. Both listed talks with their own copy of the same
 * markup, which drifted apart — only one of them fell back to the nested
 * track image, and neither tolerated a talk without speakers.
 *
 * @package  CFP.DEV
 * @since    4.5.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Renders the table heading row.
 *
 * @return string
 */
function cfp_dev_talk_table_heading() {
	$content  = '    <div class="cfp-row cfp-headline">';
	$content .= '        <div class="cfp-field">Title</div>';
	$content .= '        <div class="cfp-field cfp-speaker">Speakers</div>';
	$content .= '        <div class="cfp-field">Track</div>';
	$content .= '        <div class="cfp-field"></div>';
	$content .= '    </div>';
	return $content;
}

/**
 * Renders one table row per talk (title, speakers, track image, view link).
 *
 * Every field is optional in the API response, so each is read defensively.
 *
 * @param array|null $talks  Talks from the API, or null on failure.
 * @return string
 */
function cfp_dev_talk_table_rows( $talks ) {
	if ( empty( $talks ) || ! is_array( $talks ) ) {
		return '';
	}

	$use_slugs = ( 'no' === get_option( 'cfp_dev_content_by_id', 'yes' ) );
	$content   = '';

	foreach ( $talks as $talk ) {
		$title    = (string) ( $talk->title ?? '' );
		$talk_url = $use_slugs
			? cfp_dev_url( '/talk/' . cfp_dev_generate_slug( $title ) )
			: cfp_dev_url( '/talk?id=' . absint( $talk->id ?? 0 ) );

		// The list endpoint sends a flat trackImageURL; the detail endpoint
		// nests it under track.
		$track_image = (string) ( $talk->trackImageURL ?? '' );
		if ( '' === $track_image ) {
			$track_image = (string) ( $talk->track->imageURL ?? '' );
		}

		$content .= '<article class="cfp-article cfp-row cfp-event">';
		$content .= '    <div class="cfp-field">' . esc_html( $title ) . '</div>';
		$content .= '    <div class="cfp-field cfp-speaker">';
		$content .= cfp_dev_talk_table_speakers( $talk, $use_slugs );
		$content .= '    </div>';
		$content .= '    <div class="cfp-field">';
		$content .= '        <div class="cfp-track" style="background-image: url(\'' . esc_url( $track_image ) . '\')"></div>';
		$content .= '    </div>';
		$content .= '    <div class="cfp-field">';
		$content .= '        <a class="cfp-a" href="' . esc_url( $talk_url ) . '">View</a>';
		$content .= '    </div>';
		$content .= '</article>';
	}

	return $content;
}

/**
 * Renders the linked speaker names for one talk row.
 *
 * @param object $talk       Talk object from the API.
 * @param bool   $use_slugs  Whether permalinks use slugs instead of ids.
 * @return string
 */
function cfp_dev_talk_table_speakers( $talk, $use_slugs ) {
	$content = '';

	foreach ( (array) ( $talk->speakers ?? [] ) as $speaker ) {
		$first = (string) ( $speaker->firstName ?? '' );
		$last  = (string) ( $speaker->lastName ?? '' );
		$url   = $use_slugs
			? cfp_dev_url( '/speaker/' . cfp_dev_generate_slug( $first . '-' . $last ) )
			: cfp_dev_url( '/speaker?id=' . absint( $speaker->id ?? 0 ) );

		$content .= '<a class="cfp-a" href="' . esc_url( $url ) . '">' . esc_html( $first ) . '&nbsp;' . esc_html( $last ) . '</a>';
	}

	return $content;
}
