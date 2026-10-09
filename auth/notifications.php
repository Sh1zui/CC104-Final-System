<?php
/**
 * Notifications for any role (the bell in the top bar). Opening the page
 * marks everything read; each item links to what it's about when there's
 * a page for it (a booking, a customer's documents, a maintenance job).
 */
require_once __DIR__ . '/../includes/auth.php';

$user = require_login();
$uid = (int) $user['user_id'];

$stmt = Database::getConnection()->prepare(
    'SELECT notification_id, title, message, target, is_read, created_at FROM notifications
     WHERE user_id = ? ORDER BY created_at DESC, notification_id DESC LIMIT 60'
);
$stmt->execute([$uid]);
$items = $stmt->fetchAll();
Database::getConnection()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$uid]);

$page_title = 'Notifications';
$active_nav = 'notifications';

require __DIR__ . '/../includes/header.php';
?>

<section class="panel notif-panel">
    <div class="panel-header">Latest updates <span class="text-secondary small fw-normal">the newest 60</span></div>
    <div class="panel-body no-pad">
        <?php if (!$items): ?>
            <p class="text-secondary p-3 mb-0">Nothing yet. Bookings, payments, and document reviews that need your attention will show up here.</p>
        <?php else: ?>
            <ul class="notice-list">
            <?php foreach ($items as $n): $url = notification_url($n['target'], $user['role_name']); ?>
                <li class="<?= (int) $n['is_read'] ? '' : 'is-unread' ?>">
                    <div class="d-flex justify-content-between gap-3 align-items-start">
                        <div>
                            <div class="notice-title"><?= e($n['title']) ?><?= (int) $n['is_read'] ? '' : ' <span class="visually-hidden">(new)</span>' ?></div>
                            <div class="notice-text"><?= e($n['message']) ?></div>
                            <div class="cell-sub"><?= e(format_datetime_short($n['created_at'])) ?></div>
                        </div>
                        <?php if ($url): ?><a class="btn btn-sm btn-outline-secondary flex-shrink-0" href="<?= e($url) ?>">Open</a><?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
