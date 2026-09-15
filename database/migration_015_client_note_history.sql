-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_015_client_note_history.sql
-- Module: Clients — lets a client note/description be edited after
-- saving, tracks when it was last edited, and keeps a full history of
-- what it said before each edit. Mirrors migration_014's approach for
-- lead follow-ups.
-- Import after migration_003_clients.sql.
-- =====================================================================

USE crm_erp;

ALTER TABLE client_notes
    ADD COLUMN updated_at DATETIME DEFAULT NULL AFTER created_at;

CREATE TABLE IF NOT EXISTS client_note_history (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    note_id         INT UNSIGNED    NOT NULL,
    old_content     TEXT            NOT NULL,
    changed_by      INT UNSIGNED    DEFAULT NULL,
    changed_at      DATETIME        NOT NULL,
    CONSTRAINT fk_client_note_history_note FOREIGN KEY (note_id) REFERENCES client_notes(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_note_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_client_note_history_note (note_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
