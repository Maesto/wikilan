<?php

use dokuwiki\Extension\Plugin;

/**
 * Tournament brackets for event pages (~~LAN:tournament~~).
 *
 * Two modes:
 *  - ffa:   signed-up players are shuffled into the minimum number of lobbies
 *           of up to lobby_size; organizers enter placements; the top
 *           `advance` of each lobby move on (re-shuffled) until a single
 *           final lobby yields the podium.
 *  - teams: players are shuffled into teams of ~lobby_size which then play a
 *           single-elimination bracket (an odd team out gets a bye); the last
 *           team standing wins.
 *
 * Organizers = wiki mods/admins, the tournament creator, and anyone on the
 * per-tournament orga list. Only organizers mutate anything; every mutation
 * is limited to the current round.
 */
class helper_plugin_wikilan_tourney extends Plugin
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

    // ---------------------------------------------------------------- lookups

    public function byEvent(string $neutralPid): ?array
    {
        return $this->db()->queryRecord(
            "SELECT * FROM tourneys WHERE event_pid = ?", $neutralPid
        ) ?: null;
    }

    public function get(int $id): ?array
    {
        return $this->db()->queryRecord("SELECT * FROM tourneys WHERE id = ?", $id) ?: null;
    }

    /** groups of one round, each with its slots (ordered by rank, then id) */
    public function groups(int $tid, int $round): array
    {
        $groups = $this->db()->queryAll(
            "SELECT * FROM tourney_groups WHERE tourney_id = ? AND round = ? ORDER BY id",
            $tid, $round
        );
        foreach ($groups as &$g) {
            $g['slots'] = $this->db()->queryAll(
                "SELECT * FROM tourney_slots WHERE group_id = ?
                  ORDER BY (rank IS NULL), rank, id", $g['id']
            );
        }
        unset($g);
        return $groups;
    }

    /** all rounds: [round => groups-with-slots], ascending */
    public function rounds(array $t): array
    {
        $out = [];
        for ($r = 1; $r <= (int)$t['round']; $r++) {
            $out[$r] = $this->groups((int)$t['id'], $r);
        }
        return $out;
    }

    /** users already holding a slot in a round */
    public function usersInRound(int $tid, int $round): array
    {
        return array_column($this->db()->queryAll(
            "SELECT s.user FROM tourney_slots s
              JOIN tourney_groups g ON g.id = s.group_id
             WHERE g.tourney_id = ? AND g.round = ?", $tid, $round
        ), 'user');
    }

    /** localized label for a stored group-name token */
    public function groupLabel(string $name): string
    {
        if ($name === 'final') return $this->getLang('t_final');
        if (preg_match('/^lobby:(.+)$/', $name, $m)) {
            return sprintf($this->getLang('t_lobby'), $m[1]);
        }
        if (preg_match('/^match:(\d+)$/', $name, $m)) {
            return sprintf($this->getLang('t_match'), $m[1]);
        }
        if (strpos($name, 'bye') === 0) return $this->getLang('t_bye');
        return $name;
    }

    public function teamLabel(string $team): string
    {
        return sprintf($this->getLang('t_team'), $team);
    }

    // ---------------------------------------------------------------- mutations

    /** '' on success, error message otherwise */
    public function create(string $neutralPid, string $mode, int $size, int $advance, string $user): string
    {
        if ($this->byEvent($neutralPid)) return $this->getLang('t_exists');
        if (!in_array($mode, ['ffa', 'teams'], true)) $mode = 'ffa';
        $size = max(2, min(64, $size));
        $advance = max(1, min($size, $advance));
        $this->db()->exec(
            "INSERT INTO tourneys (event_pid, mode, lobby_size, advance, state, round, created_by, created)
             VALUES (?, ?, ?, ?, 'setup', 0, ?, ?)",
            $neutralPid, $mode, $size, $advance, $user, time()
        );
        return '';
    }

    public function delete(int $tid): void
    {
        $this->dropGroupLobbies($tid);
        $this->db()->exec(
            "DELETE FROM tourney_slots WHERE group_id IN
             (SELECT id FROM tourney_groups WHERE tourney_id = ?)", $tid
        );
        $this->db()->exec("DELETE FROM tourney_groups WHERE tourney_id = ?", $tid);
        $this->db()->exec("DELETE FROM tourneys WHERE id = ?", $tid);
    }

    /** connect-info rows attached to this tournament's groups */
    protected function dropGroupLobbies(int $tid): void
    {
        /** @var helper_plugin_wikilan_lobby $lb */
        $lb = plugin_load('helper', 'wikilan_lobby');
        $lb->deleteForGroups(array_column($this->db()->queryAll(
            "SELECT id FROM tourney_groups WHERE tourney_id = ?", $tid
        ), 'id'));
    }

    /**
     * (Re-)shuffle all firmly signed-up players into round-1 groups. Allowed
     * until any result of round 1 is entered.
     */
    public function seed(int $tid): string
    {
        $t = $this->get($tid);
        if (!$t) return $this->getLang('error');
        if ((int)$t['round'] > 1 || $t['state'] === 'done') {
            return $this->getLang('t_seed_locked');
        }
        if ((int)$t['round'] === 1 && $this->roundHasResults($tid, 1)) {
            return $this->getLang('t_seed_locked');
        }

        $players = $this->wl->signups($t['event_pid'])['signedup'];
        if (count($players) < 2) return $this->getLang('t_too_few');
        shuffle($players);

        // wipe any previous seeding (incl. connect info on wiped groups)
        $this->dropGroupLobbies($tid);
        $this->db()->exec(
            "DELETE FROM tourney_slots WHERE group_id IN
             (SELECT id FROM tourney_groups WHERE tourney_id = ?)", $tid
        );
        $this->db()->exec("DELETE FROM tourney_groups WHERE tourney_id = ?", $tid);

        if ($t['mode'] === 'teams') {
            $teams = [];
            foreach ($this->splitBalanced($players, (int)$t['lobby_size']) as $i => $members) {
                $teams[(string)($i + 1)] = $members;
            }
            $this->pairMatches($tid, 1, $teams);
        } else {
            $lobbies = $this->splitBalanced($players, (int)$t['lobby_size']);
            foreach ($lobbies as $i => $members) {
                $name = count($lobbies) === 1 ? 'final' : 'lobby:' . chr(65 + $i);
                $gid = $this->db()->exec(
                    "INSERT INTO tourney_groups (tourney_id, round, name) VALUES (?, 1, ?)",
                    $tid, $name
                );
                foreach ($members as $u) {
                    $this->db()->exec(
                        "INSERT INTO tourney_slots (group_id, user) VALUES (?, ?)", $gid, $u
                    );
                }
            }
        }
        $this->db()->exec(
            "UPDATE tourneys SET state = 'running', round = 1 WHERE id = ?", $tid
        );
        return '';
    }

    public function addPlayer(int $tid, int $groupId, string $user): string
    {
        $t = $this->get($tid);
        $g = $this->groupInCurrentRound($t, $groupId);
        if (is_string($g)) return $g;
        $user = $this->wl->resolveLogin(trim($user));
        if ($user === '') return sprintf($this->getLang('t_unknown_user'), $user);
        if (in_array($user, $this->usersInRound($tid, (int)$t['round']), true)) {
            return sprintf($this->getLang('t_in_round'), $user);
        }
        $team = null;
        if ($t['mode'] === 'teams') {
            $team = $this->smallestTeam($groupId);
            if ($team === null) return $this->getLang('error');
        }
        $this->db()->exec(
            "INSERT INTO tourney_slots (group_id, user, team) VALUES (?, ?, ?)",
            $groupId, $user, $team
        );
        return '';
    }

    public function removePlayer(int $tid, int $slotId): string
    {
        $t = $this->get($tid);
        $slot = $this->slotInCurrentRound($t, $slotId);
        if (is_string($slot)) return $slot;
        $this->db()->exec("DELETE FROM tourney_slots WHERE id = ?", $slotId);
        return '';
    }

    /**
     * Move a slot to another group of the current round. In teams mode the
     * target is "group:team"; ffa targets are plain group ids.
     */
    public function movePlayer(int $tid, int $slotId, string $target): string
    {
        $t = $this->get($tid);
        $slot = $this->slotInCurrentRound($t, $slotId);
        if (is_string($slot)) return $slot;

        $team = null;
        if ($t['mode'] === 'teams') {
            [$groupId, $team] = array_pad(explode(':', $target, 2), 2, null);
            $groupId = (int)$groupId;
            if ($team === null || $team === '') return $this->getLang('error');
        } else {
            $groupId = (int)$target;
        }
        $g = $this->groupInCurrentRound($t, $groupId);
        if (is_string($g)) return $g;
        $this->db()->exec(
            "UPDATE tourney_slots SET group_id = ?, team = ?, rank = NULL WHERE id = ?",
            $groupId, $team, $slotId
        );
        return '';
    }

    /** ffa placement entry (rank 0/empty clears) */
    public function setRank(int $tid, int $slotId, int $rank): string
    {
        $t = $this->get($tid);
        $slot = $this->slotInCurrentRound($t, $slotId);
        if (is_string($slot)) return $slot;
        $this->db()->exec(
            "UPDATE tourney_slots SET rank = ? WHERE id = ?",
            $rank > 0 ? $rank : null, $slotId
        );
        return '';
    }

    /** teams result: the given team wins the match */
    public function setWinner(int $tid, int $groupId, string $team): string
    {
        $t = $this->get($tid);
        $g = $this->groupInCurrentRound($t, $groupId);
        if (is_string($g)) return $g;
        $this->db()->exec(
            "UPDATE tourney_slots SET rank = CASE WHEN team = ? THEN 1 ELSE 2 END
              WHERE group_id = ?", $team, $groupId
        );
        return '';
    }

    /**
     * Close the current round and build the next one from the qualifiers.
     * Teams mode finishes automatically once a single team remains.
     */
    public function advance(int $tid): string
    {
        $t = $this->get($tid);
        if (!$t || $t['state'] !== 'running') return $this->getLang('error');
        $round = (int)$t['round'];
        $groups = $this->groups($tid, $round);

        if ($t['mode'] === 'teams') {
            $winners = [];
            foreach ($groups as $g) {
                $team = null;
                foreach ($g['slots'] as $s) {
                    if ((int)$s['rank'] === 1) { $team = $s['team']; break; }
                }
                if ($team === null) {
                    return sprintf(
                        $this->getLang('t_results_missing'), $this->groupLabel($g['name'])
                    );
                }
                $members = [];
                foreach ($g['slots'] as $s) {
                    if ($s['team'] === $team) $members[] = $s['user'];
                }
                $winners[$team] = $members;
            }
            if (count($winners) === 1) {
                $this->db()->exec("UPDATE tourneys SET state = 'done' WHERE id = ?", $tid);
                return '';
            }
            $keys = array_keys($winners);
            shuffle($keys);
            $shuffled = [];
            foreach ($keys as $k) $shuffled[$k] = $winners[$k];
            $this->pairMatches($tid, $round + 1, $shuffled);
        } else {
            if (count($groups) === 1) return $this->getLang('t_last_round');
            $qualified = [];
            foreach ($groups as $g) {
                $need = min((int)$t['advance'], count($g['slots']));
                $ranked = array_values(array_filter(
                    $g['slots'], static fn($s) => $s['rank'] !== null
                ));
                if (count($ranked) < $need) {
                    return sprintf(
                        $this->getLang('t_results_missing'), $this->groupLabel($g['name'])
                    );
                }
                foreach (array_slice($ranked, 0, $need) as $s) {
                    $qualified[] = $s['user'];
                }
            }
            shuffle($qualified);
            $lobbies = $this->splitBalanced($qualified, (int)$t['lobby_size']);
            foreach ($lobbies as $i => $members) {
                $name = count($lobbies) === 1 ? 'final' : 'lobby:' . chr(65 + $i);
                $gid = $this->db()->exec(
                    "INSERT INTO tourney_groups (tourney_id, round, name) VALUES (?, ?, ?)",
                    $tid, $round + 1, $name
                );
                foreach ($members as $u) {
                    $this->db()->exec(
                        "INSERT INTO tourney_slots (group_id, user) VALUES (?, ?)", $gid, $u
                    );
                }
            }
        }
        $this->db()->exec("UPDATE tourneys SET round = round + 1 WHERE id = ?", $tid);
        return '';
    }

    /** ffa: lock in the final lobby's placements as the overall result */
    public function finish(int $tid): string
    {
        $t = $this->get($tid);
        if (!$t || $t['state'] !== 'running') return $this->getLang('error');
        $groups = $this->groups($tid, (int)$t['round']);
        if (count($groups) !== 1) return $this->getLang('t_not_final');
        $hasWinner = false;
        foreach ($groups[0]['slots'] as $s) {
            if ((int)$s['rank'] === 1) { $hasWinner = true; break; }
        }
        if (!$hasWinner) return $this->getLang('t_not_final');
        $this->db()->exec("UPDATE tourneys SET state = 'done' WHERE id = ?", $tid);
        return '';
    }

    // ---------------------------------------------------------------- results

    /**
     * Final standings of a finished tournament: ffa returns the final lobby's
     * slots by rank; teams returns ['team' => name, 'members' => users].
     */
    public function result(array $t): array
    {
        $groups = $this->groups((int)$t['id'], (int)$t['round']);
        if (!$groups) return [];
        if ($t['mode'] === 'teams') {
            foreach ($groups as $g) {
                foreach ($g['slots'] as $s) {
                    if ((int)$s['rank'] === 1) {
                        $members = [];
                        foreach ($g['slots'] as $x) {
                            if ($x['team'] === $s['team']) $members[] = $x['user'];
                        }
                        return ['team' => $s['team'], 'members' => $members];
                    }
                }
            }
            return [];
        }
        $slots = array_values(array_filter(
            $groups[0]['slots'], static fn($s) => $s['rank'] !== null
        ));
        return $slots;
    }

    // ---------------------------------------------------------------- internals

    protected function roundHasResults(int $tid, int $round): bool
    {
        return (int)$this->db()->queryValue(
            "SELECT COUNT(*) FROM tourney_slots s
              JOIN tourney_groups g ON g.id = s.group_id
             WHERE g.tourney_id = ? AND g.round = ? AND s.rank IS NOT NULL",
            $tid, $round
        ) > 0;
    }

    /** shuffle-agnostic even split into ceil(n/size) buckets */
    protected function splitBalanced(array $users, int $size): array
    {
        $n = count($users);
        $buckets = max(1, (int)ceil($n / max(2, $size)));
        $out = array_fill(0, $buckets, []);
        foreach (array_values($users) as $i => $u) {
            $out[$i % $buckets][] = $u;
        }
        return $out;
    }

    /**
     * Create match groups for a round from teams (name => members): pairs
     * become matches, a final pair is named accordingly and an odd team out
     * gets a bye (auto-win, so it advances with the next round's winners).
     */
    protected function pairMatches(int $tid, int $round, array $teams): void
    {
        $names = array_keys($teams);
        $match = 1;
        for ($i = 0; $i < count($names); $i += 2) {
            if ($i === count($names) - 1) {
                $gid = $this->db()->exec(
                    "INSERT INTO tourney_groups (tourney_id, round, name) VALUES (?, ?, ?)",
                    $tid, $round, 'bye:' . $names[$i]
                );
                foreach ($teams[$names[$i]] as $u) {
                    $this->db()->exec(
                        "INSERT INTO tourney_slots (group_id, user, team, rank) VALUES (?, ?, ?, 1)",
                        $gid, $u, $names[$i]
                    );
                }
                continue;
            }
            $name = count($names) === 2 ? 'final' : 'match:' . $match++;
            $gid = $this->db()->exec(
                "INSERT INTO tourney_groups (tourney_id, round, name) VALUES (?, ?, ?)",
                $tid, $round, $name
            );
            foreach ([$names[$i], $names[$i + 1]] as $team) {
                foreach ($teams[$team] as $u) {
                    $this->db()->exec(
                        "INSERT INTO tourney_slots (group_id, user, team) VALUES (?, ?, ?)",
                        $gid, $u, $team
                    );
                }
            }
        }
    }

    /** team of the group with the fewest members (teams mode) */
    protected function smallestTeam(int $groupId): ?string
    {
        $rows = $this->db()->queryAll(
            "SELECT team, COUNT(*) AS n FROM tourney_slots
              WHERE group_id = ? GROUP BY team ORDER BY n, team", $groupId
        );
        return $rows ? $rows[0]['team'] : null;
    }

    /** @return array|string group row, or error message */
    protected function groupInCurrentRound(?array $t, int $groupId)
    {
        if (!$t || $t['state'] !== 'running') return $this->getLang('error');
        $g = $this->db()->queryRecord(
            "SELECT * FROM tourney_groups WHERE id = ? AND tourney_id = ? AND round = ?",
            $groupId, (int)$t['id'], (int)$t['round']
        );
        return $g ?: $this->getLang('t_old_round');
    }

    /** @return array|string slot row, or error message */
    protected function slotInCurrentRound(?array $t, int $slotId)
    {
        if (!$t || $t['state'] !== 'running') return $this->getLang('error');
        $s = $this->db()->queryRecord(
            "SELECT s.* FROM tourney_slots s JOIN tourney_groups g ON g.id = s.group_id
              WHERE s.id = ? AND g.tourney_id = ? AND g.round = ?",
            $slotId, (int)$t['id'], (int)$t['round']
        );
        return $s ?: $this->getLang('t_old_round');
    }
}
