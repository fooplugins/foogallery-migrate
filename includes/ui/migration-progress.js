/* global wp, jQuery, ajaxurl */
(function (window, document, $) {
    'use strict';
    var el = wp.element.createElement;
    var __ = function (text, domain) {
        return (window.foogalleryMigrateProgressLabels || {})[text] || wp.i18n.__(text, domain);
    };
    var host, root, state = null;

    function close() {
        var focus = state && state.returnFocus;
        state = null;
        render();
        // Let Modal release its focus lock before restoring a replaced trigger.
        window.setTimeout(function () {
            if (!focus || !document.contains(focus)) {
                focus = document.querySelector('#foogallery_migrate_gallery_form .button:not(:disabled), #foogallery_migrate_album_form .button:not(:disabled), #foogallery_migrate_content_form .button:not(:disabled), #foogallery_migrate_image_tags .button:not(:disabled)');
            }
            if (focus) { focus.focus(); }
        }, 0);
    }
    function requestStop() {
        if (!state || !state.running || state.stopping || !state.onStop) { return; }
        state.stopping = true;
        state.message = __('Stopping after the current batch…', 'foogallery-migrate');
        render();
        state.onStop();
    }
    function render() {
        if (!host) {
            host = document.createElement('div');
            document.body.appendChild(host);
            if (wp.element.createRoot) { root = wp.element.createRoot(host); }
        }
        var dialog = state ? el(wp.components.Modal, {
            title: state.title,
            className: 'foogm-progress-dialog',
            onRequestClose: close,
            isDismissible: !state.running,
            shouldCloseOnClickOutside: false,
            shouldCloseOnEsc: !state.running
        },
        el('div', { className: 'foogm-progress-content' },
            el('div', { className: 'foogm-progress-heading' },
                state.running ? el(wp.components.Spinner) : null,
                el('p', { role: 'status', 'aria-live': 'polite', 'aria-atomic': true }, state.message)),
            el('div', { className: 'foogm-progress-meter', role: 'progressbar',
                'aria-label': __('Migration progress', 'foogallery-migrate'), 'aria-valuemin': 0, 'aria-valuemax': 100,
                'aria-valuenow': state.percent === null ? undefined : state.percent },
                el('span', { style: { width: state.percent === null ? '100%' : state.percent + '%' },
                    className: state.percent === null ? 'is-indeterminate' : '' })),
            el('div', { className: 'foogm-progress-meta' }, el('span', null, state.detail),
                el('strong', null, state.percent === null ? '' : Math.floor(state.percent) + '%')),
            el('p', { className: 'description' }, state.running
                ? __('Keep this tab open while the migration runs.', 'foogallery-migrate')
                : __('Review the results in the table.', 'foogallery-migrate')),
            el('div', { className: 'foogm-progress-actions' }, state.running && !state.onStop ? null : state.running
                ? el(wp.components.Button, { variant: 'secondary', isSecondary: true,
                    disabled: state.stopping || !state.onStop, onClick: requestStop },
                    state.stopping ? __('Stopping…', 'foogallery-migrate') : __('Stop migration', 'foogallery-migrate'))
                : el(wp.components.Button, { variant: 'primary', isPrimary: true, onClick: close }, __('Done', 'foogallery-migrate')))
        )) : null;
        if (root) { root.render(dialog); } else { wp.element.render(dialog, host); }
    }
    var progress = window.foogalleryMigrateProgress = {
        begin: function (title, onStop) {
            state = { title: title, onStop: onStop, running: true, stopping: false, percent: null,
                message: __('Preparing migration…', 'foogallery-migrate'), detail: '', returnFocus: document.activeElement };
            render();
        },
        update: function (percent, message, detail) {
            if (!state) { return; }
            state.percent = percent === null ? null : Math.max(0, Math.min(100, Number(percent) || 0));
            if (!state.stopping) { state.message = message; }
            state.detail = detail || '';
            render();
        },
        finish: function (message, stopped) {
            if (!state) { return; }
            state.running = false;
            if (!stopped) { state.percent = 100; }
            state.message = message || __('Migration finished.', 'foogallery-migrate');
            render();
        },
        fail: function (message) {
            if (!state) { return; }
            state.running = false;
            state.message = message || __('Migration paused. Review the error and try again.', 'foogallery-migrate');
            render();
        },
        stopRequested: function () { return !!(state && state.stopping); }
    };

    // Gallery and album tabs share the same serial request/stop lifecycle.
    window.foogalleryMigrateBindCollection = function ($form, config) {
        var busy = false, stopping = false;
        function request(action, done) {
            if (busy) { return; }
            busy = true;
            $form.find('.button').prop('disabled', true);
            $.ajax({ type: 'POST', url: ajaxurl, data: $form.serialize() + '&action=' + action,
                success: function (data) {
                    busy = false;
                    if (window.foogalleryMigrateHandleAjaxResponse(data, config.error, action)) {
                        $form.find('.button').prop('disabled', false);
                        progress.fail(); return;
                    }
                    $form.html(data);
                    config.preflight();
                    done();
                },
                error: function (xhr, options, error) {
                    busy = false;
                    $form.find('.button').prop('disabled', false);
                    progress.fail();
                    window.foogalleryMigrateHandleAjaxError(xhr, options, error, config.error, action);
                }
            });
        }
        function cancel() {
            request(config.cancel, function () {
                progress.finish(__('Migration stopped. Completed work is kept.', 'foogallery-migrate'), true);
            });
        }
        function next() {
            var percent = Number($form.find(config.progress).val());
            var summary = $form.find('.foogm-migration-summary');
            var current = $form.find('.foogallery-migrate-progress-started').first().closest('tr');
            var title = current.find('td strong').first().text();
            var message = title ? title + ' — ' + current.find('.foogallery-migrate-progress-started').text().trim()
                : __('Processing selected items…', 'foogallery-migrate');
            var detail = wp.i18n.sprintf(__(config.imageProgress ? '%1$s of %2$s galleries finished' : '%1$s of %2$s finished', 'foogallery-migrate'),
                summary.attr('data-completed') || '0', summary.attr('data-queued') || '0');
            var displayPercent = percent;
            if (config.imageProgress && percent < 100) {
                var imageTotal = Number(summary.attr('data-image-total'));
                var imageProcessed = Number(summary.attr('data-image-processed'));
                // Between galleries, show preparation rather than briefly
                // switching this image-progress bar back to overall progress.
                displayPercent = null;
                message = __('Preparing next gallery…', 'foogallery-migrate');
                if (isFinite(imageTotal) && imageTotal > 0 && isFinite(imageProcessed) && imageProcessed >= 0) {
                    imageProcessed = Math.min(imageProcessed, imageTotal);
                    displayPercent = imageProcessed / imageTotal * 100;
                    message = summary.attr('data-image-title') || __('Current gallery', 'foogallery-migrate');
                    detail = wp.i18n.sprintf(__('%1$s of %2$s images processed', 'foogallery-migrate'), imageProcessed, imageTotal) + ' · ' + detail;
                }
            }
            progress.update(displayPercent, message, detail);
            // Only overall completion controls polling. A gallery reaching
            // 100% must not stop the remaining queued galleries.
            if (!isFinite(percent)) { progress.fail(); return; }
            if (stopping) { cancel(); return; }
            if (percent >= 100) { progress.finish(); return; }
            request(config.continue, next);
        }
        function begin(action) {
            if (busy) { return; }
            stopping = false;
            progress.begin(config.title, function () { stopping = true; if (!busy) { cancel(); } });
            request(action, next);
        }
        $form.on('click', config.startButton, function (event) {
            event.preventDefault();
            if (!$form.find(config.selection + ':checked:not(:disabled)').length) { window.alert(config.selectMessage); return; }
            begin(config.start);
        });
        $form.on('click', config.continueButton, function (event) { event.preventDefault(); begin(config.continue); });
        $form.on('click', config.cancelButton, function (event) {
            event.preventDefault(); if (!busy) { request(config.cancel, function () {}); }
        });
        $form.on('click', config.refreshButton, function (event) {
            event.preventDefault(); if (!busy) { request(config.refresh, function () {}); }
        });
        if (config.retry) {
            $form.on('click', '.retry_migrate_gallery', function (event) {
                event.preventDefault();
                $form.find('input[name="foogallery_migrate_retry_gallery_id"]').val($(this).data('galleryId'));
                begin(config.retry);
            });
            $form.on('click', '.check_migrate_gallery', function (event) {
                event.preventDefault();
                $form.find('input[name="foogallery_migrate_check_gallery_id"]').val($(this).data('galleryId'));
                request(config.check, function () {});
            });
        }
    };
})(window, document, jQuery);
