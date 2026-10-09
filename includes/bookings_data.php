<?php
/**
 * The booking engine: availability, pricing, and every booking status
 * change. Shared by admin/bookings.php and api/bookings.php now, and by
 * the staff portal (Phase 10) and customer booking flow (Phase 11) later,
 * so there is exactly one implementation of each rule.
 *
 * Status lifecycle handled here:
 *   pending ──confirm──> confirmed ──(check-out, Phase 7)──> active ──(check-in)──> completed
 *      │                    │  └──no_show (after pickup time passes)
 *      └──cancel──┬─────────┘
 *                 v
 *             cancelled
 *
 * Double-booking prevention: every write that claims a vehicle for a time
 * window first locks that vehicle's row (SELECT ... FOR UPDATE) inside a
 * transaction, then re-checks availability, then writes. Two people
 * booking the same car at the same moment are therefore handled one after
 * the other, and the second one sees the first one's booking.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/customers_data.php'; // get_customer(), notify_user(), license_is_expired()
require_once __DIR__ . '/vehicles_data.php';  // VEHICLE_TYPES, TRANSMISSIONS

const BOOKING_STATUSES  = ['pending', 'confirmed', 'active', 'completed', 'cancelled', 'no_show'];
// Statuses that hold a vehicle for their time window.
const BLOCKING_STATUSES = ['pending', 'confirmed', 'active'];

/** A booking rule was broken; the message is safe to show to the person. */
class BookingError extends RuntimeException {}

// ---------------------------------------------------------------------
// Time window + pricing (pure functions — no database)
// ---------------------------------------------------------------------

/**
 * Checks the requested pickup/return against the booking rules. Returns a
 * list of error strings; empty means the window is fine.
 */
function booking_window_errors(?DateTimeImmutable $pickup, ?DateTimeImmutable $return, ?DateTimeImmutable $now = null): array
{
    if (!$pickup || !$return) {
        return ['Enter a valid pickup and return date and time.'];
    }
    $now = $now ?? new DateTimeImmutable();
    $errors = [];

    if ($return <= $pickup) {
        $errors[] = 'Return must be after pickup.';
    }
    if ($pickup < $now->modify('-' . PICKUP_GRACE_MINUTES . ' minutes')) {
        $errors[] = 'Pickup can\'t be in the past.';
    }
    if ($pickup > $now->modify('+' . MAX_ADVANCE_BOOKING_DAYS . ' days')) {
        $errors[] = 'Pickup can be at most ' . MAX_ADVANCE_BOOKING_DAYS . ' days from now.';
    }
    if ($return > $pickup && rental_days($pickup->format('Y-m-d H:i:s'), $return->format('Y-m-d H:i:s')) > MAX_RENTAL_DAYS) {
        $errors[] = 'A single booking can be at most ' . MAX_RENTAL_DAYS . ' days.';
    }

    return $errors;
}

/**
 * The price of a booking, every component itemized. Money is rounded to
 * centavos at each step so the parts always add up to the total exactly.
 *
 * @throws BookingError when the manual discount is out of range
 */
function quote_booking(float $daily_rate, DateTimeImmutable $pickup, DateTimeImmutable $return, float $manual_discount = 0.0): array
{
    $days = rental_days($pickup->format('Y-m-d H:i:s'), $return->format('Y-m-d H:i:s'));
    $base = round($daily_rate * $days, 2);

    $long_rental = $days >= LONG_RENTAL_MIN_DAYS ? round($base * LONG_RENTAL_DISCOUNT_PCT / 100, 2) : 0.0;

    $manual_discount = round($manual_discount, 2);
    $max_manual = round($base * MAX_MANUAL_DISCOUNT_PCT / 100, 2);
    if ($manual_discount < 0) {
        throw new BookingError('Discount can\'t be negative.');
    }
    if ($manual_discount > $max_manual) {
        throw new BookingError('Extra discount can be at most ' . MAX_MANUAL_DISCOUNT_PCT . '% of the base amount (' . money($max_manual) . ').');
    }

    $discount = min($base, round($long_rental + $manual_discount, 2));
    $deposit  = (float) SECURITY_DEPOSIT;

    return [
        'rental_days'          => $days,
        'daily_rate'           => round($daily_rate, 2),
        'base_amount'          => $base,
        'long_rental_discount' => $long_rental,
        'manual_discount'      => $manual_discount,
        'discount_amount'      => $discount,
        'deposit_amount'       => $deposit,
        'total_amount'         => round($base - $discount + $deposit, 2),
    ];
}

