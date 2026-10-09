<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/desk_partial.php';

$user = require_role('staff');

$releases = release_queue();
$counts   = desk_counts($releases, return_queue());

$page_title   = 'Release a vehicle';
$active_nav   = 'checkout';
$page_scripts = [BASE_URL . 'assets/js/datatable.js', BASE_URL . 'assets/js/desk.js'];

require __DIR__ . '/../includes/header.php';
?>

<p class="text-secondary mb-3">Pickups for today and tomorrow, late ones first. <strong>Release</strong> opens the booking with the
handover form ready: check the license, note the odometer, fuel, and condition. Cars can be released from
<?= EARLY_CHECKOUT_MINUTES / 60 ?> hours before pickup.</p>

<section class="panel">
    <div class="table-toolbar">
        <input type="search" class="form-control form-control-sm search-box" id="desk-search" placeholder="Search reference, customer, car, or plate&hellip;" aria-label="Search pickups">
        <select class="form-select form-select-sm filter-select" id="desk-filter-day" aria-label="Filter by day">
            <option value="">Today and tomorrow</option>
            <option value="today">Today (<?= $counts['pickups_today'] ?>)</option>
            <option value="tomorrow">Tomorrow</option>
        </select>
        <select class="form-select form-select-sm filter-select" id="desk-filter-ready" aria-label="Filter by readiness">
            <option value="">Any readiness</option>
            <option value="yes">Ready now (<?= $counts['ready_now'] ?>)</option>
            <option value="no">Needs attention or not yet</option>
        </select>
    </div>
    <div class="panel-body no-pad">
        <table class="data-table" id="desk-table" data-datatable data-page-size="15">
            <thead>
                <tr>
                    <th data-sort="text">Pickup</th>
                    <th data-sort="text">Customer</th>
                    <th data-sort="text">Vehicle</th>
                    <th>Booking</th>
                    <th>Before release</th>
                    <th class="text-end"></th>
                </tr>
            </thead>
            <tbody><?= render_release_rows($releases) ?></tbody>
        </table>
    </div>
    <div class="dt-pager" id="desk-pager"></div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
