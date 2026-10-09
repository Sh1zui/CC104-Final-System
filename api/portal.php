<?php
/**
 * The customer portal's endpoint (Phase 11). Customers only, and every
 * booking is checked to be theirs (includes/portal_data.php). JSON in all
 * cases, including auth and CSRF failures.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/portal_data.php';
require_once __DIR__ . '/../includes/portal_partial.php';

header('Content-Type: application/json');

$acting_user = current_user();
if (!$acting_user || $acting_user['role_name'] !== 'customer') {
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

/** The booking window from the form, with the online rules applied. */
function requested_window(): array
{
    $pickup = parse_datetime($_POST['pickup'] ?? null);
    $return = parse_datetime($_POST['return'] ?? null);
    if ($errors = online_window_errors($pickup, $return)) {
        fail(implode(' ', $errors));
    }
    return [$pickup, $return];
}

function booking_response(array $customer, int $booking_id, string $message = '', array $extra = []): void
{
    $b = own_booking($customer, $booking_id);
    echo json_encode(array_merge([
        'success'    => true,
        'message'    => $message,
        'view'       => $b ? customer_booking_view($b) : null,
        'table_html' => render_own_booking_rows(list_own_bookings($customer)),
    ], $extra));
    exit;
}

$action = $_POST['action'] ?? '';
$uid = (int) $acting_user['user_id'];

try {
    $customer = portal_customer($acting_user);

    switch ($action) {
        case 'search': {
            $filters = [
                'type'         => $_POST['type'] ?? '',
                'transmission' => $_POST['transmission'] ?? '',
                'min_seats'    => (int) ($_POST['min_seats'] ?? 0),
            ];
            $have_dates = trim((string) ($_POST['pickup'] ?? '')) !== '' || trim((string) ($_POST['return'] ?? '')) !== '';
            if (!$have_dates) {
                $vehicles = portal_catalog($filters);
                echo json_encode(['success' => true, 'count' => count($vehicles), 'cards_html' => render_vehicle_cards($vehicles, null, false)]);
                break;
            }
            [$pickup, $return] = requested_window();
            $vehicles = portal_search($pickup, $return, $filters);
            $blockers = portal_booking_blockers($customer);
            echo json_encode([
                'success'     => true,
                'count'       => count($vehicles),
                'rental_days' => rental_days($pickup->format('Y-m-d H:i:s'), $return->format('Y-m-d H:i:s')),
                'blockers'    => $blockers,
                'cards_html'  => render_vehicle_cards($vehicles, ['pickup' => $pickup, 'return' => $return], !$blockers),
            ]);
            break;
        }

        case 'book': {
            [$pickup, $return] = requested_window();
            $id = request_booking($customer, (int) ($_POST['vehicle_id'] ?? 0), $pickup, $return, (string) ($_POST['notes'] ?? ''));
            $b = get_booking($id);
            log_action($uid, 'booking_request', "Customer requested {$b['booking_reference']} (#$id), vehicle #{$b['vehicle_id']}");
            notify_user($uid, 'Booking request received', $b['booking_reference'] . ': ' . $b['brand'] . ' ' . $b['model'] . ', '
                . format_datetime($b['pickup_datetime']) . ' to ' . format_datetime($b['return_datetime']) . '. We\'ll confirm it shortly.', 'booking:' . $id);
            booking_response($customer, $id, 'Request sent. The desk will confirm it shortly.', ['booking_id' => $id]);
            break;
        }

        case 'get': {
            $b = require_own_booking($customer, (int) ($_POST['booking_id'] ?? 0));
            echo json_encode(['success' => true, 'view' => customer_booking_view($b)]);
            break;
        }

        case 'cancel': {
            $b = require_own_booking($customer, (int) ($_POST['booking_id'] ?? 0));
            $fee = customer_cancel_booking($customer, (int) $b['booking_id'], (string) ($_POST['reason'] ?? ''));
            log_action($uid, 'booking_cancel_customer', "Customer cancelled {$b['booking_reference']}" . ($fee > 0 ? ', fee ' . money($fee) : ''));
            booking_response($customer, (int) $b['booking_id'], $fee > 0
                ? 'Booking cancelled. A ' . money($fee) . ' cancellation fee applies; the desk will settle the rest of what you paid.'
                : 'Booking cancelled at no charge.' . ((float) $b['amount_paid'] > 0 ? ' The desk will refund what you paid.' : ''));
            break;
        }

        case 'submit_payment': {
            $b = require_own_booking($customer, (int) ($_POST['booking_id'] ?? 0));
            $sid = submit_payment($customer, (int) $b['booking_id'], $_POST);
            log_action($uid, 'payment_online_submit', "Customer reported payment #$sid on {$b['booking_reference']}");
            booking_response($customer, (int) $b['booking_id'], 'Thanks — we\'ll check the reference and send you a receipt.');
            break;
        }

        case 'withdraw_payment': {
            $sid = (int) ($_POST['submission_id'] ?? 0);
            $s = get_submission($sid);
            withdraw_submission($customer, $sid);
            booking_response($customer, (int) $s['booking_id'], 'Payment report withdrawn.');
            break;
        }

        case 'notifications_read': {
            mark_notifications_read($uid);
            echo json_encode(['success' => true]);
            break;
        }

        default:
            fail('Unknown action.', 400);
    }
} catch (BookingError $e) {
    fail($e->getMessage());
} catch (Throwable $e) {
    error_log('api/portal.php ' . $action . ': ' . $e);
    fail('Something went wrong on our side. Please refresh the page and try again.', 500);
}
