<?php
/**
 * Reports: ranges, revenue, utilization, CSV safety (Phase 12).
 * Run through tests/run.php, which gives it a fresh copy of the sample
 * data in a separate test database first.
 */
chdir(dirname(__DIR__)); $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (PHP_SAPI !== 'cli' || !getenv('AUTOWAY_DB')) { exit("Run this through tests/run.php.\n"); }
require 'includes/reports_data.php';
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; $c ? $pass++ : $fail++; }
$db = Database::getConnection();
require __DIR__ . '/fixtures.php';
use_fixed_sample_bookings($db);
$today = new DateTimeImmutable('2026-10-06');

echo "== ranges\n";
$r = report_range([], $today);
ok($r['from']->format('Y-m-d') === '2026-10-01' && $r['to']->format('Y-m-d') === '2026-10-31' && $r['preset'] === 'this_month', 'default = this month');
$r = report_range(['range' => 'last_month'], $today);
ok($r['from']->format('Y-m-d') === '2026-09-01' && $r['to']->format('Y-m-d') === '2026-09-30', 'last month');
$r = report_range(['range' => 'last_month'], new DateTimeImmutable('2026-03-31'));
ok($r['from']->format('Y-m-d') === '2026-02-01' && $r['to']->format('Y-m-d') === '2026-02-28', 'last month from Mar 31 = Feb (no month overflow)');
$r = report_range(['range' => 'last_30'], $today);
ok(range_days($r) === 30 && $r['to']->format('Y-m-d') === '2026-10-06', 'last 30 days ends today');
$r = report_range(['range' => 'last_year'], $today);
ok($r['from']->format('Y-m-d') === '2025-01-01' && $r['to']->format('Y-m-d') === '2025-12-31', 'last year');
ok(report_range(['range' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-01'], $today)['error'] !== null, 'custom end before start rejected');
ok(report_range(['range' => 'custom', 'from' => '2023-01-01', 'to' => '2026-10-01'], $today)['error'] !== null, 'custom over 2 years rejected');
ok(report_range(['range' => 'custom', 'from' => 'x', 'to' => '2026-10-01'], $today)['error'] !== null, 'custom bad date rejected');
ok(report_range(['range' => 'bogus'], $today)['preset'] === 'this_month', 'unknown preset → this month');
ok(report_granularity(report_range(['range' => 'last_90'], $today)) === 'month' && report_granularity(report_range([], $today)) === 'day', 'day buckets ≤ 62 days, months beyond');

echo "== fixtures: a closed September rental on the Accent (7)\n";
$db->exec("INSERT INTO bookings (booking_reference, customer_id, vehicle_id, pickup_datetime, return_datetime, rental_days, daily_rate_snapshot, base_amount, discount_amount, deposit_amount, total_amount, booking_status, payment_status, created_by, created_at)
           VALUES ('BK-TEST-1', 2, 7, '2026-09-01 00:00:00', '2026-09-04 00:00:00', 3, 1700, 5100, 0, 2000, 7100, 'completed', 'paid', 2, '2026-08-30 10:00:00')");
$bid = (int) $db->lastInsertId();
$db->exec("INSERT INTO rentals (booking_id, released_by, released_at, odometer_out, fuel_level_out, returned_at, returned_by, odometer_in, fuel_level_in)
           VALUES ($bid, 2, '2026-09-01 00:00:00', 21400, 100, '2026-09-04 00:00:00', 2, 21700, 100)");
$ins = $db->prepare("INSERT INTO payments (booking_id, receipt_number, amount, payment_type, refund_of, payment_method, status, recorded_by, paid_at) VALUES (?,?,?,?,?,?, 'completed', 2, ?)");
$ins->execute([$bid, 'OR-T-1', 2000, 'deposit', null, 'cash', '2026-08-31 09:00:00']);
$ins->execute([$bid, 'OR-T-2', 5100, 'rental_fee', null, 'cash', '2026-09-01 00:05:00']);
$ins->execute([$bid, 'OR-T-3', 300, 'refund', 'payment', 'cash', '2026-09-04 08:00:00']);
$ins->execute([$bid, 'OR-T-4', 500, 'deposit_applied', null, null, '2026-09-04 08:00:00']);
$ins->execute([$bid, 'OR-T-5', 1500, 'refund', 'deposit', 'cash', '2026-09-04 08:00:00']);
$db->exec("INSERT INTO maintenance (vehicle_id, maintenance_type, service_date, end_date, cost, logged_by, completed_by, completed_at, status) VALUES (7, 'carwash', '2026-09-05', '2026-09-06', 400, 2, 2, '2026-09-06 12:00:00', 'completed')");

$sep = report_range(['range' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30'], $today);
$rev = revenue_report($sep);
$t = $rev['totals'];
// Seed September: OR-1 deposit 2000 (Sep 17), OR-2 rental 6400 (Sep 18), OR-3 rental 9100 (Sep 20).
ok($t['revenue'] == 6400 + 9100 + 5100 - 300 + 500, 'revenue = earned + kept − refunds of payments: ' . $t['revenue']);
ok($t['money_in'] == 2000 + 6400 + 9100 + 5100 && $t['money_out'] == 1800, 'money in / out');
ok($t['net_cash'] == $t['money_in'] - $t['money_out'], 'net cash = in − out');
ok($t['deposits_taken'] == 2000 && $t['deposits_returned'] == 1500 && $t['deposits_kept'] == 500, 'deposit lines (Aug 31 deposit not in Sept)');
ok(count($rev['labels']) === 30 && abs(array_sum($rev['revenue']) - $t['revenue']) < 0.01, '30 day buckets, buckets sum to the total');

$veh = vehicle_report($sep, new DateTimeImmutable('2026-10-06 12:00:00'));
$acc = array_values(array_filter($veh, fn($v) => (int) $v['vehicle_id'] === 7))[0];
ok($acc['revenue'] == 5100 - 300 + 500 && $acc['rentals'] === 1, 'Accent: revenue 5,300, 1 rental');
ok($acc['days_out'] == 3.0 && $acc['utilization'] == 10.0, 'Accent: 3 days out of 30 = 10%');
ok($acc['maintenance_cost'] == 400 && $acc['jobs'] === 1 && $acc['net'] == 4900, 'Accent: maintenance 400, net 4,900');
ok(abs(array_sum(array_column($veh, 'revenue')) - $t['revenue']) < 0.01, 'vehicle revenues add up to the total');
$crv = array_values(array_filter($veh, fn($v) => (int) $v['vehicle_id'] === 3))[0];
ok($crv['days_out'] == round((strtotime('2026-10-01') - strtotime('2026-09-18 09:15')) / 86400, 1), 'CR-V still out: counted to the end of the range only (' . $crv['days_out'] . ' days)');
$cust = customer_report($sep, 100);
ok(abs(array_sum(array_column($cust, 'revenue')) - $t['revenue']) < 0.01, 'customer revenues add up to the total');

// A range still in progress only counts elapsed time.
$oct = report_range(['range' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'], $today);
$hours = elapsed_range_hours($oct, new DateTimeImmutable('2026-10-06 00:00:00'));
ok($hours == 5 * 24, 'October so far = 5 elapsed days on Oct 6');
$vo = vehicle_report($oct, new DateTimeImmutable('2026-10-06 00:00:00'));
$crv = array_values(array_filter($vo, fn($v) => (int) $v['vehicle_id'] === 3))[0];
ok($crv['utilization'] == 100.0, 'CR-V out all of October so far = 100%');
ok(elapsed_range_hours(report_range(['range' => 'custom', 'from' => '2027-01-01', 'to' => '2027-01-31'], $today), new DateTimeImmutable('2026-10-06')) == 0, 'future range: nothing elapsed');

echo "== archived cars\n";
$db->exec("UPDATE vehicles SET deleted_at = NOW() WHERE vehicle_id IN (7, 8)");
$veh = vehicle_report($sep, new DateTimeImmutable('2026-10-06 12:00:00'));
ok((bool) array_filter($veh, fn($v) => (int) $v['vehicle_id'] === 7), 'archived car with activity still listed');
ok(!array_filter($veh, fn($v) => (int) $v['vehicle_id'] === 8), 'archived car with no activity hidden');
ok(fleet_utilization($veh, $sep, new DateTimeImmutable('2026-10-06')) > 0, 'fleet utilization computed over cars in service');
$db->exec("UPDATE vehicles SET deleted_at = NULL WHERE vehicle_id IN (7, 8)");

echo "== bookings\n";
$br = booking_report($sep);
ok($br['source']['desk'] === 1 && $br['source']['online'] === 1, 'Sept: 1 desk (Liam), 1 online (Nadia seed)');
$aug = booking_report(report_range(['range' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31'], $today));
ok($aug['total'] === 1 && $aug['by_status']['completed'] === 1 && $aug['avg_days'] == 3.0, 'August: the test booking, by when it was made');
ok(rental_days_sold($sep) === 4 + 3, 'rental days sold in Sept: CR-V 4 + Accent 3');

echo "== maintenance + CSV\n";
$m = maintenance_report($sep);
ok(count($m) === 1 && $m[0]['maintenance_type'] === 'carwash' && $m[0]['days'] === 2 && $m[0]['cost'] == 400, 'maintenance by type');
[$h, $rows] = report_csv_rows('revenue', $sep);
ok($h[0] === 'Date' && count($rows) === 30, 'revenue CSV: 30 rows');
ok(report_csv_rows('nope', $sep) === null, 'unknown report → null');
ok(csv_safe('=HYPERLINK("x")') === "'=HYPERLINK(\"x\")" && csv_safe('-5+3') === "'-5+3" && csv_safe(-5.5) === -5.5 && csv_safe('Liam') === 'Liam', 'csv_safe defuses formulas, leaves numbers');
[$h, $rows] = report_csv_rows('bookings', $sep);
ok(count($rows) === 2 && in_array('online', array_column($rows, 2), true), 'bookings CSV: 2 rows with source');

echo "\nTOTAL: $pass passed, $fail failed\n";
