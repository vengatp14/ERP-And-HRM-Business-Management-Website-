<?php
/**
 * includes/guest-header.php
 * Shared shell for un-authenticated pages (login, register, forgot/reset
 * password) so that card/layout markup isn't duplicated across all four.
 * Expects $pageTitle and optionally $pageSubtitle.
 */
$pageTitle = $pageTitle ?? 'Sign In';
$pageSubtitle = $pageSubtitle ?? 'Enterprise CRM & ERP Platform';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> &middot; <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= e(asset('css/auth.css')) ?>" rel="stylesheet">
</head>
<body class="auth-body">
    <div class="auth-wrapper d-flex align-items-center justify-content-center min-vh-100">
        <div class="auth-card card shadow-lg border-0">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <div class="auth-logo mb-2">CRM<span class="text-primary">ERP</span></div>
                    <p class="text-muted mb-0"><?= e($pageSubtitle) ?></p>
                </div>

                <?php require __DIR__ . '/alerts.php'; ?>
