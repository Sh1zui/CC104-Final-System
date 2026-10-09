<?php
/**
 * The customer portal (Phase 11): browsing, online booking requests,
 * cancelling, and payments sent by GCash / bank transfer. Every booking
 * rule is the desk's rule (includes/bookings_data.php) — this file only
 * adds what is different online:
 *   - an online booking is a *request* (pending) that staff confirm;
 *   - pickups need ONLINE_BOOKING_LEAD_HOURS of notice;
 *   - at most MAX_PENDING_ONLINE_BOOKINGS unconfirmed requests at once;
 *   - money sent online counts only after staff accept the reference.
 * Ownership is checked here for every booking a customer touches.
 */

require_once __DIR__ . '/bookings_data.php';
require_once __DIR__ . '/rentals_data.php';
require_once __DIR__ . '/payments_data.php';

const SUBMISSION_TYPES   = ['deposit', 'rental_fee'];
const SUBMISSION_METHODS = ['gcash', 'bank_transfer'];

// ---------------------------------------------------------------------
// Whose booking is it?
// ---------------------------------------------------------------------

/** The logged-in customer's profile row, or a BookingError if there isn't one. */
function portal_customer(array $user): array
{
    $customer = get_customer_by_user_id((int) $user['user_id']);
    if (!$customer) {
        throw new BookingError('Your customer profile is missing. Contact the rental desk.');
    }
    return $customer;
}

/** A booking, only if it belongs to this customer (else null — never "forbidden", so ids can't be probed). */
function own_booking(array $customer, int $booking_id): ?array
{
    $b = get_booking($booking_id);
    return $b && (int) $b['customer_id'] === (int) $customer['customer_id'] ? $b : null;
}

function require_own_booking(array $customer, int $booking_id): array
{
    $b = own_booking($customer, $booking_id);
    if (!$b) {
        throw new BookingError('That booking wasn\'t found on your account.');
    }
    return $b;
}

// ---------------------------------------------------------------------
// Browsing and booking
// ---------------------------------------------------------------------

/** The cars a customer can look at (no dates yet): in the fleet and not taken out of service. */
function portal_catalog(array $filters = []): array
{
    $where = ['v.deleted_at IS NULL', "v.status != 'unavailable'"];
    $params = [];
    if (!empty($filters['type']) && in_array($filters['type'], VEHICLE_TYPES, true)) {
        $where[] = 'v.vehicle_type = ?';
        $params[] = $filters['type'];
    }
    if (!empty($filters['transmission']) && in_array($filters['transmission'], TRANSMISSIONS, true)) {
        $where[] = 'v.transmission = ?';
        $params[] = $filters['transmission'];
    }
    if (!empty($filters['min_seats'])) {
        $where[] = 'v.seating_capacity >= ?';
        $params[] = (int) $filters['min_seats'];
    }
    $stmt = Database::getConnection()->prepare(
        'SELECT v.vehicle_id, v.brand, v.model, v.year, v.vehicle_type, v.transmission, v.fuel_type,
                v.seating_capacity, v.color, v.daily_rate,
                (SELECT image_path FROM vehicle_images vi WHERE vi.vehicle_id = v.vehicle_id
                 ORDER BY is_primary DESC, image_id ASC LIMIT 1) AS primary_image
         FROM vehicles v WHERE ' . implode(' AND ', $where) . '
         ORDER BY v.daily_rate ASC, v.brand, v.model'
    );
    $stmt->execute($params);
    return array_map(function ($v) {
        $v['daily_rate'] = (float) $v['daily_rate'];
        return $v;
    }, $stmt->fetchAll());
}

/** Online-only checks on top of booking_window_errors(). */
function online_window_errors(?DateTimeImmutable $pickup, ?DateTimeImmutable $return, ?DateTimeImmutable $now = null): array
{
    $errors = booking_window_errors($pickup, $return);
    if ($errors || !$pickup) {
        return $errors;
    }
    $now = $now ?? new DateTimeImmutable();
    $earliest = $now->modify('+' . ONLINE_BOOKING_LEAD_HOURS . ' hours');
    if ($pickup < $earliest) {
        $errors[] = 'Online bookings need at least ' . ONLINE_BOOKING_LEAD_HOURS . ' hours\' notice — the earliest pickup is '
            . format_datetime($earliest->format('Y-m-d H:i:s')) . '. For sooner, call or visit the desk.';
    }
    return $errors;
}

