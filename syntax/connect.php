<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * ~~LAN:connect <lobbyid>~~ — per-viewer connect-info placeholder.
 *
 * Renders an EMPTY slot; script.js fills in the lobby code (with copy
 * button) and/or connect link from the lobby_connect AJAX data the viewer
 * is entitled to. Codes therefore never appear in wiki text, page history
 * or server-rendered HTML, and a changed code propagates on the next poll.
 */
class syntax_plugin_wikilan_connect extends SyntaxPlugin
{
    public function getType()  { return 'substition'; }
    public function getPType() { return 'normal'; }
    public function getSort()  { return 155; }

    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~LAN:connect\b[^~]*~~', $mode, 'plugin_wikilan_connect');
    }

    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $params = wikilan_parse_params($match);
        $id = 0;
        foreach ($params as $k => $v) {
            if ($v === true && ctype_digit((string)$k)) $id = (int)$k;
        }
        return ['id' => $id];
    }

    public function render($format, Doku_Renderer $renderer, $data)
    {
        if ($format !== 'xhtml') return false;
        if (empty($data['id'])) return true;
        /** @var helper_plugin_wikilan_lobby $lb */
        $lb = plugin_load('helper', 'wikilan_lobby');
        $renderer->nocache();
        $renderer->doc .= '<span class="wl-connect" data-lobby="' . (int)$data['id'] . '">'
            . '<code class="wl-code" hidden></code>'
            . '<button class="wl-copy" type="button" hidden title="'
            . hsc($lb->getLang('lob_copy')) . '">⧉</button>'
            . '<a class="wl-clink" hidden rel="noopener"></a>'
            . '</span>';
        return true;
    }
}
