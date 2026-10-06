-- Add reject_reason column to reports table
ALTER TABLE reports ADD COLUMN IF NOT EXISTS reject_reason TEXT DEFAULT NULL;
