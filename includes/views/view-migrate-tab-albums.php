<?php
    $migrator = foogallery_migrate_migrator_instance();
    $albums_enabled = class_exists( 'FooGalleryAlbum' );
?>
<script>
    jQuery(function ($) {

        var $form = $('#foogallery_migrate_album_form');

        function foogallery_album_migration_ajax(action, success_callback) {
            var data = $form.serialize();

            // Hide all buttons.
            $form.find('.button').hide();

            // show the spinner.
            $('#foogallery_migrate_album_spinner .spinner').addClass('is-active');

            $.ajax({
                type: "POST",
                url: ajaxurl,
                data: data + "&action=" + action,
                success: success_callback,
                error: function(xhr, ajaxOptions, thrownError) {
                    //something went wrong! Alert the user and reload the page
                    console.log(thrownError);
                    alert('<?php _e( 'Something went wrong with the migration and the page will now reload. Once it has reloaded, click "Resume Migration" to continue with the migration.', 'foogallery-migrate' ); ?>');
                    location.reload();
                }
            });
        }

        function foogallery_album_migration_continue(dont_check_progress) {
            foogallery_album_migration_ajax( 'foogallery_album_migrate_continue', function (data) {
                $form.html(data);

                if (dont_check_progress !== true) {
                    //check if we need to carry on polling
                    var percentage = parseInt( $form.find('.album_migrate_progress').val() );
                    if (percentage < 100) {
                        foogallery_album_migration_continue();
                    } else {
                        foogallery_album_migration_continue(true);
                    }
                }
            });
        }

        $form.on('click', '.start_album_migrate', function (e) {
            e.preventDefault();

            foogallery_album_migration_ajax( 'foogallery_album_migrate', function (data) {
                $form.html(data);
                foogallery_album_migration_continue();
            });
        });

        $form.on('click', '.continue_album_migrate', function (e) {
            e.preventDefault();
            foogallery_album_migration_continue();
        });

        $form.on('click', '.cancel_album_migrate', function (e) {
            e.preventDefault();

            if (!confirm('<?php _e( 'Are you sure you want to cancel?', 'foogallery-migrate' ); ?>')) {
                return false;
            } else {
                foogallery_album_migration_ajax( 'foogallery_album_migrate_cancel', function (data) {
                    $form.html(data);
                } );
            }
        });

        $form.on('click', '.refresh_albums', function (e) {
            e.preventDefault();
            foogallery_album_migration_ajax( 'foogallery_album_migrate_refresh', function (data) {
                $form.html(data);
            } );            
        });
    });
</script>
<form id="foogallery_migrate_album_form" method="POST">
    <?php
    if ( $albums_enabled ) {
        $migrator->get_album_migrator()->render_album_form();
    } else {
        echo '<h2>' . __( 'Album feature not enabled!', 'foogallery-migrate' ) . '</h2>';
        echo '<p>';
        _e( 'Please enable the Albums feature from FooGallery -> Features before you migrate any albums!', 'foogallery-migrate' );
        echo '</p>';
    }
    ?>
</form>