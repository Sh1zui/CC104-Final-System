<?php
/**
 * Rows for the staff desk queues (dashboard, Release a vehicle, Receive a
 * return). Every button leads to the booking window on the Reservations
 * page, opened straight at the right panel (?open=ID&do=check_out|check_in).
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/desk_data.php';

function desk_customer_cell(array $b): string
{
    return '<td>' . e($b['customer_name'])
        . ($b['customer_phone'] ? '<div class="cell-sub"><a href="tel:' . e(preg_replace('/[^0-9+]/', '', $b['customer_phone'])) . '">'
            . e($b['customer_phone']) . '</a></div>' : '')
        . '</td>';
}

/** Dashboard version: who and which car in one cell, so half-width panels fit. */
function desk_compact_cell(array $b): string
{
    return '<td>' . e($b['customer_name']) . '<div class="cell-sub">' . e($b['brand'] . ' ' . $b['model'])
        . ' <span class="plate">' . e($b['plate_number']) . '</span></div></td>';
}

function desk_vehicle_cell(array $b): string
{
    return '<td><span class=\"cell-name\">' . e($b['brand'] . ' ' . $b['model']) . '</span><div class="cell-sub"><span class="plate">' . e($b['plate_number']) . '</span></div></td>';
}

/** @param bool $compact dashboard version: fewer columns */
function render_release_rows(array $rows, bool $compact = false): string
{
    $cols = $compact ? 3 : 6;
    if (!$rows) {
        return '<tr class="empty-row"><td colspan="' . $cols . '">No pickups today or tomorrow.</td></tr>';
    }

    $html = '';
    foreach ($rows as $b) {
        $search = strtolower(implode(' ', [$b['booking_reference'], $b['customer_name'], $b['brand'], $b['model'], $b['plate_number']]));
        $html .= '<tr data-search="' . e($search) . '" data-day="' . e($b['day']) . '" data-ready="' . ($b['ready'] ? 'yes' : 'no') . '">';

        // When
        $when = '<span class="nowrap fw-semibold">' . e(date('g:i A', strtotime($b['pickup_datetime']))) . '</span>'
            . '<div class="cell-sub">' . ($b['day'] === 'today' ? 'Today' : 'Tomorrow')
            . ($b['day'] === 'today' && substr($b['pickup_datetime'], 0, 10) < date('Y-m-d') ? ', ' . e(date('M j', strtotime($b['pickup_datetime']))) : '')
            . '</div>';
        if ($b['late']) {
            $when .= '<div class="cell-sub text-danger fw-semibold">late, '
                . e(duration_text(new DateTimeImmutable($b['pickup_datetime']), new DateTimeImmutable())) . '</div>';
        }
        $html .= '<td data-value="' . e($b['pickup_datetime']) . '">' . $when . '</td>';

        $html .= $compact ? desk_compact_cell($b) : desk_customer_cell($b) . desk_vehicle_cell($b);

        if (!$compact) {
            $html .= '<td><span class="mono small">' . e($b['booking_reference']) . '</span><div class="cell-sub">'
                . '<span class="status-badge ' . e(status_badge_class($b['booking_status'])) . '">' . e(humanize($b['booking_status'])) . '</span> '
                . ($b['balance_due'] > 0 ? '<span class="nowrap">' . e(money($b['balance_due'])) . ' to collect</span>' : '<span class="nowrap">paid</span>')
                . '</div></td>';
            $html .= '<td>' . desk_readiness($b) . '</td>';
        }

        $html .= '<td class="text-end">' . desk_release_button($b, $compact) . '</td>';
        $html .= '</tr>';
    }
    return $html;
}

function desk_readiness(array $b): string
{
    if ($b['issues']) {
        return '<ul class="desk-issues">' . implode('', array_map(fn ($i) => '<li>' . e($i) . '</li>', $b['issues'])) . '</ul>';
    }
    if ($b['can_release']) {
        return '<span class="status-badge status-success">Ready</span>';
    }
    $from = (new DateTimeImmutable($b['pickup_datetime']))->modify('-' . EARLY_CHECKOUT_MINUTES . ' minutes');
    return '<span class="text-secondary small">Can be released from ' . e(format_datetime_short($from->format('Y-m-d H:i:s'))) . '</span>';
}

function desk_release_button(array $b, bool $compact): string
{
    $id = (int) $b['booking_id'];
    if ($b['can_release']) {
        return '<a class="btn btn-sm ' . ($b['ready'] ? 'btn-brand' : 'btn-outline-secondary') . '" href="'
            . e(portal_url('bookings', ['open' => $id, 'do' => 'check_out'])) . '">Release</a>';
    }
    $label = $b['booking_status'] === 'pending' ? 'Confirm' : 'Open';
    return '<a class="btn btn-sm btn-outline-secondary" href="' . e(portal_url('bookings', ['open' => $id])) . '">' . $label . '</a>';
}

function render_return_rows(array $rows, bool $compact = false): string
{
    $cols = $compact ? 3 : 6;
    if (!$rows) {
        return '<tr class="empty-row"><td colspan="' . $cols . '">No cars are out on rental right now.</td></tr>';
    }

    $html = '';
    foreach ($rows as $b) {
        $search = strtolower(implode(' ', [$b['booking_reference'], $b['customer_name'], $b['brand'], $b['model'], $b['plate_number']]));
        $html .= '<tr data-search="' . e($search) . '" data-day="' . e($b['day']) . '">';

        $ts = strtotime($b['return_datetime']);
        $when = '<span class="nowrap fw-semibold">' . e(date('g:i A', $ts)) . '</span><div class="cell-sub">'
            . e($b['day'] === 'today' ? 'Today' : date('D, M j', $ts)) . '</div>';
        if ($b['overdue']) {
            $when .= '<div class="cell-sub text-danger fw-semibold' . ($compact ? '' : ' nowrap') . '">overdue ' . e($b['late_by']) . '</div>';
        }
        $html .= '<td data-value="' . e($b['return_datetime']) . '">' . $when . '</td>';
        $html .= $compact ? desk_compact_cell($b) : desk_customer_cell($b) . desk_vehicle_cell($b);

        if (!$compact) {
            $html .= '<td><span class="mono small">' . e($b['booking_reference']) . '</span><div class="cell-sub">out since '
                . e(format_datetime_short($b['pickup_datetime'])) . '</div></td>';
            $html .= '<td class="mono nowrap text-end" data-value="' . e((string) $b['balance_due']) . '">'
                . ($b['balance_due'] > 0 ? e(money($b['balance_due'])) : '<span class="text-secondary">—</span>') . '</td>';
        }

        $html .= '<td class="text-end"><a class="btn btn-sm ' . ($b['day'] !== 'later' ? 'btn-brand' : 'btn-outline-secondary') . '" href="'
            . e(portal_url('bookings', ['open' => (int) $b['booking_id'], 'do' => 'check_in'])) . '">Receive</a></td>';
        $html .= '</tr>';
    }
    return $html;
}
