-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_039_company_payment_qrs.sql
-- Module: Admin / GST Billing — payment QR codes (UPI/Google Pay/
-- PhonePe/etc.) configured from the website (Admin > Company Branding)
-- and shown in the Payment Details section of every generated bill.
-- A separate table (not more company_branding columns) because the
-- company can configure any number of QR codes, each identified by
-- its own payment method label — see includes/company_branding.php:
-- list_company_payment_qrs(). No rows configured = no QR section
-- printed (see req: never show an empty QR box).
-- Import after migration_038_company_bank_details.sql
-- (or run: mysql -u root -p crm_erp < this file)
-- =====================================================================

USE crm_erp;

CREATE TABLE IF NOT EXISTS company_payment_qrs (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    method_label        VARCHAR(100)    NOT NULL COMMENT 'e.g. "Google Pay / PhonePe / UPI"',
    qr_stored_filename  VARCHAR(255)    NOT NULL,
    qr_original_filename VARCHAR(255)   NOT NULL,
    qr_mime             VARCHAR(100)    NOT NULL,
    display_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at          DATETIME        NOT NULL,
    updated_at          DATETIME        NOT NULL,
    KEY idx_company_payment_qrs_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
