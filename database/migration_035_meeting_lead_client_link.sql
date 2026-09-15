-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_035_meeting_lead_client_link.sql
-- Module: Leads / Clients — meeting scheduling
-- Import after migration_034_project_income_invoice_link_rerun_safe.sql
--
-- Lets a meeting (existing `meetings` table, used by hr/meetings.php)
-- optionally be tied to a specific lead or client, so:
--   1. A meeting can be scheduled directly while creating/viewing a
--      lead or client (reusing the same meetings table/UI instead of
--      building a separate scheduler).
--   2. A lead or client can have multiple meetings over time (a full
--      history), since each scheduled meeting is its own row here and
--      is never overwritten.
-- Both columns are nullable — existing HR/company meetings (no lead or
-- client attached) keep working exactly as before.
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE meetings
    ADD COLUMN lead_id   INT UNSIGNED DEFAULT NULL AFTER location,
    ADD COLUMN client_id INT UNSIGNED DEFAULT NULL AFTER lead_id,
    ADD CONSTRAINT fk_meetings_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_meetings_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    ADD KEY idx_meetings_lead (lead_id),
    ADD KEY idx_meetings_client (client_id);

SET FOREIGN_KEY_CHECKS = 1;
