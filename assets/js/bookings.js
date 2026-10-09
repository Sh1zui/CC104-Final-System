/*
 * Wires up the Bookings page (admin/bookings.php, staff/reservations.php): the three-step new-booking flow, the
 * booking detail modal (confirm, cancel, no-show, reschedule), and the
 * table/stat refresh after every change. All availability and pricing is
 * decided by the server (includes/bookings_data.php); this file only asks
 * and shows the answer, so there's no second copy of a rule in JS.
 */
(function () {
    'use strict';

    var API = window.APP_BASE_URL + 'api/bookings.php';
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
            .catch(function () {
                return { success: false, error: 'Could not reach the server. Check your connection and try again.' };
            });
    }

    function formatDateTime(s) {
        if (!s) return '—';
        var d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d)) return s;
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) + ' '
            + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    }

    /** "2026-10-05 09:00:00" -> "2026-10-05T09:00" for datetime-local inputs. */
    function toInputValue(s) {
        return String(s).replace(' ', 'T').slice(0, 16);
    }

    function humanize(s) {
        var labels = { no_show: 'No-show', suv: 'SUV', traffic_violation: 'Traffic violation', extra_mileage: 'Extra mileage' };
        return labels[s] || (s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' '));
    }

    function plural(n, word) {
        return n + ' ' + word + (n === 1 ? '' : 's');
    }

    // ------------------------------------------------------------------
    // Table + stat tiles
    // ------------------------------------------------------------------
    function refreshTable(html) {
        document.getElementById('booking-table-body').innerHTML = html;
        if (window.bookingDataTable) window.bookingDataTable.refresh();
        refreshStats();
    }

    function refreshStats() {
        var rows = document.querySelectorAll('#booking-table-body tr[data-status]');
        var counts = { pending: 0, 'pickup-today': 0, active: 0, overdue: 0 };
        Array.prototype.forEach.call(rows, function (r) {
            var s = r.getAttribute('data-status');
            if (s === 'pending') counts.pending++;
            if (s === 'active') counts.active++;
            if (r.hasAttribute('data-pickup-today')) counts['pickup-today']++;
            if (r.hasAttribute('data-overdue')) counts.overdue++;
        });
        Object.keys(counts).forEach(function (k) {
            var el = document.querySelector('[data-stat="' + k + '"]');
            if (el) el.textContent = counts[k];
        });
    }

    // ------------------------------------------------------------------
    // Shared rendering: vehicle pick list + price breakdown
    // ------------------------------------------------------------------
    function renderPickList(target, vehicles, opts) {
        opts = opts || {};
        if (!vehicles.length) {
            target.innerHTML = '<p class="text-secondary mb-0">No vehicles are free for those dates with these filters. Try other dates or loosen the filters.</p>';
            return;
        }
        target.innerHTML = vehicles.map(function (v) {
            var thumb = v.primary_image_url
                ? '<img class="vehicle-thumb" src="' + esc(v.primary_image_url) + '" alt="">'
                : '<span class="vehicle-thumb"><i class="bi bi-car-front"></i></span>';
            var current = opts.currentVehicleId && String(v.vehicle_id) === String(opts.currentVehicleId);
            return '<button type="button" class="vehicle-pick" role="listitem" data-pick="' + v.vehicle_id + '">'
                + thumb
                + '<span class="vehicle-pick-main"><span class="fw-semibold">' + esc(v.brand + ' ' + v.model) + '</span>'
                + (current ? ' <span class="status-badge status-info">current</span>' : '')
                + '<span class="cell-sub d-block"><span class="plate">' + esc(v.plate_number) + '</span> '
                + esc(humanize(v.vehicle_type)) + ' &middot; ' + esc(humanize(v.transmission)) + ' &middot; ' + esc(v.seating_capacity) + ' seats</span></span>'
                + '<span class="vehicle-pick-price"><span class="mono fw-semibold">' + esc(money(v.quote.total_amount)) + '</span>'
                + '<span class="cell-sub d-block mono">' + esc(money(v.daily_rate)) + '/day</span></span>'
                + '</button>';
        }).join('');
    }

    function quoteHtml(q) {
        var rows = [
            ['Daily rate × ' + plural(q.rental_days, 'day'), money(q.daily_rate) + ' × ' + q.rental_days, money(q.base_amount)]
        ];
        if (q.long_rental_discount > 0) rows.push(['Long-rental discount', '', '− ' + money(q.long_rental_discount)]);
        if (q.manual_discount > 0) rows.push(['Extra discount', '', '− ' + money(q.manual_discount)]);
        rows.push(['Security deposit (refundable)', '', money(q.deposit_amount)]);
        return '<table class="quote-table"><tbody>' + rows.map(function (r) {
            return '<tr><th scope="row">' + esc(r[0]) + '</th><td class="mono text-secondary">' + esc(r[1]) + '</td><td class="mono">' + esc(r[2]) + '</td></tr>';
        }).join('') + '</tbody><tfoot><tr><th scope="row">Total</th><td></td><td class="mono">' + esc(money(q.total_amount)) + '</td></tr></tfoot></table>';
    }

    function debounce(fn, ms) {
        var t;
        return function () {
            var args = arguments, self = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(self, args); }, ms);
        };
    }

    // Keep "return" from being set before "pickup" in the date pickers.
    function linkDateInputs(pickupEl, returnEl) {
        pickupEl.addEventListener('change', function () {
            if (!pickupEl.value) return;
            returnEl.min = pickupEl.value;
            if (!returnEl.value || returnEl.value <= pickupEl.value) {
                var d = new Date(pickupEl.value);
                d.setDate(d.getDate() + 1);
                var pad = function (n) { return String(n).padStart(2, '0'); };
                returnEl.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
            }
        });
    }

    // ------------------------------------------------------------------
    // New booking flow
    // ------------------------------------------------------------------
    var nbModalEl = document.getElementById('new-booking-modal');
    var nbModal = new bootstrap.Modal(nbModalEl);
    var nbErrors = document.getElementById('nb-errors');
    var nbSearchForm = document.getElementById('nb-search-form');
    var nbCreateForm = document.getElementById('nb-create-form');
    var nb = { search: null, vehicles: [], picked: null };

    function nbShowError(message) {
        nbErrors.textContent = message;
        nbErrors.classList.remove('d-none');
    }

    function nbClearError() {
        nbErrors.classList.add('d-none');
        nbErrors.textContent = '';
    }

    function gotoStep(n) {
        nbClearError();
        nbModalEl.querySelectorAll('[data-step]').forEach(function (el) {
            el.classList.toggle('d-none', el.getAttribute('data-step') !== String(n));
        });
        nbModalEl.querySelectorAll('.wizard-steps li').forEach(function (li) {
            var step = parseInt(li.getAttribute('data-step-label'), 10);
            li.classList.toggle('active', step === n);
            li.classList.toggle('done', step < n);
        });
    }

    nbModalEl.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-goto-step]');
        if (btn) gotoStep(parseInt(btn.getAttribute('data-goto-step'), 10));
    });

    document.getElementById('new-booking-btn').addEventListener('click', function () {
        nbSearchForm.reset();
        nbCreateForm.reset();
        nb = { search: null, vehicles: [], picked: null };
        gotoStep(1);
        nbModal.show();
    });

    linkDateInputs(document.getElementById('nb-pickup'), document.getElementById('nb-return'));

    nbSearchForm.addEventListener('submit', function (e) {
        e.preventDefault();
        nbClearError();
        var f = nbSearchForm.elements;
        if (!f.customer_id.value) { nbShowError('Choose a customer.'); return; }
        if (!f.pickup.value || !f['return'].value) { nbShowError('Choose pickup and return times.'); return; }

        nb.search = {
            customer_id: f.customer_id.value,
            customer_label: f.customer_id.options[f.customer_id.selectedIndex].text,
            pickup: f.pickup.value,
            'return': f['return'].value,
            type: f.type.value,
            transmission: f.transmission.value,
            min_seats: f.min_seats.value
        };
        var btn = document.getElementById('nb-search-btn');
        btn.disabled = true;
        post(Object.assign({ action: 'search' }, nb.search)).then(function (r) {
            btn.disabled = false;
            if (!r.success) { nbShowError(r.error); return; }
            if (r.customer_blockers.length) { nbShowError('This customer can\'t book: ' + r.customer_blockers.join(' ')); return; }
            nb.vehicles = r.vehicles;
            document.getElementById('nb-window-summary').innerHTML =
                '<span class="fw-semibold text-body">' + esc(nb.search.customer_label) + '</span><br>'
                + esc(formatDateTime(nb.search.pickup)) + ' &rarr; ' + esc(formatDateTime(nb.search['return']))
                + ' &middot; ' + esc(plural(r.rental_days, 'day')) + ' &middot; ' + esc(plural(r.vehicles.length, 'vehicle')) + ' free';
            renderPickList(document.getElementById('nb-results'), r.vehicles);
            gotoStep(2);
        });
    });

    document.getElementById('nb-results').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-pick]');
        if (!btn) return;
        nb.picked = nb.vehicles.filter(function (v) { return String(v.vehicle_id) === btn.getAttribute('data-pick'); })[0];
        document.getElementById('nb-pick-summary').innerHTML =
            '<span class="fw-semibold">' + esc(nb.picked.brand + ' ' + nb.picked.model) + '</span> <span class="plate">' + esc(nb.picked.plate_number) + '</span>'
            + '<div class="text-secondary">' + esc(nb.search.customer_label) + '<br>' + esc(formatDateTime(nb.search.pickup)) + ' &rarr; ' + esc(formatDateTime(nb.search['return'])) + '</div>';
        document.getElementById('nb-discount').value = '0';
        document.getElementById('nb-quote').innerHTML = quoteHtml(nb.picked.quote);
        gotoStep(3);
    });

    var nbRequote = debounce(function () {
        post({ action: 'quote', vehicle_id: nb.picked.vehicle_id, pickup: nb.search.pickup, 'return': nb.search['return'],
               discount: document.getElementById('nb-discount').value }).then(function (r) {
            if (!r.success) { nbShowError(r.error); return; }
            nbClearError();
            document.getElementById('nb-quote').innerHTML = quoteHtml(r.quote);
        });
    }, 300);
    document.getElementById('nb-discount').addEventListener('input', nbRequote);

    nbCreateForm.addEventListener('submit', function (e) {
        e.preventDefault();
        nbClearError();
        var btn = document.getElementById('nb-create-btn');
        btn.disabled = true;
        post({
            action: 'create',
            customer_id: nb.search.customer_id,
            vehicle_id: nb.picked.vehicle_id,
            pickup: nb.search.pickup,
            'return': nb.search['return'],
            discount: document.getElementById('nb-discount').value,
            notes: document.getElementById('nb-notes').value,
            confirm_now: document.getElementById('nb-confirm-now').checked ? '1' : '0'
        }).then(function (r) {
            btn.disabled = false;
            if (!r.success) {
                // Most likely someone else took the car a moment ago:
                // say so, and send them back to pick again.
                nbShowError(r.error);
                return;
            }
            refreshTable(r.table_html);
            AutoWay.toast(r.message, 'success');
            nbModal.hide();
            showDetail(r.detail);
        });
    });

    // ------------------------------------------------------------------
    // Booking detail
    // ------------------------------------------------------------------
    var bkModalEl = document.getElementById('booking-modal');
    var bkModal = new bootstrap.Modal(bkModalEl);
    var current = null; // detail of the open booking

    function openBooking(id) {
        return post({ action: 'get', booking_id: id }).then(function (r) {
            if (!r.success) { AutoWay.toast(r.error, 'error'); return false; }
            showDetail(r.detail);
            return true;
        });
    }

    function showDetail(detail) {
        current = detail;
        var b = detail.booking;
        closePanels();
        document.getElementById('booking-modal-title').textContent = b.booking_reference;
        document.getElementById('booking-status-line').innerHTML =
            '<span class="status-badge ' + AutoWay.statusClass(b.booking_status) + '">' + esc(humanize(b.booking_status)) + '</span> '
            + '<span class="status-badge ' + AutoWay.statusClass(b.payment_status === 'pending' ? 'unpaid' : b.payment_status) + '">payment ' + esc(b.payment_status) + '</span>'
            + (detail.overdue ? ' <span class="status-badge status-danger">overdue</span>' : '');

        var q = {
            rental_days: b.rental_days, daily_rate: b.daily_rate_snapshot, base_amount: b.base_amount,
            long_rental_discount: detail.long_rental_discount, manual_discount: detail.manual_discount,
            deposit_amount: b.deposit_amount, total_amount: b.total_amount
        };

        var history = ['Created ' + formatDateTime(b.created_at) + (b.created_by_name ? ' by ' + b.created_by_name : ' by the customer')];
        if (b.confirmed_at) history.push('Confirmed ' + formatDateTime(b.confirmed_at) + (b.confirmed_by_name ? ' by ' + b.confirmed_by_name : ''));
        if (b.cancelled_at) {
            history.push((b.booking_status === 'no_show' ? 'Marked no-show ' : 'Cancelled ') + formatDateTime(b.cancelled_at)
                + (b.cancelled_by_name ? ' by ' + b.cancelled_by_name : ' by the customer')
                + (b.cancellation_reason ? ' — ' + b.cancellation_reason : ''));
        }

        document.getElementById('booking-detail').innerHTML =
            '<div class="detail-grid">'
            + detailBlock('Customer', '<span class="fw-semibold">' + esc(b.customer_name) + '</span><br>' + esc(b.customer_email) + (b.customer_phone ? '<br>' + esc(b.customer_phone) : '')
                + (b.customer_status !== 'verified' ? '<br><span class="status-badge ' + AutoWay.statusClass(b.customer_status) + '">' + esc(b.customer_status) + '</span>' : ''))
            + detailBlock('Vehicle', '<span class="fw-semibold">' + esc(b.brand + ' ' + b.model + ' ' + b.year) + '</span><br><span class="plate">' + esc(b.plate_number) + '</span>')
            + detailBlock('Pickup', esc(formatDateTime(b.pickup_datetime)))
            + detailBlock('Return', '<span class="' + (detail.overdue ? 'text-danger fw-semibold' : '') + '">' + esc(formatDateTime(b.return_datetime)) + '</span>')
            + '</div>'
            + '<h3 class="form-section-title">Price</h3><div class="quote-box">' + quoteHtml(q)
            + settlementHtml(b)
            + '</div>'
            + handoverHtml(detail)
            + submissionsHtml(detail)
            + paymentsHtml(detail)
            + (b.notes ? '<h3 class="form-section-title">Notes</h3><p class="mb-0">' + esc(b.notes) + '</p>' : '')
            + '<h3 class="form-section-title">History</h3><ul class="list-unstyled small mb-0">'
            + history.map(function (h) { return '<li class="mb-1">' + esc(h) + '</li>'; }).join('') + '</ul>';

        renderActions(detail);
        renderMoneyActions(detail);
        bkModal.show();
    }

    function paidLine(label, value, cls) {
        return '<div class="paid-line' + (cls ? ' ' + cls : '') + '"><span>' + esc(label) + '</span><span class="mono">' + esc(value) + '</span></div>';
    }

    // Below the booking price: charges added at/after return, the deposit
    // coming back once the rental is done, and where payment stands.
    // The numbers all come from the server (booking_amount_due()).
    function settlementHtml(b) {
        var closed = ['cancelled', 'no_show'].indexOf(b.booking_status) !== -1;
        var html = '';
        if (b.extra_charges > 0) html += paidLine('Extra charges (see Handover)', '+ ' + money(b.extra_charges));
        if (b.booking_status === 'completed') html += paidLine('Deposit returned to customer', '− ' + money(b.deposit_amount));
        if (Math.abs(b.amount_due - b.total_amount) >= 0.01) html += paidLine('Amount due', money(b.amount_due), 'fw-semibold');
        html += paidLine('Paid so far', money(b.amount_paid));
        if (!closed && b.balance_due > 0) html += paidLine('Balance due', money(b.balance_due), 'fw-semibold');
        if (!closed && b.refund_due > 0) html += paidLine('Refund due to customer', money(b.refund_due), 'fw-semibold text-success');
        return html ? '<div class="settlement">' + html + '</div>' : '';
    }

    function handoverHtml(detail) {
        var r = detail.rental;
        if (!r && !detail.penalties.length) return '';
        var html = '<h3 class="form-section-title">Handover</h3><div class="detail-grid">';
        if (r) {
            html += detailBlock('Released',
                esc(formatDateTime(r.released_at)) + ' by ' + esc(r.released_by_name)
                + '<br><span class="mono">' + esc(Number(r.odometer_out).toLocaleString()) + ' km</span> &middot; fuel ' + esc(r.fuel_out_label)
                + (r.condition_notes_out ? '<div class="cell-sub">' + esc(r.condition_notes_out) + '</div>' : ''));
            html += detailBlock('Returned', r.returned_at
                ? esc(formatDateTime(r.returned_at)) + ' by ' + esc(r.returned_by_name)
                  + '<br><span class="mono">' + esc(Number(r.odometer_in).toLocaleString()) + ' km</span> &middot; fuel ' + esc(r.fuel_in_label)
                  + ' &middot; ' + esc(Number(r.km_driven).toLocaleString()) + ' km driven'
                  + (r.damage_notes ? '<div class="cell-sub">' + esc(r.damage_notes) + '</div>' : '')
                : '<span class="text-secondary">Not yet</span>');
        }
        html += '</div>';

        var lines = [];
        if (r && r.late_fee > 0) lines.push(['Late return (' + plural(r.late_hours, 'hour') + ')', r.late_fee]);
        if (r && r.fuel_fee > 0) lines.push(['Fuel (' + r.fuel_out_label + ' → ' + r.fuel_in_label + ')', r.fuel_fee]);
        if (r && r.damage_fee > 0) lines.push(['Damage', r.damage_fee]);
        detail.penalties.forEach(function (p) {
            lines.push([humanize(p.charge_type) + ' — ' + p.description, p.amount]);
        });
        if (lines.length) {
            html += '<table class="quote-table mt-2"><tbody>' + lines.map(function (l) {
                return '<tr><th scope="row">' + esc(l[0]) + '</th><td class="mono">' + esc(money(l[1])) + '</td></tr>';
            }).join('') + '</tbody></table>';
        }
        return html;
    }

    function detailBlock(label, html) {
        return '<div><div class="detail-label">' + esc(label) + '</div><div>' + html + '</div></div>';
    }

    function renderActions(detail) {
        var a = detail.actions;
        var html = '';
        if (a.indexOf('confirm') !== -1) html += '<button type="button" class="btn btn-brand" data-booking-action="confirm">Confirm booking</button>';
        if (a.indexOf('check_out') !== -1) html += '<button type="button" class="btn btn-brand" data-booking-action="check_out"><i class="bi bi-box-arrow-right me-1"></i>Release car</button>';
        if (a.indexOf('check_in') !== -1) html += '<button type="button" class="btn btn-brand" data-booking-action="check_in"><i class="bi bi-box-arrow-in-left me-1"></i>Receive car back</button>';
        if (a.indexOf('extend') !== -1) html += '<button type="button" class="btn btn-outline-secondary" data-booking-action="extend">Extend</button>';
        if (a.indexOf('add_charge') !== -1) html += '<button type="button" class="btn btn-outline-secondary" data-booking-action="add_charge">Add a charge</button>';
        if (a.indexOf('reschedule') !== -1) html += '<button type="button" class="btn btn-outline-secondary" data-booking-action="reschedule">Reschedule</button>';
        if (a.indexOf('no_show') !== -1) html += '<button type="button" class="btn btn-outline-secondary" data-booking-action="no_show">Mark no-show</button>';
        if (a.indexOf('cancel') !== -1) html += '<button type="button" class="btn btn-outline-danger" data-booking-action="cancel">Cancel booking</button>';
        if (!html) {
            var b = detail.booking;
            var why = b.booking_status === 'confirmed'
                ? 'The car can be released from ' + formatMinutes(detail.rules.early_checkout_minutes) + ' before pickup.'
                : 'This booking is closed.';
            html = '<p class="text-secondary small mb-0">' + esc(why) + '</p>';
        }
        document.getElementById('booking-actions').innerHTML = html;
    }

    var PANELS = ['cancel-panel', 'reschedule-panel', 'checkout-panel', 'checkin-panel', 'extend-panel', 'charge-panel',
                  'payment-panel', 'settle-panel', 'void-panel', 'reject-panel'];

    function closePanels() {
        PANELS.forEach(function (id) { document.getElementById(id).classList.add('d-none'); });
        document.getElementById('booking-actions').classList.remove('d-none');
        document.getElementById('money-actions').classList.remove('d-none');
    }

    // One task at a time: opening a panel hides both action bars.
    function openPanel(id) {
        closePanels();
        document.getElementById('booking-actions').classList.add('d-none');
        document.getElementById('money-actions').classList.add('d-none');
        document.getElementById(id).classList.remove('d-none');
    }

    function handleResult(r) {
        if (!r.success) { AutoWay.toast(r.error, 'error'); return false; }
        refreshTable(r.table_html);
        AutoWay.toast(r.message, 'success');
        if (r.detail) showDetail(r.detail);
        return true;
    }

    bkModalEl.addEventListener('click', function (e) {
        if (e.target.closest('[data-close-panel]')) { closePanels(); return; }

        var btn = e.target.closest('[data-booking-action]');
        if (!btn || !current) return;
        var id = current.booking.booking_id;
        var action = btn.getAttribute('data-booking-action');

        if (action === 'confirm') {
            btn.disabled = true;
            post({ action: 'confirm', booking_id: id }).then(function (r) { btn.disabled = false; handleResult(r); });
        } else if (action === 'no_show') {
            AutoWay.confirm('Mark this booking as a no-show? The car becomes free again for this window.', { confirmText: 'Mark no-show', confirmClass: 'btn-danger' })
                .then(function (ok) {
                    if (ok) post({ action: 'no_show', booking_id: id }).then(handleResult);
                });
        } else if (action === 'cancel') {
            document.getElementById('cancel-reason').value = '';
            document.getElementById('cancel-waive').checked = false;
            var fee = current.cancellation_fee_now || 0;
            var note = document.getElementById('cancel-fee-note');
            note.classList.toggle('d-none', !(fee > 0));
            document.getElementById('cancel-waive-row').classList.toggle('d-none', !(fee > 0));
            note.textContent = fee > 0
                ? 'Pickup is less than ' + plural(current.rules.free_cancellation_hours, 'hour') + ' away (or has passed), so cancelling now costs a '
                  + money(fee) + ' fee (' + plural(current.rules.cancellation_fee_days, 'day') + ' at the booked rate). Anything paid beyond that is refunded when you settle.'
                : '';
            openPanel('cancel-panel');
            document.getElementById('cancel-reason').focus();
        } else if (action === 'reschedule') {
            var b = current.booking;
            document.getElementById('rs-pickup').value = toInputValue(b.pickup_datetime);
            document.getElementById('rs-return').value = toInputValue(b.return_datetime);
            document.getElementById('rs-results').innerHTML = '';
            document.getElementById('rs-confirm').classList.add('d-none');
            openPanel('reschedule-panel');
        } else if (action === 'check_out') {
            openCheckout();
        } else if (action === 'check_in') {
            openCheckin();
        } else if (action === 'extend') {
            openExtend();
        } else if (action === 'add_charge') {
            document.getElementById('charge-panel').reset();
            openPanel('charge-panel');
            document.getElementById('ch-amount').focus();
        }
    });

    // ------------------------------------------------------------------
    // Release (check-out)
    // ------------------------------------------------------------------
    /** 120 -> "2 hours", 90 -> "90 minutes", 0 -> "the pickup time". */
    function formatMinutes(m) {
        m = Number(m) || 0;
        if (m === 0) return '0 minutes';
        return m % 60 === 0 ? plural(m / 60, 'hour') : plural(m, 'minute');
    }

    function nowInputValue() {
        var d = new Date();
        var pad = function (n) { return String(n).padStart(2, '0'); };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function openCheckout() {
        var b = current.booking;
        var form = document.getElementById('checkout-panel');
        form.reset();
        document.getElementById('co-odometer').value = current.vehicle_mileage;
        document.getElementById('co-odometer').min = current.vehicle_mileage;
        document.getElementById('co-odometer-hint').textContent = 'Last recorded: ' + Number(current.vehicle_mileage).toLocaleString() + ' km';
        var bal = document.getElementById('checkout-balance');
        bal.classList.toggle('d-none', !(b.balance_due > 0));
        bal.textContent = b.balance_due > 0 ? money(b.balance_due) + ' is still unpaid, including the ' + money(b.deposit_amount) + ' deposit. Collect it before handing over the keys.' : '';
        openPanel('checkout-panel');
    }

    document.getElementById('checkout-panel').addEventListener('submit', function (e) {
        e.preventDefault();
        if (!document.getElementById('co-license').checked) {
            AutoWay.toast('Check the customer\'s driver\'s license first, then tick the box.', 'error');
            return;
        }
        var btn = document.getElementById('co-submit');
        btn.disabled = true;
        post({
            action: 'check_out', booking_id: current.booking.booking_id,
            odometer_out: document.getElementById('co-odometer').value,
            fuel_out: document.getElementById('co-fuel').value,
            condition_notes: document.getElementById('co-notes').value,
            license_checked: '1'
        }).then(function (r) { btn.disabled = false; handleResult(r); });
    });

    // ------------------------------------------------------------------
    // Receive back (check-in), with a live preview of the charges
    // ------------------------------------------------------------------
    var ciPanel = document.getElementById('checkin-panel');
    var ciExtras = document.getElementById('ci-extras');

    function openCheckin() {
        var r = current.rental;
        ciPanel.reset();
        ciExtras.innerHTML = '';
        document.getElementById('ci-returned-at').value = nowInputValue();
        document.getElementById('ci-returned-at').min = toInputValue(r.released_at);
        document.getElementById('ci-odometer').value = '';
        document.getElementById('ci-odometer').min = r.odometer_out;
        document.getElementById('ci-odometer-hint').textContent = 'At release: ' + Number(r.odometer_out).toLocaleString() + ' km';
        document.getElementById('ci-fuel').value = String(r.fuel_level_out);
        document.getElementById('ci-fuel-hint').textContent = 'At release: ' + r.fuel_out_label;
        document.getElementById('ci-maintenance-note').classList.add('d-none');
        openPanel('checkin-panel');
        previewReturn();
        document.getElementById('ci-odometer').focus();
    }

    function checkinFields() {
        var f = {
            booking_id: current.booking.booking_id,
            returned_at: document.getElementById('ci-returned-at').value,
            odometer_in: document.getElementById('ci-odometer').value,
            fuel_in: document.getElementById('ci-fuel').value,
            damage_fee: document.getElementById('ci-damage-fee').value,
            damage_notes: document.getElementById('ci-damage-notes').value
        };
        ciExtras.querySelectorAll('.extra-line').forEach(function (line, i) {
            ['type', 'amount', 'description'].forEach(function (k) {
                f['extras[' + i + '][' + k + ']'] = line.querySelector('[data-extra="' + k + '"]').value;
            });
        });
        return f;
    }

    function chargeRow(label, amount, cls) {
        return '<tr' + (cls ? ' class="' + cls + '"' : '') + '><th scope="row">' + esc(label) + '</th><td class="mono">' + esc(money(amount)) + '</td></tr>';
    }

    var previewReturn = debounce(function () {
        var target = document.getElementById('ci-preview');
        post(Object.assign({ action: 'preview_return' }, checkinFields())).then(function (r) {
            if (!r.success) {
                target.innerHTML = '<p class="text-danger small mb-0">' + esc(r.error) + '</p>';
                return;
            }
            var c = r.charges;
            var lateLabel = c.late_hours > 0 ? 'Late return (' + plural(c.late_hours, 'hour') + ' × ' + money(c.hourly_rate) + ', capped per day)'
                : c.minutes_late > 0 ? 'Late return (' + c.minutes_late + ' min — within the grace period)' : 'Late return';
            var rows = chargeRow(lateLabel, c.late_fee) + chargeRow('Fuel', c.fuel_fee) + chargeRow('Damage', c.damage_fee);
            if (c.extras_total > 0) rows += chargeRow('Other charges', c.extras_total);
            if (c.earlier_charges > 0) rows += chargeRow('Charges added earlier', c.earlier_charges);
            var html = '<table class="quote-table"><tbody>' + rows + '</tbody><tfoot>' + chargeRow('Charges at return', c.charges_total) + '</tfoot></table>'
                + '<div class="settlement">'
                + paidLine('Rental (without deposit)', money(c.rental_cost))
                + paidLine('Final cost', money(c.final_cost), 'fw-semibold')
                + paidLine('Paid so far', money(c.amount_paid))
                + (c.balance_due > 0 ? paidLine('Customer still owes', money(c.balance_due), 'fw-semibold text-danger')
                    : paidLine('Refund to customer', money(c.refund_due), 'fw-semibold text-success'))
                + '</div>';
            target.innerHTML = html;
        });
    }, 250);

    ciPanel.addEventListener('input', previewReturn);
    ciPanel.addEventListener('change', previewReturn);

    document.getElementById('ci-maintenance').addEventListener('change', function () {
        document.getElementById('ci-maintenance-note').classList.toggle('d-none', !this.checked);
    });

    document.getElementById('ci-add-extra').addEventListener('click', function () {
        var line = document.getElementById('extra-line-template').content.firstElementChild.cloneNode(true);
        ciExtras.appendChild(line);
        line.querySelector('[data-extra="amount"]').focus();
    });

    ciExtras.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-remove-extra]');
        if (!btn) return;
        btn.closest('.extra-line').remove();
        previewReturn();
    });

    ciPanel.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!document.getElementById('ci-odometer').value) {
            AutoWay.toast('Enter the odometer reading.', 'error');
            document.getElementById('ci-odometer').focus();
            return;
        }
        var btn = document.getElementById('ci-submit');
        btn.disabled = true;
        var fields = checkinFields();
        fields.action = 'check_in';
        fields.needs_maintenance = document.getElementById('ci-maintenance').checked ? '1' : '0';
        fields.maintenance_note = document.getElementById('ci-maintenance-note').value;
        post(fields).then(function (r) { btn.disabled = false; handleResult(r); });
    });

    // ------------------------------------------------------------------
    // Extend
    // ------------------------------------------------------------------
    function openExtend() {
        var b = current.booking;
        document.getElementById('extend-panel').reset();
        var d = new Date(String(b.return_datetime).replace(' ', 'T'));
        d.setDate(d.getDate() + 1);
        var pad = function (n) { return String(n).padStart(2, '0'); };
        document.getElementById('ex-return').value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        document.getElementById('ex-return').min = toInputValue(b.return_datetime);
        document.getElementById('ex-current').textContent = 'Currently due back ' + formatDateTime(b.return_datetime) + '.';
        openPanel('extend-panel');
        previewExtend();
    }

    var previewExtend = debounce(function () {
        var target = document.getElementById('ex-quote');
        post({ action: 'preview_extend', booking_id: current.booking.booking_id, 'return': document.getElementById('ex-return').value }).then(function (r) {
            target.classList.remove('d-none');
            if (!r.success) { target.innerHTML = '<p class="text-danger small mb-0">' + esc(r.error) + '</p>'; return; }
            target.innerHTML = quoteHtml(r.quote)
                + '<p class="small mt-2 mb-0">Total changes from <span class="mono">' + esc(money(current.booking.total_amount)) + '</span> to <span class="mono fw-semibold">' + esc(money(r.quote.total_amount)) + '</span>. Availability is checked when you save.</p>';
        });
    }, 250);
    document.getElementById('ex-return').addEventListener('input', previewExtend);
    document.getElementById('ex-return').addEventListener('change', previewExtend);

    document.getElementById('extend-panel').addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = document.getElementById('ex-submit');
        btn.disabled = true;
        post({ action: 'extend', booking_id: current.booking.booking_id, 'return': document.getElementById('ex-return').value })
            .then(function (r) { btn.disabled = false; handleResult(r); });
    });

    // ------------------------------------------------------------------
    // Add a charge later
    // ------------------------------------------------------------------
    document.getElementById('charge-panel').addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = document.getElementById('ch-submit');
        btn.disabled = true;
        post({
            action: 'add_charge', booking_id: current.booking.booking_id,
            charge_type: document.getElementById('ch-type').value,
            amount: document.getElementById('ch-amount').value,
            description: document.getElementById('ch-description').value
        }).then(function (r) { btn.disabled = false; handleResult(r); });
    });

    document.getElementById('cancel-confirm-btn').addEventListener('click', function () {
        var reason = document.getElementById('cancel-reason').value.trim();
        if (!reason) {
            AutoWay.toast('Give a reason — it\'s kept on the booking and shown to the customer.', 'error');
            document.getElementById('cancel-reason').focus();
            return;
        }
        var btn = this;
        btn.disabled = true;
        post({ action: 'cancel', booking_id: current.booking.booking_id, reason: reason,
               waive_fee: document.getElementById('cancel-waive').checked ? '1' : '0' }).then(function (r) {
            btn.disabled = false;
            handleResult(r);
        });
    });

    document.getElementById('cancel-reason').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); document.getElementById('cancel-confirm-btn').click(); }
    });

    // --- reschedule ---
    var rs = { vehicles: [], picked: null };
    linkDateInputs(document.getElementById('rs-pickup'), document.getElementById('rs-return'));

    document.getElementById('reschedule-panel').addEventListener('submit', function (e) {
        e.preventDefault();
        var b = current.booking;
        document.getElementById('rs-confirm').classList.add('d-none');
        post({
            action: 'search', exclude_booking_id: b.booking_id, customer_id: b.customer_id,
            pickup: document.getElementById('rs-pickup').value, 'return': document.getElementById('rs-return').value
        }).then(function (r) {
            if (!r.success) { AutoWay.toast(r.error, 'error'); return; }
            if (r.customer_blockers.length) { AutoWay.toast('This customer can\'t book: ' + r.customer_blockers.join(' '), 'error'); return; }
            rs.vehicles = r.vehicles;
            // The booked car first, if it's free, since keeping it is the usual case.
            rs.vehicles.sort(function (x, y) {
                return (String(y.vehicle_id) === String(b.vehicle_id)) - (String(x.vehicle_id) === String(b.vehicle_id));
            });
            renderPickList(document.getElementById('rs-results'), rs.vehicles, { currentVehicleId: b.vehicle_id });
        });
    });

    function rsQuote() {
        var b = current.booking;
        return post({
            action: 'quote', booking_id: b.booking_id, vehicle_id: rs.picked.vehicle_id,
            pickup: document.getElementById('rs-pickup').value, 'return': document.getElementById('rs-return').value,
            discount: document.getElementById('rs-discount').value
        }).then(function (r) {
            if (!r.success) { AutoWay.toast(r.error, 'error'); return; }
            document.getElementById('rs-quote').innerHTML = quoteHtml(r.quote)
                + (Math.abs(r.quote.total_amount - b.total_amount) >= 0.01
                    ? '<p class="small mt-2 mb-0">Total changes from <span class="mono">' + esc(money(b.total_amount)) + '</span> to <span class="mono fw-semibold">' + esc(money(r.quote.total_amount)) + '</span>.</p>'
                    : '<p class="small text-secondary mt-2 mb-0">Total stays the same.</p>');
        });
    }

    document.getElementById('rs-results').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-pick]');
        if (!btn) return;
        document.querySelectorAll('#rs-results .vehicle-pick').forEach(function (el) { el.classList.toggle('selected', el === btn); });
        rs.picked = rs.vehicles.filter(function (v) { return String(v.vehicle_id) === btn.getAttribute('data-pick'); })[0];
        document.getElementById('rs-discount').value = current.manual_discount || 0;
        rsQuote().then(function () { document.getElementById('rs-confirm').classList.remove('d-none'); });
    });

    document.getElementById('rs-discount').addEventListener('input', debounce(rsQuote, 300));

    document.getElementById('rs-save-btn').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        post({
            action: 'reschedule', booking_id: current.booking.booking_id, vehicle_id: rs.picked.vehicle_id,
            pickup: document.getElementById('rs-pickup').value, 'return': document.getElementById('rs-return').value,
            discount: document.getElementById('rs-discount').value
        }).then(function (r) {
            btn.disabled = false;
            handleResult(r);
        });
    });

    // ------------------------------------------------------------------
    // Money: payments list, record / settle / void, invoice (Phase 8).
    // The server decides every amount; this only shows it and asks.
    // ------------------------------------------------------------------
    var PRINT_BASE = window.APP_BASE_URL + 'print/';

    /** Money the customer says they sent online (Phase 11): check, then accept or reject. */
    function submissionsHtml(detail) {
        var subs = detail.submissions || [];
        if (!subs.length) return '';
        var waiting = subs.filter(function (x) { return x.status === 'submitted'; });
        var html = '<h3 class="form-section-title">Sent online' + (waiting.length ? ' <span class="status-badge status-warning">' + waiting.length + ' to check</span>' : '') + '</h3>'
            + '<ul class="payment-list">';
        subs.forEach(function (x) {
            var open = x.status === 'submitted';
            html += '<li class="payment-row submission-row' + (open ? ' is-open' : '') + '">'
                + '<div class="payment-main">'
                + '<span class="status-badge ' + AutoWay.statusClass(x.status === 'submitted' ? 'pending' : x.status === 'accepted' ? 'completed' : x.status === 'rejected' ? 'rejected' : 'cancelled') + '">'
                + esc(x.status === 'submitted' ? 'to check' : x.status) + '</span> '
                + '<span class="fw-semibold">' + esc(x.method_label) + '</span> ref <span class="mono">' + esc(x.transaction_ref) + '</span>'
                + '<div class="cell-sub">For ' + esc(x.type_label.toLowerCase()) + ' &middot; paid ' + esc(AutoWay.formatDate(x.paid_on))
                + ' &middot; sent ' + esc(formatDateTime(x.created_at))
                + (x.receipt_number ? ' &middot; receipt ' + esc(x.receipt_number) : '')
                + (x.review_note ? '<br>' + esc(x.review_note) : '')
                + (x.reviewed_by_name ? ' &middot; ' + esc(x.reviewed_by_name) : '') + '</div>'
                + '</div>'
                + '<div class="payment-amount mono">' + esc(money(x.amount)) + '</div>'
                + (open
                    ? '<div class="d-flex gap-1 flex-wrap justify-content-end">'
                      + '<select class="form-select form-select-sm w-auto" data-submission-type="' + esc(x.submission_id) + '" aria-label="Record as">'
                      + '<option value="deposit"' + (x.payment_type === 'deposit' ? ' selected' : '') + '>as deposit</option>'
                      + '<option value="rental_fee"' + (x.payment_type === 'rental_fee' ? ' selected' : '') + '>as rental</option>'
                      + '<option value="late_fee">as late fee</option><option value="damage_fee">as damage fee</option>'
                      + '<option value="additional_service">as other charges</option></select>'
                      + '<button type="button" class="btn btn-sm btn-brand" data-accept-submission="' + esc(x.submission_id) + '">Accept</button>'
                      + '<button type="button" class="btn btn-sm btn-outline-danger" data-reject-submission="' + esc(x.submission_id) + '" data-label="' + esc(money(x.amount) + ' ' + x.method_label + ' ref ' + x.transaction_ref) + '">Reject</button>'
                      + '</div>'
                    : '<span></span>')
                + '</li>';
        });
        html += '</ul>';
        if (waiting.length) html += '<p class="small text-secondary mt-2 mb-0">Check each reference in the GCash or bank account before accepting. Accepting records a payment and issues a receipt.</p>';
        return html;
    }

    function paymentsHtml(detail) {
        var rows = detail.payments;
        var html = '<h3 class="form-section-title">Payments</h3>';
        if (!rows.length) return html + '<p class="text-secondary small mb-0">No payments yet.</p>';
        html += '<ul class="payment-list">';
        rows.forEach(function (p) {
            var voided = p.status !== 'completed';
            html += '<li class="payment-row' + (voided ? ' is-void' : '') + '">'
                + '<div class="payment-main">'
                + '<span class="status-badge ' + esc(p.type_badge) + '">' + esc(p.type_label) + '</span> '
                + '<a href="' + esc(PRINT_BASE + 'receipt.php?id=' + encodeURIComponent(p.payment_id)) + '" target="_blank" rel="noopener" class="payment-receipt">' + esc(p.receipt_number) + '</a>'
                + '<div class="cell-sub">' + esc(formatDateTime(p.paid_at))
                + (p.payment_method ? ' &middot; ' + esc(p.method_label) : '')
                + (p.transaction_ref ? ' &middot; ref ' + esc(p.transaction_ref) : '')
                + ' &middot; ' + esc(p.recorded_by_name)
                + (p.notes ? '<br>' + esc(p.notes) : '') + '</div>'
                + '</div>'
                + '<div class="payment-amount mono">' + (p.is_money_out ? '− ' : '') + esc(money(p.amount))
                + (p.is_cashless ? '<div class="cell-sub">no cash moved</div>' : '')
                + (voided ? '<div class="cell-sub text-danger">void</div>' : '') + '</div>'
                + (detail.can_void && !voided
                    ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-void-payment="' + esc(p.payment_id) + '" data-receipt="' + esc(p.receipt_number) + '">Void</button>'
                    : '<span></span>')
                + '</li>';
        });
        html += '</ul>';
        var d = detail.deposit;
        if (d.received > 0) {
            html += '<p class="small text-secondary mt-2 mb-0">Deposit: ' + esc(money(d.received)) + ' received'
                + (d.kept > 0 ? ', ' + esc(money(d.kept)) + ' kept' : '')
                + (d.refunded > 0 ? ', ' + esc(money(d.refunded)) + ' refunded' : '')
                + (d.held > 0 ? ', <strong>' + esc(money(d.held)) + ' still held</strong>' : '') + '.</p>';
        }
        return html;
    }

    function renderMoneyActions(detail) {
        var b = detail.booking;
        var s = detail.settlement;
        var html = '';
        if (b.balance_due > 0) html += '<button type="button" class="btn btn-brand" data-money-action="pay"><i class="bi bi-cash-coin me-1"></i>Record payment</button>';
        if (s && (s.keep > 0 || s.refund_total > 0)) html += '<button type="button" class="btn btn-brand" data-money-action="settle">Settle' + (s.refund_total > 0 ? ' and refund ' + esc(money(s.refund_total)) : '') + '</button>';
        var closed = ['completed', 'cancelled', 'no_show'].indexOf(b.booking_status) !== -1;
        if (closed && !detail.invoice) html += '<button type="button" class="btn btn-outline-secondary" data-money-action="issue">Issue invoice</button>';
        html += '<a class="btn btn-outline-secondary" target="_blank" rel="noopener" href="' + esc(PRINT_BASE + 'invoice.php?booking=' + encodeURIComponent(b.booking_id)) + '">'
            + (detail.invoice ? 'Invoice ' + esc(detail.invoice.invoice_number) : 'Print statement') + '</a>';
        if (detail.invoice && detail.can_void) html += '<button type="button" class="btn btn-outline-danger" data-money-action="void-invoice">Void invoice</button>';
        document.getElementById('money-actions').innerHTML = html;
    }

    var voidTarget = null; // {kind: 'payment'|'invoice', id, label}

    function openVoid(target) {
        voidTarget = target;
        document.getElementById('void-label').textContent = 'Why is ' + target.label + ' being voided? It stays on record.';
        document.getElementById('void-reason').value = '';
        openPanel('void-panel');
        document.getElementById('void-reason').focus();
    }

    function syncRefField(methodEl, colEl) {
        var cash = methodEl.value === 'cash';
        colEl.querySelector('input').placeholder = cash ? 'Not needed for cash' : 'Required for ' + methodEl.options[methodEl.selectedIndex].text;
        colEl.classList.toggle('is-optional', cash);
    }

    function openPayment() {
        var b = current.booking;
        var form = document.getElementById('payment-panel');
        form.reset();
        var depositLeft = current.deposit_outstanding;
        // Most common next payment: the deposit if it's still owed, otherwise the balance.
        document.getElementById('pay-type').value = depositLeft > 0 ? 'deposit' : (b.extra_charges > 0 && b.booking_status === 'completed' ? 'additional_service' : 'rental_fee');
        document.getElementById('pay-amount').value = (depositLeft > 0 ? Math.min(depositLeft, b.balance_due) : b.balance_due).toFixed(2);
        document.getElementById('pay-amount-hint').textContent = 'Balance due ' + money(b.balance_due) + (depositLeft > 0 ? ', of which ' + money(depositLeft) + ' is the deposit' : '');
        syncRefField(document.getElementById('pay-method'), document.getElementById('pay-ref-col'));
        openPanel('payment-panel');
        document.getElementById('pay-amount').focus();
    }

    function openSettle() {
        var s = current.settlement;
        var rows = [];
        if (s.deposit_held > 0) rows.push(['Deposit held', money(s.deposit_held)]);
        if (s.keep > 0) rows.push(['Kept to cover what\'s owed', money(s.keep)]);
        if (s.refund_deposit > 0) rows.push(['Deposit refunded', money(s.refund_deposit)]);
        if (s.refund_payment > 0) rows.push(['Overpayment refunded', money(s.refund_payment)]);
        var html = '<table class="quote-table"><tbody>' + rows.map(function (r) {
            return '<tr><th scope="row">' + esc(r[0]) + '</th><td>' + esc(r[1]) + '</td></tr>';
        }).join('') + '</tbody></table>';
        html += s.refund_total > 0
            ? '<div class="settlement"><div class="paid-line fw-semibold"><span>Give back to the customer</span><span class="mono">' + esc(money(s.refund_total)) + '</span></div></div>'
            : '<div class="settlement"><div class="paid-line"><span>Nothing goes back to the customer</span><span></span></div></div>';
        if (s.balance_after > 0) html += '<p class="small text-danger mt-2 mb-0">After this the customer still owes ' + esc(money(s.balance_after)) + '.</p>';
        document.getElementById('settle-plan').innerHTML = html;
        document.getElementById('settle-refund-fields').classList.toggle('d-none', !(s.refund_total > 0));
        document.getElementById('settle-ref').value = '';
        syncRefField(document.getElementById('settle-method'), document.getElementById('settle-ref-col'));
        document.getElementById('settle-submit').textContent = s.refund_total > 0 ? 'Settle and refund ' + money(s.refund_total) : 'Settle';
        openPanel('settle-panel');
    }

    document.getElementById('pay-method').addEventListener('change', function () {
        syncRefField(this, document.getElementById('pay-ref-col'));
    });
    document.getElementById('settle-method').addEventListener('change', function () {
        syncRefField(this, document.getElementById('settle-ref-col'));
    });

    bkModalEl.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-money-action]');
        if (btn && current) {
            var a = btn.getAttribute('data-money-action');
            if (a === 'pay') openPayment();
            else if (a === 'settle') openSettle();
            else if (a === 'void-invoice') openVoid({ kind: 'invoice', id: current.invoice.invoice_id, label: 'invoice ' + current.invoice.invoice_number });
            else if (a === 'issue') {
                btn.disabled = true;
                post({ action: 'issue_invoice', booking_id: current.booking.booking_id }).then(function (r) {
                    btn.disabled = false;
                    if (handleResult(r)) window.open(PRINT_BASE + 'invoice.php?id=' + encodeURIComponent(r.invoice_id), '_blank', 'noopener');
                });
            }
            return;
        }
        var v = e.target.closest('[data-void-payment]');
        if (v && current) openVoid({ kind: 'payment', id: v.getAttribute('data-void-payment'), label: 'receipt ' + v.getAttribute('data-receipt') });

        var acc = e.target.closest('[data-accept-submission]');
        if (acc && current) {
            var sid = acc.getAttribute('data-accept-submission');
            var typeSel = document.querySelector('[data-submission-type="' + sid + '"]');
            acc.disabled = true;
            post({ action: 'accept_submission', submission_id: sid, payment_type: typeSel ? typeSel.value : '' })
                .then(function (r) { acc.disabled = false; handleResult(r); });
        }
        var rej = e.target.closest('[data-reject-submission]');
        if (rej && current) {
            rejectTarget = rej.getAttribute('data-reject-submission');
            document.getElementById('reject-label').textContent = 'Why can\'t ' + rej.getAttribute('data-label') + ' be confirmed? (the customer sees this)';
            document.getElementById('reject-note').value = '';
            openPanel('reject-panel');
            document.getElementById('reject-note').focus();
        }
    });

    var rejectTarget = null;
    document.getElementById('reject-confirm-btn').addEventListener('click', function () {
        var note = document.getElementById('reject-note').value.trim();
        if (!note) {
            AutoWay.toast('Say why — the customer sees this so they can fix it.', 'error');
            document.getElementById('reject-note').focus();
            return;
        }
        var btn = this;
        btn.disabled = true;
        post({ action: 'reject_submission', submission_id: rejectTarget, note: note })
            .then(function (r) { btn.disabled = false; handleResult(r); });
    });

    document.getElementById('payment-panel').addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = document.getElementById('pay-submit');
        btn.disabled = true;
        post({
            action: 'record_payment', booking_id: current.booking.booking_id,
            amount: document.getElementById('pay-amount').value,
            payment_type: document.getElementById('pay-type').value,
            method: document.getElementById('pay-method').value,
            transaction_ref: document.getElementById('pay-ref').value,
            notes: document.getElementById('pay-notes').value
        }).then(function (r) { btn.disabled = false; handleResult(r); });
    });

    document.getElementById('settle-panel').addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = document.getElementById('settle-submit');
        btn.disabled = true;
        post({
            action: 'settle', booking_id: current.booking.booking_id,
            method: document.getElementById('settle-method').value,
            transaction_ref: document.getElementById('settle-ref').value
        }).then(function (r) { btn.disabled = false; handleResult(r); });
    });

    document.getElementById('void-confirm-btn').addEventListener('click', function () {
        var reason = document.getElementById('void-reason').value.trim();
        if (!reason) {
            AutoWay.toast('Say why — the reason is kept on record.', 'error');
            document.getElementById('void-reason').focus();
            return;
        }
        var btn = this;
        btn.disabled = true;
        var fields = voidTarget.kind === 'payment'
            ? { action: 'void_payment', payment_id: voidTarget.id, reason: reason }
            : { action: 'void_invoice', booking_id: current.booking.booking_id, reason: reason };
        post(fields).then(function (r) { btn.disabled = false; handleResult(r); });
    });

    // ------------------------------------------------------------------
    // Table
    // ------------------------------------------------------------------
    document.getElementById('booking-table-body').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-action="open"]');
        if (btn) openBooking(btn.getAttribute('data-id'));
    });

    window.bookingDataTable = AutoWay.initDataTable(document.getElementById('booking-table'), {
        searchInput: document.getElementById('booking-search'),
        filters: [
            { el: document.getElementById('booking-filter-status'), attr: 'status' },
            { el: document.getElementById('booking-filter-when'), attr: 'when' },
            { el: document.getElementById('booking-filter-payment'), attr: 'payment' }
        ],
        pagerEl: document.getElementById('booking-pager'),
        emptyMessage: 'No bookings match your search or filters.'
    });
    refreshStats();

    // Deep link (e.g. from a customer's booking list): bookings.php?open=12.
    // The staff desk adds &do=check_out or &do=check_in to go straight to
    // that panel — only if the booking still allows it right now.
    var params = new URLSearchParams(window.location.search);
    var openParam = params.get('open');
    var doParam = params.get('do');
    if (openParam && /^\d+$/.test(openParam)) {
        openBooking(openParam).then(function (opened) {
            if (!opened || !doParam || !/^(check_out|check_in)$/.test(doParam)) return;
            var btn = document.querySelector('#booking-actions [data-booking-action="' + doParam + '"]');
            if (btn) {
                btn.click();
                var panel = document.getElementById(doParam === 'check_out' ? 'checkout-panel' : 'checkin-panel');
                // After the modal's fade-in, so there's something to scroll.
                if (panel) setTimeout(function () { panel.scrollIntoView({ block: 'start', behavior: 'smooth' }); }, 400);
            } else {
                AutoWay.toast(doParam === 'check_out'
                    ? 'This booking can\'t be released right now — see its status and actions.'
                    : 'This booking isn\'t out on rental any more.', 'error');
            }
        });
    }
})();
