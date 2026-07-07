<?php

use dokuwiki\Extension\Plugin;

/**
 * Lobby management + event manager roles + wiki-page materialization.
 *
 * Lobbies (standalone, or attached to a tournament group via group_id) carry
 * a name, an optional short-lived lobby code and an optional connect link.
 * Connect info is NEVER written into wiki text or server-rendered HTML — it
 * is delivered per-viewer over AJAX only (public lobby → any logged-in user;
 * private → assigned players / group members and event managers), so a
 * changing code neither pollutes the page history nor leaks via cache.
 *
 * Everything else (rosters, bracket state) is materialized as plain wiki
 * markup between ~~LAN:lobbies~~ … ~~LAN:lobbies-end~~ markers on every
 * language variant of the event page (minor edits), keeping the event pages
 * real, history-friendly wiki content.
 *
 * Roles: hosts = wiki mods/admins + users named in the event's struct `host`
 * field; hosts appoint event moderators (event_mods) who can manage lobbies
 * and tournaments but not the moderator list.
 */
class helper_plugin_wikilan_lobby extends Plugin
{
    /** @var helper_plugin_wikilan */
    protected $wl;

    public function __construct()
    {
        $this->wl = plugin_load('helper', 'wikilan');
    }

    protected function db()
    {
        return $this->wl->getDB();
    }

    // ---------------------------------------------------------------- roles

    /** wiki mods/admins + users named in the event's struct host field */
    public function isHost(string $neutralPid, string $user): bool
    {
        if ($user === '') return false;
        if ($this->wl->isMod()) return true;
        $local = $this->wl->localPage($neutralPid);
        $data = $local ? ($this->wl->structData($local) ?? []) : [];
        $host = mb_strtolower(trim((string)($data['host'] ?? '')));
        if ($host === '') return false;
        $tokens = preg_split('/[,\/&+\s]+/u', $host, -1, PREG_SPLIT_NO_EMPTY);
        return in_array(mb_strtolower($user), $tokens, true)
            || in_array(mb_strtolower($this->wl->userName($user)), $tokens, true);
    }

    /** hosts + appointed event moderators */
    public function canManage(string $neutralPid, string $user): bool
    {
        if ($user === '') return false;
        if ($this->isHost($neutralPid, $user)) return true;
        return (bool)$this->db()->queryValue(
            "SELECT COUNT(*) FROM event_mods WHERE event_pid = ? AND user = ?",
            $neutralPid, $user
        );
    }

    public function mods(string $neutralPid): array
    {
        return array_column($this->db()->queryAll(
            "SELECT user FROM event_mods WHERE event_pid = ? ORDER BY user", $neutralPid
        ), 'user');
    }

    public function setMod(string $neutralPid, string $user, bool $add): string
    {
        global $auth;
        if ($add) {
            $user = $this->wl->resolveLogin(trim($user));
            if (!$auth || !$auth->getUserData($user)) {
                return sprintf($this->getLang('t_unknown_user'), $user);
            }
            $this->db()->exec(
                "INSERT OR IGNORE INTO event_mods (event_pid, user) VALUES (?, ?)",
                $neutralPid, $user
            );
        } else {
            $this->db()->exec(
                "DELETE FROM event_mods WHERE event_pid = ? AND user = ?",
                $neutralPid, $user
            );
        }
        return '';
    }

    /** neutral event pids the user manages (for the overview; mods get all) */
    public function manageableEvents(array $lan, string $user): array
    {
        $out = [];
        foreach ($this->wl->events($lan) as $neutral => $ev) {
            if ($this->canManage($neutral, $user)) $out[$neutral] = $ev;
        }
        return $out;
    }

    // ---------------------------------------------------------------- lobbies

    public function get(int $id): ?array
    {
        return $this->db()->queryRecord("SELECT * FROM lobbies WHERE id = ?", $id) ?: null;
    }

    /** all lobby rows of an event; standalone ones have group_id NULL */
    public function byEvent(string $neutralPid): array
    {
        return $this->db()->queryAll(
            "SELECT * FROM lobbies WHERE event_pid = ? ORDER BY (group_id IS NOT NULL), id",
            $neutralPid
        );
    }

    public function forGroup(int $groupId): ?array
    {
        return $this->db()->queryRecord(
            "SELECT * FROM lobbies WHERE group_id = ?", $groupId
        ) ?: null;
    }

