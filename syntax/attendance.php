<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * ~~LAN:attendance~~ — attend-toggle + roster for a LAN edition.
 * "Attending" is manual intent; "arrived" is auto-detected presence (§4.4).
 */
class syntax_plugin_wikilan_attendance extends SyntaxPlugin
{
    public function getType()  { return 'substition'; }
    public function getPType() { return 'block'; }
    public function getSort()  { return 155; }

    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~LAN:attendance\b[^~]*~~', $mode, 'plugin_wikilan_attendance');
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
        $lan = $wl->contextLan($data, $ID);
        if (!$lan) {
            $renderer->doc .= '<p class="wl-empty">' . hsc($wl->getLang('no_active_lan')) . '</p>';
            return true;
        }
        $live = $wl->isLive($lan);
        if ($live) $renderer->nocache();

        $attendees = $wl->attendees((int)$lan['id']);
        $arrived = 0;
        foreach ($attendees as $a) {
            if ($a['seat_state'] === 'arrived') $arrived++;
        }
        $user = $wl->user();

        $renderer->doc .= '<div class="wl-attendance" data-lan="' . hsc($lan['namespace']) . '">';
        $renderer->doc .= '<p class="wl-att-count">'
            . hsc(sprintf($wl->getLang('attend_count'), count($attendees), $arrived)) . '</p>';

        if ($live && $user !== '') {
            $attending = $wl->isAttending((int)$lan['id'], $user);
            $renderer->doc .= '<button class="wl-attend-btn" data-attending="'
                . ($attending ? '1' : '0') . '">'
                . hsc($wl->getLang($attending ? 'attend_btn_undo' : 'attend_btn'))
                . '</button>';
        }

        if (!isset($data['roster']) || $data['roster']) {
            $profiles = $wl->steamProfiles(array_column($attendees, 'user'));
            $playing = $live ? $wl->nowPlaying((int)$lan['id']) : [];

            $renderer->doc .= '<ul class="wl-userlist wl-roster">';
            foreach ($attendees as $a) {
                $np = $playing[$a['user']] ?? null;
                if ($np) {
                    $state = 'ingame';
                    $status = $np['name'] ?: ('#' . $np['appid']);
                } elseif ($a['seat_state'] === 'arrived') {
                    $state = 'online';
                    $status = $wl->getLang('arrived');
                } else {
                    $state = 'offline';
                    $status = '';
                }
                $renderer->doc .= '<li class="wl-user-row'
                    . ($a['seat_state'] === 'arrived' ? ' wl-arrived' : '') . '">'
                    . $wl->userChip($a['user'], $profiles[$a['user']] ?? null, $status, $state);
                if ($a['seat_id']) {
                    $renderer->doc .= ' <span class="wl-seat-tag">'
                        . hsc(sprintf($wl->getLang('seat_tag'), $a['seat_id'])) . '</span>';
                }
                $renderer->doc .= '</li>';
            }
            $renderer->doc .= '</ul>';
        }
        $renderer->doc .= '</div>';
        return true;
    }
}
