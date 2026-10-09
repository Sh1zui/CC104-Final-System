<?php
/**
 * Session bootstrap, auth actions, and role guards.
 * Every protected page starts with:
 *   require_once __DIR__ . '/../includes/auth.php';
 *   require_role('admin');            // single role
 *   require_role('admin', 'staff');   // either role
 *   require_login();                  // any logged-in user, any role
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

// ---------------------------------------------------------------------
// Session bootstrap — runs once, the first time this file is included.
// ---------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    // Renamed in Phase 14 when the cookie path was narrowed, so an old
    // path=/ cookie from an earlier version can't shadow the new one.
    session_name('autoway_session');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_starts_with(BASE_URL, 'https://');
    session_set_cookie_params([
        'lifetime' => 0,     // cookie itself dies when the browser closes
        'path'     => parse_url(BASE_URL, PHP_URL_PATH) ?: '/', // only this app, not phpMyAdmin & co. on the same host
        'httponly' => true,  // not readable from JS — mitigates XSS token theft
        'samesite' => 'Lax',
        'secure'   => $https, // never sent over plain HTTP once the site is on HTTPS
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();

    // Idle timeout — separate from the cookie's own lifetime above. Logs
    // someone out after SESSION_LIFETIME seconds of inactivity even if
    // they never closed the tab.
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();
}

// ---------------------------------------------------------------------
// Security headers (Phase 14) — every page and endpoint loads this file.
// The CSP allows only this site: Bootstrap, the icons, Chart.js, and the
// font are all in assets/vendor, so the app works with no internet. Inline
// script is allowed for the small theme snippet. It blocks framing (clickjacking), plugins, and posting
// forms to other sites.
// ---------------------------------------------------------------------
if (!headers_sent() && PHP_SAPI !== 'cli') {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "font-src 'self'; "
        . "img-src 'self' data: blob:; connect-src 'self'; object-src 'none'; "
        . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}

// ---------------------------------------------------------------------
// Password helpers
// ---------------------------------------------------------------------
function hash_password(string $plain): string
{
    return password_hash($plain, PASSWORD_BCRYPT);
}

function verify_password(string $plain, string $hash): bool
{
    return password_verify($plain, $hash);
}

// ---------------------------------------------------------------------
// CSRF — one token per session, checked with a timing-safe compare.
// ---------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(?string $submitted_token): bool
{
    return is_string($submitted_token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submitted_token);
}

// ---------------------------------------------------------------------
// Roles — always looked up by name, never hardcoded as an ID, so the
// order rows happened to be seeded in never matters anywhere else.
// ---------------------------------------------------------------------
function role_id_by_name(string $role_name): ?int
{
    static $cache = [];

    if (!array_key_exists($role_name, $cache)) {
        $stmt = Database::getConnection()->prepare('SELECT role_id FROM roles WHERE role_name = ?');
        $stmt->execute([$role_name]);
        $row = $stmt->fetch();
        $cache[$role_name] = $row ? (int) $row['role_id'] : null;
    }

    return $cache[$role_name];
}

// ---------------------------------------------------------------------
// Login state — a lightweight snapshot lives in the session (avoids a
// join on every single page load), but status + role are re-checked
// against the users table once per request, so a suspended account or
// a role change an admin makes mid-session takes effect on the very
// next click instead of waiting for that user to log back in.
// ---------------------------------------------------------------------
function current_user(): ?array
{
    static $revalidated = false;

    if (!isset($_SESSION['user'])) {
        return null;
    }

    if (!$revalidated) {
        $revalidated = true;

        $stmt = Database::getConnection()->prepare(
            'SELECT u.status, u.session_version, r.role_name FROM users u
             JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = ?'
        );
        $stmt->execute([$_SESSION['user']['user_id']]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'active') {
            logout_user('session_terminated', 'Account suspended/deactivated while logged in');
            return null;
        }
        // Phase 14: a password change or reset bumps session_version, which
        // signs out every other session of that account (e.g. a stolen one).
        if ((int) $row['session_version'] !== (int) ($_SESSION['session_version'] ?? 0)) {
            logout_user('session_terminated', 'Password changed elsewhere; old session ended');
            return null;
        }

        $_SESSION['user']['role_name'] = $row['role_name']; // picks up a role change mid-session
    }

    return $_SESSION['user'];
}

function login_user(array $user_row): void
{
    // Regenerate the session ID on privilege change (login) so a session
    // ID an attacker fixed before authentication becomes worthless.
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'user_id'   => (int) $user_row['user_id'],
        'username'  => $user_row['username'],
        'full_name' => $user_row['full_name'],
        'email'     => $user_row['email'],
        'role_name' => $user_row['role_name'],
    ];
    $_SESSION['session_version'] = (int) ($user_row['session_version'] ?? 0);

    Database::getConnection()
        ->prepare('UPDATE users SET last_login_at = NOW() WHERE user_id = ?')
        ->execute([$user_row['user_id']]);

    log_action((int) $user_row['user_id'], 'login_success', "User '{$user_row['username']}' logged in");
}

function logout_user(string $log_as = 'logout', string $note = ''): void
{
    if (isset($_SESSION['user'])) {
        log_action($_SESSION['user']['user_id'], $log_as, $note ?: "User '{$_SESSION['user']['username']}' logged out");
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}

// ---------------------------------------------------------------------
// Route guards
// ---------------------------------------------------------------------
function relative_request_path(): ?string
{
    // BASE_URL already includes the /car_rental_system/ subfolder, and
    // redirect() adds that same prefix back on — so it has to be
    // stripped here first, or a post-login bounce doubles the folder
    // name in the resulting URL.
    $base_path    = parse_url(BASE_URL, PHP_URL_PATH) ?? '/';
    $request_path = $_SERVER['REQUEST_URI'] ?? '';

    if ($request_path !== '' && str_starts_with($request_path, $base_path)) {
        $request_path = substr($request_path, strlen($base_path));
    }

    return $request_path ?: null;
}

function require_login(): array
{
    $user = current_user();
    // Pages behind a login hold personal and money data: don't let the
    // browser keep a copy (so Back after logging out shows nothing).
    if (!headers_sent()) {
        header('Cache-Control: no-store, private');
    }

    if (!$user) {
        $_SESSION['redirect_after_login'] = relative_request_path();
        flash('error', 'Please log in to continue.');
        redirect('auth/login.php');
    }

    return $user;
}

function require_role(string ...$allowed_roles): array
{
    $user = require_login();

    if (!in_array($user['role_name'], $allowed_roles, true)) {
        flash('error', "You don't have access to that page.");
        redirect(dashboard_path_for($user['role_name']));
    }

    return $user;
}

/**
 * Where each shared page lives for a role. Admin and staff use the same
 * page bodies (includes/views/) under their own portal folder, so links
 * between pages — and the JS that builds them — ask here instead of
 * hard-coding "admin/".
 */
