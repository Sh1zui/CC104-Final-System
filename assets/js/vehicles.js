/*
 * Wires up admin/vehicles.php: the add/edit modal, the AJAX calls to
 * api/vehicles.php, and the image manager inside the modal. Every
 * mutation re-renders the table from the server's response instead of
 * guessing the new HTML client-side, so the row markup only ever lives
 * in one place (includes/vehicle_table_partial.php).
 */
(function () {
    'use strict';

    var API = window.APP_BASE_URL + 'api/vehicles.php';
    var csrfInput = document.querySelector('#vehicle-form input[name="csrf_token"]');
    var table = document.getElementById('vehicle-table');
    var isArchivedView = table.getAttribute('data-archived') === '1';

    var modalEl = document.getElementById('vehicle-modal');
    var modal = new bootstrap.Modal(modalEl);
    var form = document.getElementById('vehicle-form');
    var errorsBox = document.getElementById('vehicle-form-errors');
    var currentVehicleId = null;

    function csrfToken() {
        return csrfInput ? csrfInput.value : '';
    }

    function post(body) {
        body.append('csrf_token', csrfToken());
        return fetch(API, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); });
    }

    function refreshTable(html) {
        var tbody = document.getElementById('vehicle-table-body');
        tbody.innerHTML = html;
        if (window.vehicleDataTable) window.vehicleDataTable.refresh();
    }

    function showFormErrors(message) {
        errorsBox.textContent = message;
        errorsBox.classList.remove('d-none');
    }

    function clearFormErrors() {
        errorsBox.classList.add('d-none');
        errorsBox.textContent = '';
    }

    function resetForm() {
        form.reset();
        clearFormErrors();
        form.elements['action'].value = 'create';
        form.elements['vehicle_id'].value = '';
        currentVehicleId = null;
        document.getElementById('vehicle-modal-title').textContent = 'Add vehicle';
        document.getElementById('image-manager-target').innerHTML = '<p class="text-secondary small">Save the vehicle first, then add photos.</p>';
        document.getElementById('image-upload-row').classList.add('d-none');
        document.getElementById('vehicle-extra-info').innerHTML = '';
    }

    function fillForm(vehicle) {
        ['brand', 'model', 'year', 'plate_number', 'color', 'vehicle_type', 'transmission',
         'fuel_type', 'seating_capacity', 'mileage_km', 'daily_rate', 'status', 'description'].forEach(function (key) {
            if (form.elements[key]) form.elements[key].value = vehicle[key] ?? '';
        });
    }

    function renderExtraInfo(upcoming, maintenance) {
        var html = '';
        if (upcoming.length) {
            html += '<h3 class="h6 mt-3">Upcoming bookings</h3><ul class="list-unstyled small mb-0">';
            upcoming.forEach(function (b) {
                html += '<li class="mb-1"><span class="status-badge ' + statusClass(b.booking_status) + '">' + escapeHtml(b.booking_status) + '</span> '
                    + escapeHtml(b.customer_name) + ' &middot; ' + formatDate(b.pickup_datetime) + ' &rarr; ' + formatDate(b.return_datetime) + '</li>';
            });
            html += '</ul>';
        }
        if (maintenance.length) {
            html += '<h3 class="h6 mt-3">Maintenance history</h3><ul class="list-unstyled small mb-0">';
            maintenance.forEach(function (m) {
                html += '<li class="mb-1">' + formatDate(m.service_date) + ' &mdash; ' + escapeHtml(m.maintenance_type.replace(/_/g, ' ')) + '</li>';
            });
            html += '</ul>';
        }
        document.getElementById('vehicle-extra-info').innerHTML = html;
    }

    var statusClass = AutoWay.statusClass;
    var escapeHtml = AutoWay.escapeHtml;
    function formatDate(s) {
        // Short form (no year) — this list only shows near-future dates.
        var d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d) ? s : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    var addBtn = document.getElementById('add-vehicle-btn');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            resetForm();
            modal.show();
        });
    }

    document.getElementById('vehicle-table-body').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var id = btn.getAttribute('data-id');
        var action = btn.getAttribute('data-action');

        if (action === 'edit') {
            openEdit(id);
        } else if (action === 'archive') {
            AutoWay.confirm('Archive this vehicle? It will be hidden from the active fleet, but its booking history is kept.',
                { confirmText: 'Archive', confirmClass: 'btn-danger' }).then(function (ok) {
                if (!ok) return;
                var body = new FormData();
                body.append('action', 'archive');
                body.append('vehicle_id', id);
                post(body).then(function (r) {
                    if (!r.data.success) { AutoWay.toast(r.data.error, 'error'); return; }
                    refreshTable(r.data.table_html);
                    AutoWay.toast(r.data.message, 'success');
                });
            });
        } else if (action === 'restore') {
            var body = new FormData();
            body.append('action', 'restore');
            body.append('vehicle_id', id);
            body.append('archived', '1');
            post(body).then(function (r) {
                if (!r.data.success) { AutoWay.toast(r.data.error, 'error'); return; }
                refreshTable(r.data.table_html);
                AutoWay.toast(r.data.message, 'success');
            });
        }
    });

    function openEdit(id) {
        var body = new FormData();
        body.append('action', 'get');
        body.append('vehicle_id', id);
        post(body).then(function (r) {
            if (!r.data.success) { AutoWay.toast(r.data.error, 'error'); return; }
            resetForm();
            currentVehicleId = id;
            form.elements['action'].value = 'update';
            form.elements['vehicle_id'].value = id;
            document.getElementById('vehicle-modal-title').textContent = 'Edit vehicle';
            fillForm(r.data.vehicle);
            document.getElementById('image-manager-target').innerHTML = r.data.images_html;
            document.getElementById('image-upload-row').classList.remove('d-none');
            renderExtraInfo(r.data.upcoming, r.data.maintenance);
            modal.show();
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearFormErrors();
        var submitBtn = document.getElementById('vehicle-form-submit');
        submitBtn.disabled = true;

        post(new FormData(form)).then(function (r) {
            submitBtn.disabled = false;
            if (!r.data.success) {
                showFormErrors(r.data.error);
                return;
            }
            refreshTable(r.data.table_html);
            AutoWay.toast(r.data.message, 'success');

            if (form.elements['action'].value === 'create') {
                // Can't attach photos until the vehicle has an id — switch
                // straight into edit mode for the vehicle just created so
                // photos can be added without a second trip to the table.
                openEdit(r.data.new_id);
            } else {
                modal.hide();
            }
        });
    });

    // --- image manager: event delegation, since rows are replaced via AJAX ---
    document.getElementById('image-manager-target').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-image-action]');
        if (!btn) return;
        var imageAction = btn.getAttribute('data-image-action');
        var imageId = btn.getAttribute('data-image-id');

        if (imageAction === 'delete') {
            AutoWay.confirm('Delete this photo?', { confirmText: 'Delete', confirmClass: 'btn-danger' }).then(function (ok) {
                if (!ok) return;
                var body = new FormData();
                body.append('action', 'delete_image');
                body.append('image_id', imageId);
                post(body).then(function (r) {
                    if (!r.data.success) { AutoWay.toast(r.data.error, 'error'); return; }
                    document.getElementById('image-manager-target').innerHTML = r.data.images_html;
                });
            });
        } else if (imageAction === 'primary') {
            var body = new FormData();
            body.append('action', 'set_primary_image');
            body.append('vehicle_id', currentVehicleId);
            body.append('image_id', imageId);
            post(body).then(function (r) {
                if (!r.data.success) { AutoWay.toast(r.data.error, 'error'); return; }
                document.getElementById('image-manager-target').innerHTML = r.data.images_html;
            });
        }
    });

    document.getElementById('image-upload-input').addEventListener('change', function () {
        var file = this.files[0];
        if (!file) return;
        var body = new FormData();
        body.append('action', 'upload_image');
        body.append('vehicle_id', currentVehicleId);
        body.append('image', file);
        post(body).then(function (r) {
            this.value = ''; // allow re-selecting the same file later
            if (!r.data.success) { AutoWay.toast(r.data.error, 'error'); return; }
            document.getElementById('image-manager-target').innerHTML = r.data.images_html;
            AutoWay.toast('Photo added.', 'success');
        }.bind(this));
    });

    window.vehicleDataTable = AutoWay.initDataTable(table, {
        searchInput: document.getElementById('vehicle-search'),
        filters: [
            { el: document.getElementById('vehicle-filter-status'), attr: 'status' },
            { el: document.getElementById('vehicle-filter-type'), attr: 'type' },
        ],
        pagerEl: document.getElementById('vehicle-pager'),
        emptyMessage: isArchivedView ? 'No archived vehicles match your search or filters.' : 'No vehicles match your search or filters.',
    });
})();
