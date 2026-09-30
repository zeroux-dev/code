-- Repol schema (MySQL 5.7+/MariaDB 10.3+). Applied automatically on first request.
-- Times are stored as epoch milliseconds (BIGINT) to match the frontend.

CREATE TABLE IF NOT EXISTS users (
  id CHAR(36) PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(10) NOT NULL DEFAULT 'user',
  status VARCHAR(12) NOT NULL DEFAULT 'active',
  permissions TEXT NULL,
  phone VARCHAR(32) NOT NULL DEFAULT '',
  company VARCHAR(120) NOT NULL DEFAULT '',
  bio VARCHAR(500) NOT NULL DEFAULT '',
  avatar VARCHAR(20) NOT NULL DEFAULT 'violet',
  settings TEXT NULL,
  sub_plan VARCHAR(20) NOT NULL DEFAULT 'free',
  sub_expires BIGINT NOT NULL DEFAULT 0,
  balance BIGINT NOT NULL DEFAULT 0,
  session_version INT NOT NULL DEFAULT 0,
  email_verified BIGINT NOT NULL DEFAULT 0,
  created BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS otp_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  purpose VARCHAR(12) NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  payload TEXT NULL,
  attempts INT NOT NULL DEFAULT 0,
  expires BIGINT NOT NULL,
  created BIGINT NOT NULL,
  INDEX (email, purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  k VARCHAR(190) PRIMARY KEY,
  hits INT NOT NULL,
  reset_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rules (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  name VARCHAR(120) NOT NULL,
  trigger_type VARCHAR(12) NOT NULL,
  keywords TEXT NOT NULL,
  match_type VARCHAR(10) NOT NULL DEFAULT 'contains',
  response TEXT NOT NULL,
  comment_reply VARCHAR(300) NOT NULL DEFAULT '',
  follow_gate TINYINT NOT NULL DEFAULT 0,
  gate_message VARCHAR(600) NOT NULL DEFAULT '',
  file_id CHAR(36) NULL,
  enabled TINYINT NOT NULL DEFAULT 1,
  runs INT NOT NULL DEFAULT 0,
  created BIGINT NOT NULL,
  updated BIGINT NOT NULL,
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS files (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  name VARCHAR(200) NOT NULL,
  type VARCHAR(80) NOT NULL,
  size BIGINT NOT NULL,
  path VARCHAR(255) NOT NULL,
  public_token CHAR(48) NOT NULL UNIQUE,
  created BIGINT NOT NULL,
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  type VARCHAR(20) NOT NULL,
  text VARCHAR(500) NOT NULL,
  contact VARCHAR(80) NOT NULL DEFAULT '',
  rule_id CHAR(36) NULL,
  created BIGINT NOT NULL,
  INDEX (user_id, created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contacts (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  name VARCHAR(100) NOT NULL,
  handle VARCHAR(80) NOT NULL DEFAULT '',
  tag VARCHAR(40) NOT NULL DEFAULT '',
  note VARCHAR(500) NOT NULL DEFAULT '',
  ig_user_id VARCHAR(40) NULL,
  follows TINYINT NOT NULL DEFAULT 0,
  last_seen BIGINT NOT NULL DEFAULT 0,
  created BIGINT NOT NULL,
  UNIQUE KEY uniq_ig (user_id, ig_user_id),
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  contact_id CHAR(36) NOT NULL,
  direction VARCHAR(3) NOT NULL,
  text VARCHAR(2000) NOT NULL,
  created BIGINT NOT NULL,
  INDEX (user_id, contact_id, created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS templates (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  name VARCHAR(100) NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT '',
  text VARCHAR(1000) NOT NULL,
  created BIGINT NOT NULL,
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  title VARCHAR(120) NOT NULL,
  body VARCHAR(1500) NOT NULL,
  type VARCHAR(12) NOT NULL DEFAULT 'info',
  batch_id CHAR(36) NULL,
  created BIGINT NOT NULL,
  read_at BIGINT NULL,
  INDEX (user_id, created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id CHAR(36) PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  user_id CHAR(36) NOT NULL,
  request_id VARCHAR(64) NOT NULL,
  kind VARCHAR(14) NOT NULL,
  plan_id VARCHAR(20) NULL,
  days INT NOT NULL DEFAULT 0,
  amount BIGINT NOT NULL,
  amount_irr BIGINT NOT NULL,
  currency VARCHAR(4) NOT NULL,
  method VARCHAR(10) NOT NULL,
  status VARCHAR(10) NOT NULL,
  snapshot TEXT NOT NULL,
  proof VARCHAR(500) NOT NULL DEFAULT '',
  note VARCHAR(500) NOT NULL DEFAULT '',
  txid CHAR(64) NULL UNIQUE,
  authority VARCHAR(64) NULL UNIQUE,
  ref_id VARCHAR(40) NULL,
  paid_at BIGINT NULL,
  created BIGINT NOT NULL,
  expires BIGINT NOT NULL,
  UNIQUE KEY uniq_request (user_id, request_id),
  INDEX (status, method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallet_ledger (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  amount BIGINT NOT NULL,
  reason VARCHAR(200) NOT NULL,
  order_id CHAR(36) NULL,
  created BIGINT NOT NULL,
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
  batch_id CHAR(36) PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  body VARCHAR(1500) NOT NULL,
  type VARCHAR(12) NOT NULL,
  user_id CHAR(36) NULL,
  recipients INT NOT NULL,
  created BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  actor_id CHAR(36) NULL,
  actor_name VARCHAR(80) NOT NULL,
  action VARCHAR(200) NOT NULL,
  data TEXT NULL,
  created BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kv (
  k VARCHAR(64) PRIMARY KEY,
  v MEDIUMTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ig_accounts (
  user_id CHAR(36) PRIMARY KEY,
  ig_user_id VARCHAR(40) NOT NULL UNIQUE,
  username VARCHAR(80) NOT NULL,
  token TEXT NOT NULL,
  token_expires BIGINT NOT NULL,
  connected BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS follow_gates (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  igsid VARCHAR(40) NOT NULL,
  rule_id CHAR(36) NOT NULL,
  created BIGINT NOT NULL,
  done BIGINT NULL,
  INDEX (user_id, igsid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seen_events (
  id VARCHAR(120) PRIMARY KEY,
  created BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
