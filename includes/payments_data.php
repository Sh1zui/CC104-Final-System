<?php
/**
 * Payments, deposit settlement, receipts, and invoices.
 *
 * Money model (also in database/README.md):
 *   - What a booking owes comes from booking_amount_due() in
 *     bookings_data.php; what it has paid is the sum of NET_CASH_SQL.
 *   - The security deposit is a liability while held. Settling a closed
 *     booking turns it into exactly two things: the part handed back
 *     (a refund, refund_of = 'deposit') and the part kept to cover what was
 *     owed (deposit_applied: no cash moves, but that's when it becomes
 *     revenue). Anything paid beyond the deposit and the final cost comes
 *     back as refund_of = 'payment'.
 *   - Receipts (per payment) and invoices (per closed booking) get
 *     sequential numbers that are never reused; mistakes are voided with
 *     a reason, never edited or deleted.
 */

require_once __DIR__ . '/rentals_data.php'; // and through it bookings_data.php

const PAYMENT_METHODS = ['cash', 'card', 'gcash', 'bank_transfer'];
// What staff can record as money coming in.
const MONEY_IN_TYPES  = ['deposit', 'rental_fee', 'late_fee', 'damage_fee', 'additional_service'];

const PAYMENT_METHOD_LABELS = [
    'cash'          => 'Cash',
    'card'          => 'Card',
    'gcash'         => 'GCash',
    'bank_transfer' => 'Bank transfer',
];

const PAYMENT_TYPE_LABELS = [
    'deposit'            => 'Security deposit',
    'rental_fee'         => 'Rental',
    'late_fee'           => 'Late fee',
    'damage_fee'         => 'Damage',
    'additional_service' => 'Other charges',
    'refund'             => 'Refund',
    'deposit_applied'    => 'Deposit kept',
];

function payment_type_label(array $p): string
{
    if ($p['payment_type'] === 'refund') {
        return $p['refund_of'] === 'deposit' ? 'Deposit refund' : 'Refund';
    }
    return PAYMENT_TYPE_LABELS[$p['payment_type']] ?? humanize($p['payment_type']);
}

/** Pairs PHP's status_badge_class() vocabulary with payment types. */
function payment_type_badge(array $p): string
{
    return match ($p['payment_type']) {
        'deposit', 'deposit_applied' => 'status-info',
        'refund'                     => 'status-neutral',
        'late_fee', 'damage_fee'     => 'status-warning',
        default                      => 'status-success',
    };
}

// ---------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------

const PAYMENT_SELECT = "
    SELECT p.*, r.full_name AS recorded_by_name, vb.full_name AS voided_by_name,
           b.booking_reference, b.customer_id, u.full_name AS customer_name, u.user_id AS customer_user_id,
           v.plate_number, v.brand, v.model
    FROM payments p
    JOIN users r      ON r.user_id = p.recorded_by
    LEFT JOIN users vb ON vb.user_id = p.voided_by
    JOIN bookings b   ON b.booking_id = p.booking_id
    JOIN customers c  ON c.customer_id = b.customer_id
    JOIN users u      ON u.user_id = c.user_id
    JOIN vehicles v   ON v.vehicle_id = b.vehicle_id";

function normalize_payment(array $p): array
{
    $p['amount'] = (float) $p['amount'];
    $p['type_label'] = payment_type_label($p);
    $p['type_badge'] = payment_type_badge($p);
    $p['method_label'] = $p['payment_method'] ? PAYMENT_METHOD_LABELS[$p['payment_method']] : '—';
    $p['is_money_out'] = $p['payment_type'] === 'refund';
    $p['is_cashless'] = $p['payment_type'] === 'deposit_applied';
    return $p;
}

function booking_payments(int $booking_id): array
{
    $stmt = Database::getConnection()->prepare(PAYMENT_SELECT . ' WHERE p.booking_id = ? ORDER BY p.paid_at, p.payment_id');
    $stmt->execute([$booking_id]);

    return array_map('normalize_payment', $stmt->fetchAll());
}

