-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/database.sql
-- Import directly into phpMyAdmin, or: mysql -u root -p < database.sql
-- =====================================================================

CREATE DATABASE IF NOT EXISTS crm_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid                  CHAR(36)            NOT NULL,
    role                  ENUM('super_admin','admin','employee') NOT NULL DEFAULT 'employee',
    full_name             VARCHAR(150)        NOT NULL,
    email                 VARCHAR(190)        NOT NULL,
    mobile                VARCHAR(20)         DEFAULT NULL,
    password_hash         VARCHAR(255)        NOT NULL,
    profile_photo         VARCHAR(255)        DEFAULT NULL,
    department            VARCHAR(100)        DEFAULT NULL,
    designation           VARCHAR(100)        DEFAULT NULL,
    status                ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    email_verified_at     DATETIME            DEFAULT NULL,
    must_change_password  TINYINT(1)          NOT NULL DEFAULT 0,
    two_factor_enabled    TINYINT(1)          NOT NULL DEFAULT 0,
    last_login_at         DATETIME            DEFAULT NULL,
    created_at            DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at            DATETIME            DEFAULT NULL,
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_uuid (uuid),
    KEY idx_users_role (role),
    KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- login_attempts — powers the login rate limiter / lockout
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(190)    NOT NULL,
    ip_address      VARCHAR(45)     NOT NULL,
    successful      TINYINT(1)      NOT NULL DEFAULT 0,
    user_agent      VARCHAR(255)    DEFAULT NULL,
    attempted_at    DATETIME        NOT NULL,
    KEY idx_login_attempts_email_time (email, attempted_at),
    KEY idx_login_attempts_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- login_history
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_history (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    ip_address      VARCHAR(45)     NOT NULL,
    user_agent      VARCHAR(255)    DEFAULT NULL,
    logged_in_at    DATETIME        NOT NULL,
    CONSTRAINT fk_login_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_login_history_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- password_resets
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(190)    NOT NULL,
    token_hash      CHAR(64)        NOT NULL,
    expires_at      DATETIME        NOT NULL,
    used_at         DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_password_resets_email (email),
    KEY idx_password_resets_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- otp_verifications
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS otp_verifications (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    DEFAULT NULL,
    email           VARCHAR(190)    NOT NULL,
    otp_hash        CHAR(64)        NOT NULL,
    purpose         ENUM('login','password_reset') NOT NULL DEFAULT 'login',
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at      DATETIME        NOT NULL,
    consumed_at     DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_otp_email_purpose (email, purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- remember_tokens — selector/validator pattern (raw token never stored)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS remember_tokens (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    selector        CHAR(24)        NOT NULL,
    validator_hash  CHAR(64)        NOT NULL,
    expires_at      DATETIME        NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_remember_selector (selector)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- audit_logs
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    DEFAULT NULL,
    action          VARCHAR(100)    NOT NULL,
    module          VARCHAR(100)    NOT NULL,
    description     VARCHAR(500)    DEFAULT NULL,
    ip_address      VARCHAR(45)     DEFAULT NULL,
    user_agent      VARCHAR(255)    DEFAULT NULL,
    created_at      DATETIME        NOT NULL,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_audit_module (module),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
