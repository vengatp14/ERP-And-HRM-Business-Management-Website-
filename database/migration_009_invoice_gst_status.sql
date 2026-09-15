-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_009_invoice_gst_status.sql
-- Module: GST Billing — manual GST override + Completed status
-- Import after database/migration_005_billing.sql
--
-- Adds two things requested for the GST Billing module:
--   1. gst_override — GST was previously ALWAYS auto-decided from
--      whether the client has a GSTIN on file (see
--      invoice_gst_rate_for_client() in includes/billing.php). That's
--      now the default ('auto') but is overridable per invoice, so an
--      invoice can be explicitly forced to 'gst' or 'non_gst'
--      regardless of the client's GSTIN.
--   2. 'completed' added to the status ENUM, alongside the existing
--      'cancelled', so a finished/closed invoice can be distinguished
--      from one that's merely 'paid' but still open for reference.
-- =====================================================================

USE crm_erp;

ALTER TABLE invoices
    ADD COLUMN gst_override ENUM('auto','gst','non_gst') NOT NULL DEFAULT 'auto' AFTER is_interstate,
    MODIFY COLUMN status ENUM('draft','sent','paid','partially_paid','overdue','completed','cancelled') NOT NULL DEFAULT 'draft';
