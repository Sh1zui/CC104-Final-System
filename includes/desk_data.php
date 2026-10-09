<?php
/**
 * The staff front desk (Phase 10): who is picking up a car soon, who is
 * bringing one back, and what stands in the way of each handover. Read-only
 * — the release and return themselves happen in the booking window, under
 * the same rules as everywhere else (includes/rentals_data.php).
 */

require_once __DIR__ . '/bookings_data.php';
require_once __DIR__ . '/maintenance_data.php';

/**
 * Bookings to hand over: pending or confirmed, pickup before the end of
 * tomorrow (late pickups included), and not already past their return time.
 * Each row says whether the car can be released right now and, if not, why.
 */
function release_queue(?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable();
    $horizon = $now->setTime(0, 0)->modify('+2 days')->format('Y-m-d H:i:s');

    $stmt = Database::getConnection()->prepare(
        BOOKING_SELECT . "
        WHERE b.booking_status IN ('pending', 'confirmed')
          AND b.pickup_datetime < ? AND b.return_datetime > ?
        ORDER BY b.pickup_datetime ASC, b.booking_id ASC"
    );
    $stmt->execute([$horizon, $now->format('Y-m-d H:i:s')]);

    $today = $now->format('Y-m-d');
    $rows = [];
    foreach ($stmt->fetchAll() as $b) {
        $b = normalize_booking($b);
        $pickup = new DateTimeImmutable($b['pickup_datetime']);
        $actions = booking_allowed_actions($b, $now);

        $b['late']        = $pickup->modify('+' . PICKUP_GRACE_MINUTES . ' minutes') < $now;
        $b['day']         = $pickup->format('Y-m-d') <= $today ? 'today' : 'tomorrow';
        $b['can_release'] = in_array('check_out', $actions, true);
        $b['issues']      = release_issues($b, $now);
        // Ready = the Release button works now and nothing needs sorting first.
        $b['ready']       = $b['can_release'] && !$b['issues'];
        $rows[] = $b;
    }
    return $rows;
}

/**
 * What would stop (or should slow down) a release. The release itself
 * re-checks all of this under a row lock; this is the early warning.
 */
function release_issues(array $b, DateTimeImmutable $now): array
{
    $issues = [];
    if ($b['booking_status'] === 'pending') {
        $issues[] = 'Not confirmed yet';
    }

    $customer = get_customer((int) $b['customer_id']);
    if ($customer) {
        foreach (customer_booking_blockers($customer, new DateTimeImmutable($b['return_datetime'])) as $blocker) {
            $issues[] = $blocker;
        }
    }

    $db = Database::getConnection();
    $stmt = $db->prepare(
        "SELECT booking_reference, return_datetime FROM bookings
         WHERE vehicle_id = ? AND booking_status = 'active' AND booking_id <> ? LIMIT 1"
    );
    $stmt->execute([$b['vehicle_id'], $b['booking_id']]);
    if ($other = $stmt->fetch()) {
        $issues[] = 'Car is still out on ' . $other['booking_reference'] . ' (due ' . format_datetime_short($other['return_datetime']) . ')';
    }

    $stmt = $db->prepare(
        "SELECT maintenance_type, end_date FROM maintenance
         WHERE vehicle_id = ? AND status = 'in_progress' ORDER BY end_date DESC LIMIT 1"
    );
    $stmt->execute([$b['vehicle_id']]);
    if ($job = $stmt->fetch()) {
        $issues[] = 'Car is in the workshop (' . strtolower(humanize($job['maintenance_type'])) . ', planned end '
            . date('M j', strtotime($job['end_date'])) . ')';
    }
    return $issues;
}

/** Rentals that are out, soonest (or most overdue) first. */
function return_queue(?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable();
    $stmt = Database::getConnection()->prepare(
        BOOKING_SELECT . "
        WHERE b.booking_status = 'active'
        ORDER BY b.return_datetime ASC, b.booking_id ASC"
    );
    $stmt->execute();

    $today = $now->format('Y-m-d');
    $rows = [];
    foreach ($stmt->fetchAll() as $b) {
        $b = normalize_booking($b);
        $return = new DateTimeImmutable($b['return_datetime']);
        $b['overdue']     = $return < $now;
        $b['late_by']     = $b['overdue'] ? duration_text($return, $now) : null;
        $b['due_today']   = !$b['overdue'] && $return->format('Y-m-d') === $today;
        $b['day']         = $b['overdue'] ? 'overdue' : ($b['due_today'] ? 'today' : 'later');
        $rows[] = $b;
    }
    return $rows;
}

/** "3 h 20 min", "2 days 4 h" — how long between two moments, roughly. */
function duration_text(DateTimeImmutable $from, DateTimeImmutable $to): string
{
    $minutes = intdiv(max(0, $to->getTimestamp() - $from->getTimestamp()), 60);
    $days = intdiv($minutes, 1440);
    $hours = intdiv($minutes % 1440, 60);
    $mins = $minutes % 60;
    if ($days > 0) {
        return $days . ' day' . ($days === 1 ? '' : 's') . ($hours ? ' ' . $hours . ' h' : '');
    }
    if ($hours > 0) {
        return $hours . ' h' . ($mins ? ' ' . $mins . ' min' : '');
    }
    return $mins . ' min';
}

/** The four numbers on the desk. */
function desk_counts(array $releases, array $returns): array
{
    $pending = (int) Database::getConnection()->query(
        "SELECT COUNT(*) FROM bookings WHERE booking_status = 'pending' AND return_datetime > NOW()"
    )->fetchColumn();
    return [
        'pickups_today' => count(array_filter($releases, fn ($b) => $b['day'] === 'today')),
        'ready_now'     => count(array_filter($releases, fn ($b) => $b['ready'])),
        'returns_today' => count(array_filter($returns, fn ($b) => $b['day'] !== 'later')),
        'overdue'       => count(array_filter($returns, fn ($b) => $b['overdue'])),
        'out_now'       => count($returns),
        'pending'       => $pending,
        'in_workshop'   => maintenance_totals()['in_workshop'],
    ];
}

/** Every car and where it is right now, for the desk's fleet board. */
function fleet_board(): array
{
    $rows = Database::getConnection()->query(
        "SELECT v.vehicle_id, v.brand, v.model, v.plate_number, v.status, v.mileage_km,
                (SELECT b.return_datetime FROM bookings b
                  WHERE b.vehicle_id = v.vehicle_id AND b.booking_status = 'active' LIMIT 1) AS out_until,
                (SELECT m.end_date FROM maintenance m
                  WHERE m.vehicle_id = v.vehicle_id AND m.status = 'in_progress' ORDER BY m.end_date DESC LIMIT 1) AS workshop_until,
                (SELECT MIN(b.pickup_datetime) FROM bookings b
                  WHERE b.vehicle_id = v.vehicle_id AND b.booking_status IN ('pending', 'confirmed')
                    AND b.pickup_datetime > NOW()) AS next_pickup
         FROM vehicles v
         WHERE v.deleted_at IS NULL
         ORDER BY FIELD(v.status, 'available', 'reserved', 'rented', 'maintenance', 'unavailable'), v.brand, v.model"
    )->fetchAll();
    return $rows;
}
