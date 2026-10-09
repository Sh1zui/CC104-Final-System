<?php
/**
 * My account, for admin and staff (Phase 13): contact details and
 * password. Role and access are changed by another admin on Users and
 * staff. Customers use customer/profile.php instead.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/users_data.php';
require_once __DIR__ . '/../includes/portal_data.php'; // change_own_password()

$user = require_role('admin', 'staff');
$me = get_user_row((int) $user['user_id']);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
        redirect('auth/account.php');
    }
    if (($_POST['action'] ?? '') === 'details') {
        try {
            update_user($me, ['full_name' => $_POST['full_name'] ?? '', 'email' => $_POST['email'] ?? '',
                              'phone' => $_POST['phone'] ?? '', 'role' => $me['role_name']], (int) $user['user_id']);
            $_SESSION['user']['full_name'] = trim((string) $_POST['full_name']);
            $_SESSION['user']['email'] = trim((string) $_POST['email']);
            flash('success', 'Your details were saved.');
            redirect('auth/account.php');
        } catch (UserError $e) {
            $errors[] = $e->getMessage();
            $me = array_merge($me, array_intersect_key($_POST, array_flip(['full_name', 'email', 'phone'])));
        }
    }
    if (($_POST['action'] ?? '') === 'password') {
        $error = change_own_password($user, (string) ($_POST['current_password'] ?? ''), (string) ($_POST['new_password'] ?? ''),
                                     (string) ($_POST['confirm_password'] ?? ''));
        if ($error) {
            flash('error', $error);
        } else {
            log_action((int) $user['user_id'], 'password_change', 'Changed their own password');
            flash('success', 'Password changed.');
        }
        redirect('auth/account.php#password');
    }
}

$page_title = 'My account';
$active_nav = '';

require __DIR__ . '/../includes/header.php';
?>

<div class="profile-grid">
<section class="panel">
    <div class="panel-header">Your details <span class="role-badge role-<?= e($me['role_name']) ?>"><?= e($me['role_name']) ?></span></div>
    <form class="panel-body" method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="details">
        <?php if ($errors): ?><div class="alert alert-danger" role="alert"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
        <div class="row g-3">
            <div class="col-sm-6"><label class="form-label" for="a-name">Full name</label>
                <input class="form-control" id="a-name" name="full_name" maxlength="120" value="<?= e($me['full_name']) ?>" autocomplete="name"></div>
            <div class="col-sm-6"><label class="form-label" for="a-user">Username</label>
                <input class="form-control mono" id="a-user" value="<?= e($me['username']) ?>" disabled></div>
            <div class="col-sm-6"><label class="form-label" for="a-email">Email</label>
                <input type="email" class="form-control" id="a-email" name="email" maxlength="120" value="<?= e($me['email']) ?>" autocomplete="email"></div>
            <div class="col-sm-6"><label class="form-label" for="a-phone">Phone</label>
                <input type="tel" class="form-control" id="a-phone" name="phone" maxlength="20" value="<?= e((string) $me['phone']) ?>" autocomplete="tel"></div>
        </div>
        <p class="small text-secondary mt-3 mb-0">Your role and access are managed by an admin on <em>Users and staff</em>.</p>
        <div class="d-flex justify-content-end mt-3"><button type="submit" class="btn btn-brand">Save details</button></div>
    </form>
</section>

<section class="panel" id="password">
    <div class="panel-header">Password</div>
    <form class="panel-body" method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <input type="text" name="username" value="<?= e($me['username']) ?>" autocomplete="username" hidden>
        <div class="mb-3"><label class="form-label" for="pw-current">Current password</label>
            <input type="password" class="form-control" id="pw-current" name="current_password" autocomplete="current-password"></div>
        <div class="mb-3"><label class="form-label" for="pw-new">New password</label>
            <input type="password" class="form-control" id="pw-new" name="new_password" autocomplete="new-password">
            <div class="form-text">At least 8 characters, with a letter and a number.</div></div>
        <div class="mb-3"><label class="form-label" for="pw-confirm">New password again</label>
            <input type="password" class="form-control" id="pw-confirm" name="confirm_password" autocomplete="new-password"></div>
        <button type="submit" class="btn btn-outline-secondary">Change password</button>
    </form>
</section>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
