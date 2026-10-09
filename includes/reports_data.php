<?php
/**
 * Reports (Phase 12). Read-only queries over a date range. Every money
 * figure uses the same definitions as the dashboard and ledger:
 *   revenue  = REVENUE_SQL (earned charges + deposits kept − refunds of payments)
 *   cash     = NET_CASH_SQL (money in − money out)
 * so a total here always matches the same period on the payments page.
 *
 * A range is two dates, inclusive: [from 00:00, to + 1 day 00:00).
 */

require_once __DIR__ . '/dashboard_data.php';   // REVENUE_SQL
require_once __DIR__ . '/bookings_data.php';
require_once __DIR__ . '/maintenance_data.php';
require_once __DIR__ . '/payments_data.php';   // deposits_held_total()

const REPORT_PRESETS = [
    'this_month' => 'This month',
    'last_month' => 'Last month',
    'last_30'    => 'Last 30 days',
    'last_90'    => 'Last 90 days',
    'this_year'  => 'This year',
    'last_year'  => 'Last year',
    'custom'     => 'Custom range',
];
const REPORT_MAX_DAYS = 731;

/**
 * The range asked for in the query string, or this month.
 * @return array{from: DateTimeImmutable, to: DateTimeImmutable, preset: string, label: string, error: ?string}
 */
function report_range(array $q, ?DateTimeImmutable $today = null): array
{
    $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
    $preset = array_key_exists($q['range'] ?? '', REPORT_PRESETS) ? $q['range'] : 'this_month';
    $error = null;

    [$from, $to] = match ($preset) {
        'last_month' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
        'last_30'    => [$today->modify('-29 days'), $today],
        'last_90'    => [$today->modify('-89 days'), $today],
        'this_year'  => [$today->setDate((int) $today->format('Y'), 1, 1), $today->setDate((int) $today->format('Y'), 12, 31)],
        'last_year'  => [$today->setDate((int) $today->format('Y') - 1, 1, 1), $today->setDate((int) $today->format('Y') - 1, 12, 31)],
        'custom'     => [parse_date($q['from'] ?? null), parse_date($q['to'] ?? null)],
        default      => [$today->modify('first day of this month'), $today->modify('last day of this month')],
    };

    if ($preset === 'custom') {
        if (!$from || !$to) {
            $error = 'Pick both a start and an end date. Showing this month instead.';
        } elseif ($to < $from) {
            $error = 'The end date is before the start date. Showing this month instead.';
        } elseif ($from->diff($to)->days + 1 > REPORT_MAX_DAYS) {
            $error = 'A report can cover at most two years. Showing this month instead.';
        }
        if ($error) {
            $preset = 'this_month';
            [$from, $to] = [$today->modify('first day of this month'), $today->modify('last day of this month')];
        }
    }

    return [
        'from'   => $from,
        'to'     => $to,
        'preset' => $preset,
        'label'  => format_date_range($from->format('Y-m-d'), $to->format('Y-m-d')),
        'error'  => $error,
    ];
}

/** SQL bounds for a range: [from 00:00:00, day after `to` 00:00:00). */
function range_bounds(array $r): array
{
    return [$r['from']->format('Y-m-d 00:00:00'), $r['to']->modify('+1 day')->format('Y-m-d 00:00:00')];
}

function range_days(array $r): int
{
    return $r['from']->diff($r['to'])->days + 1;
}

/**
 * Hours in the range that have already happened (a car can't have been
 * used next week), for utilization.
 */
function elapsed_range_hours(array $r, ?DateTimeImmutable $now = null): float
{
    $now = $now ?? new DateTimeImmutable();
    [$start, $end] = range_bounds($r);
    $start_ts = strtotime($start);
    $end_ts = min(strtotime($end), $now->getTimestamp());
    return max(0, ($end_ts - $start_ts) / 3600);
}

// ---------------------------------------------------------------------
// Money
// ---------------------------------------------------------------------

/** Day buckets for ranges up to ~2 months, month buckets beyond. */
function report_granularity(array $r): string
{
    return range_days($r) <= 62 ? 'day' : 'month';
}

/**
 * Revenue and cash over time, plus the period totals.
 * @return array{granularity: string, labels: string[], keys: string[], revenue: float[], cash: float[], totals: array}
 */
