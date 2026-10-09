<?php
/**
 * Customer portal: online requests, ownership, payments sent online (Phase 11).
 * Run through tests/run.php, which gives it a fresh copy of the sample
 * data in a separate test database first.
 */
chdir(dirname(__DIR__)); $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (PHP_SAPI !== 'cli' || !getenv('AUTOWAY_DB')) { exit("Run this through tests/run.php.\n"); }
require 'includes/portal_data.php';
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; $c ? $pass++ : $fail++; }
function expect_error(callable $f, string $needle, string $m) { try { $f(); ok(false, "$m (no error)"); } catch (BookingError $e) { ok(stripos($e->getMessage(), $needle) !== false, "$m — \"" . $e->getMessage() . '"'); } }
$db = Database::getConnection();
$at = fn(string $s) => new DateTimeImmutable($s);
$liam = get_customer(1); $nadia = get_customer(2);

echo "== window + catalog\n";
ok(count(portal_catalog()) === 8, 'catalog lists 8 cars (none unavailable)');
ok(count(portal_catalog(['type' => 'suv'])) >= 1 && !array_filter(portal_catalog(['type' => 'suv']), fn($v) => $v['vehicle_type'] !== 'suv'), 'type filter');
ok((bool) array_filter(online_window_errors($at('+1 hour'), $at('+1 day')), fn($e) => str_contains($e, 'notice')), 'pickup within lead time refused');
ok(online_window_errors($at('10:00 tomorrow +2 days'), $at('10:00 tomorrow +4 days')) === [], 'normal window ok');
$res = portal_search($at('10:00 tomorrow +2 days'), $at('10:00 tomorrow +4 days'));
ok($res && !array_key_exists('plate_number', $res[0]) && isset($res[0]['quote']['total_amount']), 'search hides plates, carries quote');

echo "== request booking\n";
$p = $at('10:00 tomorrow +2 days'); $r = $at('10:00 tomorrow +4 days');
$id = request_booking($nadia, 2, $p, $r, 'Child seat please');
$b = get_booking($id);
ok($b['booking_status'] === 'pending' && $b['created_by'] === null && (float) $b['discount_amount'] === 0.0, 'pending, created by customer, no discount');
ok((int) $db->query("SELECT COUNT(*) FROM notifications WHERE title='New online booking'")->fetchColumn() >= 2, 'admin + staff notified');
expect_error(fn() => request_booking($nadia, 2, $p, $r, ''), 'available', 'same car same dates refused (engine rule)');
$db->exec("UPDATE customers SET account_status='unverified' WHERE customer_id=2");
expect_error(fn() => request_booking(get_customer(2), 4, $p, $r, ''), 'verified', 'unverified customer refused');
$db->exec("UPDATE customers SET account_status='verified' WHERE customer_id=2");
request_booking($nadia, 4, $p, $r, ''); request_booking($nadia, 7, $p, $r, '');
ok(pending_request_count(2) === 3, '3 pending requests');
expect_error(fn() => request_booking($nadia, 8, $p, $r, ''), 'requests waiting', '4th pending request refused');
ok((bool) array_filter(portal_booking_blockers($nadia), fn($x) => str_contains($x, 'waiting')), 'blockers list says so up front');

echo "== ownership\n";
ok(own_booking($liam, $id) === null, 'Liam can\'t see Nadia\'s booking');
expect_error(fn() => customer_cancel_booking($liam, $id, ''), 'wasn\'t found', 'Liam can\'t cancel it');
expect_error(fn() => submit_payment($liam, $id, ['payment_type' => 'deposit', 'payment_method' => 'gcash', 'transaction_ref' => 'X1', 'amount' => 100, 'paid_on' => date('Y-m-d')]), 'wasn\'t found', 'Liam can\'t pay into it');

echo "== payment submissions\n";
$v = customer_booking_view(get_booking($id));
ok($v['pay']['deposit'] == 2000 && $v['pay']['rental_fee'] > 0 && $v['can_pay'] && $v['can_cancel'] && $v['cancel_fee'] == 0, 'buckets: deposit 2000 + rental; free to cancel');
ok(!isset($v['booking']['customer_email']) && !isset($v['booking']['created_by_name']), 'view strips desk-only fields');
$sub = fn($x) => array_merge(['payment_type' => 'deposit', 'payment_method' => 'gcash', 'transaction_ref' => 'GC-100', 'amount' => 2000, 'paid_on' => date('Y-m-d')], $x);
expect_error(fn() => submit_payment($nadia, $id, $sub(['amount' => 2500])), 'more than', 'over the deposit refused');
expect_error(fn() => submit_payment($nadia, $id, $sub(['paid_on' => date('Y-m-d', strtotime('+1 day'))])), 'future', 'future date refused');
expect_error(fn() => submit_payment($nadia, $id, $sub(['transaction_ref' => '<script>'])), 'reference', 'odd reference refused');
expect_error(fn() => submit_payment($nadia, $id, $sub(['payment_method' => 'cash'])), 'GCash', 'cash not an online method');
expect_error(fn() => submit_payment($nadia, $id, $sub(['transaction_ref' => 'CARD-55129034'])), 'already been sent', 'reference already on a desk payment refused');
$s1 = submit_payment($nadia, $id, $sub([]));
ok(customer_booking_view(get_booking($id))['pay']['deposit'] == 0, 'deposit bucket reserved while waiting');
expect_error(fn() => submit_payment($nadia, $id, $sub(['transaction_ref' => 'GC-101', 'amount' => 1])), 'already paid', 'can\'t send deposit twice');
expect_error(fn() => submit_payment($nadia, $id, $sub(['payment_type' => 'rental_fee'])), 'already been sent', 'same ref twice refused');
ok(get_booking($id)['amount_paid'] == 0, 'nothing counts as paid yet');
$s2 = submit_payment($nadia, $id, $sub(['payment_type' => 'rental_fee', 'transaction_ref' => 'BT-7', 'payment_method' => 'bank_transfer', 'amount' => 1000]));
withdraw_submission($nadia, $s2);
ok($db->query("SELECT status FROM payment_submissions WHERE submission_id=$s2")->fetchColumn() === 'withdrawn', 'customer withdraws a report');
expect_error(fn() => withdraw_submission($liam, $s1), 'wasn\'t found', 'other customer can\'t withdraw');

