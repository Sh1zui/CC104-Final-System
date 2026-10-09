<?php
/**
 * Maintenance jobs, workshop status, reminders (Phase 9).
 * Run through tests/run.php, which gives it a fresh copy of the sample
 * data in a separate test database first.
 */
chdir(dirname(__DIR__)); $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (PHP_SAPI !== 'cli' || !getenv('AUTOWAY_DB')) { exit("Run this through tests/run.php.\n"); }
require 'includes/maintenance_data.php';
require 'includes/rentals_data.php';
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; $c ? $pass++ : $fail++; }
function expect_error(callable $f, string $needle, string $m) { try { $f(); ok(false, "$m (no error)"); } catch (MaintenanceError | BookingError $e) { ok(stripos($e->getMessage(), $needle) !== false, "$m — \"" . $e->getMessage() . '"'); } }
$db = Database::getConnection();
require __DIR__ . '/fixtures.php';
use_fixed_sample_bookings($db);
$d = fn(int $n) => (new DateTimeImmutable("today"))->modify(($n >= 0 ? "+" : "") . $n . " days")->format("Y-m-d");
$dt = fn(int $n, string $t = '09:00') => new DateTimeImmutable($d($n) . ' ' . $t);
$vstatus = fn(int $id) => $db->query("SELECT status FROM vehicles WHERE vehicle_id = $id")->fetchColumn();
$job = fn(array $x) => array_merge(['maintenance_type' => 'oil_change', 'description' => '', 'performed_by' => 'Shop'], $x);

echo "== reminders from the seed\n";
$due = service_due_list();
$by = array_column($due, null, 'plate_number');
ok($by['NBC 1234']['state'] === 'overdue' && $by['NBC 1234']['km_left'] === -1000, 'Vios: overdue by 1,000 km (' . service_due_text($by['NBC 1234']) . ')');
ok($by['NBF 8765']['state'] === 'overdue', 'Mirage: overdue by km (' . service_due_text($by['NBF 8765']) . ')');
ok($by['NBD 5678']['state'] === 'ok', 'Innova: ok (' . service_due_text($by['NBD 5678']) . ')');
ok(!isset($by['NBG 2468']), 'Ranger has no completed job with a next-due, so no reminder yet');
ok($due[0]['state'] === 'overdue', 'most urgent first');
$t = maintenance_totals(); ok($t['in_workshop'] === 1 && $t['overdue'] === 2, 'totals: 1 in workshop, 2 overdue');

echo "== scheduling rules\n";
expect_error(fn() => schedule_job(1, $job(['service_date' => $d(-1), 'end_date' => $d(0)]), 1), 'past', 'past start rejected');
expect_error(fn() => schedule_job(1, $job(['service_date' => $d(3), 'end_date' => $d(2)]), 1), 'before the start', 'end before start');
expect_error(fn() => schedule_job(1, $job(['service_date' => $d(1), 'end_date' => $d(70)]), 1), 'at most 60 days', 'too long');
expect_error(fn() => schedule_job(1, $job(['maintenance_type' => 'detailing', 'service_date' => $d(1), 'end_date' => $d(1)]), 1), 'type of work', 'bad type');
expect_error(fn() => schedule_job(1, $job(['service_date' => '2026-02-30', 'end_date' => $d(1)]), 1), 'valid start', 'impossible date');
$bk = create_booking(2, 2, $dt(5), $dt(7), 0, '', 1, true); // Innova booked days 5-7
expect_error(fn() => schedule_job(2, $job(['service_date' => $d(6), 'end_date' => $d(6)]), 1), 'booked during', 'job over a booking day refused');
expect_error(fn() => schedule_job(2, $job(['service_date' => $d(3), 'end_date' => $d(5)]), 1), 'booked during', 'job ending on the pickup day refused');
$j1 = schedule_job(2, $job(['service_date' => $d(9), 'end_date' => $d(11), 'maintenance_type' => 'tire_replacement']), 1);
ok($j1 > 0 && get_job($j1)['status'] === 'scheduled' && get_job($j1)['days'] === 3, 'scheduled days 9–11 (3 days)');
ok($vstatus(2) === 'available', 'scheduling does not take the car out of service yet');

echo "== bookings respect the whole job range\n";
expect_error(fn() => create_booking(1, 2, $dt(11), $dt(12), 0, '', 1), 'Maintenance (Tire replacement) is scheduled', 'booking over the job\'s LAST day refused (range, not just start)');
ok(!in_array(2, array_column(search_available_vehicles($dt(10), $dt(10, '18:00')), 'vehicle_id')), 'search hides the car mid-job');
ok(in_array(2, array_column(search_available_vehicles($dt(12, '10:00'), $dt(13)), 'vehicle_id')), 'free again the day after');

