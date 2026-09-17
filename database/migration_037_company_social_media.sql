-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_037_company_social_media.sql
-- Module: Admin — lets the company's social media profiles (Instagram,
-- Facebook, X/Twitter, YouTube, LinkedIn) be configured from the
-- website (Admin > Company Branding) and shown on printed/soft-copy
-- bills (see billing/view.php). Stored as one JSON object rather than
-- a column per platform so the set stays easy to extend.
-- NULL/empty means "not configured" — that platform is simply left
-- off the bill (see includes/company_branding.php: company_social_links()).
-- Import after migration_028_company_gst_mobile.sql.
-- =====================================================================

USE crm_erp;

ALTER TABLE company_branding
    ADD COLUMN social_links TEXT DEFAULT NULL COMMENT 'JSON: {platform: {name, url}} — Instagram/Facebook/X/YouTube/LinkedIn profile name + URL' AFTER company_mobile;
