-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_019_company_branding.sql
-- Module: Admin — company logo/seal/signature, uploadable from the
-- website (Admin > Company Branding) instead of replacing files on
-- the server by hand. Single-row table (id is always 1).
-- Import after migration_018_client_branding.sql
-- (or run: mysql -u root -p crm_erp < this file)
-- =====================================================================

USE crm_erp;

CREATE TABLE IF NOT EXISTS company_branding (
    id                          TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    logo_stored_filename        VARCHAR(255) DEFAULT NULL,
    logo_original_filename      VARCHAR(255) DEFAULT NULL,
    logo_mime                   VARCHAR(100) DEFAULT NULL,
    seal_stored_filename        VARCHAR(255) DEFAULT NULL,
    seal_original_filename      VARCHAR(255) DEFAULT NULL,
    seal_mime                   VARCHAR(100) DEFAULT NULL,
    signature_stored_filename   VARCHAR(255) DEFAULT NULL,
    signature_original_filename VARCHAR(255) DEFAULT NULL,
    signature_mime              VARCHAR(100) DEFAULT NULL,
    updated_at                  DATETIME DEFAULT NULL,
    CONSTRAINT chk_company_branding_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO company_branding (id) VALUES (1);