    /**
     * Create/update a lobby. Standalone lobbies need a name; group lobbies
     * (connect info on a bracket group) are keyed by group_id and default to
     * private since their audience is the group roster. '' on success.
     */
    public function save(string $neutralPid, array $f, string $user): string
    {
        $name = trim((string)($f['name'] ?? ''));
        $code = trim((string)($f['code'] ?? ''));
        $link = trim((string)($f['link'] ?? ''));
        $groupId = (int)($f['group'] ?? 0);
        $id = (int)($f['id'] ?? 0);

        if ($link !== '' && !preg_match('#^(steam|https?)://\S+$#', $link)) {
            return $this->getLang('lob_bad_link');
        }

        if ($groupId) {
            // group must belong to a tournament of this event
            $ok = $this->db()->queryValue(
                "SELECT COUNT(*) FROM tourney_groups g JOIN tourneys t ON t.id = g.tourney_id
                  WHERE g.id = ? AND t.event_pid = ?", $groupId, $neutralPid
            );
            if (!$ok) return $this->getLang('error');
            $row = $this->forGroup($groupId);
            if ($row) {
                $id = (int)$row['id'];
            } else {
                $this->db()->exec(
                    "INSERT INTO lobbies (event_pid, group_id, name, code, link, public, created_by, updated)
                     VALUES (?, ?, '', ?, ?, ?, ?, ?)",
                    $neutralPid, $groupId, $code, $link,
                    isset($f['public']) ? (int)(bool)$f['public'] : 0, $user, time()
                );
                return '';
            }
        }

        if ($id) {
            $row = $this->get($id);
            if (!$row || $row['event_pid'] !== $neutralPid) return $this->getLang('error');
            $this->db()->exec(
                "UPDATE lobbies SET name = ?, code = ?, link = ?, public = ?, updated = ? WHERE id = ?",
                $row['group_id'] ? '' : $name,
                $code, $link,
                isset($f['public']) ? (int)(bool)$f['public'] : (int)$row['public'],
                time(), $id
            );
            return '';
        }

        if ($name === '') return $this->getLang('lob_name_missing');
        $this->db()->exec(
            "INSERT INTO lobbies (event_pid, name, code, link, public, created_by, updated)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            $neutralPid, $name, $code, $link,
            isset($f['public']) ? (int)(bool)$f['public'] : 1, $user, time()
        );
        return '';
    }

    public function delete(int $id): void
    {
        $this->db()->exec("DELETE FROM lobby_players WHERE lobby_id = ?", $id);
        $this->db()->exec("DELETE FROM lobbies WHERE id = ?", $id);
    }

    /** drop connect rows of bracket groups (on tournament delete / re-seed) */
    public function deleteForGroups(array $groupIds): void
    {
        foreach ($groupIds as $gid) {
            $row = $this->forGroup((int)$gid);
            if ($row) $this->delete((int)$row['id']);
        }
    }

    public function players(int $lobbyId): array
    {
        return array_column($this->db()->queryAll(
            "SELECT user FROM lobby_players WHERE lobby_id = ? ORDER BY user", $lobbyId
        ), 'user');
    }

    public function assign(string $neutralPid, int $lobbyId, string $user, bool $add): string
    {
        $row = $this->get($lobbyId);
        if (!$row || $row['event_pid'] !== $neutralPid || $row['group_id']) {
            return $this->getLang('error');
        }
        if ($add) {
            $user = $this->wl->resolveLogin(trim($user));
            global $auth;
            if (!$auth || !$auth->getUserData($user)) {
                return sprintf($this->getLang('t_unknown_user'), $user);
            }
            $this->db()->exec(
                "INSERT OR IGNORE INTO lobby_players (lobby_id, user) VALUES (?, ?)",
                $lobbyId, $user
            );
        } else {
            $this->db()->exec(
                "DELETE FROM lobby_players WHERE lobby_id = ? AND user = ?", $lobbyId, $user
            );
        }
        return '';
    }

    // ---------------------------------------------------------------- connect info (AJAX only)

    /**
     * Connect info the viewer may see: lobby id → code/link/label. Public
     * lobbies go to every logged-in user, private ones to assigned players
     * (group roster for bracket lobbies) and event managers.
     */
    public function connectFor(string $neutralPid, string $user): array
    {
        if ($user === '') return [];
        $manage = $this->canManage($neutralPid, $user);
        $out = [];
        foreach ($this->byEvent($neutralPid) as $row) {
            if ($row['code'] === '' && $row['link'] === '') continue;
            if (!$row['public'] && !$manage) {
                $assigned = $row['group_id']
                    ? array_column($this->db()->queryAll(
                        "SELECT user FROM tourney_slots WHERE group_id = ?", $row['group_id']
                    ), 'user')
                    : $this->players((int)$row['id']);
                if (!in_array($user, $assigned, true)) continue;
            }
            $out[(int)$row['id']] = ['code' => $row['code'], 'link' => $row['link']];
        }
        return $out;
    }

