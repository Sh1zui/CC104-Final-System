<?php
/**
 * Payments, deposits, refunds, settlement, invoices (Phase 8).
 * Run through tests/run.php, which gives it a fresh copy of the sample
 * data in a separate test database first.
 */
chdir(dirname(__DIR__)); $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (PHP_SAPI !== 'cli' || !getenv('AUTOWAY_DB')) { exit("Run this through tests/run.php.\n"); }
require 'includes/payments_data.php';
require 'includes/dashboard_data.php';
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; $c ? $pass++ : $fail++; }
function expect_error(callable $f, string $needle, string $m) { try { $f(); ok(false, "$m (no error)"); } catch (BookingError $e) { ok(stripos($e->getMessage(), $needle) !== false, "$m — \"" . $e->getMessage() . '"'); } }
$db = Database::getConnection();
require __DIR__ . '/fixtures.php';
use_fixed_sample_bookings($db); $dt = fn($s) => new DateTimeImmutable($s);
$rev = fn() => revenue_summary()['all_time'];

echo "== starting position (seed)\n";
ok($rev() == 15500, 'revenue = 6400 + 9100; the 2000 deposit is not revenue (' . $rev() . ')');
ok(deposit_position(1)['held'] == 2000 && deposits_held_total() == 2000, 'deposit held 2000');
$b = get_booking(1); ok($b['balance_due'] == 6400, 'booking 1 owes 6400 while out');

echo "== recording payments\n";
expect_error(fn() => record_payment(1, 2, 100, 'refund', 'cash'), 'what the payment is for', 'refund not recordable as money in');
expect_error(fn() => record_payment(1, 2, 0, 'rental_fee', 'cash'), 'more than zero', 'zero rejected');
expect_error(fn() => record_payment(1, 2, 6400.01, 'rental_fee', 'cash'), 'more than the balance', 'over balance rejected');
expect_error(fn() => record_payment(1, 2, 100, 'deposit', 'cash'), 'already fully paid', 'deposit already paid');
expect_error(fn() => record_payment(1, 2, 100, 'rental_fee', 'gcash'), 'reference', 'GCash needs a reference');
expect_error(fn() => record_payment(1, 2, 100, 'rental_fee', 'gcash', 'GC-8841203917'), 'OR-2026-000002', 'duplicate reference caught, names the receipt');
expect_error(fn() => record_payment(1, 2, 100, 'rental_fee', 'bitcoin', 'x'), 'how the money', 'bad method');
$pid = record_payment(1, 2, 4000, 'rental_fee', 'cash', '', 'Partial at the desk');
$p = get_payment($pid);
ok(preg_match('/^OR-\d{4}-000004$/', $p['receipt_number']) === 1, 'sequential receipt ' . $p['receipt_number']);
ok($p['recorded_by_name'] === 'Maria Santos' && $p['notes'] === 'Partial at the desk', 'who + notes stored');
ok(get_booking(1)['payment_status'] === 'partial' && get_booking(1)['balance_due'] == 2400, 'partial, 2400 left');
record_payment(1, 2, 2400, 'rental_fee', 'card', 'CARD-777');
ok(get_booking(1)['payment_status'] === 'paid' && get_booking(1)['balance_due'] == 0, 'paid in full');
expect_error(fn() => record_payment(1, 2, 1, 'rental_fee', 'cash'), 'Nothing is owed', 'no more payments once paid');
ok($rev() == 15500 + 6400, 'revenue +6400 (rental payments)');

echo "== settle before close\n";
expect_error(fn() => settle_booking(1, 1, 'cash'), 'once it\'s closed', 'can\'t settle an active rental');

