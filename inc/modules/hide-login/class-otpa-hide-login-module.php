<?php
/**
 * Hide login module for Clever OTP Authenticator.
 *
 * Integrates WPS Hide Login-style behavior internally without registering a
 * second WordPress plugin.
 *
 * @package Clever_OTP_Authenticator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces the default WordPress login URL with a configurable slug.
 */
class Otpa_Hide_Login_Module {
	/** Settings group name. */
	const SETTINGS_GROUP = 'otpa_hide_login_group';

	/** Login slug option name preserved from WPS Hide Login. */
	const OPTION_LOGIN_SLUG = 'whl_page';

	/** Redirect slug option name preserved from WPS Hide Login. */
	const OPTION_REDIRECT_SLUG = 'whl_redirect_admin';

	/** Default login slug. */
	const DEFAULT_LOGIN_SLUG = 'login';

	/** Default redirect slug. */
	const DEFAULT_REDIRECT_SLUG = '404';

	/**
	 * Whether the current request originally targeted wp-login.php.
	 *
	 * @var bool
	 */
	protected static $wp_login_php = false;

	/**
	 * Register module hooks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::standalone_plugin_active() ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', array( __CLASS__, 'render_conflict_notice' ) );
			}

			return;
		}

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
			add_action( 'otpa_after_main_tab_settings', array( __CLASS__, 'render_settings_tab_link' ), 20, 1 );
			add_action( 'otpa_after_main_settings', array( __CLASS__, 'render_settings_tab' ), 20, 1 );
		}

		self::handle_plugins_loaded_request();

		add_action( 'wp_loaded', array( __CLASS__, 'handle_wp_loaded_request' ) );
		add_filter( 'site_url', array( __CLASS__, 'filter_site_url' ), 10, 4 );
		add_filter( 'network_site_url', array( __CLASS__, 'filter_network_site_url' ), 10, 3 );
		add_filter( 'wp_redirect', array( __CLASS__, 'filter_wp_redirect' ), 10, 2 );
		add_filter( 'site_option_welcome_email', array( __CLASS__, 'filter_welcome_email' ) );
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
	}

	/**
	 * Register settings for the Hide Login tab.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_LOGIN_SLUG,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_login_slug' ),
				'default'           => self::DEFAULT_LOGIN_SLUG,
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_REDIRECT_SLUG,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_redirect_slug' ),
				'default'           => self::DEFAULT_REDIRECT_SLUG,
			)
		);
	}

	/**
	 * Render the Hide Login settings tab link.
	 *
	 * @param string $active_tab Active OTP Authenticator settings tab.
	 * @return void
	 */
	public static function render_settings_tab_link( $active_tab ) {
		?>
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=otpa&tab=hide-login' ) ); ?>" class="nav-tab<?php echo ( 'hide-login' === $active_tab ) ? ' nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Hide Login', 'otpa' ); ?>
		</a>
		<?php
	}

