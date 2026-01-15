<?php
    $migrator = foogallery_migrate_migrator_instance();
?>
<script>
    jQuery(function ($) {
        var contentErrorMessage = <?php echo wp_json_encode( __( 'Something went wrong with the content migration and the page will now reload.', 'foogallery-migrate' ) ); ?>;
        var selectItemMessage = <?php echo wp_json_encode( __( 'Please select at least one item to replace.', 'foogallery-migrate' ) ); ?>;
        var replaceConfirmMessage = <?php echo wp_json_encode( __( 'Are you sure you want to replace the selected shortcodes/blocks? This will update your post/page content.', 'foogallery-migrate' ) ); ?>;

        var $form = $('#foogallery_migrate_content_form');

        function foogallery_content_migration_ajax(action, success_callback) {
            var data = $form.serialize();

            $form.find('.button').hide();

            $('#foogallery_migrate_content_spinner .spinner').addClass('is-active');

            $.ajax({
                type: "POST",
                url: ajaxurl,
                data: data + "&action=" + action,
                success: success_callback,
                error: function(xhr, ajaxOptions, thrownError) {
                    window.alert(contentErrorMessage);
                    location.reload();
                },
                complete: function() {
                    $('#foogallery_migrate_content_spinner .spinner').removeClass('is-active');
                    $form.find('.button').show();
                }
            });
        }

        $form.on('click', '.replace_content', function (e) {
            e.preventDefault();

            var checked = $form.find('input[name="content-item[]"]:checked').length;
            if (checked === 0) {
                window.alert(selectItemMessage);
                return false;
            }

            if (!window.confirm(replaceConfirmMessage)) {
                return false;
            }

            foogallery_content_migration_ajax( 'foogallery_content_replace', function (data) {
                $form.html(data);
            });
        });

        $form.on('click', '.refresh_content', function (e) {
            e.preventDefault();

            foogallery_content_migration_ajax( 'foogallery_content_refresh', function (data) {
                $form.html(data);
            });
        });

        $(document).on('change', '#foogallery_migrate_content_form #cb-select-all-content', function() {
            var checked = $(this).is(':checked');
            $('#foogallery_migrate_content_form').find('input[name="content-item[]"]:not(:disabled)').prop('checked', checked);
        });

        $(document).on('change', '#foogallery_migrate_content_form input[name="content-item[]"]', function() {
            var $form = $('#foogallery_migrate_content_form');
            var total = $form.find('input[name="content-item[]"]:not(:disabled)').length;
            var checked = $form.find('input[name="content-item[]"]:not(:disabled):checked').length;
            $form.find('#cb-select-all-content').prop('checked', total > 0 && total === checked);
        });
    });
</script>
<form id="foogallery_migrate_content_form" method="POST">
    <?php $migrator->get_content_migrator()->render_content_form(); ?>
</form>
