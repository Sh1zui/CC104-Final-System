<?php
/**
 * Maintenance jobs: scheduling, starting, completing, cancelling, logging
 * past work, and service-due reminders. Shared by admin/maintenance.php and
 * api/maintenance.php now, and the staff portal (Phase 10) later.
 *
 *   scheduled ──start──> in_progress ──complete──> completed
 *       │  └────────────complete (same-day job)────────┘
 *       └──cancel──> cancelled
 *
 * Two rules keep the workshop and the booking desk from contradicting
 * each other:
 *   1. A job holds its car from service_date to end_date. Scheduling or
 *      starting one locks the car's row (the same lock bookings take) and
 *      refuses if a pending/confirmed/active booking overlaps those days;
 *      bookings, in turn, refuse days a job holds (bookings_data.php).
 *   2. vehicles.status = 'maintenance' exactly while the car has a job in
 *      progress. Starting a job sets it; completing the last open one clears
 *      it. The vehicle form can't set or clear it by hand.
 */

require_once __DIR__ . '/bookings_data.php'; // lock_vehicle(), in_transaction(), BookingError

const MAINTENANCE_TYPES    = ['oil_change', 'tire_replacement', 'brake_service', 'general_inspection', 'repair', 'carwash', 'other'];
const MAINTENANCE_STATUSES = ['scheduled', 'in_progress', 'completed', 'cancelled'];

/** A maintenance rule was broken; the message is safe to show. */
class MaintenanceError extends RuntimeException {}

// ---------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------

const JOB_SELECT = "
    SELECT m.*, v.brand, v.model, v.plate_number, v.mileage_km AS vehicle_mileage, v.status AS vehicle_status,
           lb.full_name AS logged_by_name, cb.full_name AS completed_by_name, xb.full_name AS cancelled_by_name
    FROM maintenance m
    JOIN vehicles v ON v.vehicle_id = m.vehicle_id
    JOIN users lb ON lb.user_id = m.logged_by
    LEFT JOIN users cb ON cb.user_id = m.completed_by
    LEFT JOIN users xb ON xb.user_id = m.cancelled_by";

function normalize_job(array $j): array
{
    $j['cost'] = (float) $j['cost'];
    foreach (['next_due_km', 'odometer_km'] as $k) {
        $j[$k] = $j[$k] === null ? null : (int) $j[$k];
    }
    $j['vehicle_mileage'] = (int) $j['vehicle_mileage'];
    $j['days'] = (int) (new DateTimeImmutable($j['service_date']))->diff(new DateTimeImmutable($j['end_date']))->days + 1;
    $today = date('Y-m-d');
    // Still in the workshop past its planned end: worth flagging.
    $j['overrunning'] = $j['status'] === 'in_progress' && $j['end_date'] < $today;
    $j['actions'] = job_allowed_actions($j);
    return $j;
}

function job_allowed_actions(array $j): array
{
    $today = date('Y-m-d');
    return match ($j['status']) {
        'scheduled'   => array_values(array_filter(['start', 'edit', $j['service_date'] <= $today ? 'complete' : null, 'cancel'])),
        'in_progress' => ['complete', 'extend'],
        default       => [],
    };
}

/** @param array{status?: string, vehicle_id?: int} $filters */
function list_jobs(array $filters = []): array
{
    $where = ['1 = 1'];
    $params = [];
    if (!empty($filters['status']) && in_array($filters['status'], MAINTENANCE_STATUSES, true)) {
        $where[] = 'm.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['vehicle_id'])) {
        $where[] = 'm.vehicle_id = ?';
        $params[] = (int) $filters['vehicle_id'];
    }
    // Open jobs first (soonest first), then history (newest first).
    $stmt = Database::getConnection()->prepare(
        JOB_SELECT . ' WHERE ' . implode(' AND ', $where) . "
        ORDER BY (m.status IN ('scheduled', 'in_progress')) DESC,
                 CASE WHEN m.status IN ('scheduled', 'in_progress') THEN m.service_date END ASC,
                 m.end_date DESC, m.maintenance_id DESC"
    );
    $stmt->execute($params);

    return array_map('normalize_job', $stmt->fetchAll());
}

function get_job(int $job_id): ?array
{
    $stmt = Database::getConnection()->prepare(JOB_SELECT . ' WHERE m.maintenance_id = ?');
    $stmt->execute([$job_id]);
    $row = $stmt->fetch();

    return $row ? normalize_job($row) : null;
}