function get_payment(int $payment_id): ?array
{
    $stmt = Database::getConnection()->prepare(PAYMENT_SELECT . ' WHERE p.payment_id = ?');
    $stmt->execute([$payment_id]);
    $row = $stmt->fetch();

    return $row ? normalize_payment($row) : null;
}

/** @param array{method?: string, type?: string} $filters */
function list_payments(array $filters = [], int $limit = 500): array
{
    $where = ['1 = 1'];
    $params = [];
    if (!empty($filters['method']) && in_array($filters['method'], PAYMENT_METHODS, true)) {
        $where[] = 'p.payment_method = ?';
        $params[] = $filters['method'];
    }
    $stmt = Database::getConnection()->prepare(
        PAYMENT_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY p.paid_at DESC, p.payment_id DESC LIMIT ' . (int) $limit
    );
    $stmt->execute($params);

    return array_map('normalize_payment', $stmt->fetchAll());
}

/**
 * Where a booking's security deposit stands, from its payment rows.
 * held = received - refunded - kept.
 */
function deposit_position(int $booking_id): array
{
    $stmt = Database::getConnection()->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN payment_type = 'deposit' THEN amount END), 0) AS received,
            COALESCE(SUM(CASE WHEN payment_type = 'refund' AND refund_of = 'deposit' THEN amount END), 0) AS refunded,
            COALESCE(SUM(CASE WHEN payment_type = 'deposit_applied' THEN amount END), 0) AS kept
         FROM payments WHERE booking_id = ? AND status = 'completed'"
    );
    $stmt->execute([$booking_id]);
    $row = array_map(fn($v) => round((float) $v, 2), $stmt->fetch());
    $row['held'] = round($row['received'] - $row['refunded'] - $row['kept'], 2);

    return $row;
}

/** Deposits currently held across all bookings (a liability, not revenue). */
function deposits_held_total(): float
{
    return round((float) Database::getConnection()->query(
        "SELECT COALESCE(SUM(CASE payment_type WHEN 'deposit' THEN amount
                                               WHEN 'deposit_applied' THEN -amount
                                               WHEN 'refund' THEN IF(refund_of = 'deposit', -amount, 0) END), 0)
         FROM payments WHERE status = 'completed'"
    )->fetchColumn(), 2);
}

/** Cash in/out and revenue for the payments page tiles. */
function payment_totals(): array
{
    $row = Database::getConnection()->query(
        "SELECT
            COALESCE(SUM(CASE WHEN DATE(p.paid_at) = CURDATE() THEN " . NET_CASH_SQL . " END), 0) AS net_today,
            COALESCE(SUM(CASE WHEN p.paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN " . NET_CASH_SQL . " END), 0) AS net_month,
            COALESCE(SUM(CASE WHEN p.paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND p.payment_type = 'refund' THEN p.amount END), 0) AS refunded_month
         FROM payments p WHERE p.status = 'completed'"
    )->fetch();
    $totals = array_map(fn($v) => round((float) $v, 2), $row);
    $totals['deposits_held'] = deposits_held_total();

    return $totals;
}

/** Booking IDs with money still to collect or to give back, for the payments page. */
function bookings_needing_settlement(): array
{
    $rows = [];
    foreach (list_bookings() as $b) {
        $closed = in_array($b['booking_status'], ['completed', 'cancelled', 'no_show'], true);
        $held = $closed ? deposit_position((int) $b['booking_id'])['held'] : 0;
        if (($b['balance_due'] > 0 && $b['booking_status'] !== 'pending') || ($closed && ($b['refund_due'] > 0 || $held > 0))) {
            $b['deposit_held'] = $held;
            $rows[] = $b;
        }
    }
    return $rows;
}

// ---------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------

