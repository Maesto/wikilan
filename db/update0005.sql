-- Max concurrent players per app, resolved from PCGamingWiki (Steam's APIs
-- expose no player counts). NULL maxplayers with set mp_ts = PCGW unknown.
ALTER TABLE steam_app_cache ADD COLUMN maxplayers INTEGER;
ALTER TABLE steam_app_cache ADD COLUMN mp_ts INTEGER;
