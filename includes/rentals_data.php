<?php
/**
 * Check-out (releasing the car) and check-in (receiving it back), plus
 * extending a rental that's already out and adding charges afterwards.
 * Shared by api/bookings.php now and by the staff portal's release /
 * return pages in Phase 10.
 *
 *   confirmed ──check_out_booking()──> active ──check_in_booking()──> completed
 *                                        └─ extend_rental() / add_booking_charge()
 *
 * The fee calculations are plain functions (no database) so they can be
 * previewed live while staff fill in the return form, and tested exactly.
 */

require_once __DIR__ . '/bookings_data.php';

// Fuel gauge positions staff pick from, stored as a percentage.
const FUEL_LEVELS = [
    0   => 'Empty',
    13  => '1/8',
    25  => '1/4',
    38  => '3/8',
    50  => '1/2',
    63  => '5/8',
    75  => '3/4',
    88  => '7/8',
    100 => 'Full',
];

// Charges staff add by hand. Late, fuel, and damage are computed into
// `rentals` instead, so they're deliberately not offered here — that
// would let the same thing be charged twice.
const EXTRA_CHARGE_TYPES = ['cleaning', 'extra_mileage', 'traffic_violation', 'other'];

function fuel_label(?int $percent): string
{
    if ($percent === null) {
        return '—';
    }
    return FUEL_LEVELS[$percent] ?? $percent . '%';
}

// ---------------------------------------------------------------------
// Fee rules (pure functions)
// ---------------------------------------------------------------------

/**
 * Late fee for returning at $returned when the booking said $due.
 * Free within LATE_GRACE_MINUTES. Past that, every started hour (counted
 * from the booked return time) costs LATE_FEE_HOURLY_PCT of the daily
 * rate — but each 24 hours late never costs more than one day's rate.
 *
 * @return array{minutes_late: int, late_hours: int, hourly_rate: float, late_fee: float}
 */
function late_fee_for(float $daily_rate, DateTimeImmutable $due, DateTimeImmutable $returned): array
{
    $minutes_late = max(0, intdiv($returned->getTimestamp() - $due->getTimestamp(), 60));
    $hourly = round($daily_rate * LATE_FEE_HOURLY_PCT / 100, 2);

    if ($minutes_late <= LATE_GRACE_MINUTES) {
        return ['minutes_late' => $minutes_late, 'late_hours' => 0, 'hourly_rate' => $hourly, 'late_fee' => 0.0];
    }

    $hours = (int) ceil($minutes_late / 60);
    $full_days = intdiv($hours, 24);
    $remaining_hours = $hours % 24;
    $fee = $full_days * $daily_rate + min($remaining_hours * $hourly, $daily_rate);

    return ['minutes_late' => $minutes_late, 'late_hours' => $hours, 'hourly_rate' => $hourly, 'late_fee' => round($fee, 2)];
}

/** Charge for bringing the car back with less fuel than it left with. */
function fuel_fee_for(int $fuel_out, int $fuel_in): float
{
    return round(max(0, $fuel_out - $fuel_in) * FUEL_CHARGE_PER_PERCENT, 2);
}

/**
 * Validate the extra-charge lines from a form. Each line is
 * ['type' => ..., 'amount' => ..., 'description' => ...]; blank lines are
 * skipped.
 *
 * @throws BookingError
 */
function normalize_extra_charges(array $lines): array
{
    $clean = [];
    foreach ($lines as $line) {
        $amount_raw = trim((string) ($line['amount'] ?? ''));
        $description = trim((string) ($line['description'] ?? ''));
        if ($amount_raw === '' && $description === '') {
            continue;
        }
        $type = $line['type'] ?? '';
        if (!in_array($type, EXTRA_CHARGE_TYPES, true)) {
            throw new BookingError('Choose a valid type for each extra charge.');
        }
        if (!is_numeric($amount_raw) || (float) $amount_raw <= 0) {
            throw new BookingError('Each extra charge needs an amount above zero.');
        }
        if ((float) $amount_raw > MAX_MANUAL_CHARGE) {
            throw new BookingError('An extra charge can be at most ' . money(MAX_MANUAL_CHARGE) . '.');
        }
        if ($description === '') {
            throw new BookingError('Describe each extra charge — the customer will see it on their invoice.');
        }
        $clean[] = ['type' => $type, 'amount' => round((float) $amount_raw, 2), 'description' => mb_substr($description, 0, 255)];
    }
    if (count($clean) > 10) {
        throw new BookingError('At most 10 extra charges at once.');
    }
    return $clean;
}

