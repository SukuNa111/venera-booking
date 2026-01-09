-- Migration: allow NULL doctor_id in bookings
-- Date: 2025-12-20

BEGIN;

-- Convert any zero doctor_id to NULL
UPDATE bookings SET doctor_id = NULL WHERE doctor_id = 0;

-- Drop default if exists
ALTER TABLE bookings ALTER COLUMN doctor_id DROP DEFAULT;

-- Allow NULL values
ALTER TABLE bookings ALTER COLUMN doctor_id DROP NOT NULL;

COMMIT;