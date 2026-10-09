<?php
/**
 * Users and staff (Phase 13): every login in the system. Admins create
 * staff/admin accounts, edit them, suspend or reactivate any login, and set
 * new passwords. Customer profiles themselves live on the Customers page.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/users_data.php';

$user = require_role('admin');
$users = list_users();
$counts = ['admin' => 0, 'staff' => 0, 'customer' => 0, 'suspended' => 0];
foreach ($users as $u) {
    $counts[$u['role_name']]++;
    $counts['suspended'] += $u['status'] !== 'active' ? 1 : 0;
}

$page_title   = 'Users and staff';
$active_nav   = 'users';
$page_scripts = [BASE_URL . 'assets/js/datatable.js', BASE_URL . 'assets/js/users.js'];

require __DIR__ . '/../includes/header.php';
?>

<div class="stat-grid">
    <div class="stat-tile" style="--tile-accent: var(--accent)"><div class="stat-label">Admins</div><div class="stat-value"><?= $counts['admin'] ?></div><div class="stat-sub">Full access</div></div>
    <div class="stat-tile" style="--tile-accent: var(--info)"><div class="stat-label">Staff</div><div class="stat-value"><?= $counts['staff'] ?></div><div class="stat-sub">Front desk</div></div>
    <div class="stat-tile" style="--tile-accent: var(--neutral)"><div class="stat-label">Customers</div><div class="stat-value"><?= $counts['customer'] ?></div><div class="stat-sub">Logins for the portal</div></div>
    <div class="stat-tile" style="--tile-accent: var(--danger)"><div class="stat-label">Suspended</div><div class="stat-value"><?= $counts['suspended'] ?></div><div class="stat-sub">Can't log in</div></div>
</div>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <p class="text-secondary mb-0">Customer details and verification are on <a href="<?= e(BASE_URL) ?>admin/customers.php">Customers</a>.</p>
    <button type="button" class="btn btn-brand" id="new-user-btn"><i class="bi bi-person-plus me-1"></i>Add staff or admin</button>
</div>

<section class="panel">
    <div class="table-toolbar">
        <input type="search" class="form-control form-control-sm search-box" id="user-search" placeholder="Search name, username, or email&hellip;" aria-label="Search accounts">
        <select class="form-select form-select-sm filter-select" id="user-filter-role" aria-label="Filter by role">
            <option value="">All roles</option><option value="admin">Admins</option><option value="staff">Staff</option><option value="customer">Customers</option>
        </select>
        <select class="form-select form-select-sm filter-select" id="user-filter-status" aria-label="Filter by status">
            <option value="">Any status</option><option value="active">Active</option><option value="suspended">Suspended</option>
        </select>
    </div>
    <div class="panel-body no-pad">
        <table class="data-table" id="user-table" data-datatable data-page-size="15">
            <thead><tr><th data-sort="text">Name</th><th data-sort="text">Email</th><th data-sort="text">Role</th><th data-sort="text">Status</th><th data-sort="text">Last login</th><th></th></tr></thead>
            <tbody id="user-table-body"><?= render_user_rows($users, (int) $user['user_id']) ?></tbody>
        </table>
    </div>
    <div class="dt-pager" id="user-pager"></div>
</section>

<div class="modal fade" id="user-modal" tabindex="-1" aria-labelledby="user-modal-title" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <div><h2 class="modal-title h5" id="user-modal-title">Add staff or admin</h2><div id="user-status-line" class="small mt-1"></div></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="user-errors" class="alert alert-danger d-none" role="alert"></div>
        <form id="user-form" novalidate>
          <div class="row g-3">
            <div class="col-sm-6"><label class="form-label" for="u-name">Full name</label><input class="form-control" id="u-name" maxlength="120" required></div>
            <div class="col-sm-6"><label class="form-label" for="u-email">Email</label><input type="email" class="form-control" id="u-email" maxlength="120" required></div>
            <div class="col-sm-6"><label class="form-label" for="u-phone">Phone</label><input type="tel" class="form-control" id="u-phone" maxlength="20"></div>
            <div class="col-sm-6" data-staff-only><label class="form-label" for="u-role">Role</label>
              <select class="form-select" id="u-role"><option value="staff">Staff — front desk</option><option value="admin">Admin — everything</option></select></div>
            <div class="col-sm-6" data-create-only><label class="form-label" for="u-username">Username</label><input class="form-control mono" id="u-username" maxlength="50" autocomplete="off"></div>
            <div class="col-sm-6" data-create-only><label class="form-label" for="u-password">First password</label><input type="text" class="form-control mono" id="u-password" autocomplete="off">
              <div class="form-text">8+ characters with a letter and a number.</div></div>
          </div>
          <div class="d-flex justify-content-end gap-2 mt-3">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-brand" id="u-submit">Create account</button>
          </div>
        </form>

        <div id="user-manage" class="d-none">
          <h3 class="form-section-title">Access</h3>
          <div class="d-flex gap-2 flex-wrap" id="user-access"></div>
          <form id="pw-form" class="action-panel" novalidate>
            <label class="form-label" for="u-newpw">Set a new password</label>
            <div class="d-flex gap-2 flex-wrap">
              <input type="text" class="form-control mono" id="u-newpw" autocomplete="off" placeholder="8+ characters, a letter and a number">
              <button type="submit" class="btn btn-outline-secondary">Set password</button>
            </div>
          </form>
          <p class="small text-secondary mt-3 mb-0" id="user-meta"></p>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
