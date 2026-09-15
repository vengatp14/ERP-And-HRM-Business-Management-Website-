-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_031_project_income_invoice_number.sql
-- Module: Accounts / Projects — Monthly Payments ledger
--
-- Gives every project_income row (both the automatic monthly/one-time
-- confirm flow and the manual "Add Payment" top-up) its own invoice
-- number, generated the same way GST invoice numbers are
-- (see includes/billing.php: next_invoice_number()) — a gap-free,
-- per-financial-year counter — but kept in a separate sequence/prefix
-- so it never collides with or implies a formal GST invoice. This is
-- deliberate: project_income is the informal monthly-collection
-- ledger (see migration_026_project_payment_plans.sql), so it gets
-- its own "receipt" numbering instead of borrowing invoices.invoice_number.
--
-- invoice_number is filled in going forward by the application
-- (record_project_income() / confirm_project_payment_run() in
-- includes/project_payments.php); existing rows are left NULL rather
-- than guessed at retroactively, same as invoice_number_sequences
-- was introduced without backfilling pre-existing invoices.
--
-- Import after migration_026_project_payment_plans.sql
-- (or run: mysql -u root -p crm_erp < this file)
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE project_income
    ADD COLUMN invoice_number VARCHAR(40) DEFAULT NULL AFTER plan_id,
    ADD UNIQUE KEY uq_project_income_invoice_number (invoice_number);

-- project_income_number_sequences — one counter per financial year
-- (Apr–Mar), mirroring invoice_number_sequences (migration_005_billing.sql)
-- so numbering restarts each year (e.g. PAY-2025-26-0001) instead of
-- running forever.
CREATE TABLE IF NOT EXISTS project_income_number_sequences (
    financial_year  VARCHAR(7)      NOT NULL PRIMARY KEY, -- e.g. '2025-26'
    last_number     INT UNSIGNED    NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
