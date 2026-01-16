<?php
$migrator = foogallery_migrate_migrator_instance();
$migrated_objects = $migrator->get_migrated_objects();
$raw_output = '';

if ( ! empty( $migrated_objects ) ) {
	$raw_output = print_r( $migrated_objects, true );
}
?>
<?php if ( empty( $migrated_objects ) || ! is_array( $migrated_objects ) ) { ?>
	<p><?php esc_html_e( 'No migrated objects found.', 'foogallery-migrate' ); ?></p>
<?php } ?>
<textarea class="large-text code" rows="20" readonly="readonly"><?php echo esc_textarea( $raw_output ); ?></textarea>
