<?php
/**
 * CFP.DEV shortcodes
 *
 * Minimal WordPress runtime for the test suite.
 *
 * This is deliberately *not* a WordPress install: it implements the handful of
 * WordPress APIs the plugin actually calls, backed by plain in-memory arrays
 * (options, transients, query vars, HTTP responses). That keeps the suite fast
 * and hermetic, and makes every side effect (which option was written, which
 * URL was fetched) directly assertable.
 *
 * Fidelity notes — where behaviour is approximated rather than copied:
 *   - esc_url() implements WordPress' character allow-list, protocol check and
 *     the `'` → `&#039;` / `&` → `&#038;` display encoding, which is what the
 *     plugin's output escaping depends on.
 *   - wp_kses_post() strips script/style/iframe elements and on* handlers
 *     rather than running the full KSES allow-list.
 *   - remove_accents() covers Latin-1 Supplement and Latin Extended-A, which is
 *     the range slug generation cares about.
 *
 * @package CFP.DEV
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
// phpcs:disable WordPress.WP.GlobalVariablesOverride

// ─────────────────────────────────────────────────────────────────────────
// Constants
// ─────────────────────────────────────────────────────────────────────────

define( 'WPINC', 'wp-includes' );
define( 'ABSPATH', sys_get_temp_dir() . '/cfp-dev-tests/wordpress/' );
define( 'WP_CONTENT_DIR', rtrim( ABSPATH, '/' ) . '/wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'WP_PLUGIN_URL', 'https://example.test/wp-content/plugins' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MB_IN_BYTES', 1048576 );
define( 'OBJECT', 'OBJECT' );

/**
 * In-memory state for the fake WordPress runtime.
 *
 * Reset between tests by {@see \CfpDev\Tests\WordPressState::reset()}.
 */
final class WP_Test_State { // phpcs:ignore
	/** @var array<string,mixed> Option name => value. */
	public static array $options = [];

	/** @var array<string,mixed> Transient name => value. */
	public static array $transients = [];

	/** @var array<string,mixed> Query var name => value. */
	public static array $query_vars = [];

	/** @var string[] Slugs the current request is considered to be. */
	public static array $current_page = [];

	/** @var array<string,array{code:int,body:string,headers?:array<string,string>}> URL => canned response. */
	public static array $http_responses = [];

	/** @var string[] Every URL passed to wp_remote_get(), in order. */
	public static array $http_log = [];

	/** @var array<int,array{url:string,args:array,safe:bool}> Every request with its arguments, in order. */
	public static array $http_requests = [];

	/** @var array<string,array<int,array{callback:callable,priority:int}>> Hook name => callbacks. */
	public static array $hooks = [];

	/** @var array<string,callable> Shortcode tag => callback. */
	public static array $shortcodes = [];

	/** @var string[] Handles passed to wp_enqueue_script()/wp_enqueue_style(). */
	public static array $enqueued = [];

	/** @var array<string,array<string,mixed>> Handle => the arguments it was registered with. */
	public static array $enqueued_assets = [];

	/** @var string[] Theme features registered via add_theme_support(). */
	public static array $theme_support = [];

	/** @var array<string,mixed> Assorted request-scoped values (permalink, doc title, …). */
	public static array $env = [];

	/** @var mixed[] Payloads passed to wp_send_json_success()/wp_send_json_error(). */
	public static array $json_responses = [];
}

// ─────────────────────────────────────────────────────────────────────────
// Options & transients
// ─────────────────────────────────────────────────────────────────────────

function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, WP_Test_State::$options ) ? WP_Test_State::$options[ $name ] : $default_value;
}

function update_option( $name, $value, $autoload = null ) {
	unset( $autoload );
	WP_Test_State::$options[ $name ] = $value;
	return true;
}

