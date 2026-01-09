-- Fix SMS Templates - Populate clinic_name and clinic_phone
-- PostgreSQL-compatible syntax

-- Update existing reminder template for Venera
UPDATE sms_templates 
SET 
  clinic_name = 'Venera V.I.P Clinic',
  clinic_phone = '70115090',
  message = 'Sain baina uu! {date} {start_time}-d tany uzleg {clinic_name}-d baina. Lawlah utas: {phone}.'
WHERE type = 'reminder' AND (clinic = 'venera' OR clinic IS NULL);

-- Insert or update templates for all clinics
INSERT INTO sms_templates (type, message, clinic, clinic_name, clinic_phone, is_latin) 
VALUES
  ('confirmation', 'Sain baina uu! Tany zahialga {clinic_name}-d {date} {start_time}-d batalgaajlaa. Lawlah utas: {phone}.', 'venera', 'Venera V.I.P Clinic', '70115090', 1),
  ('reminder', 'Sain baina uu! {date} {start_time}-d tany uzleg {clinic_name}-d baina. Lawlah utas: {phone}.', 'venera', 'Venera V.I.P Clinic', '70115090', 1),
  ('cancellation', 'Uuchlaarai! Tany {date} {start_time}-iin zahialga tsutslagudsasn. Lawlah utas: {phone}.', 'venera', 'Venera V.I.P Clinic', '70115090', 1),
  ('rescheduling', 'Medeggel! Tany zahialga {old_date} {old_time}-s {new_date} {new_time}-d shilyuullee. Lawlah utas: {phone}.', 'venera', 'Venera V.I.P Clinic', '70115090', 1),
  ('aftercare', 'Sain baina uu! {clinic_name}-ees mendiilj baina. Tany emchilgeenii daraa baydalaa shalgah tsag bolloo. Lawlah utas: {phone}.', 'venera', 'Venera V.I.P Clinic', '70115090', 1),
  
  ('confirmation', 'Sain baina uu! Tany zahialga {clinic_name}-d {date} {start_time}-d batalgaajlaa. Lawlah utas: {phone}.', 'khatan', 'Goo Khatan Medical', '70117150', 1),
  ('reminder', 'Sain baina uu! {date} {start_time}-d tany uzleg {clinic_name}-d baina. Lawlah utas: {phone}.', 'khatan', 'Goo Khatan Medical', '70117150', 1),
  ('cancellation', 'Uuchlaarai! Tany {date} {start_time}-iin zahialga tsutslagudsasn. Lawlah utas: {phone}.', 'khatan', 'Goo Khatan Medical', '70117150', 1),
  ('rescheduling', 'Medeggel! Tany zahialga {old_date} {old_time}-s {new_date} {new_time}-d shilyuullee. Lawlah utas: {phone}.', 'khatan', 'Goo Khatan Medical', '70117150', 1),
  ('aftercare', 'Sain baina uu! {clinic_name}-ees mendiilj baina. Tany emchilgeenii daraa baydalaa shalgah tsag bolloo. Lawlah utas: {phone}.', 'khatan', 'Goo Khatan Medical', '70117150', 1),
  
  ('confirmation', 'Sain baina uu! Tany zahialga {clinic_name}-d {date} {start_time}-d batalgaajlaa. Lawlah utas: {phone}.', 'dent', 'Venera Dent', '80806780', 1),
  ('reminder', 'Sain baina uu! {date} {start_time}-d tany uzleg {clinic_name}-d baina. Lawlah utas: {phone}.', 'dent', 'Venera Dent', '80806780', 1),
  ('cancellation', 'Uuchlaarai! Tany {date} {start_time}-iin zahialga tsutslagudsasn. Lawlah utas: {phone}.', 'dent', 'Venera Dent', '80806780', 1),
  ('rescheduling', 'Medeggel! Tany zahialga {old_date} {old_time}-s {new_date} {new_time}-d shilyuullee. Lawlah utas: {phone}.', 'dent', 'Venera Dent', '80806780', 1),
  ('aftercare', 'Sain baina uu! {clinic_name}-ees mendiilj baina. Tany emchilgeenii daraa baydalaa shalgah tsag bolloo. Lawlah utas: {phone}.', 'dent', 'Venera Dent', '80806780', 1)
ON CONFLICT (type, clinic) 
DO UPDATE SET 
  message = EXCLUDED.message,
  clinic_name = EXCLUDED.clinic_name,
  clinic_phone = EXCLUDED.clinic_phone,
  is_latin = EXCLUDED.is_latin,
  updated_at = CURRENT_TIMESTAMP;
