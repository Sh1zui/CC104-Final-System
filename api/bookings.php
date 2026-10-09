<?php
/**
 * Booking engine endpoint, one action per POST. Admin and staff (the
 * front desk books walk-ins and phone reservations; the staff portal
 * page in Phase 10 reuses this as-is). Customers book through their own
 * portal in Phase 11, which calls the same includes/bookings_data.php.
 *
 * Every response is JSON: {"success": true, ...} or {"success": false, "error": "..."}.
 * Anything that changes a booking also returns "table_html" and "detail".
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bookings_data.php';
require_once __DIR__ . '/../includes/rentals_data.php';
require_once __DIR__ . '/../includes/payments_data.php';
require_once __DIR__ . '/../includes/portal_data.php';
require_once __DIR__ . '/../includes/booking_partial.php';

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

/**
 * Staff may give an extra discount up to STAFF_MAX_DISCOUNT_PCT of the base
 * amount; admins up to MAX_MANUAL_DISCOUNT_PCT (enforced by the engine).
 * A discount an admin already gave is kept when staff reschedule or extend.
 */
function enforce_discount_limit(float $discount, float $daily_rate, DateTimeImmutable $pickup, DateTimeImmutable $return, float $existing = 0.0): void
{
    global $acting_user;
    if ($acting_user['role_name'] !== 'staff' || $discount <= $existing + 0.004) {
        return;
    }
    $base = quote_booking($daily_rate, $pickup, $return)['base_amount'];
    $cap = round($base * STAFF_MAX_DISCOUNT_PCT / 100, 2);
    if ($discount > $cap) {
        fail('Staff can give an extra discount of up to ' . STAFF_MAX_DISCOUNT_PCT . '% of the base amount (' . money($cap) . '). Ask an admin for more.');
    }
}

/** Pickup/return from the request, validated against the booking rules. */
function requested_window(): array
{
    $pickup = parse_datetime($_POST['pickup'] ?? null);
    $return = parse_datetime($_POST['return'] ?? null);
    if ($errors = booking_window_errors($pickup, $return)) {
        fail(implode(' ', $errors));
    }
    return [$pickup, $return];
}

function requested_discount(): float
{
    $raw = trim($_POST['discount'] ?? '');
    if ($raw === '') {
        return 0.0;
    }
    if (!is_numeric($raw)) {
        fail('Discount must be a number.');
    }
    return (float) $raw;
}

function booking_detail(int $booking_id): array
{
    $b = get_booking($booking_id);
    $vehicle = get_vehicle((int) $b['vehicle_id']);
    $closed = in_array($b['booking_status'], ['completed', 'cancelled', 'no_show'], true);
    return [
        'booking'              => $b,
        'actions'              => booking_allowed_actions($b),
        'manual_discount'      => manual_discount_part($b),
        'long_rental_discount' => round($b['discount_amount'] - manual_discount_part($b), 2),
        'overdue'              => $b['booking_status'] === 'active' && new DateTimeImmutable($b['return_datetime']) < new DateTimeImmutable(),
        'rental'               => get_rental($booking_id),
        'penalties'            => booking_penalties($booking_id),
        'vehicle_mileage'      => (int) $vehicle['mileage_km'],
        // Phase 8: money
        'payments'             => booking_payments($booking_id),
        'deposit'              => deposit_position($booking_id),
        'deposit_outstanding'  => in_array($b['booking_status'], ['completed', 'cancelled', 'no_show'], true)
                                    ? 0.0 : round(max(0, $b['deposit_amount'] - deposit_position($booking_id)['received']), 2),
        'settlement'           => $closed ? settlement_plan($b) : null,
        'invoice'              => active_invoice($booking_id),
        // A request nobody confirmed cancels free; a confirmed one follows the policy.
        'cancellation_fee_now' => $b['booking_status'] === 'confirmed' ? cancellation_fee_for($b)
                                  : ($b['booking_status'] === 'pending' ? 0.0 : null),
        'rules'                => [
            'free_cancellation_hours' => FREE_CANCELLATION_HOURS,
            'cancellation_fee_days'   => CANCELLATION_FEE_DAYS,
            'early_checkout_minutes'  => EARLY_CHECKOUT_MINUTES,
        ],
        'can_void'             => current_user()['role_name'] === 'admin',
        'discount_cap_pct'     => current_user()['role_name'] === 'admin' ? MAX_MANUAL_DISCOUNT_PCT : STAFF_MAX_DISCOUNT_PCT,
        'submissions'          => booking_submissions($booking_id),
    ];
}