/** Cars free for these dates, each with its price. Plates are not shown to customers. */
function portal_search(DateTimeImmutable $pickup, DateTimeImmutable $return, array $filters = []): array
{
    return array_map(function ($v) {
        unset($v['plate_number']);
        return $v;
    }, search_available_vehicles($pickup, $return, $filters));
}

function pending_request_count(int $customer_id): int
{
    $stmt = Database::getConnection()->prepare(
        "SELECT COUNT(*) FROM bookings WHERE customer_id = ? AND booking_status = 'pending' AND return_datetime > NOW()"
    );
    $stmt->execute([$customer_id]);
    return (int) $stmt->fetchColumn();
}

/**
 * What would stop this customer booking online at all, before they pick
 * dates: the desk's blockers plus the pending-request limit.
 */
function portal_booking_blockers(array $customer): array
{
    $blockers = customer_booking_blockers($customer);
    if (pending_request_count((int) $customer['customer_id']) >= MAX_PENDING_ONLINE_BOOKINGS) {
        $blockers[] = 'You already have ' . MAX_PENDING_ONLINE_BOOKINGS . ' requests waiting for confirmation. '
            . 'Wait for the desk to confirm one, or cancel one you no longer need.';
    }
    return $blockers;
}

/**
 * Request a booking. It's created pending under the same row lock and
 * re-checks as a desk booking; staff confirm it.
 * @return int booking_id
 * @throws BookingError
 */
function request_booking(array $customer, int $vehicle_id, ?DateTimeImmutable $pickup, ?DateTimeImmutable $return, string $notes): int
{
    if ($errors = online_window_errors($pickup, $return)) {
        throw new BookingError(implode(' ', $errors));
    }
    $vehicle = get_vehicle($vehicle_id);
    if (!$vehicle || $vehicle['deleted_at'] !== null || $vehicle['status'] === 'unavailable') {
        throw new BookingError('That car isn\'t available to book.');
    }
    // Lock the customer first so parallel requests can't slip past the
    // pending-request limit (Phase 14); create_booking() then joins this
    // transaction and locks the car as usual.
    $id = in_transaction(function (PDO $pdo) use ($customer, $vehicle_id, $pickup, $return, $notes) {
        $pdo->prepare('SELECT customer_id FROM customers WHERE customer_id = ? FOR UPDATE')->execute([(int) $customer['customer_id']]);
        if (pending_request_count((int) $customer['customer_id']) >= MAX_PENDING_ONLINE_BOOKINGS) {
            throw new BookingError('You already have ' . MAX_PENDING_ONLINE_BOOKINGS . ' requests waiting for confirmation. '
                . 'Wait for the desk to confirm one, or cancel one you no longer need.');
        }
        return create_booking((int) $customer['customer_id'], $vehicle_id, $pickup, $return, 0.0, $notes, null, false);
    });
    $b = get_booking($id);
    notify_reviewers('New online booking', $b['booking_reference'] . ': ' . $b['customer_name'] . ' requested the ' . $b['brand'] . ' ' . $b['model']
        . ', ' . format_datetime($b['pickup_datetime']) . ' to ' . format_datetime($b['return_datetime']) . '. Open it to confirm.', 'booking:' . $id);
    return $id;
}

/**
 * What cancelling now would cost. A request the desk never confirmed is
 * always free; a confirmed booking follows the cancellation policy.
 */
function customer_cancellation_fee(array $b): float
{
    return $b['booking_status'] === 'pending' ? 0.0 : cancellation_fee_for($b);
}