echo "== return with damage, then settle (keep 1500, refund 500)\n";
check_in_booking(1, 2, $dt('2026-09-22 09:30'), 10200, 100, 1500, 'Cracked mirror', [], false, '');
$b = get_booking(1);
ok($b['amount_due'] == 14300 && $b['refund_due'] == 500, 'due 12800 + 1500 damage; 500 to give back');
$plan = settlement_plan($b);
ok($plan['keep'] == 1500 && $plan['refund_deposit'] == 500 && $plan['refund_payment'] == 0, 'plan: keep 1500, refund 500 of deposit');
expect_error(fn() => settle_booking(1, 1, ''), 'how the money', 'refund needs a method');
expect_error(fn() => settle_booking(1, 1, 'bank_transfer'), 'reference', 'non-cash refund needs a reference');
settle_booking(1, 1, 'cash');
ok(deposit_position(1)['held'] == 0 && deposits_held_total() == 0, 'no deposit held any more');
$b = get_booking(1);
ok($b['amount_paid'] == 14300 && $b['balance_due'] == 0 && $b['refund_due'] == 0 && $b['payment_status'] === 'paid', 'net paid 14300 = due; settled');
ok($rev() == 15500 + 6400 + 1500, 'revenue +1500 kept from the deposit (the 500 refund doesn\'t touch revenue): ' . $rev());
expect_error(fn() => settle_booking(1, 1, 'cash'), 'already settled', 'second settle rejected');
$types = array_column(booking_payments(1), 'type_label');
ok(in_array('Deposit kept', $types) && in_array('Deposit refund', $types), 'ledger shows Deposit kept + Deposit refund');
ok(!in_array('deposit_applied', array_column(recent_payments(20), 'payment_type')), 'dashboard "recent payments" hides deposit-kept (no cash moved)');

echo "== invoice\n";
$inv_id = issue_invoice(1, 1);
$inv = get_invoice($inv_id);
ok(preg_match('/^INV-\d{4}-000001$/', $inv['invoice_number']) === 1 && $inv['total'] == 14300 && $inv['status'] === 'paid', $inv['invoice_number'] . ': total 14300, paid');
ok($inv['subtotal'] == 12800 && $inv['discount'] == 0 && $inv['additional_charges'] == 1500, 'subtotal / discount / charges');
expect_error(fn() => issue_invoice(1, 1), 'already has invoice', 'one active invoice per booking');
expect_error(fn() => add_booking_charge(1, 1, 'traffic_violation', '1000', 'Ticket'), 'already issued', 'charge blocked under issued invoice');
expect_error(fn() => void_invoice($inv_id, 1, ' '), 'why', 'void needs a reason');
void_invoice($inv_id, 1, 'Ticket arrived after invoicing');
add_booking_charge(1, 1, 'traffic_violation', '1000', 'Ticket');
$inv2 = get_invoice(issue_invoice(1, 1));
ok($inv2['invoice_number'] !== $inv['invoice_number'] && $inv2['total'] == 15300 && $inv2['status'] === 'unpaid', 'reissued as ' . $inv2['invoice_number'] . ': 15300, unpaid');
ok(get_invoice($inv_id)['status'] === 'void' && get_invoice($inv_id)['void_reason'] === 'Ticket arrived after invoicing', 'old invoice kept as void with reason');
record_payment(1, 2, 1000, 'additional_service', 'cash');
ok(get_invoice($inv2['invoice_id'])['status'] === 'paid', 'paying the ticket marks the invoice paid');
expect_error(fn() => issue_invoice(2, 1), 'once the booking is closed', 'no invoice for an open booking');

echo "== voiding payments\n";
expect_error(fn() => void_payment(1, 1, 'mistake'), 'Void the invoice first', 'void blocked while invoice issued');
void_invoice($inv2['invoice_id'], 1, 'test');
expect_error(fn() => void_payment(1, 1, 'mistake'), 'settled', 'money-in row protected after settlement');
$refund = array_values(array_filter(booking_payments(1), fn($p) => $p['payment_type'] === 'refund'))[0];
$before = $rev();
void_payment($refund['payment_id'], 1, 'Refund entered twice');
ok(get_payment($refund['payment_id'])['status'] === 'failed' && str_contains(get_payment($refund['payment_id'])['notes'], 'Refund entered twice'), 'refund row voided with reason, still on record');
ok(deposit_position(1)['held'] == 500 && $rev() == $before, 'deposit back to 500 held; revenue unchanged (deposit refunds never touch it)');
expect_error(fn() => void_payment($refund['payment_id'], 1, 'again'), 'already void', 'double void rejected');

