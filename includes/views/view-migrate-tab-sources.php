<?php
    $migrator = foogallery_migrate_migrator_instance();    
?>
<script>
    jQuery(function ($) {
        var $form = $('#foogallery_migrate_source_form');
        var confirmMessage = <?php echo wp_json_encode( __( 'Are you sure you want to clear migration histories? This may result in duplicate album/galleries and media attachments!', 'foogallery-migrate' ) ); ?>;
        $form.on('click', '.clear_migration_history', function(e) {
            if (!window.confirm(confirmMessage)) {
                e.preventDefault();
                return false;
            } else {
                $form.submit();
            }
        });
    });
    </script>
<?php
    //Check if the detect button has been pressed.   
    if ( array_key_exists( 'foogallery_migrate_detect', $_POST ) ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'foogallery-migrate' ) );
        }
        if ( isset( $_POST['clear_migration_history'] ) ) {
            if ( check_admin_referer( 'foogallery_migrate_detect', 'foogallery_migrate_detect' ) ) {
                $migrator->clear_migrator_setting();
            }
        } else if ( isset( $_POST['check_migration_errors'] ) ) {
            if ( check_admin_referer( 'foogallery_migrate_detect', 'foogallery_migrate_detect' ) ) {
                $migrator->check_for_migration_errors();
				$migrator->get_gallery_migrator()->get_objects_to_migrate(true);
            }
        } else {
            if ( check_admin_referer( 'foogallery_migrate_detect', 'foogallery_migrate_detect' ) ) {
                $migrator->run_detection();
            }
        }
    }

    if ( !$migrator->has_detected_plugins() ) { ?>
<p>
    <?php esc_html_e( 'No other gallery plugins have been detected, so there is nothing to migrate!', 'foogallery-migrate' ); ?>
</p>
    <?php } else { ?>
<p>
    <?php esc_html_e( 'We detected the following gallery plugins to migrate:', 'foogallery-migrate' ); ?>
</p>
    <?php } ?>
<ul>
    <?php
    foreach ( $migrator->get_plugins() as $plugin ) {
        echo '<li>' . esc_html( $plugin->name() );
        echo $plugin->is_detected ? '<span class="dashicons dashicons-yes-alt"></span>' : '<span class="dashicons dashicons-dismiss"></span>';
        echo '</li>';
    }
    ?>
</ul>
<form method="POST" id="foogallery_migrate_source_form">
    <?php wp_nonce_field( 'foogallery_migrate_detect', 'foogallery_migrate_detect', false ); ?>
    <input type="submit" class="button" value="<?php esc_attr_e( 'Run Detection Again', 'foogallery-migrate' ); ?>">
	<?php if ( $migrator->has_migrated_objects() ) { ?>
		<input type="submit" class="button clear_migration_history" name="clear_migration_history" value="<?php esc_attr_e( 'Clear Migration History', 'foogallery-migrate' ); ?>">
		<input type="submit" class="button" name="check_migration_errors" value="<?php esc_attr_e( 'Check For Migration Errors', 'foogallery-migrate' ); ?>">
	<?php } ?>
</form>