/** Insert a payment row and give it the next OR number; returns payment_id. */
function insert_payment_row(int $booking_id, float $amount, string $type, ?string $refund_of, ?string $method,
                            ?string $transaction_ref, ?string $notes, int $recorded_by): int
{
    $pdo = Database::getConnection();
    // Insert with a unique placeholder, then derive the number from the
    // auto-increment id: sequential, gap-free per insert, no race.
    $pdo->prepare(
        'INSERT INTO payments (booking_id, receipt_number, amount, payment_type, refund_of, payment_method, transaction_ref, notes, recorded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$booking_id, 'TMP-' . bin2hex(random_bytes(8)), round($amount, 2), $type, $refund_of, $method,
                $transaction_ref, $notes, $recorded_by]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE payments SET receipt_number = ? WHERE payment_id = ?')
        ->execute([sprintf('OR-%s-%06d', date('Y'), $id), $id]);

    return $id;
}

/** @throws BookingError */
function validate_method_and_ref(string $method, string $transaction_ref): ?string
{
    if (!in_array($method, PAYMENT_METHODS, true)) {
        throw new BookingError('Choose how the money was paid.');
    }
    $ref = trim($transaction_ref);
    if ($method !== 'cash' && $ref === '') {
        throw new BookingError('Enter the ' . PAYMENT_METHOD_LABELS[$method] . ' reference number so the payment can be traced.');
    }
    if (mb_strlen($ref) > 80) {
        throw new BookingError('Reference can be at most 80 characters.');
    }
    if ($ref !== '') {
        $stmt = Database::getConnection()->prepare('SELECT receipt_number FROM payments WHERE transaction_ref = ?');
        $stmt->execute([$ref]);
        if ($existing = $stmt->fetchColumn()) {
            throw new BookingError("That reference is already recorded on receipt $existing. Check it isn't the same payment twice.");
        }
    }
    return $ref === '' ? null : $ref;
}

/**
 * Record money received for a booking.
 * @return int payment_id
 * @throws BookingError
 */
function record_payment(int $booking_id, int $acting_user_id, float $amount, string $type, string $method,
                        string $transaction_ref = '', string $notes = ''): int
{
    if (!in_array($type, MONEY_IN_TYPES, true)) {
        throw new BookingError('Choose what the payment is for.');
    }
    $amount = round($amount, 2);
    if ($amount <= 0) {
        throw new BookingError('Amount must be more than zero.');
    }
    if ($amount > MAX_PAYMENT_AMOUNT) {
        throw new BookingError('A single payment can be at most ' . money(MAX_PAYMENT_AMOUNT) . '.');
    }
    if (mb_strlen($notes) > 255) {
        throw new BookingError('Notes can be at most 255 characters.');
    }
    $ref = validate_method_and_ref($method, $transaction_ref);

    return in_transaction(function () use ($booking_id, $acting_user_id, $amount, $type, $method, $ref, $notes) {
        lock_booking($booking_id);
        $b = get_booking($booking_id);

        if ($b['balance_due'] <= 0) {
            throw new BookingError('Nothing is owed on this booking.');
        }
        if ($amount > $b['balance_due']) {
            throw new BookingError('That\'s more than the balance due (' . money($b['balance_due']) . '). Record the exact amount; give change for cash.');
        }
        if ($type === 'deposit') {
            if (in_array($b['booking_status'], ['completed', 'cancelled', 'no_show'], true)) {
                throw new BookingError('This booking is closed, so there\'s no deposit to collect. Record it as rental or charges.');
            }
            $outstanding = round($b['deposit_amount'] - deposit_position($booking_id)['received'], 2);
            if ($outstanding <= 0) {
                throw new BookingError('The deposit is already fully paid.');
            }
            if ($amount > $outstanding) {
                throw new BookingError('Only ' . money($outstanding) . ' of the deposit is left to collect. Record the rest as rental.');
            }
        }

        try {
            $id = insert_payment_row($booking_id, $amount, $type, null, $method, $ref, trim($notes) === '' ? null : trim($notes), $acting_user_id);
        } catch (PDOException $e) {
            // The UNIQUE key on transaction_ref caught a duplicate recorded at the same moment.
            if ($e->getCode() === '23000') {
                throw new BookingError('That reference number was just recorded on another payment. Check it isn\'t the same payment twice.');
            }
            throw $e;
        }
        recalculate_payment_status($booking_id);
        sync_invoice_status($booking_id);

        return $id;
    });
}

