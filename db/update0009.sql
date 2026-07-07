-- Lobby management: standalone lobbies and per-tournament-group connect info
-- (name, short-lived lobby code, steam:// link). Connect info is only ever
-- delivered via AJAX, filtered per viewer — public lobbies to any logged-in
-- user, private ones to assigned players (lobby_players, or the group's
-- slots for bracket lobbies) and event managers.
CREATE TABLE lobbies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_pid TEXT NOT NULL,
    group_id INTEGER,
    name TEXT NOT NULL DEFAULT '',
    code TEXT NOT NULL DEFAULT '',
    link TEXT NOT NULL DEFAULT '',
    public INTEGER NOT NULL DEFAULT 1,
    created_by TEXT NOT NULL DEFAULT '',
    updated INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_lobbies_event ON lobbies (event_pid);

CREATE TABLE lobby_players (
    lobby_id INTEGER NOT NULL,
    user TEXT NOT NULL,
    PRIMARY KEY (lobby_id, user)
);

-- Event-level moderators replace the per-tournament orga list: hosts add
-- wiki users who may then manage the event's lobbies and tournaments.
CREATE TABLE event_mods (
    event_pid TEXT NOT NULL,
    user TEXT NOT NULL,
    PRIMARY KEY (event_pid, user)
);
INSERT OR IGNORE INTO event_mods (event_pid, user)
    SELECT t.event_pid, o.user FROM tourney_orgas o JOIN tourneys t ON t.id = o.tourney_id;
DROP TABLE tourney_orgas;
