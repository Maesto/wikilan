<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * ~~LAN:tournament~~ — bracket display + organizer controls on an event page.
 * Optional params preset the create form: mode=ffa|teams size=8 advance=4.
 *
 * Regular visitors see the groups/rounds and, once finished, the podium or
 * winning team. Organizers (mods, creator, orga list) additionally get
 * seeding, result entry, player moves and round advancement.
 */
class syntax_plugin_wikilan_tournament extends SyntaxPlugin
{
    public function getType()  { return 'substition'; }
    public function getPType() { return 'block'; }
    public function getSort()  { return 155; }

    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~LAN:tournament\b[^~]*~~', $mode, 'plugin_wikilan_tournament');
    }

    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        return wikilan_parse_params($match);
    }

    public function render($format, Doku_Renderer $renderer, $data)
    {
        if ($format !== 'xhtml') return false;
        global $ID;
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        /** @var helper_plugin_wikilan_tourney $th */
        $th = plugin_load('helper', 'wikilan_tourney');
        $renderer->nocache();

        $pid = $wl->neutralId(!empty($data['event']) ? $data['event'] : $ID);
        $user = $wl->user();
        $t = $th->byEvent($pid);

        if (!$t) {
            if ($th->canCreate($pid, $user)) {
                $renderer->doc .= $this->createForm($wl, $th, $pid, $data);
            }
            return true;
        }

        $isOrga = $th->isOrga($t, $user);
        $tid = (int)$t['id'];
        $renderer->doc .= '<div class="wl-tourney" data-tid="' . $tid
            . '" data-mode="' . hsc($t['mode']) . '">';

        // header: mode, params, state, organizers
        $modeLabel = $th->getLang('t_mode_' . $t['mode']);
        $meta = [$modeLabel];
        $meta[] = sprintf(
            $th->getLang($t['mode'] === 'teams' ? 't_size_teams' : 't_size_ffa'),
            (int)$t['lobby_size']
        );
        if ($t['mode'] === 'ffa') {
            $meta[] = sprintf($th->getLang('t_advance_n'), (int)$t['advance']);
        }
        $meta[] = $th->getLang('t_state_' . $t['state']);
        $renderer->doc .= '<p class="wl-t-meta">' . hsc(implode(' · ', $meta)) . '</p>';

        $extraOrgas = array_diff($th->orgas($tid), [$t['created_by']]);
        $chips = $t['created_by'] !== '' ? [hsc($wl->userName($t['created_by']))] : [];
        foreach ($extraOrgas as $o) {
            $chip = hsc($wl->userName($o));
            if ($isOrga) {
                $chip .= '<button class="wl-t-orgadel" data-user="' . hsc($o)
                    . '" title="' . hsc($th->getLang('t_remove')) . '">×</button>';
            }
            $chips[] = $chip;
        }
        $renderer->doc .= '<p class="wl-t-orgas">' . sprintf(
            hsc($th->getLang('t_orgas')),
            implode(', ', $chips)
        ) . '</p>';

        if ($t['state'] === 'done') {
            $renderer->doc .= $this->resultBlock($wl, $th, $t);
        }

        if ((int)$t['round'] > 0) {
            $renderer->doc .= $this->bracket($wl, $th, $t, $isOrga);
        }

        if ($isOrga) {
            $renderer->doc .= $this->orgaControls($wl, $th, $t);
        }
        $renderer->doc .= '</div>';
        return true;
    }

    // ------------------------------------------------------------ blocks

    protected function createForm(helper_plugin_wikilan $wl, helper_plugin_wikilan_tourney $th, string $pid, array $data): string
    {
        $mode = ($data['mode'] ?? 'ffa') === 'teams' ? 'teams' : 'ffa';
        $size = max(2, (int)($data['size'] ?? 8));
        $advance = max(1, (int)($data['advance'] ?? 4));
        $n = count($wl->signups($pid)['signedup']);

        $html  = '<div class="wl-tourney wl-t-createbox" data-event="' . hsc($pid) . '">';
        $html .= '<p><strong>' . hsc($th->getLang('t_create_title')) . '</strong> — '
            . hsc(sprintf($th->getLang('t_signedup_n'), $n)) . '</p>';
        $html .= '<label>' . hsc($th->getLang('t_mode')) . ' <select class="wl-t-newmode">'
            . '<option value="ffa"' . ($mode === 'ffa' ? ' selected' : '') . '>'
            . hsc($th->getLang('t_mode_ffa')) . '</option>'
            . '<option value="teams"' . ($mode === 'teams' ? ' selected' : '') . '>'
            . hsc($th->getLang('t_mode_teams')) . '</option>'
            . '</select></label> ';
        $html .= '<label>' . hsc($th->getLang('t_size')) . ' <input type="number" class="wl-t-newsize" min="2" max="64" value="' . $size . '"></label> ';
        $html .= '<label class="wl-t-advwrap">' . hsc($th->getLang('t_advance')) . ' <input type="number" class="wl-t-newadv" min="1" max="64" value="' . $advance . '"></label> ';
        $html .= '<button class="wl-t-create">' . hsc($th->getLang('t_create')) . '</button>';
        $html .= '</div>';
        return $html;
    }

    protected function resultBlock(helper_plugin_wikilan $wl, helper_plugin_wikilan_tourney $th, array $t): string
    {
        $res = $th->result($t);
        if (!$res) return '';
        $html = '<div class="wl-t-podium">';
        if ($t['mode'] === 'teams') {
            $html .= '<p class="wl-t-champion">🏆 ' . hsc(sprintf(
                $th->getLang('t_champion'), $th->teamLabel($res['team'])
            )) . '</p><ul class="wl-userlist">';
            $profiles = $wl->steamProfiles($res['members']);
            foreach ($res['members'] as $u) {
                $html .= '<li>' . $wl->userChip($u, $profiles[$u] ?? null) . '</li>';
            }
            $html .= '</ul>';
        } else {
            $medals = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
            $profiles = $wl->steamProfiles(array_column($res, 'user'));
            $html .= '<ul class="wl-userlist">';
            foreach ($res as $s) {
                $r = (int)$s['rank'];
                $html .= '<li><span class="wl-t-medal">' . ($medals[$r] ?? '#' . $r) . '</span>'
                    . $wl->userChip($s['user'], $profiles[$s['user']] ?? null) . '</li>';
            }
            $html .= '</ul>';
        }
        return $html . '</div>';
    }

    protected function bracket(helper_plugin_wikilan $wl, helper_plugin_wikilan_tourney $th, array $t, bool $isOrga): string
    {
        $tid = (int)$t['id'];
        $rounds = $th->rounds($t);
        $live = $isOrga && $t['state'] === 'running';
        $current = (int)$t['round'];

        // one shared avatar lookup for every slot in the tournament
        $allUsers = [];
        foreach ($rounds as $groups) {
            foreach ($groups as $g) {
                foreach ($g['slots'] as $s) $allUsers[] = $s['user'];
            }
        }
        $profiles = $wl->steamProfiles(array_unique($allUsers));

        // candidates for the add-player datalist: signed up but not placed
        $datalist = '';
        if ($live) {
            $placed = $th->usersInRound($tid, $current);
            $cand = array_diff($wl->signups($t['event_pid'])['signedup'], $placed);
            $datalist = '<datalist id="wl-t-cand-' . $tid . '">';
            foreach ($cand as $u) {
                $datalist .= '<option value="' . hsc($u) . '">';
            }
            $datalist .= '</datalist>';
        }

        $html = '<div class="wl-t-rounds">';
        foreach ($rounds as $round => $groups) {
            $editable = $live && $round === $current;
            $html .= '<div class="wl-t-round' . ($round === $current ? ' wl-t-current' : '') . '">';
            $html .= '<h4>' . hsc(sprintf($th->getLang('t_round'), $round)) . '</h4>';
            foreach ($groups as $g) {
                $html .= '<div class="wl-t-group" data-group="' . (int)$g['id'] . '">';
                $html .= '<h5>' . hsc($th->groupLabel($g['name'])) . '</h5>';
                if ($t['mode'] === 'teams') {
                    $html .= $this->teamGroup($wl, $th, $t, $g, $groups, $editable, $profiles);
                } else {
                    $html .= $this->ffaGroup($wl, $th, $t, $g, $groups, $editable, $profiles);
                }
                if ($editable && strpos($g['name'], 'bye') !== 0) {
                    $html .= '<div class="wl-t-addrow">'
                        . '<input class="wl-t-adduser" list="wl-t-cand-' . $tid . '" placeholder="'
                        . hsc($th->getLang('t_add_ph')) . '">'
                        . '<button class="wl-t-add">' . hsc($th->getLang('t_add')) . '</button></div>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }
        return $html . '</div>' . $datalist;
    }

    protected function ffaGroup(helper_plugin_wikilan $wl, helper_plugin_wikilan_tourney $th, array $t, array $g, array $groups, bool $editable, array $profiles): string
    {
        $html = '<ul class="wl-t-slots">';
        foreach ($g['slots'] as $s) {
            $html .= '<li data-slot="' . (int)$s['id'] . '">';
            if ($editable) {
                $html .= '<input type="number" class="wl-t-rank" min="1" max="99" placeholder="#" value="'
                    . ($s['rank'] !== null ? (int)$s['rank'] : '') . '" title="'
                    . hsc($th->getLang('t_rank_hint')) . '">';
            } elseif ($s['rank'] !== null) {
                $html .= '<span class="wl-t-rankbadge">#' . (int)$s['rank'] . '</span>';
            }
            $html .= $wl->userChip($s['user'], $profiles[$s['user']] ?? null);
            if ($editable) {
                $html .= $this->moveSelect($th, $t, $g, $groups, false);
                $html .= '<button class="wl-t-remove" title="'
                    . hsc($th->getLang('t_remove')) . '">×</button>';
            }
            $html .= '</li>';
        }
        return $html . '</ul>';
    }

    protected function teamGroup(helper_plugin_wikilan $wl, helper_plugin_wikilan_tourney $th, array $t, array $g, array $groups, bool $editable, array $profiles): string
    {
        $teams = [];
        foreach ($g['slots'] as $s) {
            $teams[$s['team']][] = $s;
        }
        $isBye = strpos($g['name'], 'bye') === 0;
        $html = '';
        foreach ($teams as $team => $slots) {
            $won = (int)($slots[0]['rank'] ?? 0) === 1;
            $html .= '<div class="wl-t-team' . ($won ? ' wl-t-won' : '') . '" data-team="' . hsc($team) . '">';
            $html .= '<h6>' . hsc($th->teamLabel((string)$team))
                . ($won ? ' <span class="wl-t-winmark">✓</span>' : '');
            if ($editable && !$isBye && !$won) {
                $html .= ' <button class="wl-t-winner">' . hsc($th->getLang('t_winner_btn')) . '</button>';
            }
            $html .= '</h6><ul class="wl-t-slots">';
            foreach ($slots as $s) {
                $html .= '<li data-slot="' . (int)$s['id'] . '">'
                    . $wl->userChip($s['user'], $profiles[$s['user']] ?? null);
                if ($editable) {
                    $html .= $this->moveSelect($th, $t, $g, $groups, true, (string)$team);
                    $html .= '<button class="wl-t-remove" title="'
                        . hsc($th->getLang('t_remove')) . '">×</button>';
                }
                $html .= '</li>';
            }
            $html .= '</ul></div>';
        }
        return $html;
    }

    /** target picker: other groups (ffa) or group:team combos (teams) */
    protected function moveSelect(helper_plugin_wikilan_tourney $th, array $t, array $g, array $groups, bool $teams, string $ownTeam = ''): string
    {
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

    protected function orgaControls(helper_plugin_wikilan $wl, helper_plugin_wikilan_tourney $th, array $t): string
    {
        $tid = (int)$t['id'];
        $html = '<div class="wl-t-controls">';
        if ($t['state'] !== 'done') {
            if ((int)$t['round'] <= 1) {
                $html .= '<button class="wl-t-seed">' . hsc($th->getLang(
                    (int)$t['round'] === 0 ? 't_seed' : 't_reseed'
                )) . '</button>';
            }
            if ($t['state'] === 'running') {
                $groups = $th->groups($tid, (int)$t['round']);
                if ($t['mode'] === 'ffa' && count($groups) === 1) {
                    $html .= '<button class="wl-t-finish">' . hsc($th->getLang('t_finish_btn')) . '</button>';
                } else {
                    $html .= '<button class="wl-t-advance">' . hsc($th->getLang('t_advance_btn')) . '</button>';
                }
            }
        }
        $html .= '<span class="wl-t-orgactl"><input class="wl-t-orgauser" placeholder="'
            . hsc($th->getLang('t_orga_ph')) . '">'
            . '<button class="wl-t-orgaadd">+</button></span>';
        $html .= '<button class="wl-t-delete">' . hsc($th->getLang('t_delete')) . '</button>';
        return $html . '</div>';
    }
}
