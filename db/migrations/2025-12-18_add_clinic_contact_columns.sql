-- Add contact and map fields to clinics so templates can include clinic-specific data
ALTER TABLE clinics
  ADD COLUMN IF NOT EXISTS phone VARCHAR(64),
  ADD COLUMN IF NOT EXISTS phone_alt VARCHAR(128),
  ADD COLUMN IF NOT EXISTS address TEXT,
  ADD COLUMN IF NOT EXISTS map_link TEXT;

-- Optional: populate some example values for existing clinics (no-op if values exist)
UPDATE clinics SET phone = COALESCE(phone, '99303048'), phone_alt = COALESCE(phone_alt, '71007100') WHERE code = 'khatan' AND (phone IS NULL OR phone = '');
UPDATE clinics SET phone = COALESCE(phone, '80806780') WHERE code = 'venera' AND (phone IS NULL OR phone = '');
