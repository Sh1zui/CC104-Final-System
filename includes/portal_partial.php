<?php
/**
 * HTML for the customer portal: vehicle cards (browse page, first load and
 * after every search), the customer's booking rows, and — for the desk —
 * the list of online payments waiting to be checked.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/portal_data.php';

function vehicle_image_url(?string $path): ?string
{
    return $path && is_file(dirname(__DIR__) . '/' . $path) ? BASE_URL . $path : null;
}

/**
 * @param array|null $window ['pickup' => ..., 'return' => ..., 'days' => n] once dates are picked
 */
/**
 * Car cards for the browse pages. $can_book shows a Book button (logged-in,
 * verified customer). $login_link, used on the public browse page, turns a
 * card into a "Log in to book" link for those dates instead.
 */
function render_vehicle_cards(array $vehicles, ?array $window, bool $can_book, ?callable $login_link = null): string
{
    if (!$vehicles) {
        return '<div class="empty-state">' . ($window
            ? 'No cars are free for those dates with these filters. Try other dates, or fewer filters.'
            : 'No cars match these filters.') . '</div>';
    }

    $html = '';
    foreach ($vehicles as $v) {
        $name = $v['brand'] . ' ' . $v['model'];
        $img = vehicle_image_url($v['primary_image'] ?? null);
        $html .= '<article class="car-card" data-vehicle-id="' . (int) $v['vehicle_id'] . '">';
        $html .= '<div class="car-card-media" data-type="' . e($v['vehicle_type']) . '">'
            . '<span class="car-type-chip">' . e(humanize($v['vehicle_type'])) . '</span>'
            . ($img
            ? '<img src="' . e($img) . '" alt="' . e($name) . '" loading="lazy">'
            : '<i class="bi ' . vehicle_type_icon($v['vehicle_type']) . '" aria-hidden="true"></i>') . '</div>';
        $html .= '<div class="car-card-body">';
        $html .= '<h3 class="car-card-title">' . e($name) . ' <span class="text-secondary fw-normal">' . (int) $v['year'] . '</span></h3>';
        $html .= '<ul class="car-specs">'
            . '<li>' . e(humanize($v['vehicle_type'])) . '</li>'
            . '<li>' . e(humanize($v['transmission'])) . '</li>'
            . '<li>' . (int) $v['seating_capacity'] . ' seats</li>'
            . '<li>' . e(humanize($v['fuel_type'])) . '</li></ul>';

        $html .= '<div class="car-card-price">';
        if ($window && isset($v['quote'])) {
            $q = $v['quote'];
            $html .= '<div><span class="car-price-main mono">' . e(money($q['total_amount'] - $q['deposit_amount'])) . '</span>'
                . ' <span class="text-secondary small">for ' . (int) $q['rental_days'] . ' day' . ($q['rental_days'] === 1 ? '' : 's') . '</span>'
                . '<div class="cell-sub">' . e(money($q['daily_rate'])) . '/day'
                . ($q['long_rental_discount'] > 0 ? ', ' . LONG_RENTAL_DISCOUNT_PCT . '% long-rental discount' : '')
                . ', plus ' . e(money($q['deposit_amount'])) . ' refundable deposit</div></div>';
            if ($can_book) {
                $html .= '<button type="button" class="btn btn-brand" data-book="' . (int) $v['vehicle_id'] . '"'
                    . ' data-name="' . e($name . ' ' . $v['year']) . '" data-quote="' . e(json_encode($q)) . '">Book</button>';
            } elseif ($login_link) {
                $html .= '<a class="btn btn-brand" href="' . e($login_link($v)) . '">Log in to book</a>';
            }
        } else {
            $html .= '<div><span class="car-price-main mono">' . e(money((float) $v['daily_rate'])) . '</span>'
                . ' <span class="text-secondary small">/ day</span></div>'
                . '<button type="button" class="btn btn-outline-secondary" data-pick-dates>Check dates</button>';
        }
        $html .= '</div></div></article>';
    }
    return $html;
}

/** The status badge a customer sees: an active rental past its return time says so. */
function own_booking_badge(array $b): string
{
    $overdue = $b['booking_status'] === 'active' && $b['return_datetime'] < date('Y-m-d H:i:s');
    return '<span class="status-badge ' . e($overdue ? 'status-danger' : status_badge_class($b['booking_status'])) . '">'
        . e($overdue ? 'Overdue' : humanize($b['booking_status'])) . '</span>';
}

