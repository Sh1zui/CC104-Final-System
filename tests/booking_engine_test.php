<?php
/**
 * Booking engine: availability, pricing, double-booking, status changes (Phase 6).
 * Run through tests/run.php, which gives it a fresh copy of the sample
 * data in a separate test database first.
 */
// CLI unit/integration tests for includes/bookings_data.php against a fresh seed DB.
chdir(dirname(__DIR__));
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (PHP_SAPI !== 'cli' || !getenv('AUTOWAY_DB')) { exit("Run this through tests/run.php.\n"); }
require 'includes/bookings_data.php';
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; $c ? $pass++ : $fail++; }
function expect_error(callable $f, string $needle, string $m) { try { $f(); ok(false, "$m (no error thrown)"); } catch (BookingError $e) { ok(stripos($e->getMessage(), $needle) !== false, "$m — \"" . $e->getMessage() . '"'); } }
$dt = fn($s) => new DateTimeImmutable($s);
$db = Database::getConnection();
require __DIR__ . '/fixtures.php';
use_fixed_sample_bookings($db);

echo "== pricing\n";
$q = quote_booking(1800, $dt('2030-01-01 09:00'), $dt('2030-01-04 09:00'));
ok($q['rental_days'] === 3 && $q['base_amount'] == 5400 && $q['discount_amount'] == 0 && $q['total_amount'] == 7400, '3 days @1800 = 5400 + 2000 deposit = 7400');
$q = quote_booking(1800, $dt('2030-01-01 09:00'), $dt('2030-01-04 09:01'));
ok($q['rental_days'] === 4, 'one minute over rounds up to an extra day');
$q = quote_booking(1800, $dt('2030-01-01 09:00'), $dt('2030-01-08 09:00'));
ok($q['rental_days'] === 7 && $q['long_rental_discount'] == 1260 && $q['total_amount'] == 12600 - 1260 + 2000, '7 days gets 10% long-rental discount');
$q = quote_booking(1800, $dt('2030-01-01 09:00'), $dt('2030-01-08 09:00'), 500);
ok($q['discount_amount'] == 1760 && $q['total_amount'] == 12600 - 1760 + 2000, 'manual discount stacks');
expect_error(fn() => quote_booking(1800, $dt('2030-01-01 09:00'), $dt('2030-01-02 09:00'), 600), '30%', 'manual discount capped at 30%');
expect_error(fn() => quote_booking(1800, $dt('2030-01-01 09:00'), $dt('2030-01-02 09:00'), -1), 'negative', 'negative discount rejected');
$q = quote_booking(1333.33, $dt('2030-01-01 09:00'), $dt('2030-01-04 09:00'));
ok(abs($q['base_amount'] - $q['discount_amount'] + $q['deposit_amount'] - $q['total_amount']) < 0.001, 'parts add up exactly with odd rates');

echo "== window rules\n";
$now = $dt('2030-01-01 12:00');
ok(booking_window_errors($dt('2030-01-01 11:50'), $dt('2030-01-02 12:00'), $now) === [], '10 minutes ago is within grace');
ok((bool) preg_grep('/past/', booking_window_errors($dt('2030-01-01 11:00'), $dt('2030-01-02 12:00'), $now)), 'an hour ago is in the past');
ok((bool) preg_grep('/after pickup/', booking_window_errors($dt('2030-01-02 12:00'), $dt('2030-01-02 12:00'), $now)), 'return == pickup rejected');
ok((bool) preg_grep('/30 days/', booking_window_errors($dt('2030-01-02 12:00'), $dt('2030-02-02 12:00'), $now)), '31 days rejected');
ok(booking_window_errors($dt('2030-01-02 12:00'), $dt('2030-01-31 12:00'), $now) === [], '29 days ok');
ok((bool) preg_grep('/180 days/', booking_window_errors($dt('2030-08-01 12:00'), $dt('2030-08-02 12:00'), $now)), 'too far ahead rejected');
ok(booking_window_errors(null, $dt('2030-01-02 12:00'), $now) !== [], 'missing date rejected');