// ---------------------------------------------------------------------
// Availability
// ---------------------------------------------------------------------

/**
 * Why this vehicle can't be booked for this window (empty = it can).
 * $exclude_booking_id lets a booking being rescheduled ignore itself.
 * Callers that are about to write must hold the vehicle's row lock.
 */
function vehicle_availability_problems(int $vehicle_id, DateTimeImmutable $pickup, DateTimeImmutable $return, ?int $exclude_booking_id = null): array
{
    $pdo = Database::getConnection();
    $problems = [];

    $stmt = $pdo->prepare('SELECT status, deleted_at FROM vehicles WHERE vehicle_id = ?');
    $stmt->execute([$vehicle_id]);
    $vehicle = $stmt->fetch();
    if (!$vehicle || $vehicle['deleted_at'] !== null) {
        return ['This vehicle is no longer in the fleet.'];
    }
    // "maintenance" isn't a blanket block any more (Phase 9): a job holds
    // the car only for its own days (checked below), so a car in for a
    // two-day service can still be booked for next week. "unavailable" is.
    if ($vehicle['status'] === 'unavailable') {
        $problems[] = 'The vehicle is marked "unavailable".';
    }
    // A job still in progress past its planned end: like an overdue rental,
    // the car isn't back, so it can't be promised to anyone yet.
    $stmt = $pdo->prepare(
        "SELECT maintenance_type, end_date FROM maintenance
         WHERE vehicle_id = ? AND status = 'in_progress' AND end_date < CURDATE()"
    );
    $stmt->execute([$vehicle_id]);
    foreach ($stmt->fetchAll() as $m) {
        $problems[] = 'Still in the workshop (' . humanize($m['maintenance_type']) . ', planned to finish '
            . date('M j, Y', strtotime($m['end_date'])) . ').';
    }

    foreach (conflicting_bookings($vehicle_id, $pickup, $return, $exclude_booking_id) as $b) {
        $problems[] = 'Already booked ' . format_datetime($b['pickup_datetime']) . ' → ' . format_datetime($b['return_datetime'])
            . ' (' . $b['booking_reference'] . ', ' . $b['booking_status'] . ').';
    }

    foreach (overdue_rentals($vehicle_id, $exclude_booking_id) as $b) {
        $problems[] = 'Still out on an overdue rental (' . $b['booking_reference'] . ', due ' . format_datetime($b['return_datetime']) . ').';
    }

    // A job holds the car for whole days, service_date to end_date.
    $stmt = $pdo->prepare(
        "SELECT maintenance_type, service_date, end_date FROM maintenance
         WHERE vehicle_id = ? AND status IN ('scheduled', 'in_progress')
           AND service_date <= ? AND end_date >= ?"
    );
    $stmt->execute([$vehicle_id, $return->format('Y-m-d'), $pickup->format('Y-m-d')]);
    foreach ($stmt->fetchAll() as $m) {
        $problems[] = 'Maintenance (' . humanize($m['maintenance_type']) . ') is scheduled '
            . format_date_range($m['service_date'], $m['end_date']) . '.';
    }

    return $problems;
}

/**
 * Bookings that overlap the window, counting the turnaround gap on both
 * sides: a new rental must start at least TURNAROUND_HOURS after another
 * one ends, and end at least TURNAROUND_HOURS before the next one starts.
 */