/**
 * Everything charged at check-in, and where that leaves the customer.
 * Used both for the live preview and for the real check-in, so what
 * staff see is exactly what gets saved.
 *
 * @throws BookingError
 */
function return_charges(array $booking, array $rental, DateTimeImmutable $returned_at, int $fuel_in,
                        float $damage_fee, array $extras): array
{
    if ($damage_fee < 0 || $damage_fee > MAX_MANUAL_CHARGE) {
        throw new BookingError('Damage fee must be between ₱0 and ' . money(MAX_MANUAL_CHARGE) . '.');
    }
    $late = late_fee_for($booking['daily_rate_snapshot'], new DateTimeImmutable($booking['return_datetime']), $returned_at);
    $fuel_fee = fuel_fee_for((int) $rental['fuel_level_out'], $fuel_in);
    $extras_total = round(array_sum(array_column($extras, 'amount')), 2);

    // Charges already on the booking (e.g. a ticket added mid-rental).
    $earlier = round($booking['extra_charges'], 2);
    $charges = round($late['late_fee'] + $fuel_fee + round($damage_fee, 2) + $extras_total, 2);

    // What it costs once the deposit is handed back (see booking_amount_due()).
    $final_cost = round($booking['total_amount'] - $booking['deposit_amount'] + $earlier + $charges, 2);
    $difference = round($booking['amount_paid'] - $final_cost, 2);

    return [
        'minutes_late'   => $late['minutes_late'],
        'late_hours'     => $late['late_hours'],
        'hourly_rate'    => $late['hourly_rate'],
        'late_fee'       => $late['late_fee'],
        'fuel_fee'       => $fuel_fee,
        'damage_fee'     => round($damage_fee, 2),
        'extras_total'   => $extras_total,
        'earlier_charges'=> $earlier,
        'charges_total'  => $charges,
        'rental_cost'    => round($booking['total_amount'] - $booking['deposit_amount'], 2),
        'final_cost'     => $final_cost,
        'amount_paid'    => $booking['amount_paid'],
        'refund_due'     => max(0, $difference),
        'balance_due'    => max(0, -$difference),
    ];
}

// ---------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------