function respond(int $booking_id, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success'    => true,
        'message'    => $message,
        'table_html' => render_booking_rows(list_bookings()),
        'detail'     => booking_detail($booking_id),
    ], $extra));
    exit;
}

function require_booking(): array
{
    $b = get_booking((int) ($_POST['booking_id'] ?? 0));
    if (!$b) {
        fail('That booking no longer exists.', 404);
    }
    return $b;
}

/** A whole number from the form, or a 422 with $label in the message. */
function requested_int(string $key, string $label): int
{
    $raw = trim((string) ($_POST[$key] ?? ''));
    if (!preg_match('/^\d{1,9}$/', $raw)) {
        fail("Enter the $label as a whole number.");
    }
    return (int) $raw;
}

function requested_money(string $key): float
{
    $raw = trim((string) ($_POST[$key] ?? ''));
    if ($raw === '') {
        return 0.0;
    }
    if (!is_numeric($raw)) {
        fail('Amounts must be numbers.');
    }
    return (float) $raw;
}

/** Return time + fuel + fees from the check-in form, shared by preview and save. */
function requested_return(): array
{
    $returned_at = parse_datetime($_POST['returned_at'] ?? null);
    if (!$returned_at) {
        fail('Enter when the car came back.');
    }
    $extras = [];
    foreach ((array) ($_POST['extras'] ?? []) as $line) {
        if (is_array($line)) {
            $extras[] = $line;
        }
    }
    return [$returned_at, requested_int('fuel_in', 'fuel level'), requested_money('damage_fee'), $extras];
}