const PORTAL_PAGES = [
    'admin' => [
        'dashboard' => 'admin/dashboard.php', 'bookings' => 'admin/bookings.php', 'customers' => 'admin/customers.php',
        'maintenance' => 'admin/maintenance.php', 'vehicles' => 'admin/vehicles.php', 'payments' => 'admin/payments.php',
    ],
    'staff' => [
        'dashboard' => 'staff/dashboard.php', 'bookings' => 'staff/reservations.php', 'customers' => 'staff/customers.php',
        'maintenance' => 'staff/maintenance.php', 'checkout' => 'staff/checkout.php', 'checkin' => 'staff/checkin.php',
    ],
];

/** Absolute URL of a shared page for the given (or current) role, or null if that role has no such page. */
function portal_url(string $page, array $query = [], ?string $role_name = null): ?string
{
    $role_name = $role_name ?? (current_user()['role_name'] ?? '');
    $path = PORTAL_PAGES[$role_name][$page] ?? null;
    if ($path === null) {
        return null;
    }
    return BASE_URL . $path . ($query ? '?' . http_build_query($query) : '');
}

// ---------------------------------------------------------------------
// Notifications (bell in the top bar, auth/notifications.php)
// ---------------------------------------------------------------------
function unread_notification_count(int $user_id): int
{
    $stmt = Database::getConnection()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$user_id]);
    return (int) $stmt->fetchColumn();
}

