<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * ~~LAN:lobbies~~ … ~~LAN:lobbies-end~~ — managed block on event pages.
 *
 * The wiki text between the markers is GENERATED (lobby list + bracket
 * state, see helper/lobby.php::syncPages) and rendered like any other wiki
 * content — nothing on the page requires JS. The markers themselves render
 * the wrapper div (with a manage deep-link for hosts/moderators) that the
 * live-refresh script re-fills while the event's LAN is live; lobby codes
 * are filled into ~~LAN:connect~~ placeholders by the same script.
 */
class syntax_plugin_wikilan_lobbies extends SyntaxPlugin
{
    public function getType()  { return 'substition'; }
    public function getPType() { return 'block'; }
    public function getSort()  { return 155; }

    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~LAN:lobbies~~', $mode, 'plugin_wikilan_lobbies');
        $this->Lexer->addSpecialPattern('~~LAN:lobbies-end~~', $mode, 'plugin_wikilan_lobbies');
    }

    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        return ['end' => strpos($match, 'lobbies-end') !== false];
    }

    public function render($format, Doku_Renderer $renderer, $data)
    {
        if ($format !== 'xhtml') return false;
        global $ID;

        if (!empty($data['end'])) {
            $renderer->doc .= '</div></div>';
            return true;
        }

        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        /** @var helper_plugin_wikilan_lobby $lb */
        $lb = plugin_load('helper', 'wikilan_lobby');
        $renderer->nocache();

        $id = (string)$ID;
        $pid = $wl->neutralId($id);
        $lan = $wl->contextLan([], $id);
        $live = $lan && $wl->isLive($lan);
        $user = $wl->user();

        $renderer->doc .= '<div class="wl-lobbies" data-event="' . hsc($pid)
            . '" data-page="' . hsc($id) . '"'
            . ' data-hash="' . md5($lb->markup($pid, $wl->pageLang($id))) . '"'
            . ($live ? ' data-live="1"' : '') . '>';
        if ($lb->canManage($pid, $user)) {
            $renderer->doc .= '<p class="wl-manage-link"><a href="'
                . wl($id, ['do' => 'wikilan_manage', 'event' => $pid]) . '">🛠 '
                . hsc($lb->getLang('lm_manage_link')) . '</a></p>';
        }
        $renderer->doc .= '<div class="wl-lobbies-body">';
        return true;
    }
}
