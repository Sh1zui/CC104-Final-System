<?php
/**
 * Read-only queries behind the dashboards. Pages call these and get plain
 * arrays back, so the markup never touches SQL and the same numbers can
 * feed the staff dashboard and the Phase 12 reports without copy-paste.
 */

require_once __DIR__ . '/../config/database.php';

// Revenue, as one SQL expression over `payments p` (completed rows only):
// - rental and charge payments count when received;
// - a security deposit is held, not earned, so receiving or refunding it
//   doesn't count; the part kept to cover what the customer owed
//   (deposit_applied) counts when the booking is settled;
// - refunding an overpayment or a cancelled booking's payment takes
//   revenue back out.
const EARNED_PAYMENT_TYPES = "'rental_fee','late_fee','damage_fee','additional_service'";
const REVENUE_SQL = "CASE WHEN p.payment_type IN ('rental_fee','late_fee','damage_fee','additional_service','deposit_applied') THEN p.amount
                          WHEN p.payment_type = 'refund' AND p.refund_of = 'payment' THEN -p.amount
                          ELSE 0 END";

function fleet_stats(): array
{
    $row = Database::getConnection()->query(
        "SELECT COUNT(*)                        AS total,
                SUM(status = 'available')       AS available,
                SUM(status = 'reserved')        AS reserved,
                SUM(status = 'rented')          AS rented,
                SUM(status = 'maintenance')     AS maintenance,
                SUM(status = 'unavailable')     AS unavailable
         FROM vehicles
         WHERE deleted_at IS NULL"
    )->fetch();

    // SUM() is NULL on an empty table; normalise everything to int.
    return array_map('intval', $row);
}

function active_booking_count(): int
{
    return (int) Database::getConnection()->query(
        "SELECT COUNT(*) FROM bookings WHERE booking_status IN ('confirmed', 'active')"
    )->fetchColumn();
}

function revenue_summary(): array
{
    $row = Database::getConnection()->query(
        "SELECT
            COALESCE(SUM(CASE WHEN DATE(p.paid_at) = CURDATE() THEN " . REVENUE_SQL . " END), 0) AS today,
            COALESCE(SUM(CASE WHEN YEAR(p.paid_at) = YEAR(CURDATE())
                               AND MONTH(p.paid_at) = MONTH(CURDATE()) THEN " . REVENUE_SQL . " END), 0) AS this_month,
            COALESCE(SUM(" . REVENUE_SQL . "), 0) AS all_time
         FROM payments p
         WHERE p.status = 'completed'"
    )->fetch();

    return array_map('floatval', $row);
}

/**
 * One entry per day for the last $days days (oldest first), zero-filled so
 * a quiet day still shows up on the chart instead of being skipped.
 *
 * @return array{labels: string[], values: float[]}
 */
function revenue_last_days(int $days = 7): array
{
    $days = max(1, $days);

    $stmt = Database::getConnection()->query(
        "SELECT DATE(p.paid_at) AS day, SUM(" . REVENUE_SQL . ") AS total
         FROM payments p
         WHERE p.status = 'completed'
           AND p.paid_at >= CURDATE() - INTERVAL " . ($days - 1) . " DAY
         GROUP BY DATE(p.paid_at)"
    );
    $by_day = array_column($stmt->fetchAll(), 'total', 'day');

    $labels = [];
    $values = [];
    $cursor = new DateTime('today');
    $cursor->modify('-' . ($days - 1) . ' days');

    for ($i = 0; $i < $days; $i++) {
        $labels[] = $cursor->format('M j');
        $values[] = (float) ($by_day[$cursor->format('Y-m-d')] ?? 0);
        $cursor->modify('+1 day');
    }

    return ['labels' => $labels, 'values' => $values];
}

function recent_payments(int $limit = 6): array
{
    $stmt = Database::getConnection()->prepare(
        "SELECT p.payment_id, p.booking_id, p.receipt_number, p.amount, p.payment_type, p.refund_of, p.payment_method, p.paid_at,
                u.full_name AS customer_name, v.brand, v.model
         FROM payments p
         JOIN bookings  b ON b.booking_id  = p.booking_id
         JOIN customers c ON c.customer_id = b.customer_id
         JOIN users     u ON u.user_id     = c.user_id
         JOIN vehicles  v ON v.vehicle_id  = b.vehicle_id
         -- real money movements only: not voided rows, not deposit re-labels
         WHERE p.status = 'completed' AND p.payment_type != 'deposit_applied'
         ORDER BY p.paid_at DESC, p.payment_id DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    // PDO returns DECIMAL columns as numeric strings; cast once here so
    // every caller can just treat $row['amount'] as a float.
    foreach ($rows as &$row) {
        $row['amount'] = (float) $row['amount'];
    }
    unset($row); // break the reference so it can't leak into code added later

    return $rows;
}

function upcoming_returns(int $limit = 6): array
{
    $stmt = Database::getConnection()->prepare(
        "SELECT b.booking_id, b.return_datetime,
                u.full_name AS customer_name,
                v.brand, v.model, v.plate_number,
                (b.return_datetime < NOW()) AS is_overdue
         FROM bookings b
         JOIN customers c ON c.customer_id = b.customer_id
         JOIN users     u ON u.user_id     = c.user_id
         JOIN vehicles  v ON v.vehicle_id  = b.vehicle_id
         WHERE b.booking_status = 'active'
         ORDER BY b.return_datetime ASC
         LIMIT :limit"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}
