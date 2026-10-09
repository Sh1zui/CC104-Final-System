<?php
/**
 * The quick tour and the guide: who sees the tour, that seeing it is
 * remembered, and that every role's tour and guide is complete and
 * reflects the live settings. Run through tests/run.php.
 */
chdir(dirname(__DIR__)); $_SERVER['REMOTE_ADDR'] = '10.0.0.9';
if (PHP_SAPI !== 'cli' || !getenv('AUTOWAY_DB')) { exit("Run this through tests/run.php.\n"); }
require 'includes/users_data.php';
require 'includes/guide.php';
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; $c ? $pass++ : $fail++; }
$db = Database::getConnection();

echo "== who sees the quick tour\n";
$seeded = $db->query('SELECT user_id FROM users')->fetchAll(PDO::FETCH_COLUMN);
ok(count($seeded) === 5 && !array_filter($seeded, fn ($id) => tour_pending((int) $id)), 'the 5 sample accounts are existing users: no tour');

$staff_id = create_staff_user(['full_name' => 'Tess Desk', 'username' => 'tess.desk', 'email' => 'tess@autoway.local',
                               'password' => 'Welcome2026', 'role' => 'staff'], 1);
ok(tour_pending($staff_id), 'a staff account added by an admin is new: tour pending');
$admin_id = create_staff_user(['full_name' => 'Ana Boss', 'username' => 'ana.boss', 'email' => 'ana@autoway.local',
                               'password' => 'Welcome2026', 'role' => 'admin'], 1);
ok(tour_pending($admin_id), 'a new admin account: tour pending');
$db->prepare("INSERT INTO users (role_id, username, email, password_hash, full_name) VALUES (3, 'new.cust', 'nc@example.com', ?, 'Nico Cruz')")
   ->execute([hash_password('Welcome2026')]);
$cust_id = (int) $db->lastInsertId();
ok(tour_pending($cust_id), 'a customer who signs up: tour pending');

mark_tour_done($staff_id);
ok(!tour_pending($staff_id), 'finished or skipped: not shown again');
$first = $db->query("SELECT tour_completed_at FROM users WHERE user_id = $staff_id")->fetchColumn();
$db->exec("UPDATE users SET tour_completed_at = tour_completed_at - INTERVAL 1 DAY WHERE user_id = $staff_id");
mark_tour_done($staff_id);
$second = $db->query("SELECT tour_completed_at FROM users WHERE user_id = $staff_id")->fetchColumn();
ok($second < $first, 'a replay keeps the first completion date');
ok(tour_pending($admin_id) && tour_pending($cust_id), 'marking one user leaves the others alone');
ok(!tour_pending(999999), 'unknown user: no tour');

echo "== tour steps per role\n";
$targets = [
    'admin'    => ['.stat-grid', '[data-nav="vehicles"]', '[data-nav="bookings"]', '[data-nav="reports"]', '[data-nav="settings"]', '.notif-btn', '#palette-btn', '[data-nav="guide"]'],
    'staff'    => ['.stat-grid', '[data-nav="reservations"]', '[data-nav="checkout"]', '[data-nav="checkin"]', '[data-nav="customers"]', '.notif-btn', '#palette-btn', '[data-nav="guide"]'],
    'customer' => ['[data-nav="documents"]', '[data-nav="browse"]', '[data-nav="bookings"]', '[data-nav="payments"]', '.notif-btn', '#palette-btn', '[data-nav="guide"]'],
];
foreach ($targets as $role => $expected) {
    $steps = tour_steps($role, 'Pat');
    ok($steps[0]['target'] === null && str_contains($steps[0]['title'], 'Pat'), "$role: starts with a centered welcome by first name");
    ok(array_column(array_slice($steps, 1), 'target') === $expected, "$role: points at its own pages, then bell, palette, Guide");
    ok(!array_filter($steps, fn ($s) => trim($s['title']) === '' || strlen($s['body']) < 40), "$role: every step has a title and a real explanation");
}

echo "== sidebar has every tour target and the Guide link\n";
$sidebar = file_get_contents('includes/sidebar.php');
foreach (['vehicles', 'bookings', 'reports', 'settings', 'reservations', 'checkout', 'checkin', 'customers', 'documents', 'browse', 'payments'] as $key) {
    ok(str_contains($sidebar, "['$key',"), "sidebar item '$key' exists for its tour step");
}
ok(substr_count($sidebar, "'auth/guide.php'") === 3, 'Guide is in all three sidebars');
ok(str_contains($sidebar, 'data-nav="<?= e($key) ?>"'), 'sidebar links carry data-nav for the tour');

echo "== full guide content\n";
$expect = ['admin' => 13, 'staff' => 9, 'customer' => 7];
foreach ($expect as $role => $count) {
    $sections = guide_sections($role);
    ok(count($sections) === $count, "$role: $count guides");
    $ids = array_column($sections, 'id');
    ok(count($ids) === count(array_unique($ids)) && !array_filter($ids, fn ($i) => !preg_match('/^[a-z-]+$/', $i)), "$role: unique, link-safe section ids");
    $all = '';
    foreach ($sections as $sec) {
        $all .= $sec['intro'] . implode(' ', $sec['steps']) . ($sec['tip'] ?? '');
        ok(count($sec['steps']) >= 3, "$role / {$sec['title']}: at least 3 steps");
    }
    ok(substr_count($all, '**') % 2 === 0, "$role: every **bold** is closed");
}
ok(!array_intersect(['vehicles', 'reports', 'users', 'settings'], array_column(guide_sections('staff'), 'id')), 'staff guides leave out admin-only pages');
ok(!array_intersect(['new-booking', 'release', 'maintenance'], array_column(guide_sections('customer'), 'id')), 'customer guides leave out desk work');

echo "== numbers come from the live settings\n";
$text = fn ($role) => implode(' ', array_map(fn ($s) => $s['intro'] . implode(' ', $s['steps']) . ($s['tip'] ?? ''), guide_sections($role)));
ok(str_contains($text('customer'), money(SECURITY_DEPOSIT)), 'customer: deposit amount from settings');
ok(str_contains($text('customer'), FREE_CANCELLATION_HOURS . ' hours before pickup'), 'customer: free-cancellation window from settings');
ok(str_contains($text('staff'), 'up to ' . STAFF_MAX_DISCOUNT_PCT . '%'), 'staff: discount limit from settings');
ok(str_contains($text('admin'), EARLY_CHECKOUT_MINUTES . ' minutes before pickup'), 'admin: early release window from settings');

echo "== formatting is escaped\n";
ok(guide_text('Click **Save** <b>x</b>') === 'Click <strong>Save</strong> &lt;b&gt;x&lt;/b&gt;', '**bold** becomes <strong>; HTML in text is escaped');

echo "\n$pass passed, $fail failed\n";
