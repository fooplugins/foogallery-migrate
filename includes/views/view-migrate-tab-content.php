<?php
    $migrator = foogallery_migrate_migrator_instance();
?>
<script>
    jQuery(function ($) {
        var contentErrorMessage = <?php echo wp_json_encode( __( 'The content scan stopped before completion. Previously saved results are safe; use the scan button to retry.', 'foogallery-migrate' ) ); ?>;
        <?php // translators: %d is the number of posts and pages already scanned. ?>
        var contentProgressMessage = <?php echo wp_json_encode( __( 'Scanning content: %d posts/pages checked.', 'foogallery-migrate' ) ); ?>;
        var resumeScanMessage = <?php echo wp_json_encode( __( 'Resume Scan', 'foogallery-migrate' ) ); ?>;
        var retryScanMessage = <?php echo wp_json_encode( __( 'Retry Scan', 'foogallery-migrate' ) ); ?>;
        var selectItemMessage = <?php echo wp_json_encode( __( 'Please select at least one item to replace.', 'foogallery-migrate' ) ); ?>;
        var replaceConfirmMessage = <?php echo wp_json_encode( __( 'Are you sure you want to replace the selected shortcodes/blocks? This will update your post/page content.', 'foogallery-migrate' ) ); ?>;

        var $form = $('#foogallery_migrate_content_form');
        var scanInProgress = false;

        function setBusy(isBusy) {
            if (isBusy) {
                $form.find('.button').hide();
                $('#foogallery_migrate_content_spinner .spinner').addClass('is-active');
            } else {
                $('#foogallery_migrate_content_spinner .spinner').removeClass('is-active');
                $form.find('.button').show();
            }
        }

        function foogallery_content_migration_ajax(action, success_callback) {
            var data = $form.serialize();
            setBusy(true);

            $.ajax({
                type: "POST",
                url: ajaxurl,
                data: data + "&action=" + action,
                success: success_callback,
                error: function() {
                    window.alert(contentErrorMessage);
                },
                complete: function() {
                    setBusy(false);
                }
            });
        }

        function stopContentScan(message, reset) {
            scanInProgress = false;
            setBusy(false);
            $form.find('.refresh_content').attr('data-reset', reset ? '1' : '0').text(reset ? retryScanMessage : resumeScanMessage);
            window.alert(message || contentErrorMessage);
        }

        function scanContentBatch(reset) {
            var data = $form.serialize();
            scanInProgress = true;
            setBusy(true);

            $.ajax({
                type: "POST",
                url: ajaxurl,
                dataType: "json",
                data: data + "&action=foogallery_content_refresh&reset=" + (reset ? "1" : "0"),
                success: function(response) {
                    if (!response || !response.success || !response.data || !response.data.progress) {
                        var message = response && response.data && response.data.message ? response.data.message : contentErrorMessage;
                        stopContentScan(message, reset);
                        return;
                    }

                    var progress = response.data.progress;
                    $('#foogallery_migrate_content_progress').text(contentProgressMessage.replace('%d', progress.scanned));
                    $form.find('.refresh_content').attr('data-reset', '0').text(resumeScanMessage);

                    if (progress.complete) {
                        scanInProgress = false;
                        if (typeof response.data.html === 'string') {
                            $form.html(response.data.html);
                        }
                        setBusy(false);
                        return;
                    }

                    window.setTimeout(function() {
                        scanContentBatch(false);
                    }, 50);
                },
                error: function(xhr) {
                    var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : contentErrorMessage;
                    stopContentScan(message, reset);
                },
                complete: function() {
                    if (!scanInProgress) {
                        setBusy(false);
                    }
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
            scanContentBatch($(this).attr('data-reset') === '1');
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
