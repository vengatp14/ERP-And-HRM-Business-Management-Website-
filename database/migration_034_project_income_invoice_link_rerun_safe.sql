-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_034_project_income_invoice_link_rerun_safe.sql
--
-- Re-runnable version of migration_034_project_income_invoice_link.sql.
-- Your first run already added the invoice_payment_id column (that's
-- why the plain version errored with "Duplicate column name" on the
-- second run) — this version checks INFORMATION_SCHEMA first and
-- skips the ALTER TABLE / constraint if they're already there, then
-- always runs the backfill (which is naturally safe to repeat, since
-- it only touches project_income rows that still have
-- invoice_payment_id IS NULL).
--
-- Run this INSTEAD of the plain migration_034 file.
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Add invoice_payment_id column, only if it doesn't already exist.
-- ---------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_income' AND COLUMN_NAME = 'invoice_payment_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE project_income ADD COLUMN invoice_payment_id INT UNSIGNED DEFAULT NULL AFTER plan_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- Add the FK constraint, only if it doesn't already exist.
-- ---------------------------------------------------------------------
SET @fk_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_income' AND CONSTRAINT_NAME = 'fk_project_income_invoice_payment'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE project_income ADD CONSTRAINT fk_project_income_invoice_payment FOREIGN KEY (invoice_payment_id) REFERENCES invoice_payments(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Backfill: mirror any project_income row (for a project that already
-- has a linked invoice) into invoice_payments, if it hasn't been
-- mirrored yet. Safe to run more than once — only touches rows where
-- invoice_payment_id IS NULL, so already-linked rows are left alone.
-- ---------------------------------------------------------------------

INSERT INTO invoice_payments (invoice_id, amount, payment_date, payment_method, reference, notes, created_by, created_at)
SELECT inv.invoice_id, pi.amount, pi.payment_date, pi.payment_method, pi.reference,
       CONCAT('Backfilled from project payment', CASE WHEN pi.invoice_number IS NOT NULL THEN CONCAT(' (', pi.invoice_number, ')') ELSE '' END),
       pi.created_by, pi.created_at
FROM project_income pi
JOIN (
    SELECT project_id, MIN(id) AS invoice_id FROM invoices WHERE deleted_at IS NULL GROUP BY project_id
) inv ON inv.project_id = pi.project_id
WHERE pi.deleted_at IS NULL AND pi.invoice_payment_id IS NULL;

UPDATE project_income pi
JOIN (
    SELECT project_id, MIN(id) AS invoice_id FROM invoices WHERE deleted_at IS NULL GROUP BY project_id
) inv ON inv.project_id = pi.project_id
JOIN invoice_payments ip ON ip.invoice_id = inv.invoice_id
    AND ip.amount = pi.amount AND ip.payment_date = pi.payment_date AND ip.created_at = pi.created_at
SET pi.invoice_payment_id = ip.id
WHERE pi.deleted_at IS NULL AND pi.invoice_payment_id IS NULL;

-- Recompute amount_paid/status on every invoice touched by the backfill above.
UPDATE invoices inv
JOIN (
    SELECT invoice_id, COALESCE(SUM(amount), 0) AS paid FROM invoice_payments GROUP BY invoice_id
) totals ON totals.invoice_id = inv.id
SET inv.amount_paid = totals.paid,
    inv.status = CASE
        WHEN inv.status IN ('cancelled', 'completed') THEN inv.status
        WHEN totals.paid >= inv.total_amount AND inv.total_amount > 0 THEN 'paid'
        WHEN totals.paid > 0 THEN 'partially_paid'
        WHEN inv.due_date IS NOT NULL AND inv.due_date < CURDATE() THEN 'overdue'
        WHEN inv.status = 'draft' AND totals.paid <= 0 THEN 'draft'
        ELSE 'sent'
    END
WHERE inv.deleted_at IS NULL;
