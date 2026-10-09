/*
 * Client-side search + filter + column sort + pagination for a <table>.
 * Works on whatever rows the server already rendered — there's no second
 * network round-trip just to look at data that's already on the page.
 *
 * Expects:
 *   <table data-datatable data-page-size="10">
 *     <thead><tr><th data-sort="text|number">Label</th>...
 *     <tbody><tr data-search="brand model plate" data-status="available">...
 *
 * A page wires it up with:
 *   AutoWay.initDataTable(tableEl, {
 *       searchInput: el,
 *       filters: [{ el: selectEl, attr: 'status' }],   // matches tr[data-status]
 *       pagerEl: el,
 *       emptyMessage: '...'
 *   })
 *
 * Rows are re-read from <tbody> on every render, so a page may replace
 * tbody.innerHTML after an AJAX call and simply call .refresh().
 * Server-rendered placeholder rows (tr.empty-row) are never treated as data.
 */
(function () {
    'use strict';

    function initDataTable(table, opts) {
        opts = opts || {};
        var tbody = table.tBodies[0];
        var filters = opts.filters || [];
        var pageSize = parseInt(table.getAttribute('data-page-size'), 10) || 10;
        var state = { search: '', sortCol: null, sortDir: 1, page: 1 };

        function dataRows() {
            return Array.prototype.filter.call(tbody.rows, function (r) {
                return !r.classList.contains('empty-row');
            });
        }

        function matches(row) {
            for (var i = 0; i < filters.length; i++) {
                var want = filters[i].el.value;
                // An attribute may hold several space-separated tags
                // (e.g. data-payment="partial to_check"); any one matches.
                if (want && (' ' + (row.getAttribute('data-' + filters[i].attr) || '') + ' ').indexOf(' ' + want + ' ') === -1) return false;
            }
            if (!state.search) return true;
            var haystack = (row.getAttribute('data-search') || row.textContent).toLowerCase();
            return haystack.indexOf(state.search) !== -1;
        }

        function render() {
            var all = dataRows();
            var rows = all.filter(matches);

            if (state.sortCol !== null) {
                var th = table.tHead.rows[0].cells[state.sortCol];
                var type = th.getAttribute('data-sort') || 'text';
                rows.sort(function (a, b) {
                    var av = a.cells[state.sortCol].getAttribute('data-value') || a.cells[state.sortCol].textContent.trim();
                    var bv = b.cells[state.sortCol].getAttribute('data-value') || b.cells[state.sortCol].textContent.trim();
                    if (type === 'number') { av = parseFloat(av) || 0; bv = parseFloat(bv) || 0; return (av - bv) * state.sortDir; }
                    return av.localeCompare(bv) * state.sortDir;
                });
                // Re-append in sorted order so DOM order matches what's shown.
                rows.forEach(function (r) { tbody.appendChild(r); });
            }

            var totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
            state.page = Math.max(1, Math.min(state.page, totalPages));
            var start = (state.page - 1) * pageSize;
            var pageRows = rows.slice(start, start + pageSize);

            all.forEach(function (r) { r.style.display = 'none'; });
            pageRows.forEach(function (r) { r.style.display = ''; });

            // Only show our own "no matches" row when data exists but nothing
            // matches; a server placeholder row already covers the zero-data case.
            var emptyRow = tbody.querySelector('.dt-empty-row');
            var serverPlaceholder = all.length === 0 && tbody.rows.length > 0;
            if (rows.length === 0 && !serverPlaceholder) {
                if (!emptyRow) {
                    emptyRow = document.createElement('tr');
                    emptyRow.className = 'empty-row dt-empty-row';
                    var td = document.createElement('td');
                    td.colSpan = table.tHead.rows[0].cells.length;
                    td.textContent = opts.emptyMessage || 'No matching results.';
                    emptyRow.appendChild(td);
                }
                tbody.appendChild(emptyRow);
                emptyRow.style.display = '';
            } else if (emptyRow) {
                emptyRow.parentNode.removeChild(emptyRow);
            }

            renderPager(totalPages, rows.length);
        }

        function renderPager(totalPages, totalRows) {
            var pager = opts.pagerEl;
            if (!pager) return;

            if (totalRows === 0) { pager.innerHTML = ''; return; }

            var from = (state.page - 1) * pageSize + 1;
            var to = Math.min(state.page * pageSize, totalRows);
            var html = '<span class="dt-summary">' + from + '–' + to + ' of ' + totalRows + '</span>';
            html += '<div class="dt-pager-buttons">';
            html += '<button type="button" class="btn btn-sm btn-outline-secondary" data-page="' + (state.page - 1) + '"' + (state.page <= 1 ? ' disabled' : '') + '>Prev</button>';
            html += '<span class="dt-page-label">Page ' + state.page + ' of ' + totalPages + '</span>';
            html += '<button type="button" class="btn btn-sm btn-outline-secondary" data-page="' + (state.page + 1) + '"' + (state.page >= totalPages ? ' disabled' : '') + '>Next</button>';
            html += '</div>';
            pager.innerHTML = html;

            pager.querySelectorAll('[data-page]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    state.page = parseInt(btn.getAttribute('data-page'), 10);
                    render();
                });
            });
        }

        if (opts.searchInput) {
            opts.searchInput.addEventListener('input', function () {
                state.search = opts.searchInput.value.trim().toLowerCase();
                state.page = 1;
                render();
            });
        }

        filters.forEach(function (f) {
            f.el.addEventListener('change', function () {
                state.page = 1;
                render();
            });
        });

        Array.prototype.forEach.call(table.tHead.rows[0].cells, function (th, index) {
            if (!th.hasAttribute('data-sort')) return;
            th.classList.add('sortable');
            th.setAttribute('tabindex', '0');
            function toggle() {
                if (state.sortCol === index) {
                    state.sortDir *= -1;
                } else {
                    state.sortCol = index;
                    state.sortDir = 1;
                }
                Array.prototype.forEach.call(table.tHead.rows[0].cells, function (c) { c.classList.remove('sort-asc', 'sort-desc'); });
                th.classList.add(state.sortDir === 1 ? 'sort-asc' : 'sort-desc');
                render();
            }
            th.addEventListener('click', toggle);
            th.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); toggle(); }
            });
        });

        render();

        return {
            /** Re-render after tbody content changed. Optionally pass new <tr> nodes. */
            refresh: function (newRows) {
                if (newRows) {
                    tbody.innerHTML = '';
                    newRows.forEach(function (r) { tbody.appendChild(r); });
                }
                render();
            }
        };
    }

    window.AutoWay = window.AutoWay || {};
    window.AutoWay.initDataTable = initDataTable;
})();
