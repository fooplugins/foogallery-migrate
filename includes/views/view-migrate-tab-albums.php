<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
    $migrator = foogallery_migrate_migrator_instance();
    $albums_enabled = class_exists( 'FooGalleryAlbum' );
?>
<script>
    jQuery(function ($) {
        var migrationErrorMessage = <?php echo wp_json_encode( __( 'Something went wrong with the migration and the page will now reload. Once it has reloaded, click "Resume Migration" to continue with the migration.', 'foogallery-migrate' ) ); ?>;
        var cancelConfirmMessage = <?php echo wp_json_encode( __( 'Are you sure you want to cancel?', 'foogallery-migrate' ) ); ?>;
        var selectAlbumMessage = <?php echo wp_json_encode( __( 'Please select at least one album to migrate.', 'foogallery-migrate' ) ); ?>;

        var $form = $('#foogallery_migrate_album_form');

        function updateAlbumPreflight() {
            var albumCount = 0;
            var galleryCount = 0;
            var imageCount = 0;
            var $checkboxes = $form.find('.foogallery-migrate-album-select:not(:disabled)');

            $checkboxes.filter(':checked').each(function () {
                albumCount++;
                galleryCount += parseInt($(this).data('galleryCount'), 10) || 0;
                imageCount += parseInt($(this).data('imageCount'), 10) || 0;
            });

            $form.find('.foogallery-migrate-preflight-report [data-role="album-count"]').text(albumCount);
            $form.find('.foogallery-migrate-preflight-report [data-role="gallery-count"]').text(galleryCount);
            $form.find('.foogallery-migrate-preflight-report [data-role="image-count"]').text(imageCount);
            $form.find('.foogallery-migrate-album-select-all').prop('checked', $checkboxes.length > 0 && $checkboxes.length === $checkboxes.filter(':checked').length);
        }

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
                success: function(data) {
                    if (window.foogalleryMigrateHandleAjaxResponse(data, migrationErrorMessage, action)) {
                        return;
                    }
                    success_callback(data);
                    updateAlbumPreflight();
                },
                error: function(xhr, ajaxOptions, thrownError) {
                    window.foogalleryMigrateHandleAjaxError(xhr, ajaxOptions, thrownError, migrationErrorMessage, action);
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

            if ($form.find('.foogallery-migrate-album-select:checked:not(:disabled)').length < 1) {
                window.alert(selectAlbumMessage);
                return false;
            }

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

            if (!window.confirm(cancelConfirmMessage)) {
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

        $form.on('change', '.foogallery-migrate-album-select-all', function () {
            $form.find('.foogallery-migrate-album-select:not(:disabled)').prop('checked', $(this).prop('checked'));
            updateAlbumPreflight();
        });

        $form.on('change', '.foogallery-migrate-album-select', updateAlbumPreflight);

        updateAlbumPreflight();
    });
</script>
<form id="foogallery_migrate_album_form" method="POST">
    <?php
    if ( $albums_enabled ) {
        $migrator->get_album_migrator()->render_album_form();
    } else {
        echo '<h2>' . esc_html__( 'Album feature not enabled!', 'foogallery-migrate' ) . '</h2>';
        echo '<p>';
        esc_html_e( 'Please enable the Albums feature from FooGallery -> Features before you migrate any albums!', 'foogallery-migrate' );
        echo '</p>';
    }
    ?>
</form>
