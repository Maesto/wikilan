-- Per-edition schedule: buildup start joins start/end, and all three may
-- carry a time of day ('YYYY-MM-DD HH:MM'); end doubles as teardown start.
ALTER TABLE lans ADD COLUMN buildup TEXT;