function add_option( $name, $value = '' ) {
	if ( array_key_exists( $name, WP_Test_State::$options ) ) {
		return false;
	}
	WP_Test_State::$options[ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( WP_Test_State::$options[ $name ] );
	return true;
}

function get_transient( $key ) {
	return array_key_exists( $key, WP_Test_State::$transients ) ? WP_Test_State::$transients[ $key ] : false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	unset( $ttl );
	WP_Test_State::$transients[ $key ] = $value;
	return true;
}

function delete_transient( $key ) {
	$existed = array_key_exists( $key, WP_Test_State::$transients );
	unset( WP_Test_State::$transients[ $key ] );
	return $existed;
}

// ─────────────────────────────────────────────────────────────────────────
// Hooks, filters & shortcodes
// ─────────────────────────────────────────────────────────────────────────

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	unset( $accepted_args );
	WP_Test_State::$hooks[ $hook ][] = [
		'callback' => $callback,
		'priority' => $priority,
	];
	return true;
}

function do_action( $hook, ...$args ) {
	foreach ( WP_Test_State::$hooks[ $hook ] ?? [] as $entry ) {
		call_user_func_array( $entry['callback'], $args );
	}
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( WP_Test_State::$hooks[ $hook ] ?? [] as $entry ) {
		$value = call_user_func_array( $entry['callback'], array_merge( [ $value ], $args ) );
	}
	return $value;
}

function has_action( $hook, $callback = false ) {
	foreach ( WP_Test_State::$hooks[ $hook ] ?? [] as $entry ) {
		if ( false === $callback || $entry['callback'] === $callback ) {
			return true;
		}
	}
	return false;
}

function add_shortcode( $tag, $callback ) {
	WP_Test_State::$shortcodes[ $tag ] = $callback;
}

function shortcode_exists( $tag ) {
	return isset( WP_Test_State::$shortcodes[ $tag ] );
}

function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	unset( $shortcode );
	$atts = (array) $atts;
	$out  = [];
	foreach ( $pairs as $name => $default_value ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default_value;
	}
	return $out;
}

function has_shortcode( $content, $tag ) {
	return is_string( $content ) && false !== strpos( $content, '[' . $tag );
}

function register_activation_hook( $file, $callback ) {
	WP_Test_State::$hooks['activate'][] = [
		'callback' => $callback,
		'priority' => 10,
		'file'     => $file,
	];
}

function register_deactivation_hook( $file, $callback ) {
	WP_Test_State::$hooks['deactivate'][] = [
		'callback' => $callback,
		'priority' => 10,
		'file'     => $file,
	];
}

function add_theme_support( $feature ) {
	WP_Test_State::$theme_support[] = $feature;
}

function current_theme_supports( $feature ) {
	return in_array( $feature, WP_Test_State::$theme_support, true );
}

// ─────────────────────────────────────────────────────────────────────────
// HTTP
// ─────────────────────────────────────────────────────────────────────────

class WP_Error { // phpcs:ignore
	/** @var string */
	private string $message;

	public function __construct( $code = '', $message = '' ) {
		unset( $code );
		$this->message = (string) $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_remote_get( $url, $args = [] ) {
	return cfp_dev_test_http_request( $url, $args, false );
}

/**
 * wp_safe_remote_get() differs from wp_remote_get() by running the URL through
 * wp_http_validate_url(), which rejects loopback and private-range hosts. The
 * stub models that rejection so SSRF guards are actually exercised.
 */
function wp_safe_remote_get( $url, $args = [] ) {
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );

	$is_blocked = in_array( $host, [ 'localhost', '127.0.0.1', '::1', '0.0.0.0' ], true )
		|| ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) );

	if ( $is_blocked ) {
		WP_Test_State::$http_requests[] = [
			'url'  => $url,
			'args' => $args,
			'safe' => true,
		];
		return new WP_Error( 'http_request_failed', 'A valid URL was not provided.' );
	}

	return cfp_dev_test_http_request( $url, $args, true );
}

/** Shared request handling for the wp_remote_get()/wp_safe_remote_get() stubs. */
function cfp_dev_test_http_request( $url, $args, $safe ) {
	WP_Test_State::$http_log[]      = $url;
	WP_Test_State::$http_requests[] = [
		'url'  => $url,
		'args' => is_array( $args ) ? $args : [],
		'safe' => (bool) $safe,
	];

	if ( ! isset( WP_Test_State::$http_responses[ $url ] ) ) {
		return new WP_Error( 'http_request_failed', 'No canned response registered for ' . $url );
	}
	return WP_Test_State::$http_responses[ $url ];
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? ( $response['code'] ?? 0 ) : 0;
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? ( $response['body'] ?? '' ) : '';
}

