-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_014_lead_activity_history.sql
-- Module: Leads — lets a follow-up's description/response/next-followup
-- date be edited after logging, tracks when it was last edited, and
-- keeps a full history of what it looked like before each edit.
-- Import after migration_002_leads.sql.
-- =====================================================================

USE crm_erp;

ALTER TABLE lead_activities
    ADD COLUMN updated_at DATETIME DEFAULT NULL AFTER created_at;

CREATE TABLE IF NOT EXISTS lead_activity_history (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id             INT UNSIGNED    NOT NULL,
    old_content             TEXT            DEFAULT NULL,
    old_client_response     ENUM('interested','busy','call_later','meeting_fixed','not_interested','no_response') DEFAULT NULL,
    old_next_follow_up_at   DATETIME        DEFAULT NULL,
    changed_by              INT UNSIGNED    DEFAULT NULL,
    changed_at              DATETIME        NOT NULL,
    CONSTRAINT fk_lead_activity_history_activity FOREIGN KEY (activity_id) REFERENCES lead_activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_lead_activity_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_lead_activity_history_activity (activity_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
