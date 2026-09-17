-- =====================================================================
-- Enterprise CRM ERP — Core PHP Edition
-- database/migration_036_attendance_geolocation.sql
-- Module: HR — Attendance
-- Captures the employee's actual device coordinates (browser
-- Geolocation API) at the moment they punch in/out, so the Admin can
-- see where a punch was made from. See includes/hr.php (punch_in()/
-- punch_out()) and hr/attendance.php (the "View Location" column).
-- Import after migration_008_hr.sql.
-- =====================================================================

USE crm_erp;

ALTER TABLE employee_attendance
    ADD COLUMN check_in_latitude  DECIMAL(10,7) NULL DEFAULT NULL COMMENT 'Device latitude captured at punch-in, browser Geolocation API' AFTER check_in_time,
    ADD COLUMN check_in_longitude DECIMAL(10,7) NULL DEFAULT NULL COMMENT 'Device longitude captured at punch-in, browser Geolocation API' AFTER check_in_latitude,
    ADD COLUMN check_out_latitude  DECIMAL(10,7) NULL DEFAULT NULL COMMENT 'Device latitude captured at punch-out, browser Geolocation API' AFTER check_out_time,
    ADD COLUMN check_out_longitude DECIMAL(10,7) NULL DEFAULT NULL COMMENT 'Device longitude captured at punch-out, browser Geolocation API' AFTER check_out_latitude;
