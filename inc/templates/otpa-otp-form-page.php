<?php if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

?>
<!doctype html>
<html <?php language_attributes(); ?>>
	<head>
		<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1.0, user-scalable=no">
		<?php wp_head(); ?>
	</head>
	<body class="otpa-<?php echo esc_attr( str_replace( '_', '-', $otp_form_type ) ); ?> otpa-page">
		<?php load_template( OTPA_PLUGIN_PATH . 'inc/templates/otpa-otp-form-card.php' ); ?>
		<?php wp_footer(); ?>
	</body>
</html>