function conflicting_bookings(int $vehicle_id, DateTimeImmutable $pickup, DateTimeImmutable $return, ?int $exclude_booking_id = null): array
{
    $buffer = 'PT' . TURNAROUND_HOURS . 'H';
    $sql = "SELECT booking_id, booking_reference, booking_status, pickup_datetime, return_datetime
            FROM bookings
            WHERE vehicle_id = ?
              AND booking_status IN ('pending', 'confirmed', 'active')
              AND pickup_datetime < ?
              AND return_datetime > ?";
    $params = [
        $vehicle_id,
        $return->add(new DateInterval($buffer))->format('Y-m-d H:i:s'),
        $pickup->sub(new DateInterval($buffer))->format('Y-m-d H:i:s'),
    ];
    if ($exclude_booking_id !== null) {
        $sql .= ' AND booking_id != ?';
        $params[] = $exclude_booking_id;
    }
    $stmt = Database::getConnection()->prepare($sql . ' ORDER BY pickup_datetime');
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/** An active rental past its return time: the car physically isn't back yet. */
function overdue_rentals(int $vehicle_id, ?int $exclude_booking_id = null): array
{
    $stmt = Database::getConnection()->prepare(
        "SELECT booking_id, booking_reference, return_datetime FROM bookings
         WHERE vehicle_id = ? AND booking_status = 'active' AND return_datetime < NOW() AND booking_id != ?"
    );
    $stmt->execute([$vehicle_id, $exclude_booking_id ?? 0]);

    return $stmt->fetchAll();
}

/**
 * Every vehicle free for the window, each with its price quote. The same
 * rules as vehicle_availability_problems(), expressed as one query so the
 * search doesn't run N queries for N vehicles.
 *
 * @param array{type?: string, transmission?: string, min_seats?: int, max_rate?: float} $filters
 */
function search_available_vehicles(DateTimeImmutable $pickup, DateTimeImmutable $return, array $filters = [], ?int $exclude_booking_id = null): array
{
    $buffer = 'PT' . TURNAROUND_HOURS . 'H';
    $where = [
        'v.deleted_at IS NULL',
        "v.status != 'unavailable'",
        "NOT EXISTS (SELECT 1 FROM maintenance mo
                     WHERE mo.vehicle_id = v.vehicle_id AND mo.status = 'in_progress' AND mo.end_date < CURDATE())",
        "NOT EXISTS (SELECT 1 FROM bookings b
                     WHERE b.vehicle_id = v.vehicle_id
                       AND b.booking_status IN ('pending', 'confirmed', 'active')
                       AND b.pickup_datetime < ? AND b.return_datetime > ?
                       AND b.booking_id != ?)",
        "NOT EXISTS (SELECT 1 FROM bookings b
                     WHERE b.vehicle_id = v.vehicle_id AND b.booking_status = 'active'
                       AND b.return_datetime < NOW() AND b.booking_id != ?)",
        "NOT EXISTS (SELECT 1 FROM maintenance m
                     WHERE m.vehicle_id = v.vehicle_id AND m.status IN ('scheduled', 'in_progress')
                       AND m.service_date <= ? AND m.end_date >= ?)",
    ];
    $params = [
        $return->add(new DateInterval($buffer))->format('Y-m-d H:i:s'),
        $pickup->sub(new DateInterval($buffer))->format('Y-m-d H:i:s'),
        $exclude_booking_id ?? 0,
        $exclude_booking_id ?? 0,
        $return->format('Y-m-d'),
        $pickup->format('Y-m-d'),
    ];

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
    if (!empty($filters['max_rate'])) {
        $where[] = 'v.daily_rate <= ?';
        $params[] = (float) $filters['max_rate'];
    }

    $stmt = Database::getConnection()->prepare(
        'SELECT v.vehicle_id, v.brand, v.model, v.year, v.plate_number, v.vehicle_type, v.transmission,
                v.fuel_type, v.seating_capacity, v.color, v.daily_rate,
                (SELECT image_path FROM vehicle_images vi WHERE vi.vehicle_id = v.vehicle_id
                 ORDER BY is_primary DESC, image_id ASC LIMIT 1) AS primary_image
         FROM vehicles v
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY v.daily_rate, v.brand, v.model'
    );
    $stmt->execute($params);

    $vehicles = $stmt->fetchAll();
    foreach ($vehicles as &$v) {
        $v['daily_rate'] = (float) $v['daily_rate'];
        $v['quote'] = quote_booking($v['daily_rate'], $pickup, $return);
    }
    unset($v);

    return $vehicles;
}

