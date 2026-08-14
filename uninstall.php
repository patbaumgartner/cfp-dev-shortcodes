<?php
/**
 * CFP.DEV shortcodes
 *
 * Uninstall cleanup: removes all plugin options, transients, scheduled events
 * and offline snapshot files when the plugin is deleted through the WordPress
 * admin.
 *
 * @package  CFP.DEV
 * @since    4.2.0
 */

// Exit if not called by WordPress during plugin deletion.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Removes every trace of the plugin from the current site.
 *
 * Called once per site, so on a network install it runs inside
 * switch_to_blog() for each of them — options and transients are per-site
 * tables, and cleaning only the site that happened to trigger the uninstall
 * would leave the rest of the network with orphaned rows.
 */
if ( ! function_exists( 'cfp_dev_uninstall_site' ) ) {
	function cfp_dev_uninstall_site() {
		$options = [
			'cfp_dev_key',
			'cfp_dev_event_name',
			'cfp_dev_cache_duration',
			'cfp_dev_cache_version',
			'cfp_dev_installed_version',
			'cfp_dev_default_theme',
			'cfp_dev_enable_theme_switch',
			'enable_theme_switch',
			'cfp_dev_path_prefix',
			'cfp_dev_content_by_id',
			'cfp_dev_show_rooms',
			'cfp_dev_offline_mode',
			'cfp_dev_crawl_state',
		];

		foreach ( $options as $option ) {
			delete_option( $option );
		}

		// Legacy settings transients.
		delete_transient( 'CFP_DEV_KEY' );
		delete_transient( 'CFP_DEV_CACHE' );
		delete_transient( 'CFP_DEV_EVENT_NAME' );

		// A crawl may still be scheduled; its callback disappears with the plugin.
		wp_clear_scheduled_hook( 'cfp_dev_do_crawl' );

		/*
		 * Content-cache transients (all keys are prefixed — removed in one query).
		 *
		 * Only reaches transients stored in the options table. On sites running a
		 * persistent object cache the transients live there instead and are not
		 * visible to SQL; they carry a TTL and expire on their own.
		 */
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '\_transient\_cfp\_%'
			    OR option_name LIKE '\_transient\_timeout\_cfp\_%'
			    OR option_name LIKE '\_transient\_speakers\_cache\_group%'
			    OR option_name LIKE '\_transient\_timeout\_speakers\_cache\_group%'
			    OR option_name LIKE '\_transient\_speaker\_photos\_%'
			    OR option_name LIKE '\_transient\_timeout\_speaker\_photos\_%'
			    OR option_name LIKE '\_transient\_talks\_by\_%'
			    OR option_name LIKE '\_transient\_timeout\_talks\_by\_%'"
		);
	}
}

if ( is_multisite() ) {
	$cfp_dev_site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);
	foreach ( $cfp_dev_site_ids as $cfp_dev_site_id ) {
		switch_to_blog( $cfp_dev_site_id );
		cfp_dev_uninstall_site();
		restore_current_blog();
	}
	unset( $cfp_dev_site_ids, $cfp_dev_site_id );
} else {
	cfp_dev_uninstall_site();
}

/*
 * Offline snapshot files.
 *
 * Outside the per-site loop: uploads live under one wp-content directory, and
 * the snapshot path is not site-scoped.
 */
$cfp_dev_offline_dir = WP_CONTENT_DIR . '/uploads/cfp-dev-offline';
if ( is_dir( $cfp_dev_offline_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;
	if ( $wp_filesystem ) {
		$wp_filesystem->delete( $cfp_dev_offline_dir, true );
	}
}
unset( $cfp_dev_offline_dir );