echo "== availability (relative to real now)\n";
$base = (new DateTimeImmutable('tomorrow 09:00'))->modify('+2 days');
$b1 = create_booking(1, 1, $base, $base->modify('+2 days'), 0, '', 1);   // Vios, Liam, pending
ok($b1 > 0, "created booking #$b1 (pending)");
$row = get_booking($b1);
ok($row['booking_status'] === 'pending' && preg_match('/^BK-\d{6}-[0-9A-F]{6}$/', $row['booking_reference']), 'pending with BK reference ' . $row['booking_reference']);
ok($row['total_amount'] == 1800 * 2 + 2000, 'total stored from locked vehicle rate');
expect_error(fn() => create_booking(2, 1, $base->modify('+1 day'), $base->modify('+3 days'), 0, '', 1), 'already booked', 'overlapping booking rejected');
expect_error(fn() => create_booking(2, 1, $base->modify('+2 days +1 hour'), $base->modify('+3 days'), 0, '', 1), 'already booked', 'starting inside the 2h turnaround rejected');
$b2 = create_booking(2, 1, $base->modify('+2 days +2 hours'), $base->modify('+3 days'), 0, '', 1);
ok($b2 > 0, 'starting exactly at return + 2h allowed');
expect_error(fn() => create_booking(2, 1, $base->modify('-1 day'), $base->modify('-1 hour'), 0, '', 1), 'already booked', 'ending inside turnaround before next pickup rejected');
ok(create_booking(2, 1, $base->modify('-1 day'), $base->modify('-2 hours'), 0, '', 1) > 0, 'ending exactly 2h before next pickup allowed');
cancel_booking($b2, 1, 'test');
ok(vehicle_availability_problems(1, $base->modify('+2 days +2 hours'), $base->modify('+3 days')) === [], 'cancelled booking frees the slot');

expect_error(fn() => create_booking(1, 3, $base, $base->modify('+1 day'), 0, '', 1), 'overdue', 'overdue active rental blocks the car (CR-V, seed #1)');
// Keep the seed Ranger job in the workshop across the test window, whatever today's date is (Phase 9: jobs block their own days).
$db->exec("UPDATE maintenance SET end_date = '" . $base->modify('+5 days')->format('Y-m-d') . "' WHERE vehicle_id = 5 AND status = 'in_progress'");
expect_error(fn() => create_booking(1, 5, $base, $base->modify('+1 day'), 0, '', 1), 'maintenance', 'vehicle in maintenance blocked');
$db->exec("INSERT INTO maintenance (vehicle_id, maintenance_type, service_date, end_date, logged_by, status) VALUES (2, 'oil_change', '" . $base->modify('+1 day')->format('Y-m-d') . "', '" . $base->modify('+1 day')->format('Y-m-d') . "', 1, 'scheduled')");
expect_error(fn() => create_booking(1, 2, $base, $base->modify('+2 days'), 0, '', 1), 'scheduled on', 'scheduled maintenance inside window blocks');
ok(vehicle_availability_problems(2, $base->modify('+3 days'), $base->modify('+4 days')) === [], 'same car free outside maintenance day');
$db->exec("UPDATE vehicles SET deleted_at = NOW() WHERE vehicle_id = 4");
expect_error(fn() => create_booking(1, 4, $base, $base->modify('+1 day'), 0, '', 1), 'no longer in the fleet', 'archived vehicle blocked');

echo "== search agrees with the per-vehicle check\n";
$win = [$base, $base->modify('+2 days')];
$found = array_column(search_available_vehicles(...$win), 'vehicle_id');
$all = array_column($db->query('SELECT vehicle_id FROM vehicles')->fetchAll(), 'vehicle_id');
$agree = true;
foreach ($all as $vid) { $free = vehicle_availability_problems((int) $vid, ...$win) === []; if ($free !== in_array($vid, $found)) { $agree = false; echo "  mismatch vehicle $vid\n"; } }
ok($agree, 'search result == per-vehicle rules for all ' . count($all) . ' vehicles (' . count($found) . ' free)');
ok(!in_array(1, $found) && !in_array(3, $found) && !in_array(4, $found) && !in_array(5, $found), 'excludes booked, overdue, archived, maintenance');
$freeRows = search_available_vehicles(...$win); $t = $freeRows[0]['vehicle_type']; $seats = (int) $freeRows[0]['seating_capacity'];
$f = search_available_vehicles(...array_merge($win, [['type' => $t, 'min_seats' => $seats]]));
ok(count($f) >= 1 && array_reduce($f, fn($c, $v) => $c && $v['vehicle_type'] === $t && $v['seating_capacity'] >= $seats, true), "filters type ($t) + seats (>= $seats)");
ok(search_available_vehicles(...array_merge($win, [['type' => 'van']])) === [], 'only van is blocked by maintenance -> no vans');
ok(isset($found[0]) && search_available_vehicles(...$win)[0]['quote']['rental_days'] === 2, 'each result carries a quote');

