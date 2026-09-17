-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_040_quotations.sql
-- Module: Quotations — a common, reusable quotation template (company
-- info/branding/terms are pulled live from company_branding, exactly
-- like GST Billing does) with only the project-specific fields stored
-- per quotation. Reachable optionally from Lead -> Convert to Client
-- (see includes/leads.php: convert_won_lead_to_client()) or created
-- directly. Numbering mirrors invoice_number_sequences
-- (database/migration_005_billing.sql) but kept in its own sequence/
-- prefix so quotation numbers are never mistaken for GST invoice
-- numbers — see includes/quotations.php: next_quotation_number().
-- Import after migration_039_company_payment_qrs.sql
-- (or run: mysql -u root -p crm_erp < this file)
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS quotation_number_sequences (
    financial_year  VARCHAR(7)      NOT NULL PRIMARY KEY COMMENT 'e.g. "2026-27"',
    last_number     INT UNSIGNED    NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quotations (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid                CHAR(36)        NOT NULL,
    quotation_number    VARCHAR(40)     NOT NULL,
    client_id           INT UNSIGNED    NOT NULL,
    lead_id             INT UNSIGNED    DEFAULT NULL COMMENT 'Set when created via Lead -> Convert to Client -> Create Quotation',
    quotation_date      DATE            NOT NULL,
    valid_until         DATE            DEFAULT NULL,
    project_title       VARCHAR(190)    NOT NULL,
    website_type        ENUM('static','dynamic','ecommerce','crm_application','other') NOT NULL DEFAULT 'other',
    website_type_other  VARCHAR(100)    DEFAULT NULL COMMENT 'Label shown when website_type = other',
    scope_description   TEXT            DEFAULT NULL COMMENT 'Editable project scope / features / implementation',
    amount              DECIMAL(12,2)   NOT NULL DEFAULT 0.00 COMMENT 'Project/development charges before tax',
    gst_applicable      TINYINT(1)      NOT NULL DEFAULT 1,
    gst_rate            DECIMAL(5,2)    NOT NULL DEFAULT 18.00,
    gst_amount          DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    total_amount        DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    payment_terms       TEXT            DEFAULT NULL COMMENT 'e.g. 50% advance / 50% before final deployment, with computed amounts',
    terms_conditions    TEXT            DEFAULT NULL,
    notes               TEXT            DEFAULT NULL,
    status              ENUM('draft','sent','accepted','rejected') NOT NULL DEFAULT 'draft',
    created_by          INT UNSIGNED    NOT NULL,
    created_at          DATETIME        NOT NULL,
    updated_at          DATETIME        NOT NULL,
    deleted_at          DATETIME        DEFAULT NULL,
    CONSTRAINT fk_quotations_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_quotations_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL,
    CONSTRAINT fk_quotations_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_quotations_uuid (uuid),
    UNIQUE KEY uq_quotations_number (quotation_number),
    KEY idx_quotations_client (client_id),
    KEY idx_quotations_lead (lead_id),
    KEY idx_quotations_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Seed the actual Google Pay / UPI QR supplied for this update so the
-- Payment Details section on bills is populated immediately. The image
-- file ships at uploads/images/seed_gpay_qr_9c1a2f6e8b4d4a3f9c0e1b2a3d4e5f60.jpg.
-- Still fully editable/removable afterwards from Admin > Company Branding.
-- ---------------------------------------------------------------------
INSERT INTO company_payment_qrs (method_label, qr_stored_filename, qr_original_filename, qr_mime, display_order, created_at, updated_at)
SELECT 'Google Pay / PhonePe / Paytm / BHIM UPI', 'seed_gpay_qr_9c1a2f6e8b4d4a3f9c0e1b2a3d4e5f60.jpg', 'gpay-upi-qr.jpg', 'image/jpeg', 0, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM company_payment_qrs);
