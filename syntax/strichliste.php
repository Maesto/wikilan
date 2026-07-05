<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * ~~LAN:strichliste~~ — buy statistics from the (read-only) strichliste MySQL:
 * top articles and top buyers, for the context LAN's time window plus all-time.
 *
 * Parameters:
 *   lan=<ns>     explicit edition (default: page-derived, else active LAN)
 *   limit=10     rows per table
 *   user=<login> personal card for one user instead of the site tables
 *   alltime=0    hide the all-time tables
 *
 * Buyers whose strichliste name maps back to a wiki user render as a link to
 * their profile page; the mapping override lives on the admin page.
 */
class syntax_plugin_wikilan_strichliste extends SyntaxPlugin
{
    public function getType()  { return 'substition'; }
    public function getPType() { return 'block'; }
    public function getSort()  { return 155; }

    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~LAN:strichliste\b[^~]*~~', $mode, 'plugin_wikilan_strichliste');
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
        /** @var helper_plugin_wikilan_strichliste $sl */
        $sl = plugin_load('helper', 'wikilan_strichliste');
        $renderer->nocache();

        if (!$sl || !$sl->available()) {
            $renderer->doc .= '<p class="wl-empty">' . hsc($wl->getLang('sl_unavailable')) . '</p>';
            return true;
        }

        $lang = $wl->pageLang($ID);
        $limit = max(1, (int)($data['limit'] ?? 10));

        // personal card
        if (!empty($data['user'])) {
            $stats = $sl->userStats($wl->resolveLogin($data['user']));
            if (!$stats) {
                $renderer->doc .= '<p class="wl-empty">' . hsc($wl->getLang('sl_unavailable')) . '</p>';
                return true;
            }
            $renderer->doc .= '<div class="wl-strichliste"><p>'
                . hsc(sprintf($wl->getLang('sl_summary'), $stats['purchases'], $this->euro($stats['cents'], $lang)))
                . ' — ' . hsc($wl->getLang('sl_balance')) . ': ' . hsc($this->euro($stats['balance'], $lang))
                . '</p></div>';
            return true;
        }

        $renderer->doc .= '<div class="wl-strichliste">';

        // context LAN window (buildup → end); archived editions render frozen too
        $lan = $wl->contextLan($data, $ID);
        if ($lan) {
            $d = $wl->lanDates($lan);
            $from = $d['buildup'] ?: $d['start'];
            $to = $d['end'];
            if ($from) {
                $this->tables(
                    $renderer,
                    sprintf($wl->getLang('sl_during'), $lan['title']),
                    $sl->topArticles($from, $to ?: 0, $limit),
                    $sl->topBuyers($from, $to ?: 0, $limit),
                    $lang
                );
            }
        }

        if (!isset($data['alltime']) || $data['alltime']) {
            $this->tables(
                $renderer,
                $wl->getLang('sl_alltime'),
                $sl->topArticles(0, 0, $limit),
                $sl->topBuyers(0, 0, $limit),
                $lang
            );
        }

        $renderer->doc .= '</div>';
        return true;
    }

    protected function tables(Doku_Renderer $renderer, string $title, array $articles, array $buyers, string $lang): void
    {
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        /** @var helper_plugin_wikilan_strichliste $sl */
        $sl = plugin_load('helper', 'wikilan_strichliste');

        $renderer->doc .= '<h4>' . hsc($title) . '</h4><div class="wl-sl-tables">';

        $renderer->doc .= '<table class="inline"><thead><tr><th>'
            . hsc($wl->getLang('sl_article')) . '</th><th>' . hsc($wl->getLang('sl_qty'))
            . '</th><th>' . hsc($wl->getLang('sl_revenue')) . '</th></tr></thead><tbody>';
        foreach ($articles as $a) {
            $renderer->doc .= '<tr><td>' . hsc($a['name']) . '</td><td>' . (int)$a['qty']
                . '</td><td>' . hsc($this->euro((int)$a['cents'], $lang)) . '</td></tr>';
        }
        if (!$articles) $renderer->doc .= '<tr><td colspan="3">—</td></tr>';
        $renderer->doc .= '</tbody></table>';

        $renderer->doc .= '<table class="inline"><thead><tr><th>'
            . hsc($wl->getLang('sl_buyer')) . '</th><th>' . hsc($wl->getLang('sl_items'))
            . '</th><th>' . hsc($wl->getLang('sl_spent')) . '</th></tr></thead><tbody>';
        foreach ($buyers as $b) {
            $wiki = $sl->wikiUserForSl($b['name']);
            $who = $wiki
                ? '<a href="' . wl($wl->profilePage($wiki)) . '">' . hsc($wl->userName($wiki)) . '</a>'
                : hsc($b['name']);
            $renderer->doc .= '<tr><td>' . $who . '</td><td>' . (int)$b['items']
                . '</td><td>' . hsc($this->euro((int)$b['cents'], $lang)) . '</td></tr>';
        }
        if (!$buyers) $renderer->doc .= '<tr><td colspan="3">—</td></tr>';
        $renderer->doc .= '</tbody></table></div>';
    }

    protected function euro(int $cents, string $lang): string
    {
        return number_format($cents / 100, 2, $lang === 'de' ? ',' : '.', '') . ' €';
    }
}