/** @return float the fee charged @throws BookingError */
function customer_cancel_booking(array $customer, int $booking_id, string $reason): float
{
    $b = require_own_booking($customer, $booking_id);
    if (!in_array($b['booking_status'], ['pending', 'confirmed'], true)) {
        throw new BookingError('Only a booking that hasn\'t started can be cancelled online. Contact the desk for anything else.');
    }
    $reason = trim($reason) === '' ? 'Cancelled by the customer online' : trim($reason);
    $fee = cancel_booking($booking_id, null, $reason, false, true);
    // Anything they sent that nobody has checked yet goes with it.
    Database::getConnection()->prepare(
        "UPDATE payment_submissions SET status = 'withdrawn' WHERE booking_id = ? AND status = 'submitted'"
    )->execute([$booking_id]);
    notify_reviewers('Booking cancelled by customer', $b['booking_reference'] . ': ' . $b['customer_name'] . ' cancelled'
        . ($fee > 0 ? ' (cancellation fee ' . money($fee) . ')' : '') . '. Settle anything they paid.', 'booking:' . $booking_id);
    return $fee;
}

// ---------------------------------------------------------------------
// Money sent online
// ---------------------------------------------------------------------

const SUBMISSION_SELECT = "
    SELECT s.*, b.booking_reference, b.customer_id, u.full_name AS customer_name, rv.full_name AS reviewed_by_name,
           p.receipt_number
    FROM payment_submissions s
    JOIN bookings  b ON b.booking_id = s.booking_id
    JOIN customers c ON c.customer_id = b.customer_id
    JOIN users     u ON u.user_id = c.user_id
    LEFT JOIN users rv ON rv.user_id = s.reviewed_by
    LEFT JOIN payments p ON p.payment_id = s.payment_id";

function normalize_submission(array $s): array
{
    $s['amount'] = round((float) $s['amount'], 2);
    $s['method_label'] = PAYMENT_METHOD_LABELS[$s['payment_method']] ?? $s['payment_method'];
    $s['type_label'] = $s['payment_type'] === 'deposit' ? 'Deposit' : 'Rental and charges';
    return $s;
}

function booking_submissions(int $booking_id): array
{
    $stmt = Database::getConnection()->prepare(SUBMISSION_SELECT . ' WHERE s.booking_id = ? ORDER BY s.created_at DESC, s.submission_id DESC');
    $stmt->execute([$booking_id]);
    return array_map('normalize_submission', $stmt->fetchAll());
}

/** Everything waiting for a staff member to check, oldest first. */
function open_submissions(): array
{
    return array_map('normalize_submission', Database::getConnection()->query(
        SUBMISSION_SELECT . " WHERE s.status = 'submitted' ORDER BY s.created_at ASC, s.submission_id ASC"
    )->fetchAll());
}

function get_submission(int $submission_id): ?array
{
    $stmt = Database::getConnection()->prepare(SUBMISSION_SELECT . ' WHERE s.submission_id = ?');
    $stmt->execute([$submission_id]);
    $row = $stmt->fetch();
    return $row ? normalize_submission($row) : null;
}

/**
 * How much the customer can still send for each purpose: what's owed,
 * less what they've already sent that's waiting to be checked.
 * @return array{deposit: float, rental_fee: float}
 */
function payment_buckets(array $b): array
{
    $closed = in_array($b['booking_status'], ['completed', 'cancelled', 'no_show'], true);
    $deposit_out = $closed ? 0.0 : max(0.0, round($b['deposit_amount'] - deposit_position((int) $b['booking_id'])['received'], 2));
    $deposit_out = min($deposit_out, $b['balance_due']);
    $rental_out = max(0.0, round($b['balance_due'] - $deposit_out, 2));

    $stmt = Database::getConnection()->prepare(
        "SELECT payment_type, COALESCE(SUM(amount), 0) FROM payment_submissions
         WHERE booking_id = ? AND status = 'submitted' GROUP BY payment_type"
    );
    $stmt->execute([$b['booking_id']]);
    $waiting = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    return [
        'deposit'    => max(0.0, round($deposit_out - (float) ($waiting['deposit'] ?? 0), 2)),
        'rental_fee' => max(0.0, round($rental_out - (float) ($waiting['rental_fee'] ?? 0), 2)),
    ];
}