echo "== edit / cancel\n";
expect_error(fn() => update_job($j1, $job(['service_date' => $d(6), 'end_date' => $d(9)]), 1), 'booked during', 'editing into a booking refused');
update_job($j1, $job(['maintenance_type' => 'tire_replacement', 'service_date' => $d(10), 'end_date' => $d(10), 'description' => 'All four tires']), 1);
ok(get_job($j1)['service_date'] === $d(10) && get_job($j1)['description'] === 'All four tires', 'edited to day 10 only');
expect_error(fn() => cancel_job($j1, 1, '  '), 'why', 'cancel needs a reason');
cancel_job($j1, 1, 'Tires arrived late');
ok(get_job($j1)['status'] === 'cancelled' && get_job($j1)['cancel_reason'] === 'Tires arrived late', 'cancelled with reason');
ok(create_booking(1, 2, $dt(10), $dt(11), 0, '', 1) > 0, 'cancelled job frees the days for bookings');
expect_error(fn() => update_job($j1, $job(['service_date' => $d(20), 'end_date' => $d(20)]), 1), 'Only a scheduled', 'cancelled job can\'t be edited');

echo "== start → workshop\n";
$j2 = schedule_job(7, $job(['service_date' => $d(4), 'end_date' => $d(5), 'maintenance_type' => 'brake_service']), 1);
create_booking(1, 7, $dt(2), $dt(3), 0, '', 1, true); // Accent booked days 2-3
expect_error(fn() => start_job($j2, 1), 'booked during', 'starting early would eat into a booking: refused');
expect_error(fn() => start_job($j2, 1, $d(-1)), 'already passed', 'planned end in the past refused');
$j3 = schedule_job(8, $job(['service_date' => $d(1), 'end_date' => $d(2)]), 1);
start_job($j3, 2);
$g = get_job($j3);
ok($g['status'] === 'in_progress' && $g['service_date'] === $d(0) && $vstatus(8) === 'maintenance', 'started today; Soluto now in maintenance');
ok(!in_array('edit', $g['actions']) && in_array('complete', $g['actions']), 'in progress: complete/extend only');
$jr = schedule_job(3, $job(['service_date' => $d(40), 'end_date' => $d(40)]), 1);
$db->exec("UPDATE maintenance SET service_date = CURDATE(), end_date = CURDATE() WHERE maintenance_id = $jr"); // pretend it's due today
expect_error(fn() => start_job($jr, 1), 'out on rental', 'car out on a rental can\'t go into the workshop');
$db->exec("DELETE FROM maintenance WHERE maintenance_id = $jr");

echo "== vehicle form can't fake it\n";
$v8 = get_vehicle(8);
ok((bool) preg_grep('/maintenance job in progress/', validate_vehicle_input(array_merge($v8, ['status' => 'available']), 8)), 'can\'t mark a car in the workshop as available');
$v6 = get_vehicle(6);
ok((bool) preg_grep('/start a job/', validate_vehicle_input(array_merge($v6, ['status' => 'maintenance']), 6)), 'can\'t put a car in maintenance by hand');

echo "== extend\n";
$bk8 = create_booking(1, 8, $dt(6), $dt(7), 0, '', 1, true);
expect_error(fn() => extend_job($j3, 1, $d(6)), 'booked during', 'extending into a booking refused');
extend_job($j3, 1, $d(4));
ok(get_job($j3)['end_date'] === $d(4), 'extended to day 4');

echo "== complete\n";
$c = ['completed_on' => $d(0), 'cost' => '3500', 'odometer_km' => '11050', 'next_maintenance_date' => $d(180), 'next_due_km' => '16050', 'performed_by' => 'Calapan Auto Care'];
expect_error(fn() => complete_job($j3, 1, array_merge($c, ['completed_on' => $d(1)])), 'future', 'finish date in the future');
expect_error(fn() => complete_job($j3, 1, array_merge($c, ['completed_on' => $d(-3)])), 'before the job started', 'finish before start');
expect_error(fn() => complete_job($j3, 1, array_merge($c, ['cost' => ''])), 'final cost', 'cost required');
expect_error(fn() => complete_job($j3, 1, array_merge($c, ['odometer_km' => '9000'])), 'lower than', 'odometer below mileage');
expect_error(fn() => complete_job($j3, 1, array_merge($c, ['next_maintenance_date' => $d(-1)])), 'after this one', 'next date must be later');
expect_error(fn() => complete_job($j3, 1, array_merge($c, ['next_due_km' => '11000'])), 'above 11,050', 'next km must be above odometer');
ok(complete_job($j3, 2, $c) === true, 'completed: car released');
$g = get_job($j3);
ok($g['status'] === 'completed' && $g['cost'] == 3500 && $g['completed_by_name'] === 'Maria Santos' && $vstatus(8) === 'available', 'cost, who, car available');
ok((int) get_vehicle(8)['mileage_km'] === 11050, 'mileage raised to 11,050');
ok(isset(array_column(service_due_list(), null, 'plate_number')['NBJ 8642']), 'Soluto now has a next-due reminder');
ok(get_booking($bk8)['booking_status'] === 'confirmed' && vehicle_availability_problems(8, $dt(5), $dt(5, '18:00')) === [], 'car bookable again');

