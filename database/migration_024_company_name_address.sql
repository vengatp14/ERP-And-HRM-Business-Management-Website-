-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_024_company_name_address.sql
-- Module: Admin — lets the company name/address shown on certificates
-- and GST bills be edited from the website (Admin > Company Branding)
-- instead of only via the COMPANY_NAME/COMPANY_ADDRESS env vars.
-- NULL/empty means "not set yet" — the app keeps using the .env value
-- (or the "Enterprise CRM ERP" default) until an admin fills this in.
-- Import after migration_019_company_branding.sql
-- (or run: mysql -u root -p crm_erp < this file)
-- =====================================================================

USE crm_erp;

ALTER TABLE company_branding
    ADD COLUMN company_name    VARCHAR(255) DEFAULT NULL AFTER id,
    ADD COLUMN company_address TEXT         DEFAULT NULL AFTER company_name;