/**
 * The customer reports a payment they made.
 * @return int submission_id
 * @throws BookingError
 */
function submit_payment(array $customer, int $booking_id, array $in): int
{
    $type = (string) ($in['payment_type'] ?? '');
    $method = (string) ($in['payment_method'] ?? '');
    $ref = trim((string) ($in['transaction_ref'] ?? ''));
    $amount_raw = trim((string) ($in['amount'] ?? ''));

    if (!in_array($type, SUBMISSION_TYPES, true)) {
        throw new BookingError('Choose what the payment is for.');
    }
    if (!in_array($method, SUBMISSION_METHODS, true)) {
        throw new BookingError('Choose GCash or bank transfer.');
    }
    if ($ref === '' || mb_strlen($ref) > 80 || !preg_match('/^[A-Za-z0-9 \-\/#.]+$/', $ref)) {
        throw new BookingError('Enter the reference number exactly as it appears on your ' . PAYMENT_METHOD_LABELS[$method] . ' receipt (letters, numbers, dashes).');
    }
    if (!is_numeric($amount_raw) || (float) $amount_raw <= 0) {
        throw new BookingError('Enter the amount you sent.');
    }
    $amount = round((float) $amount_raw, 2);
    $paid_on = parse_date($in['paid_on'] ?? null);
    if (!$paid_on) {
        throw new BookingError('Enter the date you sent the payment.');
    }
    if ($paid_on->format('Y-m-d') > date('Y-m-d')) {
        throw new BookingError('The payment date can\'t be in the future.');
    }

    return in_transaction(function (PDO $pdo) use ($customer, $booking_id, $type, $method, $ref, $amount, $paid_on) {
        lock_booking($booking_id);
        $b = require_own_booking($customer, $booking_id);
        if ($b['booking_status'] === 'cancelled' || $b['booking_status'] === 'no_show') {
            throw new BookingError('This booking is ' . str_replace('_', '-', $b['booking_status']) . '. Contact the desk about anything you\'ve paid.');
        }
        if ($paid_on->format('Y-m-d') < substr($b['created_at'], 0, 10)) {
            throw new BookingError('The payment date is before you made this booking.');
        }
        $room = payment_buckets($b)[$type];
        if ($room <= 0) {
            throw new BookingError($type === 'deposit'
                ? 'The deposit is already paid (or a payment for it is waiting to be checked).'
                : 'Nothing else is owed on this booking right now, apart from anything waiting to be checked.');
        }
        if ($amount > $room) {
            throw new BookingError('That\'s more than what\'s left to pay for this (' . money($room) . ').');
        }

        $stmt = $pdo->prepare('SELECT 1 FROM payments WHERE transaction_ref = ?
                               UNION SELECT 1 FROM payment_submissions WHERE transaction_ref = ? AND status IN (\'submitted\', \'accepted\')');
        $stmt->execute([$ref, $ref]);
        if ($stmt->fetchColumn()) {
            throw new BookingError('That reference number has already been sent to us. If you paid twice, contact the desk.');
        }

        $pdo->prepare(
            'INSERT INTO payment_submissions (booking_id, submitted_by, amount, payment_type, payment_method, transaction_ref, paid_on)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$booking_id, (int) $customer['user_id'], $amount, $type, $method, $ref, $paid_on->format('Y-m-d')]);
        $id = (int) $pdo->lastInsertId();

        notify_reviewers('Payment to check', $b['booking_reference'] . ': ' . $b['customer_name'] . ' says they sent ' . money($amount)
            . ' by ' . PAYMENT_METHOD_LABELS[$method] . ' (ref ' . $ref . '). Check it and accept or reject it in the booking.', 'booking:' . $booking_id);
        return $id;
    });
}

/** The customer takes back a report nobody has checked yet. @throws BookingError */
function withdraw_submission(array $customer, int $submission_id): void
{
    in_transaction(function (PDO $pdo) use ($customer, $submission_id) {
        $stmt = $pdo->prepare('SELECT * FROM payment_submissions WHERE submission_id = ? FOR UPDATE');
        $stmt->execute([$submission_id]);
        $s = $stmt->fetch();
        if (!$s || !own_booking($customer, (int) $s['booking_id'])) {
            throw new BookingError('That payment report wasn\'t found on your account.');
        }
        if ($s['status'] !== 'submitted') {
            throw new BookingError('That payment has already been ' . $s['status'] . '.');
        }
        $pdo->prepare("UPDATE payment_submissions SET status = 'withdrawn' WHERE submission_id = ?")->execute([$submission_id]);
    });
}

/** Lock a submission that's still waiting. @throws BookingError */
function lock_open_submission(PDO $pdo, int $submission_id): array
{
    $stmt = $pdo->prepare('SELECT * FROM payment_submissions WHERE submission_id = ? FOR UPDATE');
    $stmt->execute([$submission_id]);
    $s = $stmt->fetch();
    if (!$s) {
        throw new BookingError('That payment report no longer exists.');
    }
    if ($s['status'] !== 'submitted') {
        throw new BookingError('That payment report was already ' . $s['status'] . '.');
    }
    return $s;
}

/**
 * Staff checked the reference and the money is there: record it as a real
 * payment (receipt and all). Staff may correct what it's for, e.g. when the
 * deposit was already paid at the desk.
 * @return int payment_id
 * @throws BookingError
 */
function accept_submission(int $submission_id, int $acting_user_id, ?string $type_override = null): int
{
    return in_transaction(function (PDO $pdo) use ($submission_id, $acting_user_id, $type_override) {
        $s = lock_open_submission($pdo, $submission_id);
        $type = $type_override !== null && $type_override !== '' ? $type_override : $s['payment_type'];
        if (!in_array($type, MONEY_IN_TYPES, true)) {
            throw new BookingError('Choose what the payment is for.');
        }
        $payment_id = record_payment((int) $s['booking_id'], $acting_user_id, (float) $s['amount'], $type, $s['payment_method'],
            $s['transaction_ref'], 'Sent online, paid ' . date('M j, Y', strtotime($s['paid_on'])));
        $pdo->prepare(
            "UPDATE payment_submissions SET status = 'accepted', reviewed_by = ?, reviewed_at = NOW(), payment_id = ? WHERE submission_id = ?"
        )->execute([$acting_user_id, $payment_id, $submission_id]);

        $b = get_booking((int) $s['booking_id']);
        $p = get_payment($payment_id);
        notify_user((int) $b['customer_user_id'], 'Payment received',
            $b['booking_reference'] . ': your ' . PAYMENT_METHOD_LABELS[$s['payment_method']] . ' payment of ' . money((float) $s['amount'])
            . ' was received. Receipt ' . $p['receipt_number'] . '.', 'booking:' . (int) $s['booking_id']);
        return $payment_id;
    });
}

/** The money isn't there (or the reference is wrong). @throws BookingError */
function reject_submission(int $submission_id, int $acting_user_id, string $note): void
{
    $note = trim($note);
    if ($note === '') {
        throw new BookingError('Say why — the customer sees this so they can fix it.');
    }
    in_transaction(function (PDO $pdo) use ($submission_id, $acting_user_id, $note) {
        $s = lock_open_submission($pdo, $submission_id);
        $pdo->prepare(
            "UPDATE payment_submissions SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE submission_id = ?"
        )->execute([$acting_user_id, mb_substr($note, 0, 255), $submission_id]);
        $b = get_booking((int) $s['booking_id']);
        notify_user((int) $b['customer_user_id'], 'Payment not confirmed',
            $b['booking_reference'] . ': we couldn\'t confirm your ' . money((float) $s['amount']) . ' payment (ref ' . $s['transaction_ref'] . '). '
            . $note, 'booking:' . (int) $s['booking_id']);
    });
}

// ---------------------------------------------------------------------
// What the customer sees
// ---------------------------------------------------------------------

/** One booking as its owner sees it, with what they can do next. */
function customer_booking_view(array $b): array
{
    $status = $b['booking_status'];
    $can_cancel = in_array($status, ['pending', 'confirmed'], true) && new DateTimeImmutable($b['return_datetime']) > new DateTimeImmutable();
    $buckets = payment_buckets($b);
    $invoice = active_invoice((int) $b['booking_id']);
    $rental = get_rental((int) $b['booking_id']);

    // Strip what's internal to the desk.
    $public = array_intersect_key($b, array_flip([
        'booking_id', 'booking_reference', 'brand', 'model', 'year', 'vehicle_type', 'transmission', 'pickup_datetime', 'return_datetime',
        'rental_days', 'daily_rate_snapshot', 'base_amount', 'discount_amount', 'deposit_amount', 'total_amount', 'booking_status',
        'payment_status', 'notes', 'created_at', 'confirmed_at', 'cancelled_at', 'cancellation_reason', 'cancellation_fee',
        'amount_paid', 'amount_due', 'balance_due', 'refund_due', 'extra_charges',
    ]));
    $public['cancelled_by_customer'] = $b['cancelled_at'] !== null && $b['cancelled_by'] === null;

    return [
        'booking'          => $public,
        'long_rental_discount' => (int) $b['rental_days'] >= LONG_RENTAL_MIN_DAYS ? round($b['base_amount'] * LONG_RENTAL_DISCOUNT_PCT / 100, 2) : 0.0,
        'manual_discount'  => manual_discount_part($b),
        'payments'         => array_map(fn ($p) => array_intersect_key($p, array_flip([
                                  'payment_id', 'receipt_number', 'amount', 'payment_type', 'refund_of', 'payment_method', 'status', 'paid_at',
                              ])) + ['label' => payment_type_label($p)], booking_payments((int) $b['booking_id'])),
        'submissions'      => array_map(fn ($s) => array_intersect_key($s, array_flip([
                                  'submission_id', 'amount', 'payment_type', 'type_label', 'method_label', 'transaction_ref', 'paid_on',
                                  'status', 'review_note', 'created_at', 'receipt_number',
                              ])), booking_submissions((int) $b['booking_id'])),
        'pay'              => $buckets,
        'can_pay'          => ($buckets['deposit'] + $buckets['rental_fee']) > 0 && !in_array($status, ['cancelled', 'no_show'], true),
        'can_cancel'       => $can_cancel,
        'cancel_fee'       => $can_cancel ? customer_cancellation_fee($b) : 0.0,
        'free_cancel_hours'=> FREE_CANCELLATION_HOURS,
        'free_cancel_until'=> $status === 'confirmed'
                                ? (new DateTimeImmutable($b['pickup_datetime']))->modify('-' . FREE_CANCELLATION_HOURS . ' hours')->format('Y-m-d H:i:s')
                                : null,
        'invoice'          => $invoice ? ['invoice_id' => (int) $invoice['invoice_id'], 'invoice_number' => $invoice['invoice_number']] : null,
        'returned_at'      => $rental['returned_at'] ?? null,
        'released_at'      => $rental['released_at'] ?? null,
    ];
}

/** The customer's bookings, newest pickup first, each with a "where it stands" line. */
function list_own_bookings(array $customer): array
{
    return list_bookings(['customer_id' => (int) $customer['customer_id']]);
}

/** The next thing on the customer's calendar: a rental that's out, or the next pickup. */
function next_own_booking(array $customer): ?array
{
    $stmt = Database::getConnection()->prepare(
        BOOKING_SELECT . " WHERE b.customer_id = ? AND (b.booking_status = 'active'
                              OR (b.booking_status IN ('pending', 'confirmed') AND b.return_datetime > NOW()))
         ORDER BY b.booking_status = 'active' DESC, b.pickup_datetime ASC LIMIT 1"
    );
    $stmt->execute([(int) $customer['customer_id']]);
    $row = $stmt->fetch();
    return $row ? normalize_booking($row) : null;
}

function customer_notifications(int $user_id, int $limit = 8): array
{
    $stmt = Database::getConnection()->prepare(
        'SELECT notification_id, title, message, target, is_read, created_at FROM notifications WHERE user_id = ?
         ORDER BY created_at DESC, notification_id DESC LIMIT ' . (int) $limit
    );
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function mark_notifications_read(int $user_id): void
{
    Database::getConnection()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$user_id]);
}

