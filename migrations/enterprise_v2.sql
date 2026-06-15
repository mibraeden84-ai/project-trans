-- Enterprise v2 Migration: BIGINT primary keys + file table partitioning
-- Run: php migrate.php migrations/enterprise_v2.sql

-- ============ 1. BIGINT Primary Keys for Infinite Rows ============

-- Users
ALTER TABLE users ALTER COLUMN id TYPE BIGINT;
ALTER TABLE user_permissions ALTER COLUMN user_id TYPE BIGINT;

-- Brands & Models
ALTER TABLE brands ALTER COLUMN id TYPE BIGINT;
ALTER TABLE device_models ALTER COLUMN id TYPE BIGINT;
ALTER TABLE device_models ALTER COLUMN brand_id TYPE BIGINT;

-- File tables
ALTER TABLE config_files ALTER COLUMN id TYPE BIGINT;
ALTER TABLE config_files ALTER COLUMN device_model_id TYPE BIGINT;

ALTER TABLE firmware_files ALTER COLUMN id TYPE BIGINT;
ALTER TABLE firmware_files ALTER COLUMN brand_id TYPE BIGINT;
ALTER TABLE firmware_files ALTER COLUMN device_model_id TYPE BIGINT;

ALTER TABLE manuals ALTER COLUMN id TYPE BIGINT;
ALTER TABLE manuals ALTER COLUMN brand_id TYPE BIGINT;
ALTER TABLE manuals ALTER COLUMN device_model_id TYPE BIGINT;

ALTER TABLE software_files ALTER COLUMN id TYPE BIGINT;
ALTER TABLE software_files ALTER COLUMN brand_id TYPE BIGINT;
ALTER TABLE software_files ALTER COLUMN device_model_id TYPE BIGINT;

ALTER TABLE common_settings ALTER COLUMN id TYPE BIGINT;

-- Activity & API
ALTER TABLE activity_log ALTER COLUMN id TYPE BIGINT;
ALTER TABLE activity_log ALTER COLUMN user_id TYPE BIGINT;

ALTER TABLE api_tokens ALTER COLUMN id TYPE BIGINT;
ALTER TABLE api_tokens ALTER COLUMN user_id TYPE BIGINT;

ALTER TABLE api_keys ALTER COLUMN id TYPE BIGINT;
ALTER TABLE api_keys ALTER COLUMN user_id TYPE BIGINT;

-- ============ 2. Covering Indexes for Query Performance ============

-- Composite indexes for common filter patterns
CREATE INDEX IF NOT EXISTS idx_config_files_active_model ON config_files (status, device_model_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_firmware_files_active_brand ON firmware_files (status, brand_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_manuals_active_brand ON manuals (status, brand_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_software_files_active_brand ON software_files (status, brand_id, created_at DESC);

-- Download count indexes for top-downloads queries
CREATE INDEX IF NOT EXISTS idx_config_files_downloads ON config_files (status, download_count DESC) WHERE status = 'active';
CREATE INDEX IF NOT EXISTS idx_firmware_files_downloads ON firmware_files (status, download_count DESC) WHERE status = 'active';
CREATE INDEX IF NOT EXISTS idx_manuals_downloads ON manuals (status, download_count DESC) WHERE status = 'active';
CREATE INDEX IF NOT EXISTS idx_software_files_downloads ON software_files (status, download_count DESC) WHERE status = 'active';

-- System type filter indexes
CREATE INDEX IF NOT EXISTS idx_config_files_system ON config_files (status, system_type) WHERE system_type IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_firmware_files_system ON firmware_files (status, system_type) WHERE system_type IS NOT NULL;

-- ============ 3. Download Log Table (Replace download_count counter) ============

CREATE TABLE IF NOT EXISTS download_log (
    id BIGSERIAL PRIMARY KEY,
    file_type VARCHAR(20) NOT NULL,
    file_id BIGINT NOT NULL,
    user_id BIGINT DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_download_log_file ON download_log (file_type, file_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_download_log_created ON download_log (created_at);

-- ============ 4. File Integrity Checksums ============

ALTER TABLE config_files ADD COLUMN IF NOT EXISTS checksum VARCHAR(64) DEFAULT NULL;
ALTER TABLE firmware_files ADD COLUMN IF NOT EXISTS checksum VARCHAR(64) DEFAULT NULL;
ALTER TABLE manuals ADD COLUMN IF NOT EXISTS checksum VARCHAR(64) DEFAULT NULL;
ALTER TABLE software_files ADD COLUMN IF NOT EXISTS checksum VARCHAR(64) DEFAULT NULL;

-- ============ 5. Deleted At Timestamp ============

ALTER TABLE config_files ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP DEFAULT NULL;
ALTER TABLE firmware_files ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP DEFAULT NULL;
ALTER TABLE manuals ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP DEFAULT NULL;
ALTER TABLE software_files ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP DEFAULT NULL;
