<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$currentUserId = (int) current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_all_read') {
        mark_all_notifications_read($currentUserId);
        flash_set('status', 'All notifications marked as read.');
        redirect('notifications/index.php');
    }
}

// Opening a specific notification from the bell dropdown marks it read and follows its link.
if (!empty($_GET['open'])) {
    $notifId = (int) $_GET['open'];
    $stmt = db()->prepare('SELECT link FROM notifications WHERE id = :id AND user_id = :uid');
    $stmt->execute(['id' => $notifId, 'uid' => $currentUserId]);
    $target = $stmt->fetch();
    mark_notification_read($notifId, $currentUserId);
    if ($target !== false && !empty($target['link'])) {
        redirect($target['link']);
    }
}

$notifications = get_notifications($currentUserId, 100);
$groupedNotifications = group_notifications_by_day($notifications);

$pageTitle = 'Notifications';
$activeMenu = 'notifications';
$breadcrumbs = [['label' => 'Notifications', 'url' => null]];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Notifications</h1>
    <form method="POST" action="<?= e(url('notifications/index.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_all_read">
        <button type="submit" class="btn btn-outline-secondary btn-sm">Mark all as read</button>
    </form>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($notifications)): ?>
            <div class="text-center text-muted py-5">No notifications yet.</div>
        <?php endif; ?>
        <?php foreach ($groupedNotifications as $groupLabel => $groupItems): ?>
            <div class="px-3 pt-3 pb-1 text-uppercase text-muted small fw-semibold bg-body-tertiary border-bottom"><?= e($groupLabel) ?></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($groupItems as $notif): ?>
                    <li class="list-group-item d-flex gap-3 <?= $notif['is_read'] ? '' : 'bg-light' ?>">
                        <i class="bi <?= e(notification_icon($notif['type'])) ?> fs-5 <?= e(notification_color_class($notif['type'])) ?> mt-1"></i>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between">
                                <strong class="<?= e(notification_color_class($notif['type'])) ?>"><?= e($notif['title']) ?></strong>
                                <span class="text-muted small"><?= e($notif['created_at']) ?></span>
                            </div>
                            <?php if ($notif['message']): ?><div class="text-muted small"><?= e($notif['message']) ?></div><?php endif; ?>
                            <?php if ($notif['link']): ?>
                                <a href="<?= e(url('notifications/index.php?open=' . $notif['id'])) ?>" class="small">View details</a>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