// ---------------------------------------------------------------------
// Customer eligibility
// ---------------------------------------------------------------------

/** Why this customer can't book a rental ending at $return (empty = they can). */
function customer_booking_blockers(array $customer, ?DateTimeImmutable $return = null): array
{
    $blockers = [];
    if ($customer['login_status'] !== 'active') {
        $blockers[] = 'Their login is ' . $customer['login_status'] . '.';
    }
    if ($customer['account_status'] === 'blocked') {
        $blockers[] = 'The customer is blocked from booking.';
    } elseif ($customer['account_status'] !== 'verified') {
        $blockers[] = 'The customer isn\'t verified yet (license and an approved ID document are needed).';
    }
    $expiry = parse_date($customer['license_expiry'] ?? null);
    if ($expiry && $return && $expiry < new DateTimeImmutable($return->format('Y-m-d'))) {
        $blockers[] = 'Their driver\'s license expires ' . $expiry->format('M j, Y') . ', before the return date.';
    } elseif ($expiry && license_is_expired($customer['license_expiry'])) {
        $blockers[] = 'Their driver\'s license has expired.';
    }
    return $blockers;
}

/** Customers who could book right now, for the "new booking" picker. */
function bookable_customers(): array
{
    return Database::getConnection()->query(
        "SELECT c.customer_id, u.full_name, u.email, u.phone, c.license_number, c.license_expiry
         FROM customers c JOIN users u ON u.user_id = c.user_id
         WHERE c.account_status = 'verified' AND u.status = 'active'
           AND (c.license_expiry IS NULL OR c.license_expiry >= CURDATE())
         ORDER BY u.full_name"
    )->fetchAll();
}

// ---------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------

const BOOKING_SELECT = "
    SELECT b.*, u.full_name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
           c.user_id AS customer_user_id, c.account_status AS customer_status,
           v.brand, v.model, v.year, v.plate_number, v.vehicle_type, v.transmission, v.daily_rate AS current_daily_rate,
           cb.full_name AS created_by_name, cf.full_name AS confirmed_by_name, cx.full_name AS cancelled_by_name,
           COALESCE((SELECT SUM(" . NET_CASH_SQL . ")
                     FROM payments p WHERE p.booking_id = b.booking_id AND p.status = 'completed'), 0) AS amount_paid,
           (SELECT COUNT(*) FROM payments p
            WHERE p.booking_id = b.booking_id AND p.status = 'completed' AND p.payment_type = 'refund') AS refund_count,
           (SELECT COUNT(*) FROM payment_submissions ps
            WHERE ps.booking_id = b.booking_id AND ps.status = 'submitted') AS submissions_waiting,
           COALESCE((SELECT r.late_fee + r.fuel_fee + r.damage_fee FROM rentals r WHERE r.booking_id = b.booking_id), 0)
         + COALESCE((SELECT SUM(pe.amount) FROM penalties pe WHERE pe.booking_id = b.booking_id), 0) AS extra_charges
    FROM bookings b
    JOIN customers c ON c.customer_id = b.customer_id
    JOIN users     u ON u.user_id     = c.user_id
    JOIN vehicles  v ON v.vehicle_id  = b.vehicle_id
    LEFT JOIN users cb ON cb.user_id = b.created_by
    LEFT JOIN users cf ON cf.user_id = b.confirmed_by
    LEFT JOIN users cx ON cx.user_id = b.cancelled_by";

function normalize_booking(array $b): array
{
    foreach (['daily_rate_snapshot', 'base_amount', 'discount_amount', 'deposit_amount', 'total_amount',
              'amount_paid', 'current_daily_rate', 'extra_charges', 'cancellation_fee'] as $k) {
        $b[$k] = round((float) $b[$k], 2);
    }
    $b['rental_days'] = (int) $b['rental_days'];
    $b['amount_due']  = booking_amount_due($b);
    $b['balance_due'] = round(max(0, $b['amount_due'] - $b['amount_paid']), 2);
    $b['refund_due']  = round(max(0, $b['amount_paid'] - $b['amount_due']), 2);
    return $b;
}

