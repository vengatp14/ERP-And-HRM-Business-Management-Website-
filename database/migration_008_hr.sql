-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_008_hr.sql
-- Module: Employee HR (Attendance, Leave, Salary, Meetings)
-- Import after database.sql (needs users table)
-- =====================================================================

USE crm_erp;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- employee_attendance — one row per employee per day. Employees punch
-- their own check-in/check-out; admins can also mark a day (e.g. as
-- absent or on_leave) directly.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS employee_attendance (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED    NOT NULL,
    attendance_date     DATE            NOT NULL,
    check_in_time       TIME            DEFAULT NULL,
    check_out_time      TIME            DEFAULT NULL,
    status              ENUM('present','absent','half_day','on_leave') NOT NULL DEFAULT 'present',
    notes               VARCHAR(255)    DEFAULT NULL,
    created_at          DATETIME        NOT NULL,
    updated_at          DATETIME        NOT NULL,
    CONSTRAINT fk_attendance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_attendance_user_date (user_id, attendance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- leave_requests
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leave_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    leave_type      VARCHAR(50)     NOT NULL DEFAULT 'General',
    start_date      DATE            NOT NULL,
    end_date        DATE            NOT NULL,
    reason          VARCHAR(500)    DEFAULT NULL,
    status          ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    approved_by     INT UNSIGNED    DEFAULT NULL,
    approved_at     DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL,
    CONSTRAINT fk_leave_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_leave_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_leave_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- employee_salaries — one row per employee per pay month.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS employee_salaries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    pay_month       CHAR(7)         NOT NULL COMMENT 'YYYY-MM',
    basic_pay       DECIMAL(12,2)   NOT NULL DEFAULT 0,
    allowances      DECIMAL(12,2)   NOT NULL DEFAULT 0,
    deductions      DECIMAL(12,2)   NOT NULL DEFAULT 0,
    net_pay         DECIMAL(12,2)   NOT NULL DEFAULT 0,
    status          ENUM('pending','paid') NOT NULL DEFAULT 'pending',
    paid_on         DATE            DEFAULT NULL,
    notes           VARCHAR(255)    DEFAULT NULL,
    created_by      INT UNSIGNED    NOT NULL,
    created_at      DATETIME        NOT NULL,
    updated_at      DATETIME        NOT NULL,
    CONSTRAINT fk_salary_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_salary_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_salary_user_month (user_id, pay_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- meetings + attendees
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS meetings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36)        NOT NULL,
    title           VARCHAR(190)    NOT NULL,
    description     TEXT            DEFAULT NULL,
    meeting_date    DATE            NOT NULL,
    start_time      TIME            DEFAULT NULL,
    end_time        TIME            DEFAULT NULL,
    location        VARCHAR(255)    DEFAULT NULL,
    created_by      INT UNSIGNED    NOT NULL,
    created_at      DATETIME        NOT NULL,
    updated_at      DATETIME        NOT NULL,
    CONSTRAINT fk_meetings_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_meetings_uuid (uuid),
    KEY idx_meetings_date (meeting_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_attendees (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meeting_id      INT UNSIGNED    NOT NULL,
    user_id         INT UNSIGNED    NOT NULL,
    CONSTRAINT fk_meeting_attendees_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
    CONSTRAINT fk_meeting_attendees_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_meeting_attendee (meeting_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
