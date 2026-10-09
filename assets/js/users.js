/*
 * Users and staff page (Phase 13): add a staff/admin login, edit any
 * account's contact details (and staff/admin role), suspend or reactivate,
 * set a new password. Every guard (your own account, the last admin) is
 * enforced by api/users.php; the buttons here only hide what can't work.
 */
(function () {
    'use strict';
    var API = window.APP_BASE_URL + 'api/users.php';
    var esc = AutoWay.escapeHtml;

    function post(fields) {
        var body = new FormData();
        Object.keys(fields).forEach(function (k) { if (fields[k] != null) body.append(k, fields[k]); });
        body.set('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
        return fetch(API, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .catch(function () { return { success: false, error: 'Could not reach the server. Check your connection and try again.' }; });
    }

    var table = AutoWay.initDataTable(document.getElementById('user-table'), {
        searchInput: document.getElementById('user-search'),
        filters: [{ el: document.getElementById('user-filter-role'), attr: 'role' },
                  { el: document.getElementById('user-filter-status'), attr: 'status' }],
        pagerEl: document.getElementById('user-pager'),
        emptyMessage: 'No accounts match.'
    });

    var modalEl = document.getElementById('user-modal');
    var modal = new bootstrap.Modal(modalEl);
    var errors = document.getElementById('user-errors');
    var current = null;
    var $ = function (id) { return document.getElementById(id); };

    function showError(m) { errors.textContent = m; errors.classList.remove('d-none'); }
    function clearError() { errors.textContent = ''; errors.classList.add('d-none'); }

    function setMode(user) {
        current = user;
        clearError();
        var creating = !user;
        var staffAccount = creating || user.role_name !== 'customer';
        $('user-modal-title').textContent = creating ? 'Add staff or admin' : user.full_name;
        $('user-status-line').innerHTML = creating ? '' :
            '<span class="mono text-secondary">' + esc(user.username) + '</span> '
            + '<span class="role-badge role-' + esc(user.role_name) + '">' + esc(user.role_name) + '</span> '
            + '<span class="status-badge ' + AutoWay.statusClass(user.status === 'active' ? 'active' : 'suspended') + '">' + esc(user.status) + '</span>';
        Array.prototype.forEach.call(modalEl.querySelectorAll('[data-create-only]'), function (el) { el.classList.toggle('d-none', !creating); });
        Array.prototype.forEach.call(modalEl.querySelectorAll('[data-staff-only]'), function (el) { el.classList.toggle('d-none', !staffAccount); });
        $('u-name').value = creating ? '' : user.full_name;
        $('u-email').value = creating ? '' : user.email;
        $('u-phone').value = creating ? '' : (user.phone || '');
        $('u-role').value = creating ? 'staff' : (staffAccount ? user.role_name : 'staff');
        $('u-role').disabled = !creating && user.is_self;
        $('u-username').value = '';
        $('u-password').value = '';
        $('u-submit').textContent = creating ? 'Create account' : 'Save changes';
        $('user-manage').classList.toggle('d-none', creating);

        if (!creating) {
            var access = '';
            if (user.is_self) {
                access = '<p class="small text-secondary mb-0">This is your account. Change your own password on <a href="' + esc(window.APP_BASE_URL + 'auth/account.php') + '">My account</a>; another admin can change your role or access.</p>';
            } else if (user.status === 'active') {
                access = '<button type="button" class="btn btn-outline-danger" data-status="suspended">Suspend login</button>';
            } else {
                access = '<button type="button" class="btn btn-brand" data-status="active">Reactivate login</button>';
            }
            if (user.customer_id) access += '<a class="btn btn-outline-secondary" href="' + esc(window.APP_BASE_URL + 'admin/customers.php?open=' + user.customer_id) + '">Customer profile</a>';
            $('user-access').innerHTML = access;
            $('pw-form').classList.toggle('d-none', user.is_self);
            $('u-newpw').value = '';
            $('user-meta').textContent = 'Account created ' + AutoWay.formatDate(user.created_at)
                + (user.last_login_at ? ' · last login ' + AutoWay.formatDate(user.last_login_at) : ' · never logged in');
        }
    }

    function handle(r) {
        if (!r.success) { showError(r.error); return false; }
        $('user-table-body').innerHTML = r.table_html;
        table.refresh();
        AutoWay.toast(r.message, 'success');
        if (r.user) setMode(r.user);
        return true;
    }

    $('new-user-btn').addEventListener('click', function () { setMode(null); modal.show(); });

    $('user-table-body').addEventListener('click', function (e) {
        var b = e.target.closest('[data-user]');
        if (!b) return;
        post({ action: 'get', user_id: b.getAttribute('data-user') }).then(function (r) {
            if (!r.success) { AutoWay.toast(r.error, 'error'); return; }
            setMode(r.user);
            modal.show();
        });
    });

    $('user-form').addEventListener('submit', function (e) {
        e.preventDefault();
        clearError();
        var f = { full_name: $('u-name').value.trim(), email: $('u-email').value.trim(), phone: $('u-phone').value.trim(), role: $('u-role').value };
        if (current) { f.action = 'update'; f.user_id = current.user_id; }
        else { f.action = 'create'; f.username = $('u-username').value.trim(); f.password = $('u-password').value; }
        var btn = $('u-submit');
        btn.disabled = true;
        post(f).then(function (r) { btn.disabled = false; handle(r); });
    });

    $('user-access').addEventListener('click', function (e) {
        var b = e.target.closest('[data-status]');
        if (!b || !current) return;
        var status = b.getAttribute('data-status');
        var ask = status === 'active'
            ? Promise.resolve(true)
            : AutoWay.confirm('Suspend ' + current.full_name + '\'s login? They\'re signed out on their next click and can\'t log in until reactivated.',
                               { confirmText: 'Suspend', confirmClass: 'btn-danger' });
        ask.then(function (yes) {
            if (yes) post({ action: 'set_status', user_id: current.user_id, status: status }).then(handle);
        });
    });

    $('pw-form').addEventListener('submit', function (e) {
        e.preventDefault();
        clearError();
        post({ action: 'reset_password', user_id: current.user_id, password: $('u-newpw').value }).then(function (r) {
            if (handle(r)) $('u-newpw').value = '';
        });
    });
})();
