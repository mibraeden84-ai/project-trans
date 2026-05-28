-- Permissions v2: Add granular tab/view permissions
ALTER TABLE user_permissions ADD COLUMN IF NOT EXISTS can_view_configs SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE user_permissions ADD COLUMN IF NOT EXISTS can_view_firmware SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE user_permissions ADD COLUMN IF NOT EXISTS can_view_manuals SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE user_permissions ADD COLUMN IF NOT EXISTS can_view_software SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE user_permissions ADD COLUMN IF NOT EXISTS can_view_brands_models SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE user_permissions ADD COLUMN IF NOT EXISTS can_edit_files SMALLINT NOT NULL DEFAULT 0;