echo "== staff review\n";
expect_error(fn() => reject_submission($s1, 2, ''), 'Say why', 'reject needs a reason');
$pid = accept_submission($s1, 2);
$pay = get_payment($pid);
ok($pay['payment_type'] === 'deposit' && $pay['transaction_ref'] === 'GC-100' && str_starts_with($pay['receipt_number'], 'OR-'), 'accepted → real deposit payment ' . $pay['receipt_number']);
ok(get_booking($id)['amount_paid'] == 2000 && deposit_position($id)['received'] == 2000, 'booking paid 2000, deposit held');
ok($db->query("SELECT status='accepted' AND payment_id=$pid FROM payment_submissions WHERE submission_id=$s1")->fetchColumn() == 1, 'submission linked to payment');
expect_error(fn() => accept_submission($s1, 2), 'already accepted', 'can\'t accept twice');
$s3 = submit_payment($nadia, $id, $sub(['payment_type' => 'rental_fee', 'transaction_ref' => 'GC-200', 'amount' => 500]));
reject_submission($s3, 2, 'No GCash payment with that reference.');
ok((int) $db->query("SELECT COUNT(*) FROM notifications WHERE user_id=5 AND title='Payment not confirmed'")->fetchColumn() === 1, 'customer told why');
$s4 = submit_payment($nadia, $id, $sub(['payment_type' => 'rental_fee', 'transaction_ref' => 'GC-200', 'amount' => 500]));
ok($s4 > 0, 'rejected reference can be sent again (corrected)');
// accept failing inside record_payment rolls back submission status
$db->exec("UPDATE bookings SET booking_status='cancelled', cancellation_fee=0 WHERE booking_id=$id");
try { accept_submission($s4, 2, 'deposit'); ok(false, 'accept on closed booking as deposit'); } catch (BookingError $e) { ok(true, 'accept refused by payment rules: ' . $e->getMessage()); }
ok($db->query("SELECT status FROM payment_submissions WHERE submission_id=$s4")->fetchColumn() === 'submitted', 'failed accept rolled back (still submitted)');
$db->exec("UPDATE bookings SET booking_status='pending', cancellation_fee=0 WHERE booking_id=$id");

echo "== cancel\n";
$c = request_booking($liam, 1, $at('10:00 tomorrow +10 days'), $at('10:00 tomorrow +11 days'), '');
$db->exec("UPDATE bookings SET booking_status='confirmed' WHERE booking_id=$c");
$db->exec("UPDATE bookings SET pickup_datetime = NOW() + INTERVAL 5 HOUR, return_datetime = NOW() + INTERVAL 29 HOUR WHERE booking_id=$c");
$v = customer_booking_view(get_booking($c));
ok($v['cancel_fee'] > 0, 'confirmed booking inside the fee window shows a fee: ' . $v['cancel_fee']);
$fee = customer_cancel_booking($liam, $c, '');
$bc = get_booking($c);
ok($bc['booking_status'] === 'cancelled' && $bc['cancelled_by'] === null && $fee == $v['cancel_fee'] && $bc['cancellation_reason'] === 'Cancelled by the customer online', 'cancelled by customer, fee charged, default reason');
$fee2 = customer_cancel_booking($nadia, $id, 'Plans changed');
ok($fee2 == 0.0, 'pending request cancels free');
ok($db->query("SELECT status FROM payment_submissions WHERE submission_id=$s4")->fetchColumn() === 'withdrawn', 'waiting reports withdrawn on cancel');
expect_error(fn() => customer_cancel_booking($nadia, $id, ''), 'hasn\'t started', 'can\'t cancel twice');
expect_error(fn() => customer_cancel_booking($liam, 1, ''), 'hasn\'t started', 'active rental can\'t be cancelled online');

echo "== paperwork + next booking\n";
$pw = own_paperwork($nadia);
ok(count(array_filter($pw['payments'], fn($p) => $p['receipt_number'] === $pay['receipt_number'])) === 1, 'receipt in her paperwork');
ok(!array_filter(own_paperwork($liam)['payments'], fn($p) => $p['booking_id'] == $id), 'not in Liam\'s');
ok(next_own_booking($liam)['booking_id'] == 1, 'Liam\'s next = his active rental');

echo "\nTOTAL: $pass passed, $fail failed\n";
