<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/desk_partial.php';
require_once __DIR__ . '/../includes/portal_partial.php';

$user = require_role('staff');

$releases = release_queue();
$returns  = return_queue();
$counts   = desk_counts($releases, $returns);
$fleet    = fleet_board();
$online_waiting = open_submissions();

$today_releases = array_values(array_filter($releases, fn ($b) => $b['day'] === 'today'));
$due_returns    = array_values(array_filter($returns, fn ($b) => $b['day'] !== 'later'));

$page_title = 'Today';
$active_nav = 'dashboard';

require __DIR__ . '/../includes/header.php';
?>

<p class="text-secondary mb-3"><?= e(date('l, F j')) ?> &middot; signed in as <?= e($user['full_name']) ?></p>

<section class="stat-grid" aria-label="Today at the desk">
    <a class="stat-tile stat-link" href="<?= e(portal_url('checkout')) ?>" style="--tile-accent: var(--info)">
        <div class="stat-label">Pickups today</div>
        <div class="stat-value"><?= $counts['pickups_today'] ?></div>
        <div class="stat-sub"><?= $counts['ready_now'] ?> ready to release now</div>
    </a>
    <a class="stat-tile stat-link" href="<?= e(portal_url('checkin')) ?>" style="--tile-accent: var(--accent)">
        <div class="stat-label">Returns due</div>
        <div class="stat-value"><?= $counts['returns_today'] ?></div>
        <div class="stat-sub"><?= $counts['out_now'] ?> car<?= $counts['out_now'] === 1 ? '' : 's' ?> out in total</div>
    </a>
    <a class="stat-tile stat-link" href="<?= e(portal_url('checkin')) ?>" style="--tile-accent: var(--danger)">
        <div class="stat-label">Overdue</div>
        <div class="stat-value"><?= $counts['overdue'] ?></div>
        <div class="stat-sub">Past their return time</div>
    </a>
    <a class="stat-tile stat-link" href="<?= e(portal_url('bookings')) ?>" style="--tile-accent: var(--warning)">
        <div class="stat-label">Waiting to confirm</div>
        <div class="stat-value"><?= $counts['pending'] ?></div>
        <div class="stat-sub"><?= $counts['in_workshop'] ?> car<?= $counts['in_workshop'] === 1 ? '' : 's' ?> in the workshop</div>
    </a>
</section>

<div class="panel-grid-2">
    <section class="panel">
        <div class="panel-header">Pickups today <a class="panel-link" href="<?= e(portal_url('checkout')) ?>">All pickups</a></div>
        <div class="panel-body no-pad">
            <table class="data-table fit">
                <thead><tr><th>Time</th><th>Customer and car</th><th></th></tr></thead>
                <tbody><?= render_release_rows($today_releases, true) ?></tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">Due back <a class="panel-link" href="<?= e(portal_url('checkin')) ?>">All rentals out</a></div>
        <div class="panel-body no-pad">
            <table class="data-table fit">
                <thead><tr><th>Due</th><th>Customer and car</th><th></th></tr></thead>
                <tbody><?= $due_returns ? render_return_rows($due_returns, true)
                    : '<tr class="empty-row"><td colspan="3">Nothing due back today.</td></tr>' ?></tbody>
            </table>
        </div>
    </section>
</div>

<?php if ($online_waiting): ?>
<section class="panel">
    <div class="panel-header">Online payments to check
        <span class="text-secondary small fw-normal">customers say they sent these by GCash or bank transfer</span></div>
    <div class="panel-body no-pad">
        <table class="data-table">
            <thead><tr><th>Customer</th><th>Sent by</th><th class="text-end">Amount</th><th></th></tr></thead>
            <tbody><?= render_open_submission_rows($online_waiting) ?></tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="panel">
    <div class="panel-header">Fleet right now</div>
    <div class="panel-body no-pad">
        <table class="data-table">
            <thead><tr><th>Vehicle</th><th>Status</th><th>Where it is</th><th>Next pickup</th></tr></thead>
            <tbody>
            <?php if (!$fleet): ?>
                <tr class="empty-row"><td colspan="4">No vehicles in the fleet yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($fleet as $v): ?>
                <?php
                $where = match (true) {
                    $v['out_until'] !== null      => (strtotime($v['out_until']) < time() ? '<span class="text-danger fw-semibold">Overdue</span> since ' : 'Out until ')
                                                     . e(format_datetime_short($v['out_until'])),
                    $v['workshop_until'] !== null => 'In the workshop, planned end ' . e(date('M j', strtotime($v['workshop_until']))),
                    $v['status'] === 'unavailable' => 'Taken out of service',
                    default                       => 'On the lot',
                };
                ?>
                <tr>
                    <td><span class="cell-name"><?= e($v['brand'] . ' ' . $v['model']) ?></span><div class="cell-sub"><span class="plate"><?= e($v['plate_number']) ?></span></div></td>
                    <td><span class="status-badge <?= e(status_badge_class($v['status'])) ?>"><?= e(humanize($v['status'])) ?></span></td>
                    <td><?= $where ?></td>
                    <td class="nowrap"><?= $v['next_pickup'] ? e(format_datetime_short($v['next_pickup'])) : '<span class="text-secondary">—</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
