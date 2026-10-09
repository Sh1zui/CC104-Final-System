<?php
/**
 * The public front page (Phase 13). Logged-in visitors go straight to
 * their dashboard; everyone else sees the fleet with today's rates and
 * where each car is, how renting works, and the rules in plain words.
 * Every number on this page comes from the database or the admin's
 * settings, so it never disagrees with what the booking engine charges.
 */
require_once __DIR__ . '/includes/auth.php';

if ($u = current_user()) {
    redirect(dashboard_path_for($u['role_name']));
}

// Public view of the fleet: no plates, no customers, just the car and where it is today.
$cars = Database::getConnection()->query(
    "SELECT v.vehicle_id, v.brand, v.model, v.year, v.vehicle_type, v.transmission, v.fuel_type, v.seating_capacity,
            v.daily_rate, v.status,
            (SELECT image_path FROM vehicle_images vi WHERE vi.vehicle_id = v.vehicle_id ORDER BY is_primary DESC, image_id ASC LIMIT 1) AS primary_image,
            EXISTS (SELECT 1 FROM bookings b WHERE b.vehicle_id = v.vehicle_id AND b.booking_status = 'active') AS is_out
     FROM vehicles v
     WHERE v.deleted_at IS NULL AND v.status != 'unavailable'
     ORDER BY v.daily_rate ASC, v.brand, v.model"
)->fetchAll();

$on_lot = count(array_filter($cars, fn ($c) => !$c['is_out'] && $c['status'] !== 'maintenance'));

function board_state(array $c): array
{
    if ($c['is_out']) {
        return ['out', 'Out on rental'];
    }
    if ($c['status'] === 'maintenance') {
        return ['service', 'In for service'];
    }
    return ['lot', 'On the lot'];
}

$page_title = 'Car rental in ' . (preg_match('/,\s*([^,]+),\s*[^,]+$/', BUSINESS_ADDRESS, $m) ? $m[1] : 'town');
?>
<!doctype html>
<html lang="en">
<?php require __DIR__ . '/includes/auth_head.php'; ?>
<body class="landing">

<header class="landing-top">
    <a class="landing-brand" href="<?= e(BASE_URL) ?>"><img class="brand-logo" src="<?= e(BASE_URL) ?>assets/img/logo.svg" alt="" width="34" height="34"><?= e(APP_NAME) ?></a>
    <nav class="landing-nav" aria-label="Account">
        <button type="button" class="icon-btn" id="theme-toggle" aria-label="Switch light/dark theme"><i class="bi bi-moon-stars"></i></button>
        <a class="btn btn-outline-secondary btn-sm landing-browse-link" href="<?= e(BASE_URL) ?>browse.php">Browse cars</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(BASE_URL) ?>auth/login.php">Log in</a>
        <a class="btn btn-brand btn-sm" href="<?= e(BASE_URL) ?>auth/register.php">Create account</a>
    </nav>
</header>

