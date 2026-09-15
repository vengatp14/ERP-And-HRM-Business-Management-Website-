-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_006_accounts.sql
-- Module: Accounts
-- Import after database.sql and migration_005_billing.sql
--
-- Accounts tracks the EXPENSE side of the ledger. The income side
-- already exists as invoice_payments (see migration_005_billing.sql) —
-- this module reads that table rather than duplicating payment
-- records, so there's one source of truth for money received.
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS expenses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36)        NOT NULL,
    category        VARCHAR(100)    NOT NULL,
    description     VARCHAR(255)    DEFAULT NULL,
    amount          DECIMAL(12,2)   NOT NULL,
    expense_date    DATE            NOT NULL,
    payment_method  ENUM('cash','bank_transfer','upi','cheque','card','other') NOT NULL DEFAULT 'bank_transfer',
    vendor          VARCHAR(150)    DEFAULT NULL,
    reference       VARCHAR(100)    DEFAULT NULL,
    project_id      INT UNSIGNED    DEFAULT NULL,
    created_by      INT UNSIGNED    NOT NULL,
    created_at      DATETIME        NOT NULL,
    updated_at      DATETIME        NOT NULL,
    deleted_at      DATETIME        DEFAULT NULL,
    CONSTRAINT fk_expenses_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_expenses_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_expenses_uuid (uuid),
    KEY idx_expenses_date (expense_date),
    KEY idx_expenses_category (category),
    KEY idx_expenses_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
