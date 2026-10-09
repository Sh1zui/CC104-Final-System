<?php
/**
 * Maintenance table rows and service-due rows, shared by
 * admin/maintenance.php (first load) and api/maintenance.php (after every
 * change) — one template each.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/maintenance_data.php';

const JOB_TABLE_COLUMNS = 7;

function render_job_rows(array $jobs): string
{
    if (!$jobs) {
        return '<tr class="empty-row"><td colspan="' . JOB_TABLE_COLUMNS . '">No maintenance yet. Schedule a job, or log work that was already done.</td></tr>';
    }

    $today = date('Y-m-d');
    $html = '';
    foreach ($jobs as $j) {
        $open = in_array($j['status'], ['scheduled', 'in_progress'], true);
        $when = $j['status'] === 'in_progress' ? 'now' : ($open ? 'upcoming' : 'history');
        $search = strtolower(implode(' ', [$j['brand'], $j['model'], $j['plate_number'], humanize($j['maintenance_type']),
                                            $j['description'] ?? '', $j['performed_by'] ?? '']));

        $html .= '<tr data-search="' . e($search) . '" data-status="' . e($j['status']) . '" data-type="' . e($j['maintenance_type']) . '"'
            . ' data-when="' . $when . '">';
        $html .= '<td><span class=\"cell-name\">' . e($j['brand'] . ' ' . $j['model']) . '</span><div class="cell-sub"><span class="plate">' . e($j['plate_number']) . '</span></div></td>';
        $html .= '<td><div class="fw-semibold">' . e(humanize($j['maintenance_type'])) . '</div>'
            . ($j['description'] ? '<div class="cell-sub text-truncate-2">' . e($j['description']) . '</div>' : '') . '</td>';
        $html .= '<td data-value="' . e($j['service_date']) . '"><span class="nowrap">' . e(preg_replace('/^on /', '', format_date_range($j['service_date'], $j['end_date']))) . '</span>'
            . ($j['overrunning'] ? '<div class="cell-sub text-danger fw-semibold">past planned end</div>'
                : ($j['status'] === 'scheduled' && $j['service_date'] <= $today ? '<div class="cell-sub">due to start</div>' : ''))
            . '</td>';
        $html .= '<td>' . e($j['performed_by'] ?: '—') . '</td>';
        $html .= '<td class="mono nowrap text-end" data-value="' . e((string) $j['cost']) . '">'
            . ($j['status'] === 'completed' ? e(money($j['cost'])) : '<span class="text-secondary">—</span>') . '</td>';
        $html .= '<td data-value="' . e($j['status']) . '"><span class="status-badge ' . e(status_badge_class($j['status'])) . '">'
            . e(humanize($j['status'])) . '</span></td>';
        $html .= '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-secondary" data-action="open" data-id="'
            . (int) $j['maintenance_id'] . '">Open</button></td>';
        $html .= '</tr>';
    }

    return $html;
}

/** Cars whose next service is overdue or due soon. */
function render_due_rows(array $due): string
{
    if (!$due) {
        return '<tr class="empty-row"><td colspan="5">Nothing due. Every car with a service plan is within its date and mileage.</td></tr>';
    }

    $html = '';
    foreach ($due as $r) {
        $badge = $r['state'] === 'overdue' ? ['status-danger', 'Overdue'] : ['status-warning', 'Due soon'];
        $next = [];
        if ($r['next_maintenance_date']) {
            $next[] = date('M j, Y', strtotime($r['next_maintenance_date']));
        }
        if ($r['next_due_km'] !== null) {
            $next[] = number_format((int) $r['next_due_km']) . ' km';
        }

        $html .= '<tr>';
        $html .= '<td><span class=\"cell-name\">' . e($r['brand'] . ' ' . $r['model']) . '</span><div class="cell-sub"><span class="plate">' . e($r['plate_number']) . '</span> <span class="nowrap">'
            . e(number_format($r['mileage_km'])) . ' km now</span></div></td>';
        $html .= '<td class="nowrap">' . e(humanize($r['maintenance_type'])) . '<div class="cell-sub">' . e(date('M j, Y', strtotime($r['last_service']))) . '</div></td>';
        $html .= '<td><span class="nowrap">' . e(implode(' or ', $next)) . '</span><div class="cell-sub">' . e(service_due_text($r)) . '</div></td>';
        $html .= '<td><span class="status-badge ' . $badge[0] . '">' . $badge[1] . '</span>'
            . ($r['open_jobs'] > 0 ? '<div class="cell-sub">already scheduled</div>' : '') . '</td>';
        $html .= '<td class="text-end">'
            . ($r['open_jobs'] > 0 ? '' : '<button type="button" class="btn btn-sm btn-outline-secondary" data-schedule-vehicle="' . (int) $r['vehicle_id']
                . '" data-schedule-type="' . e($r['maintenance_type']) . '">Schedule</button>')
            . '</td>';
        $html .= '</tr>';
    }
    return $html;
}
