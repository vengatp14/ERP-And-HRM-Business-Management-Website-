-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/reset_gst_billing_data.sql
--
-- Clears ALL invoice data (invoices, their line items, payments, and
-- the per-financial-year invoice number counter) so GST Billing starts
-- from a clean, empty table. Table STRUCTURE and columns are untouched
-- — only the rows inside are wiped — so nothing from
-- migration_005_billing.sql / migration_009_invoice_gst_status.sql
-- needs to be re-run afterwards.
--
-- ⚠️  WARNING: this permanently deletes every invoice, invoice line
--     item, and recorded payment in the database, across every
--     financial year. There is no undo. Take a DB backup first if you
--     want to keep any of this data.
--
-- How to run:
--   phpMyAdmin: open your crm_erp database → SQL tab → paste this file
--   → Go.
--   mysql CLI:  mysql -u root -p crm_erp < database/reset_gst_billing_data.sql
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE invoice_payments;
TRUNCATE TABLE invoice_items;
TRUNCATE TABLE invoices;
TRUNCATE TABLE invoice_number_sequences;

SET FOREIGN_KEY_CHECKS = 1;