echo "== customer rules\n";
$db->exec("UPDATE customers SET account_status = 'unverified' WHERE customer_id = 2");
expect_error(fn() => create_booking(2, 6, $base->modify('+5 days'), $base->modify('+6 days'), 0, '', 1), 'verified', 'unverified customer blocked');
$db->exec("UPDATE customers SET account_status = 'verified', license_expiry = '" . $base->modify('+1 day')->format('Y-m-d') . "' WHERE customer_id = 2");
expect_error(fn() => create_booking(2, 6, $base, $base->modify('+3 days'), 0, '', 1), 'before the return date', 'license expiring mid-rental blocked');
$db->exec("UPDATE customers SET license_expiry = '2030-01-01' WHERE customer_id = 2");
$db->exec("UPDATE users SET status = 'suspended' WHERE user_id = 5");
expect_error(fn() => create_booking(2, 6, $base, $base->modify('+1 day'), 0, '', 1), 'suspended', 'suspended login blocked');
$db->exec("UPDATE users SET status = 'active' WHERE user_id = 5");
ok(!in_array('Nadia Reyes', array_column(bookable_customers(), 'full_name')) === false, 'bookable_customers includes Nadia again');

echo "== lifecycle\n";
confirm_booking($b1, 2);
$row = get_booking($b1);
ok($row['booking_status'] === 'confirmed' && $row['confirmed_by_name'] === 'Maria Santos', 'confirm records who');
expect_error(fn() => confirm_booking($b1, 2), 'only a pending', 'double confirm rejected');
expect_error(fn() => mark_booking_no_show($b1, 2), "hasn't passed", 'no-show before pickup rejected');
expect_error(fn() => cancel_booking($b1, 1, '  '), 'reason', 'cancel needs a reason');
// reschedule same car keeps snapshot rate even if the price changed
$db->exec('UPDATE vehicles SET daily_rate = 2500 WHERE vehicle_id = 1');
$r = reschedule_booking($b1, 1, $base->modify('+10 days'), $base->modify('+13 days'), 0, 1);
$row = get_booking($b1);
ok($row['daily_rate_snapshot'] == 1800 && $row['rental_days'] === 3 && $row['total_amount'] == 1800 * 3 + 2000, 'reschedule same car keeps quoted rate (1800, not 2500)');
$r = reschedule_booking($b1, 7, $base->modify('+10 days'), $base->modify('+13 days'), 0, 1);
$row = get_booking($b1);
$rate7 = (float) $db->query('SELECT daily_rate FROM vehicles WHERE vehicle_id = 7')->fetchColumn();
ok($row['vehicle_id'] == 7 && $row['daily_rate_snapshot'] == $rate7, 'reschedule to another car uses its current rate');
ok(vehicle_availability_problems(1, $base->modify('+10 days'), $base->modify('+13 days')) === [], 'old car freed after moving');
// reschedule onto itself overlapping its own old window is fine (excluded)
ok(reschedule_booking($b1, 7, $base->modify('+11 days'), $base->modify('+14 days'), 0, 1)['new_total'] > 0, 'reschedule may overlap its own previous window');
// payment status recalculation
$db->exec("INSERT INTO payments (booking_id, receipt_number, amount, payment_type, payment_method, recorded_by) VALUES ($b1, 'TEST-E6-1', 99999, 'rental_fee', 'cash', 1)");
ok(recalculate_payment_status($b1) === 'paid', 'overpaid -> paid');
$db->exec("UPDATE payments SET amount = 1000 WHERE booking_id = $b1");
ok(recalculate_payment_status($b1) === 'partial', 'part paid -> partial');
cancel_booking($b1, 1, 'Customer changed plans');
$row = get_booking($b1);
ok($row['booking_status'] === 'cancelled' && $row['cancellation_reason'] === 'Customer changed plans' && $row['cancelled_by_name'] === 'Alex Rivera', 'cancel records reason + who');
expect_error(fn() => cancel_booking($b1, 1, 'again'), "can't be cancelled", 'cancelled booking can\'t be cancelled again');
expect_error(fn() => reschedule_booking($b1, 7, $base->modify('+20 days'), $base->modify('+21 days'), 0, 1), 'Only a pending', 'cancelled booking can\'t be rescheduled');
// no-show on a past confirmed booking (seed #2 is confirmed, pickup in the past)
ok(in_array('no_show', booking_allowed_actions(get_booking(2))), 'seed #2 (confirmed, past pickup) offers no-show');
mark_booking_no_show(2, 1);
ok(get_booking(2)['booking_status'] === 'no_show', 'no-show recorded');
ok(booking_allowed_actions(get_booking(1)) === ['check_in', 'extend', 'add_charge'], 'active booking offers Phase 7 actions');

echo "TOTAL: $pass passed, $fail failed\n";
