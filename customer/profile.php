<?php
/**
 * The customer's own details and password. Plain form posts
 * (POST → redirect → GET). Once the desk has verified the account, the
 * identity fields that were checked (license, ID, birth date) are locked;
 * changing them goes through the desk.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/portal_data.php';

$user = require_role('customer');
$customer = portal_customer($user);
$locked = $customer['account_status'] === 'verified';
$errors = [];
$old = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
        redirect('customer/profile.php');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'profile') {
        $errors = update_own_profile($customer, $_POST);
        if (!$errors) {
            log_action((int) $user['user_id'], 'profile_update', 'Customer updated their profile');
            // The sidebar and header read the name from the session.
            $_SESSION['user']['full_name'] = trim((string) $_POST['full_name']);
            $_SESSION['user']['email'] = trim((string) $_POST['email']);
            flash('success', 'Your details were saved.');
            redirect('customer/profile.php');
        }
        $old = $_POST; // re-show what they typed
    }

    if ($action === 'password') {
        $error = change_own_password($user, (string) ($_POST['current_password'] ?? ''), (string) ($_POST['new_password'] ?? ''),
                                     (string) ($_POST['confirm_password'] ?? ''));
        if ($error) {
            flash('error', $error);
        } else {
            log_action((int) $user['user_id'], 'password_change', 'Customer changed their password');
            flash('success', 'Password changed.');
        }
        redirect('customer/profile.php#password');
    }
}

$val = fn (string $k) => (string) ($old[$k] ?? $customer[$k] ?? '');

$page_title = 'Profile';
$active_nav = 'profile';

require __DIR__ . '/../includes/header.php';
?>

<div class="profile-grid">
<section class="panel">
    <div class="panel-header">Your details <span class="status-badge <?= e(status_badge_class($customer['account_status'])) ?>"><?= e($customer['account_status']) ?></span></div>
    <form class="panel-body" method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="profile">
        <?php if ($errors): ?>
            <div class="alert alert-danger" role="alert"><?= implode('<br>', array_map('e', $errors)) ?></div>
        <?php endif; ?>
        <div class="row g-3">
            <div class="col-sm-6">
                <label class="form-label" for="p-name">Full name</label>
                <input type="text" class="form-control" id="p-name" name="full_name" maxlength="120" required value="<?= e($val('full_name')) ?>" autocomplete="name">
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="p-email">Email</label>
                <input type="email" class="form-control" id="p-email" name="email" maxlength="120" required value="<?= e($val('email')) ?>" autocomplete="email">
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="p-phone">Mobile number</label>
                <input type="tel" class="form-control" id="p-phone" name="phone" maxlength="20" value="<?= e($val('phone')) ?>" autocomplete="tel">
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="p-dob">Date of birth</label>
                <input type="date" class="form-control" id="p-dob" name="date_of_birth" value="<?= e($val('date_of_birth')) ?>" <?= $locked ? 'disabled' : '' ?>>
            </div>
            <div class="col-sm-8">
                <label class="form-label" for="p-address">Address</label>
                <input type="text" class="form-control" id="p-address" name="address_line" maxlength="255" value="<?= e($val('address_line')) ?>" autocomplete="street-address">
            </div>
            <div class="col-sm-4">
                <label class="form-label" for="p-city">City</label>
                <input type="text" class="form-control" id="p-city" name="city" maxlength="100" value="<?= e($val('city')) ?>" autocomplete="address-level2">
            </div>
        </div>

        <h3 class="form-section-title">Driver's license and ID</h3>
        <?php if ($locked): ?>
            <p class="small text-secondary">These were checked when your account was verified. To change them, upload the new document on
                <a href="<?= e(BASE_URL) ?>customer/documents.php">My documents</a> and ask the desk to update your account.</p>
        <?php endif; ?>
        <div class="row g-3">
            <div class="col-sm-6">
                <label class="form-label" for="p-license">License number</label>
                <input type="text" class="form-control mono" id="p-license" name="license_number" maxlength="50" value="<?= e($val('license_number')) ?>" <?= $locked ? 'disabled' : '' ?>>
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="p-expiry">License expiry</label>
                <input type="date" class="form-control" id="p-expiry" name="license_expiry" value="<?= e($val('license_expiry')) ?>" <?= $locked ? 'disabled' : '' ?>>
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="p-idtype">Other ID</label>
                <select class="form-select" id="p-idtype" name="id_type" <?= $locked ? 'disabled' : '' ?>>
                    <?php foreach (ID_TYPES as $t): ?>
                        <option value="<?= e($t) ?>" <?= $val('id_type') === $t ? 'selected' : '' ?>><?= e(humanize($t)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="p-idnum">ID number</label>
                <input type="text" class="form-control mono" id="p-idnum" name="id_number" maxlength="50" value="<?= e($val('id_number')) ?>" <?= $locked ? 'disabled' : '' ?>>
            </div>
        </div>
        <div class="d-flex justify-content-end mt-3">
            <button type="submit" class="btn btn-brand">Save details</button>
        </div>
    </form>
</section>

<section class="panel" id="password">
    <div class="panel-header">Password</div>
    <form class="panel-body" method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <input type="text" name="username" value="<?= e($user['username']) ?>" autocomplete="username" hidden>
        <div class="mb-3">
            <label class="form-label" for="pw-current">Current password</label>
            <input type="password" class="form-control" id="pw-current" name="current_password" required autocomplete="current-password">
        </div>
        <div class="mb-3">
            <label class="form-label" for="pw-new">New password</label>
            <input type="password" class="form-control" id="pw-new" name="new_password" required autocomplete="new-password" minlength="8">
            <div class="form-text">At least 8 characters, with a letter and a number.</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="pw-confirm">New password again</label>
            <input type="password" class="form-control" id="pw-confirm" name="confirm_password" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-outline-secondary">Change password</button>
    </form>
</section>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