echo "== two jobs at once\n";
$ja = schedule_job(6, $job(['service_date' => $d(0), 'end_date' => $d(1)]), 1);
$jb = schedule_job(6, $job(['service_date' => $d(0), 'end_date' => $d(1), 'maintenance_type' => 'carwash']), 1);
start_job($ja, 1); start_job($jb, 1);
ok(complete_job($ja, 1, ['completed_on' => $d(0), 'cost' => '0']) === false && $vstatus(6) === 'maintenance', 'first done: car stays in (other job open)');
ok(complete_job($jb, 1, ['completed_on' => $d(0), 'cost' => '250']) === true && $vstatus(6) === 'available', 'second done: car released');
expect_error(fn() => cancel_job($ja, 1, 'x'), 'already completed', 'completed job can\'t be cancelled');

echo "== same-day quick job, and in-progress can't be cancelled\n";
$jq = schedule_job(1, $job(['service_date' => $d(0), 'end_date' => $d(0), 'maintenance_type' => 'carwash']), 1);
ok(in_array('complete', get_job($jq)['actions']), 'today\'s scheduled job can be completed directly');
complete_job($jq, 1, ['completed_on' => $d(0), 'cost' => '300']);
ok(get_job($jq)['status'] === 'completed' && $vstatus(1) === 'available', 'quick job done; car never left service');
$jf = schedule_job(1, $job(['service_date' => $d(30), 'end_date' => $d(30)]), 1);
expect_error(fn() => complete_job($jf, 1, ['completed_on' => $d(0), 'cost' => '1']), 'from that day', 'future scheduled job can\'t be completed yet');
expect_error(fn() => cancel_job(4, 1, 'x'), 'can\'t be cancelled', 'in-progress seed job can\'t be cancelled');

echo "== log past work\n";
expect_error(fn() => log_past_job(1, $job(['service_date' => $d(0), 'end_date' => $d(1), 'cost' => '1']), 1), 'already be finished', 'future work can\'t be logged');
$jl = log_past_job(1, $job(['service_date' => $d(-2), 'end_date' => $d(-2), 'cost' => '2800', 'odometer_km' => '18400', 'next_due_km' => '23400', 'next_maintenance_date' => $d(180)]), 1);
ok(get_job($jl)['status'] === 'completed' && (int) get_vehicle(1)['mileage_km'] === 18500, 'logged; lower odometer allowed for old work, mileage not lowered');
ok(array_column(service_due_list(), null, 'plate_number')['NBC 1234']['state'] === 'ok', 'Vios reminder cleared by the logged oil change');

echo "== check-in 'needs maintenance' opens an in-progress job\n";
check_in_booking(1, 2, new DateTimeImmutable('2026-09-22 09:30'), 10100, 100, 0, '', [], true, 'Strange noise from the rear');
$open = list_jobs(['vehicle_id' => 3, 'status' => 'in_progress']);
ok(count($open) === 1 && $open[0]['end_date'] === $d(CHECKIN_REPAIR_DAYS) && str_contains($open[0]['description'], 'Strange noise'), 'job in progress until day ' . CHECKIN_REPAIR_DAYS);
ok($vstatus(3) === 'maintenance', 'CR-V in maintenance, consistent with the job');
complete_job((int) $open[0]['maintenance_id'], 1, ['completed_on' => $d(0), 'cost' => '0']);
ok($vstatus(3) === 'available', 'completing it releases the car');

echo "== spending\n";
$t = maintenance_totals();
$expected = 3500 + 0 + 250 + 300 + 0 + (substr($d(-2), 0, 7) === date('Y-m') ? 2800 : 0);
ok($t['spent_month'] == $expected, "spent this month = $expected (logged work counts by the date it was done): " . $t['spent_month']);

echo "== a car in the workshop is only blocked for its job's days\n";
// Seed: the Ranger (5) is in for brakes until 2026-10-08.
$db->exec("UPDATE maintenance SET end_date = CURDATE() + INTERVAL 2 DAY WHERE maintenance_id = 4");
expect_error(fn() => create_booking(2, 5, $dt(1), $dt(2), 0, '', 1), 'Maintenance (Brake service) is scheduled', 'booking during the job: refused');
ok(create_booking(2, 5, $dt(10), $dt(12), 0, '', 1) > 0, 'booking for after the job: allowed even though the car is in the workshop now');
ok(in_array(5, array_column(search_available_vehicles($dt(20), $dt(21)), 'vehicle_id')), 'search offers it for later dates too');
$db->exec("UPDATE maintenance SET end_date = CURDATE() - INTERVAL 1 DAY WHERE maintenance_id = 4");
expect_error(fn() => create_booking(2, 5, $dt(20), $dt(21), 0, '', 1), 'Still in the workshop', 'overrunning job: blocked until completed');
ok(!in_array(5, array_column(search_available_vehicles($dt(30), $dt(31)), 'vehicle_id')), 'search hides an overrunning car');
ok(get_job(4)['overrunning'] === true, 'job flagged as overrunning');
$db->exec("UPDATE vehicles SET status = 'unavailable' WHERE vehicle_id = 6");
expect_error(fn() => create_booking(2, 6, $dt(40), $dt(41), 0, '', 1), 'unavailable', '"unavailable" still blocks entirely');
echo "TOTAL: $pass passed, $fail failed\n";
