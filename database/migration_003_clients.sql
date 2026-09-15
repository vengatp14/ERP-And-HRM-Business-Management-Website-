-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_003_clients.sql
-- Module: Client Management
-- Import after database.sql and migration_002_leads.sql
-- (or run: mysql -u root -p crm_erp < this file)
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- clients
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clients (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid              CHAR(36)        NOT NULL,
    company_name      VARCHAR(150)    NOT NULL,
    contact_name      VARCHAR(150)    NOT NULL,
    email             VARCHAR(190)    DEFAULT NULL,
    mobile            VARCHAR(20)     NOT NULL,
    whatsapp          VARCHAR(20)     DEFAULT NULL,
    gstin             VARCHAR(20)     DEFAULT NULL,
    pan_number        VARCHAR(15)     DEFAULT NULL,
    billing_address   VARCHAR(500)    DEFAULT NULL,
    city              VARCHAR(100)    DEFAULT NULL,
    state             VARCHAR(100)    DEFAULT NULL,
    pincode           VARCHAR(10)     DEFAULT NULL,
    source_lead_id    INT UNSIGNED    DEFAULT NULL,
    status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
    notes             TEXT            DEFAULT NULL,
    created_by        INT UNSIGNED    NOT NULL,
    created_at        DATETIME        NOT NULL,
    updated_at        DATETIME        NOT NULL,
    deleted_at        DATETIME        DEFAULT NULL,
    CONSTRAINT fk_clients_source_lead FOREIGN KEY (source_lead_id) REFERENCES leads(id) ON DELETE SET NULL,
    CONSTRAINT fk_clients_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_clients_uuid (uuid),
    KEY idx_clients_status (status),
    KEY idx_clients_company (company_name),
    KEY idx_clients_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- client_contacts — additional contact people at a client company
-- (the primary contact lives on clients itself; this covers extras).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS client_contacts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED    NOT NULL,
    name            VARCHAR(150)    NOT NULL,
    designation     VARCHAR(100)    DEFAULT NULL,
    email           VARCHAR(190)    DEFAULT NULL,
    mobile          VARCHAR(20)     DEFAULT NULL,
    created_at      DATETIME        NOT NULL,
    CONSTRAINT fk_client_contacts_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    KEY idx_client_contacts_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- client_notes — freeform timeline notes, mirrors the lead activity log
-- so client history reads the same way leads do.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS client_notes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED    NOT NULL,
    user_id         INT UNSIGNED    DEFAULT NULL,
    content         TEXT            NOT NULL,
    created_at      DATETIME        NOT NULL,
    CONSTRAINT fk_client_notes_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_notes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_client_notes_client (client_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
