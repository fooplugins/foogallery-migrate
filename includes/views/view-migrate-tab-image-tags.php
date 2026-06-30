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
        var $imageTagSync = $('#foogallery_migrate_image_tags');

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
    <?php } ?>
</div>
