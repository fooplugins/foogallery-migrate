<?php
    $migrator = foogallery_migrate_migrator_instance();    
?>
<script>
    jQuery(function ($) {
        var $form = $('#foogallery_migrate_source_form');
        $form.on('click', '.clear_migration_history', function(e) {
            if (!confirm('<?php echo esc_js( __( 'Are you sure you want to clear migration histories? This may result in duplicate album/galleries and media attachments!', 'foogallery-migrate' ) ); ?>')) {
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
    $summary = $migrator->get_migrated_objects_summary()
    ?><h3><?php esc_html_e('Migration Stats', 'foogallery-migrate'); ?></h3>
    <p>
        <?php esc_html_e( 'Albums : ', 'foogallery-migrate' ); ?>
        <?php echo esc_html( $summary['album'] ); ?>
    </p>
    <p>
        <?php esc_html_e( 'Galleries : ', 'foogallery-migrate' ); ?>
        <?php echo esc_html( $summary['gallery'] ); ?>
    </p>
    <p>
        <?php esc_html_e( 'Images : ', 'foogallery-migrate' ); ?>
        <?php echo esc_html( $summary['image'] ); ?>
    </p>
    <input type="submit" class="button clear_migration_history" name="clear_migration_history" value="<?php esc_attr_e( 'Clear Migration History', 'foogallery-migrate' ); ?>">
<?php } ?>
</form>