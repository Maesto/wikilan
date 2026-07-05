-- Food/paid events: signups carry an optional comment ("no onions"), and for
-- priced events a paid flag with a reference (strichliste tx id or manual note).
ALTER TABLE event_signups ADD COLUMN comment TEXT NOT NULL DEFAULT '';
ALTER TABLE event_signups ADD COLUMN paid INTEGER NOT NULL DEFAULT 0;
ALTER TABLE event_signups ADD COLUMN paid_ref TEXT;
