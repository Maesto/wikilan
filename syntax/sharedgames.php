<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * ~~LAN:sharedgames~~ — "what can we all play together": pick a subset of
 * linked attendees, intersect their owned libraries (§4.1). Client-side via
 * the sharedgames AJAX endpoint.
 */
class syntax_plugin_wikilan_sharedgames extends SyntaxPlugin
{
    public function getType()  { return 'substition'; }
    public function getPType() { return 'block'; }
    public function getSort()  { return 155; }

    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~LAN:sharedgames\b[^~]*~~', $mode, 'plugin_wikilan_sharedgames');
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
        $renderer->nocache();

        $renderer->doc .= '<div class="wl-sharedgames"'
            . ($lan ? ' data-lan="' . hsc($lan['namespace']) . '"' : '') . '>'
            . '<p>' . hsc($wl->getLang('shared_pick')) . '</p>'
            . '<div class="wl-shared-users"></div>'
            . '<label class="wl-shared-mponly"><input type="checkbox" checked> '
            . hsc($wl->getLang('shared_mponly')) . '</label> '
            . '<label class="wl-shared-minplayers">' . hsc($wl->getLang('shared_minplayers'))
            . ' <input type="number" min="0" step="1" value="0"></label> '
            . '<button class="wl-shared-go">' . hsc($wl->getLang('shared_compute')) . '</button>'
            . '<div class="wl-shared-results"></div>'
            . '</div>';
        return true;
    }
}
