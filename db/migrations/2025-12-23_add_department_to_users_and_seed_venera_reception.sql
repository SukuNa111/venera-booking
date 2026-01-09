-- Add department field to users and seed Venera reception users
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS department VARCHAR(100);

-- Ensure infusion doctor exists for Venera
INSERT INTO doctors (name, specialty, department, color, clinic, active, sort_order)
SELECT 'Цөлмөн', 'Дусал эмч', 'Дусал', '#f97316', 'venera', 1, 5
WHERE NOT EXISTS (
  SELECT 1 FROM doctors WHERE name='Цөлмөн' AND clinic='venera'
);

-- Seed 5 reception users for Venera (PIN=1234)
-- Note: phone must be unique; adjust if already taken
INSERT INTO users (name, phone, pin_hash, role, clinic_id, department)
SELECT 'Хүлээн авагч - Мэс засал', '91000001', '$2y$10$BjMsn7bv7AqwkNrkuQ67SeY/nB6xblaFom8Jj3Vd9oDbZ2b.wFIO2', 'reception', 'venera', 'Мэс засал'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE phone='91000001');

INSERT INTO users (name, phone, pin_hash, role, clinic_id, department)
SELECT 'Хүлээн авагч - Мэсийн бус', '91000002', '$2y$10$BjMsn7bv7AqwkNrkuQ67SeY/nB6xblaFom8Jj3Vd9oDbZ2b.wFIO2', 'reception', 'venera', 'Мэсийн бус'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE phone='91000002');

INSERT INTO users (name, phone, pin_hash, role, clinic_id, department)
SELECT 'Хүлээн авагч - Уламжлалт', '91000003', '$2y$10$BjMsn7bv7AqwkNrkuQ67SeY/nB6xblaFom8Jj3Vd9oDbZ2b.wFIO2', 'reception', 'venera', 'Уламжлалт'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE phone='91000003');

INSERT INTO users (name, phone, pin_hash, role, clinic_id, department)
SELECT 'Хүлээн авагч - Шүд', '91000004', '$2y$10$BjMsn7bv7AqwkNrkuQ67SeY/nB6xblaFom8Jj3Vd9oDbZ2b.wFIO2', 'reception', 'venera', 'Шүд'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE phone='91000004');

INSERT INTO users (name, phone, pin_hash, role, clinic_id, department)
SELECT 'Хүлээн авагч - Дусал', '91000005', '$2y$10$BjMsn7bv7AqwkNrkuQ67SeY/nB6xblaFom8Jj3Vd9oDbZ2b.wFIO2', 'reception', 'venera', 'Дусал'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE phone='91000005');
