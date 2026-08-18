<?php
    $migrator = foogallery_migrate_migrator_instance();
    $result = isset( $content_migration_result ) && is_array( $content_migration_result ) ? $content_migration_result : false;
    if ( is_array( $result ) ) {
        if ( $result['success'] > 0 ) {
            echo '<div class="notice notice-success inline"><p>';
            printf(
                /* translators: %d: migrated occurrence count. */
                esc_html( _n( 'Successfully migrated and replaced %d gallery occurrence.', 'Successfully migrated and replaced %d gallery occurrences.', $result['success'], 'foogallery-migrate' ) ),
                absint( $result['success'] )
            );
            echo '</p></div>';
        }
        foreach ( (array) $result['errors'] as $error ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
        }
    }
?>
<script>
    jQuery(function ($) {
        var contentErrorMessage = <?php echo wp_json_encode( __( 'Something went wrong with the content migration and the page will now reload.', 'foogallery-migrate' ) ); ?>;
        var selectItemMessage = <?php echo wp_json_encode( __( 'Please select at least one item to replace.', 'foogallery-migrate' ) ); ?>;
        var replaceConfirmMessage = <?php echo wp_json_encode( __( 'Migrate the selected galleries and replace these exact shortcode/block occurrences? This will update your post/page content.', 'foogallery-migrate' ) ); ?>;

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
<form id="foogallery_migrate_content_form" method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php $migrator->get_content_migrator()->render_content_form(); ?>
</form>
