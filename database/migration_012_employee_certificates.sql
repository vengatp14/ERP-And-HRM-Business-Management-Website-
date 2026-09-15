-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_012_employee_certificates.sql
-- Module: Employee Certificates (Pay Slip / Offer Letter / Experience
-- Certificate)
--
-- Each row is one issued document. `details` holds the type-specific
-- fields used to render it (pay figures, joining date, CTC, etc.) as
-- JSON, so a certificate can always be re-downloaded exactly as it was
-- issued even if the employee's current profile changes later.
-- =====================================================================

USE crm_erp;

CREATE TABLE IF NOT EXISTS employee_certificates (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id           INT UNSIGNED NOT NULL,
    type              ENUM('pay_slip', 'offer_letter', 'experience_certificate') NOT NULL,
    reference_label   VARCHAR(50)   DEFAULT NULL COMMENT 'e.g. pay month 2026-08, for quick display in lists',
    details           JSON          NOT NULL COMMENT 'type-specific fields used to render this certificate',
    issued_by         INT UNSIGNED  NOT NULL,
    issued_at         DATETIME      NOT NULL,
    created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_employee_certificates_user (user_id),
    KEY idx_employee_certificates_type (type),
    CONSTRAINT fk_employee_certificates_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_employee_certificates_issuer FOREIGN KEY (issued_by)
        REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
