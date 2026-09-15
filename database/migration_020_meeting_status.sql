-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_020_meeting_status.sql
-- Module: HR / Meetings
-- Import after migration_008_hr.sql and migration_007_notifications.sql
--
-- Adds a status to meetings so a scheduled meeting that has passed
-- without being marked "held" can be automatically flagged "missed",
-- the same on-demand pattern already used for leads
-- (process_missed_leads()) and invoices (process_overdue_invoices()).
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE meetings
    ADD COLUMN status ENUM('scheduled','held','missed','cancelled') NOT NULL DEFAULT 'scheduled' AFTER location;

-- Extend the existing notifications.type ENUM with 'missed_meeting'
ALTER TABLE notifications
    MODIFY COLUMN type ENUM('deadline','follow_up','missed_lead','payment','meeting','missed_meeting','leave','task','overdue_invoice','auto_expense','general') NOT NULL DEFAULT 'general';

SET FOREIGN_KEY_CHECKS = 1;
