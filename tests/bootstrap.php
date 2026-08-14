<?php
/**
 * CFP.DEV shortcodes
 *
 * PHPUnit bootstrap: boots the fake WordPress runtime, then loads the plugin
 * exactly the way WordPress would (main file first, which requires the
 * shortcode modules), and fires `plugins_loaded` so the shortcodes register.
 *
 * @package CFP.DEV
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/stubs/wordpress.php';

// The plugin resolves its own directory from its own __FILE__, and WordPress
// addresses it as a plugin through WP_PLUGIN_DIR, so link this checkout in.
// The link is repointed rather than merely created: a stale link left by an
// earlier run — or by a sibling working tree — would load a different
// checkout's code and quietly report its results as this one's.
if ( ! is_dir( WP_PLUGIN_DIR ) ) {
	mkdir( WP_PLUGIN_DIR, 0777, true );
}
$cfp_dev_plugin_root = dirname( __DIR__ );
$cfp_dev_plugin_link = WP_PLUGIN_DIR . '/cfp-dev-shortcodes';
if ( is_link( $cfp_dev_plugin_link ) && realpath( $cfp_dev_plugin_link ) !== realpath( $cfp_dev_plugin_root ) ) {
	unlink( $cfp_dev_plugin_link );
}
if ( ! file_exists( $cfp_dev_plugin_link ) ) {
	symlink( $cfp_dev_plugin_root, $cfp_dev_plugin_link );
}

// The snapshot pruner require_once's this core file before calling
// WP_Filesystem(); the function itself lives in tests/stubs/wordpress.php.
if ( ! is_dir( ABSPATH . 'wp-admin/includes' ) ) {
	mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
}
if ( ! file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
	file_put_contents( ABSPATH . 'wp-admin/includes/file.php', "<?php\n// Test stand-in for wp-admin/includes/file.php.\n" );
}

require_once dirname( __DIR__ ) . '/cfp-dev-wordpress-shortcodes.php';

// WordPress fires this after all plugins are loaded; the shortcode modules
// register their tags on it.
do_action( 'plugins_loaded' );
