-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_016_client_status.sql
-- Module: Client Management — additional statuses
-- Import after database/migration_003_clients.sql
--
-- Adds 'completed' and 'cancelled' to the clients.status ENUM,
-- alongside the existing 'active'/'inactive', so a client relationship
-- that has finished (project completed) or fallen through (cancelled)
-- can be tracked distinctly instead of just being marked inactive.
-- =====================================================================

USE crm_erp;

ALTER TABLE clients
    MODIFY COLUMN status ENUM('active','inactive','completed','cancelled') NOT NULL DEFAULT 'active';
