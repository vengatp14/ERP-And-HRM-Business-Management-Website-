-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_004_projects.sql
-- Module: Project Management
-- Import after database.sql, migration_002_leads.sql, migration_003_clients.sql
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- projects
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS projects (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid              CHAR(36)        NOT NULL,
    client_id         INT UNSIGNED    NOT NULL,
    title             VARCHAR(190)    NOT NULL,
    description       TEXT            DEFAULT NULL,
    project_type      VARCHAR(100)    DEFAULT NULL,
    status            ENUM('planning','in_progress','on_hold','completed','cancelled') NOT NULL DEFAULT 'planning',
    priority          ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    budget            DECIMAL(12,2)   DEFAULT NULL,
    start_date        DATE            DEFAULT NULL,
    deadline          DATE            DEFAULT NULL,
    completed_at      DATETIME        DEFAULT NULL,
    manager_id        INT UNSIGNED    DEFAULT NULL,
    created_by        INT UNSIGNED    NOT NULL,
    created_at        DATETIME        NOT NULL,
    updated_at        DATETIME        NOT NULL,
    deleted_at        DATETIME        DEFAULT NULL,
    CONSTRAINT fk_projects_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_projects_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_projects_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_projects_uuid (uuid),
    KEY idx_projects_status (status),
    KEY idx_projects_client (client_id),
    KEY idx_projects_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- project_members — team assigned to a project (many-to-many with users)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_members (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id      INT UNSIGNED    NOT NULL,
    user_id         INT UNSIGNED    NOT NULL,
    role_on_project VARCHAR(100)    DEFAULT NULL,
    added_at        DATETIME        NOT NULL,
    CONSTRAINT fk_project_members_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_project_member (project_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- project_tasks — lightweight task/checklist tracking per project
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_tasks (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id      INT UNSIGNED    NOT NULL,
    title           VARCHAR(255)    NOT NULL,
    status          ENUM('pending','in_progress','done') NOT NULL DEFAULT 'pending',
    assigned_to     INT UNSIGNED    DEFAULT NULL,
    due_date        DATE            DEFAULT NULL,
    created_at      DATETIME        NOT NULL,
    updated_at      DATETIME        NOT NULL,
    CONSTRAINT fk_project_tasks_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_tasks_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_project_tasks_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- project_notes — activity/notes timeline, same pattern as client_notes
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS project_notes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id      INT UNSIGNED    NOT NULL,
    user_id         INT UNSIGNED    DEFAULT NULL,
    content         TEXT            NOT NULL,
    created_at      DATETIME        NOT NULL,
    CONSTRAINT fk_project_notes_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_notes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_project_notes_project (project_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
