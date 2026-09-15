-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_010_recurring_expenses.sql
-- Module: Accounts — Recurring / Auto Expenses
-- Import after database.sql, migration_006_accounts.sql and
-- migration_007_notifications.sql
--
-- Adds a "template" for expenses that repeat every month (rent,
-- subscriptions, salaries, etc). Nothing is posted to the `expenses`
-- table automatically — the monthly scanner (scan_recurring_expenses_due()
-- in includes/accounts.php, called once per request like the other
-- opportunistic scanners) only raises a notification once per rule per
-- month. A real expense row is only created when a user confirms it
-- from accounts/auto_expense_confirm.php, keeping a human in the loop
-- while removing the need to remember/retype the same expense monthly.
--
-- recurring_expense_runs is the one-row-per-rule-per-month dedupe/audit
-- log: it is what stops the scanner from notifying twice in the same
-- month, and it records whether the month was confirmed (-> expense_id)
-- or skipped.
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS recurring_expenses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36)        NOT NULL,
    category        VARCHAR(100)    NOT NULL,
    description     VARCHAR(255)    DEFAULT NULL,
    amount          DECIMAL(12,2)   NOT NULL,
    day_of_month    TINYINT UNSIGNED NOT NULL DEFAULT 1,
    payment_method  ENUM('cash','bank_transfer','upi','cheque','card','other') NOT NULL DEFAULT 'bank_transfer',
    vendor          VARCHAR(150)    DEFAULT NULL,
    reference       VARCHAR(100)    DEFAULT NULL,
    project_id      INT UNSIGNED    DEFAULT NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED    NOT NULL,
    created_at      DATETIME        NOT NULL,
    updated_at      DATETIME        NOT NULL,
    deleted_at      DATETIME        DEFAULT NULL,
    CONSTRAINT fk_recurring_expenses_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_recurring_expenses_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_recurring_expenses_day CHECK (day_of_month BETWEEN 1 AND 28),
    UNIQUE KEY uq_recurring_expenses_uuid (uuid),
    KEY idx_recurring_expenses_active (is_active),
    KEY idx_recurring_expenses_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recurring_expense_runs (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recurring_expense_id    INT UNSIGNED    NOT NULL,
    month_key               CHAR(7)         NOT NULL, -- 'YYYY-MM'
    status                  ENUM('pending','added','skipped') NOT NULL DEFAULT 'pending',
    expense_id              INT UNSIGNED    DEFAULT NULL,
    notified_at             DATETIME        NOT NULL,
    resolved_at             DATETIME        DEFAULT NULL,
    resolved_by             INT UNSIGNED    DEFAULT NULL,
    CONSTRAINT fk_recurring_runs_rule FOREIGN KEY (recurring_expense_id) REFERENCES recurring_expenses(id) ON DELETE CASCADE,
    CONSTRAINT fk_recurring_runs_expense FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE SET NULL,
    CONSTRAINT fk_recurring_runs_resolved_by FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_recurring_runs_rule_month (recurring_expense_id, month_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extend the existing notifications.type ENUM with 'auto_expense'
ALTER TABLE notifications
    MODIFY COLUMN type ENUM('deadline','follow_up','missed_lead','payment','meeting','leave','task','overdue_invoice','auto_expense','general') NOT NULL DEFAULT 'general';

SET FOREIGN_KEY_CHECKS = 1;
