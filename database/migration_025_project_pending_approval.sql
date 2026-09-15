-- migration_025_project_pending_approval.sql
--
-- Adds a 'pending_approval' status to the project pipeline. Employees
-- can no longer set a project straight to 'completed' themselves — an
-- employee marking a project complete now lands it in
-- 'pending_approval' instead, where it shows as its own column on the
-- (admin-only) Project Board. Only an admin/super_admin accepting the
-- move on the board (or setting it directly) advances it to
-- 'completed', which is also what triggers the automatic GST invoice
-- draft in auto_generate_invoice_for_completed_project().

ALTER TABLE projects
    MODIFY COLUMN status ENUM('planning','in_progress','on_hold','pending_approval','completed','cancelled')
    NOT NULL DEFAULT 'planning';
