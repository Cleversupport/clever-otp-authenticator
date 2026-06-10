<?php
/**
 * Subscribe button module for Clever OTP Authenticator.
 *
 * This module was integrated from the former OTPA Subscribe Addon so the
 * subscribe button ships as part of the main Clever OTP Authenticator plugin
 * instead of being installed as a second WordPress plugin.
 *
 * @package Clever_OTP_Authenticator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a configurable Subscribe button to the OTP passwordless form.
 */
class Otpa_Subscribe_Button_Module {
	/** Settings group name. */
	const SETTINGS_GROUP = 'otpa_subscribe_group';

	/** Button text option name. */
	const OPTION_TEXT = 'otpa_subscribe_text';

	/** Button URL option name. */
	const OPTION_URL = 'otpa_subscribe_url';

	/** Inline style handle used when no OTPA style handle is available. */
	const STYLE_HANDLE = 'otpa-subscribe-button-module';

	/**
	 * Register module hooks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
			add_action( 'otpa_after_main_tab_settings', array( __CLASS__, 'render_settings_tab_link' ), 10, 1 );
			add_action( 'otpa_after_main_settings', array( __CLASS__, 'render_settings_tab' ), 10, 1 );
		}

		add_filter( 'otpa_otp_form_vars', array( __CLASS__, 'append_subscribe_button' ), 99, 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ), 20 );
	}

	/**
	 * Register subscribe button settings.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_TEXT,
			array(
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_URL,
			array(
				'sanitize_callback' => 'esc_url_raw',
			)
		);
	}

	/**
	 * Render the Subscribe Button settings tab link.
	 *
	 * @param string $active_tab Active OTP Authenticator settings tab.
	 * @return void
	 */
	public static function render_settings_tab_link( $active_tab ) {
		?>
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=otpa&tab=subscribe-button' ) ); ?>" class="nav-tab<?php echo ( 'subscribe-button' === $active_tab ) ? ' nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Subscribe Button', 'otpa' ); ?>
		</a>
		<?php
	}

	/**
	 * Render the Subscribe Button settings tab content.
	 *
	 * @param string $active_tab Active OTP Authenticator settings tab.
	 * @return void
	 */
	public static function render_settings_tab( $active_tab ) {
		if ( 'subscribe-button' !== $active_tab ) {
			return;
		}
		?>
		<div class="stuffbox">
			<div class="inside">
				<form method="post" action="options.php">
					<?php settings_fields( self::SETTINGS_GROUP ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( self::OPTION_TEXT ); ?>">
									<?php esc_html_e( 'Button Text', 'otpa' ); ?>
								</label>
							</th>
							<td>
								<input name="<?php echo esc_attr( self::OPTION_TEXT ); ?>" id="<?php echo esc_attr( self::OPTION_TEXT ); ?>" type="text" class="regular-text" value="<?php echo esc_attr( get_option( self::OPTION_TEXT, '' ) ); ?>">
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( self::OPTION_URL ); ?>">
									<?php esc_html_e( 'Button URL', 'otpa' ); ?>
								</label>
							</th>
							<td>
								<input name="<?php echo esc_attr( self::OPTION_URL ); ?>" id="<?php echo esc_attr( self::OPTION_URL ); ?>" type="url" class="regular-text" value="<?php echo esc_attr( get_option( self::OPTION_URL, '' ) ); ?>">
							</td>
						</tr>
					</table>
					<?php submit_button(); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Append the configured subscribe button to the OTP form footer.
	 *
	 * @param array $vars OTP form variables.
	 * @return array
	 */
	public static function append_subscribe_button( $vars ) {
		$text = trim( (string) get_option( self::OPTION_TEXT, '' ) );
		$url  = trim( (string) get_option( self::OPTION_URL, '' ) );

		if ( '' === $text || '' === $url ) {
			return $vars;
		}

		$cf_id = isset( $_GET['cf_id'] ) ? absint( wp_unslash( $_GET['cf_id'] ) ) : 0;

		if ( $cf_id > 0 ) {
			$url = add_query_arg( 'cf_id', $cf_id, $url );
		}

		$button = sprintf(
			'<a class="otpa-subscribe-button" href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html( $text )
		);

		if ( isset( $vars['otp_footer_message'] ) ) {
			$vars['otp_footer_message'] .= ' ' . $button;
		} else {
			$vars['otp_footer_message'] = $button;
		}

		return $vars;
	}

	/**
	 * Add minimal styling for the subscribe button.
	 *
	 * @return void
	 */
	public static function enqueue_styles() {
		$css = '.otpa-form .otpa-subscribe-button{display:inline-block;margin-left:10px;padding:8px 14px;border-radius:4px;text-decoration:none}.otpa-form .otpa-subscribe-button:hover{opacity:.92}';

		if ( wp_style_is( 'otpa-otp-form-inline-style', 'registered' ) || wp_style_is( 'otpa-otp-form-inline-style', 'enqueued' ) ) {
			wp_add_inline_style( 'otpa-otp-form-inline-style', $css );
			return;
		}

		wp_register_style( self::STYLE_HANDLE, false, array(), '1.0.0' );
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_add_inline_style( self::STYLE_HANDLE, $css );
	}
}
