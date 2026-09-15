-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_011_menu_permissions.sql
-- Module: Per-user menu access control
--
-- New self-registered employee accounts (register.php) start with
-- role='employee' and status='inactive'. Today, once an admin
-- activates the account (Employees > toggle status), that employee
-- immediately sees every menu in the sidebar and can open every page.
--
-- This table lets a super_admin/admin explicitly choose which menus
-- a given employee is allowed to use. super_admin and admin accounts
-- are unaffected — they always have full access. Rows here only
-- matter for role='employee' users, and an employee with no rows here
-- has access to nothing but the Dashboard until a manager grants
-- specific menus (see includes/permissions.php).
-- =====================================================================

USE crm_erp;

CREATE TABLE IF NOT EXISTS user_menu_permissions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    menu_key    VARCHAR(50)  NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_menu_permissions (user_id, menu_key),
    KEY idx_user_menu_permissions_user (user_id),
    CONSTRAINT fk_user_menu_permissions_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
