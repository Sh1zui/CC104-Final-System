<?php
/**
 * Payments ledger: every receipt (money in, refunds out, deposit kept),
 * what still needs collecting or settling, and the cash position.
 * Recording and settling happen in the booking window (admin/bookings.php),
 * where the full booking context is; every row here links there.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments_data.php';
require_once __DIR__ . '/../includes/portal_partial.php';

$user = require_role('admin');
$online_waiting = open_submissions();

$totals   = payment_totals();
$payments = list_payments();
$needing  = bookings_needing_settlement();

$today = date('Y-m-d');
$week_start = date('Y-m-d', strtotime('monday this week'));
$month_start = date('Y-m-01');
function payment_when(string $paid_at, string $today, string $week_start, string $month_start): string
{
    $day = substr($paid_at, 0, 10);
    if ($day === $today) {
        return 'today';
    }
    if ($day >= $week_start) {
        return 'week';
    }
    return $day >= $month_start ? 'month' : 'older';
}

$page_title   = 'Payments';
$active_nav   = 'payments';
$page_scripts = [BASE_URL . 'assets/js/datatable.js'];
$page_inline_js = <<<'JS'
AutoWay.initDataTable(document.getElementById('payment-table'), {
    searchInput: document.getElementById('payment-search'),
    filters: [
        { el: document.getElementById('payment-filter-kind'), attr: 'kind' },
        { el: document.getElementById('payment-filter-method'), attr: 'method' },
        { el: document.getElementById('payment-filter-when'), attr: 'when' }
    ],
    pagerEl: document.getElementById('payment-pager'),
    emptyMessage: 'No payments match your search or filters.'
});
JS;

require __DIR__ . '/../includes/header.php';
?>

<div class="stat-grid">
    <div class="stat-tile" style="--tile-accent: var(--success)">
        <div class="stat-label">Collected today</div>
        <div class="stat-value stat-money"><?= e(money($totals['net_today'])) ?></div>
        <div class="stat-sub">Money in, less refunds</div>
    </div>
    <div class="stat-tile" style="--tile-accent: var(--success)">
        <div class="stat-label">Collected this month</div>
        <div class="stat-value stat-money"><?= e(money($totals['net_month'])) ?></div>
        <div class="stat-sub">Since <?= e(date('M 1')) ?></div>
    </div>
    <div class="stat-tile" style="--tile-accent: var(--neutral)">
        <div class="stat-label">Refunded this month</div>
        <div class="stat-value stat-money"><?= e(money($totals['refunded_month'])) ?></div>
        <div class="stat-sub">Deposits and overpayments</div>
    </div>
    <div class="stat-tile" style="--tile-accent: var(--info)">
        <div class="stat-label">Deposits held</div>
        <div class="stat-value stat-money"><?= e(money($totals['deposits_held'])) ?></div>
        <div class="stat-sub">Owed back unless kept at settlement</div>
    </div>
</div>

<?php if ($online_waiting): ?>
<section class="panel">
    <div class="panel-header">Online payments to check
        <span class="text-secondary small fw-normal">customers say they sent these by GCash or bank transfer</span></div>
    <div class="panel-body no-pad">
        <table class="data-table">
            <thead><tr><th>Customer</th><th>Sent by</th><th class="text-end">Amount</th><th></th></tr></thead>
            <tbody><?= render_open_submission_rows($online_waiting) ?></tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="panel">
    <div class="panel-header">
        Needs attention
        <span class="text-secondary small fw-normal"><?= count($needing) ?> booking<?= count($needing) === 1 ? '' : 's' ?></span>
    </div>
    <div class="panel-body no-pad">
        <table class="data-table">
            <thead>
                <tr><th>Booking</th><th>Customer</th><th>Status</th><th>What's needed</th><th class="text-end">Amount</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (!$needing): ?>
                <tr class="empty-row"><td colspan="6">Nothing to collect or settle. Every closed booking is squared up.</td></tr>
            <?php endif; ?>
            <?php foreach ($needing as $b): ?>
                <?php
                if ($b['balance_due'] > 0) {
                    [$need, $amount, $cls] = ['Collect balance', $b['balance_due'], ''];
                } elseif ($b['refund_due'] > 0) {
                    [$need, $amount, $cls] = ['Settle and refund', $b['refund_due'], 'amount-out'];
                } else {
                    [$need, $amount, $cls] = ['Settle deposit (keep against charges)', $b['deposit_held'], ''];
                }
                ?>
                <tr>
                    <td class="mono fw-semibold nowrap"><?= e($b['booking_reference']) ?></td>
                    <td><?= e($b['customer_name']) ?><div class="cell-sub"><?= e($b['brand'] . ' ' . $b['model']) ?></div></td>
                    <td><span class="status-badge <?= e(status_badge_class($b['booking_status'])) ?>"><?= e(humanize($b['booking_status'])) ?></span></td>
                    <td><?= e($need) ?></td>
                    <td class="text-end mono nowrap <?= $cls ?>"><?= e(money($amount)) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(BASE_URL) ?>admin/bookings.php?open=<?= (int) $b['booking_id'] ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <div class="table-toolbar">
        <input type="search" class="form-control form-control-sm search-box" id="payment-search" placeholder="Search receipt, booking, customer, or reference&hellip;" aria-label="Search payments">
        <select class="form-select form-select-sm filter-select" id="payment-filter-kind" aria-label="Filter by kind">
            <option value="">All entries</option>
            <option value="in">Money in</option>
            <option value="out">Refunds</option>
            <option value="kept">Deposit kept</option>
            <option value="void">Voided</option>
        </select>
        <select class="form-select form-select-sm filter-select" id="payment-filter-method" aria-label="Filter by method">
            <option value="">Any method</option>
            <?php foreach (PAYMENT_METHOD_LABELS as $m => $label): ?>
                <option value="<?= e($m) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm filter-select" id="payment-filter-when" aria-label="Filter by date">
            <option value="">Any date</option>
            <option value="today">Today</option>
            <option value="week">This week</option>
            <option value="month">This month</option>
            <option value="older">Earlier</option>
        </select>
    </div>
    <div class="panel-body no-pad">
        <table class="data-table" id="payment-table" data-datatable data-page-size="15">
            <thead>
                <tr>
                    <th data-sort="text">Date</th>
                    <th data-sort="text">Receipt</th>
                    <th data-sort="text">Booking</th>
                    <th data-sort="text">For</th>
                    <th data-sort="text">Method</th>
                    <th data-sort="number" class="text-end">Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$payments): ?>
                <tr class="empty-row"><td colspan="6">No payments recorded yet. Record one from a booking's window.</td></tr>
            <?php endif; ?>
            <?php foreach ($payments as $p): ?>
                <?php
                $void = $p['status'] !== 'completed';
                $kind = $void ? 'void' : ($p['is_money_out'] ? 'out' : ($p['is_cashless'] ? 'kept' : 'in'));
                $signed = $p['is_money_out'] ? -$p['amount'] : $p['amount'];
                $search = strtolower(implode(' ', [$p['receipt_number'], $p['booking_reference'], $p['customer_name'], $p['transaction_ref'] ?? '', $p['plate_number']]));
                ?>
                <tr data-search="<?= e($search) ?>" data-kind="<?= $kind ?>" data-method="<?= e($p['payment_method'] ?? '') ?>"
                    data-when="<?= payment_when($p['paid_at'], $today, $week_start, $month_start) ?>">
                    <td class="nowrap" data-value="<?= e($p['paid_at']) ?>"><?= e(format_datetime_short($p['paid_at'])) ?><div class="cell-sub"><?= e($p['recorded_by_name']) ?></div></td>
                    <td class="nowrap"><a href="<?= e(BASE_URL) ?>print/receipt.php?id=<?= (int) $p['payment_id'] ?>" target="_blank" rel="noopener" class="mono"><?= e($p['receipt_number']) ?></a></td>
                    <td><a href="<?= e(BASE_URL) ?>admin/bookings.php?open=<?= (int) $p['booking_id'] ?>" class="mono nowrap"><?= e($p['booking_reference']) ?></a>
                        <div class="cell-sub"><?= e($p['customer_name']) ?></div></td>
                    <td data-value="<?= e($p['type_label']) ?>"><span class="status-badge <?= e($p['type_badge']) ?>"><?= e($p['type_label']) ?></span>
                        <?php if ($void): ?><div class="cell-sub text-danger">void</div><?php endif; ?></td>
                    <td><?= e($p['method_label']) ?><?php if ($p['transaction_ref']): ?><div class="cell-sub mono"><?= e($p['transaction_ref']) ?></div><?php endif; ?></td>
                    <td class="text-end mono nowrap<?= $p['is_money_out'] ? ' amount-out' : '' ?><?= $void ? ' text-decoration-line-through text-secondary' : '' ?>" data-value="<?= e((string) $signed) ?>">
                        <?= $p['is_money_out'] ? '−' : '' ?><?= e(money($p['amount'])) ?>
                        <?php if ($p['is_cashless']): ?><div class="cell-sub">no cash moved</div><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="dt-pager" id="payment-pager"></div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