function wp_remote_retrieve_header( $response, $name ) {
	if ( ! is_array( $response ) || ! isset( $response['headers'] ) ) {
		return '';
	}
	// Real WordPress lower-cases header names on retrieval.
	$headers = array_change_key_case( (array) $response['headers'], CASE_LOWER );
	return $headers[ strtolower( (string) $name ) ] ?? '';
}

// ─────────────────────────────────────────────────────────────────────────
// Escaping & sanitisation
// ─────────────────────────────────────────────────────────────────────────

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_textarea( $text ) {
	return esc_html( $text );
}

function esc_html__( $text, $domain = '' ) {
	unset( $domain );
	return esc_html( $text );
}

function esc_html_e( $text, $domain = '' ) {
	unset( $domain );
	echo esc_html( $text ); // phpcs:ignore
}

function __( $text, $domain = '' ) { // phpcs:ignore
	unset( $domain );
	return $text;
}

function _e( $text, $domain = '' ) { // phpcs:ignore
	unset( $domain );
	echo $text; // phpcs:ignore
}

function esc_attr__( $text, $domain = '' ) {
	unset( $domain );
	return esc_attr( $text );
}

function esc_attr_e( $text, $domain = '' ) {
	unset( $domain );
	echo esc_attr( $text ); // phpcs:ignore
}

function _x( $text, $context, $domain = '' ) { // phpcs:ignore
	unset( $context, $domain );
	return $text;
}

function esc_html_x( $text, $context, $domain = '' ) {
	unset( $context, $domain );
	return esc_html( $text );
}

function _n( $single, $plural, $number, $domain = '' ) { // phpcs:ignore
	unset( $domain );
	return 1 === (int) $number ? $single : $plural;
}

function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = '' ) {
	unset( $deprecated, $plugin_rel_path );
	WP_Test_State::$env['textdomain'] = $domain;
	return true;
}

/**
 * Mirrors WordPress' esc_url(): protocol allow-list, character allow-list and
 * the display-context entity encoding the plugin's markup relies on.
 */
function esc_url( $url, $protocols = null, $context = 'display' ) {
	unset( $protocols );
	$url = (string) $url;
	if ( '' === $url ) {
		return '';
	}

	$url = str_replace( [ ' ', "\t", "\n", "\r", '%0d', '%0a', '%0D', '%0A' ], '', $url );
	$url = preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\\\\x80-\\xff]|i', '', $url );
	$url = str_replace( ';//', '://', $url );

	if ( '' !== $url && '/' !== $url[0] && '#' !== $url[0] && '?' !== $url[0] ) {
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		if ( '' !== $scheme && ! in_array( $scheme, [ 'http', 'https', 'mailto', 'ftp', 'ftps' ], true ) ) {
			return '';
		}
	}

	if ( 'display' === $context ) {
		$url = str_replace( '&amp;', '&#038;', $url );
		$url = str_replace( "'", '&#039;', $url );
	}
	return $url;
}

function esc_url_raw( $url ) {
	return esc_url( $url, null, 'raw' );
}

function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = (string) $text;
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
	$text = strip_tags( (string) $text );
	if ( $remove_breaks ) {
		$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
	}
	return trim( $text );
}

/**
 * Declared with a non-nullable string so tests fail on a null argument:
 * WordPress' own wp_kses_post() passes it straight to preg_replace(),
 * which has emitted a deprecation for null since PHP 8.1.
 */
