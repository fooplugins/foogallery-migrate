<?php
    $migrator = foogallery_migrate_migrator_instance();

    //Check if the detect button has been pressed.
    if ( array_key_exists( 'foogallery_migrate_detect', $_POST ) ) {
        if (check_admin_referer('foogallery_migrate_detect', 'foogallery_migrate_detect')) {
            $migrator->run_detection();
        }
    }

    if ( !$migrator->has_detected_plugins() ) { ?>
<p>
    <?php _e( 'No other gallery plugins have been detected, so there is nothing to migrate!', 'foogallery-migrate' ); ?>
</p>
    <?php } else { ?>
<p>
    <?php _e( 'We detected the following gallery plugins to migrate:', 'foogallery-migrate' ); ?>
</p>
    <?php } ?>
<ul>
    <?php
    foreach ( $migrator->plugins as $plugin ) {
        echo '<li>' . $plugin->name();
        echo $plugin->is_detected() ? '<span class="dashicons dashicons-yes-alt"></span>' : '<span class="dashicons dashicons-dismiss"></span>';
        echo '</li>';
    }
    ?>
</ul>
<form method="POST">
    <?php wp_nonce_field( 'foogallery_migrate_detect', 'foogallery_migrate_detect', false ); ?>
    <input type="submit" class="button" value="<?php _e( 'Run Detection Again', 'foogallery-migrate' ); ?>">
</form>