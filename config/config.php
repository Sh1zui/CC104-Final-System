<?php
/**
 * Global app configuration.
 * Everything that isn't a raw DB credential lives here so it's one
 * place to change when moving from XAMPP to a real host later.
 */

// Where the app lives on the web. Leave BASE_URL_OVERRIDE empty and it's
// worked out from each request, so the app keeps working if the folder is
// renamed, Apache runs on another port (http://localhost:8080/...), or you
// open it by IP address. Set it only if you need a fixed address, e.g.
// 'https://rentals.example.com/' behind a proxy. Always end it with a /.
define('BASE_URL_OVERRIDE', '');

function detect_base_url(): string
{
    if (BASE_URL_OVERRIDE !== '') {
        return rtrim(BASE_URL_OVERRIDE, '/') . '/';
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (PHP_SAPI === 'cli' || !preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$|^\[[0-9A-Fa-f:.]+\](:\d{1,5})?$/', $host)) {
        return 'http://localhost/car_rental_system/'; // command line (tests) or a malformed Host header
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    // The project folder's URL path = the running script's URL minus its
    // path inside the project. Works under htdocs, an Alias, or a vhost.
    $root   = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
    $script = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $path = null;
    if ($root !== '' && $script !== '' && stripos($script, $root . '/') === 0) {
        $inside = substr($script, strlen($root));               // e.g. /admin/bookings.php
        $name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        if ($name !== '' && strcasecmp(substr($name, -strlen($inside)), $inside) === 0) {
            $path = substr($name, 0, -strlen($inside));         // e.g. /car_rental_system
        }
    }
    if ($path === null) {
        $doc = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $path = ($doc !== '' && stripos($root, $doc) === 0) ? substr($root, strlen($doc)) : '/car_rental_system';
    }
    return ($https ? 'https' : 'http') . '://' . $host . rtrim($path, '/') . '/';
}
define('BASE_URL', detect_base_url());

define('UPLOAD_DIR_VEHICLES', __DIR__ . '/../assets/uploads/vehicles/');
define('UPLOAD_DIR_DOCUMENTS', __DIR__ . '/../assets/uploads/documents/');
define('UPLOAD_URL_VEHICLES', BASE_URL . 'assets/uploads/vehicles/');
define('UPLOAD_URL_DOCUMENTS', BASE_URL . 'assets/uploads/documents/');

define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp']);
// Customer verification documents: sniffed MIME type => stored extension.
define('ALLOWED_DOCUMENT_TYPES', [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'application/pdf' => 'pdf',
]);

// Youngest age a customer profile may have (date_of_birth check).
define('MIN_RENTER_AGE', 18);

// Business details, prices, and booking/handover/maintenance rules are in
// config/settings.php (defaults) and editable by an admin under Settings.

// --- Technical limits (not editable from the app). ---
define('PICKUP_GRACE_MINUTES', 15);        // a pickup this many minutes in the past still counts as "now"
define('MAX_MANUAL_CHARGE', 200000.00);    // sanity cap on any one damage fee or extra charge
define('MAX_PAYMENT_AMOUNT', 1000000.00);  // sanity cap on a single payment
define('MAX_MAINTENANCE_DAYS', 60);        // longest a single job may be planned for

// Session lifetime (seconds) — kept short-ish since this handles
// financial + ID document data.
define('SESSION_LIFETIME', 60 * 60 * 2); // 2 hours

date_default_timezone_set('Asia/Manila');

// 'production' (the default) hides PHP errors from visitors and writes them
// to the PHP error log (on XAMPP: C:\xampp\php\logs\php_error_log).
// Change it to 'development' while you're changing the code, so errors
// show on the page. RIDEWELL_ENV in the server environment also works.
define('APP_ENV', getenv('AUTOWAY_ENV') ?: 'production');

// Login throttling (Phase 14): after this many failed logins for one
// username from one address within the window, that pair must wait.
define('LOGIN_MAX_FAILURES', 5);
define('LOGIN_IP_MAX_FAILURES', 20);       // any usernames, one address
define('LOGIN_WINDOW_MINUTES', 15);
define('REGISTRATIONS_PER_IP_PER_HOUR', 5);

error_reporting(E_ALL);
ini_set('display_errors', APP_ENV === 'development' ? '1' : '0');
ini_set('log_errors', '1');

// The business settings (Phase 13): defaults plus an admin's saved values.
require_once __DIR__ . '/settings.php';
