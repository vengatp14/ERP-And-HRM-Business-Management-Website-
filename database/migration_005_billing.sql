-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_005_billing.sql
-- Module: GST Billing
-- Import after database.sql, migration_002_leads.sql,
-- migration_003_clients.sql, migration_004_projects.sql
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- invoices
-- Tax split follows Indian GST rules: sales within the company's own
-- state charge CGST+SGST (each half the GST rate); sales to a client
-- in a different state charge IGST (the full rate) instead. Which
-- applies is decided per-invoice from the client's state at the time
-- of billing and stored in is_interstate, rather than being
-- recalculated later — so a state change afterwards never rewrites
-- an already-issued invoice's tax treatment.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoices (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid                CHAR(36)        NOT NULL,
    invoice_number      VARCHAR(40)     NOT NULL,
    client_id           INT UNSIGNED    NOT NULL,
    project_id          INT UNSIGNED    DEFAULT NULL,
    invoice_date        DATE            NOT NULL,
    due_date            DATE            DEFAULT NULL,
    status              ENUM('draft','sent','paid','partially_paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
    place_of_supply     VARCHAR(100)    DEFAULT NULL,
    is_interstate       TINYINT(1)      NOT NULL DEFAULT 0,
    subtotal            DECIMAL(12,2)   NOT NULL DEFAULT 0,
    discount_amount     DECIMAL(12,2)   NOT NULL DEFAULT 0,
    taxable_amount      DECIMAL(12,2)   NOT NULL DEFAULT 0,
    cgst_amount         DECIMAL(12,2)   NOT NULL DEFAULT 0,
    sgst_amount         DECIMAL(12,2)   NOT NULL DEFAULT 0,
    igst_amount         DECIMAL(12,2)   NOT NULL DEFAULT 0,
    total_amount        DECIMAL(12,2)   NOT NULL DEFAULT 0,
    amount_paid         DECIMAL(12,2)   NOT NULL DEFAULT 0,
    notes               TEXT            DEFAULT NULL,
    terms               TEXT            DEFAULT NULL,
    created_by          INT UNSIGNED    NOT NULL,
    created_at          DATETIME        NOT NULL,
    updated_at          DATETIME        NOT NULL,
    deleted_at          DATETIME        DEFAULT NULL,
    CONSTRAINT fk_invoices_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoices_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_invoices_uuid (uuid),
    UNIQUE KEY uq_invoices_number (invoice_number),
    KEY idx_invoices_status (status),
    KEY idx_invoices_client (client_id),
    KEY idx_invoices_date (invoice_date),
    KEY idx_invoices_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- invoice_items — line items; tax is computed per line (rate can vary
-- by item, e.g. 18% services vs 5% goods) and rolled up into the
-- invoice's own cgst/sgst/igst columns on save.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoice_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id      INT UNSIGNED    NOT NULL,
    description     VARCHAR(255)    NOT NULL,
    hsn_sac         VARCHAR(20)     DEFAULT NULL,
    quantity        DECIMAL(10,2)   NOT NULL DEFAULT 1,
    unit_price      DECIMAL(12,2)   NOT NULL DEFAULT 0,
    gst_rate        DECIMAL(5,2)    NOT NULL DEFAULT 18.00,
    line_subtotal   DECIMAL(12,2)   NOT NULL DEFAULT 0,
    line_tax        DECIMAL(12,2)   NOT NULL DEFAULT 0,
    line_total      DECIMAL(12,2)   NOT NULL DEFAULT 0,
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    KEY idx_invoice_items_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- invoice_payments — receipts recorded against an invoice. Shared with
-- the Accounts module, which reads this table for its income ledger
-- rather than duplicating payment records.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoice_payments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id      INT UNSIGNED    NOT NULL,
    amount          DECIMAL(12,2)   NOT NULL,
    payment_date    DATE            NOT NULL,
    payment_method  ENUM('cash','bank_transfer','upi','cheque','card','other') NOT NULL DEFAULT 'bank_transfer',
    reference       VARCHAR(100)    DEFAULT NULL,
    notes           VARCHAR(255)    DEFAULT NULL,
    created_by      INT UNSIGNED    NOT NULL,
    created_at      DATETIME        NOT NULL,
    CONSTRAINT fk_invoice_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_payments_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    KEY idx_invoice_payments_invoice (invoice_id),
    KEY idx_invoice_payments_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- invoice_number_sequences — one counter per financial year (Apr–Mar,
-- the Indian FY) so invoice numbers reset and stay gap-free per year
-- (e.g. INV-2025-26-0001) rather than one running total forever.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoice_number_sequences (
    financial_year  VARCHAR(10)     NOT NULL PRIMARY KEY,
    last_number     INT UNSIGNED    NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
