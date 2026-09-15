-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_026_project_payment_plans.sql
-- Module: Projects + Accounts — Project Payment Plans
-- Import after database.sql, migration_004_projects.sql,
-- migration_006_accounts.sql and migration_007_notifications.sql
--
-- Lets a project be marked, at creation, as either a One-Time payment
-- (a single expected amount/date) or a Monthly payment (a recurring
-- amount collected from the client every month, e.g. maintenance/
-- rent-style projects). Mirrors the existing Auto Expenses pattern
-- (migration_010_recurring_expenses.sql) but on the income side:
--
--   project_payment_plans      — one row per project's payment setup
--                                 (the template: amount + one_time due
--                                 date OR monthly day_of_month).
--   project_payment_plan_runs  — one row per due date that has come up
--                                 (dedupe + reminder + paid/skipped
--                                 audit trail — same role as
--                                 recurring_expense_runs).
--   project_income             — the actual money-in records once a
--                                 due payment is confirmed as paid.
--                                 Deliberately separate from
--                                 invoice_payments (which requires a
--                                 formal GST invoice) so a quick
--                                 monthly collection reminder doesn't
--                                 force creating an invoice every
--                                 month. Accounts' ledger/income totals
--                                 read from both.
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS project_payment_plans (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36)        NOT NULL,
    project_id      INT UNSIGNED    NOT NULL,
    payment_type    ENUM('one_time','monthly') NOT NULL DEFAULT 'monthly',
    amount          DECIMAL(12,2)   NOT NULL,
    day_of_month    TINYINT UNSIGNED DEFAULT NULL,   -- monthly: 1–28
    due_date        DATE            DEFAULT NULL,    -- one_time: the expected payment date
    payment_method  ENUM('cash','bank_transfer','upi','cheque','card','other') NOT NULL DEFAULT 'bank_transfer',
    reference       VARCHAR(100)    DEFAULT NULL,
    notes           VARCHAR(255)    DEFAULT NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED    NOT NULL,
    created_at      DATETIME        NOT NULL,
    updated_at      DATETIME        NOT NULL,
    deleted_at      DATETIME        DEFAULT NULL,
    CONSTRAINT fk_project_payment_plans_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_payment_plans_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_project_payment_plans_uuid (uuid),
    KEY idx_project_payment_plans_project (project_id),
    KEY idx_project_payment_plans_active (is_active),
    KEY idx_project_payment_plans_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_payment_plan_runs (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id                 INT UNSIGNED    NOT NULL,
    month_key               CHAR(7)         NOT NULL, -- 'YYYY-MM'
    due_date                DATE            NOT NULL,
    status                  ENUM('pending','paid','skipped') NOT NULL DEFAULT 'pending',
    income_id               INT UNSIGNED    DEFAULT NULL,
    notified_at             DATETIME        NOT NULL,
    resolved_at             DATETIME        DEFAULT NULL,
    resolved_by             INT UNSIGNED    DEFAULT NULL,
    CONSTRAINT fk_project_payment_runs_plan FOREIGN KEY (plan_id) REFERENCES project_payment_plans(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_payment_runs_resolved_by FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_project_payment_runs_plan_month (plan_id, month_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_income (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id      INT UNSIGNED    NOT NULL,
    plan_id         INT UNSIGNED    DEFAULT NULL,
    amount          DECIMAL(12,2)   NOT NULL,
    payment_date    DATE            NOT NULL,
    payment_method  ENUM('cash','bank_transfer','upi','cheque','card','other') NOT NULL DEFAULT 'bank_transfer',
    reference       VARCHAR(100)    DEFAULT NULL,
    notes           VARCHAR(255)    DEFAULT NULL,
    created_by      INT UNSIGNED    NOT NULL,
    created_at      DATETIME        NOT NULL,
    CONSTRAINT fk_project_income_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_income_plan FOREIGN KEY (plan_id) REFERENCES project_payment_plans(id) ON DELETE SET NULL,
    CONSTRAINT fk_project_income_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    KEY idx_project_income_project (project_id),
    KEY idx_project_income_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extend the existing notifications.type ENUM with 'project_payment'
-- (keeping every value already added by earlier migrations, including
-- 'missed_meeting' from migration_020 — omitting any existing value
-- here would truncate those rows and MySQL rejects the ALTER outright).
ALTER TABLE notifications
    MODIFY COLUMN type ENUM('deadline','follow_up','missed_lead','payment','meeting','missed_meeting','leave','task','overdue_invoice','auto_expense','project_payment','general') NOT NULL DEFAULT 'general';

SET FOREIGN_KEY_CHECKS = 1;
