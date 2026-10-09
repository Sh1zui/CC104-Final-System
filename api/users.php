<?php
/**
 * Accounts (Phase 13): create staff/admin logins, edit, suspend or
 * reactivate any login, set a new password. Admin only; JSON always.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/users_data.php';

header('Content-Type: application/json');

$acting_user = current_user();
if (!$acting_user || $acting_user['role_name'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => "You don't have access to that."]);
    exit;
}
if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Your session expired. Refresh the page and try again.']);
    exit;
}

$me = (int) $acting_user['user_id'];

function fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function respond(int $user_id, string $message): void
{
    global $me;
    $u = get_user_row($user_id);
    echo json_encode(['success' => true, 'message' => $message, 'user' => public_user($u),
                      'table_html' => render_user_rows(list_users(), $me)]);
    exit;
}

/** What the window needs — never the password hash. */
function public_user(?array $u): ?array
{
    global $me;
    if (!$u) {
        return null;
    }
    return array_intersect_key($u, array_flip(['user_id', 'username', 'email', 'full_name', 'phone', 'status', 'role_name',
                                               'customer_id', 'last_login_at', 'created_at']))
        + ['is_self' => (int) $u['user_id'] === $me];
}

function require_target(): array
{
    $u = get_user_row((int) ($_POST['user_id'] ?? 0));
    if (!$u) {
        fail('That account no longer exists.', 404);
    }
    return $u;
}

$action = $_POST['action'] ?? '';
try {
    switch ($action) {
        case 'get':
            echo json_encode(['success' => true, 'user' => public_user(require_target())]);
            break;
        case 'create':
            $id = create_staff_user($_POST, $me);
            respond($id, 'Account created. Give them their username and password.');
            break;
        case 'update':
            $u = require_target();
            update_user($u, $_POST, $me);
            respond((int) $u['user_id'], 'Account updated.');
            break;
        case 'set_status':
            $u = require_target();
            set_user_status($u, (string) ($_POST['status'] ?? ''), $me);
            respond((int) $u['user_id'], $_POST['status'] === 'active' ? 'Account reactivated.' : 'Account suspended. They\'re signed out on their next click.');
            break;
        case 'reset_password':
            $u = require_target();
            admin_reset_password($u, (string) ($_POST['password'] ?? ''), $me);
            respond((int) $u['user_id'], 'New password set. Tell them in person or by phone, not by email.');
            break;
        default:
            fail('Unknown action.', 400);
    }
} catch (UserError $e) {
    fail($e->getMessage());
} catch (Throwable $e) {
    error_log('api/users.php ' . $action . ': ' . $e);
    fail('Something went wrong on the server. Refresh the page and try again.', 500);
}