/**
 * What settling a closed booking would do right now, without doing it.
 * @return array{deposit_held: float, keep: float, refund_deposit: float, refund_payment: float, refund_total: float, balance_after: float}
 */
function settlement_plan(array $b): array
{
    $held = deposit_position((int) $b['booking_id'])['held'];
    $refund_total = $b['refund_due'];
    $refund_deposit = round(min($held, $refund_total), 2);
    $refund_payment = round($refund_total - $refund_deposit, 2);
    $keep = round($held - $refund_deposit, 2);

    return [
        'deposit_held'   => $held,
        'keep'           => $keep,
        'refund_deposit' => $refund_deposit,
        'refund_payment' => $refund_payment,
        'refund_total'   => round($refund_total, 2),
        'balance_after'  => $b['balance_due'],
    ];
}

/**
 * Close the money on a completed / cancelled / no-show booking: hand back
 * what's owed and keep the rest of the deposit against what the customer
 * owed. Refund method/ref are only needed if something goes back.
 *
 * @return array the plan that was carried out
 * @throws BookingError
 */
function settle_booking(int $booking_id, int $acting_user_id, string $method = '', string $transaction_ref = ''): array
{
    return in_transaction(function () use ($booking_id, $acting_user_id, $method, $transaction_ref) {
        lock_booking($booking_id);
        $b = get_booking($booking_id);
        if (!in_array($b['booking_status'], ['completed', 'cancelled', 'no_show'], true)) {
            throw new BookingError('Settle a booking once it\'s closed (completed, cancelled, or no-show).');
        }
        $plan = settlement_plan($b);
        if ($plan['keep'] <= 0 && $plan['refund_total'] <= 0) {
            throw new BookingError($b['balance_due'] > 0
                ? 'No deposit is held and nothing is owed back. Collect the ' . money($b['balance_due']) . ' balance instead.'
                : 'This booking is already settled.');
        }

        $ref = null;
        if ($plan['refund_total'] > 0) {
            $ref = validate_method_and_ref($method, $transaction_ref);
        }

        if ($plan['keep'] > 0) {
            insert_payment_row($booking_id, $plan['keep'], 'deposit_applied', null, null, null,
                'Deposit kept against the amount owed', $acting_user_id);
        }
        $first_refund = null;
        if ($plan['refund_deposit'] > 0) {
            $first_refund = insert_payment_row($booking_id, $plan['refund_deposit'], 'refund', 'deposit', $method, $ref, null, $acting_user_id);
        }
        if ($plan['refund_payment'] > 0) {
            // One transfer can cover both parts; the reference is unique, so
            // it goes on the first row and the second points to it.
            $note = $first_refund ? 'Same transfer as ' . get_payment($first_refund)['receipt_number'] : 'Overpayment returned';
            insert_payment_row($booking_id, $plan['refund_payment'], 'refund', 'payment', $method,
                $first_refund ? null : $ref, $note, $acting_user_id);
        }

        recalculate_payment_status($booking_id);
        sync_invoice_status($booking_id);

        return $plan;
    });
}

/**
 * Admin-only correction: mark a payment as void (status 'failed'). Nothing
 * is deleted, and the receipt number stays used.
 * @throws BookingError
 */
