<?php
/**
 * Release and return: late, fuel, damage fees, extensions (Phase 7).
 * Run through tests/run.php, which gives it a fresh copy of the sample
 * data in a separate test database first.
 */
chdir(dirname(__DIR__)); $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (PHP_SAPI !== 'cli' || !getenv('AUTOWAY_DB')) { exit("Run this through tests/run.php.\n"); }
require 'includes/rentals_data.php';
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; $c ? $pass++ : $fail++; }
function expect_error(callable $f, string $needle, string $m) { try { $f(); ok(false, "$m (no error)"); } catch (BookingError $e) { ok(stripos($e->getMessage(), $needle) !== false, "$m — \"" . $e->getMessage() . '"'); } }
$db = Database::getConnection();
require __DIR__ . '/fixtures.php';
use_fixed_sample_bookings($db); $dt = fn($s) => new DateTimeImmutable($s);
$due = $dt('2030-01-10 09:00');

echo "== late fee rules (daily 3200, hourly 480)\n";
$f = fn($s) => late_fee_for(3200, $due, $dt($s));
ok($f('2030-01-10 08:00')['late_fee'] == 0, 'early return: 0');
ok($f('2030-01-10 10:00')['late_fee'] == 0 && $f('2030-01-10 10:00')['late_hours'] === 0, 'exactly 60 min late: free (grace)');
ok($f('2030-01-10 10:01')['late_hours'] === 2 && $f('2030-01-10 10:01')['late_fee'] == 960, '61 min: 2 started hours = 960');
ok($f('2030-01-10 14:00')['late_fee'] == 2400, '5 hours = 2400');
ok($f('2030-01-10 16:00')['late_fee'] == 3200, '7 hours = 3360 capped at one day = 3200');
ok($f('2030-01-11 09:00')['late_fee'] == 3200, '24 hours = 1 day = 3200');
ok($f('2030-01-11 10:00')['late_fee'] == 3680, '25 hours = 3200 + 480');
ok($f('2030-01-13 09:00')['late_fee'] == 9600, '3 days = 9600');
ok(late_fee_for(1333.33, $due, $dt('2030-01-10 12:00'))['late_fee'] == round(3 * round(1333.33 * 0.15, 2), 2), 'odd rate rounds per hour');

echo "== fuel + extras\n";
ok(fuel_fee_for(100, 50) == 1250 && fuel_fee_for(50, 100) == 0 && fuel_fee_for(100, 0) == 2500, 'fuel: half tank short 1250, more fuel 0, empty 2500');
ok(normalize_extra_charges([['type' => 'cleaning', 'amount' => '', 'description' => '']]) === [], 'blank line skipped');
expect_error(fn() => normalize_extra_charges([['type' => 'late_return', 'amount' => '100', 'description' => 'x']]), 'valid type', 'late_return not allowed as manual extra (no double charge)');
expect_error(fn() => normalize_extra_charges([['type' => 'cleaning', 'amount' => '-5', 'description' => 'x']]), 'above zero', 'negative extra rejected');
expect_error(fn() => normalize_extra_charges([['type' => 'cleaning', 'amount' => '500', 'description' => '']]), 'Describe', 'extra needs description');
expect_error(fn() => normalize_extra_charges([['type' => 'other', 'amount' => '999999', 'description' => 'x']]), 'at most', 'extra cap');

echo "== amount due / deposit rule (seed #1: total 14800 incl 2000 deposit, paid 8400)\n";
$b = get_booking(1);
ok($b['amount_due'] == 14800 && $b['balance_due'] == 6400, 'active: owes total incl deposit -> 6400 left');
$b2 = get_booking(2);
ok($b2['amount_due'] == 9100 && $b2['balance_due'] == 0 && $b2['payment_status'] === 'paid', 'seed #2 confirmed+paid stays consistent');

