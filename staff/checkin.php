<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/desk_partial.php';

$user = require_role('staff');

$returns = return_queue();
$counts  = desk_counts([], $returns);

$page_title   = 'Receive a return';
$active_nav   = 'checkin';
$page_scripts = [BASE_URL . 'assets/js/datatable.js', BASE_URL . 'assets/js/desk.js'];

require __DIR__ . '/../includes/header.php';
?>

<p class="text-secondary mb-3">Every car out on rental, most overdue first. <strong>Receive</strong> opens the booking with the
return form ready: the late, fuel, and damage charges are previewed before you save.</p>

<section class="panel">
    <div class="table-toolbar">
        <input type="search" class="form-control form-control-sm search-box" id="desk-search" placeholder="Search reference, customer, car, or plate&hellip;" aria-label="Search rentals">
        <select class="form-select form-select-sm filter-select" id="desk-filter-day" aria-label="Filter by when it's due">
            <option value="">All rentals out (<?= $counts['out_now'] ?>)</option>
            <option value="overdue">Overdue (<?= $counts['overdue'] ?>)</option>
            <option value="today">Due today</option>
            <option value="later">Due later</option>
        </select>
    </div>
    <div class="panel-body no-pad">
        <table class="data-table" id="desk-table" data-datatable data-page-size="15">
            <thead>
                <tr>
                    <th data-sort="text">Due back</th>
                    <th data-sort="text">Customer</th>
                    <th data-sort="text">Vehicle</th>
                    <th>Booking</th>
                    <th data-sort="number" class="text-end">Unpaid</th>
                    <th class="text-end"></th>
                </tr>
            </thead>
            <tbody><?= render_return_rows($returns) ?></tbody>
        </table>
    </div>
    <div class="dt-pager" id="desk-pager"></div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