function get_rental(int $booking_id): ?array
{
    $stmt = Database::getConnection()->prepare(
        'SELECT r.*, rb.full_name AS released_by_name, rt.full_name AS returned_by_name
         FROM rentals r
         JOIN users rb ON rb.user_id = r.released_by
         LEFT JOIN users rt ON rt.user_id = r.returned_by
         WHERE r.booking_id = ?'
    );
    $stmt->execute([$booking_id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    foreach (['odometer_out', 'fuel_level_out', 'late_hours'] as $k) {
        $row[$k] = (int) $row[$k];
    }
    foreach (['odometer_in', 'fuel_level_in'] as $k) {
        $row[$k] = $row[$k] === null ? null : (int) $row[$k];
    }
    foreach (['late_fee', 'fuel_fee', 'damage_fee'] as $k) {
        $row[$k] = (float) $row[$k];
    }
    $row['fuel_out_label'] = fuel_label($row['fuel_level_out']);
    $row['fuel_in_label']  = fuel_label($row['fuel_level_in']);
    $row['km_driven'] = $row['odometer_in'] !== null ? $row['odometer_in'] - $row['odometer_out'] : null;

    return $row;
}

function booking_penalties(int $booking_id): array
{
    $stmt = Database::getConnection()->prepare(
        'SELECT pe.*, u.full_name AS created_by_name FROM penalties pe JOIN users u ON u.user_id = pe.created_by
         WHERE pe.booking_id = ? ORDER BY pe.created_at, pe.penalty_id'
    );
    $stmt->execute([$booking_id]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['amount'] = (float) $row['amount'];
    }
    unset($row);

    return $rows;
}

/** Another rental of this car that hasn't come back yet (blocks releasing it again). */
function vehicle_currently_out(int $vehicle_id, int $except_booking_id): ?array
{
    $stmt = Database::getConnection()->prepare(
        "SELECT booking_id, booking_reference, return_datetime FROM bookings
         WHERE vehicle_id = ? AND booking_status = 'active' AND booking_id != ? LIMIT 1"
    );
    $stmt->execute([$vehicle_id, $except_booking_id]);

    return $stmt->fetch() ?: null;
}

// ---------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------

function validate_fuel_level(int $percent): void
{
    if (!array_key_exists($percent, FUEL_LEVELS)) {
        throw new BookingError('Choose the fuel level from the list.');
    }
}

/**
 * Release the car to the customer.
 * @throws BookingError
 */
function check_out_booking(int $booking_id, int $acting_user_id, int $odometer_out, int $fuel_out,
                           string $condition_notes, bool $license_checked): void
{
    if (!$license_checked) {
        throw new BookingError('Check the customer\'s physical driver\'s license before releasing the car.');
    }
    validate_fuel_level($fuel_out);
    if (mb_strlen($condition_notes) > 500) {
        throw new BookingError('Condition notes can be at most 500 characters.');
    }

    in_transaction(function (PDO $pdo) use ($booking_id, $acting_user_id, $odometer_out, $fuel_out, $condition_notes) {
        $booking = lock_booking($booking_id);
        if ($booking['booking_status'] !== 'confirmed') {
            throw new BookingError('Only a confirmed booking can be released (this one is ' . $booking['booking_status'] . ').');
        }
        $now = new DateTimeImmutable();
        $pickup = new DateTimeImmutable($booking['pickup_datetime']);
        if ($pickup->modify('-' . EARLY_CHECKOUT_MINUTES . ' minutes') > $now) {
            throw new BookingError('Too early: the car can be released from ' . format_datetime($pickup->modify('-' . EARLY_CHECKOUT_MINUTES . ' minutes')->format('Y-m-d H:i:s')) . '.');
        }
        if (new DateTimeImmutable($booking['return_datetime']) <= $now) {
            throw new BookingError('This booking\'s return time has already passed. Mark it as a no-show, or reschedule it.');
        }

        $vehicle = lock_vehicle((int) $booking['vehicle_id']);
        if ($other = vehicle_currently_out((int) $vehicle['vehicle_id'], $booking_id)) {
            throw new BookingError('This car hasn\'t come back from ' . $other['booking_reference'] . ' yet. Receive that return first.');
        }
        if (in_array($vehicle['status'], ['maintenance', 'unavailable'], true)) {
            throw new BookingError('The car is marked "' . $vehicle['status'] . '". Reschedule the booking onto another car.');
        }
        if ($odometer_out < (int) $vehicle['mileage_km']) {
            throw new BookingError('Odometer can\'t be lower than the car\'s last recorded mileage (' . number_format((int) $vehicle['mileage_km']) . ' km).');
        }

        $customer = get_customer((int) $booking['customer_id']);
        if ($blockers = customer_booking_blockers($customer, new DateTimeImmutable($booking['return_datetime']))) {
            throw new BookingError('Can\'t release the car: ' . implode(' ', $blockers));
        }

        $pdo->prepare(
            'INSERT INTO rentals (booking_id, released_by, released_at, odometer_out, fuel_level_out, condition_notes_out)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $booking_id, $acting_user_id,
            date('Y-m-d H:i:00'), // minute precision, like every time staff enter by hand
            $odometer_out, $fuel_out, trim($condition_notes) === '' ? null : trim($condition_notes),
        ]);
        $pdo->prepare("UPDATE bookings SET booking_status = 'active' WHERE booking_id = ?")->execute([$booking_id]);
        $pdo->prepare("UPDATE vehicles SET status = 'rented', mileage_km = ? WHERE vehicle_id = ?")
            ->execute([$odometer_out, $vehicle['vehicle_id']]);
    });
}

/**
 * Receive the car back and close the rental.
 *
 * @param array $extras raw extra-charge lines from the form
 * @return array the charges breakdown (same shape as return_charges())
 * @throws BookingError
 */
function check_in_booking(int $booking_id, int $acting_user_id, DateTimeImmutable $returned_at, int $odometer_in, int $fuel_in,
                          float $damage_fee, string $damage_notes, array $extras, bool $needs_maintenance, string $maintenance_note): array
{
    validate_fuel_level($fuel_in);
    $extras = normalize_extra_charges($extras);
    $damage_notes = trim($damage_notes);
    if ($damage_fee > 0 && $damage_notes === '') {
        throw new BookingError('Describe the damage you\'re charging for.');
    }
    if (mb_strlen($damage_notes) > 500 || mb_strlen($maintenance_note) > 500) {
        throw new BookingError('Notes can be at most 500 characters.');
    }

    return in_transaction(function (PDO $pdo) use ($booking_id, $acting_user_id, $returned_at, $odometer_in, $fuel_in,
                                                  $damage_fee, $damage_notes, $extras, $needs_maintenance, $maintenance_note) {
        $locked = lock_booking($booking_id);
        if ($locked['booking_status'] !== 'active') {
            throw new BookingError('Only a car that\'s out on rental can be received back (this booking is ' . $locked['booking_status'] . ').');
        }
        $rental = get_rental($booking_id);
        if (!$rental) {
            throw new BookingError('There\'s no release record for this booking.');
        }
        // The form gives minutes only; compare at that precision so a car
        // received in the same minute it was released isn't "before" it.
        $released_minute = new DateTimeImmutable(substr($rental['released_at'], 0, 16));
        if ($returned_at < $released_minute) {
            throw new BookingError('Return time can\'t be before the car was released (' . format_datetime($rental['released_at']) . ').');
        }
        if ($returned_at > new DateTimeImmutable('+1 minute')) {
            throw new BookingError('Return time can\'t be in the future.');
        }
        if ($odometer_in < $rental['odometer_out']) {
            throw new BookingError('Odometer can\'t be lower than at release (' . number_format($rental['odometer_out']) . ' km).');
        }

        $vehicle = lock_vehicle((int) $locked['vehicle_id']);
        $booking = get_booking($booking_id);
        $charges = return_charges($booking, $rental, $returned_at, $fuel_in, $damage_fee, $extras);

        $pdo->prepare(
            'UPDATE rentals SET returned_by = ?, returned_at = ?, odometer_in = ?, fuel_level_in = ?,
                    late_hours = ?, late_fee = ?, fuel_fee = ?, damage_fee = ?, damage_notes = ?
             WHERE booking_id = ?'
        )->execute([
            $acting_user_id, $returned_at->format('Y-m-d H:i:s'), $odometer_in, $fuel_in,
            $charges['late_hours'], $charges['late_fee'], $charges['fuel_fee'], $charges['damage_fee'],
            $damage_notes === '' ? null : $damage_notes, $booking_id,
        ]);

        $insert = $pdo->prepare('INSERT INTO penalties (booking_id, charge_type, amount, description, created_by) VALUES (?, ?, ?, ?, ?)');
        foreach ($extras as $x) {
            $insert->execute([$booking_id, $x['type'], $x['amount'], $x['description'], $acting_user_id]);
        }

        $pdo->prepare("UPDATE bookings SET booking_status = 'completed' WHERE booking_id = ?")->execute([$booking_id]);

        // The car is back: record its mileage, and either free it or take
        // it out of service with a maintenance job for the workshop.
        $pdo->prepare('UPDATE vehicles SET mileage_km = GREATEST(mileage_km, ?), status = ? WHERE vehicle_id = ?')
            ->execute([$odometer_in, $needs_maintenance ? 'maintenance' : 'available', $vehicle['vehicle_id']]);
        if ($needs_maintenance) {
            $note = trim($maintenance_note) !== '' ? trim($maintenance_note) : ($damage_notes !== '' ? $damage_notes : 'Flagged at check-in');
            // The car goes straight to the workshop, so the job starts now
            // (in progress) — matching vehicles.status = 'maintenance'.
            $pdo->prepare(
                "INSERT INTO maintenance (vehicle_id, maintenance_type, service_date, end_date, description, logged_by, status)
                 VALUES (?, 'general_inspection', CURDATE(), CURDATE() + INTERVAL ? DAY, ?, ?, 'in_progress')"
            )->execute([$vehicle['vehicle_id'], CHECKIN_REPAIR_DAYS, mb_substr('After ' . $locked['booking_reference'] . ': ' . $note, 0, 500), $acting_user_id]);
        }

        recalculate_payment_status($booking_id);

        return $charges;
    });
}

/**
 * Of a booking's stored discount, the part staff added on top of the
 * automatic long-rental one (the booking only stores the sum).
 */
function manual_discount_part(array $booking): float
{
    $automatic = (int) $booking['rental_days'] >= LONG_RENTAL_MIN_DAYS
        ? round((float) $booking['base_amount'] * LONG_RENTAL_DISCOUNT_PCT / 100, 2) : 0.0;
    return round(max(0, (float) $booking['discount_amount'] - $automatic), 2);
}

/** The new price if this rental ran until $new_return: same rate, same extra discount. */
function extension_quote(array $booking, DateTimeImmutable $new_return): array
{
    return quote_booking((float) $booking['daily_rate_snapshot'], new DateTimeImmutable($booking['pickup_datetime']),
                         $new_return, manual_discount_part($booking));
}

/**
 * Push back the return time of a rental that's out (or about to go out
 * on the same terms — confirmed bookings use reschedule instead).
 *
 * @return array{old_total: float, new_total: float}
 * @throws BookingError
 */
function extend_rental(int $booking_id, DateTimeImmutable $new_return, int $acting_user_id): array
{
    return in_transaction(function (PDO $pdo) use ($booking_id, $new_return) {
        $booking = lock_booking($booking_id);
        if ($booking['booking_status'] !== 'active') {
            throw new BookingError('Only a rental that\'s out can be extended. Use reschedule for a booking that hasn\'t started.');
        }
        $pickup = new DateTimeImmutable($booking['pickup_datetime']);
        $old_return = new DateTimeImmutable($booking['return_datetime']);
        if ($new_return <= $old_return) {
            throw new BookingError('The new return time must be later than the current one (' . format_datetime($booking['return_datetime']) . ').');
        }
        if ($new_return <= new DateTimeImmutable()) {
            throw new BookingError('The new return time must be in the future.');
        }
        if (rental_days($pickup->format('Y-m-d H:i:s'), $new_return->format('Y-m-d H:i:s')) > MAX_RENTAL_DAYS) {
            throw new BookingError('A single booking can be at most ' . MAX_RENTAL_DAYS . ' days.');
        }

        lock_vehicle((int) $booking['vehicle_id']);
        $customer = get_customer((int) $booking['customer_id']);
        if ($blockers = customer_booking_blockers($customer, $new_return)) {
            throw new BookingError('Can\'t extend: ' . implode(' ', $blockers));
        }
        // Only the added time needs to be free; the rental already holds the rest.
        $conflicts = conflicting_bookings((int) $booking['vehicle_id'], $old_return, $new_return, $booking_id);
        if ($conflicts) {
            $next = $conflicts[0];
            throw new BookingError('The car is booked next from ' . format_datetime($next['pickup_datetime']) . ' (' . $next['booking_reference']
                . '). With the ' . TURNAROUND_HOURS . '-hour turnaround, it has to be back before then.');
        }

        $quote = extension_quote($booking, $new_return);

        $pdo->prepare(
            'UPDATE bookings SET return_datetime = ?, rental_days = ?, base_amount = ?, discount_amount = ?, total_amount = ?
             WHERE booking_id = ?'
        )->execute([$new_return->format('Y-m-d H:i:s'), $quote['rental_days'], $quote['base_amount'], $quote['discount_amount'],
                    $quote['total_amount'], $booking_id]);
        recalculate_payment_status($booking_id);

        return ['old_total' => (float) $booking['total_amount'], 'new_total' => $quote['total_amount']];
    });
}

/**
 * A charge that turns up during or after the rental (a traffic ticket in
 * the mail, a cleaning fee noticed later).
 * @throws BookingError
 */
function add_booking_charge(int $booking_id, int $acting_user_id, string $type, string $amount, string $description): void
{
    $line = normalize_extra_charges([['type' => $type, 'amount' => $amount, 'description' => $description]]);
    if (!$line) {
        throw new BookingError('Enter an amount and a description.');
    }
    in_transaction(function (PDO $pdo) use ($booking_id, $acting_user_id, $line) {
        $booking = lock_booking($booking_id);
        if (!in_array($booking['booking_status'], ['active', 'completed'], true)) {
            throw new BookingError('Charges can only be added to a rental that has started.');
        }
        // An issued invoice is a frozen record; changing what's owed under
        // it would make the printed invoice wrong.
        $stmt = $pdo->prepare("SELECT invoice_number FROM invoices WHERE booking_id = ? AND status != 'void' LIMIT 1");
        $stmt->execute([$booking_id]);
        if ($invoice_number = $stmt->fetchColumn()) {
            throw new BookingError("Invoice $invoice_number is already issued. Void it, add the charge, then issue a new invoice.");
        }
        $pdo->prepare('INSERT INTO penalties (booking_id, charge_type, amount, description, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$booking_id, $line[0]['type'], $line[0]['amount'], $line[0]['description'], $acting_user_id]);
        recalculate_payment_status($booking_id);
    });
}
