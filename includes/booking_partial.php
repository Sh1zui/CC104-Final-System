<?php
/**
 * Booking table rows, shared by admin/bookings.php (first load) and
 * api/bookings.php (after every change) — one template for a booking row.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/bookings_data.php';

const BOOKING_TABLE_COLUMNS = 8;

/**
 * Where a booking sits in time, for the "When" filter:
 * today (pickup or return today), upcoming, current (out right now), past.
 */
function booking_time_bucket(array $b, ?DateTimeImmutable $now = null): string
{
    $now = $now ?? new DateTimeImmutable();
    $today = $now->format('Y-m-d');
    $pickup = new DateTimeImmutable($b['pickup_datetime']);
    $return = new DateTimeImmutable($b['return_datetime']);

    if ($pickup->format('Y-m-d') === $today || $return->format('Y-m-d') === $today) {
        return 'today';
    }
    if ($pickup > $now) {
        return 'upcoming';
    }
    if ($return > $now) {
        return 'current';
    }
    return 'past';
}

function render_booking_rows(array $bookings): string
{
    if (!$bookings) {
        return '<tr class="empty-row"><td colspan="' . BOOKING_TABLE_COLUMNS . '">'
            . 'No bookings yet. Click "New booking" to make the first one.</td></tr>';
    }

    $now = new DateTimeImmutable();
    $html = '';
    foreach ($bookings as $b) {
        $search = strtolower(implode(' ', [
            $b['booking_reference'], $b['customer_name'], $b['customer_email'], $b['brand'], $b['model'], $b['plate_number'],
        ]));
        $overdue = $b['booking_status'] === 'active' && new DateTimeImmutable($b['return_datetime']) < $now;
        $pickup_today = in_array($b['booking_status'], ['pending', 'confirmed'], true)
            && substr($b['pickup_datetime'], 0, 10) === $now->format('Y-m-d');

        $html .= '<tr data-search="' . e($search) . '" data-status="' . e($b['booking_status']) . '"'
            . ' data-when="' . booking_time_bucket($b, $now) . '" data-payment="' . e($b['payment_status'] . ((int) ($b['submissions_waiting'] ?? 0) > 0 ? ' to_check' : '')) . '"'
            . ($overdue ? ' data-overdue="1"' : '') . ($pickup_today ? ' data-pickup-today="1"' : '') . '>';

        $html .= '<td class="mono fw-semibold nowrap">' . e($b['booking_reference']) . '</td>';
        $html .= '<td><div class="fw-semibold">' . e($b['customer_name']) . '</div><div class="cell-sub">' . e($b['customer_phone'] ?: $b['customer_email']) . '</div></td>';
        $html .= '<td><span class=\"cell-name\">' . e($b['brand'] . ' ' . $b['model']) . '</span><div class="cell-sub"><span class="plate">' . e($b['plate_number']) . '</span></div></td>';
        $html .= '<td data-value="' . e($b['pickup_datetime']) . '"><span class="nowrap">' . e(format_datetime_short($b['pickup_datetime'])) . '</span>'
            . '<div class="cell-sub' . ($overdue ? ' text-danger fw-semibold' : '') . '">' . ($overdue ? 'overdue since ' : 'to ')
            . '<span class="nowrap">' . e(format_datetime_short($b['return_datetime'])) . '</span></div></td>';
        $html .= '<td class="mono" data-value="' . $b['rental_days'] . '">' . $b['rental_days'] . '</td>';
        // Only worth a second line when part is paid; "unpaid" is already in the Status column.
        $html .= '<td class="mono nowrap" data-value="' . e((string) $b['total_amount']) . '">' . e(money($b['total_amount']))
            . ($b['payment_status'] === 'partial' && !in_array($b['booking_status'], ['cancelled', 'no_show'], true)
                ? '<div class="cell-sub">' . e(money($b['balance_due'])) . ' due</div>' : '')
            . '</td>';
        $html .= '<td data-value="' . e($b['booking_status']) . '"><span class="status-badge ' . e(status_badge_class($b['booking_status'])) . '">'
            . e(humanize($b['booking_status'])) . '</span>'
            . '<div class="cell-sub">payment ' . e($b['payment_status']) . '</div>'
            . ((int) $b['submissions_waiting'] > 0 ? '<div class="cell-sub text-warning-emphasis fw-semibold">online payment to check</div>' : '')
            . '</td>';
        $html .= '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-secondary" data-action="open" data-id="'
            . (int) $b['booking_id'] . '">Open</button></td>';
        $html .= '</tr>';
    }

    return $html;
}
