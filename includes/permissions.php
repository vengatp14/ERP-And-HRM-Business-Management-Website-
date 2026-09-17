<?php

declare(strict_types=1);

/**
 * includes/permissions.php
 * Per-user, per-module, per-action access control.
 *
 * super_admin and admin accounts always have full access to every
 * module/action. Plain 'employee' accounts only get what a manager
 * explicitly grants — a freshly approved registration starts with NO
 * access at all (besides Dashboard) until someone sets it from
 * Employees > Edit.
 *
 * Permissions are stored as "<module>.<action>" strings in the
 * user_menu_permissions table (one row per granted module+action).
 */

const MODULE_PERMISSIONS = [
    'leads' => ['label' => 'Leads', 'group' => 'CRM', 'actions' => ['view' => 'View', 'add' => 'Add', 'edit' => 'Edit']],
    'projects' => ['label' => 'Projects', 'group' => 'CRM', 'actions' => ['view' => 'View', 'add' => 'Add', 'edit' => 'Edit']],
    'clients' => ['label' => 'Clients', 'group' => 'CRM', 'actions' => ['view' => 'View', 'add' => 'Add', 'edit' => 'Edit']],
    // 'billing' (GST Billing) is intentionally NOT in this list — it's
    // admin-only, gated directly on role inside user_can() below, and
    // is never grantable to a plain employee from Employees > Edit.
    'accounts' => ['label' => 'Accounts', 'group' => 'Finance', 'actions' => ['view' => 'View', 'add' => 'Add', 'edit' => 'Edit']],
    'employees' => ['label' => 'Employees', 'group' => 'HR', 'actions' => ['view' => 'View']],
    'attendance' => ['label' => 'Attendance', 'group' => 'HR', 'actions' => ['view' => 'View']],
    'leaves' => ['label' => 'Leave Requests', 'group' => 'HR', 'actions' => ['view' => 'View']],
    'meetings' => ['label' => 'Meetings', 'group' => 'HR', 'actions' => ['view' => 'View']],
    'daily_updates' => ['label' => 'Daily Updates', 'group' => 'HR', 'actions' => ['view' => 'View']],
];

/** All valid "module.action" keys, e.g. "leads.view", "leads.add". */
function all_permission_keys(): array
{
    $keys = [];
    foreach (MODULE_PERMISSIONS as $module => $def) {
        foreach (array_keys($def['actions']) as $action) {
            $keys[] = $module . '.' . $action;
        }
    }
    return $keys;
}

function get_user_permission_keys(int $userId): array
{
    $stmt = db()->prepare('SELECT menu_key FROM user_menu_permissions WHERE user_id = :uid');
    $stmt->execute(['uid' => $userId]);
    return array_column($stmt->fetchAll(), 'menu_key');
}

function set_user_permission_keys(int $userId, array $keys): void
{
    // Only ever persist known "module.action" keys — never trust raw POST values.
    $keys = array_values(array_intersect($keys, all_permission_keys()));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM user_menu_permissions WHERE user_id = :uid')->execute(['uid' => $userId]);

        if ($keys !== []) {
            $stmt = $pdo->prepare('INSERT INTO user_menu_permissions (user_id, menu_key) VALUES (:uid, :menu_key)');
            foreach ($keys as $key) {
                $stmt->execute(['uid' => $userId, 'menu_key' => $key]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Dashboard is always reachable once logged in. super_admin/admin get
 * every module/action automatically. Everyone else needs an explicit
 * grant for that specific module+action.
 */
function user_can(array $user, string $module, string $action = 'view'): bool
{
    if ($module === 'dashboard') {
        return true;
    }
    if (in_array($user['role'], ['super_admin', 'admin'], true)) {
        return true;
    }
    // GST Billing is admin-only — deliberately not part of the granular
    // per-module grant system, so a plain employee can never see or act
    // on it no matter what's stored in user_menu_permissions.
    if ($module === 'billing') {
        return false;
    }
    // Quotations carry the same pricing/financial sensitivity as GST
    // Billing, so they're gated the same way — admin-only, never
    // grantable to a plain employee.
    if ($module === 'quotations') {
        return false;
    }
    if (!isset(MODULE_PERMISSIONS[$module]['actions'][$action])) {
        return false;
    }
    return in_array($module . '.' . $action, get_user_permission_keys((int) $user['id']), true);
}

/** Convenience alias — "can this user see/open this module at all". */
function user_can_access_menu(array $user, string $module): bool
{
    return $module === 'dashboard' || user_can($user, $module, 'view');
}

/**
 * Guards a page so it's only reachable by users with the given
 * module+action permission. Call after require_login() (and after
 * require_role() if the page also has a hard role restriction).
 */
function require_permission(string $module, string $action = 'view'): void
{
    $user = current_user();
    if ($user === null || !user_can($user, $module, $action)) {
        http_response_code(403);
        require dirname(__DIR__) . '/errors/403.php';
        exit;
    }
}

/** Back-compat alias for plain "can view this module" checks. */
function require_menu_access(string $module): void
{
    require_permission($module, 'view');
}

/**
 * True only for super_admin/admin. Used to hide sensitive fields
 * (e.g. project budget) from everyone else, even if they have
 * view/edit access to the module itself.
 */
function is_admin_role(?array $user): bool
{
    return $user !== null && in_array($user['role'], ['super_admin', 'admin'], true);
}
