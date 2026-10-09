<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vehicles_data.php';
require_once __DIR__ . '/../includes/vehicle_table_partial.php';

$user = require_role('admin');

$view     = ($_GET['view'] ?? '') === 'archived' ? 'archived' : 'active';
$vehicles = list_vehicles(['archived' => $view === 'archived']);

$page_title   = 'Vehicles';
$active_nav   = 'vehicles';
$page_scripts = [
    BASE_URL . 'assets/js/datatable.js',
    BASE_URL . 'assets/js/vehicles.js',
];

require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="btn-group" role="group" aria-label="View">
        <a href="<?= e(BASE_URL) ?>admin/vehicles.php" class="btn btn-sm <?= $view === 'active' ? 'btn-brand' : 'btn-outline-secondary' ?>">Active fleet</a>
        <a href="<?= e(BASE_URL) ?>admin/vehicles.php?view=archived" class="btn btn-sm <?= $view === 'archived' ? 'btn-brand' : 'btn-outline-secondary' ?>">Archived</a>
    </div>
    <?php if ($view === 'active'): ?>
        <button type="button" class="btn btn-brand" id="add-vehicle-btn"><i class="bi bi-plus-lg me-1"></i>Add vehicle</button>
    <?php endif; ?>
</div>

<section class="panel">
    <div class="table-toolbar">
        <input type="search" class="form-control form-control-sm search-box" id="vehicle-search" placeholder="Search brand, model, or plate&hellip;" aria-label="Search vehicles">
        <select class="form-select form-select-sm filter-select" id="vehicle-filter-status" aria-label="Filter by status">
            <option value="">All statuses</option>
            <?php foreach (VEHICLE_STATUSES as $s): ?>
                <option value="<?= e($s) ?>"><?= e(ucfirst($s)) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm filter-select" id="vehicle-filter-type" aria-label="Filter by type">
            <option value="">All types</option>
            <?php foreach (VEHICLE_TYPES as $t): ?>
                <option value="<?= e($t) ?>"><?= e(humanize($t)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="panel-body no-pad">
        <div class="table-scroll">
        <table class="data-table" id="vehicle-table" data-datatable data-page-size="8" data-archived="<?= $view === 'archived' ? '1' : '0' ?>">
            <thead>
                <tr>
                    <th data-sort="text">Vehicle</th>
                    <th data-sort="text">Plate</th>
                    <th data-sort="text">Type</th>
                    <th data-sort="number">Seats</th>
                    <th data-sort="number">Daily rate</th>
                    <th data-sort="text">Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody id="vehicle-table-body"><?= render_vehicle_rows($vehicles, $view === 'archived') ?></tbody>
        </table>
        </div>
    </div>
    <div class="dt-pager" id="vehicle-pager"></div>
</section>

<!-- Add/Edit modal -->
<div class="modal fade" id="vehicle-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="vehicle-form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" id="vehicle-form-action" value="create">
        <input type="hidden" name="vehicle_id" id="vehicle-form-id" value="">
        <div class="modal-header">
          <h2 class="modal-title h5" id="vehicle-modal-title">Add vehicle</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="vehicle-form-errors" class="alert alert-danger d-none"></div>

          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label" for="f-brand">Brand</label>
              <input type="text" class="form-control" id="f-brand" name="brand" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="f-model">Model</label>
              <input type="text" class="form-control" id="f-model" name="model" required>
            </div>
            <div class="col-sm-4">
              <label class="form-label" for="f-year">Year</label>
              <input type="number" class="form-control" id="f-year" name="year" min="1980" max="2100" required>
            </div>
            <div class="col-sm-4">
              <label class="form-label" for="f-plate">Plate number</label>
              <input type="text" class="form-control mono" id="f-plate" name="plate_number" required>
            </div>
            <div class="col-sm-4">
              <label class="form-label" for="f-color">Color</label>
              <input type="text" class="form-control" id="f-color" name="color">
            </div>

            <div class="col-sm-4">
              <label class="form-label" for="f-type">Vehicle type</label>
              <select class="form-select" id="f-type" name="vehicle_type" required>
                <?php foreach (VEHICLE_TYPES as $t): ?>
                  <option value="<?= e($t) ?>"><?= e(humanize($t)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="form-label" for="f-transmission">Transmission</label>
              <select class="form-select" id="f-transmission" name="transmission" required>
                <?php foreach (TRANSMISSIONS as $t): ?>
                  <option value="<?= e($t) ?>"><?= e(humanize($t)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="form-label" for="f-fuel">Fuel type</label>
              <select class="form-select" id="f-fuel" name="fuel_type" required>
                <?php foreach (FUEL_TYPES as $t): ?>
                  <option value="<?= e($t) ?>"><?= e(humanize($t)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-sm-4">
              <label class="form-label" for="f-seats">Seating capacity</label>
              <input type="number" class="form-control" id="f-seats" name="seating_capacity" min="1" max="60" required>
            </div>
            <div class="col-sm-4">
              <label class="form-label" for="f-mileage">Mileage (km)</label>
              <input type="number" class="form-control" id="f-mileage" name="mileage_km" min="0">
            </div>
            <div class="col-sm-4">
              <label class="form-label" for="f-rate">Daily rate (&#8369;)</label>
              <input type="number" class="form-control" id="f-rate" name="daily_rate" min="0" step="0.01" required>
            </div>

            <div class="col-sm-6">
              <label class="form-label" for="f-status">Status</label>
              <select class="form-select" id="f-status" name="status" required>
                <?php foreach (VEHICLE_STATUSES as $s): ?>
                  <option value="<?= e($s) ?>"><?= e(ucfirst($s)) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">&ldquo;Rented&rdquo; and &ldquo;Maintenance&rdquo; are set automatically by check-out/check-in and by maintenance jobs.</div>
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="f-description">Description</label>
              <textarea class="form-control" id="f-description" name="description" rows="1"></textarea>
            </div>
          </div>

          <hr>

          <div id="vehicle-image-section">
            <h3 class="h6">Photos</h3>
            <div id="image-manager-target"><p class="text-secondary small">Save the vehicle first, then add photos.</p></div>
            <div id="image-upload-row" class="d-none">
                <label for="image-upload-input" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-upload me-1"></i>Upload photo
                </label>
                <input type="file" id="image-upload-input" accept="image/jpeg,image/png,image/webp" class="d-none">
                <span class="text-secondary small ms-2">JPEG, PNG, or WebP, up to 5&nbsp;MB.</span>
            </div>
          </div>

          <div id="vehicle-extra-info"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-brand" id="vehicle-form-submit">Save vehicle</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