echo "== check-in of seed #1 (overdue CR-V)\n";
$r = get_rental(1);
ok($r['odometer_out'] === 9800 && $r['fuel_out_label'] === 'Full' && $r['released_by_name'] === 'Maria Santos', 'release record read back');
expect_error(fn() => check_in_booking(1, 2, $dt('2026-09-17 09:00'), 10000, 100, 0, '', [], false, ''), 'before the car was released', 'return before release rejected');
expect_error(fn() => check_in_booking(1, 2, new DateTimeImmutable('+1 hour'), 10000, 100, 0, '', [], false, ''), 'future', 'future return rejected');
expect_error(fn() => check_in_booking(1, 2, $dt('2026-09-22 09:30'), 9700, 100, 0, '', [], false, ''), 'lower than at release', 'odometer backwards rejected');
expect_error(fn() => check_in_booking(1, 2, $dt('2026-09-22 09:30'), 10000, 55, 0, '', [], false, ''), 'fuel level', 'off-gauge fuel value rejected');
expect_error(fn() => check_in_booking(1, 2, $dt('2026-09-22 09:30'), 10000, 100, 500, '', [], false, ''), 'Describe the damage', 'damage fee needs notes');
// returned 26 hours late, half tank, 1500 damage, 300 cleaning
$prev = return_charges(get_booking(1), $r, $dt('2026-09-23 11:00'), 50, 1500, normalize_extra_charges([['type' => 'cleaning', 'amount' => '300', 'description' => 'Sand everywhere']]));
ok($prev['late_hours'] === 26 && $prev['late_fee'] == 3200 + 2 * 480, 'preview: 26h late = 3200 + 960');
ok($prev['fuel_fee'] == 1250 && $prev['charges_total'] == 4160 + 1250 + 1500 + 300, 'preview: charges add up (7210)');
ok($prev['final_cost'] == 12800 + 7210 && $prev['balance_due'] == 12800 + 7210 - 8400, 'final cost = rental 12800 + charges; balance = minus 8400 paid');
$res = check_in_booking(1, 2, $dt('2026-09-23 11:00'), 10450, 50, 1500, 'Dent on driver door', [['type' => 'cleaning', 'amount' => '300', 'description' => 'Sand everywhere']], true, 'Door dent check');
ok($res == $prev, 'saved charges == previewed charges');
$b = get_booking(1); $r = get_rental(1);
ok($b['booking_status'] === 'completed' && $r['returned_by_name'] === 'Maria Santos' && $r['km_driven'] === 650, 'completed, returned_by, 650 km driven');
ok($b['extra_charges'] == 7210 && $b['amount_due'] == 20010 && $b['balance_due'] == 11610 && $b['payment_status'] === 'partial', 'deposit no longer due once completed; extras included; partial');
$v = $db->query('SELECT status, mileage_km FROM vehicles WHERE vehicle_id = 3')->fetch();
ok($v['status'] === 'maintenance' && (int) $v['mileage_km'] === 10450, 'car to maintenance, mileage updated');
ok((int) $db->query("SELECT COUNT(*) FROM maintenance WHERE vehicle_id = 3 AND status = 'in_progress' AND description LIKE '%Door dent check%'")->fetchColumn() === 1, 'maintenance job logged');
ok(count(booking_penalties(1)) === 1 && booking_penalties(1)[0]['created_by_name'] === 'Maria Santos', 'extra charge stored as penalty');
expect_error(fn() => check_in_booking(1, 2, $dt('2026-09-23 11:00'), 10450, 50, 0, '', [], false, ''), 'Only a car', 'double check-in rejected');
add_booking_charge(1, 1, 'traffic_violation', '1000', 'Speeding ticket, EDSA, Sep 20');
ok(get_booking(1)['extra_charges'] == 8210, 'charge added after completion');
expect_error(fn() => add_booking_charge(2, 1, 'other', '10', 'x'), 'has started', 'no charges on a booking that hasn\'t started');

echo "== check-out\n";
$p = (new DateTimeImmutable('+1 hour'))->setTime((int) date('H') + 1, 0);
$bid = create_booking(1, 1, $p, $p->modify('+2 days'), 0, '', 1, true);
ok(in_array('check_out', booking_allowed_actions(get_booking($bid))), 'pickup within 2h: check_out offered');
expect_error(fn() => check_out_booking($bid, 2, 18500, 100, '', false), 'license', 'license check required');
expect_error(fn() => check_out_booking($bid, 2, 100, 100, '', true), 'lower than', 'odometer below car mileage rejected');
$far = create_booking(2, 6, $p->modify('+3 days'), $p->modify('+4 days'), 0, '', 1, true);
expect_error(fn() => check_out_booking($far, 2, 99999, 100, '', true), 'Too early', 'too early rejected');
ok(!in_array('check_out', booking_allowed_actions(get_booking($far))), 'too early: check_out not offered');
check_out_booking($bid, 2, 18600, 88, 'Tiny chip on windshield', true);
$b = get_booking($bid); $r = get_rental($bid);
ok($b['booking_status'] === 'active' && $r['fuel_out_label'] === '7/8' && $r['condition_notes_out'] === 'Tiny chip on windshield', 'released: active, fuel 7/8, notes');
$v = $db->query('SELECT status, mileage_km FROM vehicles WHERE vehicle_id = 1')->fetch();
ok($v['status'] === 'rented' && (int) $v['mileage_km'] === 18600, 'car rented, mileage synced');
expect_error(fn() => check_out_booking($bid, 2, 18600, 88, '', true), 'Only a confirmed', 'double release rejected');
ok(booking_allowed_actions($b) == ['check_in', 'extend', 'add_charge'], 'active offers check_in/extend/add_charge');
// a second confirmed booking on the same car can't be released while it's out
$db->exec("INSERT INTO bookings (booking_reference, customer_id, vehicle_id, pickup_datetime, return_datetime, rental_days, daily_rate_snapshot, base_amount, total_amount, booking_status)
           VALUES ('BK-TEST-SAMECAR', 2, 1, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY), 1, 1800, 1800, 3800, 'confirmed')");