function revenue_report(array $r): array
{
    [$start, $end] = range_bounds($r);
    $gran = report_granularity($r);
    $bucket = $gran === 'day' ? "DATE_FORMAT(p.paid_at, '%Y-%m-%d')" : "DATE_FORMAT(p.paid_at, '%Y-%m')";

    $stmt = Database::getConnection()->prepare(
        "SELECT $bucket AS k, SUM(" . REVENUE_SQL . ") AS revenue, SUM(" . NET_CASH_SQL . ") AS cash
         FROM payments p
         WHERE p.status = 'completed' AND p.paid_at >= ? AND p.paid_at < ?
         GROUP BY k"
    );
    $stmt->execute([$start, $end]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[$row['k']] = $row;
    }

    // Every bucket, including empty ones, so gaps show as zero.
    $keys = $labels = $revenue = $cash = [];
    $cursor = $gran === 'day' ? $r['from'] : $r['from']->modify('first day of this month');
    while ($cursor <= $r['to']) {
        $k = $cursor->format($gran === 'day' ? 'Y-m-d' : 'Y-m');
        $keys[] = $k;
        $labels[] = $cursor->format($gran === 'day' ? 'M j' : 'M Y');
        $revenue[] = round((float) ($rows[$k]['revenue'] ?? 0), 2);
        $cash[] = round((float) ($rows[$k]['cash'] ?? 0), 2);
        $cursor = $cursor->modify($gran === 'day' ? '+1 day' : '+1 month');
    }

    $stmt = Database::getConnection()->prepare(
        "SELECT
            COALESCE(SUM(" . REVENUE_SQL . "), 0) AS revenue,
            COALESCE(SUM(" . NET_CASH_SQL . "), 0) AS net_cash,
            COALESCE(SUM(CASE WHEN p.payment_type NOT IN ('refund', 'deposit_applied') THEN p.amount END), 0) AS money_in,
            COALESCE(SUM(CASE WHEN p.payment_type = 'refund' THEN p.amount END), 0) AS money_out,
            COALESCE(SUM(CASE WHEN p.payment_type = 'deposit' THEN p.amount END), 0) AS deposits_taken,
            COALESCE(SUM(CASE WHEN p.payment_type = 'refund' AND p.refund_of = 'deposit' THEN p.amount END), 0) AS deposits_returned,
            COALESCE(SUM(CASE WHEN p.payment_type = 'deposit_applied' THEN p.amount END), 0) AS deposits_kept,
            COALESCE(SUM(CASE WHEN p.payment_type = 'rental_fee' THEN p.amount END), 0) AS rental_fees,
            COALESCE(SUM(CASE WHEN p.payment_type IN ('late_fee', 'damage_fee', 'additional_service') THEN p.amount END), 0) AS other_charges,
            COUNT(*) AS payments
         FROM payments p
         WHERE p.status = 'completed' AND p.paid_at >= ? AND p.paid_at < ?"
    );
    $stmt->execute([$start, $end]);
    $totals = array_map(fn ($v) => round((float) $v, 2), $stmt->fetch());
    $totals['payments'] = (int) $totals['payments'];

    return ['granularity' => $gran, 'labels' => $labels, 'keys' => $keys, 'revenue' => $revenue, 'cash' => $cash, 'totals' => $totals];
}

// ---------------------------------------------------------------------
// Vehicles
// ---------------------------------------------------------------------

/**
 * Per car: what it earned, how much of the elapsed range it was out on
 * rental, and what it cost in maintenance. Archived cars appear only if
 * they had activity in the range.
 */
