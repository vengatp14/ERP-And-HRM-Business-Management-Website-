<?php
/**
 * includes/navbar.php
 * Top navigation bar. Opens the main column (.app-main) and the content
 * wrapper (.app-content) — footer.php closes both. Expects $breadcrumbs
 * (array of ['label'=>.., 'url'=>..|null]) to optionally be set by the
 * including page.
 */
$breadcrumbs = $breadcrumbs ?? [];
$user = current_user();
?>
<div class="app-main">
    <nav class="navbar app-topbar px-2 px-md-3">
        <button class="btn app-menu-toggle d-lg-none" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Toggle menu">
            <i class="bi bi-list fs-4"></i>
        </button>
        <span class="navbar-brand mb-0 h1 d-lg-none">CRM<span class="text-primary">ERP</span></span>

        <div class="ms-auto d-flex align-items-center gap-3">
            <button type="button" class="btn btn-light theme-toggle-btn" id="themeToggleBtn"
                    title="Switch to Dark Theme" aria-label="Switch to Dark Theme">
                <i class="bi bi-moon-stars"></i>
            </button>
            <?php $unreadCount = get_unread_notification_count((int) $user['id']); $recentNotifications = get_notifications((int) $user['id'], 8); ?>
            <div class="dropdown">
                <button class="btn btn-light position-relative notif-bell-btn" type="button" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                    <i class="bi bi-bell fs-5"></i>
                    <?php if ($unreadCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notif-bell-badge">
                            <?= $unreadCount > 99 ? '99+' : e((string) $unreadCount) ?>
                        </span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end p-0 notif-dropdown">
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                        <span class="fw-semibold small">Notifications</span>
                        <a href="<?= e(url('notifications/index.php')) ?>" class="small text-decoration-none">View all</a>
                    </div>
                    <?php if (empty($recentNotifications)): ?>
                        <div class="text-center text-muted small py-4">You're all caught up.</div>
                    <?php endif; ?>
                    <?php foreach ($recentNotifications as $notif): ?>
                        <a href="<?= e(url('notifications/index.php?open=' . $notif['id'])) ?>"
                           class="dropdown-item d-flex gap-2 py-2 <?= $notif['is_read'] ? '' : 'bg-light' ?>">
                            <i class="bi <?= e(notification_icon($notif['type'])) ?> <?= e(notification_color_class($notif['type'])) ?> mt-1"></i>
                            <span class="flex-grow-1">
                                <span class="d-block small fw-semibold text-wrap <?= e(notification_color_class($notif['type'])) ?>"><?= e($notif['title']) ?></span>
                                <span class="d-block text-muted" style="font-size: 0.75rem;"><?= e($notif['created_at']) ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="dropdown">
                <button class="btn btn-light d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                    <span class="app-avatar"><?= e(mb_strtoupper(mb_substr($user['full_name'] ?? 'U', 0, 1))) ?></span>
                    <span class="d-none d-sm-inline"><?= e($user['full_name'] ?? 'User') ?></span>
                    <i class="bi bi-chevron-down small"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end user-menu-dropdown">
                    <li><span class="dropdown-item-text text-muted small">Signed in as <?= e($user['role'] ?? '') ?></span></li>
                    <li><a class="dropdown-item" href="<?= e(url('profile.php')) ?>"><i class="bi bi-person me-2"></i>Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="<?= e(url('logout.php')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="dropdown-item">
                                <i class="bi bi-box-arrow-right me-2"></i>Logout
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="app-content">
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="<?= e(url('dashboard.php')) ?>" class="text-decoration-none">Home</a></li>
                <?php foreach ($breadcrumbs as $crumb): ?>
                    <?php if (!empty($crumb['url'])): ?>
                        <li class="breadcrumb-item"><a href="<?= e($crumb['url']) ?>" class="text-decoration-none"><?= e($crumb['label']) ?></a></li>
                    <?php else: ?>
                        <li class="breadcrumb-item active" aria-current="page"><?= e($crumb['label']) ?></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ol>
        </nav>

        <?php require __DIR__ . '/alerts.php'; ?>