/** "What happens next" for one of the customer's bookings, in their words. */
function own_booking_next_step(array $b): string
{
    return match ($b['booking_status']) {
        'pending'   => 'Waiting for the desk to confirm',
        'confirmed' => $b['balance_due'] > 0 ? 'Confirmed · ' . money($b['balance_due']) . ' to pay' : 'Confirmed · fully paid',
        'active'    => $b['return_datetime'] < date('Y-m-d H:i:s')
                         ? 'Overdue since ' . format_datetime_short($b['return_datetime']) . ' · please return the car or call the desk'
                         : 'Car is with you · return by ' . format_datetime_short($b['return_datetime']),
        'completed' => $b['refund_due'] > 0 ? 'Returned · ' . money($b['refund_due']) . ' to be refunded'
                       : ($b['balance_due'] > 0 ? 'Returned · ' . money($b['balance_due']) . ' still owed' : 'Returned'),
        'cancelled' => 'Cancelled' . ($b['refund_due'] > 0 ? ' · ' . money($b['refund_due']) . ' to be refunded' : ''),
        'no_show'   => 'Not picked up',
        default     => humanize($b['booking_status']),
    };
}

function render_own_booking_rows(array $bookings): string
{
    if (!$bookings) {
        return '<tr class="empty-row"><td colspan="5">No bookings yet. <a href="' . e(BASE_URL) . 'customer/browse.php">Find a car</a> to get started.</td></tr>';
    }
    $now = date('Y-m-d H:i:s');
    $html = '';
    foreach ($bookings as $b) {
        $upcoming = in_array($b['booking_status'], ['pending', 'confirmed', 'active'], true) && $b['return_datetime'] > $now;
        $html .= '<tr data-search="' . e(strtolower($b['booking_reference'] . ' ' . $b['brand'] . ' ' . $b['model'])) . '"'
            . ' data-when="' . ($upcoming ? 'upcoming' : 'past') . '" data-status="' . e($b['booking_status']) . '">';
        $html .= '<td><span class="fw-semibold">' . e($b['brand'] . ' ' . $b['model']) . '</span><div class="cell-sub mono">' . e($b['booking_reference']) . '</div></td>';
        $html .= '<td data-value="' . e($b['pickup_datetime']) . '"><span class="nowrap">' . e(format_datetime_short($b['pickup_datetime'])) . '</span>'
            . '<div class="cell-sub">to <span class="nowrap">' . e(format_datetime_short($b['return_datetime'])) . '</span></div></td>';
        $html .= '<td class="mono nowrap text-end" data-value="' . e((string) $b['total_amount']) . '">' . e(money($b['total_amount'])) . '</td>';
        $html .= '<td>' . own_booking_badge($b)
            . '<div class="cell-sub">' . e(own_booking_next_step($b)) . '</div>'
            . ((int) $b['submissions_waiting'] > 0 ? '<div class="cell-sub">payment being checked</div>' : '') . '</td>';
        $html .= '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-secondary" data-open-booking="' . (int) $b['booking_id'] . '">View</button></td>';
        $html .= '</tr>';
    }
    return $html;
}

/** For admin/staff: online payments waiting to be checked, linking to each booking. */
function render_open_submission_rows(array $subs): string
{
    if (!$subs) {
        return '<tr class="empty-row"><td colspan="4">Nothing to check.</td></tr>';
    }
    $html = '';
    foreach ($subs as $s) {
        $html .= '<tr><td>' . e($s['customer_name']) . '<div class="cell-sub mono">' . e($s['booking_reference']) . '</div></td>'
            . '<td>' . e($s['method_label']) . '<div class="cell-sub">ref <span class="mono">' . e($s['transaction_ref']) . '</span></div></td>'
            . '<td class="mono nowrap text-end">' . e(money($s['amount'])) . '<div class="cell-sub">' . e($s['type_label']) . '</div></td>'
            . '<td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="' . e(portal_url('bookings', ['open' => (int) $s['booking_id']])) . '">Check</a></td></tr>';
    }
    return $html;
}
