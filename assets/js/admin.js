/* global wooImgOpt, jQuery */
(function ($) {
    'use strict';

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
        .fail(function (xhr) {
            var msg = xhr.status === 0
                ? 'Request timed out. The restore may still complete — refresh the page in 30 seconds to check.'
                : 'Network error ' + xhr.status + '. Please try again.';
            alert(msg);
            $btn.prop('disabled', false).text('↩');
        });
    });

}(jQuery));
