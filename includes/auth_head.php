<?php
/**
 * <head> for the logged-out pages. Set $page_title before including.
 * Same fonts, design tokens, and saved-theme handling as the app shell,
 * so login and the dashboard feel like one product.
 */
?>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?> — <?= e(APP_NAME) ?></title>
    <script>
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
