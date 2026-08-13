<?php
/**
 * CFP.DEV shortcodes
 *
 * Uninstall cleanup: removes all plugin options, transients, and offline
 * snapshot files when the plugin is deleted through the WordPress admin.
 *
 * @package  CFP.DEV
 * @since    4.2.0
 */

// Exit if not called by WordPress during plugin deletion.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Options.
// ─────────────────────────────────────────────────────────────────────────────
$cfp_dev_options = [
	'cfp_dev_key',
	'cfp_dev_event_name',
	'cfp_dev_cache_duration',
	'cfp_dev_cache_version',
	'cfp_dev_default_theme',
	'cfp_dev_enable_theme_switch',
	'enable_theme_switch',
	'cfp_dev_path_prefix',
	'cfp_dev_content_by_id',
	'cfp_dev_show_rooms',
	'cfp_dev_offline_mode',
	'cfp_dev_crawl_state',
];

foreach ( $cfp_dev_options as $cfp_dev_option ) {
	delete_option( $cfp_dev_option );
}

// ─────────────────────────────────────────────────────────────────────────────
// Legacy settings transients.
// ─────────────────────────────────────────────────────────────────────────────
delete_transient( 'CFP_DEV_KEY' );
delete_transient( 'CFP_DEV_CACHE' );
delete_transient( 'CFP_DEV_EVENT_NAME' );

// ─────────────────────────────────────────────────────────────────────────────
// Content-cache transients (all keys are prefixed — remove in one query).
// ─────────────────────────────────────────────────────────────────────────────
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

// ─────────────────────────────────────────────────────────────────────────────
// Offline snapshot files.
// ─────────────────────────────────────────────────────────────────────────────
$cfp_dev_offline_dir = WP_CONTENT_DIR . '/uploads/cfp-dev-offline';
if ( is_dir( $cfp_dev_offline_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;
	if ( $wp_filesystem ) {
		$wp_filesystem->delete( $cfp_dev_offline_dir, true );
	}
}
