-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_002_leads.sql
-- Module: Lead Management
-- Import after database.sql (or run: mysql -u root -p crm_erp < this file)
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- leads
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leads (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid              CHAR(36)        NOT NULL,
    client_name       VARCHAR(150)    NOT NULL,
    company           VARCHAR(150)    DEFAULT NULL,
    mobile            VARCHAR(20)     NOT NULL,
    whatsapp          VARCHAR(20)     DEFAULT NULL,
    email             VARCHAR(190)    DEFAULT NULL,
    address           VARCHAR(500)    DEFAULT NULL,
    gst_number        VARCHAR(20)     DEFAULT NULL,
    pan_number        VARCHAR(15)     DEFAULT NULL,
    budget            DECIMAL(12,2)   DEFAULT NULL,
    project_type      VARCHAR(100)    DEFAULT NULL,
    priority          ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    source            ENUM('website','referral','social_media','cold_call','walk_in','advertisement','other') NOT NULL DEFAULT 'other',
    status            ENUM('new','contacted','qualified','proposal','negotiation','won','lost','missed','cancelled') NOT NULL DEFAULT 'new',
    deadline          DATE            DEFAULT NULL,
    buffer_date       DATE            DEFAULT NULL,
    next_follow_up_at DATETIME        DEFAULT NULL,
    missed_attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    assigned_to       INT UNSIGNED    DEFAULT NULL,
    created_by        INT UNSIGNED    NOT NULL,
    created_at        DATETIME        NOT NULL,
    updated_at        DATETIME        NOT NULL,
    deleted_at        DATETIME        DEFAULT NULL,
    CONSTRAINT fk_leads_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_leads_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_leads_uuid (uuid),
    KEY idx_leads_status (status),
    KEY idx_leads_priority (priority),
    KEY idx_leads_assigned_to (assigned_to),
    KEY idx_leads_next_follow_up (next_follow_up_at),
    KEY idx_leads_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- lead_activities — timeline: notes, calls, WhatsApp, emails, follow-ups,
-- and automatic status-change entries.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lead_activities (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id             INT UNSIGNED    NOT NULL,
    user_id             INT UNSIGNED    DEFAULT NULL,
    type                ENUM('note','call','whatsapp','email','follow_up','status_change') NOT NULL,
    content             TEXT            DEFAULT NULL,
    client_response     ENUM('interested','busy','call_later','meeting_fixed','not_interested','no_response') DEFAULT NULL,
    next_follow_up_at   DATETIME        DEFAULT NULL,
    created_at          DATETIME        NOT NULL,
    CONSTRAINT fk_lead_activities_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_lead_activities_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_lead_activities_lead (lead_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- lead_documents — uploaded PDFs/images/documents attached to a lead.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lead_documents (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id             INT UNSIGNED    NOT NULL,
    uploaded_by         INT UNSIGNED    DEFAULT NULL,
    original_filename   VARCHAR(255)    NOT NULL,
    stored_filename     VARCHAR(255)    NOT NULL,
    file_size_bytes     INT UNSIGNED    NOT NULL,
    mime_type           VARCHAR(100)    NOT NULL,
    created_at          DATETIME        NOT NULL,
    CONSTRAINT fk_lead_documents_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_lead_documents_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_lead_documents_lead (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
