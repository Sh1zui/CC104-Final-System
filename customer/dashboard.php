<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/portal_partial.php';

$user = require_role('customer');
$customer = portal_customer($user);
$account_status = $customer['account_status'];
$next = next_own_booking($customer);
$notes = customer_notifications((int) $user['user_id']);
$unread = count(array_filter($notes, fn ($n) => !(int) $n['is_read']));

$rejected = 0;
foreach (customer_documents((int) $customer['customer_id']) as $doc) {
    $rejected += $doc['verification_status'] === 'rejected' ? 1 : 0;
}
$pending_docs = (int) $customer['pending_documents'];

$page_title = 'My account';
$active_nav = 'dashboard';
$page_scripts = [BASE_URL . 'assets/js/portal-home.js'];

require __DIR__ . '/../includes/header.php';
?>

<p class="text-secondary mb-3">Hi, <?= e(explode(' ', $user['full_name'])[0]) ?>.</p>

<?php if ($account_status === 'blocked'): ?>
<div class="alert alert-danger" role="status">
    <strong>Your account is on hold</strong> and can't make bookings. Please contact the rental desk.
</div>
<?php elseif ($account_status !== 'verified'): ?>
<?php
// What's left before the desk can verify the account, in order.
$has_license_details = !empty($customer['license_number']) && !empty($customer['license_expiry']);
$steps = [
    [$has_license_details, 'Add your driver\'s license number and expiry date', BASE_URL . 'customer/profile.php', 'Profile'],
    [$pending_docs > 0 || (int) $customer['approved_documents'] > 0,
        $rejected > 0 && $pending_docs === 0 ? 'Upload a new photo of your license (one was rejected; see why)' : 'Upload a photo or scan of your license',
        BASE_URL . 'customer/documents.php', 'My documents'],
    [false, 'The desk checks them and verifies your account. You\'ll get an update here.', null, null],
];
?>
<section class="panel verify-steps">
    <div class="panel-header">Before your first booking</div>
    <ol class="panel-body mb-0">
        <?php foreach ($steps as [$done, $text, $href, $link]): ?>
            <li class="<?= $done ? 'is-done' : '' ?>">
                <i class="bi <?= $done ? 'bi-check-circle-fill' : 'bi-circle' ?>" aria-hidden="true"></i>
                <span><?= e($text) ?><?= $done ? ' <span class="visually-hidden">(done)</span>' : '' ?>
                <?php if (!$done && $href): ?> &mdash; <a href="<?= e($href) ?>"><?= e($link) ?></a><?php endif; ?></span>
            </li>
        <?php endforeach; ?>
    </ol>
</section>
<?php endif; ?>

<div class="panel-grid-2">
    <section class="panel">
        <div class="panel-header"><?= $next && $next['booking_status'] === 'active' ? 'Your current rental' : 'Your next booking' ?>
            <a class="panel-link" href="<?= e(BASE_URL) ?>customer/my-bookings.php">All bookings</a></div>
        <div class="panel-body">
        <?php if ($next): ?>
            <div class="next-booking">
                <div class="next-booking-car"><?= e($next['brand'] . ' ' . $next['model']) ?> <span class="text-secondary fw-normal"><?= (int) $next['year'] ?></span></div>
                <div class="mono small text-secondary mb-2"><?= e($next['booking_reference']) ?></div>
                <div class="detail-grid">
                    <div><div class="detail-label">Pickup</div><div><?= e(format_datetime($next['pickup_datetime'])) ?></div></div>
                    <div><div class="detail-label">Return</div><div><?= e(format_datetime($next['return_datetime'])) ?></div></div>
                </div>
                <p class="mt-3 mb-3"><?= own_booking_badge($next) ?>
                    <?= e(own_booking_next_step($next)) ?></p>
                <a class="btn btn-brand btn-sm" href="<?= e(BASE_URL . 'customer/my-bookings.php?open=' . (int) $next['booking_id']) ?>">View booking</a>
            </div>
        <?php else: ?>
            <p class="text-secondary">Nothing booked right now.</p>
            <a class="btn btn-brand btn-sm" href="<?= e(BASE_URL) ?>customer/browse.php"><i class="bi bi-car-front me-1"></i>Find a car</a>
        <?php endif; ?>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">Updates
            <?php if ($unread): ?><button type="button" class="btn btn-link btn-sm p-0 panel-link" id="mark-read"><?= $unread ?> new &middot; mark read</button><?php endif; ?>
        </div>
        <div class="panel-body no-pad">
            <?php if (!$notes): ?>
                <p class="text-secondary p-3 mb-0">Booking confirmations, payment receipts, and document reviews will show up here.</p>
            <?php else: ?>
                <ul class="notice-list">
                <?php foreach ($notes as $n): ?>
                    <li class="<?= (int) $n['is_read'] ? '' : 'is-unread' ?>">
                        <?php $url = notification_url($n['target'], 'customer'); ?>
                        <div class="notice-title"><?= $url ? '<a href="' . e($url) . '">' . e($n['title']) . '</a>' : e($n['title']) ?></div>
                        <div class="notice-text"><?= e($n['message']) ?></div>
                        <div class="cell-sub"><?= e(format_datetime_short($n['created_at'])) ?></div>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="panel">
    <div class="panel-header">Account</div>
    <div class="panel-body">
        <div class="detail-grid">
            <div><div class="detail-label">Name</div><div><?= e($user['full_name']) ?></div></div>
            <div><div class="detail-label">Email</div><div><?= e($user['email']) ?></div></div>
            <div><div class="detail-label">Status</div><div><span class="status-badge <?= e(status_badge_class($account_status)) ?>"><?= e($account_status) ?></span></div></div>
            <div><div class="detail-label">License expires</div><div><?= $customer['license_expiry'] ? e(date('M j, Y', strtotime($customer['license_expiry']))) : '<span class="text-secondary">not on file</span>' ?></div></div>
        </div>
        <div class="d-flex gap-2 flex-wrap mt-3">
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(BASE_URL) ?>customer/profile.php">Edit profile</a>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(BASE_URL) ?>customer/documents.php">My documents</a>
        </div>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