function void_payment(int $payment_id, int $acting_user_id, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new BookingError('Say why the payment is being voided.');
    }
    return in_transaction(function (PDO $pdo) use ($payment_id, $acting_user_id, $reason) {
        $p = get_payment($payment_id);
        if (!$p) {
            throw new BookingError('That payment no longer exists.');
        }
        lock_booking((int) $p['booking_id']);
        if ($p['status'] !== 'completed') {
            throw new BookingError('That payment is already void.');
        }
        if (active_invoice((int) $p['booking_id'])) {
            throw new BookingError('This booking has an issued invoice. Void the invoice first, then the payment.');
        }
        // Money that came in can't be voided out from under a settlement
        // that already used it; undo the settlement rows first.
        if (!in_array($p['payment_type'], ['refund', 'deposit_applied'], true)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE booking_id = ? AND status = 'completed' AND payment_type IN ('refund', 'deposit_applied')");
            $stmt->execute([$p['booking_id']]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new BookingError('This booking has been settled. Void its refund / deposit-kept rows first.');
            }
        }
        $pdo->prepare("UPDATE payments SET status = 'failed', voided_by = ?, voided_at = NOW(),
                              notes = CONCAT_WS(' | ', notes, ?) WHERE payment_id = ?")
            ->execute([$acting_user_id, 'Voided: ' . mb_substr($reason, 0, 200), $payment_id]);
        recalculate_payment_status((int) $p['booking_id']);

        return $p;
    });
}

// ---------------------------------------------------------------------
// Invoices
// ---------------------------------------------------------------------

function active_invoice(int $booking_id): ?array
{
    $stmt = Database::getConnection()->prepare(
        "SELECT i.*, u.full_name AS issued_by_name FROM invoices i JOIN users u ON u.user_id = i.issued_by
         WHERE i.booking_id = ? AND i.status != 'void' ORDER BY i.invoice_id DESC LIMIT 1"
    );
    $stmt->execute([$booking_id]);
    $row = $stmt->fetch();
    if ($row) {
        foreach (['subtotal', 'discount', 'additional_charges', 'total'] as $k) {
            $row[$k] = (float) $row[$k];
        }
    }
    return $row ?: null;
}

function get_invoice(int $invoice_id): ?array
{
    $stmt = Database::getConnection()->prepare(
        'SELECT i.*, u.full_name AS issued_by_name, vb.full_name AS voided_by_name
         FROM invoices i JOIN users u ON u.user_id = i.issued_by LEFT JOIN users vb ON vb.user_id = i.voided_by
         WHERE i.invoice_id = ?'
    );
    $stmt->execute([$invoice_id]);
    $row = $stmt->fetch();
    if ($row) {
        foreach (['subtotal', 'discount', 'additional_charges', 'total'] as $k) {
            $row[$k] = (float) $row[$k];
        }
    }
    return $row ?: null;
}

/**
 * The invoice's three numbers for a booking as it stands, and the lines
 * behind them. total always equals booking_amount_due() — checked here.
 */
function invoice_figures(array $b): array
{
    $lines = [];
    $closed_early = in_array($b['booking_status'], ['cancelled', 'no_show'], true);

    if ($closed_early) {
        $subtotal = $b['cancellation_fee'];
        $discount = 0.0;
        $lines[] = [
            'label'  => ($b['booking_status'] === 'no_show' ? 'No-show fee' : 'Cancellation fee')
                        . ($subtotal > 0 ? ' at ' . money($b['daily_rate_snapshot']) . ' a day' : ' (waived or free cancellation)'),
            'amount' => $subtotal,
        ];
    } else {
        $subtotal = $b['base_amount'];
        $discount = $b['discount_amount'];
        $lines[] = ['label' => 'Rental: ' . $b['brand'] . ' ' . $b['model'] . ', ' . $b['rental_days'] . ' day' . ($b['rental_days'] === 1 ? '' : 's')
                               . ' × ' . money($b['daily_rate_snapshot']), 'amount' => $subtotal];
        if ($discount > 0) {
            $lines[] = ['label' => 'Discount', 'amount' => -$discount];
        }
    }

    $rental = get_rental((int) $b['booking_id']);
    if ($rental) {
        if ($rental['late_fee'] > 0) {
            $lines[] = ['label' => 'Late return (' . $rental['late_hours'] . ' hour' . ($rental['late_hours'] === 1 ? '' : 's') . ')', 'amount' => $rental['late_fee']];
        }
        if ($rental['fuel_fee'] > 0) {
            $lines[] = ['label' => 'Fuel (' . $rental['fuel_out_label'] . ' out, ' . $rental['fuel_in_label'] . ' back)', 'amount' => $rental['fuel_fee']];
        }
        if ($rental['damage_fee'] > 0) {
            $lines[] = ['label' => 'Damage' . ($rental['damage_notes'] ? ': ' . $rental['damage_notes'] : ''), 'amount' => $rental['damage_fee']];
        }
    }
    foreach (booking_penalties((int) $b['booking_id']) as $pe) {
        $lines[] = ['label' => humanize($pe['charge_type']) . ': ' . $pe['description'], 'amount' => $pe['amount']];
    }

    $additional = round($b['extra_charges'], 2);
    $total = round($subtotal - $discount + $additional, 2);
    if (in_array($b['booking_status'], ['completed', 'cancelled', 'no_show'], true) && abs($total - $b['amount_due']) >= 0.01) {
        // Two rules disagreeing about money is a bug; never print it.
        throw new RuntimeException("Invoice total $total doesn't match amount due {$b['amount_due']} for booking {$b['booking_id']}");
    }

    return ['subtotal' => round($subtotal, 2), 'discount' => round($discount, 2), 'additional_charges' => $additional,
            'total' => $total, 'lines' => $lines];
}

