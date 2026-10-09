<?php
require_once __DIR__ . '/../includes/auth.php';

if (current_user()) {
    redirect(dashboard_path_for(current_user()['role_name']));
}

$errors = [];
$old = ['full_name' => '', 'username' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired — please refresh and try again.';
    } else {
        $old['full_name'] = trim($_POST['full_name'] ?? '');
        $old['username']  = trim($_POST['username'] ?? '');
        $old['email']     = trim($_POST['email'] ?? '');
        $old['phone']     = trim($_POST['phone'] ?? '');
        $password         = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (registrations_from_ip_last_hour() >= REGISTRATIONS_PER_IP_PER_HOUR) {
            $errors[] = 'Too many accounts were created from this connection in the last hour. Please try again later, or ask the desk.';
        }
        if ($old['full_name'] === '' || mb_strlen($old['full_name']) > 120) {
            $errors[] = 'Full name is required (up to 120 characters).';
        }
        if ($old['phone'] !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $old['phone'])) {
            $errors[] = 'Phone may only contain digits, spaces, and + ( ) - (7–20 characters).';
        }
        if (!preg_match('/^[a-zA-Z0-9_.]{4,50}$/', $old['username'])) {
            $errors[] = 'Username must be 4–50 characters: letters, numbers, "." and "_" only.';
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || strlen($old['email']) > 120) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($password_error = password_policy_error($password)) {
            $errors[] = $password_error;
        }
        if ($password !== $confirm_password) {
            $errors[] = 'Passwords do not match.';
        }

        $pdo = Database::getConnection();

        if (!$errors) {
            $dupe = $pdo->prepare('SELECT user_id FROM users WHERE username = :username OR email = :email');
            $dupe->execute(['username' => $old['username'], 'email' => $old['email']]);
            if ($dupe->fetch()) {
                $errors[] = 'That username or email is already registered.';
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                $insert_user = $pdo->prepare(
                    'INSERT INTO users (role_id, username, email, password_hash, full_name, phone)
                     VALUES (:role_id, :username, :email, :password_hash, :full_name, :phone)'
                );
                $insert_user->execute([
                    'role_id'       => role_id_by_name('customer'),
                    'username'      => $old['username'],
                    'email'         => $old['email'],
                    'password_hash' => hash_password($password),
                    'full_name'     => $old['full_name'],
                    'phone'         => $old['phone'] !== '' ? $old['phone'] : null,
                ]);
                $new_user_id = (int) $pdo->lastInsertId();

                $pdo->prepare('INSERT INTO customers (user_id) VALUES (:user_id)')
                    ->execute(['user_id' => $new_user_id]);

                $pdo->commit();

                log_action($new_user_id, 'register', 'New customer account created via self-registration');
                flash('success', 'Account created — you can log in now.');
                redirect('auth/login.php');
            } catch (Throwable $e) {
                $pdo->rollBack();

                // SQLSTATE 23000 = integrity constraint violation. Covers the
                // race where two people submit the same username/email
                // between our SELECT check above and this INSERT — the
                // UNIQUE constraints on users.username/email are the real
                // guard; the earlier check is just for a friendlier message
                // in the common (non-race) case.
                if ($e instanceof PDOException && $e->getCode() === '23000') {
                    $errors[] = 'That username or email is already registered.';
                } else {
                    error_log('Registration failed: ' . $e->getMessage());
                    $errors[] = 'Something went wrong creating your account. Please try again.';
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<?php $page_title = 'Create account'; require __DIR__ . '/../includes/auth_head.php'; ?>
<body class="auth-page">
<?php require __DIR__ . '/../includes/road_scene.php'; ?>
<div class="container">
    <div class="row justify-content-center py-5">
        <div class="col-11 col-sm-9 col-md-6 col-lg-5">
            <div class="text-center mb-4">
                <h1 class="h4 fw-semibold d-inline-flex align-items-center gap-2 mb-1">
                    <a class="auth-home" href="<?= e(BASE_URL) ?>" title="Back to the front page"><img class="brand-logo" src="<?= e(BASE_URL) ?>assets/img/logo.svg" alt="" width="36" height="36"> <?= e(APP_NAME) ?></a>
                </h1>
                <p class="text-muted mb-0">Create your customer account</p>
            </div>

            <div class="panel auth-card">
                <div class="panel-body p-4">
                    <?php foreach ($errors as $error): ?>
                        <div class="alert alert-danger py-2"><?= e($error) ?></div>
                    <?php endforeach; ?>

                    <form method="post" novalidate>
                        <?= csrf_field() ?>

                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name"
                                   value="<?= e($old['full_name']) ?>" autocomplete="name" required autofocus>
                        </div>

                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username"
                                   value="<?= e($old['username']) ?>" minlength="4" maxlength="50"
                                   pattern="[a-zA-Z0-9_.]+" autocomplete="username" required>
                            <div class="form-text">Letters, numbers, "." and "_" only.</div>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?= e($old['email']) ?>" autocomplete="email" required>
                        </div>

                        <div class="mb-3">
                            <label for="phone" class="form-label">Phone <span class="text-muted">(optional)</span></label>
                            <input type="tel" class="form-control" id="phone" name="phone" autocomplete="tel" value="<?= e($old['phone']) ?>">
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" minlength="8" autocomplete="new-password" required>
                            <div class="form-text">At least 8 characters, with a letter and a number.</div>
                        </div>

                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required>
                        </div>

                        <button type="submit" class="btn btn-brand w-100">Create account</button>
                    </form>
                </div>
            </div>

            <p class="text-center text-muted mt-3 mb-0">
                Already have an account? <a href="<?= e(BASE_URL) ?>auth/login.php">Log in</a>
            </p>
        </div>
    </div>
</div>
</body>
</html>