/** The page a notification's target opens for this reader, or null. */
function notification_url(?string $target, string $role_name): ?string
{
    if (!$target) {
        return null;
    }
    [$kind, $id] = array_pad(explode(':', $target, 2), 2, null);
    $id = (int) $id;
    if ($role_name === 'customer') {
        return match ($kind) {
            'booking'   => BASE_URL . 'customer/my-bookings.php?open=' . $id,
            'documents' => BASE_URL . 'customer/documents.php',
            default     => null,
        };
    }
    return match ($kind) {
        'booking'     => portal_url('bookings', ['open' => $id], $role_name),
        'customer'    => portal_url('customers', ['open' => $id], $role_name),
        'maintenance' => portal_url('maintenance', ['open' => $id], $role_name),
        default       => null,
    };
}

function dashboard_path_for(string $role_name): string
{
    return match ($role_name) {
        'admin'    => 'admin/dashboard.php',
        'staff'    => 'staff/dashboard.php',
        'customer' => 'customer/dashboard.php',
        default    => 'auth/login.php',
    };
}

// ---------------------------------------------------------------------
// Audit log — every auth event, and every later money-moving action,
// writes here. NULL user_id covers events before a user is identified
// (e.g. a failed login where we don't yet know who it was).
// ---------------------------------------------------------------------
/**
 * Sign out every *other* session of this user (password changed or reset).
 * When it's the user's own change, their current session is kept.
 */
function bump_session_version(int $user_id, bool $keep_current = false): void
{
    $db = Database::getConnection();
    $db->prepare('UPDATE users SET session_version = session_version + 1 WHERE user_id = ?')->execute([$user_id]);
    if ($keep_current && isset($_SESSION['user']) && (int) $_SESSION['user']['user_id'] === $user_id) {
        $stmt = $db->prepare('SELECT session_version FROM users WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $_SESSION['session_version'] = (int) $stmt->fetchColumn();
        session_regenerate_id(true);
    }
}

// ---------------------------------------------------------------------
// Throttling (Phase 14), counted from system_logs so there's no extra
// table: failed logins per username+address and per address, and
// self-registrations per address.
// ---------------------------------------------------------------------
function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

/** Minutes to wait before another login attempt, or 0 if allowed. */
function login_wait_minutes(string $identifier): int
{
    $db = Database::getConnection();
    $since = date('Y-m-d H:i:s', time() - LOGIN_WINDOW_MINUTES * 60);
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS n, MIN(created_at) AS first FROM system_logs
         WHERE action = 'login_failed' AND ip_address = ? AND created_at >= ? AND description = ?"
    );
    $stmt->execute([client_ip(), $since, login_failure_note($identifier)]);
    $pair = $stmt->fetch();
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS n, MIN(created_at) AS first FROM system_logs
         WHERE action = 'login_failed' AND ip_address = ? AND created_at >= ?"
    );
    $stmt->execute([client_ip(), $since]);
    $ip = $stmt->fetch();

    $first = null;
    if ((int) $pair['n'] >= LOGIN_MAX_FAILURES) {
        $first = $pair['first'];
    } elseif ((int) $ip['n'] >= LOGIN_IP_MAX_FAILURES) {
        $first = $ip['first'];
    }
    if ($first === null) {
        return 0;
    }
    return max(1, (int) ceil((strtotime($first) + LOGIN_WINDOW_MINUTES * 60 - time()) / 60));
}

function login_failure_note(string $identifier): string
{
    return "Failed login attempt for '" . mb_substr(mb_strtolower(trim($identifier)), 0, 120) . "'";
}

function registrations_from_ip_last_hour(): int
{
    $stmt = Database::getConnection()->prepare(
        "SELECT COUNT(*) FROM system_logs WHERE action = 'register' AND ip_address = ? AND created_at >= ?"
    );
    $stmt->execute([client_ip(), date('Y-m-d H:i:s', time() - 3600)]);
    return (int) $stmt->fetchColumn();
}

function log_action(?int $user_id, string $action, string $description = ''): void
{
    Database::getConnection()
        ->prepare('INSERT INTO system_logs (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)')
        ->execute([$user_id, $action, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
}