function wp_kses_post( string $content ) {
	$content = preg_replace( '@<(script|style|iframe|object|embed)[^>]*?>.*?</\\1>@si', '', $content );
	$content = preg_replace( '@<(script|style|iframe|object|embed)\b[^>]*/?>@si', '', $content );
	$content = preg_replace( '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $content );
	$content = preg_replace( '/(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\\2/i', '', $content );
	return $content;
}

function sanitize_text_field( $str ) {
	$str = wp_strip_all_tags( (string) $str );
	$str = preg_replace( '/[\r\n\t]+/', ' ', $str );
	$str = preg_replace( '/%[a-f0-9]{2}/i', '', $str );
	return trim( preg_replace( '/ +/', ' ', (string) $str ) );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_title( $title ) {
	$title = remove_accents( (string) $title );
	$title = strtolower( wp_strip_all_tags( $title ) );
	$title = preg_replace( '/[^a-z0-9\s\-_]/', '', $title );
	$title = preg_replace( '/[\s\-_]+/', '-', $title );
	return trim( $title, '-' );
}

/**
 * Covers Latin-1 Supplement and Latin Extended-A, the range slug generation
 * needs. Anything outside it falls through to iconv transliteration.
 */
function remove_accents( $text ) {
	$text = (string) $text;
	if ( ! preg_match( '/[\x80-\xff]/', $text ) ) {
		return $text;
	}

	static $chars = [
		'À' => 'A',
		'Á' => 'A',
		'Â' => 'A',
		'Ã' => 'A',
		'Ä' => 'A',
		'Å' => 'A',
		'Æ' => 'AE',
		'Ç' => 'C',
		'È' => 'E',
		'É' => 'E',
		'Ê' => 'E',
		'Ë' => 'E',
		'Ì' => 'I',
		'Í' => 'I',
		'Î' => 'I',
		'Ï' => 'I',
		'Ñ' => 'N',
		'Ò' => 'O',
		'Ó' => 'O',
		'Ô' => 'O',
		'Õ' => 'O',
		'Ö' => 'O',
		'Ø' => 'O',
		'Ù' => 'U',
		'Ú' => 'U',
		'Û' => 'U',
		'Ü' => 'U',
		'Ý' => 'Y',
		'ß' => 's',
		'à' => 'a',
		'á' => 'a',
		'â' => 'a',
		'ã' => 'a',
		'ä' => 'a',
		'å' => 'a',
		'æ' => 'ae',
		'ç' => 'c',
		'è' => 'e',
		'é' => 'e',
		'ê' => 'e',
		'ë' => 'e',
		'ì' => 'i',
		'í' => 'i',
		'î' => 'i',
		'ï' => 'i',
		'ñ' => 'n',
		'ò' => 'o',
		'ó' => 'o',
		'ô' => 'o',
		'õ' => 'o',
		'ö' => 'o',
		'ø' => 'o',
		'ù' => 'u',
		'ú' => 'u',
		'û' => 'u',
		'ü' => 'u',
		'ý' => 'y',
		'ÿ' => 'y',
		'Č' => 'C',
		'č' => 'c',
		'Ď' => 'D',
		'ď' => 'd',
		'Ě' => 'E',
		'ě' => 'e',
		'Ł' => 'L',
		'ł' => 'l',
		'Ń' => 'N',
		'ń' => 'n',
		'Ř' => 'R',
		'ř' => 'r',
		'Š' => 'S',
		'š' => 's',
		'Ť' => 'T',
		'ť' => 't',
		'Ů' => 'U',
		'ů' => 'u',
		'Ž' => 'Z',
		'ž' => 'z',
		'Ș' => 'S',
		'ș' => 's',
		'Ț' => 'T',
		'ț' => 't',
	];

	$text = strtr( $text, $chars );

	if ( preg_match( '/[\x80-\xff]/', $text ) ) {
		$converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT', $text ); // phpcs:ignore
		if ( is_string( $converted ) ) {
			$text = $converted;
		}
	}
	return $text;
}

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth ); // phpcs:ignore
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component ); // phpcs:ignore
}

function selected( $selected, $current = true, $display = true ) {
	$result = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
	if ( $display ) {
		echo $result; // phpcs:ignore
	}
	return $result;
}

function checked( $checked, $current = true, $display = true ) {
	$result = ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
	if ( $display ) {
		echo $result; // phpcs:ignore
	}
	return $result;
}