<main>
    <section class="landing-hero">
        <div class="hero-copy">
            <h1>Rent a car in <?= e(preg_match('/,\s*([^,]+),\s*[^,]+$/', BUSINESS_ADDRESS, $m) ? $m[1] : 'town') ?>, at the price on the board.</h1>
            <p class="hero-lede">Pick your dates online, then collect the keys at our desk. The daily rate you see is what you pay,
                plus a <?= e(money(SECURITY_DEPOSIT)) ?> deposit you get back when the car comes home.</p>
            <div class="hero-actions">
                <a class="btn btn-brand btn-lg" href="<?= e(BASE_URL) ?>auth/register.php">Create an account to book</a>
                <a class="btn btn-outline-secondary btn-lg" href="<?= e(BASE_URL) ?>browse.php">Browse cars and prices</a>
            </div>
            <p class="hero-note">Prefer to talk to someone? Call <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', BUSINESS_PHONE)) ?>"><?= e(BUSINESS_PHONE) ?></a>.</p>
        </div>

        <figure class="rate-board" aria-labelledby="board-title">
            <figcaption>
                <span id="board-title">Today's board</span>
                <span class="board-date"><?= e(date('l, F j')) ?></span>
            </figcaption>
            <?php if (!$cars): ?>
                <p class="board-empty">The fleet is being set up. Check back soon.</p>
            <?php else: ?>
            <table>
                <thead class="visually-hidden"><tr><th>Car</th><th>Where it is</th><th>Per day</th></tr></thead>
                <tbody>
                <?php foreach ($cars as $c): [$state, $state_label] = board_state($c); ?>
                    <tr class="state-<?= e($state) ?>">
                        <th scope="row"><?= e($c['brand'] . ' ' . $c['model']) ?><span class="board-sub"><?= e(humanize($c['vehicle_type'])) ?>, <?= (int) $c['seating_capacity'] ?> seats, <?= e(strtolower(humanize($c['transmission']))) ?></span></th>
                        <td class="board-state"><span class="dot" aria-hidden="true"></span><?= e($state_label) ?></td>
                        <td class="board-rate"><?= e(money((float) $c['daily_rate'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="board-foot"><?= $on_lot ?> of <?= count($cars) ?> cars on the lot right now. Cars out today can still be booked for later dates. <a href="<?= e(BASE_URL) ?>browse.php">Check your dates</a></p>
            <?php endif; ?>
        </figure>
    </section>

    <section class="landing-section" aria-labelledby="how-title">
        <h2 id="how-title">How renting works</h2>
        <ol class="steps">
            <li>
                <h3>Create an account and upload your license</h3>
                <p>A photo or scan of your driver's license is enough. The desk checks it once; after that you can book any time.</p>
            </li>
            <li>
                <h3>Choose a car and your dates</h3>
                <p>You'll see every car that's free and the full price before you ask for it. Give us at least <?= ONLINE_BOOKING_LEAD_HOURS ?> hours' notice; the desk confirms your request and you get a notification.</p>
            </li>
            <li>
                <h3>Pay the deposit</h3>
                <p>At the desk, or by GCash or bank transfer from your account. You get a receipt for every payment.</p>
            </li>
            <li>
                <h3>Pick up, drive, bring it back</h3>
                <p>Bring your license. We note the fuel and odometer together, and do the same when you return. Then the deposit comes back to you.</p>
            </li>
        </ol>
    </section>

    <section class="landing-section" aria-labelledby="rules-title">
        <h2 id="rules-title">Good to know before you book</h2>
        <dl class="rules">
            <div><dt>Deposit</dt><dd><?= e(money(SECURITY_DEPOSIT)) ?>, refundable. Anything you owe at return (late hours, fuel, damage) comes out of it first, and the rest is handed back.</dd></div>
            <div><dt>Cancelling</dt><dd>Free while your request is waiting for confirmation, and free up to <?= FREE_CANCELLATION_HOURS ?> hours before pickup once it's confirmed. Later than that, it costs <?= CANCELLATION_FEE_DAYS ?> day<?= CANCELLATION_FEE_DAYS === 1 ? '' : 's' ?> of the rental.</dd></div>
            <div><dt>Returning late</dt><dd>The first <?= LATE_GRACE_MINUTES ?> minutes are on us. After that, each started hour is <?= LATE_FEE_HOURLY_PCT ?>% of the daily rate, never more than a full day's rate for each day late.</dd></div>
            <div><dt>Fuel</dt><dd>Bring it back with the same level you left with. A short tank is charged at <?= e(money(FUEL_CHARGE_PER_PERCENT)) ?> for each 1% missing.</dd></div>
            <div><dt>Longer rentals</dt><dd>Book <?= LONG_RENTAL_MIN_DAYS ?> days or more and the rate drops by <?= LONG_RENTAL_DISCOUNT_PCT ?>%, worked out for you.</dd></div>
            <div><dt>How long</dt><dd>One day up to <?= MAX_RENTAL_DAYS ?> days per booking, booked up to <?= MAX_ADVANCE_BOOKING_DAYS ?> days ahead.</dd></div>
            <div><dt>Who can rent</dt><dd>Anyone <?= MIN_RENTER_AGE ?> or older with a valid driver's license that doesn't expire before the car is due back.</dd></div>
        </dl>
    </section>

    <section class="landing-visit" aria-labelledby="visit-title">
        <div>
            <h2 id="visit-title">Find the desk</h2>
            <address>
                <?= e(BUSINESS_ADDRESS) ?><br>
                <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', BUSINESS_PHONE)) ?>"><?= e(BUSINESS_PHONE) ?></a><br>
                <a href="mailto:<?= e(BUSINESS_EMAIL) ?>"><?= e(BUSINESS_EMAIL) ?></a>
            </address>
        </div>
        <a class="btn btn-brand btn-lg" href="<?= e(BASE_URL) ?>auth/register.php">Create an account to book</a>
    </section>
</main>

<footer class="landing-foot">
    <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></span>
    <a href="<?= e(BASE_URL) ?>auth/login.php">Staff and customer log in</a>
</footer>

<script src="<?= e(BASE_URL) ?>assets/js/app.js"></script>
</body>
</html>
