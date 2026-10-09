/*
 * Customer portal (Phase 11): the browse page (search, book) and My bookings
 * (view, send a payment, cancel). Every rule — availability, price, fees,
 * what can be paid — comes from api/portal.php; this file asks and shows.
 */
(function () {
    'use strict';

    var API = window.APP_BASE_URL + 'api/portal.php';
    var PRINT = window.APP_BASE_URL + 'print/';
    var esc = AutoWay.escapeHtml;
    var money = AutoWay.formatMoney;

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function post(fields) {
        var body = new FormData();
        Object.keys(fields).forEach(function (k) {
            if (fields[k] !== undefined && fields[k] !== null) body.append(k, fields[k]);
        });
        body.set('csrf_token', csrfToken());
        return fetch(API, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .catch(function () { return { success: false, error: 'Could not reach the server. Check your connection and try again.' }; });
    }

    function fmtDateTime(s) {
        if (!s) return '—';
        var d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d) ? s : d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
            + ', ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    }

    function humanize(s) {
        var labels = { no_show: 'Not picked up', rental_fee: 'Rental', bank_transfer: 'Bank transfer', gcash: 'GCash' };
        s = String(s || '');
        return labels[s] || (s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' '));
    }

    function quoteRows(q) {
        var rows = '<div class="quote-row"><span>' + esc(money(q.daily_rate)) + ' &times; ' + q.rental_days + ' day' + (q.rental_days === 1 ? '' : 's') + '</span>'
            + '<span class="mono">' + esc(money(q.base_amount)) + '</span></div>';
        if (q.long_rental_discount > 0) rows += '<div class="quote-row text-success"><span>Long-rental discount</span><span class="mono">− ' + esc(money(q.long_rental_discount)) + '</span></div>';
        if (q.manual_discount > 0) rows += '<div class="quote-row text-success"><span>Discount</span><span class="mono">− ' + esc(money(q.manual_discount)) + '</span></div>';
        rows += '<div class="quote-row"><span>Refundable deposit</span><span class="mono">' + esc(money(q.deposit_amount)) + '</span></div>'
            + '<div class="quote-row quote-total"><span>Total</span><span class="mono">' + esc(money(q.total_amount)) + '</span></div>';
        return rows;
    }

    // ==================================================================
    // Browse
    // ==================================================================
    var searchForm = document.getElementById('car-search');
    if (searchForm) {
        var grid = document.getElementById('car-grid');
        var line = document.getElementById('results-line');
        var blockersBox = document.getElementById('book-blockers');
        var window_ = null;

        var pickupInput = document.getElementById('s-pickup');
        var returnInput = document.getElementById('s-return');
        pickupInput.addEventListener('change', function () {
            if (!pickupInput.value) return;
            returnInput.min = pickupInput.value;
            if (!returnInput.value || returnInput.value <= pickupInput.value) {
                // Default to a one-day rental at the same time of day.
                var d = new Date(pickupInput.value);
                d.setDate(d.getDate() + 1);
                var pad = function (n) { return String(n).padStart(2, '0'); };
                returnInput.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
            }
        });

        var search = function () {
            var fields = {
                action: 'search',
                pickup: pickupInput.value,
                return: returnInput.value,
                type: document.getElementById('s-type').value,
                transmission: document.getElementById('s-transmission').value,
                min_seats: document.getElementById('s-seats').value
            };
            if ((fields.pickup && !fields.return) || (!fields.pickup && fields.return)) {
                AutoWay.toast('Pick both a pickup and a return time.', 'error');
                return;
            }
            grid.setAttribute('aria-busy', 'true');
            post(fields).then(function (r) {
                grid.removeAttribute('aria-busy');
                if (!r.success) { AutoWay.toast(r.error, 'error'); return; }
                grid.innerHTML = r.cards_html;
                if (fields.pickup) {
                    window_ = { pickup: fields.pickup, return: fields.return };
                    line.textContent = r.count + (r.count === 1 ? ' car is' : ' cars are') + ' free for ' + r.rental_days + ' day' + (r.rental_days === 1 ? '' : 's')
                        + ', ' + fmtDateTime(fields.pickup) + ' to ' + fmtDateTime(fields.return) + '.';
                    if (r.blockers && r.blockers.length) {
                        blockersBox.innerHTML = '<strong>You can look around, but you can\'t book yet.</strong> ' + esc(r.blockers.join(' '));
                        blockersBox.classList.remove('d-none');
                    } else {
                        blockersBox.classList.add('d-none');
                    }
                } else {
                    window_ = null;
                    line.textContent = r.count + ' cars match. Pick dates to see what\'s free and the total price.';
                }
            });
        };

        searchForm.addEventListener('submit', function (e) { e.preventDefault(); search(); });

        // Arriving with a search in the link (from the public browse page,
        // after logging in): fill it in and run it.
        var params = new URLSearchParams(window.location.search);
        var fromLink = false;
        [['pickup', pickupInput], ['return', returnInput], ['type', document.getElementById('s-type')],
         ['transmission', document.getElementById('s-transmission')], ['min_seats', document.getElementById('s-seats')]].forEach(function (pair) {
            var v = params.get(pair[0]);
            if (v) { pair[1].value = v; fromLink = true; }
        });
        if (fromLink) search();
        ['s-type', 's-transmission', 's-seats'].forEach(function (id) {
            document.getElementById(id).addEventListener('change', search);
        });

        var bookModalEl = document.getElementById('book-modal');
        var bookModal = new bootstrap.Modal(bookModalEl);
        var booking = null;

        grid.addEventListener('click', function (e) {
            if (e.target.closest('[data-pick-dates]')) {
                pickupInput.focus();
                if (pickupInput.showPicker) { try { pickupInput.showPicker(); } catch (err) { /* not allowed everywhere */ } }
                return;
            }
            var btn = e.target.closest('[data-book]');
            if (!btn || !window_) return;
            var q = JSON.parse(btn.getAttribute('data-quote'));
            booking = { vehicle_id: btn.getAttribute('data-book'), pickup: window_.pickup, return: window_.return };
            document.getElementById('book-modal-title').textContent = btn.getAttribute('data-name');
            document.getElementById('book-when').innerHTML = '<strong>' + esc(fmtDateTime(window_.pickup)) + '</strong> to <strong>' + esc(fmtDateTime(window_.return)) + '</strong>';
            document.getElementById('book-quote').innerHTML = quoteRows(q);
            document.getElementById('book-notes').value = '';
            bookModal.show();
        });

        document.getElementById('book-form').addEventListener('submit', function (e) {
            e.preventDefault();
            if (!booking) return;
            var btn = document.getElementById('book-submit');
            btn.disabled = true;
            post({ action: 'book', vehicle_id: booking.vehicle_id, pickup: booking.pickup, return: booking.return,
                   notes: document.getElementById('book-notes').value })
                .then(function (r) {
                    btn.disabled = false;
                    if (!r.success) { AutoWay.toast(r.error, 'error'); return; }
                    bookModal.hide();
                    window.location.href = window.APP_BASE_URL + 'customer/my-bookings.php?open=' + encodeURIComponent(r.booking_id) + '&new=1';
                });
        });

        // Arriving with dates (e.g. from the dashboard): search straight away.
        var qs = new URLSearchParams(window.location.search);
        if (qs.get('pickup') && qs.get('return')) {
            pickupInput.value = qs.get('pickup');
            returnInput.value = qs.get('return');
            search();
        }
    }

    // ==================================================================
    // My bookings
    // ==================================================================
    var obModalEl = document.getElementById('ob-modal');
    if (obModalEl) {
        var obModal = new bootstrap.Modal(obModalEl);
        var current = null;
        var errors = document.getElementById('ob-errors');

        var table = window.mbDataTable = AutoWay.initDataTable(document.getElementById('mb-table'), {
            filters: [{ el: document.getElementById('mb-filter-when'), attr: 'when' }],
            pagerEl: document.getElementById('mb-pager'),
            emptyMessage: 'No bookings here.'
        });

        var showError = function (msg) { errors.textContent = msg; errors.classList.remove('d-none'); errors.scrollIntoView({ block: 'nearest' }); };
        var clearError = function () { errors.textContent = ''; errors.classList.add('d-none'); };

        var PANELS = ['ob-pay-panel', 'ob-cancel-panel'];
        var closePanels = function () {
            PANELS.forEach(function (id) { document.getElementById(id).classList.add('d-none'); });
            document.getElementById('ob-actions').classList.remove('d-none');
        };
        var openPanel = function (id) {
            closePanels();
            clearError();
            document.getElementById('ob-actions').classList.add('d-none');
            var p = document.getElementById(id);
            p.classList.remove('d-none');
            p.scrollIntoView({ block: 'nearest' });
        };

        var block = function (label, html) {
            return '<div><div class="detail-label">' + esc(label) + '</div><div>' + html + '</div></div>';
        };

        var show = function (v) {
            current = v;
            var b = v.booking;
            closePanels();
            clearError();
            document.getElementById('ob-title').textContent = b.brand + ' ' + b.model + ' ' + b.year;
            document.getElementById('ob-status').innerHTML = '<span class="mono text-secondary">' + esc(b.booking_reference) + '</span> '
                + '<span class="status-badge ' + AutoWay.statusClass(b.booking_status) + '">' + esc(humanize(b.booking_status)) + '</span> '
                + '<span class="status-badge ' + AutoWay.statusClass(b.payment_status === 'pending' ? 'unpaid' : b.payment_status) + '">'
                + esc(b.payment_status === 'pending' ? 'unpaid' : b.payment_status) + '</span>';

            var steps = {
                pending: 'We\'ve got your request. The desk will confirm it and you\'ll get a notification.',
                confirmed: 'Confirmed. Bring your driver\'s license to the desk at pickup.'
                    + (v.free_cancel_until ? ' Free cancellation until ' + fmtDateTime(v.free_cancel_until) + '.' : ''),
                active: new Date(String(b.return_datetime).replace(' ', 'T')) < new Date()
                    ? 'This rental is overdue — it was due back ' + fmtDateTime(b.return_datetime) + '. Please return the car or call the desk; late fees apply.'
                    : 'The car is with you. Please return it by ' + fmtDateTime(b.return_datetime) + '.',
                completed: 'Returned' + (v.returned_at ? ' ' + fmtDateTime(v.returned_at) : '') + '. Thanks for renting with us.',
                cancelled: 'Cancelled' + (b.cancelled_by_customer ? ' by you' : ' by the rental desk') + (b.cancellation_reason ? ': ' + b.cancellation_reason : '') + '.',
                no_show: 'The car wasn\'t picked up.'
            };

            var html = '<p class="lead-note">' + esc(steps[b.booking_status] || '') + '</p>'
                + '<div class="detail-grid">'
                + block('Pickup', esc(fmtDateTime(b.pickup_datetime)))
                + block('Return', esc(fmtDateTime(b.return_datetime)))
                + '</div>';

            var q = { daily_rate: b.daily_rate_snapshot, rental_days: b.rental_days, base_amount: b.base_amount,
                      long_rental_discount: v.long_rental_discount, manual_discount: v.manual_discount,
                      deposit_amount: b.deposit_amount, total_amount: b.total_amount };
            html += '<h3 class="form-section-title">Price</h3><div class="quote-box">' + quoteRows(q);
            if (b.extra_charges > 0) html += '<div class="quote-row"><span>Charges at return</span><span class="mono">' + esc(money(b.extra_charges)) + '</span></div>';
            if (b.cancellation_fee > 0) html += '<div class="quote-row"><span>Cancellation fee</span><span class="mono">' + esc(money(b.cancellation_fee)) + '</span></div>';
            html += '<div class="quote-row quote-divider"><span>Paid so far</span><span class="mono">' + esc(money(b.amount_paid)) + '</span></div>';
            if (b.balance_due > 0) html += '<div class="quote-row fw-semibold"><span>Still to pay</span><span class="mono">' + esc(money(b.balance_due)) + '</span></div>';
            if (b.refund_due > 0) html += '<div class="quote-row fw-semibold text-success"><span>We owe you</span><span class="mono">' + esc(money(b.refund_due)) + '</span></div>';
            html += '</div>';

            if (v.submissions.length) {
                html += '<h3 class="form-section-title">Payments you sent</h3><ul class="payment-list">';
                v.submissions.forEach(function (s) {
                    var label = { submitted: 'being checked', accepted: 'received', rejected: 'not confirmed', withdrawn: 'withdrawn' }[s.status];
                    var cls = { submitted: 'status-warning', accepted: 'status-success', rejected: 'status-danger', withdrawn: 'status-neutral' }[s.status];
                    html += '<li class="payment-row"><div class="payment-main"><span class="status-badge ' + cls + '">' + esc(label) + '</span> '
                        + esc(s.method_label) + ' ref <span class="mono">' + esc(s.transaction_ref) + '</span>'
                        + '<div class="cell-sub">For ' + esc(s.type_label.toLowerCase()) + ' &middot; sent ' + esc(AutoWay.formatDate(s.paid_on))
                        + (s.receipt_number ? ' &middot; receipt ' + esc(s.receipt_number) : '')
                        + (s.review_note ? '<br><span class="text-danger">' + esc(s.review_note) + '</span>' : '') + '</div></div>'
                        + '<div class="payment-amount mono">' + esc(money(s.amount)) + '</div>'
                        + (s.status === 'submitted' ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-withdraw="' + esc(s.submission_id) + '">Withdraw</button>' : '<span></span>')
                        + '</li>';
                });
                html += '</ul>';
            }

            html += '<h3 class="form-section-title">Receipts</h3>';
            var receipts = v.payments.filter(function (p) { return p.payment_type !== 'deposit_applied'; });
            if (!receipts.length) {
                html += '<p class="text-secondary small mb-0">No payments recorded yet.</p>';
            } else {
                html += '<ul class="payment-list">';
                receipts.forEach(function (p) {
                    var out = p.payment_type === 'refund';
                    html += '<li class="payment-row' + (p.status !== 'completed' ? ' is-void' : '') + '"><div class="payment-main">'
                        + '<a href="' + esc(PRINT + 'receipt.php?id=' + encodeURIComponent(p.payment_id)) + '" target="_blank" rel="noopener">' + esc(p.receipt_number) + '</a> '
                        + esc(p.label) + '<div class="cell-sub">' + esc(fmtDateTime(p.paid_at)) + (p.payment_method ? ' &middot; ' + esc(humanize(p.payment_method)) : '')
                        + (p.status !== 'completed' ? ' &middot; void' : '') + '</div></div>'
                        + '<div class="payment-amount mono">' + (out ? '− ' : '') + esc(money(p.amount)) + '</div><span></span></li>';
                });
                html += '</ul>';
            }
            if (v.invoice) {
                html += '<p class="mt-2 mb-0"><a href="' + esc(PRINT + 'invoice.php?id=' + encodeURIComponent(v.invoice.invoice_id)) + '" target="_blank" rel="noopener">'
                    + '<i class="bi bi-file-earmark-text me-1"></i>Invoice ' + esc(v.invoice.invoice_number) + '</a></p>';
            }
            if (b.notes) html += '<h3 class="form-section-title">Your note</h3><p class="mb-0">' + esc(b.notes) + '</p>';

            document.getElementById('ob-detail').innerHTML = html;

            var actions = '';
            if (v.can_pay) actions += '<button type="button" class="btn btn-brand" data-ob-action="pay"><i class="bi bi-phone me-1"></i>I sent a payment</button>';
            if (v.can_cancel) actions += '<button type="button" class="btn btn-outline-danger" data-ob-action="cancel">Cancel booking</button>';
            var box = document.getElementById('ob-actions');
            box.innerHTML = actions;
            box.classList.toggle('d-none', !actions);
        };

        var open = function (id) {
            return post({ action: 'get', booking_id: id }).then(function (r) {
                if (!r.success) { AutoWay.toast(r.error, 'error'); return; }
                show(r.view);
                obModal.show();
            });
        };

        var handle = function (r) {
            if (!r.success) { showError(r.error); return false; }
            document.getElementById('mb-table-body').innerHTML = r.table_html;
            table.refresh();
            AutoWay.toast(r.message, 'success');
            if (r.view) show(r.view);
            return true;
        };

        document.getElementById('mb-table-body').addEventListener('click', function (e) {
            var btn = e.target.closest('[data-open-booking]');
            if (btn) open(btn.getAttribute('data-open-booking'));
        });

        obModalEl.addEventListener('click', function (e) {
            if (e.target.closest('[data-close-panel]')) { closePanels(); return; }
            var w = e.target.closest('[data-withdraw]');
            if (w) {
                AutoWay.confirm('Withdraw this payment report? Do this only if you entered it by mistake.', { confirmText: 'Withdraw', confirmClass: 'btn-danger' })
                    .then(function (yes) { if (yes) post({ action: 'withdraw_payment', submission_id: w.getAttribute('data-withdraw') }).then(handle); });
                return;
            }
            var a = e.target.closest('[data-ob-action]');
            if (!a || !current) return;
            if (a.getAttribute('data-ob-action') === 'pay') {
                var sel = document.getElementById('op-type');
                var opts = '';
                if (current.pay.deposit > 0) opts += '<option value="deposit" data-max="' + current.pay.deposit + '">Deposit — ' + esc(money(current.pay.deposit)) + ' left</option>';
                if (current.pay.rental_fee > 0) opts += '<option value="rental_fee" data-max="' + current.pay.rental_fee + '">Rental and charges — ' + esc(money(current.pay.rental_fee)) + ' left</option>';
                sel.innerHTML = opts;
                var syncAmount = function () {
                    var o = sel.options[sel.selectedIndex];
                    var amt = document.getElementById('op-amount');
                    amt.max = o ? o.getAttribute('data-max') : '';
                    amt.value = o ? Number(o.getAttribute('data-max')).toFixed(2) : '';
                };
                sel.onchange = syncAmount;
                syncAmount();
                document.getElementById('op-ref').value = '';
                openPanel('ob-pay-panel');
            } else {
                var fee = current.cancel_fee;
                document.getElementById('ob-cancel-note').innerHTML = fee > 0
                    ? 'It\'s less than ' + esc(String(current.free_cancel_hours)) + ' hours to pickup, so a <strong>' + esc(money(fee)) + '</strong> cancellation fee applies. Anything you paid beyond that is refunded by the desk.'
                    : 'Cancelling now is <strong>free</strong>.' + (current.booking.amount_paid > 0 ? ' The desk will refund what you paid.' : '');
                document.getElementById('ob-cancel-reason').value = '';
                openPanel('ob-cancel-panel');
            }
        });

        document.getElementById('ob-pay-panel').addEventListener('submit', function (e) {
            e.preventDefault();
            clearError();
            var ref = document.getElementById('op-ref').value.trim();
            if (!ref) { showError('Enter the reference number from your GCash or bank receipt.'); return; }
            post({
                action: 'submit_payment', booking_id: current.booking.booking_id,
                payment_type: document.getElementById('op-type').value,
                payment_method: document.getElementById('op-method').value,
                amount: document.getElementById('op-amount').value,
                transaction_ref: ref,
                paid_on: document.getElementById('op-date').value
            }).then(handle);
        });

        document.getElementById('ob-cancel-confirm').addEventListener('click', function () {
            var btn = this;
            btn.disabled = true;
            post({ action: 'cancel', booking_id: current.booking.booking_id, reason: document.getElementById('ob-cancel-reason').value })
                .then(function (r) { btn.disabled = false; handle(r); });
        });

        var qs = new URLSearchParams(window.location.search);
        var openParam = qs.get('open');
        if (openParam && /^\d+$/.test(openParam)) {
            open(openParam).then(function () {
                if (qs.get('new')) AutoWay.toast('Request sent. The desk will confirm it shortly.', 'success');
            });
        }
    }
})();
