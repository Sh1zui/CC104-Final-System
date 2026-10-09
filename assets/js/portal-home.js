/* Customer home: "mark read" for updates. */
(function () {
    'use strict';
    var btn = document.getElementById('mark-read');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var body = new FormData();
        body.set('action', 'notifications_read');
        body.set('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
        fetch(window.APP_BASE_URL + 'api/portal.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                if (!r.success) { AutoWay.toast(r.error || 'Could not update.', 'error'); return; }
                document.querySelectorAll('.notice-list .is-unread').forEach(function (li) { li.classList.remove('is-unread'); });
                btn.remove();
                var badge = document.querySelector('.notif-count');
                if (badge) badge.remove();
            })
            .catch(function () { AutoWay.toast('Could not reach the server.', 'error'); });
    });
})();
