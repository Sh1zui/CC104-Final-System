<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/portal_partial.php';

// Visitors, staff, and admins can browse too, on the public page (same
// search, no Book button). Customers stay here, where booking works.
$viewer = current_user();
if (!$viewer || $viewer['role_name'] !== 'customer') {
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    redirect('browse.php' . ($qs !== '' ? '?' . $qs : ''));
}
$user = require_role('customer');
$customer = portal_customer($user);
$blockers = portal_booking_blockers($customer);
$vehicles = portal_catalog();

$page_title   = 'Browse vehicles';
$active_nav   = 'browse';
$page_scripts = [BASE_URL . 'assets/js/portal.js'];

$min_pickup = (new DateTimeImmutable('+' . ONLINE_BOOKING_LEAD_HOURS . ' hours'))->format('Y-m-d\TH:i');
$max_pickup = (new DateTimeImmutable('+' . MAX_ADVANCE_BOOKING_DAYS . ' days'))->format('Y-m-d\TH:i');

require __DIR__ . '/../includes/header.php';
?>

<form class="panel search-panel" id="car-search" novalidate>
    <div class="search-fields">
        <div>
            <label class="form-label" for="s-pickup">Pickup</label>
            <input type="datetime-local" class="form-control" id="s-pickup" name="pickup" min="<?= e($min_pickup) ?>" max="<?= e($max_pickup) ?>">
        </div>
        <div>
            <label class="form-label" for="s-return">Return</label>
            <input type="datetime-local" class="form-control" id="s-return" name="return" min="<?= e($min_pickup) ?>">
        </div>
        <div>
            <label class="form-label" for="s-type">Type</label>
            <select class="form-select" id="s-type" name="type">
                <option value="">Any</option>
                <?php foreach (VEHICLE_TYPES as $t): ?><option value="<?= e($t) ?>"><?= e(humanize($t)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="s-transmission">Transmission</label>
            <select class="form-select" id="s-transmission" name="transmission">
                <option value="">Any</option>
                <?php foreach (TRANSMISSIONS as $t): ?><option value="<?= e($t) ?>"><?= e(humanize($t)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="s-seats">Seats</label>
            <select class="form-select" id="s-seats" name="min_seats">
                <option value="">Any</option>
                <option value="5">5 or more</option>
                <option value="7">7 or more</option>
            </select>
        </div>
        <div class="search-submit">
            <button type="submit" class="btn btn-brand w-100"><i class="bi bi-search me-1"></i>Check availability</button>
        </div>
    </div>
    <p class="form-text mb-0 mt-2">Pickups need at least <?= ONLINE_BOOKING_LEAD_HOURS ?> hours' notice. Prices include everything except a
        <?= e(money(SECURITY_DEPOSIT)) ?> refundable deposit; rentals of <?= LONG_RENTAL_MIN_DAYS ?>+ days get <?= LONG_RENTAL_DISCOUNT_PCT ?>% off.</p>
</form>

<div id="book-blockers" class="alert alert-warning<?= $blockers ? '' : ' d-none' ?>" role="status">
    <?php if ($blockers): ?>
        <strong>You can look around, but you can't book yet.</strong> <?= e(implode(' ', $blockers)) ?>
        <?php if ($customer['account_status'] === 'unverified'): ?>
            <a href="<?= e(BASE_URL) ?>customer/documents.php">Upload your documents</a>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="d-flex justify-content-between align-items-baseline mb-2">
    <p class="text-secondary mb-0" id="results-line"><?= count($vehicles) ?> cars in our fleet. Pick dates to see what's free and the total price.</p>
</div>
<div class="car-grid" id="car-grid" aria-live="polite"><?= render_vehicle_cards($vehicles, null, false) ?></div>

<div class="modal fade" id="book-modal" tabindex="-1" aria-labelledby="book-modal-title" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="book-form" novalidate>
      <div class="modal-header">
        <h2 class="modal-title h5" id="book-modal-title">Book</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2" id="book-when"></p>
        <div class="quote-box" id="book-quote"></div>
        <label class="form-label mt-3" for="book-notes">Anything we should know? <span class="text-secondary fw-normal">(optional)</span></label>
        <textarea class="form-control" id="book-notes" rows="2" maxlength="500" placeholder="e.g. child seat, arriving on a late flight"></textarea>
        <ul class="small text-secondary mt-3 mb-0 ps-3">
          <li>This sends a request. The desk confirms it, usually within business hours, and you'll get a notification.</li>
          <li>Pay the deposit at the desk or by GCash / bank transfer from <em>My bookings</em>.</li>
          <li>Cancelling is free until confirmed, and up to <?= FREE_CANCELLATION_HOURS ?> hours before pickup after that.</li>
          <li>Bring your driver's license at pickup.</li>
        </ul>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Not now</button>
        <button type="submit" class="btn btn-brand" id="book-submit">Request booking</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
