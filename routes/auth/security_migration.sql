-- Security migration: HttpOnly session invalidation and sign-in throttling.
-- Run this once before deploying the secure authentication build.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS token_version INT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS auth_login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  company_id INT NOT NULL,
  ip_address VARCHAR(64) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_attempt_window (email, company_id, ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
