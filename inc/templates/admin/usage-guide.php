<?php
if ( ! defined( 'ABSPATH' ) || ! current_user_can( 'manage_options' ) ) {
	return;
}
?>
<div class="otpa-usage-guide">
	<h2><?php esc_html_e( 'Usage Guide', 'otpa' ); ?></h2>
	<p><?php esc_html_e( 'Use this guide alongside the settings tabs above. Reading it does not change your settings. All guide content is included with the plugin.', 'otpa' ); ?></p>
	<nav aria-label="<?php esc_attr_e( 'Usage Guide sections', 'otpa' ); ?>">
		<ul><?php foreach ( $sections as $id => $section ) : ?>
			<li><a href="#otpa-guide-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $section['title'] ); ?></a></li>
		<?php endforeach; ?></ul>
	</nav>
	<?php foreach ( $sections as $id => $section ) : ?>
		<section class="stuffbox" aria-labelledby="otpa-guide-<?php echo esc_attr( $id ); ?>">
			<div class="inside">
				<h2 id="otpa-guide-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $section['title'] ); ?></h2>
				<?php foreach ( $section['paragraphs'] as $paragraph ) : ?>
					<p><?php echo esc_html( $paragraph ); ?></p>
				<?php endforeach; ?>
				<?php if ( isset( $section['examples'] ) ) : ?>
					<?php foreach ( $section['examples'] as $index => $example ) : $example_id = 'otpa-example-' . $id . '-' . $index; ?>
						<div class="otpa-guide-example">
							<label for="<?php echo esc_attr( $example_id ); ?>"><?php echo esc_html( $example[0] ); ?></label>
							<textarea id="<?php echo esc_attr( $example_id ); ?>" readonly rows="3" spellcheck="false"><?php echo esc_textarea( $example[1] ); ?></textarea>
							<?php /* translators: %s: example label. */ ?>
							<button type="button" class="button otpa-guide-copy" hidden data-copy-target="<?php echo esc_attr( $example_id ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Copy example: %s', 'otpa' ), $example[0] ) ); ?>"><?php esc_html_e( 'Copy', 'otpa' ); ?></button>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</section>
	<?php endforeach; ?>
	<p class="otpa-guide-status" role="status" aria-live="polite" aria-atomic="true" data-copied="<?php esc_attr_e( 'Copied to clipboard.', 'otpa' ); ?>" data-manual="<?php esc_attr_e( 'Copy was unavailable. The example is selected; press Ctrl+C or Command+C to copy.', 'otpa' ); ?>"></p>
</div>
