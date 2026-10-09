<?php
/**
 * Maintenance jobs, one action per POST. Admin and staff (the staff portal
 * in Phase 10 reuses this as-is). Every response is JSON; anything that
 * changes a job returns the refreshed job list ("table_html"), the
 * reminders ("due_html"), the stat numbers ("totals"), and the job ("job").
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/maintenance_data.php';
require_once __DIR__ . '/../includes/maintenance_partial.php';

header('Content-Type: application/json');

$acting_user = current_user();
if (!$acting_user || !in_array($acting_user['role_name'], ['admin', 'staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => "You don't have access to that."]);
    exit;
}
if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Your session expired. Refresh the page and try again.']);
    exit;
}

function fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function respond(?int $job_id, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success'    => true,
        'message'    => $message,
        'table_html' => render_job_rows(list_jobs()),
        'due_html'   => render_due_rows(service_due_list(true)),
        'totals'     => maintenance_totals(),
        'job'        => $job_id ? get_job($job_id) : null,
    ], $extra));
    exit;
}

function require_job(): array
{
    $job = get_job((int) ($_POST['job_id'] ?? 0));
    if (!$job) {
        fail('That maintenance job no longer exists.', 404);
    }
    return $job;
}

function job_label(array $j): string
{
    return humanize($j['maintenance_type']) . ' on ' . $j['brand'] . ' ' . $j['model'] . ' (' . $j['plate_number'] . ')';
}

$action = $_POST['action'] ?? '';
$uid = (int) $acting_user['user_id'];

try {
    switch ($action) {
        case 'get': {
            $job = require_job();
            echo json_encode(['success' => true, 'job' => $job]);
            break;
        }

        case 'schedule': {
            $id = schedule_job((int) ($_POST['vehicle_id'] ?? 0), $_POST, $uid);
            $job = get_job($id);
            log_action($uid, 'maintenance_schedule', 'Scheduled ' . job_label($job) . ' ' . format_date_range($job['service_date'], $job['end_date']));
            respond($id, 'Scheduled ' . strtolower(humanize($job['maintenance_type'])) . ' ' . format_date_range($job['service_date'], $job['end_date']) . '.');
            break;
        }

        case 'log_past': {
            $id = log_past_job((int) ($_POST['vehicle_id'] ?? 0), $_POST, $uid);
            $job = get_job($id);
            log_action($uid, 'maintenance_log', 'Logged past ' . job_label($job) . ', ' . money($job['cost']));
            respond($id, 'Past service logged.');
            break;
        }

        case 'update': {
            $job = require_job();
            update_job((int) $job['maintenance_id'], $_POST, $uid);
            log_action($uid, 'maintenance_update', 'Edited job #' . $job['maintenance_id'] . ' (' . job_label($job) . ')');
            respond((int) $job['maintenance_id'], 'Job updated.');
            break;
        }

        case 'start': {
            $job = require_job();
            start_job((int) $job['maintenance_id'], $uid, (string) ($_POST['end_date'] ?? ''));
            log_action($uid, 'maintenance_start', 'Started ' . job_label($job));
            $after = get_job((int) $job['maintenance_id']);
            respond((int) $job['maintenance_id'], $job['plate_number'] . ' is in the workshop until ' . date('M j', strtotime($after['end_date'])) . '. It can\'t be booked for those days.');
            break;
        }

        case 'extend': {
            $job = require_job();
            extend_job((int) $job['maintenance_id'], $uid, (string) ($_POST['end_date'] ?? ''));
            $after = get_job((int) $job['maintenance_id']);
            log_action($uid, 'maintenance_extend', 'Moved end of ' . job_label($job) . ' to ' . $after['end_date']);
            respond((int) $job['maintenance_id'], 'Planned end moved to ' . date('M j, Y', strtotime($after['end_date'])) . '.');
            break;
        }

        case 'complete': {
            $job = require_job();
            $released = complete_job((int) $job['maintenance_id'], $uid, $_POST);
            $after = get_job((int) $job['maintenance_id']);
            log_action($uid, 'maintenance_complete', 'Completed ' . job_label($job) . ', ' . money($after['cost']));
            $message = 'Job completed (' . money($after['cost']) . ').';
            if ($released) {
                $message .= ' ' . $job['plate_number'] . ' is back in service.';
            } elseif ($job['vehicle_status'] === 'maintenance') {
                $message .= ' The car stays in the workshop: it has another job in progress.';
            }
            respond((int) $job['maintenance_id'], $message);
            break;
        }

        case 'cancel': {
            $job = require_job();
            cancel_job((int) $job['maintenance_id'], $uid, (string) ($_POST['reason'] ?? ''));
            log_action($uid, 'maintenance_cancel', 'Cancelled ' . job_label($job) . ': ' . trim((string) $_POST['reason']));
            respond((int) $job['maintenance_id'], 'Job cancelled. Its days are free for bookings again.');
            break;
        }

        default:
            fail('Unknown action.', 400);
    }
} catch (MaintenanceError | BookingError $e) {
    fail($e->getMessage());
} catch (Throwable $e) {
    error_log('api/maintenance.php ' . $action . ': ' . $e);
    fail('Something went wrong on the server. Refresh the page to see the current state; if it keeps happening, check the PHP error log.', 500);
}