/**
 * What the customer owes in total for this booking, as things stand.
 *
 * Until the car comes back, the refundable deposit is part of what's
 * collected. Once the rental is completed, the deposit is owed back to
 * them, so the real cost is the rental itself plus any extra charges
 * (late, fuel, damage, penalties). A cancelled or no-show booking owes
 * only its cancellation fee (set when it was cancelled). Any difference
 * shows up as balance_due (they owe) or refund_due (we owe; settling the
 * booking records it). One rule, used by every screen, payment_status,
 * and the invoice total.
 */
function booking_amount_due(array $b): float
{
    if (in_array($b['booking_status'], ['cancelled', 'no_show'], true)) {
        return round((float) $b['cancellation_fee'] + (float) ($b['extra_charges'] ?? 0), 2);
    }
    $rental = (float) $b['total_amount'] - (float) $b['deposit_amount'];
    $extras = (float) ($b['extra_charges'] ?? 0);
    $deposit = $b['booking_status'] === 'completed' ? 0.0 : (float) $b['deposit_amount'];

    return round($rental + $extras + $deposit, 2);
}

function get_booking(int $booking_id): ?array
{
    $stmt = Database::getConnection()->prepare(BOOKING_SELECT . ' WHERE b.booking_id = ?');
    $stmt->execute([$booking_id]);
    $row = $stmt->fetch();

    return $row ? normalize_booking($row) : null;
}

/** @param array{status?: string, customer_id?: int} $filters */
function list_bookings(array $filters = []): array
{
    $where = ['1 = 1'];
    $params = [];
    if (!empty($filters['status']) && in_array($filters['status'], BOOKING_STATUSES, true)) {
        $where[] = 'b.booking_status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['customer_id'])) {
        $where[] = 'b.customer_id = ?';
        $params[] = (int) $filters['customer_id'];
    }
    $stmt = Database::getConnection()->prepare(
        BOOKING_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY b.pickup_datetime DESC, b.booking_id DESC'
    );
    $stmt->execute($params);

    return array_map('normalize_booking', $stmt->fetchAll());
}

/** Which buttons make sense for this booking right now. */
function booking_allowed_actions(array $b, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable();
    $pickup = new DateTimeImmutable($b['pickup_datetime']);
    $return = new DateTimeImmutable($b['return_datetime']);
    $actions = [];

    if ($b['booking_status'] === 'pending' && $return > $now) {
        $actions[] = 'confirm';
    }
    if (in_array($b['booking_status'], ['pending', 'confirmed'], true)) {
        $actions[] = 'cancel';
        if ($return > $now) {
            $actions[] = 'reschedule';
        }
    }
    if ($b['booking_status'] === 'confirmed' && $pickup->modify('-' . EARLY_CHECKOUT_MINUTES . ' minutes') <= $now && $return > $now) {
        $actions[] = 'check_out';
    }
    if ($b['booking_status'] === 'confirmed' && $pickup->modify('+' . PICKUP_GRACE_MINUTES . ' minutes') < $now) {
        $actions[] = 'no_show';
    }
    if ($b['booking_status'] === 'active') {
        $actions[] = 'check_in';
        $actions[] = 'extend';
    }
    if (in_array($b['booking_status'], ['active', 'completed'], true)) {
        $actions[] = 'add_charge';
    }
    return $actions;
}

// ---------------------------------------------------------------------
// Writes. Each one runs in a transaction and throws BookingError with a
// human-readable message when a rule says no.
// ---------------------------------------------------------------------

/** Lock a vehicle row for the rest of the transaction; returns it. */
function lock_vehicle(int $vehicle_id): array
{
    $stmt = Database::getConnection()->prepare('SELECT * FROM vehicles WHERE vehicle_id = ? FOR UPDATE');
    $stmt->execute([$vehicle_id]);
    $vehicle = $stmt->fetch();
    if (!$vehicle || $vehicle['deleted_at'] !== null) {
        throw new BookingError('That vehicle is no longer in the fleet.');
    }
    return $vehicle;
}