// ─────────────────────────────────────────────────────────────────────────
// URLs, routing & query
// ─────────────────────────────────────────────────────────────────────────

function home_url( $path = '' ) {
	return 'https://example.test' . ( '' !== $path && '/' !== $path[0] ? '/' : '' ) . $path;
}

function site_url( $path = '' ) {
	return home_url( $path );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function content_url( $path = '' ) {
	return 'https://example.test/wp-content/' . ltrim( (string) $path, '/' );
}

function plugins_url( $path = '', $plugin = '' ) {
	unset( $plugin );
	return WP_PLUGIN_URL . '/cfp-dev-shortcodes/' . ltrim( (string) $path, '/' );
}

function plugin_dir_url( $file ) {
	unset( $file );
	return WP_PLUGIN_URL . '/cfp-dev-shortcodes/';
}

function plugin_basename( $file ) {
	unset( $file );
	return 'cfp-dev-shortcodes/cfp-dev-wordpress-shortcodes.php';
}

function plugin_dir_path( $file ) {
	return rtrim( str_replace( '\\', '/', dirname( (string) $file ) ), '/' ) . '/';
}

// ─────────────────────────────────────────────────────────────────────────
// Multisite & database
// ─────────────────────────────────────────────────────────────────────────

function is_multisite() {
	return (bool) ( WP_Test_State::$env['multisite'] ?? false );
}

function get_sites( $args = [] ) {
	unset( $args );
	return WP_Test_State::$env['site_ids'] ?? [ 1 ];
}

function switch_to_blog( $site_id ) {
	WP_Test_State::$env['current_blog'] = $site_id;
	return true;
}

function restore_current_blog() {
	WP_Test_State::$env['current_blog'] = 1;
	return true;
}

/**
 * Minimal $wpdb stand-in: records queries and applies the transient-cleanup
 * DELETE against the in-memory option store, so uninstall behaviour is
 * assertable without a database.
 */
class WP_Test_Wpdb { // phpcs:ignore
	/** @var string */
	public string $options = 'wp_options';

	/** @var string[] Every query passed to query(). */
	public array $queries = [];

	public function query( $sql ) {
		$this->queries[] = $sql;

		if ( ! preg_match_all( "/option_name LIKE '([^']+)'/", $sql, $matches ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $matches[1] as $pattern ) {
			// MySQL LIKE: '\_' is a literal underscore, '%' matches anything.
			$regex = '/^' . str_replace(
				[ '\\\\_', '%' ],
				[ '_', '.*' ],
				preg_quote( $pattern, '/' )
			) . '$/';
			// preg_quote escaped the wildcards too; undo that.
			$regex = str_replace( [ '\\\\_', '\\%', '\\.\\*' ], [ '_', '%', '.*' ], $regex );

			foreach ( array_keys( WP_Test_State::$transients ) as $name ) {
				if ( preg_match( $regex, '_transient_' . $name ) ) {
					unset( WP_Test_State::$transients[ $name ] );
					++$deleted;
				}
			}
		}
		return $deleted;
	}
}

function user_trailingslashit( $path, $type = '' ) {
	unset( $type );
	return rtrim( (string) $path, '/' ) . '/';
}

function trailingslashit( $path ) {
	return rtrim( (string) $path, '/\\' ) . '/';
}

function add_query_arg( $args, $url = '' ) {
	$separator = str_contains( $url, '?' ) ? '&' : '?';
	return $url . $separator . http_build_query( $args );
}

function get_query_var( $name, $default_value = '' ) {
	return WP_Test_State::$query_vars[ $name ] ?? $default_value;
}

function set_query_var( $name, $value ) {
	WP_Test_State::$query_vars[ $name ] = $value;
}

function is_page( $page = '' ) {
	if ( '' === $page ) {
		return ! empty( WP_Test_State::$current_page );
	}
	foreach ( (array) $page as $slug ) {
		if ( in_array( $slug, WP_Test_State::$current_page, true ) ) {
			return true;
		}
	}
	return false;
}

function is_admin() {
	return ! empty( WP_Test_State::$env['is_admin'] );
}

function is_singular( $type = '' ) {
	unset( $type );
	return ! empty( WP_Test_State::$current_page );
}

function get_permalink( $post = 0 ) {
	unset( $post );
	return WP_Test_State::$env['permalink'] ?? 'https://example.test/current-page/';
}

function get_post( $post = null ) {
	unset( $post );
	return WP_Test_State::$env['post'] ?? null;
}

function get_queried_object() {
	return WP_Test_State::$env['post'] ?? null;
}

function get_page_by_path( $path, $output = OBJECT, $post_type = 'page' ) {
	unset( $output, $post_type );
	return WP_Test_State::$env['pages'][ $path ] ?? null;
}

function wp_insert_post( $postarr ) {
	WP_Test_State::$env['inserted_posts'][] = $postarr;
	return count( WP_Test_State::$env['inserted_posts'] );
}

function add_rewrite_rule( $regex, $query, $after = 'bottom' ) {
	unset( $after );
	WP_Test_State::$env['rewrite_rules'][ $regex ] = $query;
}

function flush_rewrite_rules( $hard = true ) {
	unset( $hard );
	WP_Test_State::$env['rewrite_flushes'] = ( WP_Test_State::$env['rewrite_flushes'] ?? 0 ) + 1;
}

function status_header( $code ) {
	WP_Test_State::$env['status_header'] = $code;
}

function nocache_headers() {
	WP_Test_State::$env['nocache'] = true;
}

function wp_get_document_title() {
	return WP_Test_State::$env['document_title'] ?? 'Document title';
}

function wp_date( $format, $timestamp = null, $timezone = null ) {
	$timestamp = $timestamp ?? time();
	$tz        = $timezone instanceof DateTimeZone
		? $timezone
		: new DateTimeZone( WP_Test_State::$env['timezone'] ?? 'UTC' );
	return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $tz )->format( $format );
}

function wp_timezone() {
	return new DateTimeZone( WP_Test_State::$env['timezone'] ?? 'UTC' );
}

// ─────────────────────────────────────────────────────────────────────────
// Assets, admin & AJAX
// ─────────────────────────────────────────────────────────────────────────

function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $args = [] ) {
	WP_Test_State::$enqueued[]                 = $handle;
	WP_Test_State::$enqueued_assets[ $handle ] = [
		'type' => 'script',
		'src'  => $src,
		'deps' => $deps,
		'ver'  => $ver,
		'args' => $args,
	];
}

