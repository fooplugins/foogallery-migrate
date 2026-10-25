<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$migrator = foogallery_migrate_migrator_instance();
?>
<script>
jQuery(function ($) {
    var $form = $('#foogallery_migrate_gallery_form');
    function updatePreflight() {
        var images = 0;
        var $boxes = $form.find('.foogallery-migrate-gallery-select:not(:disabled)');
        var $checked = $boxes.filter(':checked');
        $checked.each(function () { images += parseInt($(this).data('imageCount'), 10) || 0; });
        $form.find('[data-role="gallery-count"]').text($checked.length);
        $form.find('[data-role="image-count"]').text(images);
        $form.find('.foogallery-migrate-gallery-select-all').prop('checked', $boxes.length > 0 && $boxes.length === $checked.length);
    }
    window.foogalleryMigrateBindCollection($form, {
        title: <?php echo wp_json_encode( __( 'Gallery migration', 'foogallery-migrate' ) ); ?>,
        error: <?php echo wp_json_encode( __( 'Migration paused. Reload the page and choose Resume Migration to continue.', 'foogallery-migrate' ) ); ?>,
        selectMessage: <?php echo wp_json_encode( __( 'Please select at least one gallery to migrate.', 'foogallery-migrate' ) ); ?>,
        selection: '.foogallery-migrate-gallery-select', progress: '.migrate_progress', imageProgress: true, preflight: updatePreflight,
        startButton: '.start_migrate', continueButton: '.continue_migrate', cancelButton: '.cancel_migrate', refreshButton: '.refresh_gallery',
        start: 'foogallery_migrate', continue: 'foogallery_migrate_continue', cancel: 'foogallery_migrate_cancel', refresh: 'foogallery_migrate_refresh',
        retry: 'foogallery_migrate_retry_gallery', check: 'foogallery_migrate_check_gallery_errors'
    });
    $form.on('change', '.foogallery-migrate-gallery-select-all', function () {
        $form.find('.foogallery-migrate-gallery-select:not(:disabled)').prop('checked', $(this).prop('checked'));
        updatePreflight();
    });
    $form.on('change', '.foogallery-migrate-gallery-select', updatePreflight);
    updatePreflight();
});
</script>
<form id="foogallery_migrate_gallery_form" method="POST">
    <?php $migrator->get_gallery_migrator()->render_gallery_form(); ?>
</form>
