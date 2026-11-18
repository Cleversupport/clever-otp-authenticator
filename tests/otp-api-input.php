<?php
/**
 * OTP API input contracts with WordPress doubles and a CLI filter_input shim.
 * Run: php tests/otp-api-input.php
 */
namespace {
	error_reporting( E_ALL );
	set_error_handler( function ( $severity, $message, $file, $line ) {
		throw new \ErrorException( $message, 0, $severity, $file, $line );
	} );
	define( 'ABSPATH', __DIR__ );

	// Only a contract double: WordPress owns the full sanitization behavior.
	function sanitize_text_field( $value ) {
		$GLOBALS['sanitized_inputs'][] = $value;
		return trim( strip_tags( $value ) );
	}
}

namespace OtpaApiInputTest {
	class JsonResponse extends \Exception {
		public $success;
		public $data;
		public function __construct( $success, $data ) {
			$this->success = $success;
			$this->data = $data;
		}
	}

	// CLI cannot populate PHP's original POST input. Apply the real native
	// filtering engine to fixtures using the exact filter/options from production.
	function filter_input( $type, $name, $filter, $options = 0 ) {
		expect( INPUT_POST === $type, 'POST input source' );
		if ( ! array_key_exists( $name, $GLOBALS['post_input'] ) ) {
			return null;
		}
		return \filter_var( $GLOBALS['post_input'][ $name ], $filter, $options );
	}
	function apply_filters( $hook, $value ) { return $value; }
	function wp_verify_nonce( $nonce, $action ) {
		return 'valid-nonce' === $nonce && 'otpa_nonce' === $action;
	}
	function wp_send_json_error( $data ) { throw new JsonResponse( false, $data ); }
	function wp_send_json_success( $data ) { throw new JsonResponse( true, $data ); }
	function is_wp_error( $value ) { return false; }
	function __( $text, $domain = '' ) { return $text; }
	function expect( $condition, $message ) {
		if ( ! $condition ) { throw new \Exception( $message ); }
	}

	// Namespace only to substitute input/WordPress boundaries; execute the actual
	// production class and its handle_api_request method without rewriting it.
	$source = file_get_contents( __DIR__ . '/../inc/class-otpa.php' );
	eval( 'namespace OtpaApiInputTest; ' . substr( $source, 5 ) );

	class Api extends Otpa {
		public $received = array();
		public function request_otp_code( $payload ) {
			$this->received[] = $payload;
			return $payload;
		}
		public function verify_otp_code( $payload ) { return $this->request_otp_code( $payload ); }
		public function set_otp_identifier( $payload ) { return $this->request_otp_code( $payload ); }
	}

	function dispatch( $post ) {
		$GLOBALS['post_input'] = $post;
		$GLOBALS['sanitized_inputs'] = array();
		$api = new Api( null );
		try {
			$api->handle_api_request();
		} catch ( JsonResponse $response ) {
			return array( $api, $response );
		}
		throw new \Exception( 'Expected a JSON response' );
	}

	foreach ( array( 'request_otp_code', 'verify_otp_code', 'set_otp_identifier' ) as $handler ) {
		$payload = array( 'identifier' => 'user+otp@example.test', 'code' => '001234' );
		list( $api, $response ) = dispatch( array(
			'nonce' => 'valid-nonce', 'handler' => $handler,
			'otpType' => 'default', 'payload' => $payload,
		) );
		expect( $response->success && array( $payload ) === $api->received, 'Valid handler and OTP payload preserved' );
		expect( array( 'valid-nonce', 'default', $handler, 'user+otp@example.test', '001234' ) === $GLOBALS['sanitized_inputs'], 'Every scalar input uses the WordPress sanitizer callback' );
	}

	foreach ( array( array(), array( 'nonce' => 'wrong' ), array( 'nonce' => array( 'valid-nonce' ) ) ) as $post ) {
		list( $api, $response ) = dispatch( $post );
		expect( ! $response->success && ! $api->received, 'Missing, invalid, or array nonce cannot dispatch' );
	}

	$base = array( 'nonce' => 'valid-nonce', 'handler' => 'request_otp_code', 'otpType' => 'default' );
	foreach ( array( 'activate', 'not_a_handler', array( 'request_otp_code' ) ) as $handler ) {
		$post = $base;
		$post['handler'] = $handler;
		list( $api, $response ) = dispatch( $post );
		expect( ! $response->success && ! $api->received, 'Unauthorized or array handler cannot dispatch' );
	}
	list( $api, $response ) = dispatch( $base + array( 'payload' => 'not-an-array' ) );
	expect( array( false ) === $api->received, 'Scalar payload remains rejected by FILTER_REQUIRE_ARRAY' );
	list( $api, $response ) = dispatch( $base );
	expect( array( null ) === $api->received, 'Missing payload retains null contract' );
	list( $api, $response ) = dispatch( $base + array( 'payload' => array(
		'identifier' => '<b>user@example.test</b>', 'nested' => array( 'code' => ' 001234 ' ),
	) ) );
	expect( array( array( 'identifier' => 'user@example.test', 'nested' => array( 'code' => '001234' ) ) ) === $api->received, 'Native filter recursively sanitizes payload values' );

	echo "OTP API input contracts passed with E_ALL.\n";
}