function wp_enqueue_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) {
	WP_Test_State::$enqueued[]                 = $handle;
	WP_Test_State::$enqueued_assets[ $handle ] = [
		'type'  => 'style',
		'src'   => $src,
		'deps'  => $deps,
		'ver'   => $ver,
		'media' => $media,
	];
}

function wp_register_script( $handle, $src = '', $deps = [], $ver = false, $args = [] ) {
	unset( $handle, $src, $deps, $ver, $args );
	return true;
}

function wp_register_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) {
	unset( $handle, $src, $deps, $ver, $media );
	return true;
}

function wp_localize_script( $handle, $name, $data ) {
	WP_Test_State::$env['localized'][ $handle ][ $name ] = $data;
	return true;
}

function add_options_page( $page_title, $menu_title, $capability, $slug, $callback = null ) {
	unset( $page_title, $menu_title, $capability );
	WP_Test_State::$env['options_pages'][ $slug ] = $callback;
	return $slug;
}

function current_user_can( $capability ) {
	return in_array( $capability, WP_Test_State::$env['capabilities'] ?? [], true );
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	return ( 'valid-nonce-' . $action ) === $nonce ? 1 : false;
}

function wp_create_nonce( $action = -1 ) {
	return 'valid-nonce-' . $action;
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
	unset( $referer );
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
	if ( $display ) {
		echo $field; // phpcs:ignore
	}
	return $field;
}

function wp_send_json_success( $data = null ) {
	WP_Test_State::$json_responses[] = [
		'success' => true,
		'data'    => $data,
	];
	throw new \CfpDev\Tests\JsonResponseSent();
}

