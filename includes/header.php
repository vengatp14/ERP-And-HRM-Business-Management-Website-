<?php
/**
 * includes/header.php
 * Opens the HTML document for authenticated pages. Expects $pageTitle to
 * be set by the including page. Pair with sidebar.php, navbar.php, and
 * footer.php (see any authenticated page, e.g. dashboard.php, for the
 * standard include order).
 */
$pageTitle = $pageTitle ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!--
        Applied as early as possible (before any CSS/paint) to avoid a
        flash of the wrong theme. Light Theme is always the default —
        data-bs-theme only ever switches to "dark" if the user previously
        chose it from the navbar toggle (see includes/navbar.php), stored
        client-side since this app has no per-user preferences table.
    -->
    <script nonce="<?= e(csp_nonce()) ?>">
        (function () {
            try {
                var saved = localStorage.getItem('crmerp_theme');
                if (saved === 'dark') {
                    document.documentElement.setAttribute('data-bs-theme', 'dark');
                }
            } catch (e) { /* localStorage unavailable — stay on the Light Theme default */ }
        })();
    </script>
    <title><?= e($pageTitle) ?> &middot; <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.11/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="<?= e(asset('css/app.css')) ?>" rel="stylesheet">
    <!--
        Loaded here (not at the bottom of footer.php) and WITHOUT defer/async
        on purpose: every page's own inline <script> block (e.g. this
        dashboard's DataTables init, or the follow-up modal on leads/view.php)
        appears in the body BEFORE footer.php runs. A deferred/bottom-loaded
        script would still execute after those inline blocks and leave
        `$`/`bootstrap` undefined when they run. Loading them here,
        render-blocking, guarantees they're defined first.
    -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/datatables.net@1.13.11/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.11/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/datatables.net-responsive@2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/datatables.net-responsive-bs5@2.5.0/js/responsive.bootstrap5.min.js"></script>
</head>
<body>
<div class="app-shell">
