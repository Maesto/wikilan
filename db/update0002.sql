-- Steam profile snapshot (persona name + avatars) cached alongside the link,
-- refreshed at link time and by the now-playing sync.

ALTER TABLE steam_links ADD COLUMN persona TEXT;
ALTER TABLE steam_links ADD COLUMN avatar TEXT;       -- medium (64px) url, lists
ALTER TABLE steam_links ADD COLUMN avatar_full TEXT;  -- full (184px) url, profile card
ALTER TABLE steam_links ADD COLUMN profile_ts INTEGER NOT NULL DEFAULT 0;