	/**
	 * Render the Hide Login settings tab content.
	 *
	 * @param string $active_tab Active OTP Authenticator settings tab.
	 * @return void
	 */
	public static function render_settings_tab( $active_tab ) {
		if ( 'hide-login' !== $active_tab ) {
			return;
		}

		$login_slug    = self::new_login_slug();
		$redirect_slug = self::new_redirect_slug();
		?>
		<div class="stuffbox">
			<div class="inside">
				<form method="post" action="options.php">
					<?php settings_fields( self::SETTINGS_GROUP ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( self::OPTION_LOGIN_SLUG ); ?>">
									<?php esc_html_e( 'Login URL', 'otpa' ); ?>
								</label>
							</th>
							<td>
								<code><?php echo esc_html( trailingslashit( home_url() ) ); ?></code>
								<input name="<?php echo esc_attr( self::OPTION_LOGIN_SLUG ); ?>" id="<?php echo esc_attr( self::OPTION_LOGIN_SLUG ); ?>" type="text" class="regular-text" value="<?php echo esc_attr( $login_slug ); ?>">
								<p class="description">
									<?php esc_html_e( 'WordPress login URLs will use this slug instead of wp-login.php. Bookmark the resulting URL before saving.', 'otpa' ); ?>
								</p>
								<p class="description">
									<?php esc_html_e( 'Current login URL:', 'otpa' ); ?>
									<a href="<?php echo esc_url( self::new_login_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( self::new_login_url() ); ?></a>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( self::OPTION_REDIRECT_SLUG ); ?>">
									<?php esc_html_e( 'Redirect URL', 'otpa' ); ?>
								</label>
							</th>
							<td>
								<code><?php echo esc_html( trailingslashit( home_url() ) ); ?></code>
								<input name="<?php echo esc_attr( self::OPTION_REDIRECT_SLUG ); ?>" id="<?php echo esc_attr( self::OPTION_REDIRECT_SLUG ); ?>" type="text" class="regular-text" value="<?php echo esc_attr( $redirect_slug ); ?>">
								<p class="description">
									<?php esc_html_e( 'Logged-out wp-admin requests are redirected to this slug.', 'otpa' ); ?>
								</p>
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
	 * Handle early login URL routing.
	 *
	 * @return void
	 */
	public static function handle_plugins_loaded_request() {
		global $pagenow;

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$request     = wp_parse_url( $request_uri );
		$path        = isset( $request['path'] ) ? untrailingslashit( $request['path'] ) : '';

		if ( ( false !== strpos( $request_uri, 'wp-login.php' ) || $path === untrailingslashit( site_url( 'wp-login', 'relative' ) ) ) && ! is_admin() ) {
			self::$wp_login_php      = true;
			$_SERVER['REQUEST_URI'] = self::user_trailingslashit( '/' . str_repeat( '-/', 10 ) );
			$pagenow                = 'index.php';
			return;
		}

		if ( self::request_matches_login_slug( $request ) ) {
			$pagenow = 'wp-login.php';
		}
	}

	/**
	 * Handle redirects and wp-login.php inclusion after WordPress has loaded.
	 *
	 * @return void
	 */
	public static function handle_wp_loaded_request() {
		global $pagenow;

		if ( is_admin() && ! is_user_logged_in() && ! wp_doing_ajax() ) {
			wp_safe_redirect( self::new_redirect_url() );
			exit;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$request     = wp_parse_url( $request_uri );
		$path        = isset( $request['path'] ) ? $request['path'] : '';

		if ( 'wp-login.php' === $pagenow && $path !== self::user_trailingslashit( $path ) && get_option( 'permalink_structure' ) ) {
			wp_safe_redirect( self::user_trailingslashit( self::new_login_url() ) . ( ! empty( $_SERVER['QUERY_STRING'] ) ? '?' . wp_unslash( $_SERVER['QUERY_STRING'] ) : '' ) );
			exit;
		}

		if ( self::$wp_login_php ) {
			self::load_404_template();
		}

		if ( 'wp-login.php' === $pagenow ) {
			global $error, $interim_login, $action, $user_login;
			require_once ABSPATH . 'wp-login.php';
			exit;
		}
	}

	/**
	 * Replace wp-login.php in site URLs.
	 *
	 * @param string      $url     Complete site URL.
	 * @param string      $path    Site URL path.
	 * @param string|null $scheme  URL scheme.
	 * @param int|null    $blog_id Blog ID.
	 * @return string
	 */
	public static function filter_site_url( $url, $path, $scheme, $blog_id ) {
		return self::filter_wp_login_php( $url, $scheme );
	}

	/**
	 * Replace wp-login.php in network site URLs.
	 *
	 * @param string      $url    Complete network site URL.
	 * @param string      $path   Network site URL path.
	 * @param string|null $scheme URL scheme.
	 * @return string
	 */
	public static function filter_network_site_url( $url, $path, $scheme ) {
		return self::filter_wp_login_php( $url, $scheme );
	}

	/**
	 * Replace wp-login.php in redirect destinations.
	 *
	 * @param string $location Redirect destination.
	 * @param int    $status   Redirect status code.
	 * @return string
	 */
	public static function filter_wp_redirect( $location, $status ) {
		return self::filter_wp_login_php( $location );
	}

	/**
	 * Replace wp-login.php references in multisite welcome emails.
	 *
	 * @param string $value Welcome email content.
	 * @return string
	 */
	public static function filter_welcome_email( $value ) {
		return str_replace( 'wp-login.php', trailingslashit( self::new_login_slug() ), $value );
	}

	/**
	 * Filter wp-login.php URLs to the configured login URL.
	 *
	 * @param string      $url    URL to filter.
	 * @param string|null $scheme URL scheme.
	 * @return string
	 */
	public static function filter_wp_login_php( $url, $scheme = null ) {
		if ( false === strpos( $url, 'wp-login.php' ) ) {
			return $url;
		}

		if ( is_ssl() ) {
			$scheme = 'https';
		}

		$args = explode( '?', $url, 2 );
		$url  = self::new_login_url( $scheme );

		if ( isset( $args[1] ) ) {
			wp_parse_str( $args[1], $query_args );
			$url = add_query_arg( $query_args, $url );
		}

		return $url;
	}

	/**
	 * Get the configured login slug.
	 *
	 * @return string
	 */
	public static function new_login_slug() {
		$slug = get_option( self::OPTION_LOGIN_SLUG, self::DEFAULT_LOGIN_SLUG );

		return self::sanitize_login_slug( $slug );
	}

	/**
	 * Get the configured login URL.
	 *
	 * @param string|null $scheme URL scheme.
	 * @return string
	 */
	public static function new_login_url( $scheme = null ) {
		if ( get_option( 'permalink_structure' ) ) {
			return self::user_trailingslashit( home_url( '/' . self::new_login_slug(), $scheme ) );
		}

		return home_url( '/', $scheme ) . '?' . self::new_login_slug();
	}

	/**
	 * Get the configured redirect slug.
	 *
	 * @return string
	 */
	public static function new_redirect_slug() {
		$slug = get_option( self::OPTION_REDIRECT_SLUG, self::DEFAULT_REDIRECT_SLUG );

		return self::sanitize_redirect_slug( $slug );
	}

	/**
	 * Get the configured wp-admin redirect URL.
	 *
	 * @return string
	 */
	public static function new_redirect_url() {
		$slug = self::new_redirect_slug();

		if ( preg_match( '#^https?://#i', $slug ) ) {
			return $slug;
		}

		if ( get_option( 'permalink_structure' ) ) {
			return self::user_trailingslashit( home_url( '/' . ltrim( $slug, '/' ) ) );
		}

		return home_url( '/' . ltrim( $slug, '/' ) );
	}

	/**
	 * Sanitize the login slug.
	 *
	 * @param string $slug Login slug.
	 * @return string
	 */
	public static function sanitize_login_slug( $slug ) {
		$slug = sanitize_title_with_dashes( $slug );

		if ( '' === $slug || false !== strpos( $slug, 'wp-login' ) || in_array( $slug, self::forbidden_slugs(), true ) ) {
			$slug = self::DEFAULT_LOGIN_SLUG;
		}

		return $slug;
	}

	/**
	 * Sanitize the redirect slug or URL.
	 *
	 * @param string $slug Redirect slug or URL.
	 * @return string
	 */
	public static function sanitize_redirect_slug( $slug ) {
		$slug = trim( (string) $slug );

		if ( preg_match( '#^https?://#i', $slug ) ) {
			return esc_url_raw( $slug );
		}

		$slug = trim( $slug, '/' );
		$slug = sanitize_title_with_dashes( $slug );

		return '' === $slug ? self::DEFAULT_REDIRECT_SLUG : $slug;
	}

	/**
	 * Apply the site's trailing slash preference.
	 *
	 * @param string $string URL or path.
	 * @return string
	 */
	protected static function user_trailingslashit( $string ) {
		return use_trailing_slashes() ? trailingslashit( $string ) : untrailingslashit( $string );
	}

	/**
	 * Determine whether the current request matches the custom login slug.
	 *
	 * @param array|false $request Parsed request URL.
	 * @return bool
	 */
	protected static function request_matches_login_slug( $request ) {
		$slug = self::new_login_slug();

		if ( ! is_array( $request ) ) {
			$request = array();
		}

		$path = isset( $request['path'] ) ? untrailingslashit( $request['path'] ) : '';

		return untrailingslashit( home_url( $slug, 'relative' ) ) === $path || ( ! get_option( 'permalink_structure' ) && isset( $_GET[ $slug ] ) && empty( $_GET[ $slug ] ) );
	}

	/**
	 * Load the theme 404 template for blocked wp-login.php requests.
	 *
	 * @return void
	 */
	protected static function load_404_template() {
		global $wp_query;

		status_header( 404 );
		nocache_headers();

		if ( $wp_query ) {
			$wp_query->set_404();
		}

		$template = get_404_template();

		if ( $template ) {
			include $template;
		} else {
			echo '404'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		exit;
	}

	/**
	 * Get slugs that would conflict with WordPress query variables.
	 *
	 * @return array
	 */
	protected static function forbidden_slugs() {
		$wp = new WP();

		return array_merge( $wp->public_query_vars, $wp->private_query_vars );
	}

	/**
	 * Determine whether the standalone WPS Hide Login plugin is active.
	 *
	 * @return bool
	 */
	protected static function standalone_plugin_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin = 'wps-hide-login/wps-hide-login.php';

		return is_plugin_active( $plugin ) || ( is_multisite() && is_plugin_active_for_network( $plugin ) );
	}

	/**
	 * Render a conflict notice when the standalone plugin is active.
	 *
	 * @return void
	 */
	public static function render_conflict_notice() {
		?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'Clever OTP Authenticator Hide Login module is inactive because the standalone WPS Hide Login plugin is active.', 'otpa' ); ?></p>
		</div>
		<?php
	}
}
