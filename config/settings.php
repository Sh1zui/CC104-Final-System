<?php
/**
 * Business settings an admin can change from Admin → Settings (Phase 13).
 *
 * Each one is still a PHP constant (SECURITY_DEPOSIT, LATE_FEE_HOURLY_PCT,
 * ...) so every rule in the code reads it exactly as before. The value is
 * the default below unless the `settings` table holds an override, which
 * is read once per request, before any constant is defined.
 *
 * A setting changes what happens from now on: bookings keep the price and
 * deposit they were made with; fees worked out at check-in or cancellation
 * use the setting in force at that moment.
 */

require_once __DIR__ . '/database.php';

const SETTINGS_SCHEMA = [
    // key => [group, label, type, default, min, max, help]
    'APP_NAME'                  => ['Business', 'Business name', 'text', 'AutoWay Car Rentals', 2, 80, 'Shown in the title bar, on receipts and invoices, and on the public page.'],
    'BUSINESS_ADDRESS'          => ['Business', 'Address', 'text', '123 J.P. Rizal Street, Calapan City, Oriental Mindoro', 5, 200, 'Printed on receipts and invoices.'],
    'BUSINESS_PHONE'            => ['Business', 'Phone', 'text', '(043) 123 4567', 5, 40, ''],
    'BUSINESS_EMAIL'            => ['Business', 'Email', 'email', 'desk@autoway.local', 5, 120, ''],
    'BUSINESS_TIN'              => ['Business', 'TIN', 'text', '000-000-000-000', 5, 30, 'Tax identification number, printed on invoices.'],
    'BUSINESS_GCASH_NUMBER'     => ['Business', 'GCash number', 'text', '0917 000 0000', 5, 40, 'Where customers send GCash payments.'],
    'BUSINESS_BANK_ACCOUNT'     => ['Business', 'Bank account', 'text', 'BPI 0000-0000-00 · AutoWay Car Rentals', 5, 120, 'Shown to customers paying by bank transfer.'],

    'SECURITY_DEPOSIT'          => ['Pricing', 'Security deposit (₱)', 'money', 2000.00, 0, 100000, 'Refundable, added to every new booking.'],
    'LONG_RENTAL_MIN_DAYS'      => ['Pricing', 'Long rental starts at (days)', 'int', 7, 2, 60, ''],
    'LONG_RENTAL_DISCOUNT_PCT'  => ['Pricing', 'Long-rental discount (%)', 'int', 10, 0, 50, 'Taken off the base amount automatically.'],
    'MAX_MANUAL_DISCOUNT_PCT'   => ['Pricing', 'Most extra discount an admin may give (%)', 'int', 30, 0, 90, ''],
    'STAFF_MAX_DISCOUNT_PCT'    => ['Pricing', 'Most extra discount staff may give (%)', 'int', 10, 0, 90, 'Must not exceed the admin limit.'],

    'MAX_RENTAL_DAYS'           => ['Bookings', 'Longest booking (days)', 'int', 30, 1, 365, ''],
    'MAX_ADVANCE_BOOKING_DAYS'  => ['Bookings', 'How far ahead pickups can be booked (days)', 'int', 180, 7, 730, ''],
    'TURNAROUND_HOURS'          => ['Bookings', 'Gap between two rentals of a car (hours)', 'int', 2, 0, 48, 'Time to clean and inspect.'],
    'ONLINE_BOOKING_LEAD_HOURS' => ['Bookings', 'Notice needed for online bookings (hours)', 'int', 3, 0, 168, ''],
    'MAX_PENDING_ONLINE_BOOKINGS' => ['Bookings', 'Unconfirmed online requests per customer', 'int', 3, 1, 20, ''],
    'FREE_CANCELLATION_HOURS'   => ['Bookings', 'Free cancellation until (hours before pickup)', 'int', 24, 0, 720, ''],
    'CANCELLATION_FEE_DAYS'     => ['Bookings', 'Late-cancellation and no-show fee (days of rental)', 'int', 1, 0, 7, ''],

    'EARLY_CHECKOUT_MINUTES'    => ['Handover', 'Release up to this early (minutes before pickup)', 'int', 120, 0, 1440, ''],
    'LATE_GRACE_MINUTES'        => ['Handover', 'Free lateness on return (minutes)', 'int', 60, 0, 600, ''],
    'LATE_FEE_HOURLY_PCT'       => ['Handover', 'Late fee per started hour (% of daily rate)', 'int', 15, 0, 100, 'Never more than one day\'s rate per 24 hours late.'],
    'FUEL_CHARGE_PER_PERCENT'   => ['Handover', 'Fuel charge per 1% of tank short (₱)', 'money', 25.00, 0, 1000, ''],

    'SERVICE_DUE_SOON_DAYS'     => ['Maintenance', 'Remind before a service date (days)', 'int', 14, 1, 90, ''],
    'SERVICE_DUE_SOON_KM'       => ['Maintenance', 'Remind before a service mileage (km)', 'int', 500, 50, 10000, ''],
    'CHECKIN_REPAIR_DAYS'       => ['Maintenance', 'Planned length of a job opened at check-in (days)', 'int', 2, 0, 30, ''],
];

/** The overrides saved by an admin. Missing table (an older database) = no overrides. */
function load_setting_overrides(): array
{
    try {
        return Database::getConnection()
            ->query('SELECT setting_key, setting_value FROM settings')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        return [];
    }
}

/** A stored string turned back into the setting's type; null if it no longer fits the rules. */
function setting_cast(string $key, $raw)
{
    [, , $type, , $min, $max] = SETTINGS_SCHEMA[$key];
    $raw = trim((string) $raw);
    if ($type === 'int') {
        if (!preg_match('/^-?\d+$/', $raw)) {
            return null;
        }
        $v = (int) $raw;
        return $v >= $min && $v <= $max ? $v : null;
    }
    if ($type === 'money') {
        if (!is_numeric($raw)) {
            return null;
        }
        $v = round((float) $raw, 2);
        return $v >= $min && $v <= $max ? $v : null;
    }
    $len = mb_strlen($raw);
    if ($len < $min || $len > $max) {
        return null;
    }
    if ($type === 'email' && !filter_var($raw, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    return $raw;
}

(function () {
    $overrides = load_setting_overrides();
    foreach (SETTINGS_SCHEMA as $key => $def) {
        $value = array_key_exists($key, $overrides) ? setting_cast($key, $overrides[$key]) : null;
        define($key, $value ?? $def[3]);
    }
})();
