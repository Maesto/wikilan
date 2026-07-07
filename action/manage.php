<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

/**
 * ?do=wikilan_manage — global lobby & tournament management view.
 *
 * Works from any page: pick an edition (defaults to the active LAN), get an
 * overview of the events you may manage (hosts and event moderators see
 * theirs, wiki mods everything), drill into one event to manage moderators,
 * lobbies (name / code / connect link / public flag / assigned players) and
 * the tournament bracket. All mutations go through the plugin AJAX endpoints
 * and re-materialize the event's wiki pages.
 */
class action_plugin_wikilan_manage extends ActionPlugin
{
    /** @var helper_plugin_wikilan */
    protected $wl;
    /** @var helper_plugin_wikilan_lobby */
    protected $lb;
    /** @var helper_plugin_wikilan_tourney */
    protected $th;

    public function register(EventHandler $controller)
    {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'allowAct');
        $controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE', $this, 'output');
    }

    public function allowAct(Event $event, $param)
    {
        if ($event->data !== 'wikilan_manage') return;
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        if ($wl->user() === '') {
            $event->data = 'login';
            return;
        }
        $event->preventDefault();
    }

    public function output(Event $event, $param)
    {
        if ($event->data !== 'wikilan_manage') return;
        $event->preventDefault();
        $event->stopPropagation();

        $this->wl = plugin_load('helper', 'wikilan');
        $this->lb = plugin_load('helper', 'wikilan_lobby');
        $this->th = plugin_load('helper', 'wikilan_tourney');

        global $INPUT;
        $lan = null;
        if ($INPUT->str('lan') !== '') {
            $lan = $this->wl->lanByNamespace($INPUT->str('lan'));
        }
        if (!$lan) $lan = $this->wl->activeLan();

        echo '<div class="wl-managepage">';
        echo '<h1>' . hsc($this->lb->getLang('lm_title')) . '</h1>';

        $eventPid = $INPUT->str('event');
        if ($eventPid !== '') {
            $this->detail($this->wl->neutralId($eventPid), $lan);
        } else {
            $this->overview($lan);
        }
        echo '</div>';
    }

    // ---------------------------------------------------------------- overview

    protected function overview(?array $lan): void
    {
        global $ID;
        $user = $this->wl->user();

        // edition picker
        $lans = $this->wl->getDB()->queryAll("SELECT * FROM lans ORDER BY id DESC");
        if (count($lans) > 1) {
            echo '<p class="wl-lm-lans">';
            $links = [];
            foreach ($lans as $l) {
                $label = hsc($l['title'] ?? $l['namespace']);
                if ($lan && $l['id'] === $lan['id']) {
                    $links[] = '<strong>' . $label . '</strong>';
                } else {
                    $links[] = '<a href="' . wl($ID, ['do' => 'wikilan_manage', 'lan' => $l['namespace']])
                        . '">' . $label . '</a>';
                }
            }
            echo implode(' · ', $links) . '</p>';
        }
        if (!$lan) {
            echo '<p class="wl-empty">' . hsc($this->wl->getLang('no_active_lan')) . '</p>';
            return;
        }

        $events = $this->lb->manageableEvents($lan, $user);
        if (!$events) {
            echo '<p class="wl-empty">' . hsc($this->lb->getLang('lm_no_events')) . '</p>';
            return;
        }

        echo '<table class="inline wl-lm-overview"><tr>'
            . '<th>' . hsc($this->lb->getLang('lm_event')) . '</th>'
            . '<th>' . hsc($this->lb->getLang('lm_when')) . '</th>'
            . '<th>' . hsc($this->lb->getLang('lob_heading')) . '</th>'
            . '<th>' . hsc($this->lb->getLang('t_create_title')) . '</th>'
            . '<th></th></tr>';
        $lang = $this->wl->pageLang($GLOBALS['ID'] ?? '');
        foreach ($events as $neutral => $ev) {
            $lobbies = count(array_filter(
                $this->lb->byEvent($neutral), static fn($r) => !$r['group_id']
            ));
            $t = $this->th->byEvent($neutral);
            echo '<tr><td>' . ($ev['page']
                    ? html_wikilink(':' . $ev['page'], $ev['title'])
                    : hsc($ev['title'])) . '</td>'
                . '<td>' . ($ev['start_ts'] ? hsc($this->wl->formatWhen($ev['start_ts'], $lang)) : '—') . '</td>'
                . '<td>' . ($lobbies ?: '—') . '</td>'
                . '<td>' . ($t ? hsc($this->lb->getLang('t_state_' . $t['state'])) : '—') . '</td>'
                . '<td><a href="' . wl($GLOBALS['ID'], ['do' => 'wikilan_manage', 'event' => $neutral])
                . '">' . hsc($this->lb->getLang('lm_manage_link')) . '</a></td></tr>';
        }
        echo '</table>';
    }

    // ---------------------------------------------------------------- detail

    protected function detail(string $pid, ?array $lan): void
    {
        global $ID;
        $user = $this->wl->user();
        if (!$this->lb->canManage($pid, $user)) {
            echo '<p class="wl-empty">' . hsc($this->lb->getLang('t_not_orga')) . '</p>';
            return;
        }

        $local = $this->wl->localPage($pid);
        $title = $local ? (p_get_first_heading($local) ?: noNS($pid)) : noNS($pid);
        echo '<p class="wl-lm-nav"><a href="' . wl($ID, ['do' => 'wikilan_manage'])
            . '">← ' . hsc($this->lb->getLang('lm_back')) . '</a></p>';
        echo '<h2>' . hsc($title) . '</h2>';
        if ($local) {
            echo '<p>' . html_wikilink(':' . $local) . '</p>';
        }

        echo '<div class="wl-lm" data-event="' . hsc($pid) . '">';
        $this->moderators($pid);
        $this->lobbies($pid, $lan);
        $this->tournament($pid);
        echo '</div>';
    }

    protected function moderators(string $pid): void
    {
        $isHost = $this->lb->isHost($pid, $this->wl->user());
        echo '<h3>' . hsc($this->lb->getLang('lm_mods')) . '</h3>';
        echo '<p class="wl-lm-modhint">' . hsc($this->lb->getLang('lm_mods_hint')) . '</p>';
        echo '<p class="wl-lm-modlist">';
        $mods = $this->lb->mods($pid);
        if (!$mods) echo '<em>—</em>';
        foreach ($mods as $m) {
            echo '<span class="wl-lm-mod">' . hsc($this->wl->userName($m));
            if ($isHost) {
                echo '<button class="wl-lm-moddel" data-user="' . hsc($m) . '" title="'
                    . hsc($this->lb->getLang('t_remove')) . '">×</button>';
            }
            echo '</span> ';
        }
        echo '</p>';
        if ($isHost) {
            echo '<p><input class="wl-lm-moduser" placeholder="'
                . hsc($this->lb->getLang('t_orga_ph')) . '">'
                . '<button class="wl-lm-modadd">+</button></p>';
        }
    }

    protected function lobbies(string $pid, ?array $lan): void
    {
        echo '<h3>' . hsc($this->lb->getLang('lob_heading')) . '</h3>';

        // player candidates: event signups + LAN attendees
        $cand = $this->wl->signups($pid);
        $cand = array_merge($cand['signedup'], $cand['interested']);
        if ($lan) {
            foreach ($this->wl->attendees((int)$lan['id']) as $a) $cand[] = $a['user'];
        }
        echo '<datalist id="wl-lm-cand">';
        foreach (array_unique($cand) as $u) {
            echo '<option value="' . hsc($u) . '">';
        }
        echo '</datalist>';

        echo '<div class="wl-lm-lobbies">';
        $rows = array_filter($this->lb->byEvent($pid), static fn($r) => !$r['group_id']);
        foreach ($rows as $row) {
            $this->lobbyCard($row);
        }
        $this->lobbyCard(null); // blank "new lobby" card
        echo '</div>';
    }

    protected function lobbyCard(?array $row): void
    {
        $lb = $this->lb;
        $new = $row === null;
        echo '<div class="wl-lm-lobby' . ($new ? ' wl-lm-new' : '')
            . '" data-id="' . ($new ? '' : (int)$row['id']) . '">';
        echo '<h4>' . hsc($lb->getLang($new ? 'lob_new' : 'lob_lobby')) . '</h4>';
        echo '<label>' . hsc($lb->getLang('lob_name'))
            . ' <input class="wl-lm-name" maxlength="80" value="' . ($new ? '' : hsc($row['name'])) . '"></label>';
        echo '<label>' . hsc($lb->getLang('lob_code'))
            . ' <input class="wl-lm-code" maxlength="120" value="' . ($new ? '' : hsc($row['code'])) . '"></label>';
        echo '<label>' . hsc($lb->getLang('lob_link'))
            . ' <input class="wl-lm-link" maxlength="250" placeholder="steam://… / https://…" value="'
            . ($new ? '' : hsc($row['link'])) . '"></label>';
        echo '<label class="wl-lm-publabel"><input type="checkbox" class="wl-lm-public"'
            . ($new || $row['public'] ? ' checked' : '') . '> '
            . hsc($lb->getLang('lob_public')) . '</label>';

        if (!$new) {
            echo '<div class="wl-lm-players"' . ($row['public'] ? ' hidden' : '') . '>';
            echo '<strong>' . hsc($lb->getLang('lob_players')) . ':</strong> ';
            foreach ($lb->players((int)$row['id']) as $p) {
                echo '<span class="wl-lm-player">' . hsc($this->wl->userName($p))
                    . '<button class="wl-lm-punassign" data-user="' . hsc($p) . '" title="'
                    . hsc($lb->getLang('t_remove')) . '">×</button></span> ';
            }
            echo '<input class="wl-lm-passign" list="wl-lm-cand" placeholder="'
                . hsc($lb->getLang('t_add_ph')) . '">'
                . '<button class="wl-lm-passignbtn">+</button>';
            echo '</div>';
        }

        echo '<div class="wl-lm-lobbybtns">'
            . '<button class="wl-lm-save">' . hsc($lb->getLang($new ? 'lob_create' : 'lob_save')) . '</button>';
        if (!$new) {
            echo '<button class="wl-lm-delete">' . hsc($lb->getLang('lob_delete')) . '</button>';
        }
        echo '</div></div>';
    }

    // ---------------------------------------------------------------- tournament

    protected function tournament(string $pid): void
    {
        $th = $this->th;
        echo '<h3>' . hsc($th->getLang('t_create_title')) . '</h3>';
        $t = $th->byEvent($pid);

        if (!$t) {
            $n = count($this->wl->signups($pid)['signedup']);
            echo '<div class="wl-tourney wl-t-createbox" data-event="' . hsc($pid) . '">';
            echo '<p>' . hsc(sprintf($th->getLang('t_signedup_n'), $n)) . '</p>';
            echo '<label>' . hsc($th->getLang('t_mode')) . ' <select class="wl-t-newmode">'
                . '<option value="ffa">' . hsc($th->getLang('t_mode_ffa')) . '</option>'
                . '<option value="teams">' . hsc($th->getLang('t_mode_teams')) . '</option>'
                . '</select></label> ';
            echo '<label>' . hsc($th->getLang('t_size'))
                . ' <input type="number" class="wl-t-newsize" min="2" max="64" value="8"></label> ';
            echo '<label class="wl-t-advwrap">' . hsc($th->getLang('t_advance'))
                . ' <input type="number" class="wl-t-newadv" min="1" max="64" value="4"></label> ';
            echo '<button class="wl-t-create">' . hsc($th->getLang('t_create')) . '</button>';
            echo '</div>';
            return;
        }

        $tid = (int)$t['id'];
        echo '<div class="wl-tourney" data-tid="' . $tid . '" data-mode="' . hsc($t['mode']) . '">';

        $meta = [$th->getLang('t_mode_' . $t['mode'])];
        $meta[] = sprintf(
            $th->getLang($t['mode'] === 'teams' ? 't_size_teams' : 't_size_ffa'),
            (int)$t['lobby_size']
        );
        if ($t['mode'] === 'ffa') {
            $meta[] = sprintf($th->getLang('t_advance_n'), (int)$t['advance']);
        }
        $meta[] = $th->getLang('t_state_' . $t['state']);
        echo '<p class="wl-t-meta">' . hsc(implode(' · ', $meta)) . '</p>';

        if ($t['state'] === 'done') {
            $this->tourneyResult($t);
        }
        if ((int)$t['round'] > 0) {
            $this->bracket($t);
        }
        $this->tourneyControls($t);
        echo '</div>';
    }

    protected function tourneyResult(array $t): void
    {
        $res = $this->th->result($t);
        if (!$res) return;
        echo '<div class="wl-t-podium">';
        if ($t['mode'] === 'teams') {
            echo '<p class="wl-t-champion">🏆 ' . hsc(sprintf(
                $this->th->getLang('t_champion'), $this->th->teamLabel($res['team'])
            )) . '</p><p>' . hsc(implode(', ', array_map([$this->wl, 'userName'], $res['members']))) . '</p>';
        } else {
            $medals = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
            echo '<ul class="wl-userlist">';
            foreach ($res as $s) {
                $r = (int)$s['rank'];
                echo '<li><span class="wl-t-medal">' . ($medals[$r] ?? '#' . $r) . '</span>'
                    . hsc($this->wl->userName($s['user'])) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    protected function bracket(array $t): void
    {
        $th = $this->th;
        $tid = (int)$t['id'];
        $current = (int)$t['round'];
        $live = $t['state'] === 'running';

        // add-player candidates: signed up but not placed in the current round
        if ($live) {
            $placed = $th->usersInRound($tid, $current);
            $cand = array_diff($this->wl->signups($t['event_pid'])['signedup'], $placed);
            echo '<datalist id="wl-t-cand-' . $tid . '">';
            foreach ($cand as $u) echo '<option value="' . hsc($u) . '">';
            echo '</datalist>';
        }

        echo '<div class="wl-t-rounds">';
        foreach ($th->rounds($t) as $round => $groups) {
            $editable = $live && $round === $current;
            echo '<div class="wl-t-round' . ($round === $current ? ' wl-t-current' : '') . '">';
            echo '<h4>' . hsc(sprintf($th->getLang('t_round'), $round)) . '</h4>';
            foreach ($groups as $g) {
                echo '<div class="wl-t-group" data-group="' . (int)$g['id'] . '">';
                echo '<h5>' . hsc($th->groupLabel($g['name'])) . '</h5>';
                if ($t['mode'] === 'teams') {
                    $this->teamGroup($t, $g, $groups, $editable);
                } else {
                    $this->ffaGroup($t, $g, $groups, $editable);
                }
                if ($editable && strpos($g['name'], 'bye') !== 0) {
                    echo '<div class="wl-t-addrow">'
                        . '<input class="wl-t-adduser" list="wl-t-cand-' . $tid . '" placeholder="'
                        . hsc($th->getLang('t_add_ph')) . '">'
                        . '<button class="wl-t-add">' . hsc($th->getLang('t_add')) . '</button></div>';
                    $this->groupConnect($g);
                }
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    /** connect-info mini-form on a current-round group */
    protected function groupConnect(array $g): void
    {
        $lb = $this->lb;
        $row = $lb->forGroup((int)$g['id']);
        echo '<div class="wl-t-conn">'
            . '<input class="wl-lm-code" maxlength="120" placeholder="'
            . hsc($lb->getLang('lob_code')) . '" value="' . hsc($row['code'] ?? '') . '">'
            . '<input class="wl-lm-link" maxlength="250" placeholder="steam://…" value="'
            . hsc($row['link'] ?? '') . '">'
            . '<label><input type="checkbox" class="wl-lm-public"'
            . (!empty($row['public']) ? ' checked' : '') . '> '
            . hsc($lb->getLang('lob_public')) . '</label>'
            . '<button class="wl-t-connsave">' . hsc($lb->getLang('lob_save')) . '</button>'
            . '</div>';
    }

    protected function ffaGroup(array $t, array $g, array $groups, bool $editable): void
    {
        $th = $this->th;
        echo '<ul class="wl-t-slots">';
        foreach ($g['slots'] as $s) {
            echo '<li data-slot="' . (int)$s['id'] . '">';
            if ($editable) {
                echo '<input type="number" class="wl-t-rank" min="1" max="99" placeholder="#" value="'
                    . ($s['rank'] !== null ? (int)$s['rank'] : '') . '" title="'
                    . hsc($th->getLang('t_rank_hint')) . '">';
            } elseif ($s['rank'] !== null) {
                echo '<span class="wl-t-rankbadge">#' . (int)$s['rank'] . '</span>';
            }
            echo '<span class="wl-t-user">' . hsc($this->wl->userName($s['user'])) . '</span>';
            if ($editable) {
                echo $this->moveSelect($t, $g, $groups, false);
                echo '<button class="wl-t-remove" title="' . hsc($th->getLang('t_remove')) . '">×</button>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }

    protected function teamGroup(array $t, array $g, array $groups, bool $editable): void
    {
        $th = $this->th;
        $teams = [];
        foreach ($g['slots'] as $s) $teams[$s['team']][] = $s;
        $isBye = strpos($g['name'], 'bye') === 0;
        foreach ($teams as $team => $slots) {
            $won = (int)($slots[0]['rank'] ?? 0) === 1;
            echo '<div class="wl-t-team' . ($won ? ' wl-t-won' : '') . '" data-team="' . hsc($team) . '">';
            echo '<h6>' . hsc($th->teamLabel((string)$team))
                . ($won ? ' <span class="wl-t-winmark">✓</span>' : '');
            if ($editable && !$isBye && !$won) {
                echo ' <button class="wl-t-winner">' . hsc($th->getLang('t_winner_btn')) . '</button>';
            }
            echo '</h6><ul class="wl-t-slots">';
            foreach ($slots as $s) {
                echo '<li data-slot="' . (int)$s['id'] . '">'
                    . '<span class="wl-t-user">' . hsc($this->wl->userName($s['user'])) . '</span>';
                if ($editable) {
                    echo $this->moveSelect($t, $g, $groups, true, (string)$team);
                    echo '<button class="wl-t-remove" title="' . hsc($th->getLang('t_remove')) . '">×</button>';
                }
                echo '</li>';
            }
            echo '</ul></div>';
        }
    }

    protected function moveSelect(array $t, array $g, array $groups, bool $teams, string $ownTeam = ''): string
    {
        $th = $this->th;
        $opts = '';
        foreach ($groups as $other) {
            if ($teams) {
                $otherTeams = array_unique(array_column($other['slots'], 'team'));
                sort($otherTeams);
                foreach ($otherTeams as $ot) {
                    if ($other['id'] === $g['id'] && $ot === $ownTeam) continue;
                    $opts .= '<option value="' . (int)$other['id'] . ':' . hsc($ot) . '">'
                        . hsc($th->groupLabel($other['name']) . ' / ' . $th->teamLabel((string)$ot))
                        . '</option>';
                }
            } else {
                if ($other['id'] === $g['id']) continue;
                $opts .= '<option value="' . (int)$other['id'] . '">'
                    . hsc($th->groupLabel($other['name'])) . '</option>';
            }
        }
        if ($opts === '') return '';
        return '<select class="wl-t-move"><option value="">'
            . hsc($th->getLang('t_move')) . '</option>' . $opts . '</select>';
    }

    protected function tourneyControls(array $t): void
    {
        $th = $this->th;
        echo '<div class="wl-t-controls">';
        if ($t['state'] !== 'done') {
            if ((int)$t['round'] <= 1) {
                echo '<button class="wl-t-seed">' . hsc($th->getLang(
                    (int)$t['round'] === 0 ? 't_seed' : 't_reseed'
                )) . '</button>';
            }
            if ($t['state'] === 'running') {
                $groups = $th->groups((int)$t['id'], (int)$t['round']);
                if ($t['mode'] === 'ffa' && count($groups) === 1) {
                    echo '<button class="wl-t-finish">' . hsc($th->getLang('t_finish_btn')) . '</button>';
                } else {
                    echo '<button class="wl-t-advance">' . hsc($th->getLang('t_advance_btn')) . '</button>';
                }
            }
        }
        echo '<button class="wl-t-delete">' . hsc($th->getLang('t_delete')) . '</button>';
        echo '</div>';
    }
}
