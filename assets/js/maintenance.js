/*
 * Wires up the Maintenance page (admin, and staff from Phase 10): schedule a
 * job, log past service, and the job window (start, change end, complete,
 * cancel, edit). Every rule lives in includes/maintenance_data.php; this file
 * sends the form and redraws what the server returns.
 */
(function () {
    'use strict';

    var API = window.APP_BASE_URL + 'api/maintenance.php';
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

    function humanize(s) {
        s = String(s || '');
        return s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ');
    }

    function fmtDate(s) {
        if (!s) return '—';
        var d = new Date(String(s).slice(0, 10) + 'T00:00:00');
        return isNaN(d) ? s : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function fmtDateTime(s) {
        if (!s) return '—';
        var d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d) ? s : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) + ' '
            + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    }

    function km(n) { return Number(n).toLocaleString() + ' km'; }

    function ymd(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function todayStr() { return ymd(new Date()); }

    // ------------------------------------------------------------------
    // Page refresh
    // ------------------------------------------------------------------
    function refreshPage(r) {
        if (r.table_html !== undefined) {
            document.getElementById('job-table-body').innerHTML = r.table_html;
            if (window.jobDataTable) window.jobDataTable.refresh();
        }
        if (r.due_html !== undefined) document.getElementById('due-table-body').innerHTML = r.due_html;
        if (r.totals) {
            var t = r.totals;
            var set = function (k, v) {
                var el = document.querySelector('[data-stat="' + k + '"]');
                if (el) el.textContent = v;
            };
            set('in_workshop', t.in_workshop);
            set('scheduled_soon', t.scheduled_soon);
            set('due_total', t.overdue + t.due_soon);
            set('overdue', t.overdue);
            set('spent_month', money(t.spent_month));
            set('spent_year', money(t.spent_year));
        }
    }

    // ------------------------------------------------------------------
    // Modal plumbing
    // ------------------------------------------------------------------
    var modalEl = document.getElementById('job-modal');
    var modal = new bootstrap.Modal(modalEl);
    var form = document.getElementById('job-form');
    var view = document.getElementById('job-view');
    var errors = document.getElementById('job-errors');
    var tpl = document.getElementById('result-fields-template');
    var mode = null;     // 'schedule' | 'log' | 'edit' | 'view'
    var current = null;  // the open job

    function showError(msg) {
        errors.textContent = msg;
        errors.classList.remove('d-none');
        errors.scrollIntoView({ block: 'nearest' });
    }

    function clearError() {
        errors.textContent = '';
        errors.classList.add('d-none');
    }

    /** Copy the shared result fields into a container, keeping only those wanted. */
    function fillResultFields(container, prefix, skip) {
        container.innerHTML = '';
        var frag = tpl.content.cloneNode(true);
        Array.prototype.forEach.call(frag.querySelectorAll('[data-field]'), function (el) {
            if (skip.indexOf(el.getAttribute('data-field')) !== -1) el.remove();
        });
        Array.prototype.forEach.call(frag.querySelectorAll('input'), function (input) {
            input.id = prefix + input.name;
            var label = input.parentNode.querySelector('label');
            if (label) label.htmlFor = input.id;
        });
        container.appendChild(frag);
    }

    function readFields(container) {
        var out = {};
        Array.prototype.forEach.call(container.querySelectorAll('input[name]'), function (i) { out[i.name] = i.value.trim(); });
        return out;
    }

    function setMode(m) {
        mode = m;
        clearError();
        form.classList.toggle('d-none', m === 'view');
        view.classList.toggle('d-none', m !== 'view');
        Array.prototype.forEach.call(form.querySelectorAll('[data-mode-only]'), function (el) {
            var only = el.getAttribute('data-mode-only');
            el.classList.toggle('d-none', !(only === m || (only === 'schedule' && m === 'edit')));
        });
        document.getElementById('job-status-line').innerHTML = '';
    }

    function setVal(id, v) { document.getElementById(id).value = v == null ? '' : v; }

    function openForm(m, preset) {
        preset = preset || {};
        setMode(m);
        var titles = { schedule: 'Schedule a job', log: 'Log past service', edit: 'Edit scheduled job' };
        var submits = { schedule: 'Schedule', log: 'Save record', edit: 'Save changes' };
        document.getElementById('job-modal-title').textContent = titles[m];
        document.getElementById('jf-submit').textContent = submits[m];
        document.getElementById('jf-description-label').textContent = m === 'log' ? 'What was done' : 'What needs doing';

        var today = todayStr();
        var start = document.getElementById('jf-start');
        var end = document.getElementById('jf-end');
        // Planned work starts today or later; past work ended today or earlier.
        start.min = end.min = m === 'log' ? '' : today;
        start.max = end.max = m === 'log' ? today : '';

        var vehicle = document.getElementById('jf-vehicle');
        vehicle.disabled = m === 'edit';
        setVal('jf-vehicle', preset.vehicle_id || '');
        setVal('jf-type', preset.maintenance_type || 'general_inspection');
        setVal('jf-start', preset.service_date || (m === 'log' ? '' : today));
        setVal('jf-end', preset.end_date || (m === 'log' ? '' : today));
        setVal('jf-description', preset.description || '');
        setVal('jf-shop', preset.performed_by || '');

        var logFields = document.getElementById('log-result-fields');
        if (m === 'log') fillResultFields(logFields, 'lf-', ['completed_on', 'performed_by', 'notes']);
        else logFields.innerHTML = '';

        modal.show();
    }

    // Moving the start past the end drags the end along — fewer "end before start" errors.
    document.getElementById('jf-start').addEventListener('change', function () {
        var end = document.getElementById('jf-end');
        if (this.value && (!end.value || end.value < this.value)) end.value = this.value;
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearError();
        var fields = {
            maintenance_type: document.getElementById('jf-type').value,
            service_date: document.getElementById('jf-start').value,
            end_date: document.getElementById('jf-end').value,
            description: document.getElementById('jf-description').value.trim(),
            performed_by: document.getElementById('jf-shop').value.trim()
        };
        if (mode === 'edit') {
            fields.action = 'update';
            fields.job_id = current.maintenance_id;
        } else {
            fields.vehicle_id = document.getElementById('jf-vehicle').value;
            if (!fields.vehicle_id) { showError('Choose a car.'); return; }
            fields.action = mode === 'log' ? 'log_past' : 'schedule';
        }
        if (!fields.service_date || !fields.end_date) { showError('Pick the start and end dates.'); return; }
        if (mode === 'log') {
            var extra = readFields(document.getElementById('log-result-fields'));
            if (extra.cost === '') { showError('Enter what the work cost (0 if it was free).'); return; }
            Object.keys(extra).forEach(function (k) { fields[k] = extra[k]; });
        }

        var btn = document.getElementById('jf-submit');
        btn.disabled = true;
        post(fields).then(function (r) {
            btn.disabled = false;
            if (!r.success) { showError(r.error); return; }
            refreshPage(r);
            AutoWay.toast(r.message, 'success');
            if (mode === 'edit' && r.job) showJob(r.job);
            else modal.hide();
        });
    });

    // ------------------------------------------------------------------
    // Existing job
    // ------------------------------------------------------------------
    function detailBlock(label, html) {
        return '<div><div class="detail-label">' + esc(label) + '</div><div>' + html + '</div></div>';
    }

    function openJob(id) {
        post({ action: 'get', job_id: id }).then(function (r) {
            if (!r.success) { AutoWay.toast(r.error, 'error'); return; }
            showJob(r.job);
            modal.show();
        });
    }

    function showJob(j) {
        current = j;
        setMode('view');
        closePanels();
        document.getElementById('job-modal-title').textContent = humanize(j.maintenance_type) + ' — ' + j.brand + ' ' + j.model;
        document.getElementById('job-status-line').innerHTML =
            '<span class="status-badge ' + AutoWay.statusClass(j.status) + '">' + esc(humanize(j.status)) + '</span>'
            + (j.overrunning ? ' <span class="status-badge status-danger">past planned end</span>' : '');

        var dates = fmtDate(j.service_date) + (j.end_date !== j.service_date ? ' – ' + fmtDate(j.end_date) : '')
            + ' <span class="text-secondary">(' + j.days + ' day' + (j.days === 1 ? '' : 's') + ')</span>';

        var next = [];
        if (j.next_maintenance_date) next.push(fmtDate(j.next_maintenance_date));
        if (j.next_due_km !== null) next.push(km(j.next_due_km));

        var blocks = detailBlock('Vehicle', '<span class="fw-semibold">' + esc(j.brand + ' ' + j.model) + '</span><br><span class="plate">'
                + esc(j.plate_number) + '</span> <span class="text-secondary small">' + esc(km(j.vehicle_mileage)) + ' now</span>')
            + detailBlock(j.status === 'completed' ? 'Dates' : 'Planned dates', dates)
            + detailBlock('Shop or mechanic', esc(j.performed_by || '—'));
        if (j.status === 'completed') {
            blocks += detailBlock('Cost', '<span class="mono fw-semibold">' + esc(money(j.cost)) + '</span>')
                + detailBlock('Odometer', j.odometer_km !== null ? esc(km(j.odometer_km)) : '—')
                + detailBlock('Next service', next.length ? esc(next.join(' or ')) : 'Not set');
        }

        var history = ['Logged ' + fmtDateTime(j.created_at) + ' by ' + j.logged_by_name];
        if (j.completed_at) history.push('Completed ' + fmtDateTime(j.completed_at) + (j.completed_by_name ? ' by ' + j.completed_by_name : ''));
        if (j.cancelled_at) history.push('Cancelled ' + fmtDateTime(j.cancelled_at) + (j.cancelled_by_name ? ' by ' + j.cancelled_by_name : '')
            + (j.cancel_reason ? ' — ' + j.cancel_reason : ''));

        var note = '';
        if (j.status === 'scheduled') {
            note = '<p class="small text-secondary mt-3 mb-0">The car can\'t be booked on these days. It goes into the workshop when you press Start.</p>';
        } else if (j.status === 'in_progress') {
            note = '<p class="small text-secondary mt-3 mb-0">The car is in the workshop and can\'t be booked until the job is completed'
                + (j.overrunning ? '. It is past its planned end — change the end date or complete the job.' : ' (planned end ' + esc(fmtDate(j.end_date)) + ').') + '</p>';
        }

        document.getElementById('job-detail').innerHTML =
            '<div class="detail-grid">' + blocks + '</div>'
            + (j.description ? '<h3 class="form-section-title">Work</h3><p class="mb-0 pre-line">' + esc(j.description) + '</p>' : '')
            + note
            + '<h3 class="form-section-title">History</h3><ul class="list-unstyled small mb-0">'
            + history.map(function (h) { return '<li class="mb-1">' + esc(h) + '</li>'; }).join('') + '</ul>';

        renderActions(j);
    }

    function renderActions(j) {
        var labels = {
            start: ['Start now', 'btn-brand'],
            complete: ['Complete', j.status === 'in_progress' ? 'btn-brand' : 'btn-outline-secondary'],
            extend: ['Change planned end', 'btn-outline-secondary'],
            edit: ['Edit', 'btn-outline-secondary'],
            cancel: ['Cancel job', 'btn-outline-danger']
        };
        var box = document.getElementById('job-actions');
        box.innerHTML = j.actions.map(function (a) {
            return '<button type="button" class="btn ' + labels[a][1] + '" data-job-action="' + a + '">' + labels[a][0] + '</button>';
        }).join('');
        box.classList.toggle('d-none', j.actions.length === 0);
    }

    var PANELS = ['start-panel', 'extend-panel', 'complete-panel', 'cancel-panel'];

    function closePanels() {
        PANELS.forEach(function (id) { document.getElementById(id).classList.add('d-none'); });
        if (current && current.actions.length) document.getElementById('job-actions').classList.remove('d-none');
    }

    function openPanel(id) {
        closePanels();
        clearError();
        document.getElementById('job-actions').classList.add('d-none');
        var panel = document.getElementById(id);
        panel.classList.remove('d-none');
        var first = panel.querySelector('input');
        if (first) first.focus();
    }

    function handleResult(r) {
        if (!r.success) { showError(r.error); return false; }
        refreshPage(r);
        AutoWay.toast(r.message, 'success');
        if (r.job) showJob(r.job);
        return true;
    }

    view.addEventListener('click', function (e) {
        if (e.target.closest('[data-close-panel]')) { closePanels(); return; }
        var btn = e.target.closest('[data-job-action]');
        if (!btn || !current) return;
        var j = current;
        var today = todayStr();

        switch (btn.getAttribute('data-job-action')) {
            case 'start': {
                // Keep the planned length: a 3-day job started today ends in 3 days.
                var end = new Date(today + 'T00:00:00');
                end.setDate(end.getDate() + j.days - 1);
                var plannedEnd = j.end_date >= today ? j.end_date : ymd(end);
                setVal('sp-end', plannedEnd < today ? today : plannedEnd);
                openPanel('start-panel');
                break;
            }
            case 'extend':
                setVal('ep-end', j.end_date >= today ? j.end_date : today);
                openPanel('extend-panel');
                break;
            case 'complete': {
                var box = document.getElementById('complete-fields');
                fillResultFields(box, 'cf-', []);
                setVal('cf-completed_on', today);
                document.getElementById('cf-completed_on').min = j.service_date;
                setVal('cf-performed_by', j.performed_by || '');
                setVal('cf-odometer_km', j.vehicle_mileage);
                document.getElementById('cf-odometer_km').min = j.vehicle_mileage;
                openPanel('complete-panel');
                document.getElementById('cf-cost').focus();
                break;
            }
            case 'edit':
                openForm('edit', j);
                break;
            case 'cancel':
                setVal('cp-reason', '');
                openPanel('cancel-panel');
                break;
        }
    });

    document.getElementById('start-panel').addEventListener('submit', function (e) {
        e.preventDefault();
        var end = document.getElementById('sp-end').value;
        if (!end) { showError('Pick the planned end date.'); return; }
        post({ action: 'start', job_id: current.maintenance_id, end_date: end }).then(handleResult);
    });

    document.getElementById('extend-panel').addEventListener('submit', function (e) {
        e.preventDefault();
        var end = document.getElementById('ep-end').value;
        if (!end) { showError('Pick the new planned end.'); return; }
        post({ action: 'extend', job_id: current.maintenance_id, end_date: end }).then(handleResult);
    });

    document.getElementById('complete-panel').addEventListener('submit', function (e) {
        e.preventDefault();
        var fields = readFields(document.getElementById('complete-fields'));
        if (fields.cost === '') { showError('Enter the final cost (0 if it was free).'); return; }
        fields.action = 'complete';
        fields.job_id = current.maintenance_id;
        post(fields).then(handleResult);
    });

    document.getElementById('cp-confirm').addEventListener('click', function () {
        var reason = document.getElementById('cp-reason').value.trim();
        if (!reason) { showError('Say why — the reason is kept on record.'); return; }
        post({ action: 'cancel', job_id: current.maintenance_id, reason: reason }).then(handleResult);
    });

    // ------------------------------------------------------------------
    // Page buttons
    // ------------------------------------------------------------------
    document.getElementById('schedule-btn').addEventListener('click', function () { openForm('schedule'); });
    document.getElementById('log-past-btn').addEventListener('click', function () { openForm('log'); });

    document.getElementById('job-table-body').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-action="open"]');
        if (btn) openJob(btn.getAttribute('data-id'));
    });

    document.getElementById('due-table-body').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-schedule-vehicle]');
        if (!btn) return;
        openForm('schedule', {
            vehicle_id: btn.getAttribute('data-schedule-vehicle'),
            maintenance_type: btn.getAttribute('data-schedule-type')
        });
    });

    window.jobDataTable = AutoWay.initDataTable(document.getElementById('job-table'), {
        searchInput: document.getElementById('job-search'),
        filters: [
            { el: document.getElementById('job-filter-when'), attr: 'when' },
            { el: document.getElementById('job-filter-type'), attr: 'type' }
        ],
        pagerEl: document.getElementById('job-pager'),
        emptyMessage: 'No jobs match your search or filters.'
    });

    // Deep link (e.g. from a vehicle or the dashboard): maintenance.php?open=7
    var openParam = new URLSearchParams(window.location.search).get('open');
    if (openParam && /^\d+$/.test(openParam)) openJob(openParam);
})();