/** Every receipt and invoice on the customer's bookings. */
function own_paperwork(array $customer): array
{
    $db = Database::getConnection();
    $stmt = $db->prepare(
        "SELECT p.payment_id, p.receipt_number, p.amount, p.payment_type, p.refund_of, p.payment_method, p.status, p.paid_at,
                b.booking_id, b.booking_reference
         FROM payments p JOIN bookings b ON b.booking_id = p.booking_id
         WHERE b.customer_id = ? AND p.payment_type <> 'deposit_applied'
         ORDER BY p.paid_at DESC, p.payment_id DESC"
    );
    $stmt->execute([(int) $customer['customer_id']]);
    $payments = array_map(fn ($p) => $p + ['label' => payment_type_label($p)], $stmt->fetchAll());

    $stmt = $db->prepare(
        "SELECT i.invoice_id, i.invoice_number, i.total, i.status, i.issue_date, b.booking_id, b.booking_reference
         FROM invoices i JOIN bookings b ON b.booking_id = i.booking_id
         WHERE b.customer_id = ? AND i.status <> 'void'
         ORDER BY i.issue_date DESC, i.invoice_id DESC"
    );
    $stmt->execute([(int) $customer['customer_id']]);
    $invoices = $stmt->fetchAll();

    $stmt = $db->prepare(SUBMISSION_SELECT . " WHERE b.customer_id = ? AND s.status IN ('submitted', 'rejected') ORDER BY s.created_at DESC");
    $stmt->execute([(int) $customer['customer_id']]);
    $submissions = array_map('normalize_submission', $stmt->fetchAll());

    return ['payments' => $payments, 'invoices' => $invoices, 'submissions' => $submissions];
}

