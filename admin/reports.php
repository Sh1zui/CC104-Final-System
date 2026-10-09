<?php
/**
 * Reports (Phase 12): revenue, fleet use, bookings, customers, maintenance
 * over a chosen period, plus what's owed right now. Admin only. Each table
 * exports to CSV through admin/report_export.php with the same range.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports_data.php';

$user = require_role('admin');

$range    = report_range($_GET);
$revenue  = revenue_report($range);
$vehicles = vehicle_report($range);
$util     = fleet_utilization($vehicles, $range);
$bookings = booking_report($range);
$days_sold = rental_days_sold($range);
$customers = customer_report($range);
$maint    = maintenance_report($range);
$owed     = receivables_now();

$range_query = ['range' => $range['preset']] + ($range['preset'] === 'custom'
    ? ['from' => $range['from']->format('Y-m-d'), 'to' => $range['to']->format('Y-m-d')] : []);
$export = fn (string $report) => BASE_URL . 'admin/report_export.php?' . http_build_query(['report' => $report] + $range_query);

// Chart data, keyed the same way as the tables.
$booking_series = ['desk' => [], 'online' => []];
foreach ($revenue['keys'] as $k) {
    $booking_series['desk'][] = $bookings['series'][$k]['desk'] ?? 0;
    $booking_series['online'][] = $bookings['series'][$k]['online'] ?? 0;
}
$chart_data = [
    'labels'      => $revenue['labels'],
    'granularity' => $revenue['granularity'],
    'revenue'     => $revenue['revenue'],
    'bookings'    => $booking_series,
];

$elapsed_note = $range['to'] >= new DateTimeImmutable('today') ? ' (so far)' : '';
$maint_total = array_sum(array_column($maint, 'cost'));

$page_title   = 'Reports';
$active_nav   = 'reports';
$page_scripts = [
    BASE_URL . 'assets/vendor/chartjs/chart.umd.min.js',
    BASE_URL . 'assets/js/reports.js',
];

require __DIR__ . '/../includes/header.php';
?>

<form class="report-filters" method="get" id="report-filters">
    <div>
        <label class="form-label" for="r-range">Period</label>
        <select class="form-select" id="r-range" name="range">
            <?php foreach (REPORT_PRESETS as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $range['preset'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="custom-dates<?= $range['preset'] === 'custom' ? '' : ' d-none' ?>" id="r-custom">
        <div>
            <label class="form-label" for="r-from">From</label>
            <input type="date" class="form-control" id="r-from" name="from" value="<?= e($range['from']->format('Y-m-d')) ?>">
        </div>
        <div>
            <label class="form-label" for="r-to">To</label>
            <input type="date" class="form-control" id="r-to" name="to" value="<?= e($range['to']->format('Y-m-d')) ?>">
        </div>
    </div>
    <div class="report-filters-go">
        <button type="submit" class="btn btn-brand">Show</button>
    </div>
    <div class="report-range-label"><?= e($range['label']) ?></div>
</form>

<?php if ($range['error']): ?>
    <div class="alert alert-warning" role="status"><?= e($range['error']) ?></div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat-tile" style="--tile-accent: var(--accent)">
        <div class="stat-label">Revenue</div>
        <div class="stat-value stat-money"><?= e(money($revenue['totals']['revenue'])) ?></div>
        <div class="stat-sub">Earned charges, deposits kept, less refunds</div>
    </div>
    <div class="stat-tile" style="--tile-accent: var(--info)">
        <div class="stat-label">Rental days sold</div>
        <div class="stat-value"><?= number_format($days_sold) ?></div>
        <div class="stat-sub">On rentals that went out in the period</div>
    </div>
    <div class="stat-tile" style="--tile-accent: var(--warning)">
        <div class="stat-label">Fleet utilization</div>
        <div class="stat-value"><?= e(number_format($util, 1)) ?>%</div>
        <div class="stat-sub">Time cars were out<?= e($elapsed_note) ?></div>
    </div>
    <div class="stat-tile" style="--tile-accent: var(--neutral)">
        <div class="stat-label">Bookings made</div>
        <div class="stat-value"><?= $bookings['total'] ?></div>
        <div class="stat-sub"><?= $bookings['source']['online'] ?> online &middot; <?= e(number_format($bookings['cancel_rate'], 1)) ?>% cancelled or no-show</div>
    </div>
</div>

<script type="application/json" id="report-data"><?= json_encode($chart_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<section class="panel">
    <div class="panel-header">Revenue by <?= $revenue['granularity'] ?>
        <a class="panel-link" href="<?= e($export('revenue')) ?>"><i class="bi bi-download me-1"></i>CSV</a></div>
    <div class="panel-body">
        <div class="chart-box"><canvas id="revenue-chart" role="img" aria-label="Bar chart of revenue by <?= $revenue['granularity'] ?>, <?= e($range['label']) ?>"></canvas></div>
        <dl class="money-breakdown">
            <div><dt>Rental fees</dt><dd class="mono"><?= e(money($revenue['totals']['rental_fees'])) ?></dd></div>
            <div><dt>Late, damage, other charges</dt><dd class="mono"><?= e(money($revenue['totals']['other_charges'])) ?></dd></div>
            <div><dt>Deposits kept</dt><dd class="mono"><?= e(money($revenue['totals']['deposits_kept'])) ?></dd></div>
            <div><dt>Money in</dt><dd class="mono"><?= e(money($revenue['totals']['money_in'])) ?></dd></div>
            <div><dt>Money out (refunds)</dt><dd class="mono"><?= e(money($revenue['totals']['money_out'])) ?></dd></div>
            <div><dt>Deposits taken / returned</dt><dd class="mono"><?= e(money($revenue['totals']['deposits_taken'])) ?> / <?= e(money($revenue['totals']['deposits_returned'])) ?></dd></div>
        </dl>
    </div>
</section>

<div class="panel-grid-2">
    <section class="panel">
        <div class="panel-header">Bookings made, desk vs online
            <a class="panel-link" href="<?= e($export('bookings')) ?>"><i class="bi bi-download me-1"></i>CSV</a></div>
        <div class="panel-body">
            <div class="chart-box chart-box-sm"><canvas id="bookings-chart" role="img" aria-label="Bookings made per <?= $revenue['granularity'] ?>, desk and online"></canvas></div>
        </div>
    </section>
    <section class="panel">
        <div class="panel-header">Where those bookings stand</div>
        <div class="panel-body no-pad">
            <table class="data-table fit">
                <tbody>
                <?php foreach ($bookings['by_status'] as $status => $n): ?>
                    <tr><td><span class="status-badge <?= e(status_badge_class($status)) ?>"><?= e(humanize($status)) ?></span></td>
                        <td class="mono text-end"><?= $n ?></td></tr>
                <?php endforeach; ?>
                <tr><td class="text-secondary">Average length</td><td class="mono text-end"><?= e(number_format($bookings['avg_days'], 1)) ?> days</td></tr>
                <tr><td class="text-secondary">Average value (before deposit)</td><td class="mono text-end"><?= e(money($bookings['avg_value'])) ?></td></tr>
                </tbody>
            </table>
        </div>
    </section>
</div>

<section class="panel">
    <div class="panel-header">Vehicles
        <span class="d-flex align-items-center gap-3"><span class="text-secondary small fw-normal">utilization = time out on rental<?= e($elapsed_note) ?></span>
        <a class="panel-link" href="<?= e($export('vehicles')) ?>"><i class="bi bi-download me-1"></i>CSV</a></span></div>
    <div class="panel-body no-pad">
        <table class="data-table">
            <thead><tr><th>Vehicle</th><th class="text-end">Rentals</th><th>Utilization</th><th class="text-end">Revenue</th>
                <th class="text-end">Maintenance</th><th class="text-end">Revenue less maintenance</th></tr></thead>
            <tbody>
            <?php foreach ($vehicles as $v): ?>
                <tr>
                    <td><span class="cell-name"><?= e($v['brand'] . ' ' . $v['model']) ?></span><div class="cell-sub"><span class="plate"><?= e($v['plate_number']) ?></span>
                        <?= $v['deleted_at'] ? ' archived' : '' ?></div></td>
                    <td class="mono text-end"><?= $v['rentals'] ?></td>
                    <td class="util-cell">
                        <div class="util-bar" role="img" aria-label="<?= e(number_format($v['utilization'], 1)) ?> percent"><span style="width: <?= e((string) $v['utilization']) ?>%"></span></div>
                        <span class="mono small"><?= e(number_format($v['utilization'], 1)) ?>%</span>
                        <div class="cell-sub"><?= e(number_format($v['days_out'], 1)) ?> days out</div>
                    </td>
                    <td class="mono nowrap text-end"><?= e(money($v['revenue'])) ?></td>
                    <td class="mono nowrap text-end"><?= $v['maintenance_cost'] > 0 ? e(money($v['maintenance_cost'])) : '<span class="text-secondary">—</span>' ?>
                        <?= $v['jobs'] ? '<div class="cell-sub">' . $v['jobs'] . ' job' . ($v['jobs'] === 1 ? '' : 's') . '</div>' : '' ?></td>
                    <td class="mono nowrap text-end<?= $v['net'] < 0 ? ' text-danger' : '' ?>"><?= e(money($v['net'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="panel-grid-2">
    <section class="panel">
        <div class="panel-header">Top customers
            <a class="panel-link" href="<?= e($export('customers')) ?>"><i class="bi bi-download me-1"></i>CSV</a></div>
        <div class="panel-body no-pad">
            <table class="data-table fit">
                <thead><tr><th>Customer</th><th class="text-end">Bookings</th><th class="text-end">Revenue</th></tr></thead>
                <tbody>
                <?php if (!$customers): ?><tr class="empty-row"><td colspan="3">No revenue in this period.</td></tr><?php endif; ?>
                <?php foreach ($customers as $c): ?>
                    <tr><td><a href="<?= e(BASE_URL . 'admin/customers.php?open=' . (int) $c['customer_id']) ?>"><?= e($c['full_name']) ?></a></td>
                        <td class="mono text-end"><?= $c['bookings'] ?></td>
                        <td class="mono nowrap text-end"><?= e(money($c['revenue'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">Maintenance by type of work
            <a class="panel-link" href="<?= e($export('maintenance')) ?>"><i class="bi bi-download me-1"></i>CSV</a></div>
        <div class="panel-body no-pad">
            <table class="data-table fit">
                <thead><tr><th>Work</th><th class="text-end">Jobs</th><th class="text-end">Days</th><th class="text-end">Cost</th></tr></thead>
                <tbody>
                <?php if (!$maint): ?><tr class="empty-row"><td colspan="4">No maintenance finished in this period.</td></tr><?php endif; ?>
                <?php foreach ($maint as $m): ?>
                    <tr><td><?= e(humanize($m['maintenance_type'])) ?></td><td class="mono text-end"><?= $m['jobs'] ?></td>
                        <td class="mono text-end"><?= $m['days'] ?></td><td class="mono nowrap text-end"><?= e(money($m['cost'])) ?></td></tr>
                <?php endforeach; ?>
                <?php if ($maint): ?>
                    <tr class="total-row"><td>Total</td><td class="mono text-end"><?= array_sum(array_column($maint, 'jobs')) ?></td>
                        <td class="mono text-end"><?= array_sum(array_column($maint, 'days')) ?></td><td class="mono nowrap text-end"><?= e(money($maint_total)) ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<section class="panel">
    <div class="panel-header">Owed right now <span class="d-flex align-items-center gap-3"><span class="text-secondary small fw-normal">as of <?= e(date('M j, g:i A')) ?>, whatever the period</span>
        <a class="panel-link" href="<?= e($export('receivables')) ?>"><i class="bi bi-download me-1"></i>CSV</a></span></div>
    <div class="panel-body">
        <dl class="money-breakdown money-breakdown-3">
            <div><dt>Customers owe us</dt><dd class="mono"><?= e(money($owed['total_owed'])) ?></dd><dd class="cell-sub"><?= count($owed['owed_to_us']) ?> booking<?= count($owed['owed_to_us']) === 1 ? '' : 's' ?></dd></div>
            <div><dt>We owe customers</dt><dd class="mono"><?= e(money($owed['total_owed_back'])) ?></dd><dd class="cell-sub"><?= count($owed['owed_back']) ?> booking<?= count($owed['owed_back']) === 1 ? '' : 's' ?> to settle</dd></div>
            <div><dt>Deposits held</dt><dd class="mono"><?= e(money($owed['deposits_held'])) ?></dd><dd class="cell-sub">a liability, not revenue</dd></div>
        </dl>
        <?php if ($owed['owed_to_us'] || $owed['owed_back']): ?>
            <p class="small mb-0 mt-2"><a href="<?= e(BASE_URL) ?>admin/payments.php">Collect or settle on the Payments page &rarr;</a></p>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