function vehicle_report(array $r, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable();
    [$start, $end] = range_bounds($r);
    $end_eff = min($end, $now->format('Y-m-d H:i:s'));
    $db = Database::getConnection();

    $stmt = $db->prepare(
        "SELECT v.vehicle_id, v.brand, v.model, v.plate_number, v.daily_rate, v.status, v.deleted_at,
            COALESCE((SELECT SUM(" . REVENUE_SQL . ") FROM payments p JOIN bookings b ON b.booking_id = p.booking_id
                      WHERE b.vehicle_id = v.vehicle_id AND p.status = 'completed' AND p.paid_at >= ? AND p.paid_at < ?), 0) AS revenue,
            (SELECT COUNT(*) FROM rentals rt JOIN bookings b ON b.booking_id = rt.booking_id
              WHERE b.vehicle_id = v.vehicle_id AND rt.released_at >= ? AND rt.released_at < ?) AS rentals,
            COALESCE((SELECT SUM(GREATEST(0, TIMESTAMPDIFF(MINUTE,
                          GREATEST(rt.released_at, ?), LEAST(COALESCE(rt.returned_at, NOW()), ?))))
                      FROM rentals rt JOIN bookings b ON b.booking_id = rt.booking_id
                      WHERE b.vehicle_id = v.vehicle_id AND rt.released_at < ? AND COALESCE(rt.returned_at, NOW()) > ?), 0) AS minutes_out,
            COALESCE((SELECT SUM(m.cost) FROM maintenance m
                      WHERE m.vehicle_id = v.vehicle_id AND m.status = 'completed' AND m.end_date >= ? AND m.end_date < ?), 0) AS maintenance_cost,
            (SELECT COUNT(*) FROM maintenance m
              WHERE m.vehicle_id = v.vehicle_id AND m.status = 'completed' AND m.end_date >= ? AND m.end_date < ?) AS jobs
         FROM vehicles v
         ORDER BY v.brand, v.model, v.vehicle_id"
    );
    $d_start = substr($start, 0, 10);
    $d_end = substr($end, 0, 10);
    $stmt->execute([$start, $end, $start, $end, $start, $end_eff, $end_eff, $start, $d_start, $d_end, $d_start, $d_end]);

    $hours = elapsed_range_hours($r, $now);
    $rows = [];
    foreach ($stmt->fetchAll() as $v) {
        $v['revenue'] = round((float) $v['revenue'], 2);
        $v['maintenance_cost'] = round((float) $v['maintenance_cost'], 2);
        $v['rentals'] = (int) $v['rentals'];
        $v['jobs'] = (int) $v['jobs'];
        $v['days_out'] = round((int) $v['minutes_out'] / 1440, 1);
        $v['utilization'] = $hours > 0 ? round(min(100, (int) $v['minutes_out'] / 60 / $hours * 100), 1) : 0.0;
        $v['net'] = round($v['revenue'] - $v['maintenance_cost'], 2);
        unset($v['minutes_out']);
        $active = $v['revenue'] != 0 || $v['rentals'] || $v['days_out'] > 0 || $v['jobs'];
        if ($v['deleted_at'] !== null && !$active) {
            continue;
        }
        $rows[] = $v;
    }
    usort($rows, fn ($a, $b) => $b['revenue'] <=> $a['revenue'] ?: strcmp($a['brand'] . $a['model'], $b['brand'] . $b['model']));
    return $rows;
}

/** Fleet-wide utilization: total time out ÷ (cars in service × elapsed hours). */
function fleet_utilization(array $vehicles, array $r, ?DateTimeImmutable $now = null): float
{
    $in_fleet = array_filter($vehicles, fn ($v) => $v['deleted_at'] === null);
    $hours = elapsed_range_hours($r, $now);
    if (!$in_fleet || $hours <= 0) {
        return 0.0;
    }
    $days_out = array_sum(array_column($in_fleet, 'days_out'));
    return round(min(100, $days_out * 24 / ($hours * count($in_fleet)) * 100), 1);
}

// ---------------------------------------------------------------------
// Bookings
// ---------------------------------------------------------------------

/**
 * Bookings *made* in the range, by status and by source (desk vs online;
 * created_by NULL means the customer booked it themself).
 */
function booking_report(array $r): array
{
    [$start, $end] = range_bounds($r);
    $db = Database::getConnection();

    $stmt = $db->prepare(
        "SELECT booking_status, (created_by IS NULL) AS online, COUNT(*) AS n,
                SUM(rental_days) AS days, SUM(total_amount - deposit_amount) AS value
         FROM bookings WHERE created_at >= ? AND created_at < ?
         GROUP BY booking_status, online"
    );
    $stmt->execute([$start, $end]);

    $by_status = array_fill_keys(BOOKING_STATUSES, 0);
    $source = ['desk' => 0, 'online' => 0];
    $total = $days = 0;
    $value = 0.0;
    $lost = 0; // cancelled + no-show
    foreach ($stmt->fetchAll() as $row) {
        $n = (int) $row['n'];
        $by_status[$row['booking_status']] = ($by_status[$row['booking_status']] ?? 0) + $n;
        $source[(int) $row['online'] ? 'online' : 'desk'] += $n;
        $total += $n;
        if (in_array($row['booking_status'], ['cancelled', 'no_show'], true)) {
            $lost += $n;
        } else {
            $days += (int) $row['days'];
            $value += (float) $row['value'];
        }
    }
    $kept = $total - $lost;

    // The same, bucketed for the chart.
    $gran = report_granularity($r);
    $bucket = $gran === 'day' ? "DATE_FORMAT(created_at, '%Y-%m-%d')" : "DATE_FORMAT(created_at, '%Y-%m')";
    $stmt = $db->prepare(
        "SELECT $bucket AS k, SUM(created_by IS NULL) AS online, SUM(created_by IS NOT NULL) AS desk
         FROM bookings WHERE created_at >= ? AND created_at < ? GROUP BY k"
    );
    $stmt->execute([$start, $end]);
    $series = [];
    foreach ($stmt->fetchAll() as $row) {
        $series[$row['k']] = ['desk' => (int) $row['desk'], 'online' => (int) $row['online']];
    }

    return [
        'total'            => $total,
        'by_status'        => $by_status,
        'source'           => $source,
        'rental_days'      => $days,
        'avg_days'         => $kept ? round($days / $kept, 1) : 0.0,
        'avg_value'        => $kept ? round($value / $kept, 2) : 0.0,
        'cancel_rate'      => $total ? round($lost / $total * 100, 1) : 0.0,
        'series'           => $series,
    ];
}

