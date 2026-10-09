<?php
/**
 * All vehicle mutations, admin-only, one action per POST. Every action
 * returns JSON: {"success": true, ...} or {"success": false, "error": "..."}.
 * A successful list-changing action also returns "table_html" — the
 * refreshed <tbody> rows — so the page never needs a second request just
 * to see its own change, and the row markup has exactly one source (the
 * same partial admin/vehicles.php uses on first load).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vehicles_data.php';
require_once __DIR__ . '/../includes/vehicle_table_partial.php';

header('Content-Type: application/json');

// An AJAX 403/redirect is useless to fetch() — it just sees non-JSON HTML.
// So the RBAC/CSRF failures here respond in JSON instead of using
// require_role()'s normal redirect-to-login behaviour.
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

$action = $_POST['action'] ?? '';

function respond_with_table(string $message = ''): void
{
    $refreshed = filter_input(INPUT_POST, 'archived', FILTER_VALIDATE_BOOLEAN) ?? false;
    $vehicles  = list_vehicles(['archived' => $refreshed]);
    echo json_encode([
        'success'    => true,
        'message'    => $message,
        'table_html' => render_vehicle_rows($vehicles, $refreshed),
    ]);
    exit;
}

function fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

switch ($action) {
    case 'get': {
        $vehicle_id = (int) ($_POST['vehicle_id'] ?? 0);
        $vehicle = get_vehicle($vehicle_id);
        if (!$vehicle) {
            fail('That vehicle no longer exists.', 404);
        }
        echo json_encode([
            'success'      => true,
            'vehicle'      => $vehicle,
            'images_html'  => render_image_manager($vehicle_id),
            'upcoming'     => vehicle_upcoming_bookings($vehicle_id),
            'maintenance'  => vehicle_maintenance_history($vehicle_id),
        ]);
        break;
    }

    case 'create': {
        $errors = validate_vehicle_input($_POST);
        if ($errors) {
            fail(implode(' ', $errors));
        }
        $id = create_vehicle($_POST);
        log_action($acting_user['user_id'], 'vehicle_create', "Added vehicle #$id ({$_POST['brand']} {$_POST['model']})");
        $refreshed = filter_input(INPUT_POST, 'archived', FILTER_VALIDATE_BOOLEAN) ?? false;
        echo json_encode([
            'success'    => true,
            'message'    => 'Vehicle added. You can add photos now.',
            'table_html' => render_vehicle_rows(list_vehicles(['archived' => $refreshed]), $refreshed),
            'new_id'     => $id,
        ]);
        break;
    }

    case 'update': {
        $vehicle_id = (int) ($_POST['vehicle_id'] ?? 0);
        if (!get_vehicle($vehicle_id)) {
            fail('That vehicle no longer exists.', 404);
        }
        $errors = validate_vehicle_input($_POST, $vehicle_id);
        if ($errors) {
            fail(implode(' ', $errors));
        }
        update_vehicle($vehicle_id, $_POST);
        log_action($acting_user['user_id'], 'vehicle_update', "Updated vehicle #$vehicle_id");
        respond_with_table('Vehicle updated.');
        break;
    }

    case 'archive': {
        $vehicle_id = (int) ($_POST['vehicle_id'] ?? 0);
        $vehicle = get_vehicle($vehicle_id);
        if (!$vehicle) {
            fail('That vehicle no longer exists.', 404);
        }
        $blocking = vehicle_blocking_bookings($vehicle_id);
        if ($blocking) {
            fail('This vehicle has ' . count($blocking) . ' pending, confirmed, or active booking(s). Resolve those first.');
        }
        $stmt = Database::getConnection()->prepare("SELECT COUNT(*) FROM maintenance WHERE vehicle_id = ? AND status IN ('scheduled', 'in_progress')");
        $stmt->execute([$vehicle_id]);
        if ($open_jobs = (int) $stmt->fetchColumn()) {
            fail("This vehicle has $open_jobs open maintenance job(s). Complete or cancel them on the Maintenance page first.");
        }
        archive_vehicle($vehicle_id);
        log_action($acting_user['user_id'], 'vehicle_archive', "Archived vehicle #$vehicle_id ({$vehicle['brand']} {$vehicle['model']})");
        respond_with_table('Vehicle archived.');
        break;
    }

    case 'restore': {
        $vehicle_id = (int) ($_POST['vehicle_id'] ?? 0);
        if (!get_vehicle($vehicle_id)) {
            fail('That vehicle no longer exists.', 404);
        }
        restore_vehicle($vehicle_id);
        log_action($acting_user['user_id'], 'vehicle_restore', "Restored vehicle #$vehicle_id");
        respond_with_table('Vehicle restored.');
        break;
    }

    case 'upload_image': {
        $vehicle_id = (int) ($_POST['vehicle_id'] ?? 0);
        $vehicle = get_vehicle($vehicle_id);
        if (!$vehicle) {
            fail('That vehicle no longer exists.', 404);
        }

        $file = $_FILES['image'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            fail('Choose an image to upload.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            fail('Upload failed (error code ' . $file['error'] . '). Try a smaller file.');
        }
        if ($file['size'] > MAX_UPLOAD_BYTES) {
            fail('That image is larger than ' . (MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            fail('Upload failed. Try again.');
        }

        // Trust the file's actual bytes, never the client-supplied MIME
        // type or the original filename/extension.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $real_type = $finfo->file($file['tmp_name']);
        $extension_by_type = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $extension = $extension_by_type[$real_type] ?? null;
        if (!in_array($real_type, ALLOWED_IMAGE_TYPES, true) || $extension === null) {
            fail('Only JPEG, PNG, or WebP images are allowed.');
        }

        $filename = 'vehicle_' . $vehicle_id . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR_VEHICLES . $filename)) {
            fail('Could not save the uploaded file. Check folder permissions.', 500);
        }

        $make_primary = empty(vehicle_images($vehicle_id)); // first image is primary automatically anyway
        add_vehicle_image($vehicle_id, 'assets/uploads/vehicles/' . $filename, $make_primary);
        log_action($acting_user['user_id'], 'vehicle_image_upload', "Added an image to vehicle #$vehicle_id");

        echo json_encode(['success' => true, 'images_html' => render_image_manager($vehicle_id)]);
        break;
    }

    case 'delete_image': {
        $image_id = (int) ($_POST['image_id'] ?? 0);
        $image = get_vehicle_image($image_id);
        if (!$image) {
            fail('That image is already gone.', 404);
        }
        delete_vehicle_image($image_id);
        log_action($acting_user['user_id'], 'vehicle_image_delete', "Removed an image from vehicle #{$image['vehicle_id']}");

        echo json_encode(['success' => true, 'images_html' => render_image_manager((int) $image['vehicle_id'])]);
        break;
    }

    case 'set_primary_image': {
        $vehicle_id = (int) ($_POST['vehicle_id'] ?? 0);
        $image_id   = (int) ($_POST['image_id'] ?? 0);
        if (!get_vehicle($vehicle_id)) {
            fail('That vehicle no longer exists.', 404);
        }
        set_primary_vehicle_image($vehicle_id, $image_id);

        echo json_encode(['success' => true, 'images_html' => render_image_manager($vehicle_id)]);
        break;
    }

    default:
        fail('Unknown action.', 400);
}
