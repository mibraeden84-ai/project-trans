-- Translink GPS Library - PostgreSQL schema
-- Run through install.php, or with: psql -d translink_gps -f schema.sql

CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    image VARCHAR(255) DEFAULT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user' CHECK (role IN ('viewer', 'user', 'editor', 'admin')),
    is_active SMALLINT NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    last_login_at TIMESTAMP DEFAULT NULL,
    last_seen_at TIMESTAMP DEFAULT NULL,
    total_active_seconds BIGINT NOT NULL DEFAULT 0,
    total_downloads BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_users_username ON users (username);
CREATE INDEX IF NOT EXISTS idx_users_role ON users (role);
CREATE INDEX IF NOT EXISTS idx_users_active ON users (is_active);

CREATE TABLE IF NOT EXISTS brands (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT DEFAULT NULL,
    icon VARCHAR(20) DEFAULT 'GPS',
    color VARCHAR(7) DEFAULT '#1a73e8',
    image VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_brands_slug ON brands (slug);
CREATE INDEX IF NOT EXISTS idx_brands_name ON brands (name);

CREATE TABLE IF NOT EXISTS device_models (
    id SERIAL PRIMARY KEY,
    brand_id INTEGER NOT NULL REFERENCES brands(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    image_url VARCHAR(255) DEFAULT NULL,
    system_type VARCHAR(20) DEFAULT NULL CHECK (system_type IS NULL OR system_type IN ('advanced', 'standard')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_brand_model UNIQUE (brand_id, slug)
);

CREATE INDEX IF NOT EXISTS idx_models_brand ON device_models (brand_id);
CREATE INDEX IF NOT EXISTS idx_models_name ON device_models (name);
CREATE INDEX IF NOT EXISTS idx_models_system ON device_models (system_type);
CREATE INDEX IF NOT EXISTS idx_models_search ON device_models USING gin (to_tsvector('simple', coalesce(name, '') || ' ' || coalesce(description, '')));

CREATE TABLE IF NOT EXISTS config_files (
    id SERIAL PRIMARY KEY,
    category VARCHAR(50) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'deleted')),
    device_model_id INTEGER DEFAULT NULL REFERENCES device_models(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    system_type VARCHAR(20) DEFAULT NULL CHECK (system_type IS NULL OR system_type IN ('advanced', 'standard')),
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT DEFAULT 0 CHECK (file_size >= 0),
    version VARCHAR(50) DEFAULT '1.0',
    description TEXT DEFAULT NULL,
    download_count INTEGER DEFAULT 0 CHECK (download_count >= 0),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_config_model ON config_files (device_model_id);
CREATE INDEX IF NOT EXISTS idx_config_version ON config_files (version);
CREATE INDEX IF NOT EXISTS idx_config_downloads ON config_files (download_count);
CREATE INDEX IF NOT EXISTS idx_config_search ON config_files USING gin (to_tsvector('simple', coalesce(name, '') || ' ' || coalesce(description, '')));

CREATE TABLE IF NOT EXISTS firmware_files (
    id SERIAL PRIMARY KEY,
    category VARCHAR(50) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'deleted')),
    brand_id INTEGER NOT NULL REFERENCES brands(id) ON DELETE CASCADE,
    device_model_id INTEGER DEFAULT NULL REFERENCES device_models(id) ON DELETE SET NULL,
    name VARCHAR(255) NOT NULL,
    system_type VARCHAR(20) DEFAULT NULL CHECK (system_type IS NULL OR system_type IN ('advanced', 'standard')),
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT DEFAULT 0 CHECK (file_size >= 0),
    version VARCHAR(50) DEFAULT '1.0',
    changelog TEXT DEFAULT NULL,
    download_count INTEGER DEFAULT 0 CHECK (download_count >= 0),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_firmware_brand ON firmware_files (brand_id);
CREATE INDEX IF NOT EXISTS idx_firmware_model ON firmware_files (device_model_id);
CREATE INDEX IF NOT EXISTS idx_firmware_version ON firmware_files (version);
CREATE INDEX IF NOT EXISTS idx_firmware_search ON firmware_files USING gin (to_tsvector('simple', coalesce(name, '') || ' ' || coalesce(changelog, '')));

CREATE TABLE IF NOT EXISTS common_settings (
    id SERIAL PRIMARY KEY,
    category VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT DEFAULT 0 CHECK (file_size >= 0),
    description TEXT DEFAULT NULL,
    download_count INTEGER DEFAULT 0 CHECK (download_count >= 0),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_common_category ON common_settings (category);
CREATE INDEX IF NOT EXISTS idx_common_search ON common_settings USING gin (to_tsvector('simple', coalesce(name, '') || ' ' || coalesce(description, '') || ' ' || coalesce(category, '')));

CREATE TABLE IF NOT EXISTS software_files (
    id SERIAL PRIMARY KEY,
    category VARCHAR(50) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'deleted')),
    brand_id INTEGER DEFAULT NULL REFERENCES brands(id) ON DELETE CASCADE,
    device_model_id INTEGER DEFAULT NULL REFERENCES device_models(id) ON DELETE SET NULL,
    name VARCHAR(255) NOT NULL,
    system_type VARCHAR(20) DEFAULT NULL CHECK (system_type IS NULL OR system_type IN ('advanced', 'standard')),
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT DEFAULT 0 CHECK (file_size >= 0),
    version VARCHAR(50) DEFAULT '1.0',
    description TEXT DEFAULT NULL,
    download_count INTEGER DEFAULT 0 CHECK (download_count >= 0),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_software_brand ON software_files (brand_id);
CREATE INDEX IF NOT EXISTS idx_software_model ON software_files (device_model_id);
CREATE INDEX IF NOT EXISTS idx_software_search ON software_files USING gin (to_tsvector('simple', coalesce(name, '') || ' ' || coalesce(description, '')));

CREATE TABLE IF NOT EXISTS manuals (
    id SERIAL PRIMARY KEY,
    category VARCHAR(50) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'deleted')),
    brand_id INTEGER DEFAULT NULL REFERENCES brands(id) ON DELETE CASCADE,
    device_model_id INTEGER DEFAULT NULL REFERENCES device_models(id) ON DELETE SET NULL,
    name VARCHAR(255) NOT NULL,
    system_type VARCHAR(20) DEFAULT NULL CHECK (system_type IS NULL OR system_type IN ('advanced', 'standard')),
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT DEFAULT 0 CHECK (file_size >= 0),
    description TEXT DEFAULT NULL,
    download_count INTEGER DEFAULT 0 CHECK (download_count >= 0),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_manuals_brand ON manuals (brand_id);
CREATE INDEX IF NOT EXISTS idx_manuals_model ON manuals (device_model_id);
CREATE INDEX IF NOT EXISTS idx_manuals_search ON manuals USING gin (to_tsvector('simple', coalesce(name, '') || ' ' || coalesce(description, '')));

CREATE TABLE IF NOT EXISTS user_permissions (
    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    can_upload SMALLINT NOT NULL DEFAULT 1 CHECK (can_upload IN (0, 1)),
    can_delete SMALLINT NOT NULL DEFAULT 0 CHECK (can_delete IN (0, 1)),
    can_manage_brands SMALLINT NOT NULL DEFAULT 0 CHECK (can_manage_brands IN (0, 1)),
    can_manage_models SMALLINT NOT NULL DEFAULT 0 CHECK (can_manage_models IN (0, 1))
);

CREATE TABLE IF NOT EXISTS activity_log (
    id SERIAL PRIMARY KEY,
    action VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INTEGER DEFAULT NULL,
    entity_name VARCHAR(255) DEFAULT NULL,
    details TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_activity_created ON activity_log (created_at);
CREATE INDEX IF NOT EXISTS idx_activity_action ON activity_log (action, entity_type);

CREATE TABLE IF NOT EXISTS user_downloads (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL,
    file_type VARCHAR(20) NOT NULL,
    file_id BIGINT DEFAULT NULL,
    file_name VARCHAR(255) DEFAULT NULL,
    downloaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_user_downloads_user_date ON user_downloads (user_id, downloaded_at);
CREATE INDEX IF NOT EXISTS idx_user_downloads_date ON user_downloads (downloaded_at);

CREATE OR REPLACE FUNCTION touch_updated_at()
RETURNS trigger AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_config_files_updated_at ON config_files;
CREATE TRIGGER trg_config_files_updated_at
BEFORE UPDATE ON config_files
FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

DROP TRIGGER IF EXISTS trg_firmware_files_updated_at ON firmware_files;
CREATE TRIGGER trg_firmware_files_updated_at
BEFORE UPDATE ON firmware_files
FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

DROP TRIGGER IF EXISTS trg_software_files_updated_at ON software_files;
CREATE TRIGGER trg_software_files_updated_at
BEFORE UPDATE ON software_files
FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

DROP TRIGGER IF EXISTS trg_manuals_updated_at ON manuals;
CREATE TRIGGER trg_manuals_updated_at
BEFORE UPDATE ON manuals
FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Seed data

INSERT INTO brands (name, slug, description, icon, color) VALUES
('Teltonika', 'teltonika', 'Professional GPS trackers with advanced vehicle monitoring, OBDII, and CAN-Bus support', 'GPS', '#005aa0'),
('GalileoSky', 'galileosky', 'Reliable GPS tracking devices with robust offline data logging and multi-interface support', 'GEO', '#e67e22'),
('StarLink', 'starlink', 'Simple and cost-effective GPS trackers for basic fleet tracking needs', 'STAR', '#f1c40f'),
('Dash Cam', 'dash-cam', 'Video telematics and dash cam solutions for fleet safety and driver monitoring', 'CAM', '#e74c3c')
ON CONFLICT (slug) DO UPDATE SET
    name = EXCLUDED.name,
    description = EXCLUDED.description,
    icon = EXCLUDED.icon,
    color = EXCLUDED.color;

INSERT INTO device_models (brand_id, name, slug, description, system_type)
SELECT b.id, v.name, v.slug, v.description, v.system_type
FROM (VALUES
    ('teltonika', 'FMB920', 'fmb920', 'Basic GNSS/GSM tracker with 1-Wire and analog inputs', 'standard'),
    ('teltonika', 'FMC920', 'fmc920', 'CAN-Bus enabled GNSS/GSM tracker for vehicle diagnostics', 'advanced'),
    ('teltonika', 'FMB130', 'fmb130', 'Compact GNSS/GSM tracker with analog and digital inputs', 'standard'),
    ('teltonika', 'FMB125', 'fmb125', 'Ultra-compact GNSS/GSM tracker with 1-Wire support', 'standard'),
    ('teltonika', 'FMB140', 'fmb140', 'Advanced GNSS/GSM tracker with CAN-Bus, 1-Wire, and analog inputs', 'advanced'),
    ('teltonika', 'FMC150', 'fmc150', 'Premium CAN-Bus GNSS/GSM tracker with extensive I/O support', 'advanced'),
    ('galileosky', 'GalileoSky V7', 'galileosky-v7', 'Standard GPS tracker with reliable performance and offline logging', NULL),
    ('starlink', 'STL100', 'stl100', 'Basic GPS tracker with real-time tracking and geo-fencing', 'standard'),
    ('starlink', 'STL300', 'stl300', 'Advanced GPS tracker with dual-GSM and extended battery', 'advanced'),
    ('dash-cam', 'DC100', 'dc100', '1080p dash cam with GPS logging and collision detection', NULL),
    ('dash-cam', 'DC200', 'dc200', '4G LTE dash cam with dual cameras and cloud upload', NULL),
    ('dash-cam', 'MDVR300', 'mdvr300', 'Multi-channel MDVR for fleet video surveillance', NULL)
) AS v(brand_slug, name, slug, description, system_type)
JOIN brands b ON b.slug = v.brand_slug
ON CONFLICT (brand_id, slug) DO UPDATE SET
    name = EXCLUDED.name,
    description = EXCLUDED.description,
    system_type = EXCLUDED.system_type;

INSERT INTO config_files (device_model_id, category, status, name, system_type, file_path, file_size, version, description)
SELECT dm.id, 'config', 'active', v.name, v.system_type, v.file_path, v.file_size, v.version, v.description
FROM (VALUES
    ('teltonika', 'fmb920', 'FMB920_Translink_Default_v1.cfg', 'standard', 'uploads/configs/FMB920_Translink_Default_v1.cfg', 330, '1.0', 'Default Translink configuration for FMB920 - server 88.99.188.166:2050, APN internet.et'),
    ('teltonika', 'fmb920', 'FMB920_Standard_Config_v1.txt', 'standard', 'uploads/configs/FMB920_Standard_Config_v1.txt', 340, '1.0', 'Standard tracking system config for FMB920 with basic I/O and features'),
    ('teltonika', 'fmc920', 'FMC920_Translink_Default_v1.cfg', 'advanced', 'uploads/configs/FMC920_Translink_Default_v1.cfg', 344, '1.0', 'Default Translink configuration for FMC920 with CAN-Bus enabled'),
    ('teltonika', 'fmc920', 'FMC920_Advanced_Config_v1.txt', 'advanced', 'uploads/configs/FMC920_Advanced_Config_v1.txt', 420, '1.0', 'Advanced tracking system config for FMC920 with full CAN-Bus and OBDII'),
    ('teltonika', 'fmb130', 'FMB130_Translink_Default_v1.cfg', 'standard', 'uploads/configs/FMB130_Translink_Default_v1.cfg', 344, '1.0', 'Default Translink configuration for FMB130 with analog input support'),
    ('teltonika', 'fmb130', 'FMB130_Standard_Config_v1.txt', 'standard', 'uploads/configs/FMB130_Standard_Config_v1.txt', 280, '1.0', 'Standard tracking system config for FMB130 compact tracker'),
    ('teltonika', 'fmb125', 'FMB125_Translink_Default_v1.cfg', 'standard', 'uploads/configs/FMB125_Translink_Default_v1.cfg', 346, '1.0', 'Default Translink configuration for FMB125 ultra-compact tracker'),
    ('teltonika', 'fmb125', 'FMB125_Standard_Config_v1.txt', 'standard', 'uploads/configs/FMB125_Standard_Config_v1.txt', 200, '1.0', 'Standard tracking system config for FMB125 ultra-compact tracker'),
    ('teltonika', 'fmb140', 'FMB140_Translink_Default_v1.cfg', 'advanced', 'uploads/configs/FMB140_Translink_Default_v1.cfg', 374, '1.0', 'Default Translink configuration for FMB140 with CAN-Bus + 1-Wire support'),
    ('teltonika', 'fmb140', 'FMB140_Advanced_Config_v1.txt', 'advanced', 'uploads/configs/FMB140_Advanced_Config_v1.txt', 380, '1.0', 'Advanced tracking system config for FMB140 with CAN-Bus and OBDII'),
    ('teltonika', 'fmc150', 'FMC150_Translink_Default_v1.cfg', 'advanced', 'uploads/configs/FMC150_Translink_Default_v1.cfg', 344, '1.0', 'Default Translink configuration for FMC150 premium CAN-Bus tracker'),
    ('teltonika', 'fmc150', 'FMC150_Advanced_Config_v1.txt', 'advanced', 'uploads/configs/FMC150_Advanced_Config_v1.txt', 350, '1.0', 'Advanced tracking system config for FMC150 premium tracker'),
    ('galileosky', 'galileosky-v7', 'GalileoSky_V7_Config_v1.txt', 'standard', 'uploads/configs/GalileoSky_V7_Config_v1.txt', 360, '1.0', 'Standard configuration for GalileoSky V7 with offline logging')
) AS v(brand_slug, model_slug, name, system_type, file_path, file_size, version, description)
JOIN brands b ON b.slug = v.brand_slug
JOIN device_models dm ON dm.brand_id = b.id AND dm.slug = v.model_slug
WHERE NOT EXISTS (
    SELECT 1 FROM config_files c WHERE c.device_model_id = dm.id AND c.name = v.name
);

INSERT INTO firmware_files (brand_id, device_model_id, category, status, name, system_type, file_path, file_size, version, changelog)
SELECT b.id, dm.id, 'firmware', 'active', v.name, v.system_type, v.file_path, v.file_size, v.version, v.changelog
FROM (VALUES
    ('teltonika', NULL, 'Teltonika_Advanced_FW_Package_v2.0', 'advanced', 'uploads/firmware/teltonika_advanced_fw.txt', 0, '2.0', 'Advanced firmware for CAN-Bus and OBDII models - improved diagnostics'),
    ('teltonika', NULL, 'Teltonika_Standard_FW_Package_v1.5', 'standard', 'uploads/firmware/teltonika_standard_fw.txt', 0, '1.5', 'Standard firmware for basic tracker models - stability improvements'),
    ('galileosky', 'galileosky-v7', 'GalileoSky_V7_Firmware_v2.1', 'standard', 'uploads/firmware/galileosky_fw_update.txt', 0, '2.1', 'Updated GPS module driver, improved offline logging'),
    ('starlink', NULL, 'StarLink_Firmware_v1.5', NULL, 'uploads/firmware/starlink_fw_update.txt', 0, '1.5', 'Bug fixes, improved APN auto-detection')
) AS v(brand_slug, model_slug, name, system_type, file_path, file_size, version, changelog)
JOIN brands b ON b.slug = v.brand_slug
LEFT JOIN device_models dm ON dm.brand_id = b.id AND dm.slug = v.model_slug
WHERE NOT EXISTS (
    SELECT 1 FROM firmware_files f WHERE f.brand_id = b.id AND f.name = v.name
);

INSERT INTO manuals (brand_id, device_model_id, category, status, name, system_type, file_path, file_size, description)
SELECT b.id, dm.id, 'manual', 'active', v.name, v.system_type, v.file_path, v.file_size, v.description
FROM (VALUES
    ('teltonika', NULL, 'Teltonika_Advanced_Installation_Guide.pdf', 'advanced', 'uploads/manuals/teltonika_advanced_guide.pdf', 0, 'Installation guide for Teltonika advanced tracking system devices with CAN-Bus'),
    ('teltonika', NULL, 'Teltonika_Standard_Quick_Start.pdf', 'standard', 'uploads/manuals/teltonika_standard_guide.pdf', 0, 'Quick start guide for Teltonika standard tracking system devices'),
    ('galileosky', 'galileosky-v7', 'GalileoSky_V7_User_Manual.pdf', 'standard', 'uploads/manuals/galileosky_v7_manual.pdf', 0, 'Complete user manual for GalileoSky V7 GPS tracker')
) AS v(brand_slug, model_slug, name, system_type, file_path, file_size, description)
JOIN brands b ON b.slug = v.brand_slug
LEFT JOIN device_models dm ON dm.brand_id = b.id AND dm.slug = v.model_slug
WHERE NOT EXISTS (
    SELECT 1 FROM manuals m WHERE m.brand_id = b.id AND m.name = v.name
);

INSERT INTO common_settings (category, name, file_path, file_size, description)
SELECT v.category, v.name, v.file_path, v.file_size, v.description
FROM (VALUES
    ('APN', 'Translink APN Default', 'uploads/configs/Translink_APN_Default.cfg', 0, 'Default APN configuration for Ethio Telecom - APN: internet.et'),
    ('Server', 'Translink Server Config', 'uploads/configs/Translink_Server_Config.cfg', 0, 'Server IP and port - 88.99.188.166:2050'),
    ('SIM', 'SIM Setup Guide', 'uploads/manuals/Translink_SIM_Guide.txt', 0, 'Guide for SIM card preparation, PIN disable, APN setup'),
    ('Installation', 'Installation Guide', 'uploads/manuals/Translink_Installation_Guide.txt', 0, 'Step-by-step GPS device installation with LED troubleshooting')
) AS v(category, name, file_path, file_size, description)
WHERE NOT EXISTS (
    SELECT 1 FROM common_settings c WHERE c.category = v.category AND c.name = v.name
);

INSERT INTO users (username, password_hash, email, role, is_active) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@translink.et', 'admin', 1),
('user', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user@example.com', 'user', 1)
ON CONFLICT (username) DO UPDATE SET
    email = EXCLUDED.email,
    role = EXCLUDED.role,
    is_active = EXCLUDED.is_active;
