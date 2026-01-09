-- Backfill missing doctor user accounts (default PIN=1234)
-- Generates an 8-digit phone per doctor: 9 + zero-padded doctor_id.
-- Skips if a user with the same id already exists, or if the generated phone is already taken.

-- Ensure department column exists (safe no-op if already added)
ALTER TABLE users ADD COLUMN IF NOT EXISTS department VARCHAR(100);

INSERT INTO users (id, name, phone, pin_hash, role, clinic_id, department, created_at)
SELECT d.id,
       d.name,
       '9' || LPAD(CAST(d.id AS TEXT), 7, '0') AS phone,
       '$2y$10$BjMsn7bv7AqwkNrkuQ67SeY/nB6xblaFom8Jj3Vd9oDbZ2b.wFIO2' AS pin_hash, -- PIN: 1234
       'doctor' AS role,
       COALESCE(NULLIF(d.clinic,''), 'venera') AS clinic_id,
       d.department,
       NOW()
FROM doctors d
LEFT JOIN users u ON u.id = d.id
WHERE u.id IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM users u2 WHERE u2.phone = '9' || LPAD(CAST(d.id AS TEXT), 7, '0')
  );
