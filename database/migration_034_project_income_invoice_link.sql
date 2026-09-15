-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_034_project_income_invoice_link.sql
-- Module: Projects / Accounts — links project_income to invoice_payments
--
-- Problem this fixes: a completed project auto-drafts a GST invoice
-- (auto_generate_invoice_for_completed_project(), includes/billing.php)
-- whose amount_paid/status is computed only from invoice_payments.
-- Meanwhile "Add Payment" on projects/view.php writes to the separate
-- project_income table. The two never touched each other, so a
-- payment logged from the project page never showed up as "Paid" on
-- the project header or on the linked invoice/GST Billing page.
--
-- Fix: project_income now optionally carries the id of the
-- invoice_payments row it was mirrored into (see record_project_income()
-- in includes/project_payments.php), so:
--   - the linked invoice's amount_paid/status stay in sync
--     (recalculate_invoice_payment_status(), includes/billing.php)
--   - income totals that already sum invoice_payments and project_income
--     as two separate streams (total_income(), revenue_month_to_date(),
--     get_ledger_entries()) don't double-count the same rupee twice —
--     they exclude project_income rows once they're linked.
--
-- Import after migration_033_daily_update_rating.sql
-- (or run: mysql -u root -p crm_erp < this file)
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE project_income
    ADD COLUMN invoice_payment_id INT UNSIGNED DEFAULT NULL AFTER plan_id,
    ADD CONSTRAINT fk_project_income_invoice_payment
        FOREIGN KEY (invoice_payment_id) REFERENCES invoice_payments(id) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- One-off backfill: any project_income row recorded before this
-- migration, for a project that already has a linked invoice, has no
-- matching invoice_payments row yet — mirror it now so existing
-- payments (like the ₹8,000 already logged) show up as Paid
-- immediately instead of only for payments recorded from now on.
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
