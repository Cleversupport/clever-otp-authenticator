<?php
/** Isolated rendering regression; no WordPress or Elementor runtime required. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
define( 'OTPA_PLUGIN_PATH', dirname( __DIR__ ) . '/' );
$hooks = array();
$wp_query = (object) array( 'query_vars' => array() );
$replace_query = false;
function add_action( $name, $callback, $priority = 10, $accepted = 1 ) { $GLOBALS['hooks'][$name][$priority][] = $callback; }
function add_filter( $name, $callback, $priority = 10, $accepted = 1 ) { add_action( $name, $callback, $priority, $accepted ); }
function apply_filters( $name, $value ) {
	$groups = isset( $GLOBALS['hooks'][$name] ) ? $GLOBALS['hooks'][$name] : array();
	ksort( $groups );
	foreach ( $groups as $callbacks ) { foreach ( $callbacks as $callback ) { $value = call_user_func( $callback, $value ); } }
	return $value;
}
function do_action( $name, $value = null ) { apply_filters( $name, $value ); }
function __( $text, $domain = '' ) { return $text; }
function _x( $text, $context ) { return $text; }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_html( $text ) { return esc_attr( $text ); }
function esc_url( $text ) { return esc_attr( $text ); }
function esc_html_e( $text, $domain = '' ) { echo esc_html( $text ); }
function is_user_logged_in() { return false; }
function home_url( $path = '' ) { return 'https://example.test/' . ltrim( $path, '/' ); }
function get_bloginfo( $key, $context = '' ) { return 'Test site'; }
function get_the_privacy_policy_link( $before, $after ) { return ''; }
function set_query_var( $key, $value ) { $GLOBALS['wp_query']->query_vars[$key] = $value; }
function locate_template( $name, $load = false ) { return ''; }
function language_attributes() { echo 'lang="en"'; }
function wp_nonce_field( $action, $name ) { echo '<input name="' . esc_attr( $name ) . '" value="test-nonce">'; }
function wp_head() {
	// Simulate a header callback replacing the query after the page captured its locals.
	if ( $GLOBALS['replace_query'] ) { $GLOBALS['wp_query'] = (object) array( 'query_vars' => array() ); }
}
function wp_footer() {}
function load_template( $_template_file, $load_once = true, $args = array() ) {
	global $wp_query;
	extract( $wp_query->query_vars, EXTR_SKIP );
	if ( $load_once ) { require_once $_template_file; } else { require $_template_file; }
}
function shortcode_atts( $defaults, $attrs, $name ) { return array_merge( $defaults, array_intersect_key( $attrs, $defaults ) ); }
function sanitize_html_class( $value ) { return preg_replace( '/[^a-zA-Z0-9_-]/', '', $value ); }
class Otpa_Style_Settings { public static function get_option( $key ) { return ''; } }
require OTPA_PLUGIN_PATH . 'inc/class-otpa.php';
require OTPA_PLUGIN_PATH . 'inc/class-otpa-passwordless.php';
require OTPA_PLUGIN_PATH . 'inc/gateways/class-otpa-abstract-gateway.php';
require OTPA_PLUGIN_PATH . 'inc/gateways/class-otpa-wp-email-gateway.php';
class Rendering_Email_Gateway extends Otpa_WP_Email_Gateway {
	public function __construct() {}
	protected function add_otp_widget_scripts( $css = true, $js = true ) {}
}
class Rendering_Otpa extends Otpa {
	public function add_frontend_scripts() {}
	protected function get_logout_markup() { return 'Log Out'; }
}
function expect_form( $html ) {
	foreach ( array( 'data-otp_form_type="passwordless"', '<h1>Passwordless Authentication</h1>', 'id="otpa_id_widget"', 'placeholder="Email"', 'id="otpa_code_widget"', 'id="otpa_send_code"', 'name="otpa_nonce"', 'data-handler="verify_otp_code"' ) as $needle ) {
		if ( false === strpos( $html, $needle ) ) { throw new Exception( 'Missing: ' . $needle ); }
	}
}
$gateway = new Rendering_Email_Gateway();
add_filter( 'otpa_otp_widget_identifier_placeholder', array( $gateway, 'otp_widget_identifier_placeholder' ) );
add_action( 'otpa_before_otp_form', array( $gateway, 'print_form_hint' ) );
$otpa = new Rendering_Otpa( null, $gateway );
$passwordless = new Otpa_Passwordless( $otpa );
// Exercise the real shortcode path before the full-page path in separate processes.
if ( isset( $argv[1] ) && 'shortcode' === $argv[1] ) {
	$html = $passwordless->passwordless_login_shortcode( array( 'class' => 'custom' ) );
	expect_form( $html );
	if ( false === strpos( $html, 'otpa-shortcode custom' ) || false !== strpos( $html, 'id="otpa_logo"' ) ) { throw new Exception( 'Shortcode attributes changed' ); }
	echo "PASS: real shortcode renders Email, Code, Send Code, heading, type and nonce\n";
	exit;
}
$passwordless->login_page();
$otpa->otp_form_page();
$replace_query = isset( $argv[1] ) && 'replace-query' === $argv[1];
ob_start();
$otpa->otp_form_template( '' );
$html = ob_get_clean();
expect_form( $html );
echo 'PASS: full page controls survive ' . ( $replace_query ? 'query replacement in wp_head' : 'normal header rendering' ) . "\n";