    // ---------------------------------------------------------------- wiki materialization

    /** plugin lang strings for a given language code (en fallback) */
    public function langFor(string $code): array
    {
        static $cache = [];
        if (!isset($cache[$code])) {
            $lang = [];
            include DOKU_PLUGIN . 'wikilan/lang/en/lang.php';
            $merged = $lang;
            $f = DOKU_PLUGIN . 'wikilan/lang/' . $code . '/lang.php';
            if ($code !== 'en' && file_exists($f)) {
                $lang = [];
                include $f;
                $merged = array_merge($merged, $lang);
            }
            $cache[$code] = $merged;
        }
        return $cache[$code];
    }

    /** localized group-name token (mirrors tourney::groupLabel for a lang) */
    protected function groupLabelIn(array $L, string $name): string
    {
        if ($name === 'final') return $L['t_final'];
        if (preg_match('/^lobby:(.+)$/', $name, $m)) return sprintf($L['t_lobby'], $m[1]);
        if (preg_match('/^match:(\d+)$/', $name, $m)) return sprintf($L['t_match'], $m[1]);
        if (strpos($name, 'bye') === 0) return $L['t_bye'];
        return $name;
    }

    /**
     * The generated wiki markup for one language: lobby list + bracket state.
     * Connect info appears only as ~~LAN:connect <id>~~ placeholders.
     */
    public function markup(string $neutralPid, string $langCode): string
    {
        $L = $this->langFor($langCode);
        $out = '';

        $standalone = array_filter(
            $this->byEvent($neutralPid), static fn($r) => !$r['group_id']
        );
        if ($standalone) {
            $out .= '===== ' . $L['lob_heading'] . " =====\n\n";
            foreach ($standalone as $row) {
                $line = '  * **' . $row['name'] . '**';
                if ($row['public']) {
                    $line .= ' — //' . $L['lob_public'] . '//';
                } else {
                    $players = $this->players((int)$row['id']);
                    $line .= ' — ' . ($players
                        ? implode(', ', array_map([$this->wl, 'userName'], $players))
                        : '//' . $L['lob_private'] . '//');
                }
                if ($row['code'] !== '' || $row['link'] !== '') {
                    $line .= ' ~~LAN:connect ' . (int)$row['id'] . '~~';
                }
                $out .= $line . "\n";
            }
            $out .= "\n";
        }

        /** @var helper_plugin_wikilan_tourney $th */
        $th = plugin_load('helper', 'wikilan_tourney');
        $t = $th->byEvent($neutralPid);
        if ($t && (int)$t['round'] > 0) {
            $out .= '===== ' . $L['t_create_title'] . " =====\n\n";
            $meta = [$L['t_mode_' . $t['mode']]];
            $meta[] = sprintf(
                $L[$t['mode'] === 'teams' ? 't_size_teams' : 't_size_ffa'],
                (int)$t['lobby_size']
            );
            if ($t['mode'] === 'ffa') {
                $meta[] = sprintf($L['t_advance_n'], (int)$t['advance']);
            }
            $meta[] = $L['t_state_' . $t['state']];
            $out .= implode(' · ', $meta) . "\n\n";

            if ($t['state'] === 'done') {
                $res = $th->result($t);
                if ($t['mode'] === 'teams' && $res) {
                    $out .= '🏆 **' . sprintf(
                        $L['t_champion'], sprintf($L['t_team'], $res['team'])
                    ) . "**\\\\ " . implode(', ', array_map([$this->wl, 'userName'], $res['members'])) . "\n\n";
                } elseif ($res) {
                    $medals = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
                    foreach ($res as $s) {
                        $r = (int)$s['rank'];
                        $out .= '  * ' . ($medals[$r] ?? '#' . $r) . ' '
                            . $this->wl->userName($s['user']) . "\n";
                    }
                    $out .= "\n";
                }
            }

            // one row of boxes per round: each lobby/match is a small table,
            // laid out side by side by CSS; advancers are bold + ✓
            foreach ($th->rounds($t) as $round => $groups) {
                $out .= '==== ' . sprintf($L['t_round'], $round) . " ====\n\n";
                foreach ($groups as $g) {
                    $out .= $t['mode'] === 'teams'
                        ? $this->teamTable($L, $g)
                        : $this->ffaTable($L, $t, $g, count($groups) === 1);
                    $out .= "\n";
                }
            }
        }
        return rtrim($out);
    }

