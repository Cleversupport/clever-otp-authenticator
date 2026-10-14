<?php
/** Static contract tests of the real Hide Login logout exception; no WordPress runtime. */
error_reporting( E_ALL );
set_error_handler( function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
define( 'ABSPATH', __DIR__ );
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function wp_unslash( $value ) { return $value; }
function wp_parse_url( $value ) { return parse_url( $value ); }
function wp_parse_str( $value, &$args ) { parse_str( $value, $args ); }
function is_admin() { return true; }
function wp_doing_ajax() { return false; }
require __DIR__ . '/../inc/modules/inactivity/class-otpa-inactivity-module.php';
require __DIR__ . '/../inc/modules/hide-login/class-otpa-hide-login-module.php';
$pagenow = 'wp-login.php';
$_SERVER['REQUEST_URI'] = '/wp-login.php?action=logout&_wpnonce=nonce&redirect_to=%2Fotpa%2Fpasswordless-login%2F';
$url = 'https://example.test' . $_SERVER['REQUEST_URI'];
if ( $url !== Otpa_Hide_Login_Module::filter_wp_login_php( $url ) ) {
	throw new Exception( 'Logout URL, nonce and redirect must be preserved' );
}
Otpa_Hide_Login_Module::handle_plugins_loaded_request();
Otpa_Hide_Login_Module::handle_wp_loaded_request();
echo "PASS: Hide Login lets native logout URL through both routing phases unchanged\n";
$method = new ReflectionMethod( 'Otpa_Hide_Login_Module', 'is_logout_request' );
$method->setAccessible( true );
if ( ! $method->invoke( null, parse_url( $url ) ) || $method->invoke( null, array( 'query' => 'action=login' ) ) ) {
	throw new Exception( 'Logout exception must not open the normal login action' );
}
echo "PASS: logout exception does not permit ordinary login\n";
