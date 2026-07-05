<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * ~~LAN:when~~ — inline edition schedule dates, localized to the page's
 * language and rendered fresh from the DB, so wiki text stays correct when
 * the schedule changes.
 *
 *   ~~LAN:when~~                    event start, full date + time
 *   ~~LAN:when buildup~~            buildup start
 *   ~~LAN:when end~~                event end / teardown start (alias: teardown)
 *   ~~LAN:when start date~~         date only
 *   ~~LAN:when buildup time~~       time only
 *   lan=<neutral ns>                edition override like every other module
 */
class syntax_plugin_wikilan_when extends SyntaxPlugin
{
    public function getType()  { return 'substition'; }
    public function getPType() { return 'normal'; }
    public function getSort()  { return 155; }

    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~LAN:when\b[^~]*~~', $mode, 'plugin_wikilan_when');
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
        $renderer->nocache(); // the schedule lives in the DB, not the page

        $what = 'start';
        foreach (['buildup', 'start', 'end', 'teardown'] as $k) {
            if (!empty($data[$k])) $what = $k === 'teardown' ? 'end' : $k;
        }
        $show = 'datetime';
        foreach (['date', 'time', 'datetime'] as $k) {
            if (!empty($data[$k])) $show = $k;
        }

        $lan = $wl->contextLan($data, $ID);
        $ts = $lan ? $wl->lanDates($lan)[$what] : null;
        if (!$ts) {
            $renderer->doc .= '<span class="wl-when wl-empty">'
                . hsc($wl->getLang('when_tba')) . '</span>';
            return true;
        }
        $renderer->doc .= '<span class="wl-when">'
            . hsc($wl->formatWhen($ts, $wl->pageLang($ID), $show))
            . '</span>';
        return true;
    }
}
