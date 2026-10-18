<?php
/** Local, read-only documentation in the existing settings screen. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Otpa_Usage_Guide {
	public static function init() {
		add_action( 'otpa_after_tabs_settings', array( __CLASS__, 'tab' ), 30 );
		add_action( 'otpa_after_settings', array( __CLASS__, 'render' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function tab( $active_tab ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=otpa&tab=usage-guide' ) ); ?>" class="nav-tab<?php echo 'usage-guide' === $active_tab ? ' nav-tab-active' : ''; ?>" <?php if ( 'usage-guide' === $active_tab ) { echo 'aria-current="page"'; } ?>><?php esc_html_e( 'Usage Guide', 'otpa' ); ?></a>
		<?php
	}

	public static function enqueue( $hook ) {
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		if ( 'settings_page_otpa' !== $hook || 'usage-guide' !== $tab || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_style( 'otpa-usage-guide', OTPA_PLUGIN_URL . 'css/admin/usage-guide.css', array(), filemtime( OTPA_PLUGIN_PATH . 'css/admin/usage-guide.css' ) );
		wp_enqueue_script( 'otpa-usage-guide', OTPA_PLUGIN_URL . 'js/admin/usage-guide.js', array(), filemtime( OTPA_PLUGIN_PATH . 'js/admin/usage-guide.js' ), true );
	}

	public static function render( $active_tab ) {
		if ( 'usage-guide' !== $active_tab || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$sections = require OTPA_PLUGIN_PATH . 'inc/admin/usage-guide-content.php';
		require OTPA_PLUGIN_PATH . 'inc/templates/admin/usage-guide.php';
	}
}
