<?php
/**
 * Security rules that can be checked without a browser (Phase 14):
 * login throttling, registration limits, ending other sessions,
 * output escaping, CSV formula defusing, settings and account guards.
 * Run through tests/run.php.
 */
chdir(dirname(__DIR__)); $_SERVER['REMOTE_ADDR'] = '10.0.0.7';
if (PHP_SAPI !== 'cli' || !getenv('AUTOWAY_DB')) { exit("Run this through tests/run.php.\n"); }
require 'includes/users_data.php';
require 'includes/reports_data.php';
require 'includes/settings_data.php';
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; $c ? $pass++ : $fail++; }
$db = Database::getConnection();

echo "== login throttling\n";
ok(login_wait_minutes('admin') === 0, 'no failures: no wait');
for ($i = 0; $i < LOGIN_MAX_FAILURES - 1; $i++) { log_action(null, 'login_failed', login_failure_note('Admin')); }
ok(login_wait_minutes('admin') === 0, (LOGIN_MAX_FAILURES - 1) . ' failures: still allowed');
log_action(null, 'login_failed', login_failure_note('ADMIN '));
ok(login_wait_minutes('admin') === LOGIN_WINDOW_MINUTES, LOGIN_MAX_FAILURES . ' failures (any letter case, spaces): wait ' . LOGIN_WINDOW_MINUTES . ' minutes');
ok(login_wait_minutes('staff.maria') === 0, 'other usernames from the same address unaffected');
$_SERVER['REMOTE_ADDR'] = '10.0.0.8';
ok(login_wait_minutes('admin') === 0, 'same username from another address unaffected (no lockout of the real admin)');
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';
$db->exec("UPDATE system_logs SET created_at = created_at - INTERVAL " . (LOGIN_WINDOW_MINUTES - 5) . " MINUTE WHERE action = 'login_failed'");
ok(login_wait_minutes('admin') === 5, 'the wait counts down from the first failure');
$db->exec("UPDATE system_logs SET created_at = created_at - INTERVAL 10 MINUTE WHERE action = 'login_failed'");
ok(login_wait_minutes('admin') === 0, 'after the window: allowed again');
for ($i = 0; $i < LOGIN_IP_MAX_FAILURES; $i++) { log_action(null, 'login_failed', login_failure_note("guess$i")); }
ok(login_wait_minutes('brand.new.name') > 0, LOGIN_IP_MAX_FAILURES . ' failures across names: the address waits');

echo "== registrations per address\n";
for ($i = 0; $i < REGISTRATIONS_PER_IP_PER_HOUR; $i++) { log_action(null, 'register', 'x'); }
ok(registrations_from_ip_last_hour() === REGISTRATIONS_PER_IP_PER_HOUR, 'counted per address in the last hour');

echo "== ending other sessions\n";
$before = (int) $db->query('SELECT session_version FROM users WHERE user_id = 4')->fetchColumn();
admin_reset_password(get_user_row(4), 'Reset2026x', 1);
ok((int) $db->query('SELECT session_version FROM users WHERE user_id = 4')->fetchColumn() === $before + 1, 'admin password reset bumps the version (signs out open sessions)');
ok(password_verify('Reset2026x', $db->query('SELECT password_hash FROM users WHERE user_id = 4')->fetchColumn()), 'new password stored as bcrypt');
try { admin_reset_password(get_user_row(1), 'Whatever123', 1); ok(false, 'admin reset own password'); } catch (UserError $e) { ok(true, 'admins change their own password on their account page, not here'); }
try { admin_reset_password(get_user_row(4), 'short', 1); ok(false, 'weak password accepted'); } catch (UserError $e) { ok(true, 'password policy enforced on resets'); }

echo "== escaping\n";
ok(e('<script>alert("x")</script>') === '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', 'e() escapes tags and quotes');
ok(e("it's") === 'it&#039;s', "e() escapes single quotes");
ok(csv_safe('=1+1') === "'=1+1" && csv_safe('@SUM(A1)') === "'@SUM(A1)" && csv_safe("\t=x") === "'\t=x" && csv_safe(12.5) === 12.5, 'CSV cells that look like formulas are defused');

echo "== settings can't be poisoned\n";
ok(setting_cast('SECURITY_DEPOSIT', '-5') === null && setting_cast('SECURITY_DEPOSIT', '1e9') === null, 'out-of-range numbers rejected');
ok(setting_cast('LATE_FEE_HOURLY_PCT', '15; DROP TABLE users') === null, 'non-numbers rejected');
ok(setting_cast('BUSINESS_EMAIL', 'not-an-email') === null, 'bad email rejected');
$r = save_settings(['STAFF_MAX_DISCOUNT_PCT' => '40', 'MAX_MANUAL_DISCOUNT_PCT' => '30'], 1);
ok(isset($r['errors']['STAFF_MAX_DISCOUNT_PCT']) && (int) $db->query('SELECT COUNT(*) FROM settings')->fetchColumn() === 0, 'staff limit above admin limit refused, nothing saved');

echo "== account guards\n";
try { set_user_status(get_user_row(1), 'suspended', 1); ok(false, 'suspended self'); } catch (UserError $e) { ok(true, 'nobody suspends themselves'); }
$db->exec("UPDATE users SET status = 'suspended' WHERE user_id = 2");
try { set_user_status(get_user_row(1), 'suspended', 3); ok(false, 'last admin suspended'); } catch (UserError $e) { ok(true, 'the only active admin can\'t be suspended'); }
update_user(get_user_row(4), ['full_name' => 'Liam Torres', 'email' => 'liam@example.com', 'role' => 'admin'], 1);
ok(get_user_row(4)['role_name'] === 'customer', 'a role sent for a customer account is ignored');
try { create_staff_user(['full_name' => 'X', 'email' => 'x@x.com', 'username' => 'xuser', 'password' => 'Abcdef123', 'role' => 'customer'], 1); ok(false, 'created customer via staff form'); }
catch (UserError $e) { ok(true, 'the staff form only creates admin or staff'); }

echo "\nTOTAL: $pass passed, $fail failed\n";