$same = (int) $db->lastInsertId();
expect_error(fn() => check_out_booking($same, 2, 18600, 100, '', true), 'come back from', 'car still out blocks another release');
$db->exec("DELETE FROM bookings WHERE booking_id = $same");
$db->exec("UPDATE vehicles SET status = 'maintenance' WHERE vehicle_id = 6");
$soon = $db->exec("UPDATE bookings SET pickup_datetime = DATE_ADD(NOW(), INTERVAL 30 MINUTE), return_datetime = DATE_ADD(NOW(), INTERVAL 2 DAY) WHERE booking_id = $far");
expect_error(fn() => check_out_booking($far, 2, 99999, 100, '', true), 'maintenance', 'car in maintenance can\'t be released');
$db->exec("UPDATE vehicles SET status = 'available' WHERE vehicle_id = 6");

echo "== extend\n";
$ret = new DateTimeImmutable(get_booking($bid)['return_datetime']);
expect_error(fn() => extend_rental($bid, $ret->modify('-1 hour'), 1), 'later than', 'extend must be later');
$x = extend_rental($bid, $ret->modify('+1 day'), 1);
$b = get_booking($bid);
ok($b['rental_days'] === 3 && $b['total_amount'] == 1800 * 3 + 2000 && $x['old_total'] == 5600, 'extended by a day: 3 days, total 7400');
$blocker = create_booking(2, 1, $ret->modify('+2 days'), $ret->modify('+3 days'), 0, '', 1);
expect_error(fn() => extend_rental($bid, $ret->modify('+2 days 1 hour'), 1), 'booked next', 'extension into next booking rejected');
expect_error(fn() => extend_rental($bid, $ret->modify('+1 day 23 hours'), 1), 'turnaround', 'extension inside turnaround rejected');
ok(extend_rental($bid, $ret->modify('+1 day 22 hours'), 1)['new_total'] > 0, 'extension right up to turnaround allowed');
expect_error(fn() => extend_rental(2, $ret->modify('+5 days'), 1), 'Only a rental', 'confirmed booking: use reschedule');
// 7+ days crosses into the long-rental discount automatically
$db->exec("DELETE FROM bookings WHERE booking_id = $blocker");
extend_rental($bid, $ret->modify('+6 days'), 1);
$b = get_booking($bid);
ok($b['rental_days'] === 8 && $b['discount_amount'] == round(1800 * 8 * 0.10, 2), 'extending past 7 days applies the long-rental discount');

echo "== payment status follows the deposit rule\n";
$db->exec("INSERT INTO payments (booking_id, receipt_number, amount, payment_type, payment_method, recorded_by) VALUES ($bid, 'TEST-E7-1', " . get_booking($bid)['total_amount'] . ", 'rental_fee', 'cash', 1)");
ok(recalculate_payment_status($bid) === 'paid', 'paid in full while active');
check_in_booking($bid, 1, new DateTimeImmutable(), 18700, 88, 0, '', [], false, '');
$b = get_booking($bid);
ok($b['payment_status'] === 'paid' && $b['refund_due'] == 2000 && $b['balance_due'] == 0, 'on-time return, no charges: deposit 2000 shows as refund due');
ok($db->query('SELECT status FROM vehicles WHERE vehicle_id = 1')->fetchColumn() === 'available', 'car available again');
echo "TOTAL: $pass passed, $fail failed\n";
