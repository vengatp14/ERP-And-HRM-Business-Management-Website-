<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$user = current_user();
scan_followups_due_today();
scan_project_deadlines();
scan_recurring_expenses_due();
scan_project_payments_due();
$recentLogins = get_login_history((int) $user['id'], 20);
$totalLeadsCount = count_leads([]);
// Employees only see counts for projects they're assigned to (as manager
// or team member); admins/super_admins see the count across all projects.
$activeProjectsCount = count_active_projects(is_admin_role($user) ? null : (int) $user['id']);
$missedLeadsCount = count_leads(['status' => 'missed']);
// Revenue is financial data — only admins/super_admins see it on the
// dashboard, same admin-only gating used for GST Billing.
$revenueMtd = is_admin_role($user) ? revenue_month_to_date() : null;

// Login notification popup (#8/#9): show once per session, grouped by
// day, using the same notifications data as the navbar bell (see
// includes/notifications.php). Cleared at login (includes/auth.php)
// so it reappears the next time the user logs in, but doesn't pop up
// again on every dashboard visit within the same session.
$showNotificationPopup = empty($_SESSION['notification_popup_shown']);
$popupNotifications = $showNotificationPopup ? get_notifications((int) $user['id'], 20) : [];
$groupedPopupNotifications = group_notifications_by_day($popupNotifications);
if ($showNotificationPopup) {
    $_SESSION['notification_popup_shown'] = true;
}

$pageTitle = 'Dashboard';
$activeMenu = 'dashboard';
$breadcrumbs = [['label' => 'Dashboard', 'url' => null]];

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/navbar.php';
?>

<div class="mb-4">
    <h1 class="h4 mb-1">Welcome back, <?= e($user['full_name']) ?></h1>
    <p class="text-muted mb-0">Here's what's happening across your workspace today.</p>
</div>

<?php $statColWidth = is_admin_role($user) ? 'col-lg-3' : 'col-lg-4'; ?>
<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 <?= $statColWidth ?>">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-person-lines-fill"></i></div>
                <div>
                    <div class="text-muted small">Total Leads</div>
                    <div class="fs-4 fw-semibold"><?= e((string) $totalLeadsCount) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 <?= $statColWidth ?>">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-kanban"></i></div>
                <div>
                    <div class="text-muted small">Active Projects</div>
                    <div class="fs-4 fw-semibold"><?= e((string) $activeProjectsCount) ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php if (is_admin_role($user)): ?>
    <div class="col-12 col-sm-6 <?= $statColWidth ?>">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-cash-coin"></i></div>
                <div>
                    <div class="text-muted small">Revenue (MTD)</div>
                    <div class="fs-4 fw-semibold">₹<?= e(number_format($revenueMtd, 2)) ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-12 col-sm-6 <?= $statColWidth ?>">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-exclamation-triangle"></i></div>
                <div>
                    <div class="text-muted small">Missed Follow-ups</div>
                    <div class="fs-4 fw-semibold"><?= e((string) $missedLeadsCount) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (is_admin_role($user)): ?>
<div class="alert alert-info d-flex align-items-start gap-2 small mb-4">
    <i class="bi bi-info-circle mt-1"></i>
    <div>
        All four figures above are now real. Accounts and Reports are still pending.
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Your Recent Login Activity</h2>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="loginHistoryTable" class="table table-striped table-hover align-middle w-100" style="white-space: nowrap;">
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th>IP Address</th>
                        <th>Device / Browser</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentLogins as $login): ?>
                        <tr>
                            <td><?= e($login['logged_in_at']) ?></td>
                            <td><?= e($login['ip_address']) ?></td>
                            <td>
                                <span class="text-truncate d-inline-block" style="max-width: 320px;">
                                    <?= e($login['user_agent'] ?? 'Unknown') ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($showNotificationPopup && !empty($groupedPopupNotifications)): ?>
    <!-- Login notification popup (#8/#9): grouped by day, dismissible, shown
         once per session. Reuses the same notification_icon()/notification_color_class()
         helpers and notifications/index.php link target as the navbar bell dropdown. -->
    <div class="modal fade" id="loginNotificationsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-bell-fill text-primary"></i> Notifications</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php foreach ($groupedPopupNotifications as $groupLabel => $groupItems): ?>
                        <div class="text-uppercase text-muted small fw-semibold mt-2 mb-1"><?= e($groupLabel) ?></div>
                        <ul class="list-group list-group-flush mb-2">
                            <?php foreach ($groupItems as $notif): ?>
                                <a href="<?= e(url('notifications/index.php?open=' . $notif['id'])) ?>"
                                   class="list-group-item list-group-item-action d-flex gap-2 <?= $notif['is_read'] ? '' : 'bg-light' ?>">
                                    <i class="bi <?= e(notification_icon($notif['type'])) ?> <?= e(notification_color_class($notif['type'])) ?> mt-1"></i>
                                    <span class="flex-grow-1 text-break">
                                        <span class="d-block small fw-semibold <?= e(notification_color_class($notif['type'])) ?>"><?= e($notif['title']) ?></span>
                                        <?php if ($notif['message']): ?><span class="d-block small text-muted"><?= e($notif['message']) ?></span><?php endif; ?>
                                        <span class="d-block text-muted" style="font-size: 0.72rem;"><?= e($notif['created_at']) ?></span>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                </div>
                <div class="modal-footer">
                    <a href="<?= e(url('notifications/index.php')) ?>" class="btn btn-outline-primary btn-sm">View All</a>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <script nonce="<?= e(csp_nonce()) ?>">
    document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('loginNotificationsModal');
        if (el && typeof bootstrap !== 'undefined') {
            new bootstrap.Modal(el).show();
        }
    });
    </script>
<?php endif; ?>

<script nonce="<?= e(csp_nonce()) ?>">
$(function () {
    $('#loginHistoryTable').DataTable({
        responsive: false,
        autoWidth: false,
        order: [[0, 'desc']],
        pageLength: 10,
        columnDefs: [{ targets: 2, width: '100%' }],
        language: { search: '_INPUT_', searchPlaceholder: 'Search login history...' }
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
