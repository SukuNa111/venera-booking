-- Migration to add clinic column to app_settings
ALTER TABLE app_settings ADD COLUMN clinic VARCHAR(32) DEFAULT 'venera';
ALTER TABLE app_settings DROP CONSTRAINT app_settings_pkey;
ALTER TABLE app_settings ADD PRIMARY KEY (clinic, "key");
