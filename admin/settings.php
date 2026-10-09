<?php
/**
 * Business settings (Phase 13): details printed on paperwork, prices, and
 * the booking / handover / maintenance rules. Plain form post
 * (POST → redirect → GET). Admin only.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings_data.php';

$user = require_role('admin');
$errors = [];
$submitted = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
        redirect('admin/settings.php');
    }
    $input = array_intersect_key($_POST, SETTINGS_SCHEMA);
    if (isset($_POST['reset_group']) && $_POST['reset_group'] !== '') {
        // "Restore defaults" for one group: submit the defaults for its keys.
        $input = [];
        foreach (SETTINGS_SCHEMA as $key => $def) {
            if ($def[0] === $_POST['reset_group']) {
                $input[$key] = setting_display($def[3], $def[2]);
            }
        }
    }
    $result = save_settings($input, (int) $user['user_id']);
    if ($result['errors']) {
        $errors = $result['errors'];
        $submitted = $input;
    } else {
        flash('success', $result['changes']
            ? 'Saved. ' . count($result['changes']) . ' setting' . (count($result['changes']) === 1 ? '' : 's') . ' changed.'
            : 'Nothing changed.');
        redirect('admin/settings.php');
    }
}

$meta = setting_overrides_meta();
$groups = [];
foreach (SETTINGS_SCHEMA as $key => $def) {
    $groups[$def[0]][$key] = $def;
}
$group_notes = [
    'Business' => 'Printed on receipts and invoices and shown on the public page.',
    'Pricing'  => 'Applies to new bookings and to price changes (reschedules, extensions). Existing bookings keep the price and deposit they were made with.',
    'Bookings' => 'Checked whenever a booking is made, changed, or cancelled.',
    'Handover' => 'Used when a car is released or received back.',
    'Maintenance' => 'Service reminders and jobs opened at check-in.',
];

$page_title = 'Settings';
$active_nav = 'settings';

require __DIR__ . '/../includes/header.php';
?>

<?php if ($errors): ?>
    <div class="alert alert-danger" role="alert">Nothing was saved. Fix the highlighted settings and try again.</div>
<?php endif; ?>

<!-- "Restore defaults" buttons post this form (form="reset-form"), so pressing
     Enter in a field always means "Save", never "restore". -->
<form method="post" id="reset-form"><?= csrf_field() ?></form>

<form method="post" novalidate class="settings-form">
    <?= csrf_field() ?>
    <?php foreach ($groups as $group => $defs): ?>
    <section class="panel" id="group-<?= e(strtolower($group)) ?>">
        <div class="panel-header"><?= e($group) ?>
            <button type="submit" form="reset-form" class="btn btn-link btn-sm p-0 panel-link" name="reset_group" value="<?= e($group) ?>"
                    data-confirm="Restore the default <?= e(strtolower($group)) ?> settings?">Restore defaults</button></div>
        <div class="panel-body">
            <p class="small text-secondary mb-3"><?= e($group_notes[$group] ?? '') ?></p>
            <div class="row g-3">
            <?php foreach ($defs as $key => [, $label, $type, $default, $min, $max, $help]): ?>
                <?php
                $value = $submitted[$key] ?? setting_display(setting_current($key), $type);
                $is_default = setting_display(setting_current($key), $type) === setting_display($default, $type);
                $id = 'set-' . strtolower($key);
                ?>
                <div class="<?= $type === 'text' || $type === 'email' ? 'col-md-6' : 'col-sm-6 col-lg-4' ?>">
                    <label class="form-label" for="<?= e($id) ?>"><?= e($label) ?></label>
                    <input class="form-control<?= in_array($type, ['int', 'money'], true) ? ' mono' : '' ?><?= isset($errors[$key]) ? ' is-invalid' : '' ?>"
                           id="<?= e($id) ?>" name="<?= e($key) ?>" value="<?= e((string) $value) ?>"
                           <?php if ($type === 'int'): ?>type="number" step="1" min="<?= $min ?>" max="<?= $max ?>"
                           <?php elseif ($type === 'money'): ?>type="number" step="0.01" min="<?= $min ?>" max="<?= $max ?>"
                           <?php elseif ($type === 'email'): ?>type="email" maxlength="<?= $max ?>"
                           <?php else: ?>type="text" maxlength="<?= $max ?>"<?php endif; ?>
                           aria-describedby="<?= e($id) ?>-help">
                    <div class="form-text" id="<?= e($id) ?>-help">
                        <?php if (isset($errors[$key])): ?><span class="text-danger"><?= e($errors[$key]) ?></span>
                        <?php else: ?>
                            <?= e($help) ?>
                            <?php if (!$is_default): ?>
                                <span class="setting-changed">Default <?= e(setting_display($default, $type)) ?><?= isset($meta[$key]) && $meta[$key]['full_name'] ? '; changed by ' . e($meta[$key]['full_name']) . ' ' . e(date('M j', strtotime($meta[$key]['updated_at']))) : '' ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endforeach; ?>

    <div class="settings-save">
        <span class="text-secondary small">Changes apply from now on. Every change is recorded in the activity log.</span>
        <button type="submit" class="btn btn-brand">Save settings</button>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
