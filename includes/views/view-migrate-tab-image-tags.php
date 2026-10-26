<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$migrator = foogallery_migrate_migrator_instance();
$image_tag_sync_status = $migrator->get_image_tag_sync_status();
$has_migratable_image_tags = $migrator->has_migratable_image_tags();
$show_image_tag_sync = $has_migratable_image_tags || $image_tag_sync_status['total'] > 0;
?>
<script>
    jQuery(function ($) {
        var imageTagSyncErrorMessage = <?php echo wp_json_encode( __( 'Something went wrong syncing image tags and the page will now reload. You can start the tag sync again after the page reloads.', 'foogallery-migrate' ) ); ?>;
        var imageTagSyncCompleteMessage = <?php echo wp_json_encode( __( 'Image tag sync complete.', 'foogallery-migrate' ) ); ?>;
        var imageTagSyncRunningMessage = <?php echo wp_json_encode( __( 'Syncing image tags...', 'foogallery-migrate' ) ); ?>;
        var imageTagSyncFailedMessage = <?php echo wp_json_encode( __( 'Some image tags could not be synced. Review the results below before trying again.', 'foogallery-migrate' ) ); ?>;
        var $imageTagSync = $('#foogallery_migrate_image_tags');
        var dialog = window.foogalleryMigrateProgress;
        var tagSyncReset = <?php echo $image_tag_sync_status['complete'] ? 'true' : 'false'; ?>;
        var syncInProgress = false;

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
            dialog.update(progress, status.complete ? imageTagSyncCompleteMessage : imageTagSyncRunningMessage,
                <?php /* translators: 1: processed image count, 2: total image count. */ echo wp_json_encode( __( '%1$s of %2$s images processed', 'foogallery-migrate' ) ); ?>.replace('%1$s', processed).replace('%2$s', total));

            $imageTagSync.find('[data-role="tag-sync-message"]').text(status.complete ? imageTagSyncCompleteMessage : imageTagSyncRunningMessage);
            $imageTagSync.find('[data-role="tag-sync-total"]').text(total);
            $imageTagSync.find('[data-role="tag-sync-processed"]').text(processed);
            $imageTagSync.find('[data-role="tag-sync-updated"]').text(updated);
            $imageTagSync.find('[data-role="tag-sync-skipped"]').text(skipped);
            $imageTagSync.find('[data-role="tag-sync-unmatched"]').text(unmatched);
            $imageTagSync.find('[data-role="tag-sync-errors"]').text(errors);

            if (status.complete) {
                syncInProgress = false;
                tagSyncReset = true;
                if (errors > 0) { dialog.fail(imageTagSyncFailedMessage); }
                else { dialog.finish(imageTagSyncCompleteMessage); }
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
                        syncInProgress = false;
                        dialog.fail();
                        $imageTagSync.find('.foogallery-migrate-image-tags-start').prop('disabled', false);
                        return;
                    }

                    if (!response || response.success !== true || !response.data || typeof response.data.complete !== 'boolean') {
                        syncInProgress = false; dialog.fail();
                        $imageTagSync.find('.foogallery-migrate-image-tags-start').prop('disabled', false);
                        return;
                    }
                    updateImageTagSyncStatus(response.data);

                    if (response.data && !response.data.complete) {
                        tagSyncReset = false;
                        if (dialog.stopRequested()) {
                            syncInProgress = false;
                            dialog.finish(<?php echo wp_json_encode( __( 'Migration stopped. Completed work is kept.', 'foogallery-migrate' ) ); ?>, true);
                            $imageTagSync.find('.spinner').removeClass('is-active');
                            $imageTagSync.find('.foogallery-migrate-image-tags-start').prop('disabled', false).text(<?php echo wp_json_encode( __( 'Resume Image Tags', 'foogallery-migrate' ) ); ?>);
                        } else { syncImageTags(false); }
                    }
                },
                error: function(xhr, ajaxOptions, thrownError) {
                    syncInProgress = false; dialog.fail();
                    $imageTagSync.find('.foogallery-migrate-image-tags-start').prop('disabled', false);
                    window.foogalleryMigrateHandleAjaxError(xhr, ajaxOptions, thrownError, imageTagSyncErrorMessage, 'foogallery_migrate_image_tags');
                }
            });
        }

        $imageTagSync.on('click', '.foogallery-migrate-image-tags-start', function (e) {
            e.preventDefault();
            if (syncInProgress) { return; }
            syncInProgress = true;
            dialog.begin(<?php echo wp_json_encode( __( 'Image Tags migration', 'foogallery-migrate' ) ); ?>, function () {});
            syncImageTags(tagSyncReset);
        });
    });
</script>
<div id="foogallery_migrate_image_tags">
    <p><strong><?php esc_html_e( 'Image Tags', 'foogallery-migrate' ); ?></strong></p>
    <p><?php esc_html_e( 'Sync NextGEN image tags onto images that have already been imported into the Media Library.', 'foogallery-migrate' ); ?></p>

    <?php if ( ! $show_image_tag_sync ) { ?>
        <p><?php esc_html_e( 'No NextGEN image tags have been detected yet.', 'foogallery-migrate' ); ?></p>
    <?php } else { ?>
        <?php wp_nonce_field( 'foogallery_migrate_image_tags', 'foogallery_migrate_image_tags', false ); ?>

        <?php if ( ! $migrator->is_foogallery_expert_available() ) { ?>
            <div class="notice notice-warning inline">
                <p><?php esc_html_e( 'FooGallery Migrate found tagged NextGEN images. Migrating those tags into FooGallery media tags for tag-based dynamic galleries requires FooGallery PRO Expert.', 'foogallery-migrate' ); ?></p>
                <p>
                    <a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=foogallery&page=foogallery-pricing&trial=true' ) ); ?>">
                        <?php esc_html_e( 'Start PRO Expert trial', 'foogallery-migrate' ); ?>
                    </a>
                </p>
            </div>
        <?php } else { ?>
            <p>
                <button type="button" class="button button-primary foogallery-migrate-image-tags-start">
                    <?php echo $image_tag_sync_status['complete'] ? esc_html__( 'Sync Image Tags', 'foogallery-migrate' ) : esc_html__( 'Resume Image Tags', 'foogallery-migrate' ); ?>
                </button>
            </p>
            <p data-role="tag-sync-message">
                <?php echo $image_tag_sync_status['complete'] ? esc_html__( 'Ready to sync image tags.', 'foogallery-migrate' ) : esc_html__( 'Image tag sync in progress.', 'foogallery-migrate' ); ?>
            </p>
            <p>
                <?php
                printf(
                    /* translators: 1: processed count, 2: total count, 3: updated count, 4: skipped count, 5: unmatched count, 6: error count. */
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
        <?php } ?>
    <?php } ?>
</div>
