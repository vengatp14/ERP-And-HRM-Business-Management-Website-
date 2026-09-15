-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_022_client_calendar.sql
-- Module: Client Management — calendar follow-up tracking
-- Import after migration_016_client_status.sql
--
-- Clients had no date field of their own to drive the new shared
-- calendar widget (see includes/calendar.php). Adds a next_contact_at
-- date, plus a small contact_status so a scheduled contact date can be
-- flagged "missed" (on-demand, same pattern as process_missed_leads()
-- and process_missed_meetings()) once it passes without being marked
-- contacted, or "contacted" when someone marks it done.
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE clients
    ADD COLUMN next_contact_at DATETIME DEFAULT NULL AFTER notes,
    ADD COLUMN contact_status ENUM('pending','contacted','missed') NOT NULL DEFAULT 'pending' AFTER next_contact_at,
    ADD COLUMN last_contacted_at DATETIME DEFAULT NULL AFTER contact_status;

-- Extend notifications.type so a missed client contact date can raise
-- a notification the same way missed_lead / missed_meeting already do.
ALTER TABLE notifications
    MODIFY COLUMN type ENUM('deadline','follow_up','missed_lead','payment','meeting','missed_meeting','missed_client_contact','leave','task','overdue_invoice','auto_expense','general') NOT NULL DEFAULT 'general';

SET FOREIGN_KEY_CHECKS = 1;