// ---------------------------------------------------------------------
// Profile
// ---------------------------------------------------------------------

/** Fields locked once the desk has verified the customer (they're what was checked). */
const VERIFIED_LOCKED_FIELDS = ['date_of_birth', 'license_number', 'license_expiry', 'id_type', 'id_number'];
const PROFILE_FIELDS = ['full_name', 'email', 'phone', 'date_of_birth', 'address_line', 'city',
                        'license_number', 'license_expiry', 'id_type', 'id_number'];

/** @return string[] errors; empty means saved */
function update_own_profile(array $customer, array $in): array
{
    $data = [];
    foreach (PROFILE_FIELDS as $f) {
        $data[$f] = (string) ($customer[$f] ?? '');
    }
    $locked = $customer['account_status'] === 'verified' ? VERIFIED_LOCKED_FIELDS : [];
    foreach (PROFILE_FIELDS as $f) {
        if (!in_array($f, $locked, true) && array_key_exists($f, $in)) {
            $data[$f] = (string) $in[$f];
        }
    }
    if ($errors = validate_customer_input($data, $customer)) {
        return $errors;
    }
    update_customer($customer, $data);
    return [];
}

/** @return ?string error, null on success */
function change_own_password(array $user, string $current, string $new, string $confirm): ?string
{
    $stmt = Database::getConnection()->prepare('SELECT password_hash FROM users WHERE user_id = ?');
    $stmt->execute([(int) $user['user_id']]);
    if (!verify_password($current, (string) $stmt->fetchColumn())) {
        return 'Your current password is wrong.';
    }
    if ($new !== $confirm) {
        return 'The new passwords don\'t match.';
    }
    if ($new === $current) {
        return 'Choose a password different from the current one.';
    }
    if ($error = password_policy_error($new)) {
        return $error;
    }
    Database::getConnection()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
        ->execute([hash_password($new), (int) $user['user_id']]);
    bump_session_version((int) $user['user_id'], true); // other devices are signed out; this one stays
    return null;
}
