-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_028_company_gst_mobile.sql
-- Module: Admin — lets the company's GSTIN and mobile number shown on
-- GST bills/reports be edited from the website (Admin > Company
-- Branding) instead of only via the COMPANY_GSTIN/COMPANY_MOBILE env
-- vars. NULL/empty means "not set yet" — the app keeps using the .env
-- value until an admin fills this in (same pattern as
-- migration_024_company_name_address.sql).
-- Import after migration_024_company_name_address.sql
-- (or run: mysql -u root -p crm_erp < this file)
-- =====================================================================

USE crm_erp;

ALTER TABLE company_branding
    ADD COLUMN company_gstin  VARCHAR(20) DEFAULT NULL AFTER company_address,
    ADD COLUMN company_mobile VARCHAR(20) DEFAULT NULL AFTER company_gstin;
