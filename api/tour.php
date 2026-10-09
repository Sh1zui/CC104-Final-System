<?php
/**
 * Quick tour state. POST action=complete (finished or skipped) records
 * that this user has seen it, so it doesn't run again on their next
 * dashboard visit. Any logged-in role; CSRF required; JSON out.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/guide.php';

header('Content-Type: application/json');

$acting_user = current_user();
if (!$acting_user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please log in again.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Your session expired. Refresh the page and try again.']);
    exit;
}

try {
    if (($_POST['action'] ?? '') !== 'complete') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
        exit;
    }
    mark_tour_done((int) $acting_user['user_id']);
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('api/tour.php: ' . $e);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong on our side.']);
}
