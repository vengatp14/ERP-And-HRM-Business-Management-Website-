<?php
/**
 * includes/sidebar.php
 * Responsive sidebar menu. Expects $activeMenu (string key) to be set
 * by the including page to highlight the current section.
 */
$activeMenu = $activeMenu ?? 'dashboard';

// Modules not yet built are marked disabled rather than linking to a
// dead page — this menu structure exists now so future modules
// (Leads, Projects, etc.) drop straight in.
$menuItems = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'bi-speedometer2', 'url' => url('dashboard.php'), 'enabled' => true],
    ['key' => 'leads', 'label' => 'Leads', 'icon' => 'bi-person-lines-fill', 'url' => url('leads/index.php'), 'enabled' => true],
    ['key' => 'projects', 'label' => 'Projects', 'icon' => 'bi-kanban', 'url' => url('projects/index.php'), 'enabled' => true],
    ['key' => 'clients', 'label' => 'Clients', 'icon' => 'bi-building', 'url' => url('clients/index.php'), 'enabled' => true],
    ['key' => 'billing', 'label' => 'GST Billing', 'icon' => 'bi-receipt', 'url' => url('billing/index.php'), 'enabled' => true],
    ['key' => 'accounts', 'label' => 'Accounts', 'icon' => 'bi-cash-coin', 'url' => url('accounts/index.php'), 'enabled' => true],
    ['key' => 'employees', 'label' => 'Employees', 'icon' => 'bi-people', 'url' => url('employees/index.php'), 'enabled' => true],
    ['key' => 'attendance', 'label' => 'Attendance', 'icon' => 'bi-calendar-check', 'url' => url('hr/attendance.php'), 'enabled' => true],
    ['key' => 'leaves', 'label' => 'Leave Requests', 'icon' => 'bi-calendar-x', 'url' => url('hr/leaves.php'), 'enabled' => true],
    ['key' => 'meetings', 'label' => 'Meetings', 'icon' => 'bi-camera-video', 'url' => url('hr/meetings.php'), 'enabled' => true],
    ['key' => 'daily_updates', 'label' => 'Daily Updates', 'icon' => 'bi-journal-text', 'url' => url('hr/daily-updates.php'), 'enabled' => true],
];
// Reports is admin-only — gated directly on role (super_admin/admin),
// same pattern as Project Board / Company Branding below, so it's
// never at the mercy of the granular per-module permission system
// (a manager can no longer grant "Reports" access from Employees > Edit).
if (in_array($_SESSION['user_role'] ?? '', ['super_admin', 'admin'], true)) {
    $menuItems[] = ['key' => 'reports', 'label' => 'Reports', 'icon' => 'bi-bar-chart-line', 'url' => url('reports/index.php'), 'enabled' => true];
}
// Project Board is an admin-only Kanban view, gated directly on role
// (super_admin/admin) the same way as Company Branding below — it's
// never at the mercy of user_can_access_menu(), so a manager who was
// merely granted projects.edit still can't see or reach it.
if (in_array($_SESSION['user_role'] ?? '', ['super_admin', 'admin'], true)) {
    $menuItems[] = ['key' => 'project_board', 'label' => 'Project Board', 'icon' => 'bi-kanban-fill', 'url' => url('projects/board.php'), 'enabled' => true];
}

// Company Branding comes right BEFORE Admin in the menu — gated
// directly on role (super_admin/admin), not on the granular
// per-module permission system below, so it's never at the mercy of
// user_can_access_menu().
if (in_array($_SESSION['user_role'] ?? '', ['super_admin', 'admin'], true)) {
    $menuItems[] = ['key' => 'company_branding', 'label' => 'Company Branding', 'icon' => 'bi-image', 'url' => url('admin/company-branding.php'), 'enabled' => true];
}
if (($_SESSION['user_role'] ?? '') === 'super_admin') {
    $menuItems[] = ['key' => 'admin', 'label' => 'Admin', 'icon' => 'bi-shield-lock', 'url' => url('admin/index.php'), 'enabled' => true];
}

// Only show menus this user actually has access to. super_admin/admin
// see everything; a plain employee only sees Dashboard plus whatever
// menus a manager has granted from Employees > Menu Access.
// 'admin', 'company_branding', 'project_board', and 'reports' are
// exempt — they're already gated on role directly above, and were
// never part of the granular per-module permission system
// (MODULE_PERMISSIONS) to begin with.
$sidebarUser = current_user();
if ($sidebarUser !== null) {
    $menuItems = array_values(array_filter(
        $menuItems,
        static fn (array $item): bool => in_array($item['key'], ['admin', 'company_branding', 'project_board', 'reports'], true)
            || user_can_access_menu($sidebarUser, $item['key'])
    ));
}
?>
<div class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="appSidebar">
    <div class="offcanvas-header d-lg-none">
        <span class="navbar-brand mb-0 h1 text-white">CRM<span class="text-primary">ERP</span></span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column p-0">
        <div class="app-logo d-none d-lg-flex">CRM<span class="text-primary">ERP</span></div>
        <nav class="nav flex-column app-nav">
            <?php foreach ($menuItems as $item): ?>
                <?php if ($item['enabled']): ?>
                    <a href="<?= e($item['url']) ?>" class="nav-link<?= $activeMenu === $item['key'] ? ' active' : '' ?>">
                        <i class="bi <?= e($item['icon']) ?>"></i>
                        <span><?= e($item['label']) ?></span>
                    </a>
                <?php else: ?>
                    <span class="nav-link disabled" aria-disabled="true" title="Coming soon">
                        <i class="bi <?= e($item['icon']) ?>"></i>
                        <span><?= e($item['label']) ?></span>
                        <span class="badge text-bg-secondary ms-auto">Soon</span>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </div>
</div>