/** @throws BookingError */
function issue_invoice(int $booking_id, int $acting_user_id): int
{
    return in_transaction(function (PDO $pdo) use ($booking_id, $acting_user_id) {
        lock_booking($booking_id);
        $b = get_booking($booking_id);
        if (!in_array($b['booking_status'], ['completed', 'cancelled', 'no_show'], true)) {
            throw new BookingError('An invoice is issued once the booking is closed. Print a statement in the meantime.');
        }
        if ($existing = active_invoice($booking_id)) {
            throw new BookingError('This booking already has invoice ' . $existing['invoice_number'] . '. Void it first to issue a corrected one.');
        }
        $f = invoice_figures($b);
        $pdo->prepare(
            'INSERT INTO invoices (booking_id, invoice_number, issue_date, subtotal, discount, additional_charges, total, status, issued_by)
             VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?)'
        )->execute([$booking_id, 'TMP-' . bin2hex(random_bytes(8)), $f['subtotal'], $f['discount'], $f['additional_charges'], $f['total'],
                    $b['balance_due'] <= 0 ? 'paid' : 'unpaid', $acting_user_id]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE invoices SET invoice_number = ? WHERE invoice_id = ?')
            ->execute([sprintf('INV-%s-%06d', date('Y'), $id), $id]);

        return $id;
    });
}

/** @throws BookingError */
function void_invoice(int $invoice_id, int $acting_user_id, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new BookingError('Say why the invoice is being voided — it stays on record.');
    }
    $inv = get_invoice($invoice_id);
    if (!$inv) {
        throw new BookingError('That invoice no longer exists.');
    }
    if ($inv['status'] === 'void') {
        throw new BookingError('That invoice is already void.');
    }
    Database::getConnection()->prepare(
        "UPDATE invoices SET status = 'void', voided_by = ?, voided_at = NOW(), void_reason = ? WHERE invoice_id = ?"
    )->execute([$acting_user_id, mb_substr($reason, 0, 255), $invoice_id]);

    return $inv;
}

/** Keep an issued invoice's paid/unpaid in step with the payments. */
function sync_invoice_status(int $booking_id): void
{
    $inv = active_invoice($booking_id);
    if (!$inv) {
        return;
    }
    $b = get_booking($booking_id);
    Database::getConnection()->prepare('UPDATE invoices SET status = ? WHERE invoice_id = ?')
        ->execute([$b['balance_due'] <= 0 ? 'paid' : 'unpaid', $inv['invoice_id']]);
}

/** Who may see a receipt or invoice: admin/staff, or the customer it belongs to. */
function can_view_booking_paperwork(array $user, array $booking): bool
{
    if (in_array($user['role_name'], ['admin', 'staff'], true)) {
        return true;
    }
    return $user['role_name'] === 'customer' && (int) $booking['customer_user_id'] === (int) $user['user_id'];
}
