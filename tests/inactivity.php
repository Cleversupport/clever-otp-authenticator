<?php
/** Isolated contract tests; run with php tests/inactivity.php. No WordPress services required. */
error_reporting( E_ALL );
set_error_handler( function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'OTPA_PLUGIN_URL', 'https://example.test/plugin/' );
class Otpa_Hide_Login_Module { const SETTINGS_GROUP = 'hide'; }
class WP_Error { public function __construct( $code, $message, $data ) {} }
class Response extends Exception { public $data; public function __construct( $data ) { $this->data = $data; } }
class WP_Session_Tokens {
	public static function get_instance( $id ) { return new self(); }
	public function get( $token ) { return array( 'login' => $GLOBALS['login'] ); }
	public function verify( $token ) { return ! empty( $GLOBALS['valid_tokens'][$token] ); }
}
$options = array(); $meta = array(); $hooks = array(); $scripts = array(); $token = 'session-a'; $logged = true; $roles = array( 'subscriber' ); $super = false; $ajax = false; $nonce = true; $login = time(); $blog = 1; $valid_tokens = array( 'session-a' => true, 'independent-session' => true ); $pagenow = 'index.php';
function get_option( $key, $default ) { return isset( $GLOBALS['options'][ $key ] ) ? $GLOBALS['options'][ $key ] : $default; }
function admin_url( $path ) { return home_url( '/wp-admin/' . $path ); }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function esc_url_raw( $url, $protocols ) { return preg_match( '#^https?://#', $url ) ? $url : ''; }
function wp_validate_redirect( $url, $fallback ) { return $url; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $hook ] = $callback; }
function add_filter( $hook, $callback, $priority = 10 ) { add_action( $hook, $callback, $priority ); }
function wp_get_current_user() { return new class { public $ID = 7; public $roles; public function __construct() { $this->roles = $GLOBALS['roles']; } public function exists() { return $GLOBALS['logged']; } }; }
function wp_get_session_token() { return $GLOBALS['logged'] ? $GLOBALS['token'] : ''; }
function user_can( $user, $cap ) { return in_array( 'administrator', $user->roles, true ); }
function is_multisite() { return true; }
function is_super_admin( $id ) { return $GLOBALS['super']; }
function get_current_blog_id() { return $GLOBALS['blog']; }
function get_current_user_id() { return 7; }
function wp_salt( $scheme ) { return 'test-salt'; }
function get_user_meta( $id, $key = '', $single = false ) { if ( '' === $key ) { return $GLOBALS['meta']; } return isset( $GLOBALS['meta'][ $key ] ) ? $GLOBALS['meta'][ $key ] : ''; }
function add_user_meta( $id, $key, $value, $unique ) { if ( ! isset( $GLOBALS['meta'][ $key ] ) ) { $GLOBALS['meta'][ $key ] = $value; } }
function update_user_meta( $id, $key, $value, $previous ) { if ( $GLOBALS['meta'][ $key ] == $previous ) { $GLOBALS['meta'][ $key ] = $value; } }
function wp_cache_delete( $id, $group ) { if ( isset( $GLOBALS['concurrent_activity'] ) ) { $GLOBALS['meta'][ Otpa_Inactivity_Module::key() ] = $GLOBALS['concurrent_activity']; unset( $GLOBALS['concurrent_activity'] ); } }
function delete_user_meta( $id, $key ) { unset( $GLOBALS['meta'][ $key ] ); }
function wp_logout() {
	unset( $GLOBALS['valid_tokens'][$GLOBALS['token']] );
	// Both old and current WordPress fire this hook before clearing current user/cookies.
	call_user_func( $GLOBALS['hooks']['clear_auth_cookie'] );
	$GLOBALS['logged'] = false; $GLOBALS['token'] = '';
}
function wp_doing_ajax() { return $GLOBALS['ajax']; }
function wp_send_json_error( $data, $status ) { throw new Response( array( 'success' => false, 'data' => $data, 'status' => $status ) ); }
function wp_send_json_success( $data ) { throw new Response( array( 'success' => true, 'data' => $data ) ); }
function check_ajax_referer( $action, $field ) { if ( ! $GLOBALS['nonce'] ) { throw new Response( array( 'status' => 403 ) ); } }
function sanitize_key( $value ) { return $value; }
function wp_unslash( $value ) { return $value; }
function wp_create_nonce( $action ) { return 'fresh-nonce'; }
function wp_enqueue_script( $handle, $url, $deps, $version, $footer ) { $GLOBALS['scripts'][] = $handle; }
function wp_enqueue_style( $handle, $url, $deps, $version ) {}
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['config'] = $data; }
function wp_logout_url( $redirect ) { return 'https://example.test/wp-login.php?action=logout&_wpnonce=test'; }
function __( $text, $domain ) { return $text; }
require __DIR__ . '/../inc/modules/inactivity/class-otpa-inactivity-module.php';
function expect( $condition, $label ) { if ( ! $condition ) { throw new Exception( $label ); } echo "PASS: $label\n"; }
function endpoint( $mode ) { $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST['mode'] = $mode; try { Otpa_Inactivity_Module::endpoint(); } catch ( Response $r ) { return $r->data; } }
$s = Otpa_Inactivity_Module::sanitize( array( 'timeout' => -2, 'warning_minutes' => 90, 'redirect' => 'https://evil.test/' ) );
expect( 30 === $s['timeout'] && 29 === $s['warning_minutes'] && home_url( '/otpa/passwordless-login/' ) === $s['redirect'], 'duration bounds and external redirect rejected' );
expect( 30 === Otpa_Inactivity_Module::sanitize( array( 'timeout' => '1.5' ) )['timeout'], 'fractional timeout rejected' );
expect( 0 === Otpa_Inactivity_Module::sanitize( array( 'timeout' => 1 ) )['warning_minutes'], 'warning remains below one-minute timeout' );
foreach ( array( 'http://example.test/', '//evil.test/path', '/\\evil.test', 'javascript:alert(1)', 'https://example.test:444/', "https://example.test/\nfoo", 'https://user@example.test/' ) as $bad ) {
	expect( home_url( '/otpa/passwordless-login/' ) === Otpa_Inactivity_Module::safe_redirect( $bad ), 'unsafe redirect rejected: ' . json_encode( $bad ) );
}
expect( home_url( '/safe/?x=1' ) === Otpa_Inactivity_Module::safe_redirect( '/safe/?x=1' ), 'internal redirect accepted' );
expect( home_url( '/relative/path/' ) === Otpa_Inactivity_Module::safe_redirect( 'relative/path/' ), 'relative redirect without leading slash accepted' );
Otpa_Inactivity_Module::init(); Otpa_Inactivity_Module::enqueue();
expect( ! isset( $hooks['init'] ) && ! $scripts, 'disabled feature registers no timer or enforcement' );
$options['otpa_inactivity'] = array( 'enabled' => 1, 'timeout' => 1 );
Otpa_Inactivity_Module::init(); Otpa_Inactivity_Module::enqueue();
expect( array( 'otpa-inactivity' ) === $scripts && 30 === $config['warning'], 'authenticated assets and short warning configured' );
$original = new WP_Error( 'existing', 'Existing auth error', array() );
expect( $original === Otpa_Inactivity_Module::rest_expired( $original ), 'unexpired REST preserves existing authentication result' );
$key = Otpa_Inactivity_Module::key(); $meta[$key] = time() - 20;
$r = endpoint( 'status' );
expect( $r['success'] && $meta[$key] <= time() - 20, 'polling never renews activity' );
$_POST['idle_ms'] = 5000;
$r = endpoint( 'activity' );
expect( $meta[$key] <= time() - 5, 'queued activity uses interaction age rather than wake time' );
$_POST['idle_ms'] = 0;
$meta[$key] = time() - 20;
$r = endpoint( 'activity' );
expect( $r['success'] && $meta[$key] >= time() - 1, 'authenticated interaction renews session' );
$meta[$key] = time() - 10; $nonce = false; $r = endpoint( 'stay' );
expect( 403 === $r['status'] && $meta[$key] <= time() - 10, 'invalid nonce cannot renew' ); $nonce = true;
$meta[$key] = time() - 20; $r = endpoint( 'stay' );
expect( $r['success'] && $meta[$key] >= time() - 1, 'stay verifies and renews active session' );
$token = 'independent-session'; $other = Otpa_Inactivity_Module::key(); $meta[$other] = time(); $token = 'session-a';
expect( $other !== $key, 'independent sessions have separate state' );
$blog = 2; expect( $key !== Otpa_Inactivity_Module::key(), 'multisite policy state is site scoped' ); $blog = 1;
$options['otpa_inactivity']['exclude_admins'] = 1; $roles = array( 'administrator' );
expect( ! Otpa_Inactivity_Module::applies(), 'administrator exclusion' ); $roles = array( 'subscriber' ); $super = true;
expect( ! Otpa_Inactivity_Module::applies(), 'multisite super administrator exclusion' ); $super = false;
$meta[$key] = time() - 61; $concurrent_activity = time();
expect( ! Otpa_Inactivity_Module::expired(), 'expiration rechecks activity committed by another tab' );
$meta[$key] = time() - 61; $r = endpoint( 'stay' );
expect( 401 === $r['status'] && ! $logged && isset( $meta[$other] ), 'expired session cannot revive and independent session survives' );
$logged = true; $token = 'session-a'; $valid_tokens[$token] = true; $meta[$key] = time() - 61; $ajax = true;
try { Otpa_Inactivity_Module::enforce(); } catch ( Response $r ) {}
expect( ! $logged && ! isset( $meta[$key] ), 'server expiration without JavaScript' );
expect( Otpa_Inactivity_Module::rest_expired( null ) instanceof WP_Error, 'expired REST request is rejected' );
$logged = true; $token = 'session-a'; $valid_tokens[$token] = true; $meta = array(); $login = time() - 86400;
expect( ! Otpa_Inactivity_Module::expired(), 'old untracked login receives first-observation baseline' );
$baseline = $meta[$key]; endpoint( 'status' );
expect( $baseline === $meta[$key], 'repeated observations do not reset tracked baseline' );
$meta[$key] = time() - 61;
expect( Otpa_Inactivity_Module::expired(), 'expired tracked record never receives a new baseline' );
// Manual logout must clean all site records for this token and leave another login intact.
$blog = 2; $site_two = Otpa_Inactivity_Module::key(); $blog = 1;
$meta[$key] = time(); $meta[$site_two] = time(); $meta[$other] = time();
wp_logout();
expect( ! isset( $meta[$key] ) && ! isset( $meta[$site_two] ) && isset( $meta[$other] ), 'manual logout cleans only current token across multisite sites' );
$logged = true; $token = 'session-a'; $valid_tokens[$token] = true; $meta[$key] = time() - 61;
$pagenow = 'wp-login.php'; $_REQUEST['action'] = 'logout';
Otpa_Inactivity_Module::enforce();
expect( $logged && isset( $meta[$key] ), 'native logout nonce flow remains untouched even for an idle session' );
$pagenow = 'index.php'; unset( $_REQUEST['action'] );
$valid_tokens[$token] = false;
expect( ! Otpa_Inactivity_Module::applies(), 'invalid token cannot attach idle state to an independently authenticated user' );
$valid_tokens[$token] = true; $meta[$key] = time();
$r = endpoint( array( 'stay' ) ); expect( 400 === $r['status'], 'array mode rejected without PHP warnings' );
$_SERVER['REQUEST_METHOD'] = 'GET';
try { Otpa_Inactivity_Module::endpoint(); } catch ( Response $r ) { expect( 403 === $r->data['status'], 'GET cannot renew session' ); }
try { Otpa_Inactivity_Module::logged_out_status(); } catch ( Response $r ) { expect( 401 === $r->data['status'], 'logged-out endpoint only reports expiration' ); }
$options['otpa_inactivity']['enabled'] = 0; Otpa_Inactivity_Module::init();
expect( isset( $hooks['clear_auth_cookie'] ), 'metadata cleanup stays registered when feature is disabled' );
$logged = false; $scripts = array(); Otpa_Inactivity_Module::enqueue();
expect( ! $scripts, 'visitors receive no timer' );
