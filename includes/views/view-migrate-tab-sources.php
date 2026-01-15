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
        if(isset($_POST['clear_migration_history'])) {
            if ( check_admin_referer('foogallery_migrate_detect', 'foogallery_migrate_detect' ) ) {
                $migrator->clear_migrator_setting();
            }
        } else {
            if ( check_admin_referer('foogallery_migrate_detect', 'foogallery_migrate_detect' ) ) {
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
<?php
if ( $migrator->has_migrated_objects() ) {
    $summary = $migrator->get_migrated_objects_summary();
    ?><h3><?php esc_html_e( 'Migration Stats', 'foogallery-migrate' ); ?></h3>
	<?php if ( array_key_exists( 'album', $summary ) ) { ?>
    <p>
        <?php esc_html_e( 'Albums : ', 'foogallery-migrate' ); ?>
        <?php echo intval( $summary['album']['count'] ); ?>
    </p>
    <?php } ?>
	
    <?php if ( array_key_exists( 'gallery', $summary ) ) { ?>
    <p>
        <?php esc_html_e( 'Galleries : ', 'foogallery-migrate' ); ?>
        <?php echo intval( $summary['gallery']['count'] ); ?>
    </p>
    <?php } ?>
	
    <?php if ( array_key_exists( 'image', $summary ) ) { ?>
    <p>
        <?php esc_html_e( 'Images : ', 'foogallery-migrate' ); ?>
        <?php echo intval( $summary['image']['count'] ); ?>
		<?php if ( $summary['image']['errors'] > 0 ) { ?>
			<span class="foogallery-migrate-progress-error"><?php printf( esc_html__( ' (%s errors)', 'foogallery-migrate' ), intval( $summary['image']['errors'] ) ); ?></span>
		<?php } ?>
    </p>
	<?php } ?>
    <input type="submit" class="button clear_migration_history" name="clear_migration_history" value="<?php esc_attr_e( 'Clear Migration History', 'foogallery-migrate' ); ?>">
<?php } ?>
</form>
