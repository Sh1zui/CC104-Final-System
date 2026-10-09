<?php
/**
 * The bill for a booking.
 *   print/invoice.php?id=<invoice_id>      a specific issued (or voided) invoice
 *   print/invoice.php?booking=<booking_id> the booking's current invoice, or a
 *                                          statement (marked not final) if none
 * Admin/staff, or the customer the booking belongs to.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments_data.php';
require_once __DIR__ . '/_layout.php';

$user = require_login();

$invoice = null;
if (isset($_GET['id'])) {
    $invoice = get_invoice((int) $_GET['id']);
    $b = $invoice ? get_booking((int) $invoice['booking_id']) : null;
} else {
    $b = get_booking((int) ($_GET['booking'] ?? 0));
    $invoice = $b ? active_invoice((int) $b['booking_id']) : null;
}
if (!$b || !can_view_booking_paperwork($user, $b)) {
    http_response_code(404);
    exit('Invoice not found.');
}

$is_statement = $invoice === null;
$is_void = $invoice && $invoice['status'] === 'void';
$figures = invoice_figures($b);
// An issued invoice prints its frozen totals; the lines are its detail.
$subtotal   = $invoice ? $invoice['subtotal'] : $figures['subtotal'];
$discount   = $invoice ? $invoice['discount'] : $figures['discount'];
$additional = $invoice ? $invoice['additional_charges'] : $figures['additional_charges'];
$total      = $invoice ? $invoice['total'] : $figures['total'];

$payments = array_values(array_filter(booking_payments((int) $b['booking_id']), fn($p) => $p['status'] === 'completed'));
$customer = get_customer((int) $b['customer_id']);
$rental = get_rental((int) $b['booking_id']);
$closed = in_array($b['booking_status'], ['completed', 'cancelled', 'no_show'], true);

$title = $is_statement ? 'Statement ' . $b['booking_reference'] : $invoice['invoice_number'];
print_header($title, paperwork_back_url($user, (int) $b['booking_id']));
?>
<main class="sheet<?= $is_void ? ' is-void' : '' ?>">
    <div class="head">
        <?php print_business_block(); ?>
        <div class="doc-title">
            <h1><?= $is_statement ? 'Statement' : 'Invoice' ?></h1>
            <?php if ($is_statement): ?>
                <div class="doc-number"><?= e($b['booking_reference']) ?></div>
                <div class="doc-meta">As of <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></div>
                <span class="stamp draft">Not final — <?= $closed ? 'invoice not issued yet' : 'booking still open' ?></span>
            <?php else: ?>
                <div class="doc-number"><?= e($invoice['invoice_number']) ?></div>
                <div class="doc-meta">Issued <?= e(date('M j, Y', strtotime($invoice['issue_date']))) ?> by <?= e($invoice['issued_by_name']) ?></div>
                <span class="stamp <?= e($invoice['status']) ?>"><?= e(ucfirst($invoice['status'])) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="parties">
        <div>
            <div class="label">Bill to</div>
            <strong><?= e($customer['full_name']) ?></strong><br>
            <?= e($customer['email']) ?><?= $customer['phone'] ? '<br>' . e($customer['phone']) : '' ?>
            <?php if ($customer['address_line'] || $customer['city']): ?>
                <br><?= e(trim(($customer['address_line'] ?? '') . ', ' . ($customer['city'] ?? ''), ', ')) ?>
            <?php endif; ?>
        </div>
        <div>
            <div class="label">Booking <?= e($b['booking_reference']) ?></div>
            <?= e($b['brand'] . ' ' . $b['model'] . ' ' . $b['year']) ?> <span class="plate"><?= e($b['plate_number']) ?></span><br>
            <?= e(format_datetime($b['pickup_datetime'])) ?> to <?= e(format_datetime($b['return_datetime'])) ?>
            <?php if ($rental && $rental['returned_at']): ?>
                <br><span class="label">Returned <?= e(format_datetime($rental['returned_at'])) ?>, <?= e(number_format((int) $rental['km_driven'])) ?> km driven</span>
            <?php elseif (in_array($b['booking_status'], ['cancelled', 'no_show'], true)): ?>
                <br><span class="label"><?= $b['booking_status'] === 'no_show' ? 'Not picked up' : 'Cancelled ' . e(format_datetime($b['cancelled_at'])) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <table class="lines">
        <thead><tr><th>Description</th><th class="amt">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($figures['lines'] as $line): ?>
            <tr><td><?= e($line['label']) ?></td><td class="amt"><?= $line['amount'] < 0 ? '−' . e(money(-$line['amount'])) : e(money($line['amount'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <div class="row"><span><?= in_array($b['booking_status'], ['cancelled', 'no_show'], true) ? 'Fee' : 'Rental' ?></span><span><?= e(money($subtotal)) ?></span></div>
        <?php if ($discount > 0): ?><div class="row"><span>Discount</span><span>−<?= e(money($discount)) ?></span></div><?php endif; ?>
        <?php if ($additional > 0): ?><div class="row"><span>Charges</span><span><?= e(money($additional)) ?></span></div><?php endif; ?>
        <div class="row grand"><span>Total</span><span><?= e(money($total)) ?></span></div>
        <?php if (!$closed): ?>
            <div class="row"><span>Security deposit (refundable)</span><span><?= e(money($b['deposit_amount'])) ?></span></div>
        <?php endif; ?>
        <div class="row"><span>Paid</span><span><?= e(money($b['amount_paid'])) ?></span></div>
        <?php if ($b['balance_due'] > 0): ?>
            <div class="row due"><span>Balance due</span><span><?= e(money($b['balance_due'])) ?></span></div>
        <?php elseif ($b['refund_due'] > 0): ?>
            <div class="row refund"><span>To be refunded</span><span><?= e(money($b['refund_due'])) ?></span></div>
        <?php endif; ?>
    </div>

    <?php if ($payments): ?>
        <table class="lines">
            <thead><tr><th>Payments and refunds</th><th>Receipt</th><th class="amt">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td><?= e($p['type_label']) ?><?= $p['payment_method'] ? ' · ' . e($p['method_label']) : '' ?> · <?= e(date('M j, Y', strtotime($p['paid_at']))) ?></td>
                    <td class="nowrap"><?= e($p['receipt_number']) ?></td>
                    <td class="amt"><?= $p['is_money_out'] ? '−' : '' ?><?= e(money($p['amount'])) ?><?= $p['is_cashless'] ? '<br><span class="label">no cash moved</span>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($is_void): ?>
        <p class="void-note">Voided <?= e(format_datetime($invoice['voided_at'])) ?> by <?= e($invoice['voided_by_name']) ?>: <?= e($invoice['void_reason']) ?></p>
    <?php endif; ?>

    <div class="notes">
        <?php if ($closed && $b['deposit_amount'] > 0 && $b['booking_status'] === 'completed'): ?>
            <p>The <?= e(money($b['deposit_amount'])) ?> security deposit is not part of the total. Any part of it not used to cover the amounts above is refunded.</p>
        <?php elseif (!$closed): ?>
            <p>The security deposit is refunded when the car is returned, less any charges at return. This statement will change until the booking is closed.</p>
        <?php endif; ?>
        <p>Thank you for renting with <?= e(APP_NAME) ?>.</p>
    </div>
</main>
<?php print_footer();
