<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/portal_partial.php';

$user = require_role('customer');
$customer = portal_customer($user);
$bookings = list_own_bookings($customer);

$page_title   = 'My bookings';
$active_nav   = 'bookings';
$page_scripts = [BASE_URL . 'assets/js/datatable.js', BASE_URL . 'assets/js/portal.js'];

require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <p class="text-secondary mb-0">Your requests, upcoming rentals, and history. Open one to pay, cancel, or print receipts.</p>
    <a class="btn btn-brand" href="<?= e(BASE_URL) ?>customer/browse.php"><i class="bi bi-plus-lg me-1"></i>Book a car</a>
</div>

<section class="panel">
    <div class="table-toolbar">
        <select class="form-select form-select-sm filter-select" id="mb-filter-when" aria-label="Show">
            <option value="">All bookings</option>
            <option value="upcoming">Upcoming and current</option>
            <option value="past">Past and cancelled</option>
        </select>
    </div>
    <div class="panel-body no-pad">
        <table class="data-table" id="mb-table" data-datatable data-page-size="10">
            <thead>
                <tr>
                    <th data-sort="text">Car</th>
                    <th data-sort="text">Dates</th>
                    <th data-sort="number" class="text-end">Total</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="mb-table-body"><?= render_own_booking_rows($bookings) ?></tbody>
        </table>
    </div>
    <div class="dt-pager" id="mb-pager"></div>
</section>

<div class="modal fade" id="ob-modal" tabindex="-1" aria-labelledby="ob-title" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h2 class="modal-title h5" id="ob-title">Booking</h2>
          <div id="ob-status" class="small mt-1"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="ob-errors" class="alert alert-danger d-none" role="alert"></div>
        <div id="ob-detail"></div>
        <div id="ob-actions" class="booking-actions"></div>

        <form id="ob-pay-panel" class="action-panel d-none" novalidate>
          <h3 class="form-section-title">Tell us about a payment you sent</h3>
          <div class="pay-instructions small mb-3">
            <div><strong>GCash:</strong> <span class="mono"><?= e(BUSINESS_GCASH_NUMBER) ?></span></div>
            <div><strong>Bank transfer:</strong> <?= e(BUSINESS_BANK_ACCOUNT) ?></div>
            <div class="text-secondary mt-1">Send the money first, then enter the reference number from your GCash or bank receipt. It counts as paid once we've checked it — you'll get a receipt.</div>
          </div>
          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label" for="op-type">What it's for</label>
              <select class="form-select" id="op-type"></select>
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="op-amount">Amount sent (&#8369;)</label>
              <input type="number" class="form-control mono" id="op-amount" min="1" step="0.01" required>
            </div>
            <div class="col-sm-4">
              <label class="form-label" for="op-method">Sent by</label>
              <select class="form-select" id="op-method">
                <option value="gcash">GCash</option>
                <option value="bank_transfer">Bank transfer</option>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="form-label" for="op-ref">Reference number</label>
              <input type="text" class="form-control mono" id="op-ref" maxlength="80" required autocomplete="off">
            </div>
            <div class="col-sm-4">
              <label class="form-label" for="op-date">Date sent</label>
              <input type="date" class="form-control" id="op-date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>" required>
            </div>
          </div>
          <div class="d-flex justify-content-end gap-2 mt-3">
            <button type="button" class="btn btn-outline-secondary" data-close-panel>Not now</button>
            <button type="submit" class="btn btn-brand">Send</button>
          </div>
        </form>

        <div id="ob-cancel-panel" class="action-panel d-none">
          <h3 class="form-section-title">Cancel this booking</h3>
          <p id="ob-cancel-note" class="mb-2"></p>
          <label class="form-label" for="ob-cancel-reason">Reason <span class="text-secondary fw-normal">(optional)</span></label>
          <div class="d-flex gap-2 flex-wrap">
            <input type="text" class="form-control" id="ob-cancel-reason" maxlength="255">
            <button type="button" class="btn btn-danger" id="ob-cancel-confirm">Cancel booking</button>
            <button type="button" class="btn btn-outline-secondary" data-close-panel>Keep it</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
