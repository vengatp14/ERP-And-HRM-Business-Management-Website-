-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_023_billing_admin_only.sql
-- Module: GST Billing access control
--
-- GST Billing is now admin-only (super_admin/admin), gated directly on
-- role inside includes/permissions.php::user_can(), the same way
-- Reports / Project Board / Company Branding already are. It is no
-- longer part of the granular per-module grant system, so it can't be
-- handed to a plain employee from Employees > Edit.
--
-- This clears out any existing billing.* grants sitting in
-- user_menu_permissions from before this change — they're inert now,
-- but removing them keeps the table honest.
-- =====================================================================

USE crm_erp;

DELETE FROM user_menu_permissions WHERE menu_key LIKE 'billing.%';
