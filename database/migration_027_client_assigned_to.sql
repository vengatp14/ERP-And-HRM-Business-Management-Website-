-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_027_client_assigned_to.sql
-- Module: Client Management
-- Adds a direct "Assign To" field on clients so a client can be
-- assigned straight to an employee (instead of only being visible via
-- an assigned lead or a project). Import after migration_003_clients.sql.
-- (or run: mysql -u root -p crm_erp < this file)
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE clients
    ADD COLUMN assigned_to INT UNSIGNED DEFAULT NULL AFTER source_lead_id,
    ADD CONSTRAINT fk_clients_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    ADD KEY idx_clients_assigned_to (assigned_to);

SET FOREIGN_KEY_CHECKS = 1;
