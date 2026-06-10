<?php
/**
 * Unified settings tabs for Clever OTP Authenticator.
 *
 * Keeps related module settings inside the existing OTP Authenticator settings
 * page so WordPress does not show several separate Settings entries for one
 * unified plugin.
 *
 * @package Clever_OTP_Authenticator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds internal settings tabs and removes legacy standalone settings entries.
 */
class Otpa_Admin_Settings_Tabs {
	/** Updates settings tab slug. */
	const UPDATES_TAB = 'github-updates';

	/** GitHub token option name used by the updater. */
	const TOKEN_OPTION = 'clever_otp_authenticator_github_token';

	/** GitHub updater settings group. */
	const SETTINGS_GROUP = 'clever_otp_authenticator_updater';

	/** Optional wp-config.php constant containing a GitHub token. */
	const TOKEN_CONSTANT = 'CLEVER_OTP_AUTHENTICATOR_GITHUB_TOKEN';

	/** GitHub repository owner. */
	const OWNER = 'Cleversupport';

	/** GitHub repository name. */
	const REPO = 'clever-otp-authenticator';

	/** Updater cache key. */
	const CACHE_KEY = 'clever_otp_authenticator_github_release';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_init', array( __CLASS__, 'register_settings' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'remove_standalone_settings_pages' ), 999 );
		add_action( 'otpa_after_main_tab_settings', array( __CLASS__, 'render_updates_tab' ), 20, 1 );
		add_action( 'otpa_after_main_settings', array( __CLASS__, 'render_updates_panel' ), 20, 1 );
		add_filter( 'plugin_action_links_otp-authenticator/otp-authenticator.php', array( __CLASS__, 'normalize_plugin_action_links' ), 99, 1 );
	}

	/**
	 * Register updater token settings.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::TOKEN_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_token' ),
				'default'           => '',
			)
		);
	}

	/**
	 * Remove standalone Settings submenu entries created before the plugin was unified.
	 *
	 * @return void
	 */
	public static function remove_standalone_settings_pages() {
		remove_submenu_page( 'options-general.php', 'clever-otp-updates' );
		remove_submenu_page( 'options-general.php', 'otpa-subscribe' );
	}

	/**
	 * Replace updater action links so they point to the internal Updates tab.
	 *
	 * @param array $links Plugin action links.
	 * @return array
	 */
	public static function normalize_plugin_action_links( $links ) {
		$updates_url = esc_url( admin_url( 'options-general.php?page=otpa&tab=' . self::UPDATES_TAB ) );

		foreach ( $links as $index => $link ) {
			if ( false !== strpos( $link, 'clever-otp-updates' ) ) {
				$links[ $index ] = sprintf(
					'<a href="%1$s">%2$s</a>',
					$updates_url,
					esc_html__( 'Update Settings', 'otpa' )
				);
			}
		}

		return $links;
	}

	/**
	 * Sanitize and save the GitHub token.
	 *
	 * @param string $token Submitted token.
	 * @return string
	 */
	public static function sanitize_token( $token ) {
		$clear_token = isset( $_POST['clever_otp_authenticator_clear_github_token'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $clear_token ) {
			delete_site_transient( self::CACHE_KEY );
			return '';
		}

		$token = trim( sanitize_text_field( wp_unslash( $token ) ) );

		if ( '' === $token ) {
			return (string) get_option( self::TOKEN_OPTION, '' );
		}

		delete_site_transient( self::CACHE_KEY );

		return $token;
	}

	/**
	 * Render Updates tab.
	 *
	 * @param string $active_tab Active settings tab.
	 * @return void
	 */
	public static function render_updates_tab( $active_tab ) {
		?>
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=otpa&tab=' . self::UPDATES_TAB ) ); ?>" class="nav-tab<?php echo ( self::UPDATES_TAB === $active_tab ) ? ' nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Updates', 'otpa' ); ?>
		</a>
		<?php
	}

	/**
	 * Render Updates settings panel.
	 *
	 * @param string $active_tab Active settings tab.
	 * @return void
	 */
	public static function render_updates_panel( $active_tab ) {
		if ( self::UPDATES_TAB !== $active_tab ) {
			return;
		}

		$has_constant_token = self::has_constant_token();
		$has_saved_token    = '' !== trim( (string) get_option( self::TOKEN_OPTION, '' ) );
		$has_token          = '' !== self::get_token();
		?>
		<div class="stuffbox">
			<div class="inside">
				<h2><?php esc_html_e( 'Updates', 'otpa' ); ?></h2>
				<p><?php esc_html_e( 'Use this section to connect Clever OTP Authenticator to private GitHub Releases. Public repositories do not need a token.', 'otpa' ); ?></p>

				<?php if ( $has_token ) : ?>
					<div class="notice notice-success inline">
						<p><?php esc_html_e( 'A GitHub token is currently configured for plugin updates.', 'otpa' ); ?></p>
					</div>
				<?php else : ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'No GitHub token is configured. Private repository updates will not work until a token is saved.', 'otpa' ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( $has_constant_token ) : ?>
					<div class="notice notice-info inline">
						<p>
							<?php
							printf(
								/* translators: %s: wp-config.php constant name. */
								esc_html__( 'The token is being loaded from the %s constant. To change it, update wp-config.php.', 'otpa' ),
								'<code>' . esc_html( self::TOKEN_CONSTANT ) . '</code>'
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<form method="post" action="options.php">
					<?php settings_fields( self::SETTINGS_GROUP ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( self::TOKEN_OPTION ); ?>"><?php esc_html_e( 'GitHub Token', 'otpa' ); ?></label>
							</th>
							<td>
								<input name="<?php echo esc_attr( self::TOKEN_OPTION ); ?>" id="<?php echo esc_attr( self::TOKEN_OPTION ); ?>" type="password" class="regular-text" value="" autocomplete="new-password" <?php disabled( $has_constant_token ); ?>>
								<p class="description">
									<?php
									if ( $has_constant_token ) {
										esc_html_e( 'This field is disabled because the token is defined in wp-config.php.', 'otpa' );
									} elseif ( $has_saved_token ) {
										esc_html_e( 'A token is saved. Leave this field blank to keep the current token, or enter a new token to replace it.', 'otpa' );
									} else {
										esc_html_e( 'Paste a GitHub fine-grained or classic token that can read this private repository and its releases.', 'otpa' );
									}
									?>
								</p>
								<?php if ( $has_saved_token && ! $has_constant_token ) : ?>
									<label><input type="checkbox" name="clever_otp_authenticator_clear_github_token" value="1"> <?php esc_html_e( 'Clear saved token', 'otpa' ); ?></label>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Repository', 'otpa' ); ?></th>
							<td><code><?php echo esc_html( self::OWNER . '/' . self::REPO ); ?></code></td>
						</tr>
					</table>
					<?php submit_button( __( 'Save Update Settings', 'otpa' ) ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Get the configured GitHub token.
	 *
	 * @return string
	 */
	private static function get_token() {
		if ( self::has_constant_token() ) {
			return trim( (string) constant( self::TOKEN_CONSTANT ) );
		}

		return trim( (string) get_option( self::TOKEN_OPTION, '' ) );
	}

	/**
	 * Determine whether a GitHub token is defined in wp-config.php.
	 *
	 * @return bool
	 */
	private static function has_constant_token() {
		return defined( self::TOKEN_CONSTANT ) && '' !== trim( (string) constant( self::TOKEN_CONSTANT ) );
	}
}
