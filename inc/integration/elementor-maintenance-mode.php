<?php
/**
 * Preserve OTP query variables when Elementor maintenance mode is enabled.
 *
 * @package Clever_OTP_Authenticator
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Treat only the two authentication endpoints as login pages for Elementor.
 *
 * Elementor otherwise replaces the query after OTP prepares its form variables.
 * This filter changes presentation only; OTP routing and validation still run.
 *
 * @param bool $is_login_page Elementor's existing login-page decision.
 * @return bool
 */
function otpa_elementor_maintenance_is_login_page( $is_login_page ) {
	if ( $is_login_page ) {
		return $is_login_page;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
	if ( ! is_string( $request_uri ) || '/' !== substr( $request_uri, 0, 1 ) || '//' === substr( $request_uri, 0, 2 ) ) {
		return $is_login_page;
	}

	// Ignore query parameters without decoding or broadening the allowed paths.
	$path = wp_parse_url( $request_uri, PHP_URL_PATH );
	foreach ( array( '/otpa/passwordless-login', '/otpa-api/otp' ) as $endpoint ) {
		$endpoint_path = wp_parse_url( home_url( $endpoint ), PHP_URL_PATH );
		if ( is_string( $endpoint_path ) && ( $path === $endpoint_path || $path === $endpoint_path . '/' ) ) {
			return true;
		}
	}

	return $is_login_page;
}

// Register during plugin loading, before Elementor's template_redirect (priority 11).
// Registering an unused filter is harmless when Elementor (or this hook) is absent.
add_filter( 'elementor/maintenance_mode/is_login_page', 'otpa_elementor_maintenance_is_login_page' );
