<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/portal_partial.php';

$user = require_role('customer');
$customer = portal_customer($user);
$paper = own_paperwork($customer);

$page_title = 'Payments';
$active_nav = 'payments';

require __DIR__ . '/../includes/header.php';
$mb = BASE_URL . 'customer/my-bookings.php?open=';
?>

<?php if ($paper['submissions']): ?>
<section class="panel">
    <div class="panel-header">Payments you sent online</div>
    <div class="panel-body no-pad">
        <table class="data-table">
            <thead><tr><th>Booking</th><th>Sent</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($paper['submissions'] as $s): ?>
                <tr>
                    <td><a class="mono" href="<?= e($mb . (int) $s['booking_id']) ?>"><?= e($s['booking_reference']) ?></a></td>
                    <td><?= e($s['method_label']) ?> <span class="mono"><?= e($s['transaction_ref']) ?></span>
                        <div class="cell-sub"><?= e(date('M j, Y', strtotime($s['paid_on']))) ?> &middot; for <?= e(strtolower($s['type_label'])) ?></div></td>
                    <td class="mono nowrap text-end"><?= e(money($s['amount'])) ?></td>
                    <td>
                        <?php if ($s['status'] === 'submitted'): ?>
                            <span class="status-badge status-warning">being checked</span>
                        <?php else: ?>
                            <span class="status-badge status-danger">not confirmed</span>
                            <div class="cell-sub"><?= e($s['review_note']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="panel">
    <div class="panel-header">Receipts <span class="text-secondary small fw-normal">every payment and refund on your bookings</span></div>
    <div class="panel-body no-pad">
        <table class="data-table">
            <thead><tr><th>Receipt</th><th>For</th><th>Date</th><th class="text-end">Amount</th></tr></thead>
            <tbody>
            <?php if (!$paper['payments']): ?>
                <tr class="empty-row"><td colspan="4">No payments yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($paper['payments'] as $p): ?>
                <?php $void = $p['status'] !== 'completed'; ?>
                <tr<?= $void ? ' class="is-void-row"' : '' ?>>
                    <td><a class="mono" href="<?= e(BASE_URL . 'print/receipt.php?id=' . (int) $p['payment_id']) ?>" target="_blank" rel="noopener"><?= e($p['receipt_number']) ?></a>
                        <?= $void ? '<span class="status-badge status-danger">void</span>' : '' ?></td>
                    <td><?= e($p['label']) ?><div class="cell-sub"><a href="<?= e($mb . (int) $p['booking_id']) ?>"><?= e($p['booking_reference']) ?></a></div></td>
                    <td class="nowrap"><?= e(format_datetime_short($p['paid_at'])) ?><div class="cell-sub"><?= e(humanize((string) $p['payment_method'])) ?></div></td>
                    <td class="mono nowrap text-end"><?= $p['payment_type'] === 'refund' ? '− ' : '' ?><?= e(money((float) $p['amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <div class="panel-header">Invoices</div>
    <div class="panel-body no-pad">
        <table class="data-table">
            <thead><tr><th>Invoice</th><th>Booking</th><th>Issued</th><th class="text-end">Total</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$paper['invoices']): ?>
                <tr class="empty-row"><td colspan="5">Invoices appear here once a booking is closed.</td></tr>
            <?php endif; ?>
            <?php foreach ($paper['invoices'] as $i): ?>
                <tr>
                    <td><a class="mono" href="<?= e(BASE_URL . 'print/invoice.php?id=' . (int) $i['invoice_id']) ?>" target="_blank" rel="noopener"><?= e($i['invoice_number']) ?></a></td>
                    <td><a class="mono" href="<?= e($mb . (int) $i['booking_id']) ?>"><?= e($i['booking_reference']) ?></a></td>
                    <td class="nowrap"><?= e(date('M j, Y', strtotime($i['issue_date']))) ?></td>
                    <td class="mono nowrap text-end"><?= e(money((float) $i['total'])) ?></td>
                    <td><span class="status-badge <?= e(status_badge_class($i['status'])) ?>"><?= e($i['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
