<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard_data.php';
require_once __DIR__ . '/../includes/payments_data.php'; // shared payment labels

$user = require_role('admin');

$fleet    = fleet_stats();
$revenue  = revenue_summary();
$trend    = revenue_last_days(7);
$payments = recent_payments(6);
$returns  = upcoming_returns(6);
$active_bookings = active_booking_count();

$hour     = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$first_name = explode(' ', trim($user['full_name']))[0];

// Payment type -> the shared five-color status language.

// Chart.js gets everything it needs as one JSON blob; the JSON_HEX_* flags
// make it safe to embed inside a <script> block.
$chart_data = [
    'revenue' => $trend,
    'fleet'   => [
        'labels' => ['Available', 'Rented', 'Maintenance', 'Unavailable'],
        'values' => [$fleet['available'] + $fleet['reserved'], $fleet['rented'], $fleet['maintenance'], $fleet['unavailable']],
    ],
];

$page_title = 'Dashboard';
$active_nav = 'dashboard';
$page_scripts = [
    BASE_URL . 'assets/vendor/chartjs/chart.umd.min.js',
    BASE_URL . 'assets/js/dashboard-charts.js',
];

require __DIR__ . '/../includes/header.php';
?>

<script type="application/json" id="dashboard-data"><?= json_encode($chart_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<p class="text-secondary mb-4"><?= e($greeting) ?>, <?= e($first_name) ?>. Here is the fleet as of <?= e(date('g:i A, l F j')) ?>.</p>

<section class="stat-grid" aria-label="Fleet">
    <div class="stat-tile" style="--tile-accent: var(--text-muted)">
        <div class="stat-label">Vehicles in the fleet</div>
        <div class="stat-value"><?= $fleet['total'] ?></div>
    </div>
    <div class="stat-tile" style="--tile-accent: var(--success)">
        <div class="stat-label">Available now</div>
        <div class="stat-value"><?= $fleet['available'] ?></div>
    </div>
    <div class="stat-tile" style="--tile-accent: var(--info)">
        <div class="stat-label">Out on rental</div>
        <div class="stat-value"><?= $fleet['rented'] ?></div>
    </div>
    <div class="stat-tile" style="--tile-accent: var(--warning)">
        <div class="stat-label">In maintenance</div>
        <div class="stat-value"><?= $fleet['maintenance'] ?></div>
    </div>
</section>

<section class="stat-grid" aria-label="Bookings and revenue">
    <div class="stat-tile" style="--tile-accent: var(--info)">
        <div class="stat-label">Active bookings</div>
        <div class="stat-value"><?= $active_bookings ?></div>
        <div class="stat-sub">Confirmed or in progress</div>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Revenue today</div>
        <div class="stat-value stat-money"><?= e(money($revenue['today'])) ?></div>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Revenue this month</div>
        <div class="stat-value stat-money"><?= e(money($revenue['this_month'])) ?></div>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Revenue all time</div>
        <div class="stat-value stat-money"><?= e(money($revenue['all_time'])) ?></div>
        <div class="stat-sub">Security deposits not included</div>
    </div>
</section>

<div class="panel-grid-2">
    <section class="panel">
        <div class="panel-header">Revenue, last 7 days</div>
        <div class="panel-body"><div class="chart-box"><canvas id="revenue-chart" role="img" aria-label="Bar chart of daily revenue for the last 7 days"></canvas></div></div>
    </section>

    <section class="panel">
        <div class="panel-header">Fleet status</div>
        <div class="panel-body">
            <?php if ($fleet['total'] > 0): ?>
                <div class="chart-box"><canvas id="fleet-chart" role="img" aria-label="Doughnut chart of vehicles by status"></canvas></div>
            <?php else: ?>
                <p class="text-secondary mb-0">No vehicles yet. Once vehicles are added, their status breakdown shows up here.</p>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="panel-grid-2">
    <section class="panel">
        <div class="panel-header">Recent payments</div>
        <div class="panel-body no-pad">
            <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr><th>Customer</th><th>For</th><th>Type</th><th class="text-end">Amount</th></tr>
                </thead>
                <tbody>
                <?php if (!$payments): ?>
                    <tr class="empty-row"><td colspan="4">No payments recorded yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td>
                            <?= e($p['customer_name']) ?>
                            <div class="cell-sub"><?= e(date('M j, g:i A', strtotime($p['paid_at']))) ?> by <?= e(PAYMENT_METHOD_LABELS[$p['payment_method']] ?? '—') ?></div>
                        </td>
                        <td><span class="cell-name"><?= e($p['brand'] . ' ' . $p['model']) ?></span></td>
                        <td><span class="status-badge <?= e(payment_type_badge($p)) ?>"><?= e(payment_type_label($p)) ?></span></td>
                        <td class="text-end mono nowrap<?= $p['payment_type'] === 'refund' ? ' amount-out' : '' ?>">
                            <a href="<?= e(BASE_URL) ?>print/receipt.php?id=<?= (int) $p['payment_id'] ?>" target="_blank" rel="noopener" class="text-reset text-decoration-none" title="Receipt <?= e($p['receipt_number']) ?>">
                                <?= $p['payment_type'] === 'refund' ? '−' : '' ?><?= e(money($p['amount'])) ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">Vehicles due back</div>
        <div class="panel-body no-pad">
            <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr><th>Vehicle</th><th>Customer</th><th>Due</th></tr>
                </thead>
                <tbody>
                <?php if (!$returns): ?>
                    <tr class="empty-row"><td colspan="3">No vehicles are out on rental right now.</td></tr>
                <?php endif; ?>
                <?php foreach ($returns as $r): ?>
                    <tr>
                        <td>
                            <?= e($r['brand'] . ' ' . $r['model']) ?>
                            <div class="cell-sub"><span class="plate"><?= e($r['plate_number']) ?></span></div>
                        </td>
                        <td><?= e($r['customer_name']) ?></td>
                        <td>
                            <?= e(date('M j, g:i A', strtotime($r['return_datetime']))) ?>
                            <?php if ($r['is_overdue']): ?>
                                <div><span class="status-badge status-danger">overdue</span></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </section>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
