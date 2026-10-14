<?php
/**
 * Session-scoped inactivity protection within the existing plugin.
 *
 * @package Clever_OTP_Authenticator
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Otpa_Inactivity_Module {
	const OPTION = 'otpa_inactivity';
	const DEFAULT_REDIRECT = '/otpa/passwordless-login/';
	private static $request_expired = false;

	/** Register settings even when protection is disabled. */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		// WordPress < 5.5 supplies no user ID to wp_logout. Capture before cookies/current user clear.
		add_action( 'clear_auth_cookie', array( __CLASS__, 'cleanup' ), 0 );
		if ( ! self::settings()['enabled'] ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'enforce' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_expired' ), 0 );
		add_action( 'admin_init', array( __CLASS__, 'redirect_expired' ), 0 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'rest_expired' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_otpa_inactivity', array( __CLASS__, 'endpoint' ) );
		add_action( 'wp_ajax_nopriv_otpa_inactivity', array( __CLASS__, 'logged_out_status' ) );
	}

	public static function defaults() {
		return array( 'enabled' => 0, 'timeout' => 30, 'warning' => 1, 'warning_minutes' => 2, 'redirect' => self::DEFAULT_REDIRECT, 'exclude_admins' => 0 );
	}

	public static function settings() {
		return self::sanitize( get_option( self::OPTION, array() ) );
	}

	/** Reject invalid durations; zero selects the final 30-second warning. */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = array_merge( self::defaults(), array_intersect_key( $input, self::defaults() ) );
		foreach ( array( 'enabled', 'warning', 'exclude_admins' ) as $key ) {
			$out[ $key ] = empty( $out[ $key ] ) ? 0 : 1;
		}
		foreach ( array( 'timeout', 'warning_minutes' ) as $key ) {
			$value       = filter_var( $out[ $key ], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 'timeout' === $key ? 1 : 0, 'max_range' => 525600 ) ) );
			$out[ $key ] = false === $value ? self::defaults()[ $key ] : $value;
		}
		$out['warning_minutes'] = min( $out['warning_minutes'], $out['timeout'] - 1 );
		$out['redirect']        = self::safe_redirect( $out['redirect'] );
		return $out;
	}

	/** Restrict redirects to this site's origin as well as WordPress's URL validation. */
	public static function safe_redirect( $value ) {
		$fallback = home_url( self::DEFAULT_REDIRECT );
		if ( ! is_string( $value ) || '' === $value || preg_match( '/[\x00-\x20\\\\]/', $value ) ) {
			return $fallback;
		}
		$relative = wp_parse_url( $value );
		$url = is_array( $relative ) && ! isset( $relative['scheme'] ) && ! isset( $relative['host'] ) && 0 !== strpos( $value, '//' ) ? home_url( '/' . ltrim( $value, '/' ) ) : esc_url_raw( $value, array( 'http', 'https' ) );
		$a   = wp_parse_url( $url );
		$b   = wp_parse_url( home_url() );
		if ( ! $a || empty( $a['host'] ) || strtolower( $a['host'] ) !== strtolower( $b['host'] ) || empty( $a['scheme'] ) || $a['scheme'] !== $b['scheme'] || isset( $a['user'] ) || isset( $a['pass'] ) || ( isset( $a['port'] ) ? $a['port'] : null ) !== ( isset( $b['port'] ) ? $b['port'] : null ) ) {
			return $fallback;
		}
		return wp_validate_redirect( $url, $fallback );
	}

	public static function register_settings() {
		register_setting( Otpa_Hide_Login_Module::SETTINGS_GROUP, self::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ), 'default' => self::defaults() ) );
	}

	public static function render_settings() {
		$s = self::settings();
		$fields = array(
			'enabled' => array( __( 'Enable Automatic Logout Due to Inactivity', 'otpa' ), 'checkbox' ),
			'timeout' => array( __( 'Inactivity Timeout (minutes)', 'otpa' ), 'number' ),
			'warning' => array( __( 'Show Warning Before Logout', 'otpa' ), 'checkbox' ),
			'warning_minutes' => array( __( 'Warning Before Logout (minutes)', 'otpa' ), 'number' ),
			'redirect' => array( __( 'Redirect After Logout', 'otpa' ), 'text' ),
		);
		?>
		<h2><?php esc_html_e( 'Automatic Logout Due to Inactivity', 'otpa' ); ?></h2>
		<table class="form-table" role="presentation">
		<?php foreach ( $fields as $key => $field ) : ?>
			<tr><th scope="row"><label for="otpa-inactivity-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field[0] ); ?></label></th><td>
			<?php if ( 'checkbox' === $field[1] ) : ?>
				<input type="hidden" name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>" value="0">
			<?php endif; ?>
			<input id="otpa-inactivity-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>" type="<?php echo esc_attr( $field[1] ); ?>" value="<?php echo esc_attr( 'checkbox' === $field[1] ? 1 : $s[ $key ] ); ?>" <?php if ( 'checkbox' === $field[1] ) { checked( $s[ $key ], 1 ); } ?> <?php if ( 'number' === $field[1] ) { echo 'step="1" min="' . ( 'timeout' === $key ? '1' : '0' ) . '" max="' . esc_attr( 525600 ) . '"'; } ?>>
			<?php if ( 'timeout' === $key ) : ?><p class="description"><?php esc_html_e( 'Automatically log out users after this period without activity.', 'otpa' ); ?></p><?php endif; ?>
			<?php if ( 'warning_minutes' === $key ) : ?><p class="description"><?php esc_html_e( 'Must be lower than the timeout. At a one-minute timeout, zero shows the warning during the final 30 seconds.', 'otpa' ); ?></p><?php endif; ?>
			</td></tr>
		<?php endforeach; ?>
		<tr><th scope="row"><label for="otpa-inactivity-roles"><?php esc_html_e( 'Apply Automatic Logout To', 'otpa' ); ?></label></th><td><select id="otpa-inactivity-roles" name="<?php echo esc_attr( self::OPTION ); ?>[exclude_admins]">
		<option value="0" <?php selected( $s['exclude_admins'], 0 ); ?>><?php esc_html_e( 'All Users', 'otpa' ); ?></option>
		<option value="1" <?php selected( $s['exclude_admins'], 1 ); ?>><?php esc_html_e( 'All Users Except Administrators', 'otpa' ); ?></option>
		</select></td></tr></table>
		<?php
	}

	public static function applies() {
		$s = self::settings();
		$u = wp_get_current_user();
		return $s['enabled'] && $u->exists() && wp_get_session_token() && WP_Session_Tokens::get_instance( $u->ID )->verify( wp_get_session_token() ) && ! ( $s['exclude_admins'] && ( in_array( 'administrator', (array) $u->roles, true ) || user_can( $u, 'manage_options' ) || ( is_multisite() && is_super_admin( $u->ID ) ) ) );
	}

	/** Never store or expose the authentication token itself. Site policy is scoped within a shared multisite session. */
	public static function key() {
		return '_otpa_idle_' . get_current_blog_id() . '_' . hash_hmac( 'sha256', wp_get_session_token(), wp_salt( 'auth' ) );
	}

	public static function last_activity() {
		$id   = get_current_user_id();
		$key  = self::key();
		$last = get_user_meta( $id, $key, true );
		if ( '' === $last ) {
			// Only an untracked session gets a first-observation baseline. Never reset existing records.
			add_user_meta( $id, $key, time(), true );
			$last = get_user_meta( $id, $key, true );
		}
		return (int) $last;
	}

	public static function expired() {
		$timeout = self::settings()['timeout'] * MINUTE_IN_SECONDS;
		if ( time() < self::last_activity() + $timeout ) {
			return false;
		}
		// Recheck at the boundary: another tab may have reported after this request loaded user metadata.
		wp_cache_delete( get_current_user_id(), 'user_meta' );
		if ( ! WP_Session_Tokens::get_instance( get_current_user_id() )->verify( wp_get_session_token() ) ) {
			return true;
		}
		return time() >= self::last_activity() + $timeout;
	}

	/** Remove only this token's records across sites, before WordPress clears authentication. */
	public static function cleanup() {
		$user_id = get_current_user_id();
		$token   = wp_get_session_token();
		if ( ! $user_id || ! $token ) {
			return;
		}
		$suffix = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
		foreach ( array_keys( get_user_meta( $user_id ) ) as $key ) {
			if ( preg_match( '/^_otpa_idle_[0-9]+_' . preg_quote( $suffix, '/' ) . '$/', $key ) ) {
				delete_user_meta( $user_id, $key );
			}
		}
	}

	public static function logout() {
		wp_logout(); // Destroys only the current token; clear_auth_cookie cleans its metadata.
	}

	/** Read-only logged-out status, never a public logout or renewal action. */
	public static function logged_out_status() {
		wp_send_json_error( array( 'expired' => true ), 401 );
	}

	/** All cookie-authenticated requests validate expiration; none implicitly renew it. */
	public static function enforce() {
		global $pagenow;
		// Preserve core's nonce/session handling for the native Hide Login logout exception.
		if ( 'wp-login.php' === $pagenow && isset( $_REQUEST['action'] ) && 'logout' === $_REQUEST['action'] ) {
			return;
		}
		if ( ! self::applies() || ! self::expired() ) {
			return;
		}
		self::logout();
		self::$request_expired = true;
		if ( wp_doing_ajax() ) {
			wp_send_json_error( array( 'expired' => true, 'redirect' => self::settings()['redirect'] ), 401 );
		}
	}

	public static function redirect_expired() {
		if ( self::$request_expired ) {
			wp_safe_redirect( self::settings()['redirect'] );
			exit;
		}
	}

	public static function rest_expired( $result ) {
		return self::$request_expired ? new WP_Error( 'otpa_session_expired', __( 'Session expired due to inactivity.', 'otpa' ), array( 'status' => 401 ) ) : $result;
	}

	/** Authenticated POST endpoint. Expiration always precedes renewal. */
	public static function endpoint() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] || ! self::applies() ) {
			wp_send_json_error( array(), 403 );
		}
		check_ajax_referer( 'otpa_inactivity', 'nonce' );
		if ( self::expired() ) {
			self::logout();
			wp_send_json_error( array( 'expired' => true, 'redirect' => self::settings()['redirect'] ), 401 );
		}
		$mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		if ( ! in_array( $mode, array( 'status', 'activity', 'stay' ), true ) ) {
			wp_send_json_error( array(), 400 );
		}
		if ( 'activity' === $mode || 'stay' === $mode ) {
			$last = self::last_activity();
			$age  = isset( $_POST['idle_ms'] ) ? filter_var( wp_unslash( $_POST['idle_ms'] ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0, 'max_range' => PHP_INT_MAX ) ) ) : 0;
			if ( false === $age ) {
				wp_send_json_error( array(), 400 );
			}
			$next = 'stay' === $mode ? time() : time() - (int) ceil( $age / 1000 );
			if ( $next > $last && ( time() - $last >= 5 || 'stay' === $mode ) ) {
				// Compare-and-set avoids overwriting a newer activity report from another request.
				update_user_meta( get_current_user_id(), self::key(), $next, $last );
				// A failed compare-and-set may mean another request won; return its current deadline.
				wp_cache_delete( get_current_user_id(), 'user_meta' );
			}
		}
		wp_send_json_success( array( 'remaining' => max( 0, self::last_activity() + self::settings()['timeout'] * MINUTE_IN_SECONDS - time() ), 'nonce' => wp_create_nonce( 'otpa_inactivity' ) ) );
	}

	public static function enqueue() {
		if ( ! self::applies() ) {
			return;
		}
		$s = self::settings();
		wp_enqueue_script( 'otpa-inactivity', OTPA_PLUGIN_URL . 'js/inactivity.js', array(), '1.0.21', true );
		wp_enqueue_style( 'otpa-inactivity', OTPA_PLUGIN_URL . 'css/inactivity.css', array(), '1.0.21' );
		wp_localize_script( 'otpa-inactivity', 'otpaInactivity', array(
			'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'otpa_inactivity' ),
			'key' => self::key(), 'timeout' => $s['timeout'] * MINUTE_IN_SECONDS,
			'remaining' => max( 0, self::last_activity() + $s['timeout'] * MINUTE_IN_SECONDS - time() ),
			'warning' => $s['warning'] ? max( 30, $s['warning_minutes'] * MINUTE_IN_SECONDS ) : 0,
			'redirect' => $s['redirect'], 'fallback' => wp_logout_url( $s['redirect'] ),
			'title' => __( 'Session Expiring Soon', 'otpa' ),
			'message' => __( 'Your session is about to expire due to inactivity.', 'otpa' ),
			'countdown' => __( 'You will be automatically logged out in %s.', 'otpa' ),
			'stay' => __( 'Stay Logged In', 'otpa' ),
			'failure' => __( 'Unable to verify your session. Retrying…', 'otpa' ),
			'logout' => __( 'Log Out Securely', 'otpa' ),
		) );
	}
}
