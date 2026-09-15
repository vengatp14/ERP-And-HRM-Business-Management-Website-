-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_021_meeting_attendance_notes.sql
-- Module: HR / Meetings
-- Import after migration_020_meeting_status.sql
--
-- "Mark held" now requires a short attendance note (who attended /
-- what was discussed) instead of a bare button — an empty click no
-- longer counts as attendance, only a typed note does.
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE meetings
    ADD COLUMN attendance_notes TEXT DEFAULT NULL AFTER status;

SET FOREIGN_KEY_CHECKS = 1;
