<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$migrator = foogallery_migrate_migrator_instance();
?>
<script>
jQuery(function ($) {
    var $form = $('#foogallery_migrate_album_form');
    function updatePreflight() {
        var galleries = 0, images = 0;
        var $boxes = $form.find('.foogallery-migrate-album-select:not(:disabled)');
        var $checked = $boxes.filter(':checked');
        $checked.each(function () {
            galleries += parseInt($(this).data('galleryCount'), 10) || 0;
            images += parseInt($(this).data('imageCount'), 10) || 0;
        });
        $form.find('[data-role="album-count"]').text($checked.length);
        $form.find('[data-role="gallery-count"]').text(galleries);
        $form.find('[data-role="image-count"]').text(images);
        $form.find('.foogallery-migrate-album-select-all').prop('checked', $boxes.length > 0 && $boxes.length === $checked.length);
    }
    window.foogalleryMigrateBindCollection($form, {
        title: <?php echo wp_json_encode( __( 'Album migration', 'foogallery-migrate' ) ); ?>,
        error: <?php echo wp_json_encode( __( 'Migration paused. Reload the page and choose Resume Migration to continue.', 'foogallery-migrate' ) ); ?>,
        selectMessage: <?php echo wp_json_encode( __( 'Please select at least one album to migrate.', 'foogallery-migrate' ) ); ?>,
        selection: '.foogallery-migrate-album-select', progress: '.album_migrate_progress', preflight: updatePreflight,
        startButton: '.start_album_migrate', continueButton: '.continue_album_migrate', cancelButton: '.cancel_album_migrate', refreshButton: '.refresh_albums',
        start: 'foogallery_album_migrate', continue: 'foogallery_album_migrate_continue', cancel: 'foogallery_album_migrate_cancel', refresh: 'foogallery_album_migrate_refresh'
    });
    $form.on('change', '.foogallery-migrate-album-select-all', function () {
        $form.find('.foogallery-migrate-album-select:not(:disabled)').prop('checked', $(this).prop('checked'));
        updatePreflight();
    });
    $form.on('change', '.foogallery-migrate-album-select', updatePreflight);
    updatePreflight();
});
</script>
<form id="foogallery_migrate_album_form" method="POST">
    <?php if ( class_exists( 'FooGalleryAlbum' ) ) {
        $migrator->get_album_migrator()->render_album_form();
    } else { ?>
        <h2><?php esc_html_e( 'Album feature not enabled!', 'foogallery-migrate' ); ?></h2>
        <p><?php esc_html_e( 'Please enable the Albums feature from FooGallery -> Features before you migrate any albums!', 'foogallery-migrate' ); ?></p>
    <?php } ?>
</form>
