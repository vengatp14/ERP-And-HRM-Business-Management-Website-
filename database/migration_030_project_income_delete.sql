-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_030_project_income_delete.sql
-- Module: Accounts / Projects — lets an admin manually record a
-- project payment (record_project_income() already existed but had no
-- UI) and delete an income entry, whether it was added manually or
-- accepted via the automatic monthly/one-time confirm flow
-- (confirm_project_payment_run()). Soft delete, same pattern as
-- expenses.deleted_at in database.sql.
-- Import after migration_026_project_payment_plans.sql
-- (or run: mysql -u root -p crm_erp < this file)
-- =====================================================================

USE crm_erp;

ALTER TABLE project_income
    ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER created_at;
