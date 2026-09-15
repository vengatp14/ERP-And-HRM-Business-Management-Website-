-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_017_employee_profile.sql
-- Module: Employees — profile detail fields
-- Import after database.sql (needs users table)
--
-- Adds the extra fields needed for the Employee "View Profile" screen:
-- date of birth, blood group, address, a second/alternate phone number,
-- and date of joining. profile_photo already exists on `users`.
-- =====================================================================

USE crm_erp;

ALTER TABLE users
    ADD COLUMN date_of_birth    DATE          DEFAULT NULL AFTER profile_photo,
    ADD COLUMN blood_group      VARCHAR(5)    DEFAULT NULL AFTER date_of_birth,
    ADD COLUMN address          VARCHAR(255)  DEFAULT NULL AFTER blood_group,
    ADD COLUMN alternate_mobile VARCHAR(20)   DEFAULT NULL AFTER mobile,
    ADD COLUMN joining_date     DATE          DEFAULT NULL AFTER address;
