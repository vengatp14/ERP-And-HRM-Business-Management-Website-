-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_013_monthly_salary.sql
-- Module: Employee HR — adds a fixed monthly salary to each employee so
-- the Salary screen can auto-calculate net pay from that month's
-- attendance percentage (see includes/hr.php: suggested_salary_for_month()).
-- Import after migration_008_hr.sql.
-- =====================================================================

USE crm_erp;

ALTER TABLE users
    ADD COLUMN monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0
        COMMENT 'Fixed full-attendance monthly salary, used to auto-calculate pay by attendance %'
        AFTER designation;
