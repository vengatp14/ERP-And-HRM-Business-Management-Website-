-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_038_company_bank_details.sql
-- Module: Admin / GST Billing — lets the company's bank account
-- details be configured from the website (Admin > Company Branding)
-- and shown in the Payment Details section of every generated bill
-- (see billing/view.php and includes/company_branding.php:
-- company_bank_details()). Same fallback pattern as
-- migration_024_company_name_address.sql / migration_028: NULL/empty
-- means "not configured yet" — the bank details block is simply left
-- off the bill rather than showing blank rows.
-- Import after migration_037_company_social_media.sql
-- (or run: mysql -u root -p crm_erp < this file)
-- =====================================================================

USE crm_erp;

ALTER TABLE company_branding
    ADD COLUMN bank_account_name   VARCHAR(150) DEFAULT NULL AFTER social_links,
    ADD COLUMN bank_name           VARCHAR(150) DEFAULT NULL AFTER bank_account_name,
    ADD COLUMN bank_account_number VARCHAR(40)  DEFAULT NULL AFTER bank_name,
    ADD COLUMN bank_ifsc           VARCHAR(20)  DEFAULT NULL AFTER bank_account_number,
    ADD COLUMN bank_branch         VARCHAR(150) DEFAULT NULL AFTER bank_ifsc,
    ADD COLUMN bank_other_details  VARCHAR(255) DEFAULT NULL AFTER bank_branch;

-- Seed with the actual bank details supplied for this update. Still
-- fully editable afterwards from Admin > Company Branding — this only
-- avoids shipping with a blank/placeholder Payment Details section.
UPDATE company_branding
   SET bank_account_name   = 'Software development and graphic design',
       bank_account_number = '7301002100000415',
       bank_ifsc           = 'PUNB0730100',
       updated_at          = NOW()
 WHERE id = 1;