/** Tell the customer what happened to their booking. */
function notify_customer(array $b, string $title, string $message): void
{
    notify_user((int) $b['customer_user_id'], $title, $b['booking_reference'] . ': ' . $message, 'booking:' . (int) $b['booking_id']);
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'search': {
            [$pickup, $return] = requested_window();
            $customer_blockers = [];
            if (!empty($_POST['customer_id'])) {
                $customer = get_customer((int) $_POST['customer_id']);
                $customer_blockers = $customer ? customer_booking_blockers($customer, $return) : ['That customer no longer exists.'];
            }
            $exclude = !empty($_POST['exclude_booking_id']) ? (int) $_POST['exclude_booking_id'] : null;
            $vehicles = search_available_vehicles($pickup, $return, [
                'type'         => $_POST['type'] ?? '',
                'transmission' => $_POST['transmission'] ?? '',
                'min_seats'    => (int) ($_POST['min_seats'] ?? 0),
            ], $exclude);
            foreach ($vehicles as &$v) {
                $v['primary_image_url'] = $v['primary_image'] && is_file(dirname(__DIR__) . '/' . $v['primary_image'])
                    ? BASE_URL . $v['primary_image'] : null;
            }
            unset($v);
            echo json_encode([
                'success'           => true,
                'vehicles'          => $vehicles,
                'customer_blockers' => $customer_blockers,
                'rental_days'       => rental_days($pickup->format('Y-m-d H:i:s'), $return->format('Y-m-d H:i:s')),
            ]);
            break;
        }

        case 'quote': {
            [$pickup, $return] = requested_window();
            $vehicle = get_vehicle((int) ($_POST['vehicle_id'] ?? 0));
            if (!$vehicle || $vehicle['deleted_at'] !== null) {
                fail('That vehicle is no longer in the fleet.', 404);
            }
            // When rescheduling on the same car, the original rate stays.
            $rate = (float) $vehicle['daily_rate'];
            $kept_discount = 0.0;
            if (!empty($_POST['booking_id'])) {
                $existing = get_booking((int) $_POST['booking_id']);
                if ($existing && (int) $existing['vehicle_id'] === (int) $vehicle['vehicle_id']) {
                    $rate = $existing['daily_rate_snapshot'];
                }
                $kept_discount = $existing ? manual_discount_part($existing) : 0.0;
            }
            enforce_discount_limit(requested_discount(), (float) $rate, $pickup, $return, $kept_discount);
            echo json_encode(['success' => true, 'quote' => quote_booking($rate, $pickup, $return, requested_discount())]);
            break;
        }

        case 'create': {
            [$pickup, $return] = requested_window();
            $confirm_now = filter_var($_POST['confirm_now'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $vehicle = get_vehicle((int) ($_POST['vehicle_id'] ?? 0));
            if ($vehicle) {
                enforce_discount_limit(requested_discount(), (float) $vehicle['daily_rate'], $pickup, $return);
            }
            $id = create_booking(
                (int) ($_POST['customer_id'] ?? 0),
                (int) ($_POST['vehicle_id'] ?? 0),
                $pickup, $return, requested_discount(), (string) ($_POST['notes'] ?? ''),
                (int) $acting_user['user_id'], $confirm_now
            );
            $b = get_booking($id);
            log_action($acting_user['user_id'], 'booking_create', "Created booking {$b['booking_reference']} (#$id) for customer #{$b['customer_id']}, vehicle #{$b['vehicle_id']}, {$b['booking_status']}");
            notify_customer($b, $confirm_now ? 'Booking confirmed' : 'Booking received',
                "{$b['brand']} {$b['model']}, " . format_datetime($b['pickup_datetime']) . ' to ' . format_datetime($b['return_datetime']) . '. Total ' . money($b['total_amount']) . '.');
            respond($id, 'Booking ' . $b['booking_reference'] . ($confirm_now ? ' created and confirmed.' : ' created as pending.'), ['new_id' => $id]);
            break;
        }

        case 'get': {
            $b = require_booking();
            echo json_encode(['success' => true, 'detail' => booking_detail((int) $b['booking_id'])]);
            break;
        }

        case 'confirm': {
            $b = require_booking();
            confirm_booking((int) $b['booking_id'], (int) $acting_user['user_id']);
            log_action($acting_user['user_id'], 'booking_confirm', "Confirmed booking {$b['booking_reference']}");
            notify_customer($b, 'Booking confirmed', 'Your booking is confirmed. See you on ' . format_datetime($b['pickup_datetime']) . '.');
            respond((int) $b['booking_id'], 'Booking confirmed.');
            break;
        }

        case 'cancel': {
            $b = require_booking();
            $reason = (string) ($_POST['reason'] ?? '');
            $waive = filter_var($_POST['waive_fee'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $fee = cancel_booking((int) $b['booking_id'], (int) $acting_user['user_id'], $reason, $waive, true);
            log_action($acting_user['user_id'], 'booking_cancel', "Cancelled booking {$b['booking_reference']}: " . trim($reason)
                . ($fee > 0 ? ' (fee ' . money($fee) . ')' : ($waive ? ' (fee waived)' : '')));
            notify_customer($b, 'Booking cancelled', 'Your booking was cancelled. Reason: ' . trim($reason)
                . ($fee > 0 ? ' A cancellation fee of ' . money($fee) . ' applies.' : ''));
            $after = get_booking((int) $b['booking_id']);
            $message = 'Booking cancelled' . ($fee > 0 ? ' with a ' . money($fee) . ' cancellation fee.' : '.');
            if ($after['refund_due'] > 0) {
                $message .= ' ' . money($after['refund_due']) . ' is owed back — use Settle to refund it.';
            } elseif ($after['balance_due'] > 0) {
                $message .= ' The customer owes ' . money($after['balance_due']) . '.';
            }
            respond((int) $b['booking_id'], $message);
            break;
        }

        case 'no_show': {
            $b = require_booking();
            mark_booking_no_show((int) $b['booking_id'], (int) $acting_user['user_id']);
            $after = get_booking((int) $b['booking_id']);
            log_action($acting_user['user_id'], 'booking_no_show', "Marked booking {$b['booking_reference']} as no-show (fee " . money($after['cancellation_fee']) . ')');
            notify_customer($b, 'Missed pickup', 'You didn\'t pick up the vehicle, so the booking was closed with a no-show fee of '
                . money($after['cancellation_fee']) . '. Contact the desk if this is a mistake.');
            $message = 'Marked as no-show (fee ' . money($after['cancellation_fee']) . '). The car is free again for that window.';
            if ($after['refund_due'] > 0) {
                $message .= ' ' . money($after['refund_due']) . ' is owed back — use Settle to refund it.';
            }
            respond((int) $b['booking_id'], $message);
            break;
        }

        case 'reschedule': {
            $b = require_booking();
            [$pickup, $return] = requested_window();
            $vehicle = get_vehicle((int) ($_POST['vehicle_id'] ?? 0));
            if ($vehicle) {
                $rate = (int) $vehicle['vehicle_id'] === (int) $b['vehicle_id'] ? (float) $b['daily_rate_snapshot'] : (float) $vehicle['daily_rate'];
                enforce_discount_limit(requested_discount(), $rate, $pickup, $return, manual_discount_part($b));
            }
            $result = reschedule_booking(
                (int) $b['booking_id'], (int) ($_POST['vehicle_id'] ?? 0), $pickup, $return, requested_discount(), (int) $acting_user['user_id']
            );
            $after = get_booking((int) $b['booking_id']);
            log_action($acting_user['user_id'], 'booking_reschedule', "Rescheduled booking {$b['booking_reference']}: total " . money($result['old_total']) . ' → ' . money($result['new_total']));
            notify_customer($after, 'Booking changed',
                "Now {$after['brand']} {$after['model']}, " . format_datetime($after['pickup_datetime']) . ' to ' . format_datetime($after['return_datetime']) . '. New total ' . money($after['total_amount']) . '.');
            $message = 'Booking rescheduled.';
            if (abs($result['new_total'] - $result['old_total']) >= 0.01) {
                $message .= ' Total changed from ' . money($result['old_total']) . ' to ' . money($result['new_total']) . '.';
            }
            respond((int) $b['booking_id'], $message);
            break;
        }

        case 'check_out': {
            $b = require_booking();
            check_out_booking(
                (int) $b['booking_id'], (int) $acting_user['user_id'],
                requested_int('odometer_out', 'odometer reading'), requested_int('fuel_out', 'fuel level'),
                (string) ($_POST['condition_notes'] ?? ''),
                filter_var($_POST['license_checked'] ?? false, FILTER_VALIDATE_BOOLEAN)
            );
            log_action($acting_user['user_id'], 'rental_check_out', "Released {$b['plate_number']} for {$b['booking_reference']}");
            notify_customer($b, 'Enjoy your trip', "You've picked up the {$b['brand']} {$b['model']}. Please return it by " . format_datetime($b['return_datetime']) . '.');
            $message = 'Car released. The booking is now active.';
            $after = get_booking((int) $b['booking_id']);
            if ($after['balance_due'] > 0) {
                $message .= ' Note: ' . money($after['balance_due']) . ' is still unpaid.';
            }
            respond((int) $b['booking_id'], $message);
            break;
        }

        case 'preview_return': {
            $b = require_booking();
            $rental = get_rental((int) $b['booking_id']);
            if ($b['booking_status'] !== 'active' || !$rental) {
                fail('This booking isn\'t out on rental.');
            }
            [$returned_at, $fuel_in, $damage_fee, $extras] = requested_return();
            validate_fuel_level($fuel_in);
            echo json_encode(['success' => true, 'charges' => return_charges($b, $rental, $returned_at, $fuel_in, $damage_fee, normalize_extra_charges($extras))]);
            break;
        }

        case 'check_in': {
            $b = require_booking();
            [$returned_at, $fuel_in, $damage_fee, $extras] = requested_return();
            $needs_maintenance = filter_var($_POST['needs_maintenance'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $charges = check_in_booking(
                (int) $b['booking_id'], (int) $acting_user['user_id'], $returned_at,
                requested_int('odometer_in', 'odometer reading'), $fuel_in, $damage_fee,
                (string) ($_POST['damage_notes'] ?? ''), $extras, $needs_maintenance, (string) ($_POST['maintenance_note'] ?? '')
            );
            log_action($acting_user['user_id'], 'rental_check_in', "Received {$b['plate_number']} back for {$b['booking_reference']}; charges " . money($charges['charges_total']));

            $parts = [];
            if ($charges['charges_total'] > 0) {
                $parts[] = 'charges ' . money($charges['charges_total']);
            }
            if ($charges['balance_due'] > 0) {
                $parts[] = 'balance due ' . money($charges['balance_due']);
            } elseif ($charges['refund_due'] > 0) {
                $parts[] = 'refund due ' . money($charges['refund_due']);
            }
            notify_customer($b, 'Rental complete', 'Thanks for returning the car.' . ($parts ? ' ' . ucfirst(implode('; ', $parts)) . '.' : ''));

            $message = 'Car received. Rental completed' . ($parts ? ' — ' . implode('; ', $parts) : '') . '.';
            if ($needs_maintenance) {
                $stmt = Database::getConnection()->prepare(
                    "SELECT COUNT(*) FROM bookings WHERE vehicle_id = ? AND booking_status IN ('pending', 'confirmed')"
                );
                $stmt->execute([$b['vehicle_id']]);
                $upcoming = (int) $stmt->fetchColumn();
                $message .= ' The car is now in maintenance' . ($upcoming ? " — it has $upcoming upcoming booking(s) to move to another car." : '.');
            }
            respond((int) $b['booking_id'], $message);
            break;
        }

        case 'preview_extend':
        case 'extend': {
            $b = require_booking();
            $new_return = parse_datetime($_POST['return'] ?? null);
            if (!$new_return) {
                fail('Enter the new return date and time.');
            }
            if ($action === 'preview_extend') {
                if ($b['booking_status'] !== 'active') {
                    fail('Only a rental that\'s out can be extended.');
                }
                echo json_encode(['success' => true, 'quote' => extension_quote($b, $new_return)]);
                break;
            }
            $result = extend_rental((int) $b['booking_id'], $new_return, (int) $acting_user['user_id']);
            log_action($acting_user['user_id'], 'rental_extend', "Extended {$b['booking_reference']} to " . $new_return->format('Y-m-d H:i') . ': ' . money($result['old_total']) . ' → ' . money($result['new_total']));
            notify_customer($b, 'Rental extended', 'New return time ' . format_datetime($new_return->format('Y-m-d H:i:s')) . '. New total ' . money($result['new_total']) . '.');
            respond((int) $b['booking_id'], 'Rental extended. Total changed from ' . money($result['old_total']) . ' to ' . money($result['new_total']) . '.');
            break;
        }

        case 'add_charge': {
            $b = require_booking();
            add_booking_charge((int) $b['booking_id'], (int) $acting_user['user_id'],
                (string) ($_POST['charge_type'] ?? ''), (string) ($_POST['amount'] ?? ''), (string) ($_POST['description'] ?? ''));
            log_action($acting_user['user_id'], 'booking_charge', "Added " . money((float) ($_POST['amount'] ?? 0)) . " ({$_POST['charge_type']}) to {$b['booking_reference']}");
            notify_customer($b, 'New charge on your rental', humanize((string) $_POST['charge_type']) . ': ' . money((float) $_POST['amount']) . ' — ' . trim((string) ($_POST['description'] ?? '')));
            respond((int) $b['booking_id'], 'Charge added.');
            break;
        }

        // ---------------- Phase 8: payments, settlement, invoices ----------------

        case 'record_payment': {
            $b = require_booking();
            $amount = trim((string) ($_POST['amount'] ?? ''));
            if (!is_numeric($amount)) {
                fail('Enter the amount as a number.');
            }
            $pid = record_payment((int) $b['booking_id'], (int) $acting_user['user_id'], (float) $amount,
                (string) ($_POST['payment_type'] ?? ''), (string) ($_POST['method'] ?? ''),
                (string) ($_POST['transaction_ref'] ?? ''), (string) ($_POST['notes'] ?? ''));
            $p = get_payment($pid);
            log_action($acting_user['user_id'], 'payment_record', "{$p['receipt_number']}: " . money($p['amount']) . " {$p['payment_type']} by {$p['payment_method']} for {$b['booking_reference']}");
            notify_customer($b, 'Payment received', money($p['amount']) . ' (' . $p['type_label'] . ', ' . $p['method_label'] . '). Receipt ' . $p['receipt_number'] . '.');
            respond((int) $b['booking_id'], 'Payment recorded — receipt ' . $p['receipt_number'] . '.', ['receipt_id' => $pid]);
            break;
        }

        case 'settle': {
            $b = require_booking();
            $plan = settle_booking((int) $b['booking_id'], (int) $acting_user['user_id'],
                (string) ($_POST['method'] ?? ''), (string) ($_POST['transaction_ref'] ?? ''));
            $parts = [];
            if ($plan['keep'] > 0) {
                $parts[] = money($plan['keep']) . ' of the deposit kept';
            }
            if ($plan['refund_total'] > 0) {
                $parts[] = money($plan['refund_total']) . ' refunded';
            }
            log_action($acting_user['user_id'], 'booking_settle', "Settled {$b['booking_reference']}: " . implode(', ', $parts));
            if ($plan['refund_total'] > 0) {
                notify_customer($b, 'Refund sent', money($plan['refund_total']) . ' has been returned to you.');
            }
            $after = get_booking((int) $b['booking_id']);
            respond((int) $b['booking_id'], 'Settled: ' . implode(', ', $parts) . '.'
                . ($after['balance_due'] > 0 ? ' The customer still owes ' . money($after['balance_due']) . '.' : ''));
            break;
        }

        case 'void_payment': {
            if ($acting_user['role_name'] !== 'admin') {
                fail('Only an admin can void a payment.', 403);
            }
            $p = void_payment((int) ($_POST['payment_id'] ?? 0), (int) $acting_user['user_id'], (string) ($_POST['reason'] ?? ''));
            log_action($acting_user['user_id'], 'payment_void', "Voided {$p['receipt_number']} (" . money($p['amount']) . '): ' . trim((string) $_POST['reason']));
            respond((int) $p['booking_id'], 'Receipt ' . $p['receipt_number'] . ' voided.');
            break;
        }

        case 'accept_submission': {
            $sub = get_submission((int) ($_POST['submission_id'] ?? 0));
            if (!$sub) {
                fail('That payment report no longer exists.', 404);
            }
            $pid = accept_submission((int) $sub['submission_id'], (int) $acting_user['user_id'], (string) ($_POST['payment_type'] ?? ''));
            $p = get_payment($pid);
            log_action($acting_user['user_id'], 'payment_online_accept', "Accepted online payment #{$sub['submission_id']} on {$sub['booking_reference']} as {$p['receipt_number']} (" . money($p['amount']) . ')');
            respond((int) $sub['booking_id'], money($p['amount']) . ' accepted — receipt ' . $p['receipt_number'] . '. The customer was notified.', ['receipt_id' => $pid]);
            break;
        }

        case 'reject_submission': {
            $sub = get_submission((int) ($_POST['submission_id'] ?? 0));
            if (!$sub) {
                fail('That payment report no longer exists.', 404);
            }
            reject_submission((int) $sub['submission_id'], (int) $acting_user['user_id'], (string) ($_POST['note'] ?? ''));
            log_action($acting_user['user_id'], 'payment_online_reject', "Rejected online payment #{$sub['submission_id']} on {$sub['booking_reference']}: " . trim((string) $_POST['note']));
            respond((int) $sub['booking_id'], 'Payment report rejected. The customer was told why.');
            break;
        }

        case 'issue_invoice': {
            $b = require_booking();
            $iid = issue_invoice((int) $b['booking_id'], (int) $acting_user['user_id']);
            $inv = get_invoice($iid);
            log_action($acting_user['user_id'], 'invoice_issue', "Issued {$inv['invoice_number']} for {$b['booking_reference']}: " . money($inv['total']));
            notify_customer($b, 'Invoice issued', 'Invoice ' . $inv['invoice_number'] . ' for ' . money($inv['total']) . ' (' . $inv['status'] . ').');
            respond((int) $b['booking_id'], 'Invoice ' . $inv['invoice_number'] . ' issued.', ['invoice_id' => $iid]);
            break;
        }

        case 'void_invoice': {
            if ($acting_user['role_name'] !== 'admin') {
                fail('Only an admin can void an invoice.', 403);
            }
            $b = require_booking();
            $inv = active_invoice((int) $b['booking_id']);
            if (!$inv) {
                fail('This booking has no issued invoice.');
            }
            void_invoice((int) $inv['invoice_id'], (int) $acting_user['user_id'], (string) ($_POST['reason'] ?? ''));
            log_action($acting_user['user_id'], 'invoice_void', "Voided {$inv['invoice_number']}: " . trim((string) $_POST['reason']));
            respond((int) $b['booking_id'], 'Invoice ' . $inv['invoice_number'] . ' voided. It stays on record; issue a corrected one when ready.');
            break;
        }

        default:
            fail('Unknown action.', 400);
    }
} catch (BookingError $e) {
    fail($e->getMessage());
} catch (Throwable $e) {
    // Anything unexpected (a database constraint, a bug): keep the details
    // in the server log, give the browser JSON it can show.
    error_log('api/bookings.php ' . $action . ': ' . $e);
    fail('Something went wrong on the server. Refresh the page to see the booking as it is now; if it keeps happening, check the PHP error log.', 500);
}
