-- Password security migration for non-super-admin first-login enforcement.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS token_version INT NOT NULL DEFAULT 0;

-- Run once only if you intend to force all existing admin/supervisor/staff users
-- to set private passwords at the next login.
UPDATE users u
INNER JOIN roles r ON r.id = u.role_id
SET u.must_change_password = 1
WHERE LOWER(REPLACE(TRIM(r.name), ' ', '_')) IN ('admin', 'supervisor', 'staff');