/** Rental days on rentals that went out in the range (what was actually sold). */
function rental_days_sold(array $r): int
{
    [$start, $end] = range_bounds($r);
    $stmt = Database::getConnection()->prepare(
        "SELECT COALESCE(SUM(b.rental_days), 0) FROM rentals rt JOIN bookings b ON b.booking_id = rt.booking_id
         WHERE rt.released_at >= ? AND rt.released_at < ?"
    );
    $stmt->execute([$start, $end]);
    return (int) $stmt->fetchColumn();
}

// ---------------------------------------------------------------------
// Customers, maintenance, what's owed
// ---------------------------------------------------------------------

/** Customers by revenue in the range. */
function customer_report(array $r, int $limit = 10): array
{
    [$start, $end] = range_bounds($r);
    $stmt = Database::getConnection()->prepare(
        "SELECT c.customer_id, u.full_name, u.email,
                SUM(" . REVENUE_SQL . ") AS revenue,
                COUNT(DISTINCT b.booking_id) AS bookings
         FROM payments p
         JOIN bookings b ON b.booking_id = p.booking_id
         JOIN customers c ON c.customer_id = b.customer_id
         JOIN users u ON u.user_id = c.user_id
         WHERE p.status = 'completed' AND p.paid_at >= ? AND p.paid_at < ?
         GROUP BY c.customer_id, u.full_name, u.email
         HAVING revenue <> 0
         ORDER BY revenue DESC, u.full_name
         LIMIT " . (int) $limit
    );
    $stmt->execute([$start, $end]);
    return array_map(function ($row) {
        $row['revenue'] = round((float) $row['revenue'], 2);
        $row['bookings'] = (int) $row['bookings'];
        return $row;
    }, $stmt->fetchAll());
}

/** Completed maintenance in the range, by type of work. */
function maintenance_report(array $r): array
{
    [$start, $end] = range_bounds($r);
    $stmt = Database::getConnection()->prepare(
        "SELECT maintenance_type, COUNT(*) AS jobs, SUM(cost) AS cost,
                SUM(DATEDIFF(end_date, service_date) + 1) AS days
         FROM maintenance
         WHERE status = 'completed' AND end_date >= ? AND end_date < ?
         GROUP BY maintenance_type ORDER BY cost DESC"
    );
    $stmt->execute([substr($start, 0, 10), substr($end, 0, 10)]);
    return array_map(fn ($row) => [
        'maintenance_type' => $row['maintenance_type'],
        'jobs'             => (int) $row['jobs'],
        'cost'             => round((float) $row['cost'], 2),
        'days'             => (int) $row['days'],
    ], $stmt->fetchAll());
}

/**
 * What's owed right now, regardless of the range: balances customers owe,
 * refunds the business owes, and deposits held.
 */
function receivables_now(): array
{
    $owed_to_us = [];
    $owed_back = [];
    foreach (list_bookings() as $b) {
        if ($b['balance_due'] > 0 && $b['booking_status'] !== 'pending') {
            $owed_to_us[] = $b;
        }
        if ($b['refund_due'] > 0) {
            $owed_back[] = $b;
        }
    }
    usort($owed_to_us, fn ($a, $b) => $b['balance_due'] <=> $a['balance_due']);
    return [
        'owed_to_us'       => $owed_to_us,
        'owed_back'        => $owed_back,
        'total_owed'       => round(array_sum(array_column($owed_to_us, 'balance_due')), 2),
        'total_owed_back'  => round(array_sum(array_column($owed_back, 'refund_due')), 2),
        'deposits_held'    => deposits_held_total(),
    ];
}

