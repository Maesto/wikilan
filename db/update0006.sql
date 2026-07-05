-- Wiki login → strichliste account name where they differ (site convention
-- is same-name; this overrides payment matching and buy statistics).
CREATE TABLE strichliste_map (
    user TEXT NOT NULL PRIMARY KEY,
    sl_name TEXT NOT NULL
);
