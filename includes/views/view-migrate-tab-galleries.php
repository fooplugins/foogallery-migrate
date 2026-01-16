<?php
    $migrator = foogallery_migrate_migrator_instance();
?>
<script>
    jQuery(function ($) {
        var migrationErrorMessage = <?php echo wp_json_encode( __( 'Something went wrong with the migration and the page will now reload. Once it has reloaded, click "Resume Migration" to continue with the migration.', 'foogallery-migrate' ) ); ?>;
        var cancelConfirmMessage = <?php echo wp_json_encode( __( 'Are you sure you want to cancel?', 'foogallery-migrate' ) ); ?>;

        var $form = $('#foogallery_migrate_gallery_form');

        function foogallery_gallery_migration_ajax(action, success_callback) {
            var data = $form.serialize();

            // Hide all buttons.
            $form.find('.button').hide();

            // show the spinner.
            $('#foogallery_migrate_gallery_spinner .spinner').addClass('is-active');

            $.ajax({
                type: "POST",
                url: ajaxurl,
                data: data + "&action=" + action,
                success: success_callback,
                error: function(xhr, ajaxOptions, thrownError) {
                    //something went wrong! Alert the user and reload the page
                    window.alert(migrationErrorMessage);
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
            e.preventDefault();
            if (!window.confirm(cancelConfirmMessage)) {
                return false;
            } else {
                foogallery_gallery_migration_ajax( 'foogallery_migrate_cancel', function (data) {
                    $form.html(data);
                } );
            }
        });

        $form.on('click', '.refresh_gallery', function (e) {
            e.preventDefault();
            foogallery_gallery_migration_ajax( 'foogallery_migrate_refresh', function (data) {
                $form.html(data);
            } );
        });

        $form.on('click', '.retry_migrate_gallery', function (e) {
            e.preventDefault();
            var galleryId = $(this).data('galleryId');
            $form.find('input[name="foogallery_migrate_retry_gallery_id"]').val(galleryId);
            foogallery_gallery_migration_ajax( 'foogallery_migrate_retry_gallery', function (data) {
                $form.html(data);
                foogallery_gallery_migration_continue();
            } );
        });

        $form.on('click', '.check_migrate_gallery', function (e) {
            e.preventDefault();
            var galleryId = $(this).data('galleryId');
            $form.find('input[name="foogallery_migrate_check_gallery_id"]').val(galleryId);
            foogallery_gallery_migration_ajax( 'foogallery_migrate_check_gallery_errors', function (data) {
                $form.html(data);
            } );
        });
    });
</script>
<form id="foogallery_migrate_gallery_form" method="POST">
    <?php $migrator->get_gallery_migrator()->render_gallery_form(); ?>
</form>
