-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_033_daily_update_rating.sql
-- Module: HR — lets an admin/super_admin give a 1-5 star rating on an
-- employee's daily work update. Ratings roll up into a monthly average
-- shown as stars on the employee's profile.
-- Import after migration_032_daily_work_updates.sql
-- (or run: mysql -u root -p crm_erp < this file)
-- =====================================================================

USE crm_erp;

ALTER TABLE daily_work_updates
    ADD COLUMN rating   TINYINT UNSIGNED DEFAULT NULL AFTER reference_link,
    ADD COLUMN rated_by INT UNSIGNED     DEFAULT NULL AFTER rating,
    ADD COLUMN rated_at DATETIME         DEFAULT NULL AFTER rated_by,
    ADD CONSTRAINT chk_daily_work_updates_rating CHECK (rating IS NULL OR (rating BETWEEN 1 AND 5)),
    ADD CONSTRAINT fk_daily_work_updates_rated_by FOREIGN KEY (rated_by) REFERENCES users(id) ON DELETE SET NULL;
