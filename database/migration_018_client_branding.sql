-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_018_client_branding.sql
-- Module: Client Management — GST invoice branding
-- Adds optional logo/seal/signature storage to clients, so the GST
-- invoice print view can show the client's own letterhead/seal on
-- their side of the bill, alongside the company's on the other side.
-- Import after migration_003_clients.sql
-- (or run: mysql -u root -p crm_erp < this file)
-- =====================================================================

USE crm_erp;

ALTER TABLE clients
    ADD COLUMN logo_stored_filename      VARCHAR(255) DEFAULT NULL AFTER notes,
    ADD COLUMN logo_original_filename    VARCHAR(255) DEFAULT NULL AFTER logo_stored_filename,
    ADD COLUMN logo_mime                 VARCHAR(100) DEFAULT NULL AFTER logo_original_filename,
    ADD COLUMN seal_stored_filename      VARCHAR(255) DEFAULT NULL AFTER logo_mime,
    ADD COLUMN seal_original_filename    VARCHAR(255) DEFAULT NULL AFTER seal_stored_filename,
    ADD COLUMN seal_mime                 VARCHAR(100) DEFAULT NULL AFTER seal_original_filename,
    ADD COLUMN signature_stored_filename VARCHAR(255) DEFAULT NULL AFTER seal_mime,
    ADD COLUMN signature_original_filename VARCHAR(255) DEFAULT NULL AFTER signature_stored_filename,
    ADD COLUMN signature_mime            VARCHAR(100) DEFAULT NULL AFTER signature_original_filename;
