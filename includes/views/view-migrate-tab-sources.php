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
<?php
$wordpress_gallery_detected = isset( $_GET['wordpress-gallery-detected'] ) ? sanitize_key( wp_unslash( $_GET['wordpress-gallery-detected'] ) ) : '';
$wordpress_gallery_count = isset( $_GET['wordpress-gallery-count'] ) ? absint( wp_unslash( $_GET['wordpress-gallery-count'] ) ) : 0;
if ( '0' === $wordpress_gallery_detected ) {
    echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No valid WordPress core galleries were found in published posts or pages.', 'foogallery-migrate' ) . '</p></div>';
} elseif ( '1' === $wordpress_gallery_detected ) {
    echo '<div class="notice notice-success inline"><p>';
    printf(
        /* translators: %d: detected gallery occurrence count. */
        esc_html( _n( 'Found %d WordPress core gallery. You can migrate it in Blocks / Shortcodes.', 'Found %d WordPress core galleries. You can migrate them in Blocks / Shortcodes.', $wordpress_gallery_count, 'foogallery-migrate' ) ),
        absint( $wordpress_gallery_count )
    );
    echo '</p></div>';
}
?>
<div class="notice notice-info inline">
    <p><strong><?php esc_html_e( 'Built-in WordPress galleries', 'foogallery-migrate' ); ?></strong></p>
    <p><?php esc_html_e( 'Scan published posts and pages for valid [gallery] shortcodes and core Gallery blocks. Detection does not change content.', 'foogallery-migrate' ); ?></p>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="foogallery_migrate_detect_wordpress_core">
        <?php wp_nonce_field( 'foogallery_migrate_detect_wordpress_core' ); ?>
        <button type="submit" class="button button-primary"><?php esc_html_e( 'Detect WordPress Galleries', 'foogallery-migrate' ); ?></button>
    </form>
</div>
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
