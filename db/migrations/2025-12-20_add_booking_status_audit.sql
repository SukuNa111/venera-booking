-- Migration: Захиалгын статус өөрчлөлтийн audit log
-- Table: booking_status_audit

CREATE TABLE IF NOT EXISTS booking_status_audit (
  id SERIAL PRIMARY KEY,
  booking_id INTEGER NOT NULL REFERENCES bookings(id),
  old_status VARCHAR(20) NOT NULL,
  new_status VARCHAR(20) NOT NULL,
  changed_by INTEGER NOT NULL REFERENCES users(id),
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
