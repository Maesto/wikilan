<?php

use dokuwiki\Extension\Plugin;
use dokuwiki\HTTP\DokuHTTPClient;

/**
 * Steam Web API client + sync jobs (now-playing/sessions, playtime, owned games,
 * lazy app-metadata cache). Called from the cli/cron component.
 */
class helper_plugin_wikilan_steam extends Plugin
{
    protected ?helper_plugin_wikilan $wl = null;

    protected function wl(): helper_plugin_wikilan
    {
        if (!$this->wl) $this->wl = plugin_load('helper', 'wikilan');
        return $this->wl;
    }

    // ---------------------------------------------------------------- raw API

    protected function apiGet(string $url, array $params): ?array
    {
        $http = new DokuHTTPClient();
        $http->timeout = 15;
        $resp = $http->get($url . '?' . buildURLparams($params, '&'));
        if ($resp === false) return null;
        $json = json_decode($resp, true);
        return is_array($json) ? $json : null;
    }

    protected function webApi(string $iface, string $method, string $version, array $params): ?array
    {
        $key = trim((string)$this->getConf('steamapikey'));
        if ($key === '') return null;
        $params['key'] = $key;
        return $this->apiGet("https://api.steampowered.com/$iface/$method/$version/", $params);
    }

    /** @param string[] $steamids up to any number; chunked into API batches of 100 */
    public function playerSummaries(array $steamids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($steamids)), 100) as $chunk) {
            $r = $this->webApi('ISteamUser', 'GetPlayerSummaries', 'v2', [
                'steamids' => implode(',', $chunk),
            ]);
            foreach ($r['response']['players'] ?? [] as $p) {
                $out[$p['steamid']] = $p;
            }
        }
        return $out;
    }

    public function ownedGames(string $steamid): ?array
    {
        $r = $this->webApi('IPlayerService', 'GetOwnedGames', 'v1', [
            'steamid' => $steamid,
            'include_played_free_games' => 1,
        ]);
        if ($r === null) return null;
        return $r['response']['games'] ?? []; // private profile → empty response
    }

    public function recentlyPlayed(string $steamid): array
    {
        $r = $this->webApi('IPlayerService', 'GetRecentlyPlayedGames', 'v1', [
            'steamid' => $steamid,
        ]);
        return $r['response']['games'] ?? [];
    }

    /** Steam store metadata for one app (rate limited upstream — call sparingly) */
    public function appDetails(int $appid): ?array
    {
        $r = $this->apiGet('https://store.steampowered.com/api/appdetails', [
            'appids' => $appid,
            'filters' => 'basic,categories',
        ]);
        $entry = $r[(string)$appid] ?? null;
        if (!$entry || empty($entry['success'])) return null;
        return $entry['data'] ?? null;
    }

    // ---------------------------------------------------------------- sync jobs

    /**
     * Fast poll: now-playing for present linked attendees of the active LAN.
     * Maintains the sessions table (open/extend/close). Returns #users polled.
     */
    public function syncNowPlaying(): int
    {
        $wl = $this->wl();
        $lan = $wl->activeLan();
        if (!$lan) return 0;
        $db = $wl->getDB();

        // present attendees preferred; before arrivals exist, fall back to all attendees
        $users = array_column($wl->arrivedUsers($lan['id']), 'user');
        if (!$users) $users = array_column($wl->attendees($lan['id']), 'user');
        $links = $wl->steamLinks($users);
        if (!$links) return 0;

        $summaries = $this->playerSummaries(array_values($links));
        $now = time();

        foreach ($links as $user => $sid) {
            $p = $summaries[$sid] ?? null;
            if ($p) $this->storeProfile($user, $p);
            $appid = isset($p['gameid']) && ctype_digit((string)$p['gameid'])
                ? (int)$p['gameid'] : 0;
            $open = $db->queryRecord(
                "SELECT * FROM sessions WHERE lan_id = ? AND user = ? AND end IS NULL",
                $lan['id'],
                $user
            );

            if ($open && (int)$open['appid'] === $appid) {
                $db->exec("UPDATE sessions SET last_seen = ? WHERE id = ?", $now, $open['id']);
                continue;
            }
            if ($open) {
                $db->exec(
                    "UPDATE sessions SET end = ? WHERE id = ?",
                    (int)$open['last_seen'],
                    $open['id']
                );
            }
            if ($appid) {
                $db->exec(
                    "INSERT INTO sessions (lan_id, user, appid, start, last_seen) VALUES (?, ?, ?, ?, ?)",
                    $lan['id'],
                    $user,
                    $appid,
                    $now,
                    $now
                );
                $this->registerApp($appid, $p['gameextrainfo'] ?? null);
            }
        }
        return count($links);
    }

    /** Cache the persona/avatar part of a GetPlayerSummaries row for a wiki user */
    protected function storeProfile(string $user, array $p): void
    {
        $this->wl()->setSteamProfile(
            $user,
            $p['personaname'] ?? null,
            $p['avatarmedium'] ?? ($p['avatar'] ?? null),
            $p['avatarfull'] ?? null
        );
    }

    /** Fetch + cache persona/avatar for one user right away (used after linking) */
    public function refreshProfile(string $user): bool
    {
        $sid = $this->wl()->steamLink($user);
        if (!$sid) return false;
        $p = $this->playerSummaries([$sid])[$sid] ?? null;
        if (!$p) return false;
        $this->storeProfile($user, $p);
        return true;
    }

    /** Slower sync: refresh lifetime playtime of recently played games. */
    public function syncPlaytime(): int
    {
        $wl = $this->wl();
        $lan = $wl->activeLan();
        if (!$lan) return 0;
        $db = $wl->getDB();
        $links = $wl->steamLinks(array_column($wl->attendees($lan['id']), 'user'));
        $n = 0;
        foreach ($links as $user => $sid) {
            foreach ($this->recentlyPlayed($sid) as $g) {
                $db->exec(
                    "INSERT INTO steam_owned (user, appid, playtime, last_played)
                     VALUES (?, ?, ?, ?)
                     ON CONFLICT(user, appid) DO UPDATE
                        SET playtime = excluded.playtime, last_played = excluded.last_played",
                    $user,
                    (int)$g['appid'],
                    (int)($g['playtime_forever'] ?? 0),
                    time()
                );
                $this->registerApp((int)$g['appid'], $g['name'] ?? null);
            }
            $n++;
        }
        return $n;
    }

    /**
     * Background owned-games sync: one stale user per call (libraries can be huge,
     * keep each cron tick cheap). Returns synced user or null if everyone is fresh.
     */
    public function syncOwnedNext(?string $forceUser = null): ?string
    {
        $wl = $this->wl();
        $db = $wl->getDB();
        $maxAge = time() - (int)$this->getConf('owned_refresh_days') * 86400;

        if ($forceUser !== null) {
            $user = $forceUser;
        } else {
            $user = $db->queryValue(
                "SELECT l.user FROM steam_links l
              LEFT JOIN steam_owned_meta m ON m.user = l.user
                  WHERE m.user IS NULL OR m.synced_ts < ?
               ORDER BY COALESCE(m.synced_ts, 0) LIMIT 1",
                $maxAge
            );
            if (!$user) return null;
        }

        $sid = $wl->steamLink($user);
        if (!$sid) return null;
        $games = $this->ownedGames($sid);
        if ($games === null) return null; // API failure: retry next tick

        $db->getPdo()->beginTransaction();
        try {
            foreach ($games as $g) {
                $db->exec(
                    "INSERT INTO steam_owned (user, appid, playtime, last_played)
                     VALUES (?, ?, ?, ?)
                     ON CONFLICT(user, appid) DO UPDATE
                        SET playtime = excluded.playtime,
                            last_played = COALESCE(excluded.last_played, steam_owned.last_played)",
                    $user,
                    (int)$g['appid'],
                    (int)($g['playtime_forever'] ?? 0),
                    isset($g['rtime_last_played']) ? (int)$g['rtime_last_played'] : null
                );
                // appids only; names resolve lazily via the app cache
                $db->exec(
                    "INSERT OR IGNORE INTO steam_app_cache (appid) VALUES (?)",
                    (int)$g['appid']
                );
            }
            $db->exec(
                "REPLACE INTO steam_owned_meta (user, synced_ts, game_count) VALUES (?, ?, ?)",
                $user,
                time(),
                count($games)
            );
            $db->getPdo()->commit();
        } catch (\Throwable $e) {
            $db->getPdo()->rollBack();
            throw $e;
        }
        return $user;
    }

    /**
     * Lazily resolve pending appids (name/type/multiplayer) via the store API.
     * Store API is heavily rate limited: small batches, pause between calls.
     */
    public function resolveAppMeta(?int $batch = null): int
    {
        $wl = $this->wl();
        $db = $wl->getDB();
        $batch = $batch ?? (int)$this->getConf('appmeta_batch');

        // prioritize apps someone actually plays/owns-with-playtime; then the rest
        $pending = $db->queryAll(
            "SELECT c.appid FROM steam_app_cache c
              WHERE c.resolved_ts IS NULL
           ORDER BY (SELECT MAX(playtime) FROM steam_owned o WHERE o.appid = c.appid) DESC
              LIMIT ?",
            $batch
        );
        $n = 0;
        foreach ($pending as $row) {
            $appid = (int)$row['appid'];
            $data = $this->appDetails($appid);
            $cats = array_column($data['categories'] ?? [], 'id');
            // steam category ids meaning some flavor of multiplayer/co-op/PvP
            $mpIds = [1, 9, 20, 27, 36, 37, 38, 39, 47, 48, 49];
            $db->exec(
                "UPDATE steam_app_cache
                    SET name = COALESCE(?, name), type = ?, multiplayer = ?, resolved_ts = ?
                  WHERE appid = ?",
                $data['name'] ?? null,
                $data['type'] ?? null,
                $data ? (int)(bool)array_intersect($mpIds, $cats) : null,
                time(),
                $appid
            );
            $n++;
            if ($n < count($pending)) usleep(1500000); // ~1.5s between store calls
        }
        return $n;
    }

    /** Ensure an appid exists in the cache; store the name if we already know it */
    public function registerApp(int $appid, ?string $name = null): void
    {
        $db = $this->wl()->getDB();
        $db->exec("INSERT OR IGNORE INTO steam_app_cache (appid) VALUES (?)", $appid);
        if ($name !== null) {
            $db->exec(
                "UPDATE steam_app_cache SET name = ? WHERE appid = ? AND name IS NULL",
                $name,
                $appid
            );
        }
    }

    // ---------------------------------------------------------------- shared games

    /**
     * Max-player resolution via PCGamingWiki — Steam's APIs expose no player
     * counts. Small batch of unresolved multiplayer apps per cron tick,
     * most-played first. maxplayers stays NULL where PCGW has no data.
     */
    public function resolveMaxPlayers(?int $batch = null): int
    {
        $db = $this->wl()->getDB();
        $batch = $batch ?? (int)$this->getConf('appmeta_batch');
        $pending = $db->queryAll(
            "SELECT appid FROM steam_app_cache
              WHERE multiplayer = 1 AND mp_ts IS NULL
           ORDER BY (SELECT MAX(playtime) FROM steam_owned o
                      WHERE o.appid = steam_app_cache.appid) DESC
              LIMIT ?",
            $batch
        );
        $n = 0;
        foreach ($pending as $row) {
            $appid = (int)$row['appid'];
            $max = $this->pcgwMaxPlayers($appid);
            if ($max === false) {
                // transport error or rate limit (429): leave the row pending
                // for the next tick instead of mislabeling it as "no data"
                break;
            }
            $db->exec(
                "UPDATE steam_app_cache SET maxplayers = ?, mp_ts = ? WHERE appid = ?",
                $max,
                time(),
                $appid
            );
            $n++;
            if ($n < count($pending)) usleep(1000000);
        }
        return $n;
    }

    /**
     * Highest player count PCGamingWiki lists (online, LAN or local).
     * null = queried fine, no data; false = request failed, retry later.
     */
    protected function pcgwMaxPlayers(int $appid)
    {
        $url = 'https://www.pcgamingwiki.com/w/api.php?action=cargoquery'
            . '&tables=' . rawurlencode('Infobox_game,Multiplayer')
            . '&join_on=' . rawurlencode('Infobox_game._pageID=Multiplayer._pageID')
            . '&fields=' . rawurlencode(
                'Multiplayer.Online_players,Multiplayer.LAN_players,Multiplayer.Local_players'
            )
            . '&where=' . rawurlencode('Infobox_game.Steam_AppID HOLDS "' . $appid . '"')
            . '&format=json';
        $http = new \dokuwiki\HTTP\DokuHTTPClient();
        $http->timeout = 10;
        // PCGamingWiki API policy: custom user agent with contact info
        $http->agent = 'WikiLAN/1.0 (' . DOKU_URL . '; '
            . str_replace('mailto:', '', (string)$this->wl()->getConf('push_contact'))
            . ') DokuHTTPClient';
        $res = $http->get($url);
        if ($res === false || (int)$http->status !== 200) return false;
        $data = json_decode((string)$res, true);
        if (!is_array($data)) return false;
        $max = 0;
        foreach ($data['cargoquery'] ?? [] as $row) {
            foreach (['Online players', 'LAN players', 'Local players'] as $f) {
                $v = (string)($row['title'][$f] ?? '');
                if ($v !== '' && preg_match_all('/\d+/', $v, $m)) {
                    $max = max($max, max(array_map('intval', $m[0])));
                }
            }
        }
        return $max > 0 ? $max : null;
    }

    /**
     * Intersection of owned libraries for a set of wiki users.
     * Returns rows: appid, name, multiplayer, maxplayers, min_playtime.
     */
    public function sharedGames(array $users, bool $multiplayerOnly = false, int $minPlayers = 0): array
    {
        $wl = $this->wl();
        $users = array_values(array_unique(array_filter($users)));
        if (count($users) < 2) return [];
        $db = $wl->getDB();
        $ph = implode(',', array_fill(0, count($users), '?'));
        $mp = $multiplayerOnly ? 'AND (a.multiplayer = 1 OR a.multiplayer IS NULL)' : '';
        // unknown counts pass the filter — only games KNOWN to be too small drop
        $mpl = $minPlayers > 0
            ? 'AND (a.maxplayers IS NULL OR a.maxplayers >= ' . (int)$minPlayers . ')'
            : '';
        // count inlined, not bound: PDO binds params as TEXT, and SQLite
        // never equates an INTEGER count() with a TEXT '2' — expressions
        // get no affinity conversion, so the bound version matches nothing
        $n = count($users);
        return $db->queryAll(
            "SELECT o.appid, a.name, a.multiplayer, a.maxplayers,
                    MIN(o.playtime) AS min_playtime
               FROM steam_owned o
          LEFT JOIN steam_app_cache a ON a.appid = o.appid
              WHERE o.user IN ($ph) $mp $mpl
           GROUP BY o.appid
             HAVING COUNT(DISTINCT o.user) = $n
           ORDER BY a.name IS NULL, a.name",
            ...$users
        );
    }
}