function lock_booking(int $booking_id): array
{
    $stmt = Database::getConnection()->prepare('SELECT * FROM bookings WHERE booking_id = ? FOR UPDATE');
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch();
    if (!$booking) {
        throw new BookingError('That booking no longer exists.');
    }
    return $booking;
}

/** Runs $work inside a transaction; rolls back on any exception and rethrows it. */
function in_transaction(callable $work)
{
    $pdo = Database::getConnection();
    // Already inside one (e.g. accepting an online payment records a payment):
    // join it, so the outer caller commits or rolls back everything together.
    if ($pdo->inTransaction()) {
        return $work($pdo);
    }
    $pdo->beginTransaction();
    try {
        $result = $work($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function new_booking_reference(): string
{
    $stmt = Database::getConnection()->prepare('SELECT 1 FROM bookings WHERE booking_reference = ?');
    do {
        $reference = generate_reference('BK');
        $stmt->execute([$reference]);
    } while ($stmt->fetchColumn());

    return $reference;
}

/**
 * Create a booking. $acting_user_id is the staff/admin who made it (NULL
 * when a customer books for themself). Staff may confirm on the spot.
 *
 * @return int the new booking_id
 * @throws BookingError
 */
function create_booking(int $customer_id, int $vehicle_id, DateTimeImmutable $pickup, DateTimeImmutable $return,
                        float $manual_discount, string $notes, ?int $acting_user_id, bool $confirm_now = false): int
{
    if ($errors = booking_window_errors($pickup, $return)) {
        throw new BookingError(implode(' ', $errors));
    }
    if (mb_strlen($notes) > 500) {
        throw new BookingError('Notes can be at most 500 characters.');
    }

    return in_transaction(function (PDO $pdo) use ($customer_id, $vehicle_id, $pickup, $return, $manual_discount, $notes, $acting_user_id, $confirm_now) {
        // 1. Claim the car. Anyone else booking it waits here until we commit.
        $vehicle = lock_vehicle($vehicle_id);

        // 2. Re-check everything now that nobody else can change it.
        $customer = get_customer($customer_id);
        if (!$customer) {
            throw new BookingError('That customer no longer exists.');
        }
        if ($blockers = customer_booking_blockers($customer, $return)) {
            throw new BookingError('This customer can\'t book: ' . implode(' ', $blockers));
        }
        if ($problems = vehicle_availability_problems($vehicle_id, $pickup, $return)) {
            throw new BookingError('That vehicle isn\'t available for those dates. ' . implode(' ', $problems));
        }

        // 3. Price it from the locked row's current rate, then write.
        $quote = quote_booking((float) $vehicle['daily_rate'], $pickup, $return, $manual_discount);
        $status = $confirm_now ? 'confirmed' : 'pending';
        $pdo->prepare(
            'INSERT INTO bookings (booking_reference, customer_id, vehicle_id, pickup_datetime, return_datetime, rental_days,
                                   daily_rate_snapshot, base_amount, discount_amount, deposit_amount, total_amount,
                                   booking_status, notes, created_by, confirmed_by, confirmed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            new_booking_reference(), $customer_id, $vehicle_id,
            $pickup->format('Y-m-d H:i:s'), $return->format('Y-m-d H:i:s'), $quote['rental_days'],
            $quote['daily_rate'], $quote['base_amount'], $quote['discount_amount'], $quote['deposit_amount'], $quote['total_amount'],
            $status, trim($notes) === '' ? null : trim($notes), $acting_user_id,
            $confirm_now ? $acting_user_id : null, $confirm_now ? date('Y-m-d H:i:s') : null,
        ]);

        return (int) $pdo->lastInsertId();
    });
}

/** @throws BookingError */
function confirm_booking(int $booking_id, int $acting_user_id): void
{
    in_transaction(function (PDO $pdo) use ($booking_id, $acting_user_id) {
        $booking = lock_booking($booking_id);
        if ($booking['booking_status'] !== 'pending') {
            throw new BookingError('Only a pending booking can be confirmed (this one is ' . $booking['booking_status'] . ').');
        }
        if (new DateTimeImmutable($booking['return_datetime']) <= new DateTimeImmutable()) {
            throw new BookingError('This booking\'s return time has already passed. Cancel it instead.');
        }
        // The customer may have been blocked or their license may have
        // lapsed since they booked — confirming must not skip that.
        $customer = get_customer((int) $booking['customer_id']);
        if ($blockers = customer_booking_blockers($customer, new DateTimeImmutable($booking['return_datetime']))) {
            throw new BookingError('Can\'t confirm: ' . implode(' ', $blockers));
        }
        $pdo->prepare("UPDATE bookings SET booking_status = 'confirmed', confirmed_by = ?, confirmed_at = NOW() WHERE booking_id = ?")
            ->execute([$acting_user_id, $booking_id]);
    });
}

/**
 * $acting_user_id NULL = the customer cancelled it themself (Phase 11).
 * @throws BookingError
 */
/**
 * The fee for cancelling this booking at $when: free until
 * FREE_CANCELLATION_HOURS before pickup, then CANCELLATION_FEE_DAYS at the
 * booked rate (never more than the rental itself costs).
 */
function cancellation_fee_for(array $booking, ?DateTimeImmutable $when = null): float
{
    $when = $when ?? new DateTimeImmutable();
    $free_until = (new DateTimeImmutable($booking['pickup_datetime']))->modify('-' . FREE_CANCELLATION_HOURS . ' hours');
    if ($when < $free_until) {
        return 0.0;
    }
    return no_show_fee_for($booking);
}

/** A no-show always costs the late-cancellation fee. */
function no_show_fee_for(array $booking): float
{
    $fee = (float) $booking['daily_rate_snapshot'] * CANCELLATION_FEE_DAYS;
    $rental_cost = (float) $booking['base_amount'] - (float) $booking['discount_amount'];
    return round(min($fee, $rental_cost), 2);
}

/**
 * $acting_user_id NULL = the customer cancelled it themself (Phase 11).
 * $waive_fee lets staff drop a late-cancellation fee (e.g. the car broke down).
 * @return float the cancellation fee charged
 * @throws BookingError
 */
function cancel_booking(int $booking_id, ?int $acting_user_id, string $reason, bool $waive_fee = false, bool $free_if_pending = false): float
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new BookingError('Give a reason for cancelling — it\'s kept on the booking and shown to the customer.');
    }
    return in_transaction(function (PDO $pdo) use ($booking_id, $acting_user_id, $reason, $waive_fee, $free_if_pending) {
        $booking = lock_booking($booking_id);
        if (!in_array($booking['booking_status'], ['pending', 'confirmed'], true)) {
            throw new BookingError('A ' . $booking['booking_status'] . ' booking can\'t be cancelled.');
        }
        // $free_if_pending: the customer's own cancel is free only if the
        // booking is *still* pending now that it's locked (Phase 14).
        $fee = ($waive_fee || ($free_if_pending && $booking['booking_status'] === 'pending')) ? 0.0 : cancellation_fee_for($booking);
        $pdo->prepare(
            "UPDATE bookings SET booking_status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancellation_reason = ?,
                    cancellation_fee = ?
             WHERE booking_id = ?"
        )->execute([$acting_user_id, mb_substr($reason, 0, 255), $fee, $booking_id]);
        recalculate_payment_status($booking_id);
        return $fee;
    });
}

