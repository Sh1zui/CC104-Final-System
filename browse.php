<?php
/**
 * Public car browser: anyone can see the fleet, pick dates, and get the
 * full price before making an account. Booking itself still needs a
 * customer login.
 *
 * - A logged-in customer is sent to customer/browse.php (same search),
 *   where the Book button works.
 * - Everyone else gets this page. It's a plain GET form, rendered on the
 *   server, so it works without JavaScript and every search has a link.
 * - "Log in to book" (?book=<vehicle id>) remembers the search, sends the
 *   visitor to log in, and brings them back to customer/browse.php with
 *   those dates already searched.
 *
 * Plates and anything about other customers are never shown here
 * (portal_search() drops the plate).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/portal_partial.php';

// Only these query fields are carried between pages.
$query = array_filter([
    'pickup'       => (string) ($_GET['pickup'] ?? ''),
    'return'       => (string) ($_GET['return'] ?? ''),
    'type'         => in_array($_GET['type'] ?? '', VEHICLE_TYPES, true) ? $_GET['type'] : '',
    'transmission' => in_array($_GET['transmission'] ?? '', TRANSMISSIONS, true) ? $_GET['transmission'] : '',
    'min_seats'    => in_array((string) ($_GET['min_seats'] ?? ''), ['5', '7'], true) ? (string) $_GET['min_seats'] : '',
], fn ($v) => $v !== '');
$customer_browse = 'customer/browse.php' . ($query ? '?' . http_build_query($query) : '');

$viewer = current_user();
if ($viewer && $viewer['role_name'] === 'customer') {
    redirect($customer_browse);
}

// "Log in to book": come back to the customer browse page with this search.
if (isset($_GET['book']) && !$viewer) {
    $_SESSION['redirect_after_login'] = $customer_browse;
    flash('error', 'Log in or create an account to book. We\'ll bring you back to these dates.');
    redirect('auth/login.php');
}

$filters = [
    'type'         => $query['type'] ?? '',
    'transmission' => $query['transmission'] ?? '',
    'min_seats'    => (int) ($query['min_seats'] ?? 0),
];

$errors = [];
$window = null;
$have_dates = isset($query['pickup']) || isset($query['return']);
if ($have_dates) {
    $pickup = parse_datetime($query['pickup'] ?? null);
    $return = parse_datetime($query['return'] ?? null);
    $errors = (!$pickup || !$return)
        ? ['Pick both a pickup and a return date and time.']
        : online_window_errors($pickup, $return);
    if (!$errors) {
        $window = ['pickup' => $pickup, 'return' => $return];
    }
}

$vehicles = $window ? portal_search($window['pickup'], $window['return'], $filters) : portal_catalog($filters);
$login_link = $viewer ? null : fn (array $v) => BASE_URL . 'browse.php?' . http_build_query($query + ['book' => (int) $v['vehicle_id']]);

$min_pickup = (new DateTimeImmutable('+' . ONLINE_BOOKING_LEAD_HOURS . ' hours'))->format('Y-m-d\TH:i');
$max_pickup = (new DateTimeImmutable('+' . MAX_ADVANCE_BOOKING_DAYS . ' days'))->format('Y-m-d\TH:i');
$sel = fn (string $field, string $value) => (($query[$field] ?? '') === $value) ? ' selected' : '';

$page_title = 'Browse cars';
?>
<!doctype html>
<html lang="en">
<?php require __DIR__ . '/includes/auth_head.php'; ?>
<body class="landing">
<header class="landing-top">
    <a class="landing-brand" href="<?= e(BASE_URL) ?>"><img class="brand-logo" src="<?= e(BASE_URL) ?>assets/img/logo.svg" alt="" width="34" height="34"><?= e(APP_NAME) ?></a>
    <nav class="landing-nav" aria-label="Account">
        <button type="button" class="icon-btn" id="theme-toggle" aria-label="Switch light/dark theme"><i class="bi bi-moon-stars"></i></button>
        <?php if ($viewer): ?>
            <a class="btn btn-brand btn-sm" href="<?= e(BASE_URL . dashboard_path_for($viewer['role_name'])) ?>">Back to your dashboard</a>
        <?php else: ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(BASE_URL) ?>auth/login.php">Log in</a>
            <a class="btn btn-brand btn-sm" href="<?= e(BASE_URL) ?>auth/register.php">Create account</a>
        <?php endif; ?>
    </nav>
</header>
<main class="public-browse">
    <div class="public-browse-head">
        <h1>Browse cars</h1>
        <p>Pick your dates to see which cars are free and the full price. You'll need an account to book.</p>
    </div>

    <form class="panel search-panel" method="get" action="<?= e(BASE_URL) ?>browse.php" id="public-search">
        <div class="search-fields">
            <div>
                <label class="form-label" for="s-pickup">Pickup</label>
                <input type="datetime-local" class="form-control" id="s-pickup" name="pickup" value="<?= e($query['pickup'] ?? '') ?>"
                       min="<?= e($min_pickup) ?>" max="<?= e($max_pickup) ?>">
            </div>
            <div>
                <label class="form-label" for="s-return">Return</label>
                <input type="datetime-local" class="form-control" id="s-return" name="return" value="<?= e($query['return'] ?? '') ?>" min="<?= e($min_pickup) ?>">
            </div>
            <div>
                <label class="form-label" for="s-type">Type</label>
                <select class="form-select" id="s-type" name="type">
                    <option value="">Any</option>
                    <?php foreach (VEHICLE_TYPES as $t): ?><option value="<?= e($t) ?>"<?= $sel('type', $t) ?>><?= e(humanize($t)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="s-transmission">Transmission</label>
                <select class="form-select" id="s-transmission" name="transmission">
                    <option value="">Any</option>
                    <?php foreach (TRANSMISSIONS as $t): ?><option value="<?= e($t) ?>"<?= $sel('transmission', $t) ?>><?= e(humanize($t)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="s-seats">Seats</label>
                <select class="form-select" id="s-seats" name="min_seats">
                    <option value="">Any</option>
                    <option value="5"<?= $sel('min_seats', '5') ?>>5 or more</option>
                    <option value="7"<?= $sel('min_seats', '7') ?>>7 or more</option>
                </select>
            </div>
            <div class="search-submit">
                <button type="submit" class="btn btn-brand w-100"><i class="bi bi-search me-1"></i>Check availability</button>
            </div>
        </div>
        <p class="form-text mb-0 mt-2">Pickups need at least <?= ONLINE_BOOKING_LEAD_HOURS ?> hours' notice. Prices include everything except a
            <?= e(money(SECURITY_DEPOSIT)) ?> refundable deposit; rentals of <?= LONG_RENTAL_MIN_DAYS ?>+ days get <?= LONG_RENTAL_DISCOUNT_PCT ?>% off.</p>
    </form>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-warning" role="alert"><?= e($error) ?></div>
    <?php endforeach; ?>

    <p class="text-secondary mb-2" id="results-line">
        <?php if ($window): ?>
            <?php $days = rental_days($window['pickup']->format('Y-m-d H:i:s'), $window['return']->format('Y-m-d H:i:s')); ?>
            <?= count($vehicles) ?> <?= count($vehicles) === 1 ? 'car is' : 'cars are' ?> free for <?= $days ?> day<?= $days === 1 ? '' : 's' ?>,
            <?= e(format_datetime($window['pickup']->format('Y-m-d H:i:s'))) ?> to <?= e(format_datetime($window['return']->format('Y-m-d H:i:s'))) ?>.
        <?php else: ?>
            <?= count($vehicles) ?> <?= count($vehicles) === 1 ? 'car' : 'cars' ?> in our fleet. Pick dates to see what's free and the total price.
        <?php endif; ?>
    </p>
    <div class="car-grid" id="car-grid"><?= render_vehicle_cards($vehicles, $window, false, $login_link) ?></div>
</main>
<footer class="landing-foot">
    <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>, <?= e(BUSINESS_ADDRESS) ?></span>
    <a href="<?= e(BASE_URL) ?>">Front page</a>
</footer>
<script src="<?= e(BASE_URL) ?>assets/js/app.js"></script>
<script src="<?= e(BASE_URL) ?>assets/js/browse-public.js"></script>
</body>
</html>
