<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
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
$wordpress_scan_complete = isset( $_GET['wordpress-scan-complete'] ) ? sanitize_key( wp_unslash( $_GET['wordpress-scan-complete'] ) ) : '';
$wordpress_gallery_mode = \FooPlugins\FooGalleryMigrate\Plugins\WordPressCore::normalize_mode(
    $migrator->get_migrator_setting(
        \FooPlugins\FooGalleryMigrate\Plugins\WordPressCore::SETTING_MODE,
        \FooPlugins\FooGalleryMigrate\Plugins\WordPressCore::MODE_CREATE
    )
);
if ( '' !== $wordpress_gallery_detected && '0' === $wordpress_scan_complete ) {
    echo '<div class="notice notice-info inline"><p>';
    printf(
        /* translators: %d: detected gallery occurrence count so far. */
        esc_html( _n( 'The scan started and found %d WordPress core gallery so far. Continue it in Blocks / Shortcodes.', 'The scan started and found %d WordPress core galleries so far. Continue it in Blocks / Shortcodes.', $wordpress_gallery_count, 'foogallery-migrate' ) ),
        absint( $wordpress_gallery_count )
    );
    echo '</p></div>';
} elseif ( '0' === $wordpress_gallery_detected ) {
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
<style>
    .foogallery-wordpress-detection summary {
        cursor: pointer;
        display: inline-block;
    }
    .foogallery-wordpress-detection summary::marker,
    .foogallery-wordpress-detection summary::-webkit-details-marker {
        display: none;
        content: '';
    }
    .foogallery-wordpress-mode-options {
        margin: 16px 0 12px;
        max-width: 760px;
    }
    .foogallery-wordpress-mode-option {
        border-top: 1px solid #dcdcde;
        display: grid;
        gap: 4px 8px;
        grid-template-columns: 20px 1fr;
        padding: 12px 0;
    }
    .foogallery-wordpress-mode-option input {
        margin-top: 2px;
    }
    .foogallery-wordpress-mode-option .description {
        grid-column: 2;
        margin: 0;
    }
</style>
<div class="notice notice-info inline foogallery-wordpress-detection">
    <p><strong><?php esc_html_e( 'Built-in WordPress galleries', 'foogallery-migrate' ); ?></strong></p>
    <p><?php esc_html_e( 'Scan published posts and pages for valid [gallery] shortcodes and core Gallery blocks. Detection does not change content.', 'foogallery-migrate' ); ?></p>
    <details>
        <summary class="button button-primary"><?php esc_html_e( 'Detect WordPress Galleries', 'foogallery-migrate' ); ?></summary>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="foogallery_migrate_detect_wordpress_core">
            <?php wp_nonce_field( 'foogallery_migrate_detect_wordpress_core' ); ?>
            <fieldset class="foogallery-wordpress-mode-options">
                <legend><strong><?php esc_html_e( 'Choose how WordPress galleries should be migrated', 'foogallery-migrate' ); ?></strong></legend>
                <label class="foogallery-wordpress-mode-option">
                    <input type="radio" name="wordpress_gallery_mode" value="<?php echo esc_attr( \FooPlugins\FooGalleryMigrate\Plugins\WordPressCore::MODE_DYNAMIC ); ?>" <?php checked( $wordpress_gallery_mode, \FooPlugins\FooGalleryMigrate\Plugins\WordPressCore::MODE_DYNAMIC ); ?>>
                    <strong><?php esc_html_e( 'Replace with dynamic FooGalleries', 'foogallery-migrate' ); ?></strong>
                    <span class="description"><?php esc_html_e( 'Stores the image selection directly in each post or page. No FooGallery records are created, and results appear only in Blocks / Shortcodes.', 'foogallery-migrate' ); ?></span>
                </label>
                <label class="foogallery-wordpress-mode-option">
                    <input type="radio" name="wordpress_gallery_mode" value="<?php echo esc_attr( \FooPlugins\FooGalleryMigrate\Plugins\WordPressCore::MODE_CREATE ); ?>" <?php checked( $wordpress_gallery_mode, \FooPlugins\FooGalleryMigrate\Plugins\WordPressCore::MODE_CREATE ); ?>>
                    <strong><?php esc_html_e( 'Create reusable FooGallery records', 'foogallery-migrate' ); ?></strong>
                    <span class="description"><?php esc_html_e( 'Creates an editable FooGallery for each occurrence, then replaces the source content with its ID. Results appear in Galleries and Blocks / Shortcodes.', 'foogallery-migrate' ); ?></span>
                </label>
            </fieldset>
            <p class="description"><?php esc_html_e( 'Changing mode does not delete FooGalleries created by an earlier migration.', 'foogallery-migrate' ); ?></p>
            <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Scan WordPress Galleries', 'foogallery-migrate' ); ?></button></p>
        </form>
    </details>
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
