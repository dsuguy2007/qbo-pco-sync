-- Schema for QBO–PCO Sync

CREATE TABLE IF NOT EXISTS sync_settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key VARCHAR(100) NOT NULL,
  setting_value TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_sync_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Basic user table for app login
CREATE TABLE IF NOT EXISTS app_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PCO funds cached locally
CREATE TABLE IF NOT EXISTS pco_funds (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pco_fund_id INT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_pco_funds_fund_id (pco_fund_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mapping from PCO fund -> QBO Class / Location
CREATE TABLE IF NOT EXISTS pco_fund_mappings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pco_fund_id INT UNSIGNED NOT NULL,
  qbo_class_name VARCHAR(255) DEFAULT NULL,
  qbo_location_name VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_pco_fund_mappings_fund_id (pco_fund_id),
  CONSTRAINT fk_pco_fund_mappings_fund
    FOREIGN KEY (pco_fund_id) REFERENCES pco_funds (pco_fund_id)
      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- QuickBooks OAuth tokens
CREATE TABLE IF NOT EXISTS qbo_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  realm_id VARCHAR(32) NOT NULL,
  access_token TEXT NOT NULL,
  refresh_token TEXT NOT NULL,
  token_type VARCHAR(32) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  access_token_expires_at DATETIME NULL,
  refresh_token_expires_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_qbo_tokens_realm_id (realm_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
