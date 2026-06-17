/* global wooImgOpt, jQuery */
(function ($) {
    'use strict';

    var state = {
        running:   false,
        paused:    false,
        processed: 0,
        saved:     0,
        errors:    0
    };

    var $start     = $('#wio-start');
    var $pause     = $('#wio-pause');
    var $resume    = $('#wio-resume');
    var $progressW = $('#wio-progress-wrap');
    var $fill      = $('#wio-progress-fill');
    var $progressT = $('#wio-progress-text');
    var $log       = $('#wio-log');
    var $logList   = $('#wio-log-list');

    // -----------------------------------------------------------------------
    // Bulk optimizer
    // -----------------------------------------------------------------------

    function startBulk() {
        state.running   = true;
        state.paused    = false;
        state.processed = 0;
        state.saved     = 0;
        state.errors    = 0;

        $start.prop('disabled', true);
        $pause.show();
        $progressW.show();
        $log.show();

        addLog(wooImgOpt.i18n.queuing, 'info');

        // Step 1: enqueue all unoptimized product images
        $.post(wooImgOpt.ajaxUrl, {
            action: 'woo_optimizer_queue_all',
            nonce:  wooImgOpt.nonce
        })
        .done(function (res) {
            if ( ! res.success ) {
                addLog('Queue error: ' + (res.data || '?'), 'error');
                stopBulk();
                return;
            }
            var d = res.data;
            addLog('Queued ' + d.queued + ' image(s). ' + d.skipped + ' already in queue.', 'info');
            updateStats(d.stats);

            if ( d.stats.pending === 0 ) {
                addLog('Nothing to process.', 'info');
                stopBulk(true);
                return;
            }

            // Step 2: process batches
            runBatch();
        })
        .fail(function (xhr) {
            addLog('Network error: ' + xhr.status, 'error');
            stopBulk();
        });
    }

    function runBatch() {
        if ( ! state.running || state.paused ) return;

        $.post(wooImgOpt.ajaxUrl, {
            action: 'woo_optimizer_batch',
            nonce:  wooImgOpt.nonce
        })
        .done(function (res) {
            if ( ! res.success ) {
                addLog('Batch error: ' + (res.data || '?'), 'error');
                stopBulk();
                return;
            }
            var d = res.data;

            state.processed += d.processed || 0;
            state.saved     += d.saved_bytes || 0;
            state.errors    += (d.errors || []).length;

            if ( d.processed > 0 ) {
                addLog('Batch done — ' + d.processed + ' optimized, saved ' + formatBytes(d.saved_bytes || 0));
            }
            $.each(d.errors || [], function (i, err) {
                addLog('Error: ' + err, 'error');
            });

            updateStats(d.stats);

            if ( d.done ) {
                addLog(wooImgOpt.i18n.done, 'info');
                addLog(
                    'Total: ' + state.processed + ' optimized | ' +
                    state.errors + ' errors | ' +
                    formatBytes(state.saved) + ' saved',
                    'info'
                );
                stopBulk(true);
                return;
            }

            // Continue
            setTimeout(runBatch, 300);
        })
        .fail(function (xhr) {
            addLog('Network error: ' + xhr.status, 'error');
            stopBulk();
        });
    }

    function stopBulk(done) {
        state.running = false;
        $pause.hide();
        $resume.hide();
        $start.prop('disabled', done ? true : false);
        if ( done ) {
            $start.text(wooImgOpt.i18n.done);
        }
    }

    function updateStats(stats) {
        if ( ! stats ) return;
        $('#wio-stat-total').text(stats.total || 0);
        $('#wio-stat-done').text(stats.done || 0);
        $('#wio-stat-pending').text(stats.pending || 0);
        $('#wio-stat-failed').text(stats.failed || 0);
        $('#wio-stat-saved').text(formatBytes(stats.saved_bytes || 0));

        var total   = (stats.done || 0) + (stats.pending || 0) + (stats.failed || 0);
        var done    = stats.done || 0;
        var pct     = total > 0 ? Math.round(done / total * 100) : 0;
        $fill.css('width', pct + '%');
        $progressT.text(done + ' / ' + total + ' (' + pct + '%)');
    }

    $start.on('click', startBulk);

    $pause.on('click', function () {
        state.paused = true;
        $pause.hide();
        $resume.show();
        addLog(wooImgOpt.i18n.paused, 'info');
    });

    $resume.on('click', function () {
        state.paused = false;
        $resume.hide();
        $pause.show();
        addLog('Resumed.', 'info');
        runBatch();
    });

    // -----------------------------------------------------------------------
    // Retry all failed jobs
    // -----------------------------------------------------------------------

    $('#wio-retry-failed').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);

        $.post(wooImgOpt.ajaxUrl, {
            action: 'woo_optimizer_retry_failed',
            nonce:  wooImgOpt.nonce
        })
        .done(function (res) {
            if ( res.success ) {
                var n = res.data.reset || 0;
                addLog('Reset ' + n + ' failed job(s) back to pending.', 'info');
                updateStats(res.data.stats);
                if ( n > 0 ) {
                    $btn.hide();
                }
            } else {
                addLog('Retry failed: ' + (res.data || '?'), 'error');
                $btn.prop('disabled', false);
            }
        })
        .fail(function (xhr) {
            addLog('Network error: ' + xhr.status, 'error');
            $btn.prop('disabled', false);
        });

        $log.show();
    });

    // -----------------------------------------------------------------------
    // Per-image restore (media library column)
    // -----------------------------------------------------------------------

    $(document).on('click', '.wio-restore-btn', function () {
        var $btn = $(this);
        var id   = $btn.data('id');

        if ( ! confirm('Restore original image from Server 2 backup? The WebP will be deleted.') ) {
            return;
        }

        $btn.prop('disabled', true).text('…');

        $.post(wooImgOpt.ajaxUrl, {
            action:        'woo_optimizer_restore',
            nonce:         wooImgOpt.nonce,
            attachment_id: id
        })
        .done(function (res) {
            if ( res.success ) {
                $btn.closest('td').html('<span class="wio-col-na">Restored</span>');
            } else {
                alert('Restore failed: ' + (res.data || 'unknown error'));
                $btn.prop('disabled', false).text('↩');
            }
        })
        .fail(function () {
            $btn.prop('disabled', false).text('↩');
        });
    });

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    function addLog(msg, type) {
        var cls = type ? 'wio-log-' + type : '';
        var $li = $('<li>').text('[' + timestamp() + '] ' + msg);
        if ( cls ) $li.addClass(cls);
        $logList.prepend($li);
    }

    function timestamp() {
        return new Date().toTimeString().slice(0, 8);
    }

    function formatBytes(bytes) {
        if ( bytes < 1024 )    return bytes + ' B';
        if ( bytes < 1048576 ) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(2) + ' MB';
    }

}(jQuery));
