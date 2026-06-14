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

	/** Enable/disable option name for the internal Hide Login module. */
	const OPTION_ENABLED = 'otpa_hide_login_enabled';

	/** Dashboard access restriction option name. */
	const OPTION_RESTRICT_DASHBOARD_ACCESS = 'otpa_restrict_dashboard_access';

	/** Allowed dashboard roles option name. */
	const OPTION_ALLOWED_DASHBOARD_ROLES = 'otpa_allowed_dashboard_roles';

	/** Default login slug. */
	const DEFAULT_LOGIN_SLUG = 'login';

	/** Default redirect slug. */
	const DEFAULT_REDIRECT_SLUG = '404';

	/** Fixed passwordless login path for the public login slug. */
	const PASSWORDLESS_LOGIN_PATH = '/otpa/passwordless-login/';

	/**
	 * Register module hooks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
			add_action( 'otpa_after_main_tab_settings', array( __CLASS__, 'render_settings_tab_link' ), 20, 1 );
			add_action( 'otpa_after_main_settings', array( __CLASS__, 'render_settings_tab' ), 20, 1 );
		}

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'restrict_dashboard_access' ), 20 );
		}

		if ( self::standalone_plugin_active() ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', array( __CLASS__, 'render_conflict_notice' ) );
			}

			return;
		}

		if ( ! self::is_enabled() ) {
			return;
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
			self::OPTION_ENABLED,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_enabled' ),
				'default'           => '0',
			)
		);

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

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_RESTRICT_DASHBOARD_ACCESS,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_enabled' ),
				'default'           => '0',
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_ALLOWED_DASHBOARD_ROLES,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_allowed_dashboard_roles' ),
				'default'           => array( 'administrator' ),
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

		$enabled                   = self::is_enabled();
		$login_slug                = self::new_login_slug();
		$redirect_slug             = self::new_redirect_slug();
		$restrict_dashboard_access = self::is_dashboard_access_restriction_enabled();
		$allowed_dashboard_roles   = self::allowed_dashboard_roles();
		$editable_roles            = self::get_available_roles();
		?>
		<div class="stuffbox">
			<div class="inside">
				<form method="post" action="options.php">
					<?php settings_fields( self::SETTINGS_GROUP ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Enable Hide Login', 'otpa' ); ?>
							</th>
							<td>
								<input name="<?php echo esc_attr( self::OPTION_ENABLED ); ?>" type="hidden" value="0">
								<label for="<?php echo esc_attr( self::OPTION_ENABLED ); ?>">
									<input name="<?php echo esc_attr( self::OPTION_ENABLED ); ?>" id="<?php echo esc_attr( self::OPTION_ENABLED ); ?>" type="checkbox" value="1" <?php checked( $enabled ); ?>>
									<?php esc_html_e( 'Enable custom login URL protection.', 'otpa' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'When disabled, this module leaves wp-login.php and wp-admin behavior unchanged.', 'otpa' ); ?>
								</p>
							</td>
						</tr>
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
									<?php esc_html_e( 'This public short login URL redirects to the OTP Authenticator passwordless login page instead of loading wp-login.php.', 'otpa' ); ?>
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
									<?php esc_html_e( 'Legacy compatibility setting. Blocked login and logged-out admin requests now redirect to the site home page.', 'otpa' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Enable Dashboard Access Restriction', 'otpa' ); ?>
							</th>
							<td>
								<input name="<?php echo esc_attr( self::OPTION_RESTRICT_DASHBOARD_ACCESS ); ?>" type="hidden" value="0">
								<label for="<?php echo esc_attr( self::OPTION_RESTRICT_DASHBOARD_ACCESS ); ?>">
									<input name="<?php echo esc_attr( self::OPTION_RESTRICT_DASHBOARD_ACCESS ); ?>" id="<?php echo esc_attr( self::OPTION_RESTRICT_DASHBOARD_ACCESS ); ?>" type="checkbox" value="1" <?php checked( $restrict_dashboard_access ); ?>>
									<?php esc_html_e( 'Redirect logged-in users without an allowed role away from wp-admin.', 'otpa' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Allowed Dashboard Roles', 'otpa' ); ?>
							</th>
							<td>
								<?php foreach ( $editable_roles as $role_key => $role ) : ?>
									<label style="display:block;margin-bottom:4px;" for="<?php echo esc_attr( self::OPTION_ALLOWED_DASHBOARD_ROLES . '_' . $role_key ); ?>">
										<input name="<?php echo esc_attr( self::OPTION_ALLOWED_DASHBOARD_ROLES ); ?>[]" id="<?php echo esc_attr( self::OPTION_ALLOWED_DASHBOARD_ROLES . '_' . $role_key ); ?>" type="checkbox" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, $allowed_dashboard_roles, true ) ); ?>>
										<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description">
									<?php esc_html_e( 'Administrators are always allowed, and super admins are always allowed on multisite, even if this setting is changed.', 'otpa' ); ?>
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
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$request     = wp_parse_url( $request_uri );

		if ( self::is_wp_login_request( $request ) ) {
			if ( self::is_logout_request( $request ) ) {
				return;
			}

			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		if ( is_admin() && ! is_user_logged_in() && ! wp_doing_ajax() ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		if ( self::request_matches_login_slug( $request ) ) {
			wp_safe_redirect( self::passwordless_login_url() );
			exit;
		}
	}

	/**
	 * Handle redirects and wp-login.php inclusion after WordPress has loaded.
	 *
	 * @return void
	 */
	public static function handle_wp_loaded_request() {
		global $pagenow;

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$request     = wp_parse_url( $request_uri );

		if ( self::is_wp_login_request( $request ) && self::is_logout_request( $request ) ) {
			return;
		}

		if ( is_admin() && ! is_user_logged_in() && ! wp_doing_ajax() ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$path = isset( $request['path'] ) ? $request['path'] : '';

		if ( 'wp-login.php' === $pagenow && $path !== self::user_trailingslashit( $path ) && get_option( 'permalink_structure' ) ) {
			wp_safe_redirect( self::user_trailingslashit( self::new_login_url() ) . ( ! empty( $_SERVER['QUERY_STRING'] ) ? '?' . wp_unslash( $_SERVER['QUERY_STRING'] ) : '' ) );
			exit;
		}

	}

	/**
	 * Redirect logged-in admin users who do not have an allowed dashboard role.
	 *
	 * @return void
	 */
	public static function restrict_dashboard_access() {
		global $pagenow;

		if ( ! self::is_dashboard_access_restriction_enabled() ) {
			return;
		}

		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( 'admin-post.php' === $pagenow || ! is_user_logged_in() ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			return;
		}

		if ( in_array( 'administrator', (array) $user->roles, true ) || ( is_multisite() && is_super_admin( $user->ID ) ) ) {
			return;
		}

		if ( array_intersect( self::allowed_dashboard_roles(), (array) $user->roles ) ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ) );
		exit;
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

		$parsed_url = wp_parse_url( $url );

		if ( self::is_logout_request( $parsed_url ) ) {
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
	 * Get the fixed OTP Authenticator passwordless login URL.
	 *
	 * @return string
	 */
	public static function passwordless_login_url() {
		return home_url( self::PASSWORDLESS_LOGIN_PATH );
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
	 * Determine whether the internal Hide Login module is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return '1' === get_option( self::OPTION_ENABLED, '0' );
	}

	/**
	 * Determine whether dashboard access restriction is enabled.
	 *
	 * @return bool
	 */
	public static function is_dashboard_access_restriction_enabled() {
		return '1' === get_option( self::OPTION_RESTRICT_DASHBOARD_ACCESS, '0' );
	}

	/**
	 * Get existing WordPress roles keyed by role slug.
	 *
	 * @return array
	 */
	public static function get_available_roles() {
		$wp_roles = wp_roles();

		return is_object( $wp_roles ) && is_array( $wp_roles->roles ) ? $wp_roles->roles : array();
	}

	/**
	 * Get sanitized allowed dashboard roles.
	 *
	 * @return array
	 */
	public static function allowed_dashboard_roles() {
		$roles = get_option( self::OPTION_ALLOWED_DASHBOARD_ROLES, array( 'administrator' ) );

		return self::sanitize_allowed_dashboard_roles( $roles );
	}

	/**
	 * Sanitize the Hide Login enable setting.
	 *
	 * @param mixed $enabled Submitted enable value.
	 * @return string
	 */
	public static function sanitize_enabled( $enabled ) {
		return empty( $enabled ) ? '0' : '1';
	}

	/**
	 * Sanitize dashboard role slugs against roles available on this site.
	 *
	 * @param mixed $roles Submitted role slugs.
	 * @return array
	 */
	public static function sanitize_allowed_dashboard_roles( $roles ) {
		if ( ! is_array( $roles ) ) {
			$roles = empty( $roles ) ? array() : array( $roles );
		}

		$available_roles = array_keys( self::get_available_roles() );
		$roles           = array_map( 'sanitize_key', wp_unslash( $roles ) );
		$roles           = array_values( array_unique( array_intersect( $roles, $available_roles ) ) );

		return $roles;
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
		if ( function_exists( 'user_trailingslashit' ) ) {
			return user_trailingslashit( $string );
		}

		return trailingslashit( $string );
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
	 * Determine whether the current request directly targets wp-login.php.
	 *
	 * @param array|false $request Parsed request URL.
	 * @return bool
	 */
	protected static function is_wp_login_request( $request ) {
		global $pagenow;

		if ( 'wp-login.php' === $pagenow ) {
			return true;
		}

		if ( ! is_array( $request ) || empty( $request['path'] ) ) {
			return false;
		}

		$path            = untrailingslashit( rawurldecode( $request['path'] ) );
		$wp_login_path   = untrailingslashit( site_url( 'wp-login.php', 'relative' ) );
		$wp_login_legacy = untrailingslashit( site_url( 'wp-login', 'relative' ) );

		return $wp_login_path === $path || $wp_login_legacy === $path || 'wp-login.php' === basename( $path );
	}

	/**
	 * Determine whether WordPress should handle the current logout request.
	 *
	 * @param array|false $request Parsed request URL.
	 * @return bool
	 */
	protected static function is_logout_request( $request ) {
		$query = array();

		if ( is_array( $request ) && ! empty( $request['query'] ) ) {
			wp_parse_str( $request['query'], $query );
		} elseif ( isset( $_GET['action'] ) ) {
			$query['action'] = wp_unslash( $_GET['action'] );
		}

		return isset( $query['action'] ) && 'logout' === sanitize_key( $query['action'] );
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
