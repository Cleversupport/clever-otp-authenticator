<?php
/** Read-only guide contracts using WordPress doubles; no live WordPress required. */
error_reporting( E_ALL );
set_error_handler( function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
define( 'ABSPATH', __DIR__ );
define( 'OTPA_PLUGIN_PATH', dirname( __DIR__ ) . '/' );
define( 'OTPA_PLUGIN_URL', 'https://example.test/plugin/' );
$allowed = true; $assets = array(); $hooks = array();
function current_user_can( $cap ) { if ( 'manage_options' !== $cap ) { throw new Exception( 'Wrong permission' ); } return $GLOBALS['allowed']; }
function __( $text, $domain ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_textarea( $text ) { return esc_html( $text ); }
function esc_url( $text ) { return esc_html( $text ); }
function esc_html_e( $text, $domain ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain ) { echo esc_attr( $text ); }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function home_url( $path ) { return 'https://example.test/subdir' . $path; }
function add_query_arg( $key, $value, $url ) { return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function wp_unslash( $value ) { return $value; }
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook][] = $callback; }
function wp_enqueue_style( $handle, $url, $deps, $version ) { $GLOBALS['assets'][] = array( $handle, $deps, $url ); }
function wp_enqueue_script( $handle, $url, $deps, $version, $footer ) { $GLOBALS['assets'][] = array( $handle, $deps, $url ); }
function expect( $condition, $label ) { if ( ! $condition ) { throw new Exception( $label ); } echo "PASS: $label\n"; }
function render_guide( $tab ) { ob_start(); Otpa_Usage_Guide::render( $tab ); return ob_get_clean(); }
require OTPA_PLUGIN_PATH . 'inc/admin/class-otpa-usage-guide.php';
Otpa_Usage_Guide::init();
expect( isset( $hooks['otpa_after_tabs_settings'], $hooks['otpa_after_settings'], $hooks['admin_enqueue_scripts'] ), 'uses existing settings hooks' );
$allowed = false;
expect( '' === render_guide( 'usage-guide' ), 'unauthorized users receive no guide' );
ob_start(); Otpa_Usage_Guide::tab( 'usage-guide' ); $tab = ob_get_clean();
expect( '' === $tab, 'unauthorized users receive no navigation' );
$_GET['tab'] = 'usage-guide'; Otpa_Usage_Guide::enqueue( 'settings_page_otpa' );
expect( ! $assets, 'unauthorized requests load no guide assets' );
$allowed = true;
expect( '' === render_guide( 'settings' ), 'other settings tabs receive no guide content' );
Otpa_Usage_Guide::enqueue( 'dashboard' ); $_GET['tab'] = 'settings'; Otpa_Usage_Guide::enqueue( 'settings_page_otpa' );
$_GET['tab'] = array( 'usage-guide' ); Otpa_Usage_Guide::enqueue( 'settings_page_otpa' );
expect( ! $assets, 'assets limited to guide tab and malformed tabs rejected' );
$_GET['tab'] = 'usage-guide'; Otpa_Usage_Guide::enqueue( 'settings_page_otpa' );
expect( 2 === count( $assets ) && ! $assets[0][1] && ! $assets[1][1], 'local assets have no library dependencies' );
$html = render_guide( 'usage-guide' );
expect( 11 === substr_count( $html, '<section ' ), 'all eleven documentation sections render' );
expect( false === strpos( $html, '<form' ) && false === strpos( $html, 'options.php' ), 'guide has no settings submission' );
expect( false !== strpos( $html, 'https://example.test/subdir/otpa/passwordless-login/' ), 'examples use subdirectory-aware home URL' );
expect( false !== strpos( $html, '[otpa_passwordless_login show_logo=&quot;yes&quot; redirect_to=&quot;https://example.test/subdir/account/&quot;]' ), 'shortcode attributes remain literal copyable text' );
expect( 5 === substr_count( $html, 'data-copy-target=' ) && 5 === substr_count( $html, 'readonly rows=' ), 'all five examples remain manually copyable without JS' );
expect( false !== strpos( $html, 'aria-live="polite"' ) && 5 === substr_count( $html, 'aria-label="Copy example:' ), 'copy controls have names and a live status' );
ob_start(); Otpa_Usage_Guide::tab( 'usage-guide' ); $tab = ob_get_clean();
expect( false !== strpos( $tab, 'nav-tab-active' ) && false !== strpos( $tab, 'aria-current="page"' ), 'active tab follows WordPress navigation' );
// Exercise the actual template with hostile translated/example content.
$sections = array( 'test' => array( 'title' => '<script>alert(1)</script>', 'paragraphs' => array( '<img src=x onerror=alert(1)>' ), 'examples' => array( array( '" onclick="bad', '</textarea><script>bad</script>' ) ) ) );
ob_start(); require OTPA_PLUGIN_PATH . 'inc/templates/admin/usage-guide.php'; $escaped = ob_get_clean();
expect( false === strpos( $escaped, '<script>' ) && false === strpos( $escaped, '<img ' ) && false !== strpos( $escaped, '&lt;/textarea&gt;' ), 'template escapes headings, prose, labels and examples' );
