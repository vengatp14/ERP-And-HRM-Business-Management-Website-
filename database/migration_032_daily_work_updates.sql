-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_032_daily_work_updates.sql
-- Module: HR — lets every employee log a short "what I worked on
-- today" entry (heading + description), with an optional reference
-- link and optional supporting documents. One entry per employee per
-- calendar day (editable during that day). Mirrors the
-- project_completion_documents pattern from
-- migration_029_project_completion_submission.sql.
-- Import after migration_031_project_income_invoice_number.sql
-- (or run: mysql -u root -p crm_erp < this file)
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- daily_work_updates — one row per employee per calendar day.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS daily_work_updates (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED    NOT NULL,
    update_date         DATE            NOT NULL,
    heading             VARCHAR(150)    NOT NULL,
    description         TEXT            NOT NULL,
    reference_link      VARCHAR(500)    DEFAULT NULL,
    created_at          DATETIME        NOT NULL,
    updated_at          DATETIME        NOT NULL,
    CONSTRAINT fk_daily_work_updates_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_daily_work_updates_user_date (user_id, update_date),
    KEY idx_daily_work_updates_date (update_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- daily_work_update_documents — optional files attached to an update.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS daily_work_update_documents (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    update_id           INT UNSIGNED    NOT NULL,
    uploaded_by         INT UNSIGNED    DEFAULT NULL,
    original_filename   VARCHAR(255)    NOT NULL,
    stored_filename     VARCHAR(255)    NOT NULL,
    file_size_bytes     INT UNSIGNED    NOT NULL,
    mime_type           VARCHAR(100)    NOT NULL,
    created_at          DATETIME        NOT NULL,
    CONSTRAINT fk_daily_work_update_documents_update FOREIGN KEY (update_id) REFERENCES daily_work_updates(id) ON DELETE CASCADE,
    CONSTRAINT fk_daily_work_update_documents_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_daily_work_update_documents_update (update_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
