-- WikiLAN initial schema (PDR §5)

CREATE TABLE lans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    namespace TEXT NOT NULL UNIQUE,      -- language-neutral edition ns, e.g. msl:2026_2
    title TEXT NOT NULL,
    start TEXT,                          -- ISO date
    end TEXT,                            -- ISO date
    state TEXT NOT NULL DEFAULT 'planned', -- planned | active | archived
    plan_media TEXT                      -- media id of the seating-plan SVG
);

CREATE TABLE lan_attendees (
    lan_id INTEGER NOT NULL REFERENCES lans(id),
    user TEXT NOT NULL,
    ts INTEGER NOT NULL,
    PRIMARY KEY (lan_id, user)
);

CREATE TABLE event_signups (
    event_pid TEXT NOT NULL,             -- language-neutral page id
    user TEXT NOT NULL,
    state TEXT NOT NULL,                 -- interested | signedup
    ts INTEGER NOT NULL,
    PRIMARY KEY (event_pid, user)
);

CREATE TABLE seats (
    lan_id INTEGER NOT NULL REFERENCES lans(id),
    seat_id TEXT NOT NULL,               -- label from the plan, e.g. A1
    svg_ref TEXT,                        -- element id / coords in the plan SVG
    admin_only INTEGER NOT NULL DEFAULT 0,
    notes TEXT,
    PRIMARY KEY (lan_id, seat_id)
);

CREATE TABLE seat_state (
    lan_id INTEGER NOT NULL REFERENCES lans(id),
    seat_id TEXT NOT NULL,
    user TEXT NOT NULL,
    state TEXT NOT NULL,                 -- reserved | arrived | admin-assigned
    ts INTEGER NOT NULL,
    PRIMARY KEY (lan_id, seat_id)
);
CREATE INDEX idx_seat_state_user ON seat_state(lan_id, user);

CREATE TABLE port_seat (
    lan_id INTEGER NOT NULL REFERENCES lans(id),
    port_id TEXT NOT NULL,               -- switch/port label, e.g. sw1/12
    seat_id TEXT NOT NULL,
    ip TEXT,                             -- deterministic IP for that port
    PRIMARY KEY (lan_id, port_id)
);
CREATE INDEX idx_port_seat_ip ON port_seat(lan_id, ip);

CREATE TABLE steam_links (
    user TEXT PRIMARY KEY,
    steamid64 TEXT NOT NULL,
    ts INTEGER NOT NULL
);

CREATE TABLE sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lan_id INTEGER NOT NULL REFERENCES lans(id),
    user TEXT NOT NULL,
    appid INTEGER NOT NULL,
    start INTEGER NOT NULL,
    last_seen INTEGER NOT NULL,
    end INTEGER                          -- NULL = currently playing
);
CREATE INDEX idx_sessions_user ON sessions(lan_id, user, end);

CREATE TABLE steam_owned (
    user TEXT NOT NULL,
    appid INTEGER NOT NULL,
    playtime INTEGER NOT NULL DEFAULT 0, -- minutes, lifetime
    last_played INTEGER,
    PRIMARY KEY (user, appid)
);
CREATE INDEX idx_steam_owned_app ON steam_owned(appid);

CREATE TABLE steam_owned_meta (
    user TEXT PRIMARY KEY,
    synced_ts INTEGER NOT NULL,
    game_count INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE steam_app_cache (
    appid INTEGER PRIMARY KEY,
    name TEXT,
    type TEXT,
    multiplayer INTEGER,                 -- NULL = unresolved, 0/1 once resolved
    resolved_ts INTEGER                  -- NULL = pending resolution
);

CREATE TABLE notices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lan_id INTEGER,
    user TEXT,                           -- NULL = public broadcast
    kind TEXT NOT NULL,                  -- broadcast | reminder | event_change
    title TEXT NOT NULL,
    body TEXT,
    link TEXT,
    author TEXT,
    ts INTEGER NOT NULL,
    expires INTEGER                      -- NULL = never
);
CREATE INDEX idx_notices_ts ON notices(ts);

CREATE TABLE push_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user TEXT NOT NULL,
    endpoint TEXT NOT NULL UNIQUE,
    p256dh TEXT NOT NULL,
    auth TEXT NOT NULL,
    ua TEXT,
    ts INTEGER NOT NULL
);
CREATE INDEX idx_push_user ON push_subscriptions(user);

CREATE TABLE push_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user TEXT NOT NULL,
    trigger_key TEXT UNIQUE,             -- idempotency key, NULL = always send
    payload TEXT NOT NULL,               -- JSON {title, body, link}
    state TEXT NOT NULL DEFAULT 'pending', -- pending | sent | failed
    tries INTEGER NOT NULL DEFAULT 0,
    ts INTEGER NOT NULL
);
CREATE INDEX idx_outbox_state ON push_outbox(state);

-- last-known schedule per event, for change detection (notify-tick)
CREATE TABLE event_sched (
    event_pid TEXT PRIMARY KEY,
    start INTEGER,
    duration INTEGER,
    location TEXT,
    title TEXT
);
