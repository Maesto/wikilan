-- Discord account links (OAuth2 identify scope), mirroring steam_links.
-- notify=1 routes this user's push notifications to a Discord DM (sent by
-- the configured bot) instead of browser web push; dm_channel caches the
-- bot's DM channel id after the first successful delivery.
CREATE TABLE discord_links (
    user TEXT NOT NULL PRIMARY KEY,
    discord_id TEXT NOT NULL,
    username TEXT NOT NULL DEFAULT '',
    global_name TEXT NOT NULL DEFAULT '',
    avatar TEXT NOT NULL DEFAULT '',
    notify INTEGER NOT NULL DEFAULT 0,
    dm_channel TEXT NOT NULL DEFAULT '',
    ts INTEGER NOT NULL DEFAULT 0
);
