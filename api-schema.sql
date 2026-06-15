-- Enterprise API Migration: Adds rate limiting, partitioning, user_id to activity_log
-- Run: php -r "require 'config.php'; require 'includes/database.php'; \$db = Database::getInstance(); \$db->execute(file_get_contents('api-schema.sql')); echo 'Migration applied';"

ALTER TABLE activity_log ADD COLUMN IF NOT EXISTS user_id INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_activity_user ON activity_log (user_id);
CREATE INDEX IF NOT EXISTS idx_activity_entity ON activity_log (entity_type, entity_id);

CREATE TABLE IF NOT EXISTS api_rate_limits (
    id BIGSERIAL,
    rate_key VARCHAR(128) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_rate_limits_key_time ON api_rate_limits (rate_key, created_at);

CREATE TABLE IF NOT EXISTS api_tokens (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash VARCHAR(64) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    expires_at TIMESTAMP DEFAULT NULL,
    is_revoked SMALLINT NOT NULL DEFAULT 0 CHECK (is_revoked IN (0, 1)),
    last_used_at TIMESTAMP DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_api_tokens_hash ON api_tokens (token_hash);
CREATE INDEX IF NOT EXISTS idx_api_tokens_user ON api_tokens (user_id);

-- Partitioned activity_log for infinite scalability
CREATE TABLE IF NOT EXISTS activity_log_partitioned (
    id BIGSERIAL NOT NULL,
    action VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INTEGER DEFAULT NULL,
    entity_name VARCHAR(255) DEFAULT NULL,
    details TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_id INTEGER DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) PARTITION BY RANGE (created_at);

CREATE INDEX IF NOT EXISTS idx_activity_part_created ON activity_log_partitioned (created_at);
CREATE INDEX IF NOT EXISTS idx_activity_part_action ON activity_log_partitioned (action, entity_type);

-- Create initial partitions (quarterly for 2 years)
DO $$
DECLARE
    start_date DATE := date_trunc('quarter', CURRENT_DATE)::DATE;
    end_date DATE;
    partition_name TEXT;
    i INTEGER := 0;
BEGIN
    WHILE i < 8 LOOP
        end_date := start_date + INTERVAL '3 months';
        partition_name := 'activity_log_' || to_char(start_date, 'YYYY') || '_q' || EXTRACT(quarter FROM start_date)::TEXT;
        IF NOT EXISTS (SELECT 1 FROM pg_class WHERE relname = partition_name) THEN
            EXECUTE format(
                'CREATE TABLE %I PARTITION OF activity_log_partitioned FOR VALUES FROM (%L) TO (%L)',
                partition_name, start_date, end_date
            );
        END IF;
        start_date := end_date;
        i := i + 1;
    END LOOP;
END $$;

-- Function to auto-create future partitions
CREATE OR REPLACE FUNCTION create_future_activity_partition()
RETURNS event_trigger AS $$
DECLARE
    next_quarter DATE;
    partition_name TEXT;
BEGIN
    next_quarter := date_trunc('quarter', CURRENT_DATE + INTERVAL '6 months')::DATE;
    partition_name := 'activity_log_' || to_char(next_quarter, 'YYYY') || '_q' || EXTRACT(quarter FROM next_quarter)::TEXT;
    IF NOT EXISTS (SELECT 1 FROM pg_class WHERE relname = partition_name) THEN
        EXECUTE format(
            'CREATE TABLE %I PARTITION OF activity_log_partitioned FOR VALUES FROM (%L) TO (%L)',
            partition_name, next_quarter, next_quarter + INTERVAL '3 months'
        );
    END IF;
END;
$$ LANGUAGE plpgsql;

-- Add table for tracking storage metrics
CREATE TABLE IF NOT EXISTS storage_metrics (
    id SERIAL PRIMARY KEY,
    storage_path VARCHAR(500) NOT NULL,
    total_files INTEGER NOT NULL DEFAULT 0,
    total_size BIGINT NOT NULL DEFAULT 0,
    measured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_storage_metrics_time ON storage_metrics (measured_at);

-- Add table for API keys (long-lived tokens)
CREATE TABLE IF NOT EXISTS api_keys (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    key_prefix VARCHAR(8) NOT NULL,
    key_hash VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    permissions TEXT DEFAULT 'read',
    expires_at TIMESTAMP DEFAULT NULL,
    is_active SMALLINT NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    last_used_at TIMESTAMP DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_api_keys_prefix ON api_keys (key_prefix);
CREATE INDEX IF NOT EXISTS idx_api_keys_user ON api_keys (user_id);

-- Update config_files with storage tier
ALTER TABLE config_files ADD COLUMN IF NOT EXISTS storage_tier VARCHAR(20) DEFAULT 'standard' CHECK (storage_tier IN ('standard', 'archive', 'cold'));
ALTER TABLE firmware_files ADD COLUMN IF NOT EXISTS storage_tier VARCHAR(20) DEFAULT 'standard' CHECK (storage_tier IN ('standard', 'archive', 'cold'));
ALTER TABLE manuals ADD COLUMN IF NOT EXISTS storage_tier VARCHAR(20) DEFAULT 'standard' CHECK (storage_tier IN ('standard', 'archive', 'cold'));
ALTER TABLE software_files ADD COLUMN IF NOT EXISTS storage_tier VARCHAR(20) DEFAULT 'standard' CHECK (storage_tier IN ('standard', 'archive', 'cold'));