// ---------------------------------------------------------------------
// CSV
// ---------------------------------------------------------------------

/**
 * A cell that a spreadsheet won't execute: text starting with = + - @ (or a
 * tab/CR) gets a leading apostrophe. Numbers pass through untouched.
 */
function csv_safe($value)
{
    if (is_int($value) || is_float($value) || $value === null) {
        return $value;
    }
    $value = (string) $value;
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

/** @return array{0: string[], 1: array[]} header and rows for one report */
function report_csv_rows(string $report, array $r): ?array
{
    switch ($report) {
        case 'revenue':
            $rev = revenue_report($r);
            $rows = [];
            foreach ($rev['keys'] as $i => $k) {
                $rows[] = [$k, $rev['revenue'][$i], $rev['cash'][$i]];
            }
            return [[$rev['granularity'] === 'day' ? 'Date' : 'Month', 'Revenue (PHP)', 'Net cash (PHP)'], $rows];

        case 'vehicles':
            return [['Vehicle', 'Plate', 'Rentals', 'Days out', 'Utilization %', 'Revenue (PHP)', 'Maintenance jobs', 'Maintenance cost (PHP)', 'Revenue less maintenance (PHP)', 'Archived'],
                array_map(fn ($v) => [$v['brand'] . ' ' . $v['model'], $v['plate_number'], $v['rentals'], $v['days_out'], $v['utilization'],
                                      $v['revenue'], $v['jobs'], $v['maintenance_cost'], $v['net'], $v['deleted_at'] ? 'yes' : 'no'], vehicle_report($r))];

        case 'customers':
            return [['Customer', 'Email', 'Bookings paid in range', 'Revenue (PHP)'],
                array_map(fn ($c) => [$c['full_name'], $c['email'], $c['bookings'], $c['revenue']], customer_report($r, 1000))];

        case 'bookings':
            [$start, $end] = range_bounds($r);
            $stmt = Database::getConnection()->prepare(
                BOOKING_SELECT . ' WHERE b.created_at >= ? AND b.created_at < ? ORDER BY b.created_at, b.booking_id'
            );
            $stmt->execute([$start, $end]);
            return [['Reference', 'Made on', 'Source', 'Customer', 'Vehicle', 'Plate', 'Pickup', 'Return', 'Days', 'Status', 'Payment', 'Total (PHP)', 'Paid (PHP)', 'Balance (PHP)'],
                array_map(function ($b) {
                    $b = normalize_booking($b);
                    return [$b['booking_reference'], $b['created_at'], $b['created_by'] === null ? 'online' : 'desk', $b['customer_name'],
                            $b['brand'] . ' ' . $b['model'], $b['plate_number'], $b['pickup_datetime'], $b['return_datetime'], $b['rental_days'],
                            $b['booking_status'], $b['payment_status'], $b['total_amount'], $b['amount_paid'], $b['balance_due']];
                }, $stmt->fetchAll())];

        case 'maintenance':
            [$start, $end] = range_bounds($r);
            $stmt = Database::getConnection()->prepare(
                JOB_SELECT . " WHERE m.status = 'completed' AND m.end_date >= ? AND m.end_date < ? ORDER BY m.end_date, m.maintenance_id"
            );
            $stmt->execute([substr($start, 0, 10), substr($end, 0, 10)]);
            return [['Vehicle', 'Plate', 'Work', 'Start', 'End', 'Shop', 'Cost (PHP)', 'Description'],
                array_map(fn ($j) => [$j['brand'] . ' ' . $j['model'], $j['plate_number'], humanize($j['maintenance_type']), $j['service_date'],
                                      $j['end_date'], (string) $j['performed_by'], round((float) $j['cost'], 2), (string) $j['description']], $stmt->fetchAll())];

        case 'receivables':
            $now = receivables_now();
            $rows = [];
            foreach ($now['owed_to_us'] as $b) {
                $rows[] = ['owed to us', $b['booking_reference'], $b['customer_name'], $b['booking_status'], $b['balance_due']];
            }
            foreach ($now['owed_back'] as $b) {
                $rows[] = ['owed to customer', $b['booking_reference'], $b['customer_name'], $b['booking_status'], $b['refund_due']];
            }
            return [['Direction', 'Booking', 'Customer', 'Status', 'Amount (PHP)'], $rows];
    }
    return null;
}
