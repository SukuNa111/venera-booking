-- SMS Templates Table for custom message configuration
CREATE TABLE IF NOT EXISTS sms_templates (
  id SERIAL PRIMARY KEY,
  type VARCHAR(50) NOT NULL,
  message TEXT NOT NULL,
  clinic VARCHAR(50) DEFAULT 'venera',
  clinic_name VARCHAR(100),
  clinic_phone VARCHAR(20),
  is_latin TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(type, clinic)
);

-- Insert default SMS templates (WITHOUT patient_name and end_time)
INSERT INTO sms_templates (type, message, clinic, clinic_name, clinic_phone, is_latin) VALUES
('confirmation', 'Sain baina uu! Tany zahalga {clinic_name}-d {date} {start_time}-d batalguajlaa. Uts: {phone}.', 'venera', 'Venera V.I.P Clinic', '70115090', 1),
('reminder', 'Sain baina uu! Margaash {date} {start_time}-d tany uzleg {clinic_name}-d baina. Uts: {phone}.', 'venera', 'Venera V.I.P Clinic', '70115090', 1),
('cancellation', 'Uuchlaarai! Tany {date} {start_time}-iin zahalga tsutslagudsasn. Uts: {phone}.', 'venera', 'Venera V.I.P Clinic', '70115090', 1),
('rescheduling', 'Medeggel! Tany zahalga {old_date} {old_time}-s {new_date} {new_time}-d shilyuullee. Uts: {phone}.', 'venera', 'Venera V.I.P Clinic', '70115090', 1),
('confirmation', 'Sain baina uu! Tany zahalga {clinic_name}-d {date} {start_time}-d batalguajlaa. Uts: {phone}.', 'khatan', 'Goo Khatan Medical', '70117150', 1),
('reminder', 'Sain baina uu! Margaash {date} {start_time}-d tany uzleg {clinic_name}-d baina. Uts: {phone}.', 'khatan', 'Goo Khatan Medical', '70117150', 1),
('cancellation', 'Uuchlaarai! Tany {date} {start_time}-iin zahalga tsutslagudsasn. Uts: {phone}.', 'khatan', 'Goo Khatan Medical', '70117150', 1),
('rescheduling', 'Medeggel! Tany zahalga {old_date} {old_time}-s {new_date} {new_time}-d shilyuullee. Uts: {phone}.', 'khatan', 'Goo Khatan Medical', '70117150', 1)
ON DUPLICATE KEY UPDATE message = VALUES(message);

