<?php
/**
 * Small stateless helpers shared across modules.
 * Auth-aware helpers (current_user(), require_role(), etc.) land in
 * includes/auth.php during Phase 2 — kept separate on purpose so this
 * file can be unit-tested without a session or DB in play.
 */

/**
 * Net cash a payment row represents, for SUM()s over `payments p`:
 * money in counts positive, refunds negative, and deposit_applied zero
 * (it moves no cash — it re-labels deposit money already received).
 * The one definition every "amount paid" query uses.
 */
const NET_CASH_SQL = "CASE p.payment_type WHEN 'refund' THEN -p.amount WHEN 'deposit_applied' THEN 0 ELSE p.amount END";

function money(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function e(?string $value): string
{
    // Shorthand escape for echoing user-supplied strings into HTML.
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function rental_days(string $pickup_datetime, string $return_datetime): int
{
    $pickup = new DateTime($pickup_datetime);
    $return = new DateTime($return_datetime);
    $hours  = ($return->getTimestamp() - $pickup->getTimestamp()) / 3600;

    // Any partial day beyond a full 24h block counts as a full day —
    // matches how most rental counters price it.
    return max(1, (int) ceil($hours / 24));
}

function generate_reference(string $prefix): string
{
    return strtoupper($prefix) . '-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

function redirect(string $path): void
{
    header('Location: ' . BASE_URL . ltrim($path, '/'));
    exit;
}

function flash(string $key, ?string $message = null)
{
    // Set: flash('error', 'Something went wrong');
    // Read (and clear) on the next request: flash('error');
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }

    $value = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $value;
}

function status_badge_class(string $status): string
{
    // Matches the color order already established in the fleet doughnut
    // chart (assets/js/dashboard-charts.js) and the five .status-* classes
    // in assets/css/style.css — one color language for badges, stat tiles,
    // and charts alike. Covers every status/enum value across the schema
    // (users, customers, vehicles, bookings, payments, invoices,
    // maintenance, documents), not just one table's.
    return match ($status) {
        // "everything's fine" / positive end-states
        'available', 'active', 'completed', 'paid', 'verified', 'approved' => 'status-success',
        // needs attention, not urgent
        'pending', 'unverified', 'scheduled', 'partial', 'reserved', 'unpaid' => 'status-warning',
        // currently in progress
        'confirmed', 'in_progress', 'rented' => 'status-info',
        // paused / inactive, not alarming
        'maintenance', 'refunded', 'deactivated' => 'status-neutral',
        // stopped / wrong / needs action
        'cancelled', 'unavailable', 'blocked', 'rejected', 'failed', 'no_show', 'suspended', 'void' => 'status-danger',
        default => 'status-neutral',
    };
}

/**
 * The one password policy, used by self-registration and by staff/admin
 * creating an account at the desk. Returns an error message, or null if OK.
 */
function password_policy_error(string $password): ?string
{
    if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'Password must be at least 8 characters and include a letter and a number.';
    }
    return null;
}

/** Parse a strict Y-m-d date; null if it's not a real calendar date. */
function parse_date(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);
    $date  = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $date : null;
}

/** Enum value -> label shown to people ("drivers_license" -> "Driver's license"). */
/**
 * The icon for a body type, used on car cards and vehicle thumbnails that
 * have no photo yet. The color comes from CSS ([data-type] in style.css).
 */
function vehicle_type_icon(string $type): string
{
    return match ($type) {
        'van'        => 'bi-bus-front',
        'pickup'     => 'bi-truck-front',
        'suv', 'luxury' => 'bi-car-front-fill',
        'motorcycle' => 'bi-scooter',
        default      => 'bi-car-front',
    };
}

function humanize(string $value): string
{
    // The few values where "replace underscores, capitalize" reads wrong.
    $labels = [
        'drivers_license' => "Driver's license",
        'national_id'     => 'National ID',
        'id_number'       => 'ID number',
        'no_show'         => 'No-show',
        'suv'             => 'SUV',
    ];
    return $labels[$value] ?? ucfirst(str_replace('_', ' ', $value));
}


/**
 * Parse a date+time from a form ("2026-10-05T09:30" from datetime-local,
 * or "2026-10-05 09:30[:00]"). Seconds are dropped. Null if it isn't a
 * real calendar date/time.
 */
function parse_datetime(?string $value): ?DateTimeImmutable
{
    $value = str_replace('T', ' ', trim((string) $value));
    foreach (['!Y-m-d H:i', '!Y-m-d H:i:s'] as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value);
        if ($dt && $dt->format(strlen($value) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s') === $value) {
            return $dt->setTime((int) $dt->format('H'), (int) $dt->format('i'));
        }
    }
    return null;
}

/** "Oct 5 9:30 AM" this year, "Oct 5, 2027 9:30 AM" otherwise — for tight table cells. */
function format_datetime_short(?string $value): string
{
    if (!$value) {
        return '—';
    }
    $ts = strtotime($value);
    return date(date('Y', $ts) === date('Y') ? 'M j, g:i A' : 'M j, Y g:i A', $ts);
}

/** "Oct 5, 2026 9:30 AM" — one display format for every date+time in the app. */
function format_datetime(?string $value): string
{
    return $value ? date('M j, Y g:i A', strtotime($value)) : '—';
}

/** "on Oct 7, 2026" for one day, "Oct 7 – 9, 2026" / "Sep 30 – Oct 2, 2026" for a range. */
function format_date_range(string $start, string $end): string
{
    $a = strtotime($start);
    $b = strtotime($end);
    if (date('Y-m-d', $a) === date('Y-m-d', $b)) {
        return 'on ' . date('M j, Y', $a);
    }
    if (date('Y-m', $a) === date('Y-m', $b)) {
        return date('M j', $a) . ' – ' . date('j, Y', $b);
    }
    if (date('Y', $a) === date('Y', $b)) {
        return date('M j', $a) . ' – ' . date('M j, Y', $b);
    }
    return date('M j, Y', $a) . ' – ' . date('M j, Y', $b);
}

