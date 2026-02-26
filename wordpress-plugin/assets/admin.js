/* global aiwpcData, jQuery */
(function ($) {
    'use strict';

    // -------------------------------------------------------------------------
    // Test Connection
    // -------------------------------------------------------------------------
    var $testBtn = $('#aiwpc-test-connection');
    var $testResult = $('#aiwpc-connection-result');

    if ($testBtn.length) {
        $testBtn.on('click', function () {
            $testBtn.prop('disabled', true);
            $testResult.text('Testing…').removeClass('aiwpc-result--ok aiwpc-result--error');

            fetch(aiwpcData.restUrl + '/heartbeat', {
                method: 'GET',
                headers: {
                    'X-WP-Nonce': aiwpcData.restNonce
                }
            })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data && data.status === 'ok') {
                    $testResult.text('✓ Connected (worker: ' + (data.worker_url || 'n/a') + ')')
                               .addClass('aiwpc-result--ok');
                } else {
                    $testResult.text('✗ Unexpected response').addClass('aiwpc-result--error');
                }
            })
            .catch(function (err) {
                $testResult.text('✗ Request failed: ' + err.message).addClass('aiwpc-result--error');
            })
            .finally(function () {
                $testBtn.prop('disabled', false);
            });
        });
    }

    // -------------------------------------------------------------------------
    // Purge All
    // -------------------------------------------------------------------------
    var $purgeBtn = $('#aiwpc-purge-all');
    var $purgeResult = $('#aiwpc-purge-result');

    if ($purgeBtn.length) {
        $purgeBtn.on('click', function () {
            if (!window.confirm('Purge all cached content?')) {
                return;
            }
            $purgeBtn.prop('disabled', true);
            $purgeResult.text('Purging…').removeClass('aiwpc-result--ok aiwpc-result--error');

            fetch(aiwpcData.restUrl + '/purge', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': aiwpcData.restNonce
                },
                body: JSON.stringify({ tags: ['site'] })
            })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data && data.status === 'purged') {
                    $purgeResult.text('✓ Purged (' + (data.deleted || 0) + ' entries removed)')
                                .addClass('aiwpc-result--ok');
                } else {
                    $purgeResult.text('✗ Purge may have failed').addClass('aiwpc-result--error');
                }
            })
            .catch(function (err) {
                $purgeResult.text('✗ Request failed: ' + err.message).addClass('aiwpc-result--error');
            })
            .finally(function () {
                $purgeBtn.prop('disabled', false);
            });
        });
    }

    // -------------------------------------------------------------------------
    // Log level filter (client-side row toggle for instant UX)
    // -------------------------------------------------------------------------
    var $logFilter = $('#aiwpc-log-level-filter');
    var $logTable  = $('#aiwpc-log-table');

    if ($logFilter.length && $logTable.length) {
        $logFilter.on('change', function () {
            var level = $(this).val();
            $logTable.find('tbody tr').each(function () {
                if (level === '' || $(this).data('level') === level) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        });
    }

}(jQuery));
