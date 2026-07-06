-- Tournament brackets per event page (neutral id). Two modes:
--   ffa:   lobbies of up to lobby_size players, the top `advance` of each
--          lobby move on (re-shuffled) until a single final lobby remains
--   teams: teams of lobby_size players in a single-elimination bracket
-- Group names are stored as tokens (lobby:A, match:1, final, bye:N, team
-- names as plain numbers) and localized at render time.
CREATE TABLE tourneys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_pid TEXT NOT NULL UNIQUE,
    mode TEXT NOT NULL DEFAULT 'ffa',
    lobby_size INTEGER NOT NULL DEFAULT 8,
    advance INTEGER NOT NULL DEFAULT 4,
    state TEXT NOT NULL DEFAULT 'setup',
    round INTEGER NOT NULL DEFAULT 0,
    created_by TEXT NOT NULL DEFAULT '',
    created INTEGER NOT NULL DEFAULT 0
);

-- explicit organizer list on top of mods + creator
CREATE TABLE tourney_orgas (
    tourney_id INTEGER NOT NULL,
    user TEXT NOT NULL,
    PRIMARY KEY (tourney_id, user)
);

CREATE TABLE tourney_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tourney_id INTEGER NOT NULL,
    round INTEGER NOT NULL,
    name TEXT NOT NULL
);
CREATE INDEX idx_tourney_groups ON tourney_groups (tourney_id, round);

CREATE TABLE tourney_slots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER NOT NULL,
    user TEXT NOT NULL,
    team TEXT,
    rank INTEGER
);
CREATE INDEX idx_tourney_slots ON tourney_slots (group_id);