/** @throws BookingError */
function mark_booking_no_show(int $booking_id, int $acting_user_id): void
{
    in_transaction(function (PDO $pdo) use ($booking_id, $acting_user_id) {
        $booking = lock_booking($booking_id);
        if ($booking['booking_status'] !== 'confirmed') {
            throw new BookingError('Only a confirmed booking can be marked as a no-show.');
        }
        $grace_end = (new DateTimeImmutable($booking['pickup_datetime']))->modify('+' . PICKUP_GRACE_MINUTES . ' minutes');
        if ($grace_end > new DateTimeImmutable()) {
            throw new BookingError('The pickup time (plus ' . PICKUP_GRACE_MINUTES . ' minutes\' grace) hasn\'t passed yet.');
        }
        $pdo->prepare(
            "UPDATE bookings SET booking_status = 'no_show', cancelled_by = ?, cancelled_at = NOW(),
                    cancellation_reason = 'Customer did not pick up the vehicle', cancellation_fee = ?
             WHERE booking_id = ?"
        )->execute([$acting_user_id, no_show_fee_for($booking), $booking_id]);
        recalculate_payment_status($booking_id);
    });
}

/**
 * Move a pending/confirmed booking to new dates and/or another vehicle.
 * Same vehicle keeps the rate the customer was quoted; a different
 * vehicle is priced at that vehicle's current rate.
 *
 * @return array the old and new totals, for the confirmation message
 * @throws BookingError
 */
