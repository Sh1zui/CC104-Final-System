<?php
/**
 * The full guide, for every role (sidebar → Help → Guide).
 * Step-by-step guides for each task this role does, a contents list to
 * jump between them, and a button to replay the quick tour on the
 * dashboard. Content lives in includes/guide.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/guide.php';

$user = require_login();
$sections = guide_sections($user['role_name']);
$role_label = ['admin' => 'admins', 'staff' => 'the front desk', 'customer' => 'customers'][$user['role_name']] ?? 'you';

$page_title   = 'Guide';
$active_nav   = 'guide';
$page_scripts = [BASE_URL . 'assets/js/guide.js'];

require __DIR__ . '/../includes/header.php';
?>

<section class="guide-hero">
    <div>
        <h2>How to use <?= e(APP_NAME) ?></h2>
        <p>Short guides for <?= e($role_label) ?>, one task at a time. Pick a topic, or start from the top.</p>
    </div>
    <a class="btn btn-brand" href="<?= e(BASE_URL . dashboard_path_for($user['role_name'])) ?>?tour=1">
        <i class="bi bi-play-circle me-1" aria-hidden="true"></i>Replay the quick tour
    </a>
</section>

<div class="guide-layout">
    <nav class="guide-toc" aria-label="Guide contents">
        <p class="guide-toc-title">Contents</p>
        <ol>
            <?php foreach ($sections as $sec): ?>
                <li><a href="#<?= e($sec['id']) ?>"><i class="bi <?= e($sec['icon']) ?>" aria-hidden="true"></i><?= e($sec['title']) ?></a></li>
            <?php endforeach; ?>
        </ol>
    </nav>

    <div class="guide-sections">
        <?php foreach ($sections as $n => $sec): ?>
            <section class="panel guide-section" id="<?= e($sec['id']) ?>" aria-labelledby="<?= e($sec['id']) ?>-title">
                <div class="guide-section-head">
                    <span class="guide-icon" aria-hidden="true"><i class="bi <?= e($sec['icon']) ?>"></i></span>
                    <div>
                        <h3 id="<?= e($sec['id']) ?>-title"><?= e($sec['title']) ?></h3>
                        <p><?= guide_text($sec['intro']) ?></p>
                    </div>
                </div>
                <ol class="guide-steps">
                    <?php foreach ($sec['steps'] as $step): ?>
                        <li><?= guide_text($step) ?></li>
                    <?php endforeach; ?>
                </ol>
                <?php if (!empty($sec['tip'])): ?>
                    <p class="guide-tip"><i class="bi bi-lightbulb" aria-hidden="true"></i><span><?= guide_text($sec['tip']) ?></span></p>
                <?php endif; ?>
                <div class="guide-section-foot">
                    <?php if ($n < count($sections) - 1): ?>
                        <a href="#<?= e($sections[$n + 1]['id']) ?>">Next: <?= e($sections[$n + 1]['title']) ?></a>
                    <?php else: ?>
                        <a href="#top-of-guide" data-scroll-top>Back to the top</a>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
        <p class="text-secondary small">Still stuck? <?= $user['role_name'] === 'customer'
            ? 'Call the desk at ' . e(BUSINESS_PHONE) . ' or email ' . e(BUSINESS_EMAIL) . '.'
            : 'Ask an admin, or check the README that came with the system.' ?></p>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
