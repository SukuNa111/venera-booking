-- PostgreSQL Schema for Venera-Dent Booking System
-- Converted from MySQL/MariaDB

-- =====================================================
-- Table: app_settings
-- =====================================================
DROP TABLE IF EXISTS app_settings CASCADE;
CREATE TABLE app_settings (
  key VARCHAR(64) PRIMARY KEY,
  value TEXT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO app_settings (key, value) VALUES
('date_format', '"YYYY-MM-DD"'),
('default_clinic', '"venera"'),
('slot_minutes', '"30"'),
('status_colors', '{"online":"#3b82f6","arrived":"#f59e0b","paid":"#10b981","pending":"#a855f7","cancelled":"#ef4444"}'),
('time_format', '"HH:mm"'),
('timezone', '"Asia/Ulaanbaatar"'),
('week_start', '"monday"'),
('work_end', '"18:00"'),
('work_start', '"09:00"');

-- =====================================================
-- Table: clinics
-- =====================================================
DROP TABLE IF EXISTS clinics CASCADE;
CREATE TABLE clinics (
  id SERIAL PRIMARY KEY,
  code VARCHAR(50) UNIQUE NOT NULL,
  name VARCHAR(100) NOT NULL,
  theme_color VARCHAR(20) DEFAULT '#0f3b57',
  active SMALLINT DEFAULT 1,
  sort_order INTEGER DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO clinics (code, name, theme_color, active, sort_order) VALUES
('venera', 'Венера', '#0f3b57', 1, 1),
('luxor', 'Голден Луксор', '#1b5f84', 1, 2),
('khatan', 'Гоо Хатан', '#7c3aed', 1, 3);

-- =====================================================
-- Table: doctors
-- =====================================================
DROP TABLE IF EXISTS doctors CASCADE;
CREATE TABLE doctors (
  id SERIAL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  specialty VARCHAR(100) DEFAULT 'Ерөнхий эмч',
  department VARCHAR(100) DEFAULT NULL,
  color VARCHAR(7) DEFAULT '#0d6efd',
  clinic VARCHAR(50) DEFAULT 'venera',
  active SMALLINT DEFAULT 1,
  show_in_calendar SMALLINT DEFAULT 1,
  sort_order INTEGER DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO doctors (name, specialty, color, clinic, active) VALUES
('Эрдэнэбүрэн', 'Ерөнхий эмч', '#3b82f6', 'venera', 1),
('Номин', 'Ерөнхий эмч', '#10b981', 'venera', 1),
('Сүхэ', 'Ерөнхий эмч', '#f59e0b', 'venera', 1),
('Анхаа', 'Ерөнхий эмч', '#a855f7', 'luxor', 1),
('Гэлэг', 'Ерөнхий эмч', '#f59e0b', 'luxor', 1),
('Сарнай', 'Ерөнхий эмч', '#10b981', 'luxor', 1),
('Цэнэ', 'Ерөнхий эмч', '#3b82f6', 'khatan', 1),
('Болд', 'Ерөнхий эмч', '#ef4444', 'khatan', 1),
('Сэтэм', 'Ерөнхий эмч', '#14b8a6', 'khatan', 1);

-- =====================================================
-- Table: users
-- =====================================================
DROP TABLE IF EXISTS users CASCADE;
CREATE TABLE users (
  id SERIAL PRIMARY KEY,
  name VARCHAR(100),
  phone VARCHAR(20) UNIQUE,
  pin_hash VARCHAR(255),
  role VARCHAR(20) DEFAULT 'reception' CHECK (role IN ('admin', 'reception', 'doctor')),
  clinic_id VARCHAR(50),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Password: 1234
INSERT INTO users (name, phone, pin_hash, role, clinic_id) VALUES
('Админ', '99999999', '$2y$10$BjMsn7bv7AqwkNrkuQ67SeY/nB6xblaFom8Jj3Vd9oDbZ2b.wFIO2', 'admin', 'all'),
('Болор', '88888888', '$2y$10$BjMsn7bv7AqwkNrkuQ67SeY/nB6xblaFom8Jj3Vd9oDbZ2b.wFIO2', 'reception', 'venera'),
('Эрдэнэбүрэн', '77777777', '$2y$10$BjMsn7bv7AqwkNrkuQ67SeY/nB6xblaFom8Jj3Vd9oDbZ2b.wFIO2', 'doctor', 'venera');

-- =====================================================
-- Table: treatments
-- =====================================================
DROP TABLE IF EXISTS treatments CASCADE;
CREATE TABLE treatments (
  id SERIAL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  sessions INTEGER DEFAULT 1,
  interval_days INTEGER DEFAULT 0,
  aftercare_days INTEGER DEFAULT 0,
  aftercare_message TEXT,
  is_active SMALLINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO treatments (name, sessions, interval_days, aftercare_days, aftercare_message) VALUES
('Шүдний цэвэрлэгээ', 1, 180, 180, 'Sain baina uu! Shudnii tseverlegee hiigesnees 6 sar bolloo. Dahin tsag avna uu.'),
('Суулгац эмчилгээ', 3, 14, 90, 'Sain baina uu! Suulgats emchilgeenii daraa 3 sar bolloo. Shalgalt hiigene uu.'),
('Сувгийн эмчилгээ', 2, 7, 30, 'Sain baina uu! Suvgin emchilgeenii shalgalt hiigeh tsag bolloo.'),
('Ердийн үзлэг', 1, 0, 365, 'Sain baina uu! Jiliin shudnii uzleg hiigeh tsag bolloo.'),
('Гажиг засал', 12, 30, 0, NULL),
('Filler', 1, 0, 180, 'Sain baina uu! Filler emchilgeenii daraa 6 sar bolloo. Dahin tsag avna uu.');

-- =====================================================
-- Table: bookings
-- =====================================================
DROP TABLE IF EXISTS bookings CASCADE;
CREATE TABLE bookings (
  id SERIAL PRIMARY KEY,
  doctor_id INTEGER REFERENCES doctors(id),
  clinic VARCHAR(50) DEFAULT 'venera',
  date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  patient_name VARCHAR(255) NOT NULL,
  gender VARCHAR(20),
  visit_count INTEGER DEFAULT 1,
  phone VARCHAR(20),
  note TEXT,
  service_name VARCHAR(255),
  price DECIMAL(10,2) DEFAULT 0.00,
  status VARCHAR(20) DEFAULT 'online',
  department VARCHAR(100),
  treatment_id INTEGER REFERENCES treatments(id),
  session_number INTEGER DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  source VARCHAR(50)
);

CREATE INDEX idx_bookings_clinic_date ON bookings(clinic, date);
CREATE INDEX idx_bookings_doctor_date ON bookings(doctor_id, date);
CREATE INDEX idx_bookings_phone ON bookings(phone);

-- =====================================================
-- Table: working_hours
-- =====================================================
DROP TABLE IF EXISTS working_hours CASCADE;
CREATE TABLE working_hours (
  id SERIAL PRIMARY KEY,
  doctor_id INTEGER NOT NULL REFERENCES doctors(id),
  day_of_week SMALLINT NOT NULL CHECK (day_of_week >= 0 AND day_of_week <= 6),
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  is_available SMALLINT DEFAULT 1
);

CREATE INDEX idx_working_hours_doctor ON working_hours(doctor_id);

-- Insert default working hours (Mon-Fri 09:00-18:00, Sat 09:00-17:00) for all doctors
INSERT INTO working_hours (doctor_id, day_of_week, start_time, end_time, is_available)
SELECT d.id, dow.day, 
  CASE WHEN dow.day = 6 THEN '09:00:00'::time ELSE '09:00:00'::time END,
  CASE WHEN dow.day = 6 THEN '17:00:00'::time ELSE '18:00:00'::time END,
  1
FROM doctors d
CROSS JOIN (SELECT generate_series(1, 6) as day) dow;

-- =====================================================
-- Table: sms_log
-- =====================================================
DROP TABLE IF EXISTS sms_log CASCADE;
CREATE TABLE sms_log (
  id SERIAL PRIMARY KEY,
  booking_id INTEGER REFERENCES bookings(id),
  phone VARCHAR(20) NOT NULL,
  message TEXT NOT NULL,
  status VARCHAR(20) DEFAULT 'sent',
  http_code INTEGER,
  response TEXT,
  error_message TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_sms_log_booking ON sms_log(booking_id);
CREATE INDEX idx_sms_log_phone ON sms_log(phone);

-- =====================================================
-- Table: sms_schedule
-- =====================================================
DROP TABLE IF EXISTS sms_schedule CASCADE;
CREATE TABLE sms_schedule (
  id SERIAL PRIMARY KEY,
  booking_id INTEGER REFERENCES bookings(id),
  phone VARCHAR(20) NOT NULL,
  message TEXT NOT NULL,
  scheduled_at TIMESTAMP NOT NULL,
  type VARCHAR(20) DEFAULT 'reminder',
  status VARCHAR(20) DEFAULT 'pending',
  sent_at TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_sms_schedule_status ON sms_schedule(status, scheduled_at);

-- =====================================================
-- Table: feedback
-- =====================================================
DROP TABLE IF EXISTS feedback CASCADE;
CREATE TABLE feedback (
  id SERIAL PRIMARY KEY,
  user_id INTEGER NOT NULL,
  user_name VARCHAR(150),
  user_role VARCHAR(20),
  clinic_id VARCHAR(50),
  topic VARCHAR(150),
  message TEXT NOT NULL,
  status VARCHAR(20) DEFAULT 'open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_feedback_user ON feedback(user_id);
CREATE INDEX idx_feedback_status ON feedback(status);

-- =====================================================
-- Done!
-- =====================================================
