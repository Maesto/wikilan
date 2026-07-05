<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * ~~LAN:notify~~ — optional embedded, expanded notice feed (e.g. for a
 * room-display page). The default channel is the site-wide injected widget.
 */
class syntax_plugin_wikilan_notify extends SyntaxPlugin
{
    public function getType()  { return 'substition'; }
    public function getPType() { return 'block'; }
    public function getSort()  { return 155; }

    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~LAN:notify\b[^~]*~~', $mode, 'plugin_wikilan_notify');
    }

    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        return wikilan_parse_params($match);
    }

    public function render($format, Doku_Renderer $renderer, $data)
    {
        if ($format !== 'xhtml') return false;
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        $renderer->nocache();

        $limit = isset($data['limit']) ? (int)$data['limit'] : 20;
        $notices = $wl->noticesFor($wl->user(), 0, $limit);

        $renderer->doc .= '<div class="wl-notify-feed">';
        $renderer->doc .= '<h3>' . hsc($wl->getLang('notices_title')) . '</h3>';
        if (!$notices) {
            $renderer->doc .= '<p class="wl-empty">&mdash;</p>';
        } else {
            $renderer->doc .= '<ul class="wl-notices">';
            foreach ($notices as $n) {
                $renderer->doc .= '<li class="wl-notice wl-notice-' . hsc($n['kind']) . '">'
                    . '<span class="wl-notice-time">' . dformat($n['ts'], '%H:%M') . '</span> '
                    . '<strong>' . hsc($n['title']) . '</strong>'
                    . ($n['body'] !== '' && $n['body'] !== null
                        ? ' — ' . hsc($n['body']) : '')
                    . ($n['author'] ? ' <span class="wl-notice-author">('
                        . hsc($wl->userName($n['author'])) . ')</span>' : '')
                    . '</li>';
            }
            $renderer->doc .= '</ul>';
        }
        $renderer->doc .= '</div>';
        return true;
    }
}
