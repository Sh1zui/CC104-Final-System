/*
 * Staff desk queues (staff/checkout.php, staff/checkin.php): search and
 * filters over the server-rendered rows. Every action is a plain link into
 * the booking window, so there's nothing else to wire up here.
 */
(function () {
    'use strict';
    var table = document.getElementById('desk-table');
    if (!table) return;
    var filters = [];
    var day = document.getElementById('desk-filter-day');
    var ready = document.getElementById('desk-filter-ready');
    if (day) filters.push({ el: day, attr: 'day' });
    if (ready) filters.push({ el: ready, attr: 'ready' });
    AutoWay.initDataTable(table, {
        searchInput: document.getElementById('desk-search'),
        filters: filters,
        pagerEl: document.getElementById('desk-pager'),
        emptyMessage: 'Nothing matches your search or filters.'
    });
})();