    /** display name sanitized for use inside wiki table cells */
    protected function cellName(string $user): string
    {
        return str_replace(['^', '|'], ' ', $this->wl->userName($user));
    }

    /** group header cell: label + optional connect placeholder */
    protected function groupHeader(array $L, array $g): string
    {
        $head = $this->groupLabelIn($L, $g['name']);
        $conn = $this->forGroup((int)$g['id']);
        if ($conn && ($conn['code'] !== '' || $conn['link'] !== '')) {
            $head .= ' ~~LAN:connect ' . (int)$conn['id'] . '~~';
        }
        return $head;
    }

    /**
     * One lobby as a single-column table. In non-final rounds the players
     * currently in the advancing ranks (same pick as advance()) are marked
     * bold + ✓; the final lobby's podium is shown separately once done.
     */
    protected function ffaTable(array $L, array $t, array $g, bool $isFinal): string
    {
        $advancing = [];
        if (!$isFinal) {
            $ranked = array_values(array_filter(
                $g['slots'], static fn($s) => $s['rank'] !== null
            ));
            $need = min((int)$t['advance'], count($g['slots']));
            foreach (array_slice($ranked, 0, $need) as $s) {
                $advancing[(int)$s['id']] = true;
            }
        }
        $out = '^ ' . $this->groupHeader($L, $g) . " ^\n";
        foreach ($g['slots'] as $s) {
            $cell = ($s['rank'] !== null ? '#' . (int)$s['rank'] . ' ' : '')
                . $this->cellName($s['user']);
            if (isset($advancing[(int)$s['id']])) {
                $cell = '**' . $cell . '** ✓';
            }
            $out .= '| ' . $cell . " |\n";
        }
        return $out;
    }

    /**
     * One match as a table with a column per team (spanning title row);
     * the winning team's column is marked in the header and bold.
     */
    protected function teamTable(array $L, array $g): string
    {
        $teams = [];
        foreach ($g['slots'] as $s) $teams[$s['team']][] = $s;
        $names = array_keys($teams);
        $span = count($names);

        $out = '^ ' . $this->groupHeader($L, $g) . ' ' . str_repeat('^', $span) . "\n";
        $won = [];
        $head = '';
        foreach ($names as $team) {
            $won[$team] = (int)($teams[$team][0]['rank'] ?? 0) === 1;
            $head .= '^ ' . sprintf($L['t_team'], $team) . ($won[$team] ? ' ✓' : '') . ' ';
        }
        $out .= $head . "^\n";
        $rows = max(array_map('count', $teams));
        for ($i = 0; $i < $rows; $i++) {
            $out .= '|';
            foreach ($names as $team) {
                $cell = isset($teams[$team][$i])
                    ? $this->cellName($teams[$team][$i]['user'])
                    : '';
                if ($cell !== '' && $won[$team]) $cell = '**' . $cell . '** ✓';
                $out .= ' ' . $cell . ' |';
            }
            $out .= "\n";
        }
        return $out;
    }

    /**
     * Rewrite the managed block on every language variant of the event page.
     * Saves as a minor edit and only when the text actually changed; a
     * missing end marker is inserted on first sync.
     */
    public function syncPages(string $neutralPid): void
    {
        foreach ($this->wl->languages() ?: [''] as $l) {
            $pid = ($l ? "$l:" : '') . $neutralPid;
            if (!page_exists($pid)) continue;
            $text = rawWiki($pid);
            $start = '~~LAN:lobbies~~';
            $end = '~~LAN:lobbies-end~~';
            $s = strpos($text, $start);
            if ($s === false) continue;
            $bodyStart = $s + strlen($start);
            $e = strpos($text, $end, $bodyStart);
            $markup = $this->markup($neutralPid, $l ?: 'en');
            $generated = "\n" . ($markup !== '' ? $markup . "\n" : '');
            $new = $e === false
                ? substr($text, 0, $bodyStart) . $generated . $end . substr($text, $bodyStart)
                : substr($text, 0, $bodyStart) . $generated . substr($text, $e);
            if ($new !== $text) {
                saveWikiText($pid, $new, $this->getLang('lob_sync_summary'), true);
            }
        }
    }
}
