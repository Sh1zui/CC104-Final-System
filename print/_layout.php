<?php
/**
 * Shared top/bottom for printable documents (receipt.php, invoice.php).
 * Set $doc_title and $back_url before including; call print_footer() at the end.
 */
function print_header(string $doc_title, string $back_url): void
{
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($doc_title) ?> — <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e(BASE_URL) ?>assets/img/favicon.svg?v=autoway-1" type="image/svg+xml">
    <link rel="icon" href="<?= e(BASE_URL) ?>assets/img/favicon.ico?v=autoway-1" sizes="48x48">
    <link rel="apple-touch-icon" href="<?= e(BASE_URL) ?>assets/img/apple-touch-icon.png?v=autoway-1">
    <link href="<?= e(BASE_URL) ?>assets/vendor/fonts/fonts.css" rel="stylesheet">
    <link href="<?= e(BASE_URL) ?>assets/css/print.css" rel="stylesheet">
</head>
<body>
<div class="toolbar">
    <a href="<?= e($back_url) ?>">&larr; Back</a>
    <button type="button" onclick="window.print()">Print or save as PDF</button>
</div>
    <?php
}

function print_business_block(): void
{
    ?>
    <div>
        <div class="brand"><img class="brand-logo" src="<?= e(BASE_URL) ?>assets/img/logo.svg" alt="" width="30" height="30"><?= e(APP_NAME) ?></div>
        <div class="business">
            <?= e(BUSINESS_ADDRESS) ?><br>
            <?= e(BUSINESS_PHONE) ?> &nbsp;·&nbsp; <?= e(BUSINESS_EMAIL) ?><br>
            TIN <?= e(BUSINESS_TIN) ?>
        </div>
    </div>
    <?php
}

function print_footer(): void
{
    echo "\n</body>\n</html>\n";
}

/** Where "Back" goes: the booking for staff/admin, the account page for a customer. */
function paperwork_back_url(array $user, int $booking_id): string
{
    return portal_url('bookings', ['open' => $booking_id], $user['role_name'])
        ?? BASE_URL . dashboard_path_for($user['role_name']);
}
