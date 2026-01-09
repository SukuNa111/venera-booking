-- Add departments for Venera clinic doctors
-- Update existing doctors with departments

UPDATE doctors SET department = 'Мэс засал' WHERE name = 'Эрдэнэбүрэн' AND clinic = 'venera';
UPDATE doctors SET department = 'Мэсийн бус' WHERE name = 'Номин' AND clinic = 'venera';
UPDATE doctors SET department = 'Уламжлалт' WHERE name = 'Сүхэ' AND clinic = 'venera';

-- Insert additional doctors for other departments if needed
-- Шүд department
INSERT INTO doctors (name, specialty, department, color, clinic, active, sort_order) 
VALUES ('Батсайхан', 'Шүдний эмч', 'Шүд', '#ec4899', 'venera', 1, 4);

-- Дусал department
INSERT INTO doctors (name, specialty, department, color, clinic, active, sort_order) 
VALUES ('Цөлмөн', 'Дусал эмч', 'Дусал', '#f97316', 'venera', 1, 5);
