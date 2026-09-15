-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_029_project_completion_submission.sql
-- Module: Projects — lets an employee attach a reference link and
-- supporting documents at the moment they mark a project complete
-- (i.e. push it to 'pending_approval'), so the admin reviewing it on
-- the Project Board has proof to check before accepting the move to
-- Completed. Mirrors the lead_documents pattern from
-- migration_002_leads.sql.
-- Import after migration_025_project_pending_approval.sql
-- (or run: mysql -u root -p crm_erp < this file)
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE projects
    ADD COLUMN completion_link VARCHAR(500) DEFAULT NULL AFTER completed_at;

-- ---------------------------------------------------------------------
-- project_completion_documents — files uploaded by the employee to
-- support a completion (pending_approval) submission on a project.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_completion_documents (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id          INT UNSIGNED    NOT NULL,
    uploaded_by         INT UNSIGNED    DEFAULT NULL,
    original_filename   VARCHAR(255)    NOT NULL,
    stored_filename     VARCHAR(255)    NOT NULL,
    file_size_bytes     INT UNSIGNED    NOT NULL,
    mime_type           VARCHAR(100)    NOT NULL,
    created_at          DATETIME        NOT NULL,
    CONSTRAINT fk_project_completion_documents_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_completion_documents_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_project_completion_documents_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
