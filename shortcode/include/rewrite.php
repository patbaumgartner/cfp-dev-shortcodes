<?php
/**
 * CFP.DEV shortcodes
 *
 * URL routing, query vars and the plugin lifecycle hooks.
 *
 * @package CFP.DEV
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Registers the rewrite rules for the pretty /speaker/<slug> and /talk/<slug>
 * URLs, honouring the configured URL path prefix.
 */
function cfp_dev_add_rewrite_rules() {
	$prefix = get_option( 'cfp_dev_path_prefix', '' );
	$prefix = $prefix ? $prefix . '/' : '';

	add_rewrite_rule(
		'^' . $prefix . 'speaker/([^/]+)/?$',
		'index.php?pagename=speaker&speaker_slug=$matches[1]',
		'top'
	);

	add_rewrite_rule(
		'^' . $prefix . 'talk/([^/]+)/?$',
		'index.php?pagename=talk&talk_slug=$matches[1]',
		'top'
	);

	add_rewrite_rule(
		'^' . $prefix . 'schedule/?$',
		'index.php?pagename=schedule',
		'top'
	);

	// Handle subdirectory before talk URL with slug (fix for subdirectory redirect)
	if ( ! empty( $prefix ) ) {
		add_rewrite_rule(
			'([^/]+)/' . $prefix . 'talk/([^/]+)/?$',
			'index.php?pagename=talk&talk_slug=$matches[2]',
			'top'
		);
	}
}
add_action( 'init', 'cfp_dev_add_rewrite_rules' );

/**
 * Prepends the configured URL path prefix (e.g. '/trieste') to a plugin path.
 *
 * @param string $path  Site-relative path, e.g. '/talk/my-talk'.
 * @return string
 */
function cfp_dev_url( $path ) {
	$prefix = get_option( 'cfp_dev_path_prefix', '' );
	$prefix = $prefix ? '/' . $prefix : '';
	$url    = $prefix . $path;
	// Match the site's permalink style — un-slashed URLs bounce through redirect_canonical's 301,
	// and canonicals pointing at a redirect form a loop Google reports as "alternate page".
	if ( ! str_contains( $url, '?' ) && ! str_contains( $url, '#' ) ) {
		$url = user_trailingslashit( $url );
	}
	return $url;
}

/**
 * Whether permalinks address content by slug rather than by id.
 *
 * Multisite installs must stay on ids, so this is a setting rather than a
 * property of the permalink structure.
 */
function cfp_dev_uses_slugs(): bool {
	return 'no' === get_option( 'cfp_dev_content_by_id', 'yes' );
}

/**
 * Permalink for a talk, in whichever form the site is configured for.
 *
 * Both fields are optional in every API response that carries a talk, so the
 * caller does not have to prove they are present.
 *
 * @param mixed $talk  Talk object with an id and/or a title.
 */
function cfp_dev_talk_url( $talk ): string {
	return cfp_dev_uses_slugs()
		? cfp_dev_url( '/talk/' . cfp_dev_generate_slug( (string) ( $talk->title ?? '' ) ) )
		: cfp_dev_url( '/talk?id=' . absint( $talk->id ?? 0 ) );
}

/**
 * Permalink for a speaker, in whichever form the site is configured for.
 *
 * @param mixed $speaker  Speaker object with an id and/or a first/last name.
 */
function cfp_dev_speaker_url( $speaker ): string {
	return cfp_dev_uses_slugs()
		? cfp_dev_url( '/speaker/' . cfp_dev_generate_slug( ( $speaker->firstName ?? '' ) . '-' . ( $speaker->lastName ?? '' ) ) )
		: cfp_dev_url( '/speaker?id=' . absint( $speaker->id ?? 0 ) );
}

/**
 * Registers the query vars used by the plugin pages.
 *
 * @param array $vars  Public query vars.
 * @return array
 */
function cfp_dev_add_query_vars( $vars ) {
	$vars[] = 'speaker_slug';
	$vars[] = 'talk_slug';
	$vars[] = 'id';
	$vars[] = 'query';
	return $vars;
}
add_filter( 'query_vars', 'cfp_dev_add_query_vars' );

/** Activation hook: registers rewrite rules and flushes them once. */
function cfp_dev_flush_rewrite_rules() {
	cfp_dev_add_rewrite_rules();
	flush_rewrite_rules();
}
register_activation_hook( CFP_DEV_PLUGIN_FILE, 'cfp_dev_flush_rewrite_rules' );

/**
 * Deactivation hook: leaves no scheduled work or routing behind.
 *
 * The crawl runs from a WP Cron event. Without this, deactivating mid-crawl
 * leaves an event in the cron array pointing at a callback that no longer
 * exists, and the plugin's rewrite rules keep capturing /talk/… and
 * /speaker/… URLs that nothing renders any more.
 *
 * Settings, caches and snapshots are deliberately kept — deactivation is not
 * uninstallation; uninstall.php handles data removal.
 */
function cfp_dev_deactivate() {
	wp_clear_scheduled_hook( 'cfp_dev_do_crawl' );
	flush_rewrite_rules();
}
register_deactivation_hook( CFP_DEV_PLUGIN_FILE, 'cfp_dev_deactivate' );

/**
 * Invalidates cached markup after the plugin is updated.
 *
 * Shortcode output is cached as rendered HTML, so a release that changes
 * that HTML would keep serving the previous version's markup until every
 * transient happened to expire — up to a month on the longest TTL.
 */
function cfp_dev_maybe_upgrade() {
	if ( CFP_DEV_VERSION === get_option( 'cfp_dev_installed_version' ) ) {
		return;
	}

	update_option( 'cfp_dev_installed_version', CFP_DEV_VERSION );
	cfp_dev_clear_cache();
	cfp_dev_log( 'upgrade: caches invalidated for version ' . CFP_DEV_VERSION );
}
add_action( 'init', 'cfp_dev_maybe_upgrade' );

/**
 * Creates the required shortcode pages on plugin activation.
 * Existing pages (any status) are left untouched.
 */
function cfp_dev_create_required_pages() {
	$pages = [
		'speakers'          => [
			'title'   => 'Speakers',
			'content' => '[cfp_speakers]',
		],
		'speaker'           => [
			'title'   => 'Speaker',
			'content' => '[cfp_speaker_details]',
		],
		'talk'              => [
			'title'   => 'Talks',
			'content' => '[cfp_talk_details]',
		],
		'schedule'          => [
			'title'   => 'Schedule',
			'content' => '[cfp_schedule]',
		],
		'search-results'    => [
			'title'   => 'Search Results',
			'content' => '[cfp_search_results]',
		],
		'talks-by-tracks'   => [
			'title'   => 'Talks by Tracks',
			'content' => '[cfp_talks_by_tracks]',
		],
		'talks-by-sessions' => [
			'title'   => 'Talks by Sessions',
			'content' => '[cfp_talks_by_sessions]',
		],
	];

	foreach ( $pages as $slug => $page_data ) {
		// get_page_by_path also finds drafts/private pages, preventing duplicates.
		if ( null === get_page_by_path( $slug, OBJECT, 'page' ) ) {
			wp_insert_post(
				[
					'post_title'   => $page_data['title'],
					'post_name'    => $slug,
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_content' => $page_data['content'],
				]
			);
		}
	}

	flush_rewrite_rules();
}
register_activation_hook( CFP_DEV_PLUGIN_FILE, 'cfp_dev_create_required_pages' );
