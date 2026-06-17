/* global bdskOptimizer, jQuery */
(function ($) {
    'use strict';

    var state = {
        running:   false,
        paused:    false,
        processed: 0,
        saved:     0,
        errors:    0,
        total:     parseInt($('#bdsk-progress-text').text(), 10) || 0
    };

    var $start      = $('#bdsk-start');
    var $pause      = $('#bdsk-pause');
    var $resume     = $('#bdsk-resume');
    var $progressW  = $('#bdsk-progress-wrap');
    var $fill       = $('#bdsk-progress-fill');
    var $progressT  = $('#bdsk-progress-text');
    var $log        = $('#bdsk-log');
    var $logList    = $('#bdsk-log-list');

    // -----------------------------------------------------------------------
    // Bulk optimizer
    // -----------------------------------------------------------------------

    function startBulk() {
        state.running = true;
        state.paused  = false;

        $start.hide();
        $pause.show();
        $progressW.show();
        $log.show();

        addLog('Starting bulk optimization…', 'info');
        runBatch();
    }

    function runBatch() {
        if ( ! state.running || state.paused ) return;

        $.post(bdskOptimizer.ajaxUrl, {
            action:     'bdsk_optimizer_bulk',
            nonce:      bdskOptimizer.nonce,
            batch_size: bdskOptimizer.batchSize
        })
        .done(function (res) {
            if ( ! res.success ) {
                addLog('Server error: ' + (res.data || '?'), 'error');
                stopBulk();
                return;
            }

            var data = res.data;

            if ( data.done ) {
                addLog('All images optimized!', 'info');
                stopBulk(true);
                return;
            }

            // Log processed
            $.each(data.processed || [], function (i, item) {
                state.processed++;
                state.saved += item.saved_bytes || 0;
                addLog('[' + item.id + '] ' + (item.title || 'Image') + ' → saved ' + item.saved_human);
            });

            // Log errors
            $.each(data.errors || [], function (i, err) {
                state.errors++;
                addLog('[' + err.id + '] Error: ' + err.error, 'error');
            });

            // Update progress using live stats from server
            if ( data.stats ) {
                var s = data.stats;
                var pct = s.total > 0 ? Math.round(s.optimized / s.total * 100) : 0;
                $fill.css('width', pct + '%');
                $progressT.text(s.optimized + ' / ' + s.total + ' (' + pct + '% — saved ' + s.saved_human + ')');
            }

            // Continue next batch (small delay to avoid hammering server)
            setTimeout(runBatch, 200);
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

        if ( done ) {
            $start.text('All Done!').prop('disabled', true);
            addLog(
                'Finished. Processed: ' + state.processed +
                ' | Errors: ' + state.errors +
                ' | Saved: ' + formatBytes(state.saved),
                'info'
            );
        } else {
            $start.show();
        }
    }

    $start.on('click', startBulk);

    $pause.on('click', function () {
        state.paused = true;
        $pause.hide();
        $resume.show();
        addLog('Paused.', 'info');
    });

    $resume.on('click', function () {
        state.paused = false;
        $resume.hide();
        $pause.show();
        addLog('Resumed.', 'info');
        runBatch();
    });

    // -----------------------------------------------------------------------
    // Single image (media library)
    // -----------------------------------------------------------------------

    $(document).on('click', '.bdsk-optimize-single', function () {
        var $btn = $(this);
        var id   = $btn.data('id');

        $btn.prop('disabled', true).text('…');

        $.post(bdskOptimizer.ajaxUrl, {
            action:        'bdsk_optimizer_single',
            nonce:         bdskOptimizer.nonce,
            attachment_id: id
        })
        .done(function (res) {
            if ( res.success ) {
                $btn.replaceWith('<span class="bdsk-col-done">✓ ' + res.data.saved_human + ' saved</span>');
            } else {
                $btn.prop('disabled', false).text('Retry');
                alert('Error: ' + (res.data || 'unknown'));
            }
        })
        .fail(function () {
            $btn.prop('disabled', false).text('Retry');
        });
    });

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    function addLog(msg, type) {
        var cls = type ? 'bdsk-log-' + type : '';
        var $li = $('<li>').text('[' + timestamp() + '] ' + msg);
        if ( cls ) $li.addClass(cls);
        $logList.prepend($li);
    }

    function timestamp() {
        var d = new Date();
        return d.toTimeString().slice(0, 8);
    }

    function formatBytes(bytes) {
        if ( bytes < 1024 )        return bytes + ' B';
        if ( bytes < 1048576 )     return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(2) + ' MB';
    }

}(jQuery));