echo "== cancellation fees\n";
$far = (new DateTimeImmutable('tomorrow 09:00'))->modify('+5 days');
$bf = create_booking(2, 1, $far, $far->modify('+2 days'), 0, '', 1, true);
ok(cancel_booking($bf, 1, 'Plans changed') == 0 && get_booking($bf)['payment_status'] === 'paid' && get_booking($bf)['amount_due'] == 0, 'free cancellation (>24h): fee 0, nothing owed');
$soon = (new DateTimeImmutable('+3 hours'))->setTime((int) date('H', strtotime('+3 hours')), 0);
$bs = create_booking(2, 1, $soon, $soon->modify('+2 days'), 0, '', 1, true);
record_payment($bs, 1, 2000, 'deposit', 'cash');
ok(cancel_booking($bs, 1, 'Late cancel') == 1800, 'late cancellation fee = 1 day = 1800');
$b = get_booking($bs);
ok($b['amount_due'] == 1800 && $b['refund_due'] == 200, 'owes 1800 from the 2000 deposit; 200 back');
$before = $rev();
settle_booking($bs, 1, 'cash');
ok(deposits_held_total() == 500 && $rev() == $before + 1800, 'kept 1800 becomes revenue, 200 refunded');
$bw = create_booking(2, 1, $soon->modify('+5 days'), $soon->modify('+6 days'), 0, '', 1, true);
$db->exec("UPDATE bookings SET pickup_datetime = DATE_ADD(NOW(), INTERVAL 2 HOUR) WHERE booking_id = $bw");
ok(cancel_booking($bw, 1, 'Car broke down', true) == 0, 'staff can waive a late fee');

echo "== no-show (seed booking 2: paid 9100 by card, all as rental)\n";
$before = $rev();
mark_booking_no_show(2, 1);
$b = get_booking(2);
ok($b['cancellation_fee'] == 3800 && $b['amount_due'] == 3800 && $b['refund_due'] == 5300, 'no-show fee 1 day (3800); 5300 to refund');
$plan = settlement_plan($b);
ok($plan['refund_deposit'] == 0 && $plan['refund_payment'] == 5300 && $plan['keep'] == 0, 'no deposit row held, so it\'s an overpayment refund');
settle_booking(2, 1, 'card', 'CARD-REV-1');
ok($rev() == $before - 5300 && get_booking(2)['payment_status'] === 'paid', 'revenue falls by the refunded 5300 (net 3800 kept)');
$inv3 = get_invoice(issue_invoice(2, 1));
ok($inv3['total'] == 3800 && $inv3['subtotal'] == 3800 && $inv3['status'] === 'paid', 'no-show invoice: 3800, paid');

echo "== the books balance\n";
$cash_in  = (float) $db->query("SELECT SUM(amount) FROM payments WHERE status='completed' AND payment_type IN ('deposit','rental_fee','late_fee','damage_fee','additional_service')")->fetchColumn();
$cash_out = (float) $db->query("SELECT SUM(amount) FROM payments WHERE status='completed' AND payment_type = 'refund'")->fetchColumn();
ok(abs(($cash_in - $cash_out) - ($rev() + deposits_held_total())) < 0.01, sprintf('cash in − cash out (%.2f) = revenue + deposits held (%.2f + %.2f)', $cash_in - $cash_out, $rev(), deposits_held_total()));
echo "TOTAL: $pass passed, $fail failed\n";
