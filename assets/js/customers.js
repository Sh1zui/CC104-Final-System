/*
 * Wires up the Customers page (admin/customers.php, staff/customers.php): the create/edit modal, status changes
 * (verify / unverify / block / unblock), the document review list, and
 * staff-side document upload. Every change re-renders from the server's
 * response (table rows + customer detail), so markup only lives in
 * includes/customer_partial.php and rules only in the PHP.
 */
(function () {
    'use strict';

    var API = window.APP_BASE_URL + 'api/customers.php';
    var esc = AutoWay.escapeHtml;

    var modalEl = document.getElementById('customer-modal');
    var modal = new bootstrap.Modal(modalEl);
    var form = document.getElementById('customer-form');
    var errorsBox = document.getElementById('customer-form-errors');
    var csrfInput = form.querySelector('input[name="csrf_token"]');
    var docTarget = document.getElementById('document-list-target');
    var tabDocs = document.getElementById('tab-documents');
    var tabActivity = document.getElementById('tab-activity');
    var current = null; // customer row currently open, or null in create mode

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : csrfInput.value;
    }

    function post(body) {
        body.set('csrf_token', csrfToken());
        return fetch(API, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (res) {
                return res.json().then(function (data) { return { ok: res.ok, data: data }; });
            })
            .catch(function () {
                return { ok: false, data: { success: false, error: 'Could not reach the server. Check your connection and try again.' } };
            });
    }

    function postAction(fields) {
        var body = new FormData();
        Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
        return post(body);
    }

    // ------------------------------------------------------------------
    // Table + stat tiles
    // ------------------------------------------------------------------
    function refreshTable(html) {
        document.getElementById('customer-table-body').innerHTML = html;
        if (window.customerDataTable) window.customerDataTable.refresh();
        refreshStats();
    }

    function refreshStats() {
        var rows = document.querySelectorAll('#customer-table-body tr[data-status]');
        var counts = { verified: 0, unverified: 0, blocked: 0, 'pending-docs': 0 };
        Array.prototype.forEach.call(rows, function (r) {
            counts[r.getAttribute('data-status')]++;
            if (r.getAttribute('data-docs') === 'pending') counts['pending-docs']++;
        });
        Object.keys(counts).forEach(function (k) {
            var el = document.querySelector('[data-stat="' + k + '"]');
            if (el) el.textContent = counts[k];
        });
    }

    // ------------------------------------------------------------------
    // Modal modes
    // ------------------------------------------------------------------
    function showFormErrors(message) {
        errorsBox.textContent = message;
        errorsBox.classList.remove('d-none');
        errorsBox.scrollIntoView({ block: 'nearest' });
    }

    function clearFormErrors() {
        errorsBox.classList.add('d-none');
        errorsBox.textContent = '';
    }

    function setMode(mode) {
        modalEl.classList.toggle('mode-create', mode === 'create');
        modalEl.classList.toggle('mode-edit', mode === 'edit');
        form.elements['action'].value = mode === 'create' ? 'create' : 'update';
        // Documents and bookings only exist once the customer does.
        [tabDocs, tabActivity].forEach(function (t) {
            t.disabled = mode === 'create';
            t.classList.toggle('disabled', mode === 'create');
        });
    }

    function showTab(id) {
        bootstrap.Tab.getOrCreateInstance(document.getElementById(id)).show();
    }

    function openCreate() {
        current = null;
        form.reset();
        clearFormErrors();
        form.elements['customer_id'].value = '';
        setMode('create');
        document.getElementById('customer-modal-title').textContent = 'Add customer';
        document.getElementById('customer-status-line').innerHTML = '';
        document.getElementById('verification-box').classList.add('d-none');
        document.getElementById('tab-documents-count').classList.add('d-none');
        docTarget.innerHTML = '';
        document.getElementById('activity-target').innerHTML = '';
        showTab('tab-profile');
        modal.show();
    }

    function openCustomer(id, tab) {
        postAction({ action: 'get', customer_id: id }).then(function (r) {
            if (!r.data.success) { AutoWay.toast(r.data.error, 'error'); return; }
            form.reset();
            clearFormErrors();
            setMode('edit');
            applyDetail(r.data.detail);
            showTab(tab || 'tab-profile');
            modal.show();
        });
    }

    var PROFILE_FIELDS = ['full_name', 'email', 'phone', 'date_of_birth', 'address_line', 'city',
        'license_number', 'license_expiry', 'id_type', 'id_number'];

    function applyDetail(detail) {
        var c = detail.customer;
        current = c;
        form.elements['customer_id'].value = c.customer_id;
        PROFILE_FIELDS.forEach(function (key) {
            if (form.elements[key]) form.elements[key].value = c[key] == null ? '' : c[key];
        });
        document.getElementById('c-username-display').value = c.username;
        document.getElementById('customer-modal-title').textContent = c.full_name;

        renderStatusLine(c);
        renderVerificationBox(c, detail.blockers, detail.license_expired);

        docTarget.innerHTML = detail.documents_html;
        var pending = parseInt(c.pending_documents, 10) || 0;
        var badge = document.getElementById('tab-documents-count');
        badge.textContent = pending;
        badge.classList.toggle('d-none', pending === 0);

        renderActivity(detail.summary);
    }

    function renderStatusLine(c) {
        var html = '<span class="status-badge ' + AutoWay.statusClass(c.account_status) + '">' + esc(c.account_status) + '</span>';
        if (c.login_status !== 'active') {
            html += ' <span class="status-badge ' + AutoWay.statusClass(c.login_status) + '">login ' + esc(c.login_status) + '</span>';
        }
        html += ' <span class="text-secondary">&middot; registered ' + esc(AutoWay.formatDate(c.registered_at)) + '</span>';
        document.getElementById('customer-status-line').innerHTML = html;
    }

    // The status actions live in one box that also explains what's
    // missing, so nobody has to click "Verify" just to find out why not.
    function renderVerificationBox(c, blockers, licenseExpired) {
        var box = document.getElementById('verification-box');
        var html = '';
        var buttons = '';

        if (c.account_status === 'unverified') {
            if (blockers.length) {
                html = '<strong>Not ready to verify.</strong> To verify this customer: ' + esc(blockers.join('; ')) + '.';
            } else {
                html = '<strong>Ready to verify.</strong> License is on file and valid, and an ID document is approved.';
            }
            buttons += '<button type="button" class="btn btn-sm btn-brand" data-status-action="verified"' + (blockers.length ? ' disabled' : '') + '>Verify customer</button>';
            buttons += '<button type="button" class="btn btn-sm btn-outline-danger" data-status-action="blocked">Block</button>';
        } else if (c.account_status === 'verified') {
            html = '<strong>Verified.</strong> This customer can book vehicles.';
            if (licenseExpired) html += ' <span class="text-danger">Their license has since expired — update it or un-verify them.</span>';
            buttons += '<button type="button" class="btn btn-sm btn-outline-secondary" data-status-action="unverified">Mark unverified</button>';
            buttons += '<button type="button" class="btn btn-sm btn-outline-danger" data-status-action="blocked">Block</button>';
        } else {
            html = '<strong>Blocked.</strong> This customer can\'t make bookings.';
            if (window.APP_ROLE === 'admin') {
                buttons += '<button type="button" class="btn btn-sm btn-outline-secondary" data-status-action="unverified">Unblock</button>';
            } else {
                html += ' Only an admin can lift the block.';
            }
        }

        box.className = 'verification-box verification-' + c.account_status;
        box.innerHTML = '<div class="verification-text">' + html + '</div><div class="verification-actions">' + buttons + '</div>';
    }

    function renderActivity(s) {
        var html = '<div class="mini-stats">'
            + miniStat('Bookings', s.total)
            + miniStat('Open', s.open_bookings)
            + miniStat('Completed', s.completed)
            + miniStat('Cancelled / no-show', s.cancelled)
            + miniStat('Total paid', AutoWay.formatMoney(s.total_paid))
            + '</div>';

        if (!s.recent.length) {
            html += '<p class="text-secondary small mb-0">No bookings yet.</p>';
        } else {
            html += '<h3 class="form-section-title">Most recent</h3><ul class="list-unstyled small mb-0">';
            s.recent.forEach(function (b) {
                html += '<li class="mb-2"><span class="status-badge ' + AutoWay.statusClass(b.booking_status) + '">' + esc(b.booking_status) + '</span> '
                    + '<a class="fw-semibold" href="' + esc(AutoWay.link('bookings', { open: b.booking_id })) + '">'
                    + esc(b.booking_reference) + '</a> '
                    + esc(b.brand + ' ' + b.model) + ' <span class="plate">' + esc(b.plate_number) + '</span>'
                    + '<div class="text-secondary">' + esc(AutoWay.formatDate(b.pickup_datetime)) + ' &rarr; ' + esc(AutoWay.formatDate(b.return_datetime))
                    + ' &middot; <span class="mono">' + esc(AutoWay.formatMoney(b.total_amount)) + '</span></div></li>';
            });
            html += '</ul>';
        }
        document.getElementById('activity-target').innerHTML = html;
    }

    function miniStat(label, value) {
        return '<div class="mini-stat"><div class="mini-stat-label">' + esc(label) + '</div><div class="mini-stat-value mono">' + esc(value) + '</div></div>';
    }

    // One handler for every successful change: table, stats, open modal.
    function handleResult(r, opts) {
        opts = opts || {};
        if (!r.data.success) {
            if (opts.formErrors) showFormErrors(r.data.error); else AutoWay.toast(r.data.error, 'error');
            return false;
        }
        refreshTable(r.data.table_html);
        if (r.data.detail) applyDetail(r.data.detail);
        AutoWay.toast(r.data.message, 'success');
        return true;
    }

    // ------------------------------------------------------------------
    // Events
    // ------------------------------------------------------------------
    document.getElementById('add-customer-btn').addEventListener('click', openCreate);

    document.getElementById('customer-table-body').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-action="open"]');
        if (btn) openCustomer(btn.getAttribute('data-id'));
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearFormErrors();
        var submitBtn = document.getElementById('customer-form-submit');
        var creating = form.elements['action'].value === 'create';
        submitBtn.disabled = true;

        post(new FormData(form)).then(function (r) {
            submitBtn.disabled = false;
            if (!handleResult(r, { formErrors: true })) return;
            if (creating) {
                // Straight on to documents — the usual next step at the desk.
                setMode('edit');
                showTab('tab-documents');
            }
        });
    });

    document.getElementById('verification-box').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-status-action]');
        if (!btn || !current) return;
        var status = btn.getAttribute('data-status-action');
        var prompts = {
            verified: null,
            unverified: current.account_status === 'blocked'
                ? 'Unblock this customer? They\'ll be unverified and need verifying again before booking.'
                : 'Mark this customer unverified? They won\'t be able to book until verified again.',
            blocked: 'Block this customer from making bookings? Existing bookings are not cancelled.'
        };
        var ask = prompts[status]
            ? AutoWay.confirm(prompts[status], { confirmText: status === 'blocked' ? 'Block' : 'Continue', confirmClass: status === 'blocked' ? 'btn-danger' : 'btn-brand' })
            : Promise.resolve(true);

        ask.then(function (ok) {
            if (!ok) return;
            btn.disabled = true;
            postAction({ action: 'set_status', customer_id: current.customer_id, status: status }).then(function (r) {
                btn.disabled = false;
                handleResult(r);
            });
        });
    });

    // Document review: approve / reject (with reason) / delete.
    docTarget.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-doc-action]');
        if (!btn) return;
        var action = btn.getAttribute('data-doc-action');
        var id = btn.getAttribute('data-document-id');
        var rejectForm = docTarget.querySelector('[data-reject-form="' + id + '"]');

        if (action === 'approve') {
            btn.disabled = true;
            postAction({ action: 'review_document', document_id: id, decision: 'approved' }).then(function (r) {
                btn.disabled = false;
                handleResult(r);
            });
        } else if (action === 'reject') {
            rejectForm.classList.remove('d-none');
            rejectForm.querySelector('input').focus();
        } else if (action === 'reject-cancel') {
            rejectForm.classList.add('d-none');
        } else if (action === 'reject-confirm') {
            var note = rejectForm.querySelector('input').value.trim();
            if (!note) {
                AutoWay.toast('Give a reason so the customer knows what to fix.', 'error');
                rejectForm.querySelector('input').focus();
                return;
            }
            btn.disabled = true;
            postAction({ action: 'review_document', document_id: id, decision: 'rejected', note: note }).then(function (r) {
                btn.disabled = false;
                handleResult(r);
            });
        } else if (action === 'delete') {
            AutoWay.confirm('Delete this document? The file is removed permanently.', { confirmText: 'Delete', confirmClass: 'btn-danger' })
                .then(function (ok) {
                    if (!ok) return;
                    postAction({ action: 'delete_document', document_id: id }).then(function (r) { handleResult(r); });
                });
        }
    });

    // Enter in a reject-reason box submits that rejection, not the page.
    docTarget.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || !e.target.closest('[data-reject-form]')) return;
        e.preventDefault();
        var id = e.target.closest('[data-reject-form]').getAttribute('data-reject-form');
        docTarget.querySelector('[data-doc-action="reject-confirm"][data-document-id="' + id + '"]').click();
    });

    document.getElementById('doc-file').addEventListener('change', function () {
        var input = this;
        var file = input.files[0];
        if (!file || !current) return;
        var body = new FormData();
        body.append('action', 'upload_document');
        body.append('customer_id', current.customer_id);
        body.append('document_type', document.getElementById('doc-type').value);
        body.append('document', file);
        post(body).then(function (r) {
            input.value = ''; // allow choosing the same file again
            handleResult(r);
        });
    });

    window.customerDataTable = AutoWay.initDataTable(document.getElementById('customer-table'), {
        searchInput: document.getElementById('customer-search'),
        filters: [
            { el: document.getElementById('customer-filter-status'), attr: 'status' },
            { el: document.getElementById('customer-filter-docs'), attr: 'docs' },
            { el: document.getElementById('customer-filter-license'), attr: 'license' }
        ],
        pagerEl: document.getElementById('customer-pager'),
        emptyMessage: 'No customers match your search or filters.'
    });

    // Deep link from elsewhere (e.g. the dashboard): customers.php?open=12
    var openParam = new URLSearchParams(window.location.search).get('open');
    if (openParam && /^\d+$/.test(openParam)) openCustomer(openParam);
})();
