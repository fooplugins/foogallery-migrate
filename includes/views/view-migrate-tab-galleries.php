<?php
    $migrator = foogallery_migrate_migrator_instance();
?>
<script>
    jQuery(function ($) {
        var migrationErrorMessage = <?php echo wp_json_encode( __( 'Something went wrong with the migration and the page will now reload. Once it has reloaded, click "Resume Migration" to continue with the migration.', 'foogallery-migrate' ) ); ?>;
        var cancelConfirmMessage = <?php echo wp_json_encode( __( 'Are you sure you want to cancel?', 'foogallery-migrate' ) ); ?>;
        var selectGalleryMessage = <?php echo wp_json_encode( __( 'Please select at least one gallery to migrate.', 'foogallery-migrate' ) ); ?>;
        var imageTagSyncErrorMessage = <?php echo wp_json_encode( __( 'Something went wrong syncing image tags and the page will now reload. You can start the tag sync again after the page reloads.', 'foogallery-migrate' ) ); ?>;
        var imageTagSyncCompleteMessage = <?php echo wp_json_encode( __( 'Image tag sync complete.', 'foogallery-migrate' ) ); ?>;
        var imageTagSyncRunningMessage = <?php echo wp_json_encode( __( 'Syncing image tags...', 'foogallery-migrate' ) ); ?>;

        var $form = $('#foogallery_migrate_gallery_form');
        var $imageTagSync = $('#foogallery_migrate_image_tags');

        function updateGalleryPreflight() {
            var galleryCount = 0;
            var imageCount = 0;
            var $checkboxes = $form.find('.foogallery-migrate-gallery-select:not(:disabled)');

            $checkboxes.filter(':checked').each(function () {
                galleryCount++;
                imageCount += parseInt($(this).data('imageCount'), 10) || 0;
            });

            $form.find('.foogallery-migrate-preflight-report [data-role="gallery-count"]').text(galleryCount);
            $form.find('.foogallery-migrate-preflight-report [data-role="image-count"]').text(imageCount);
            $form.find('.foogallery-migrate-gallery-select-all').prop('checked', $checkboxes.length > 0 && $checkboxes.length === $checkboxes.filter(':checked').length);
        }

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
                success: function(data) {
                    if (window.foogalleryMigrateHandleAjaxResponse(data, migrationErrorMessage, action)) {
                        return;
                    }
                    success_callback(data);
                    updateGalleryPreflight();
                },
                error: function(xhr, ajaxOptions, thrownError) {
                    window.foogalleryMigrateHandleAjaxError(xhr, ajaxOptions, thrownError, migrationErrorMessage, action);
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

            if ($form.find('.foogallery-migrate-gallery-select:checked:not(:disabled)').length < 1) {
                window.alert(selectGalleryMessage);
                return false;
            }

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

        $form.on('change', '.foogallery-migrate-gallery-select-all', function () {
            $form.find('.foogallery-migrate-gallery-select:not(:disabled)').prop('checked', $(this).prop('checked'));
            updateGalleryPreflight();
        });

        $form.on('change', '.foogallery-migrate-gallery-select', updateGalleryPreflight);

        function updateImageTagSyncStatus(status) {
            if (!$imageTagSync.length || !status) {
                return;
            }

            var total = parseInt(status.total, 10) || 0;
            var processed = parseInt(status.processed, 10) || 0;
            var updated = parseInt(status.updated, 10) || 0;
            var skipped = parseInt(status.skipped, 10) || 0;
            var unmatched = parseInt(status.unmatched, 10) || 0;
            var errors = parseInt(status.error_count, 10) || 0;
            var progress = parseInt(status.progress, 10);
            progress = isNaN(progress) ? 0 : Math.max(0, Math.min(100, progress));

            $imageTagSync.find('[data-role="tag-sync-message"]').text(status.complete ? imageTagSyncCompleteMessage : imageTagSyncRunningMessage);
            $imageTagSync.find('[data-role="tag-sync-total"]').text(total);
            $imageTagSync.find('[data-role="tag-sync-processed"]').text(processed);
            $imageTagSync.find('[data-role="tag-sync-updated"]').text(updated);
            $imageTagSync.find('[data-role="tag-sync-skipped"]').text(skipped);
            $imageTagSync.find('[data-role="tag-sync-unmatched"]').text(unmatched);
            $imageTagSync.find('[data-role="tag-sync-errors"]').text(errors);
            $imageTagSync.find('[data-role="tag-sync-progress"]').css('width', progress + '%');

            if (status.complete) {
                $imageTagSync.find('.spinner').removeClass('is-active');
                $imageTagSync.find('.foogallery-migrate-image-tags-start').prop('disabled', false);
            }
        }

        function syncImageTags(reset) {
            if (!$imageTagSync.length) {
                return;
            }

            $imageTagSync.find('.foogallery-migrate-image-tags-start').prop('disabled', true);
            $imageTagSync.find('.spinner').addClass('is-active');

            $.ajax({
                type: "POST",
                url: ajaxurl,
                data: {
                    action: 'foogallery_migrate_image_tags',
                    foogallery_migrate_image_tags: $imageTagSync.find('input[name="foogallery_migrate_image_tags"]').val(),
                    reset: reset ? 1 : 0
                },
                success: function(response) {
                    if (window.foogalleryMigrateHandleAjaxResponse(response, imageTagSyncErrorMessage, 'foogallery_migrate_image_tags')) {
                        return;
                    }

                    updateImageTagSyncStatus(response.data);

                    if (response.data && !response.data.complete) {
                        syncImageTags(false);
                    }
                },
                error: function(xhr, ajaxOptions, thrownError) {
                    window.foogalleryMigrateHandleAjaxError(xhr, ajaxOptions, thrownError, imageTagSyncErrorMessage, 'foogallery_migrate_image_tags');
                }
            });
        }

        $imageTagSync.on('click', '.foogallery-migrate-image-tags-start', function (e) {
            e.preventDefault();
            syncImageTags(true);
        });

        updateGalleryPreflight();
    });
</script>
<form id="foogallery_migrate_gallery_form" method="POST">
    <?php $migrator->get_gallery_migrator()->render_gallery_form(); ?>
</form>
<?php
$image_tag_sync_status = $migrator->get_image_tag_sync_status();
$show_image_tag_sync = $migrator->has_migratable_image_tags() || $image_tag_sync_status['total'] > 0;
?>
<?php if ( $show_image_tag_sync ) { ?>
    <div id="foogallery_migrate_image_tags" class="notice notice-info inline">
        <?php wp_nonce_field( 'foogallery_migrate_image_tags', 'foogallery_migrate_image_tags', false ); ?>
        <p><strong><?php esc_html_e( 'Image Tags', 'foogallery-migrate' ); ?></strong></p>
        <p><?php esc_html_e( 'Sync NextGEN image tags onto images that have already been imported into the Media Library.', 'foogallery-migrate' ); ?></p>

        <?php if ( ! $migrator->is_foogallery_expert_available() ) { ?>
            <p>
                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=foogallery&page=foogallery-pricing&trial=true' ) ); ?>">
                    <?php esc_html_e( 'Start PRO Expert trial', 'foogallery-migrate' ); ?>
                </a>
            </p>
        <?php } else { ?>
            <p>
                <button type="button" class="button button-primary foogallery-migrate-image-tags-start">
                    <?php esc_html_e( 'Sync Image Tags', 'foogallery-migrate' ); ?>
                </button>
                <span class="spinner"></span>
            </p>
            <p data-role="tag-sync-message">
                <?php echo $image_tag_sync_status['complete'] ? esc_html__( 'Ready to sync image tags.', 'foogallery-migrate' ) : esc_html__( 'Image tag sync in progress.', 'foogallery-migrate' ); ?>
            </p>
            <p>
                <?php
                printf(
                    esc_html__( '%1$s of %2$s processed. Updated: %3$s. Skipped: %4$s. Unmatched: %5$s. Errors: %6$s.', 'foogallery-migrate' ),
                    '<span data-role="tag-sync-processed">' . esc_html( number_format_i18n( $image_tag_sync_status['processed'] ) ) . '</span>',
                    '<span data-role="tag-sync-total">' . esc_html( number_format_i18n( $image_tag_sync_status['total'] ) ) . '</span>',
                    '<span data-role="tag-sync-updated">' . esc_html( number_format_i18n( $image_tag_sync_status['updated'] ) ) . '</span>',
                    '<span data-role="tag-sync-skipped">' . esc_html( number_format_i18n( $image_tag_sync_status['skipped'] ) ) . '</span>',
                    '<span data-role="tag-sync-unmatched">' . esc_html( number_format_i18n( $image_tag_sync_status['unmatched'] ) ) . '</span>',
                    '<span data-role="tag-sync-errors">' . esc_html( number_format_i18n( $image_tag_sync_status['error_count'] ) ) . '</span>'
                );
                ?>
            </p>
            <div class="foogallery-migrate-progressbar" aria-hidden="true">
                <span data-role="tag-sync-progress" style="width: <?php echo esc_attr( min( 100, absint( $image_tag_sync_status['progress'] ) ) ); ?>%;"></span>
            </div>
        <?php } ?>
    </div>
<?php } ?>