function wp_send_json_error( $data = null ) {
	WP_Test_State::$json_responses[] = [
		'success' => false,
		'data'    => $data,
	];
	throw new \CfpDev\Tests\JsonResponseSent();
}

function wp_die( $message = '' ) {
	throw new \CfpDev\Tests\WpDieException( is_string( $message ) ? $message : '' );
}

// ─────────────────────────────────────────────────────────────────────────
// Filesystem & cron
// ─────────────────────────────────────────────────────────────────────────

function wp_mkdir_p( $target ) {
	return is_dir( $target ) || mkdir( $target, 0777, true );
}

/**
 * Normalises a filesystem path: backslashes to forward slashes and collapsed
 * duplicate slashes, matching WordPress' own implementation.
 */
function wp_normalize_path( $path ) {
	$path = str_replace( '\\', '/', (string) $path );
	$path = preg_replace( '|(?<=.)/+|', '/', $path );
	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}
	return $path;
}

/** Minimal stand-in for WP_Filesystem_Direct — only delete() is used. */
class WP_Test_Filesystem { // phpcs:ignore
	public function delete( $path, $recursive = false, $type = false ) {
		unset( $type );
		if ( is_file( $path ) || is_link( $path ) ) {
			return unlink( $path );
		}
		if ( ! is_dir( $path ) ) {
			return false;
		}
		if ( ! $recursive ) {
			return rmdir( $path );
		}
		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$this->delete( $path . '/' . $entry, true );
		}
		return rmdir( $path );
	}
}

function WP_Filesystem( $args = false, $context = false, $allow_relaxed_file_ownership = false ) { // phpcs:ignore
	unset( $args, $context, $allow_relaxed_file_ownership );
	global $wp_filesystem;
	if ( ! $wp_filesystem instanceof WP_Test_Filesystem ) {
		$wp_filesystem = new WP_Test_Filesystem();
	}
	return true;
}

function wp_schedule_single_event( $timestamp, $hook, $args = [] ) {
	unset( $args );
	WP_Test_State::$env['scheduled'][] = [
		'timestamp' => $timestamp,
		'hook'      => $hook,
	];
	return true;
}

function wp_clear_scheduled_hook( $hook, $args = [] ) {
	unset( $args );

	$scheduled = WP_Test_State::$env['scheduled'] ?? [];
	$remaining = array_values(
		array_filter(
			$scheduled,
			static function ( $event ) use ( $hook ) {
				return $event['hook'] !== $hook;
			}
		)
	);

	WP_Test_State::$env['scheduled'] = $remaining;

	return count( $scheduled ) - count( $remaining );
}

function wp_next_scheduled( $hook, $args = [] ) {
	unset( $args );

	$timestamps = [];
	foreach ( WP_Test_State::$env['scheduled'] ?? [] as $event ) {
		if ( $event['hook'] === $hook ) {
			$timestamps[] = $event['timestamp'];
		}
	}

	return empty( $timestamps ) ? false : min( $timestamps );
}

function spawn_cron( $gmt_time = 0 ) {
	unset( $gmt_time );
	WP_Test_State::$env['cron_spawned'] = true;
}

function _get_cron_array() {
	return WP_Test_State::$env['cron_array'] ?? [];
}

function _set_cron_array( $cron ) {
	WP_Test_State::$env['cron_array'] = $cron;
	return true;
}

// ─────────────────────────────────────────────────────────────────────────
// Sitemaps
// ─────────────────────────────────────────────────────────────────────────

/** Minimal stand-in for the WP_Post the queried object provides. */
class WP_Post { // phpcs:ignore
	/** @var string */
	public $post_content;

	/** @var string */
	public $post_type = 'page';

	public function __construct( $post_content = '' ) {
		$this->post_content = (string) $post_content;
	}
}

function __return_true() { // phpcs:ignore
	return true;
}

function __return_false() { // phpcs:ignore
	return false;
}

abstract class WP_Sitemaps_Provider { // phpcs:ignore
	/** @var string */
	public $name = '';

	/** @var string */
	public $object_type = '';

	abstract public function get_url_list( $page_num, $object_subtype = '' );

	abstract public function get_max_num_pages( $object_subtype = '' );
}