function lock_job(int $job_id): array
{
    $stmt = Database::getConnection()->prepare('SELECT * FROM maintenance WHERE maintenance_id = ? FOR UPDATE');
    $stmt->execute([$job_id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new MaintenanceError('That maintenance job no longer exists.');
    }
    return $row;
}

/** Bookings that would overlap a job on this car for these whole days. */
function job_booking_conflicts(int $vehicle_id, string $start_date, string $end_date): array
{
    $stmt = Database::getConnection()->prepare(
        "SELECT booking_id, booking_reference, booking_status, pickup_datetime, return_datetime
         FROM bookings
         WHERE vehicle_id = ? AND booking_status IN ('pending', 'confirmed', 'active')
           AND DATE(pickup_datetime) <= ? AND DATE(return_datetime) >= ?
         ORDER BY pickup_datetime"
    );
    $stmt->execute([$vehicle_id, $end_date, $start_date]);

    return $stmt->fetchAll();
}

function conflicts_message(array $conflicts): string
{
    $list = array_map(fn($b) => $b['booking_reference'] . ' (' . $b['booking_status'] . ', '
        . format_datetime($b['pickup_datetime']) . ' → ' . format_datetime($b['return_datetime']) . ')', $conflicts);
    return 'The car is booked during those days: ' . implode('; ', $list)
        . '. Move ' . (count($conflicts) === 1 ? 'that booking' : 'those bookings') . ' to another car first, or pick other dates.';
}

/**
 * For each car, when its next service is due (from the latest completed
 * job that set a next date or km), and how close that is.
 * state: overdue | due_soon | ok
 */
function service_due_list(bool $only_attention = false): array
{
    $rows = Database::getConnection()->query(
        "SELECT v.vehicle_id, v.brand, v.model, v.plate_number, v.mileage_km, v.status AS vehicle_status,
                m.maintenance_id, m.maintenance_type, m.end_date AS last_service, m.next_maintenance_date, m.next_due_km,
                (SELECT COUNT(*) FROM maintenance o WHERE o.vehicle_id = v.vehicle_id
                   AND o.status IN ('scheduled', 'in_progress')) AS open_jobs
         FROM vehicles v
         JOIN maintenance m ON m.maintenance_id = (
             SELECT m2.maintenance_id FROM maintenance m2
             WHERE m2.vehicle_id = v.vehicle_id AND m2.status = 'completed'
               AND (m2.next_maintenance_date IS NOT NULL OR m2.next_due_km IS NOT NULL)
             ORDER BY m2.end_date DESC, m2.maintenance_id DESC LIMIT 1)
         WHERE v.deleted_at IS NULL"
    )->fetchAll();

    $today = new DateTimeImmutable('today');
    $out = [];
    foreach ($rows as $r) {
        $days_left = $r['next_maintenance_date'] !== null
            ? (int) $today->diff(new DateTimeImmutable($r['next_maintenance_date']))->format('%r%a') : null;
        $km_left = $r['next_due_km'] !== null ? (int) $r['next_due_km'] - (int) $r['mileage_km'] : null;

        if (($days_left !== null && $days_left < 0) || ($km_left !== null && $km_left < 0)) {
            $state = 'overdue';
        } elseif (($days_left !== null && $days_left <= SERVICE_DUE_SOON_DAYS) || ($km_left !== null && $km_left <= SERVICE_DUE_SOON_KM)) {
            $state = 'due_soon';
        } else {
            $state = 'ok';
        }
        if ($only_attention && $state === 'ok') {
            continue;
        }
        $r['days_left'] = $days_left;
        $r['km_left'] = $km_left;
        $r['state'] = $state;
        $r['mileage_km'] = (int) $r['mileage_km'];
        $r['open_jobs'] = (int) $r['open_jobs'];
        $out[] = $r;
    }
    // Most urgent first.
    $rank = ['overdue' => 0, 'due_soon' => 1, 'ok' => 2];
    usort($out, fn($a, $b) => [$rank[$a['state']], min($a['days_left'] ?? PHP_INT_MAX, ($a['km_left'] ?? PHP_INT_MAX))]
                          <=> [$rank[$b['state']], min($b['days_left'] ?? PHP_INT_MAX, ($b['km_left'] ?? PHP_INT_MAX))]);
    return $out;
}

/** In plain words: "overdue by 1,000 km", "due in 5 days", "due Oct 10 or at 17,500 km". */
function service_due_text(array $r): string
{
    $parts = [];
    if ($r['days_left'] !== null) {
        $parts[] = $r['days_left'] < 0 ? abs($r['days_left']) . ' day' . (abs($r['days_left']) === 1 ? '' : 's') . ' overdue'
            : ($r['days_left'] === 0 ? 'due today' : 'due in ' . $r['days_left'] . ' day' . ($r['days_left'] === 1 ? '' : 's'));
    }
    if ($r['km_left'] !== null) {
        $parts[] = $r['km_left'] < 0 ? number_format(-$r['km_left']) . ' km over'
            : number_format($r['km_left']) . ' km to go';
    }
    return implode(', ', $parts);
}

function maintenance_totals(): array
{
    $pdo = Database::getConnection();
    $row = $pdo->query(
        "SELECT
            COUNT(DISTINCT CASE WHEN status = 'in_progress' THEN vehicle_id END) AS in_workshop,
            SUM(status = 'scheduled' AND service_date <= CURDATE() + INTERVAL 14 DAY) AS scheduled_soon,
            COALESCE(SUM(CASE WHEN status = 'completed' AND end_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN cost END), 0) AS spent_month,
            COALESCE(SUM(CASE WHEN status = 'completed' AND YEAR(end_date) = YEAR(CURDATE()) THEN cost END), 0) AS spent_year
         FROM maintenance"
    )->fetch();
    $due = service_due_list(true);

    return [
        'in_workshop'    => (int) $row['in_workshop'],
        'scheduled_soon' => (int) $row['scheduled_soon'],
        'spent_month'    => round((float) $row['spent_month'], 2),
        'spent_year'     => round((float) $row['spent_year'], 2),
        'overdue'        => count(array_filter($due, fn($r) => $r['state'] === 'overdue')),
        'due_soon'       => count(array_filter($due, fn($r) => $r['state'] === 'due_soon')),
    ];
}

// ---------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------

/** @throws MaintenanceError */
function validated_dates(?string $start_raw, ?string $end_raw, bool $must_be_future): array
{
    $start = parse_date($start_raw);
    $end = parse_date($end_raw);
    if (!$start || !$end) {
        throw new MaintenanceError('Enter a valid start and end date.');
    }
    if ($end < $start) {
        throw new MaintenanceError('The end date can\'t be before the start date.');
    }
    if ($start->diff($end)->days + 1 > MAX_MAINTENANCE_DAYS) {
        throw new MaintenanceError('A single job can be planned for at most ' . MAX_MAINTENANCE_DAYS . ' days. Split longer work into stages.');
    }
    $today = new DateTimeImmutable('today');
    if ($must_be_future && $start < $today) {
        throw new MaintenanceError('A scheduled job can\'t start in the past. Use "Log past service" for work already done.');
    }
    if (!$must_be_future && $end > $today) {
        throw new MaintenanceError('Logged work must already be finished (end date today or earlier).');
    }
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

/** @throws MaintenanceError */
function validated_job_text(array $in): array
{
    if (!in_array($in['maintenance_type'] ?? '', MAINTENANCE_TYPES, true)) {
        throw new MaintenanceError('Choose the type of work.');
    }
    $description = trim((string) ($in['description'] ?? ''));
    $shop = trim((string) ($in['performed_by'] ?? ''));
    if (mb_strlen($description) > 500) {
        throw new MaintenanceError('Description can be at most 500 characters.');
    }
    if (mb_strlen($shop) > 120) {
        throw new MaintenanceError('Shop / mechanic can be at most 120 characters.');
    }
    return [$in['maintenance_type'], $description === '' ? null : $description, $shop === '' ? null : $shop];
}

/**
 * Completion figures: cost, odometer, next service. Shared by complete_job()
 * and log_past_job().
 * @throws MaintenanceError
 */
function validated_completion(array $in, string $completed_on, int $vehicle_mileage, bool $odometer_may_be_lower): array
{
    $cost_raw = trim((string) ($in['cost'] ?? ''));
    if ($cost_raw === '' || !is_numeric($cost_raw) || (float) $cost_raw < 0) {
        throw new MaintenanceError('Enter the final cost (₱0 if there was none).');
    }
    $cost = round((float) $cost_raw, 2);
    if ($cost > MAX_MANUAL_CHARGE) {
        throw new MaintenanceError('Cost can be at most ' . money(MAX_MANUAL_CHARGE) . '.');
    }

    $odometer = null;
    $odo_raw = trim((string) ($in['odometer_km'] ?? ''));
    if ($odo_raw !== '') {
        if (!preg_match('/^\d{1,9}$/', $odo_raw)) {
            throw new MaintenanceError('Enter the odometer as a whole number of km.');
        }
        $odometer = (int) $odo_raw;
        // For work happening now, the odometer can't be below what the car
        // already shows; for old work being logged, it naturally can.
        if (!$odometer_may_be_lower && $odometer < $vehicle_mileage) {
            throw new MaintenanceError('Odometer can\'t be lower than the car\'s recorded mileage (' . number_format($vehicle_mileage) . ' km).');
        }
    }

    $next_date = null;
    if (trim((string) ($in['next_maintenance_date'] ?? '')) !== '') {
        $d = parse_date($in['next_maintenance_date']);
        if (!$d) {
            throw new MaintenanceError('Next service date must be a valid date.');
        }
        if ($d->format('Y-m-d') <= $completed_on) {
            throw new MaintenanceError('The next service date must be after this one.');
        }
        $next_date = $d->format('Y-m-d');
    }

    $next_km = null;
    $km_raw = trim((string) ($in['next_due_km'] ?? ''));
    if ($km_raw !== '') {
        if (!preg_match('/^\d{1,9}$/', $km_raw)) {
            throw new MaintenanceError('Enter the next service mileage as a whole number of km.');
        }
        $next_km = (int) $km_raw;
        $baseline = $odometer ?? $vehicle_mileage;
        if ($next_km <= $baseline) {
            throw new MaintenanceError('Next service mileage must be above ' . number_format($baseline) . ' km.');
        }
    }

    return [$cost, $odometer, $next_date, $next_km];
}

// ---------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------

/** Put the car back in service if nothing else is keeping it in the workshop. */
function release_vehicle_if_free(PDO $pdo, int $vehicle_id): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM maintenance WHERE vehicle_id = ? AND status = 'in_progress'");
    $stmt->execute([$vehicle_id]);
    if ((int) $stmt->fetchColumn() > 0) {
        return false;
    }
    $stmt = $pdo->prepare("UPDATE vehicles SET status = 'available' WHERE vehicle_id = ? AND status = 'maintenance'");
    $stmt->execute([$vehicle_id]);
    return $stmt->rowCount() > 0;
}

/**
 * Plan a job for the future (or today).
 * @return int maintenance_id
 * @throws MaintenanceError
 */
function schedule_job(int $vehicle_id, array $in, int $acting_user_id): int
{
    [$type, $description, $shop] = validated_job_text($in);
    [$start, $end] = validated_dates($in['service_date'] ?? null, $in['end_date'] ?? null, true);

    return in_transaction(function (PDO $pdo) use ($vehicle_id, $type, $description, $shop, $start, $end, $acting_user_id) {
        try {
            lock_vehicle($vehicle_id);
        } catch (BookingError $e) {
            throw new MaintenanceError($e->getMessage());
        }
        if ($conflicts = job_booking_conflicts($vehicle_id, $start, $end)) {
            throw new MaintenanceError(conflicts_message($conflicts));
        }
        $pdo->prepare(
            "INSERT INTO maintenance (vehicle_id, maintenance_type, service_date, end_date, description, performed_by, logged_by, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled')"
        )->execute([$vehicle_id, $type, $start, $end, $description, $shop, $acting_user_id]);

        return (int) $pdo->lastInsertId();
    });
}

/** Change a scheduled job's type, dates, description, or shop. @throws MaintenanceError */
function update_job(int $job_id, array $in, int $acting_user_id): void
{
    [$type, $description, $shop] = validated_job_text($in);
    [$start, $end] = validated_dates($in['service_date'] ?? null, $in['end_date'] ?? null, true);

    in_transaction(function (PDO $pdo) use ($job_id, $type, $description, $shop, $start, $end) {
        $job = lock_job($job_id);
        if ($job['status'] !== 'scheduled') {
            throw new MaintenanceError('Only a scheduled job can be edited (this one is ' . str_replace('_', ' ', $job['status']) . ').');
        }
        lock_vehicle((int) $job['vehicle_id']);
        if ($conflicts = job_booking_conflicts((int) $job['vehicle_id'], $start, $end)) {
            throw new MaintenanceError(conflicts_message($conflicts));
        }
        $pdo->prepare('UPDATE maintenance SET maintenance_type = ?, service_date = ?, end_date = ?, description = ?, performed_by = ?
                       WHERE maintenance_id = ?')
            ->execute([$type, $start, $end, $description, $shop, $job_id]);
    });
}

/**
 * The car goes into the workshop now. $end_date may move the planned end.
 * @throws MaintenanceError
 */
function start_job(int $job_id, int $acting_user_id, ?string $end_date = null): void
{
    in_transaction(function (PDO $pdo) use ($job_id, $end_date) {
        $job = lock_job($job_id);
        if ($job['status'] !== 'scheduled') {
            throw new MaintenanceError('Only a scheduled job can be started.');
        }
        $vehicle = lock_vehicle((int) $job['vehicle_id']);
        $today = date('Y-m-d');
        $end = $end_date !== null && $end_date !== '' ? parse_date($end_date)?->format('Y-m-d') : $job['end_date'];
        if (!$end) {
            throw new MaintenanceError('Enter a valid planned end date.');
        }
        if ($end < $today) {
            throw new MaintenanceError('The planned end has already passed. Set a new planned end date to start the job.');
        }

        $stmt = $pdo->prepare("SELECT booking_reference FROM bookings WHERE vehicle_id = ? AND booking_status = 'active' LIMIT 1");
        $stmt->execute([$vehicle['vehicle_id']]);
        if ($out = $stmt->fetchColumn()) {
            throw new MaintenanceError("The car is out on rental ($out). Receive it back first.");
        }
        if ($conflicts = job_booking_conflicts((int) $vehicle['vehicle_id'], $today, $end)) {
            throw new MaintenanceError(conflicts_message($conflicts));
        }

        $pdo->prepare("UPDATE maintenance SET status = 'in_progress', service_date = ?, end_date = ? WHERE maintenance_id = ?")
            ->execute([$today, $end, $job_id]);
        $pdo->prepare("UPDATE vehicles SET status = 'maintenance' WHERE vehicle_id = ? AND status <> 'unavailable'")->execute([$vehicle['vehicle_id']]);
    });
}

/**
 * Move the planned end of a job that's in progress (later or earlier),
 * checking the new days against bookings.
 * @throws MaintenanceError
 */
function extend_job(int $job_id, int $acting_user_id, string $end_date): void
{
    in_transaction(function (PDO $pdo) use ($job_id, $end_date) {
        $job = lock_job($job_id);
        if ($job['status'] !== 'in_progress') {
            throw new MaintenanceError('Only a job in progress can have its end date changed this way.');
        }
        $end = parse_date($end_date)?->format('Y-m-d');
        if (!$end) {
            throw new MaintenanceError('Enter a valid planned end date.');
        }
        $today = date('Y-m-d');
        if ($end < $today) {
            throw new MaintenanceError('The planned end can\'t be in the past. If the work is done, complete the job.');
        }
        if ((new DateTimeImmutable($job['service_date']))->diff(new DateTimeImmutable($end))->days + 1 > MAX_MAINTENANCE_DAYS) {
            throw new MaintenanceError('A single job can run at most ' . MAX_MAINTENANCE_DAYS . ' days.');
        }
        lock_vehicle((int) $job['vehicle_id']);
        if ($conflicts = job_booking_conflicts((int) $job['vehicle_id'], $today, $end)) {
            throw new MaintenanceError(conflicts_message($conflicts));
        }
        $pdo->prepare('UPDATE maintenance SET end_date = ? WHERE maintenance_id = ?')->execute([$end, $job_id]);
    });
}

/**
 * Close a job: final cost, shop, odometer, and when the next service is due.
 * @return bool whether the car went back into service
 * @throws MaintenanceError
 */
function complete_job(int $job_id, int $acting_user_id, array $in): bool
{
    return in_transaction(function (PDO $pdo) use ($job_id, $acting_user_id, $in) {
        $job = lock_job($job_id);
        $today = date('Y-m-d');
        if (!($job['status'] === 'in_progress' || ($job['status'] === 'scheduled' && $job['service_date'] <= $today))) {
            throw new MaintenanceError($job['status'] === 'scheduled'
                ? 'This job is planned for ' . date('M j', strtotime($job['service_date'])) . '. It can be completed from that day.'
                : 'This job is already ' . str_replace('_', ' ', $job['status']) . '.');
        }
        $vehicle = lock_vehicle((int) $job['vehicle_id']);

        // Blank means "today" — the usual case at the counter.
        $done_raw = trim((string) ($in['completed_on'] ?? ''));
        $done = $done_raw === '' ? new DateTimeImmutable($today) : parse_date($done_raw);
        if (!$done) {
            throw new MaintenanceError('Enter the date the work was finished.');
        }
        $done = $done->format('Y-m-d');
        if ($done > $today) {
            throw new MaintenanceError('The finish date can\'t be in the future.');
        }
        if ($done < $job['service_date']) {
            throw new MaintenanceError('The finish date can\'t be before the job started (' . date('M j, Y', strtotime($job['service_date'])) . ').');
        }
        [$cost, $odometer, $next_date, $next_km] = validated_completion($in, $done, (int) $vehicle['mileage_km'], false);

        $shop = trim((string) ($in['performed_by'] ?? ''));
        $notes = trim((string) ($in['notes'] ?? ''));
        $description = $job['description'];
        if ($notes !== '') {
            $description = mb_substr(trim(($description ? $description . ' ' : '') . 'Done: ' . $notes), 0, 500);
        }

        $pdo->prepare(
            "UPDATE maintenance SET status = 'completed', end_date = ?, cost = ?, performed_by = ?, odometer_km = ?,
                    next_maintenance_date = ?, next_due_km = ?, description = ?, completed_by = ?, completed_at = NOW()
             WHERE maintenance_id = ?"
        )->execute([$done, $cost, $shop === '' ? $job['performed_by'] : mb_substr($shop, 0, 120), $odometer,
                    $next_date, $next_km, $description, $acting_user_id, $job_id]);

        if ($odometer !== null) {
            $pdo->prepare('UPDATE vehicles SET mileage_km = GREATEST(mileage_km, ?) WHERE vehicle_id = ?')
                ->execute([$odometer, $vehicle['vehicle_id']]);
        }
        return release_vehicle_if_free($pdo, (int) $vehicle['vehicle_id']);
    });
}

/** @throws MaintenanceError */
function cancel_job(int $job_id, int $acting_user_id, string $reason): void
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new MaintenanceError('Say why the job is being cancelled — it stays on record.');
    }
    in_transaction(function (PDO $pdo) use ($job_id, $acting_user_id, $reason) {
        $job = lock_job($job_id);
        if ($job['status'] !== 'scheduled') {
            throw new MaintenanceError($job['status'] === 'in_progress'
                ? 'A job in progress can\'t be cancelled; complete it (₱0 if nothing was done) so the car comes back into service.'
                : 'This job is already ' . $job['status'] . '.');
        }
        $pdo->prepare("UPDATE maintenance SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ?
                       WHERE maintenance_id = ?")
            ->execute([$acting_user_id, mb_substr($reason, 0, 255), $job_id]);
    });
}

/**
 * Record work that already happened (e.g. before the system was used, or
 * done outside without scheduling). Doesn't touch bookings or the car's
 * status; raises the car's mileage if the odometer is higher.
 * @throws MaintenanceError
 */
function log_past_job(int $vehicle_id, array $in, int $acting_user_id): int
{
    [$type, $description, $shop] = validated_job_text($in);
    [$start, $end] = validated_dates($in['service_date'] ?? null, $in['end_date'] ?? null, false);

    return in_transaction(function (PDO $pdo) use ($vehicle_id, $in, $type, $description, $shop, $start, $end, $acting_user_id) {
        try {
            $vehicle = lock_vehicle($vehicle_id);
        } catch (BookingError $e) {
            throw new MaintenanceError($e->getMessage());
        }
        [$cost, $odometer, $next_date, $next_km] = validated_completion($in, $end, (int) $vehicle['mileage_km'], true);
        $pdo->prepare(
            "INSERT INTO maintenance (vehicle_id, maintenance_type, service_date, end_date, cost, description, next_maintenance_date,
                                      next_due_km, odometer_km, performed_by, logged_by, completed_by, completed_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'completed')"
        )->execute([$vehicle_id, $type, $start, $end, $cost, $description, $next_date, $next_km, $odometer, $shop,
                    $acting_user_id, $acting_user_id]);
        $id = (int) $pdo->lastInsertId();
        if ($odometer !== null) {
            $pdo->prepare('UPDATE vehicles SET mileage_km = GREATEST(mileage_km, ?) WHERE vehicle_id = ?')
                ->execute([$odometer, $vehicle_id]);
        }
        return $id;
    });
}
