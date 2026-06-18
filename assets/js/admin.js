/* global wooImgOpt, jQuery */
(function ($) {
    'use strict';

    var state = {
        running: false,
        paused:  false
    };

    var $start     = $('#wio-start');
    var $pause     = $('#wio-pause');
    var $resume    = $('#wio-resume');
    var $fill      = $('#wio-progress-fill');
    var $progressT = $('#wio-progress-text');
    var $log       = $('#wio-log');
    var $logList   = $('#wio-log-list');

    var pollTimer = null;
    var POLL_MS   = wooImgOpt.pollInterval || 10000;

    // -----------------------------------------------------------------------
    // Bulk optimizer — enqueue then let WP-Cron drive processing
    // -----------------------------------------------------------------------

    function startBulk() {
        state.running = true;
        state.paused  = false;

        $start.prop('disabled', true);
        $pause.show();
        $log.show();

        addLog(wooImgOpt.i18n.queuing, 'info');

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
            addLog(wooImgOpt.i18n.cronNote.replace('%ds', POLL_MS / 1000), 'info');
            updateStats(d.stats);

            if ( d.stats.pending === 0 && d.stats.processing === 0 ) {
                addLog('Nothing to process.', 'info');
                stopBulk(true);
                return;
            }

            startPolling();
        })
        .fail(function (xhr) {
            addLog('Network error: ' + xhr.status, 'error');
            stopBulk();
        });
    }

    function startPolling() {
        clearInterval(pollTimer);
        pollTimer = setInterval(fetchStats, POLL_MS);
    }

    function fetchStats() {
        if ( state.paused ) return;

        $.post(wooImgOpt.ajaxUrl, {
            action: 'woo_optimizer_stats',
            nonce:  wooImgOpt.nonce
        })
        .done(function (res) {
            if ( ! res.success ) return;
            var s = res.data;
            updateStats(s);

            if ( s.pending === 0 && s.processing === 0 ) {
                addLog(wooImgOpt.i18n.done, 'info');
                stopBulk(true);
            }
        });
    }

    function stopBulk(done) {
        state.running = false;
        clearInterval(pollTimer);
        $pause.hide();
        $resume.hide();
        $start.prop('disabled', done ? true : false);
        if ( done ) {
            $start.text(wooImgOpt.i18n.done);
        }
    }

    function updateStats(stats) {
        if ( ! stats ) return;
        var done    = stats.done    || 0;
        var pending = stats.pending || 0;
        var failed  = stats.failed  || 0;

        $('#wio-stat-total').text(stats.total || 0);
        $('#wio-stat-done').text(done);
        $('#wio-stat-pending').text(pending);
        $('#wio-stat-failed').text(failed);
        $('#wio-stat-saved').text(formatBytes(stats.saved_bytes || 0));

        // Retry Failed button: show/hide and update count
        if ( failed > 0 ) {
            $('#wio-retry-count').text(failed);
            $('#wio-retry-failed').show();
        } else {
            $('#wio-retry-failed').hide();
        }

        // Progress bar
        var eligible = done + pending + failed;
        var pct      = eligible > 0 ? Math.round(done / eligible * 100) : 0;
        $fill.css('width', pct + '%');
        $progressT.text(done + ' of ' + eligible + ' optimized (' + pct + '%)');
    }

    $start.on('click', startBulk);

    $pause.on('click', function () {
        state.paused = true;
        $pause.hide();
        $resume.show();
        addLog(wooImgOpt.i18n.paused + ' (cron still runs in background)', 'info');
    });

    $resume.on('click', function () {
        state.paused = false;
        $resume.hide();
        $pause.show();
        addLog('Resumed polling.', 'info');
        fetchStats();
        startPolling();
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
    // Test connection
    // -----------------------------------------------------------------------

    $('#wio-test-connection').on('click', function () {
        var $btn    = $(this);
        var $status = $('#wio-connection-status');

        $btn.prop('disabled', true);
        $status.removeClass('wio-conn-ok wio-conn-error wio-conn-warn')
               .text(wooImgOpt.i18n.testConn.testing);

        $.post(wooImgOpt.ajaxUrl, {
            action: 'woo_optimizer_test_connection',
            nonce:  wooImgOpt.nonce
        })
        .done(function (res) {
            if ( res.success ) {
                $status.addClass('wio-conn-ok')
                       .text(wooImgOpt.i18n.testConn.ok + ' (' + res.data.ms + 'ms)');
            } else {
                var msg = (res.data && res.data.message) ? res.data.message : wooImgOpt.i18n.testConn.error;
                var ms  = (res.data && res.data.ms)      ? ' (' + res.data.ms + 'ms)' : '';
                $status.addClass('wio-conn-error').text(msg + ms);
            }
        })
        .fail(function (xhr) {
            $status.addClass('wio-conn-error')
                   .text(wooImgOpt.i18n.testConn.error + ': network ' + xhr.status);
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
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
