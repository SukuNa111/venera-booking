-- Add phone1/phone2 to clinics and keep legacy columns in sync
ALTER TABLE clinics
  ADD COLUMN IF NOT EXISTS phone1 VARCHAR(64),
  ADD COLUMN IF NOT EXISTS phone2 VARCHAR(64),
  ADD COLUMN IF NOT EXISTS address TEXT,
  ADD COLUMN IF NOT EXISTS map_link TEXT;

-- Backfill from legacy fields when present
UPDATE clinics
   SET phone1 = COALESCE(phone1, NULLIF(phone, '')),
       phone2 = COALESCE(phone2, NULLIF(phone_alt, ''));

-- Seed known clinic contacts (can be overwritten later)
UPDATE clinics SET phone1 = '80806780', phone2 = COALESCE(phone2, '70115090') WHERE code = 'venera';
UPDATE clinics SET phone1 = '70337070' WHERE code = 'luxor';
UPDATE clinics SET phone1 = '99303048', phone2 = '71007100' WHERE code = 'khatan';

-- Keep legacy phone fields mirrored for compatibility
UPDATE clinics
   SET phone     = COALESCE(phone, phone1),
       phone_alt = COALESCE(phone_alt, phone2);

-- Add clinic_id to bookings for explicit clinic linkage
ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS clinic_id VARCHAR(50);

-- Backfill clinic_id from existing clinic column
UPDATE bookings
   SET clinic_id = clinic
 WHERE (clinic_id IS NULL OR clinic_id = '') AND clinic IS NOT NULL;

-- Helpful index for queries filtering by clinic_id
CREATE INDEX IF NOT EXISTS idx_bookings_clinic_id ON bookings(clinic_id);
