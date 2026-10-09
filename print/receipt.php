<?php
/**
 * One payment's receipt: print/receipt.php?id=<payment_id>.
 * Admin/staff, or the customer the booking belongs to. A voided payment
 * still prints (for the record) but is stamped VOID.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments_data.php';
require_once __DIR__ . '/_layout.php';

$user = require_login();
$p = get_payment((int) ($_GET['id'] ?? 0));
$b = $p ? get_booking((int) $p['booking_id']) : null;
// Same "not found" for missing and not-yours, so receipt IDs can't be probed.
if (!$p || !$b || !can_view_booking_paperwork($user, $b)) {
    http_response_code(404);
    exit('Receipt not found.');
}

$is_void = $p['status'] !== 'completed';
$kind = match ($p['payment_type']) {
    'refund'          => 'Refund',
    'deposit_applied' => 'Deposit kept',
    default           => 'Official receipt',
};

print_header($p['receipt_number'], paperwork_back_url($user, (int) $b['booking_id']));
?>
<main class="sheet narrow<?= $is_void ? ' is-void' : '' ?>">
    <div class="head">
        <?php print_business_block(); ?>
        <div class="doc-title">
            <h1><?= e($kind) ?></h1>
            <div class="doc-number"><?= e($p['receipt_number']) ?></div>
            <div class="doc-meta"><?= e(format_datetime($p['paid_at'])) ?></div>
        </div>
    </div>

    <div class="big-amount"><?= $p['is_money_out'] ? '−' : '' ?><?= e(money($p['amount'])) ?></div>
    <div class="label">
        <?php if ($p['payment_type'] === 'refund'): ?>
            Returned to <?= e($p['customer_name']) ?>
        <?php elseif ($p['payment_type'] === 'deposit_applied'): ?>
            From <?= e($p['customer_name']) ?>'s security deposit, kept against the amount owed. No new money changed hands.
        <?php else: ?>
            Received from <?= e($p['customer_name']) ?>
        <?php endif; ?>
    </div>

    <dl class="facts">
        <dt>For</dt>
        <dd><?= e($p['type_label']) ?></dd>
        <dt>Booking</dt>
        <dd><?= e($b['booking_reference']) ?></dd>
        <dt>Vehicle</dt>
        <dd><?= e($b['brand'] . ' ' . $b['model']) ?> <span class="plate"><?= e($b['plate_number']) ?></span></dd>
        <?php if ($p['payment_method']): ?>
            <dt>Paid by</dt>
            <dd><?= e($p['method_label']) ?><?= $p['transaction_ref'] ? ', ref. ' . e($p['transaction_ref']) : '' ?></dd>
        <?php endif; ?>
        <dt>Recorded by</dt>
        <dd><?= e($p['recorded_by_name']) ?></dd>
        <?php if ($p['notes']): ?>
            <dt>Notes</dt>
            <dd><?= e($p['notes']) ?></dd>
        <?php endif; ?>
    </dl>

    <?php if ($is_void): ?>
        <p class="void-note">This payment was voided<?= $p['voided_by_name'] ? ' by ' . e($p['voided_by_name']) : '' ?><?= $p['voided_at'] ? ' on ' . e(format_datetime($p['voided_at'])) : '' ?>. It is kept for the record and does not count toward the booking.</p>
    <?php endif; ?>

    <div class="notes">
        <p>Balance on booking <?= e($b['booking_reference']) ?> as of printing: <strong><?= e(money($b['balance_due'])) ?></strong><?= $b['refund_due'] > 0 ? ' (' . e(money($b['refund_due'])) . ' to be refunded)' : '' ?>.</p>
        <p>Keep this receipt. Questions: <?= e(BUSINESS_PHONE) ?>.</p>
    </div>
</main>
<?php print_footer();
