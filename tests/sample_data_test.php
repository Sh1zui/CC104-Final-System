<?php
/**
 * The shipped sample data tells a consistent story on any import date, and
 * the fixes from the final review hold (verification survives an edit,
 * free cancellation of unconfirmed requests, "unavailable" survives a
 * maintenance job, notifications open the right page per role).
 * Run through tests/run.php.
 */
chdir(dirname(__DIR__)); $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (PHP_SAPI !== 'cli' || !getenv('AUTOWAY_DB')) { exit("Run this through tests/run.php.\n"); }
require_once __DIR__ . '/../includes/portal_data.php';
require_once __DIR__ . '/../includes/maintenance_data.php';
require_once __DIR__ . '/../includes/vehicles_data.php';
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; $c ? $pass++ : $fail++; }
$db = Database::getConnection();
$now = new DateTimeImmutable();

echo "== the story on import day\n";
$b1 = get_booking(1); $b2 = get_booking(2);
ok($b1['booking_status'] === 'active' && new DateTimeImmutable($b1['return_datetime']) < $now, "Liam's CR-V is out and overdue");
$overdue_h = ($now->getTimestamp() - strtotime($b1['return_datetime'])) / 3600;
ok($overdue_h > 4 && $overdue_h < 7, 'overdue by about 5 hours, not weeks (' . round($overdue_h, 1) . ' h)');
ok(get_rental(1) !== null && $b1['balance_due'] == 3200, 'released, ₱3,200 of the rental still to pay');
ok($b2['booking_status'] === 'confirmed' && new DateTimeImmutable($b2['pickup_datetime']) > $now, "Nadia's Fortuner is confirmed for the future");
ok($b2['payment_status'] === 'paid' && deposit_position(2)['held'] == 2000, 'paid in full, with her ₱2,000 as a real deposit');
ok(in_array('reschedule', booking_allowed_actions($b2), true) && in_array('cancel', booking_allowed_actions($b2), true), 'her booking can still be rescheduled or cancelled');
ok(in_array('check_in', booking_allowed_actions($b1), true), 'the CR-V can be received back');
ok(substr($b1['pickup_datetime'], 14) === '00:00' && substr($b2['pickup_datetime'], 14) === '00:00', 'times are on the hour');
$job = get_job(4);
ok($job['status'] === 'in_progress' && !$job['overrunning'] && $job['end_date'] === $now->modify('+2 days')->format('Y-m-d'), 'the Ranger is in the workshop for 2 more days');
$due = array_column(service_due_list(true), 'state', 'vehicle_id');
ok(($due[1] ?? '') === 'overdue' && ($due[4] ?? '') === 'overdue', 'service reminders: Vios and Mirage past their mileage');

echo "== verification\n";
foreach ([1, 2] as $cid) {
    $c = get_customer($cid);
    ok($c['account_status'] === 'verified' && (int) $c['approved_documents'] === 1 && customer_verification_blockers($c) === [],
       "customer $cid is verified with an approved license on file (an edit won't un-verify them)");
    ok(is_file(dirname(__DIR__) . '/' . $db->query("SELECT file_path FROM documents WHERE customer_id = $cid")->fetchColumn()), "customer $cid's license image exists");
}

echo "== cancelling an unconfirmed request is free (desk or customer)\n";
$p = new DateTimeImmutable('tomorrow 09:00'); $p = $p->modify('+5 days');
$id = create_booking(2, 8, $p, $p->modify('+2 days'), 0, '', null, false);
$db->exec("UPDATE bookings SET pickup_datetime = NOW() - INTERVAL 1 HOUR, return_datetime = NOW() + INTERVAL 1 DAY WHERE booking_id = $id");
ok(cancel_booking($id, 2, 'Never confirmed', false, true) == 0.0, 'desk cancel of a stale pending request: no fee');
$id = create_booking(2, 8, $p, $p->modify('+2 days'), 0, '', 2, true);
$db->exec("UPDATE bookings SET pickup_datetime = NOW() + INTERVAL 3 HOUR, return_datetime = NOW() + INTERVAL 2 DAY WHERE booking_id = $id");
ok(cancel_booking($id, 2, 'Late change', false, true) > 0, 'a confirmed booking inside the window still pays the fee');

echo "== vehicle status\n";
ok(!in_array('reserved', VEHICLE_STATUSES, true), '"Reserved" is no longer offered (it did nothing)');
$db->exec("UPDATE vehicles SET status = 'unavailable' WHERE vehicle_id = 7");
$jid = schedule_job(7, ['maintenance_type' => 'carwash', 'service_date' => $now->format('Y-m-d'), 'end_date' => $now->format('Y-m-d'), 'description' => '', 'performed_by' => ''], 1);
start_job($jid, 1, $now->format('Y-m-d'));
ok($db->query('SELECT status FROM vehicles WHERE vehicle_id = 7')->fetchColumn() === 'unavailable', 'starting a job keeps an "unavailable" car unavailable');
complete_job($jid, 1, ['cost' => '0']);
ok($db->query('SELECT status FROM vehicles WHERE vehicle_id = 7')->fetchColumn() === 'unavailable', 'and completing it doesn\'t make it bookable');

echo "== notifications open the right page\n";
ok(str_ends_with((string) notification_url('booking:5', 'staff'), 'staff/reservations.php?open=5'), 'staff → Reservations');
ok(str_ends_with((string) notification_url('booking:5', 'admin'), 'admin/bookings.php?open=5'), 'admin → Bookings');
ok(str_ends_with((string) notification_url('booking:5', 'customer'), 'customer/my-bookings.php?open=5'), 'customer → My bookings');
ok(str_ends_with((string) notification_url('customer:2', 'staff'), 'staff/customers.php?open=2'), 'document to review → the customer');
ok(notification_url(null, 'admin') === null && notification_url('nonsense:1', 'admin') === null, 'no target → no link');
$c = get_customer(1);
request_booking($c, 1, new DateTimeImmutable('tomorrow 10:00 +3 days'), new DateTimeImmutable('tomorrow 10:00 +4 days'), '');
ok((int) $db->query("SELECT COUNT(*) FROM notifications WHERE title = 'New online booking' AND target LIKE 'booking:%'")->fetchColumn() >= 3, 'new online booking reaches every admin and staff member, with a link');
ok(unread_notification_count(2) >= 1, 'it shows in the bell count');

echo "\nTOTAL: $pass passed, $fail failed\n";
