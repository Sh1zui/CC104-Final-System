<?php
/**
 * Top of every authenticated page. The page sets these before including:
 *   $user         — array returned by require_role() (sidebar.php reads
 *                    $user['role_name'] and $user['full_name'] directly)
 *   $page_title   — shown in the tab and the topbar
 *   $active_nav   — key of the sidebar item to highlight (see sidebar.php)
 * Pair with includes/footer.php at the bottom of the page.
 */
$page_title = $page_title ?? 'Dashboard';
$active_nav = $active_nav ?? '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($page_title) ?> — <?= e(APP_NAME) ?></title>

    <script>
        // Needed by any page script that calls the api/ endpoints — a
        // plain relative fetch('api/vehicles.php') would resolve against
        // the *current* page's URL (e.g. admin/api/...), not the project
        // root, since api/ is a sibling of admin/, not nested under it.
        window.APP_BASE_URL = <?= json_encode(BASE_URL) ?>;
        // Who is looking (UI hints only — every rule is enforced server-side)
        // and where this role's copy of each shared page lives.
        window.APP_ROLE = <?= json_encode($user['role_name']) ?>;
        window.APP_LINKS = <?= json_encode(array_map(fn ($p) => BASE_URL . $p, PORTAL_PAGES[$user['role_name']] ?? []), JSON_UNESCAPED_SLASHES) ?>;

        // Runs before first paint so a saved theme doesn't flash the wrong colors.
        (function () {
            try {
                var saved = localStorage.getItem('autoway-theme');
                var root  = document.documentElement;
                var palette = localStorage.getItem('autoway-palette');
                if (palette && /^(bay|pine|jeepney|orchid)$/.test(palette)) root.setAttribute('data-palette', palette);
                if (saved) {
                    root.setAttribute('data-theme', saved);
                    root.setAttribute('data-bs-theme', saved);
                } else if (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) {
                    root.setAttribute('data-bs-theme', 'dark');
                }
            } catch (e) {}
        })();
    </script>

    <link rel="icon" href="<?= e(BASE_URL) ?>assets/img/favicon.svg?v=autoway-1" type="image/svg+xml">
    <link rel="icon" href="<?= e(BASE_URL) ?>assets/img/favicon.ico?v=autoway-1" sizes="48x48">
    <link rel="apple-touch-icon" href="<?= e(BASE_URL) ?>assets/img/apple-touch-icon.png?v=autoway-1">
    <link href="<?= e(BASE_URL) ?>assets/vendor/fonts/fonts.css" rel="stylesheet">
    <link href="<?= e(BASE_URL) ?>assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e(BASE_URL) ?>assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(BASE_URL) ?>assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php
// Quick tour: once for new users, on their dashboard; ?tour=1 replays it.
require_once __DIR__ . '/guide.php';
$tour_steps = [];
if ($active_nav === 'dashboard') {
    $tour_replay = ($_GET['tour'] ?? '') === '1';
    if ($tour_replay || tour_pending((int) $user['user_id'])) {
        $tour_steps = tour_steps($user['role_name'], explode(' ', trim($user['full_name']))[0] ?: $user['full_name']);
    }
}
?>
<?php if ($tour_steps): ?>
<script type="application/json" id="tour-data"><?= json_encode(['steps' => $tour_steps, 'replay' => $tour_replay], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>
<div class="app-shell">

    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

    <div class="main-column">
        <header class="topbar">
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="icon-btn sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
                    <i class="bi bi-list"></i>
                </button>
                <h1 class="topbar-title"><?= e($page_title) ?></h1>
            </div>
            <div class="topbar-actions">
                <?php $notif_unread = unread_notification_count((int) $user['user_id']); ?>
                <a href="<?= e(BASE_URL) ?>auth/notifications.php" class="icon-btn notif-btn<?= $active_nav === 'notifications' ? ' active' : '' ?>"
                   aria-label="Notifications<?= $notif_unread ? ', ' . $notif_unread . ' unread' : '' ?>" title="Notifications">
                    <i class="bi bi-bell"></i><?php if ($notif_unread): ?><span class="notif-count"><?= $notif_unread > 99 ? '99+' : $notif_unread ?></span><?php endif; ?>
                </a>
                <div class="dropdown">
                    <button type="button" class="icon-btn" id="palette-btn" data-bs-toggle="dropdown" aria-expanded="false"
                            aria-label="Color palette" title="Color palette">
                        <i class="bi bi-palette"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end palette-menu" aria-labelledby="palette-btn">
                        <p class="palette-menu-title">Color palette</p>
                        <?php foreach (['bay' => ['Bay', 'Calapan Bay teal and mango'], 'pine' => ['Pine', 'Forest green and gold'],
                                        'jeepney' => ['Jeepney', 'Royal blue and sun yellow'], 'orchid' => ['Orchid', 'Violet and marigold']] as $pk => [$pname, $pdesc]): ?>
                            <button type="button" class="dropdown-item palette-option" data-palette="<?= $pk ?>" aria-pressed="false">
                                <span class="palette-swatch" aria-hidden="true"><i></i><i></i><i></i></span>
                                <span><span class="palette-name"><?= $pname ?></span><span class="palette-desc"><?= $pdesc ?></span></span>
                                <i class="bi bi-check2 palette-check" aria-hidden="true"></i>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="button" class="icon-btn" id="theme-toggle" aria-label="Switch light/dark theme">
                    <i class="bi bi-moon-stars"></i>
                </button>
                <form method="post" action="<?= e(BASE_URL) ?>auth/logout.php" class="d-inline m-0">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-outline-secondary logout-btn" aria-label="Log out">
                        <i class="bi bi-box-arrow-right" aria-hidden="true"></i><span class="logout-label">Log out</span>
                    </button>
                </form>
            </div>
        </header>

        <main class="content">
            <?php foreach (['success' => 'success', 'error' => 'warning'] as $flash_key => $alert_type): ?>
                <?php if ($flash_message = flash($flash_key)): ?>
                    <div class="alert alert-<?= $alert_type ?> alert-dismissible fade show" role="alert">
                        <?= e($flash_message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
