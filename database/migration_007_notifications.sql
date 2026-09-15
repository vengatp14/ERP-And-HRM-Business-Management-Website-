-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_007_notifications.sql
-- Module: Notifications
-- Import after database.sql (needs users table)
--
-- No cron/queue in this stack, so delivery is on-demand: notifications
-- are created at the moment something notification-worthy happens
-- (opportunistically, the same pattern used by process_missed_leads()
-- and process_overdue_invoices()), and read via a navbar bell that
-- polls on each page load — not a websocket/SSE push.
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS notifications (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    type            ENUM('deadline','follow_up','missed_lead','payment','meeting','leave','task','overdue_invoice','general') NOT NULL DEFAULT 'general',
    title           VARCHAR(150)    NOT NULL,
    message         VARCHAR(500)    DEFAULT NULL,
    link            VARCHAR(255)    DEFAULT NULL,
    is_read         TINYINT(1)      NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_notifications_user (user_id, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
