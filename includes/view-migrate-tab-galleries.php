<?php
    $migrator = foogallery_migrate_migrator_instance();

    //Check if the reset button has been pressed.
//    if ( isset( $_POST['foogallery_migrate_reset'] ) ) {
//        if ( check_admin_referer('foogallery_migrate_reset', 'foogallery_migrate_reset' ) ) {
//            $migrator->reset_migration();
//        }
//    }
?>
<script>
    jQuery(function ($) {

        var $form = $('#foogallery_migrate_gallery_form');

        function foogallery_gallery_migration_ajax(action, success_callback) {
            var data = $form.serialize();

            $.ajax({
                type: "POST",
                url: ajaxurl,
                data: data + "&action=" + action,
                success: success_callback,
                error: function(xhr, ajaxOptions, thrownError) {
                    //something went wrong! Alert the user and reload the page
                    alert('<?php _e( 'Something went wrong with the migration and the page will now reload. Once it has reloaded, click "Resume Migration" to continue with the migration.', 'foogallery-migrate' ); ?>');
                    location.reload();
                }
            });
        }

        function foogallery_gallery_migration_continue(dont_check_progress) {
            foogallery_gallery_migration_ajax( 'foogallery_migrate_continue', function (data) {
                $form.html(data);

                if (dont_check_progress !== true) {
                    //check if we need to carry on polling
                    var percentage = parseInt( $form.find('.migrate_progress').val() );
                    if (percentage < 100) {
                        foogallery_gallery_migration_continue();
                    } else {
                        foogallery_gallery_migration_continue(true);
                    }
                }
            });
        }

        $form.on('click', '.start_migrate', function (e) {
            e.preventDefault();

            // Hide all buttons.
            $form.find('.button').hide();

            // show the spinner.
            $('#foogallery_migrate_gallery_spinner .spinner').addClass('is-active');

            foogallery_gallery_migration_ajax( 'foogallery_migrate', function (data) {
                $form.html(data);
                foogallery_gallery_migration_continue();
            });
        });

        $form.on('click', '.continue_migrate', function (e) {
            e.preventDefault();
            foogallery_gallery_migration_continue();
        });

        $form.on('click', '.cancel_migrate', function (e) {
            if (!confirm('<?php _e( 'Are you sure you want to cancel?', 'foogallery-migrate' ); ?>')) {
                e.preventDefault();
                return false;
            } else {
                foogallery_gallery_migration_ajax( 'foogallery_migrate_cancel', function (data) {
                    $form.html(data);
                } );
            }
        });

        $form.on('click', '.reset_migrate', function (e) {
            if (!confirm('<?php _e( 'Are you sure you want to reset all migration data? This may result in duplicate galleries and media attachments!', 'foogallery-migrate' ); ?>')) {
                e.preventDefault();
                return false;
            } else {
                foogallery_gallery_migration_ajax( 'foogallery_migrate_reset', function (data) {
                    $form.html(data);
                } );
            }
        });
    });
</script>
<form id="foogallery_migrate_gallery_form" method="POST">
    <?php $migrator->render_gallery_form(); ?>
</form>