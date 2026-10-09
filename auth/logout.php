<?php
/**
 * Log out. POST with the CSRF token only (Phase 14), so another site can't
 * sign someone out with a hidden image or link. A plain visit just goes
 * back where the visitor belongs.
 */
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) {
    $u = current_user();
    redirect($u ? dashboard_path_for($u['role_name']) : 'auth/login.php');
}

// logout_user() reads $_SESSION['user'] to log who logged out, so it has
// to run before the session is torn down, not after.
logout_user();

// The session was just destroyed — start a fresh one so the flash
// message below actually survives to render on the login page.
session_start();
flash('success', 'You have been logged out.');
redirect('auth/login.php');
