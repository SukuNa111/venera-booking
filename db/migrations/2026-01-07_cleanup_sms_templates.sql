-- Cleanup SMS Templates - Remove unnecessary types
-- Keep only: confirmation, reminder, aftercare
-- Remove: cancellation, rescheduling

DELETE FROM sms_templates WHERE type IN ('cancellation', 'rescheduling');

-- Verify remaining templates
-- Expected: Only confirmation, reminder, aftercare for each clinic
