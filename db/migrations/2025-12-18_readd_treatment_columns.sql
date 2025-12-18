-- Restore treatment pricing/clinic columns expected by UI and API
ALTER TABLE treatments
  ADD COLUMN IF NOT EXISTS category VARCHAR(255),
  ADD COLUMN IF NOT EXISTS price NUMERIC(12,2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS duration_minutes INTEGER DEFAULT 30,
  ADD COLUMN IF NOT EXISTS next_visit_mode VARCHAR(10) DEFAULT 'auto',
  ADD COLUMN IF NOT EXISTS clinic VARCHAR(50),
  ADD COLUMN IF NOT EXISTS price_editable SMALLINT DEFAULT 0;

-- Ensure defaults on existing core columns
ALTER TABLE treatments
  ALTER COLUMN sessions SET DEFAULT 1,
  ALTER COLUMN interval_days SET DEFAULT 0,
  ALTER COLUMN aftercare_days SET DEFAULT 0,
  ALTER COLUMN is_active SET DEFAULT 1;