function reschedule_booking(int $booking_id, int $vehicle_id, DateTimeImmutable $pickup, DateTimeImmutable $return,
                            float $manual_discount, int $acting_user_id): array
{
    if ($errors = booking_window_errors($pickup, $return)) {
        throw new BookingError(implode(' ', $errors));
    }

    return in_transaction(function (PDO $pdo) use ($booking_id, $vehicle_id, $pickup, $return, $manual_discount) {
        $booking = lock_booking($booking_id);
        if (!in_array($booking['booking_status'], ['pending', 'confirmed'], true)) {
            throw new BookingError('Only a pending or confirmed booking can be rescheduled.');
        }
        $vehicle = lock_vehicle($vehicle_id);

        $customer = get_customer((int) $booking['customer_id']);
        if ($blockers = customer_booking_blockers($customer, $return)) {
            throw new BookingError('This customer can\'t book: ' . implode(' ', $blockers));
        }
        if ($problems = vehicle_availability_problems($vehicle_id, $pickup, $return, $booking_id)) {
            throw new BookingError('That vehicle isn\'t available for those dates. ' . implode(' ', $problems));
        }

        $same_vehicle = (int) $booking['vehicle_id'] === $vehicle_id;
        $rate = $same_vehicle ? (float) $booking['daily_rate_snapshot'] : (float) $vehicle['daily_rate'];
        $quote = quote_booking($rate, $pickup, $return, $manual_discount);

        $pdo->prepare(
            'UPDATE bookings SET vehicle_id = ?, pickup_datetime = ?, return_datetime = ?, rental_days = ?,
                    daily_rate_snapshot = ?, base_amount = ?, discount_amount = ?, deposit_amount = ?, total_amount = ?
             WHERE booking_id = ?'
        )->execute([
            $vehicle_id, $pickup->format('Y-m-d H:i:s'), $return->format('Y-m-d H:i:s'), $quote['rental_days'],
            $quote['daily_rate'], $quote['base_amount'], $quote['discount_amount'], $quote['deposit_amount'], $quote['total_amount'],
            $booking_id,
        ]);
        recalculate_payment_status($booking_id);

        return ['old_total' => (float) $booking['total_amount'], 'new_total' => $quote['total_amount']];
    });
}

/**
 * Derive payment_status from what's actually been paid. Used whenever a
 * booking's total changes; the payments module (Phase 8) calls it too.
 */
function recalculate_payment_status(int $booking_id): string
{
    $b = get_booking($booking_id);

    if ($b['amount_paid'] <= 0 && $b['amount_due'] <= 0) {
        // Nothing owed and nothing held: settled, or fully refunded.
        $status = (int) $b['refund_count'] > 0 ? 'refunded' : 'paid';
    } elseif ($b['amount_paid'] <= 0) {
        $status = (int) $b['refund_count'] > 0 ? 'refunded' : 'pending';
    } elseif ($b['amount_paid'] < $b['amount_due']) {
        $status = 'partial';
    } else {
        $status = 'paid';
    }
    Database::getConnection()->prepare('UPDATE bookings SET payment_status = ? WHERE booking_id = ?')->execute([$status, $booking_id]);

    return $status;
}
