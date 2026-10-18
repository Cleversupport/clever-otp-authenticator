<?php
/** Isolated WordPress contract doubles; no live WordPress or Elementor required. */
error_reporting( E_ALL );
set_error_handler( function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
define( 'ABSPATH', __DIR__ );
$hooks = array();
$home = 'https://example.test';
function home_url( $path = '' ) { return $GLOBALS['home'] . '/' . ltrim( $path, '/' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/plugin/'; }
function is_admin() { return false; }
function register_activation_hook( $file, $callback ) {}
function register_deactivation_hook( $file, $callback ) {}
function register_uninstall_hook( $file, $callback ) {}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { add_filter( $hook, $callback, $priority, $args ); }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $hook ][ $priority ][] = $callback; }
function apply_filters( $hook, $value ) {
	if ( isset( $GLOBALS['hooks'][ $hook ] ) ) {
		$callbacks = $GLOBALS['hooks'][ $hook ];
		ksort( $callbacks );
		foreach ( $callbacks as $group ) {
			foreach ( $group as $callback ) { $value = call_user_func( $callback, $value ); }
		}
	}
	return $value;
}
function expect( $condition, $label ) {
	if ( ! $condition ) { throw new Exception( $label ); }
	echo "PASS: $label\n";
}
// Bootstrap the actual entry point, without firing plugins_loaded or loading Elementor.
require __DIR__ . '/../clever-otp-authenticator.php';
$hook = 'elementor/maintenance_mode/is_login_page';
expect( isset( $hooks[ $hook ] ), 'exception registered during plugin loading without Elementor' );
expect( array( $hook, 'plugins_loaded' ) === array_keys( $hooks ), 'bootstrap adds no access-control or option overrides' );

foreach ( array( '', '/wordpress', '/network/site' ) as $base ) {
	$home = 'https://example.test' . $base;
	foreach ( array( '/otpa/passwordless-login', '/otpa-api/otp' ) as $endpoint ) {
		foreach ( array( '', '/', '?redirect_to=%2Faccount%2F&x=1', '/?redirect_to=https%3A%2F%2Fexample.test%2Faccount%2F' ) as $suffix ) {
			$_SERVER['REQUEST_URI'] = $base . $endpoint . $suffix;
			$original = $_SERVER['REQUEST_URI'];
			expect( true === apply_filters( $hook, false ), 'exact endpoint exempt: ' . $original );
			expect( $original === $_SERVER['REQUEST_URI'], 'query and redirect_to preserved verbatim' );
		}
	}
	foreach ( array(
		'/', '/about/', '/wp-admin/', '/wp-login.php', '/custom-login/',
		'/otpa/', '/otpa-api/', '/otpa/account-validation/',
		'/otpa/passwordless-login/child', '/otpa/passwordless-login-extra',
		'/otpa/passwordless-login//', '/otpa-api/otp/child', '/otpa-api/otp-extra',
		'/OTPA/passwordless-login/', '/otpa%2Fpasswordless-login/',
		'/other/?redirect_to=/otpa/passwordless-login/',
		'/?__otpa_page=1&action=passwordless_login', '/?__otpa_api=1&action=otp',
		'/shortcode-page/', '/otpa/passwordless-login/../private/',
	) as $other ) {
		$_SERVER['REQUEST_URI'] = $base . $other;
		expect( false === apply_filters( $hook, false ), 'unrelated path stays protected: ' . $_SERVER['REQUEST_URI'] );
	}
	if ( $base ) {
		$_SERVER['REQUEST_URI'] = '/otpa/passwordless-login/';
		expect( false === apply_filters( $hook, false ), 'root route is not exempt for a subdirectory site' );
	}
}
foreach ( array( '', 'https://example.test/otpa/passwordless-login/', '//example.test/otpa/passwordless-login/', array(), null ) as $malformed ) {
	$_SERVER['REQUEST_URI'] = $malformed;
	expect( false === apply_filters( $hook, false ), 'malformed request fails closed: ' . json_encode( $malformed ) );
}
unset( $_SERVER['REQUEST_URI'] );
expect( false === apply_filters( $hook, false ), 'missing request fails closed' );
expect( true === apply_filters( $hook, true ), 'existing login-page exception preserved' );

// Model Elementor's documented filter boundary: a non-login request replaces the
// query at template_redirect priority 11, after OTP populated its form variables.
// This checks the interaction contract, not live Elementor rendering or HTTP status.
$home = 'https://example.test';
foreach ( array( 'coming_soon', 'maintenance', 'disabled' ) as $mode ) {
	foreach ( array( '/otpa/passwordless-login/', '/otpa-api/otp', '/about/' ) as $path ) {
		$_SERVER['REQUEST_URI'] = $path;
		$query_vars = array( 'otp_form_type' => 'passwordless', 'otp_widget' => 'original widget', 'redirect_to' => '/account/' );
		$expected = $query_vars;
		if ( 'disabled' !== $mode && ! apply_filters( $hook, false ) ) {
			$query_vars = array( 'p' => 123, 'post_type' => 'elementor_library' );
		}
		$should_preserve = 'disabled' === $mode || '/about/' !== $path;
		expect( $should_preserve === ( $query_vars === $expected ), "$mode query preservation: $path" );
	}
}
