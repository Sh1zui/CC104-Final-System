<?php
require_once __DIR__ . '/../includes/auth.php';

if (current_user()) {
    redirect(dashboard_path_for(current_user()['role_name']));
}

$errors = [];
$old_identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired — please try again.';
    } else {
        $identifier     = trim($_POST['identifier'] ?? '');
        $password       = $_POST['password'] ?? '';
        $old_identifier = $identifier;

        if ($identifier === '' || $password === '') {
            $errors[] = 'Enter your username/email and password.';
        } elseif ($wait = login_wait_minutes($identifier)) {
            // Phase 14: too many failures for this username from this
            // address (or from this address overall). Counted from
            // system_logs; the wait ends on its own.
            log_action(null, 'login_throttled', login_failure_note($identifier));
            $errors[] = 'Too many failed attempts. Try again in ' . $wait . ' minute' . ($wait === 1 ? '' : 's') . ', or ask the desk to reset your password.';
        } else {
            $stmt = Database::getConnection()->prepare(
                'SELECT u.*, r.role_name
                 FROM users u
                 JOIN roles r ON r.role_id = u.role_id
                 WHERE u.username = :by_username OR u.email = :by_email
                 LIMIT 1'
            );
            // Two separate placeholders for the same value on purpose —
            // native prepared statements can be unreliable with the same
            // named parameter repeated twice in one query.
            $stmt->execute(['by_username' => $identifier, 'by_email' => $identifier]);
            $user = $stmt->fetch();

            // Always run one bcrypt check, even for an unknown username, so
            // the response time doesn't reveal which usernames exist.
            $hash = $user['password_hash'] ?? '$2y$10$hrml3OGZhK.n1Lv.pIqMUevG4sFwyG9mkXJ4CWkKGSY6WeOyIyn2a' /* a real hash of random bytes */;
            $password_ok = verify_password($password, $hash) && $user;

            if (!$password_ok) {
                // Same generic message whether the account doesn't exist or
                // the password is wrong. The note (not the user id) is what
                // the throttle counts, so it works for unknown names too.
                log_action($user['user_id'] ?? null, 'login_failed', login_failure_note($identifier));
                $errors[] = 'Incorrect username/email or password.';
            } elseif ($user['status'] !== 'active') {
                $errors[] = 'This account is ' . $user['status'] . '. Contact an administrator.';
            } else {
                login_user($user);
                // The sample accounts all share one published password.
                if (verify_password('Password123!', $user['password_hash'])) {
                    flash('error', 'You are using the sample password. Change it now: '
                        . ($user['role_name'] === 'customer' ? 'Profile → Password.' : 'click your name in the sidebar → Password.'));
                }

                // Send them back to whatever protected page they originally
                // asked for before require_login() bounced them here, if any.
                $destination = $_SESSION['redirect_after_login'] ?? null;
                unset($_SESSION['redirect_after_login']);
                redirect($destination ?: dashboard_path_for($user['role_name']));
            }
        }
    }
}

$success = flash('success');
$notice  = flash('error'); // e.g. "Please log in to continue." from require_login()
?>
<!doctype html>
<html lang="en">
<?php $page_title = 'Log in'; require __DIR__ . '/../includes/auth_head.php'; ?>
<body class="auth-page">
<?php require __DIR__ . '/../includes/road_scene.php'; ?>
<div class="container">
    <div class="row justify-content-center align-items-center" style="min-height: 100vh;">
        <div class="col-11 col-sm-8 col-md-5 col-lg-4">
            <div class="text-center mb-4">
                <h1 class="h4 fw-semibold d-inline-flex align-items-center gap-2 mb-1">
                    <a class="auth-home" href="<?= e(BASE_URL) ?>" title="Back to the front page"><img class="brand-logo" src="<?= e(BASE_URL) ?>assets/img/logo.svg" alt="" width="36" height="36"> <?= e(APP_NAME) ?></a>
                </h1>
                <p class="text-muted mb-0">Log in to your account</p>
            </div>

            <div class="panel auth-card">
                <div class="panel-body p-4">
                    <?php if ($success): ?>
                        <div class="alert alert-success py-2"><?= e($success) ?></div>
                    <?php endif; ?>

                    <?php if ($notice): ?>
                        <div class="alert alert-warning py-2"><?= e($notice) ?></div>
                    <?php endif; ?>

                    <?php foreach ($errors as $error): ?>
                        <div class="alert alert-danger py-2"><?= e($error) ?></div>
                    <?php endforeach; ?>

                    <form method="post" novalidate>
                        <?= csrf_field() ?>

                        <div class="mb-3">
                            <label for="identifier" class="form-label">Username or email</label>
                            <input type="text" class="form-control" id="identifier" name="identifier"
                                   value="<?= e($old_identifier) ?>" autocomplete="username" required autofocus>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                        </div>

                        <button type="submit" class="btn btn-brand w-100">Log in</button>
                    </form>
                </div>
            </div>

            <p class="text-center text-muted mt-3 mb-0">
                New here? <a href="<?= e(BASE_URL) ?>auth/register.php">Create a customer account</a>
            </p>
        </div>
    </div>
</div>
</body>
</html>
