<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * ~~LAN:calendar~~ — Fahrplan-style event calendar for a LAN edition:
 * day columns of time-sorted event blocks, from struct data on the edition's
 * event pages, merged across translations by neutral id.
 */
class syntax_plugin_wikilan_calendar extends SyntaxPlugin
{
    public function getType()  { return 'substition'; }
    public function getPType() { return 'block'; }
    public function getSort()  { return 155; }

    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~LAN:calendar\b[^~]*~~', $mode, 'plugin_wikilan_calendar');
    }

    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        return wikilan_parse_params($match);
    }

    public function render($format, Doku_Renderer $renderer, $data)
    {
        if ($format !== 'xhtml') return false;
        global $ID, $conf;
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        $lan = $wl->contextLan($data, $ID);
        if (!$lan) {
            $renderer->doc .= '<p class="wl-empty">' . hsc($wl->getLang('no_active_lan')) . '</p>';
            return true;
        }
        if ($wl->isLive($lan)) $renderer->nocache();

        $events = $wl->events($lan);
        if (!$events) {
            $renderer->doc .= '<p class="wl-empty">' . hsc($wl->getLang('cal_no_events')) . '</p>';
            $renderer->doc .= $this->newEventForm($wl, $lan, $renderer);
            return true;
        }

        // group by day; undated events land in a '' bucket at the end
        $days = [];
        foreach ($events as $neutral => $ev) {
            $day = $ev['start_ts'] ? date('Y-m-d', $ev['start_ts']) : '';
            $days[$day][$neutral] = $ev;
        }
        ksort($days);
        if (isset($days[''])) {
            $undated = $days[''];
            unset($days['']);
            $days[''] = $undated;
        }

        $now = time();
        $lanStart = $wl->lanDates($lan)['start'];
        $renderer->doc .= '<div class="wl-calendar">';
        foreach ($days as $day => $dayEvents) {
            $head = $day ? hsc(dformat(strtotime($day), '%A %d.%m.')) : '&hellip;';
            if ($day && $lanStart) {
                $head = hsc(sprintf($wl->getLang('cal_day'), $wl->dayNumber(strtotime($day), $lanStart)))
                    . ' · ' . $head;
            }
            $renderer->doc .= '<div class="wl-cal-day">';
            $renderer->doc .= '<h3 class="wl-cal-dayhead">' . $head . '</h3>';
            foreach ($dayEvents as $neutral => $ev) {
                $d = $ev['data'] ?? [];
                $running = $ev['start_ts'] && $ev['end_ts']
                    && $now >= $ev['start_ts'] && $now <= $ev['end_ts'];
                $cls = 'wl-cal-event';
                if ($running) $cls .= ' wl-cal-running';
                if (!empty($d['category'])) {
                    $cls .= ' wl-cat-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($d['category']));
                }
                $signups = $wl->signups($neutral);

                $renderer->doc .= '<div class="' . $cls . '">';
                if ($ev['start_ts']) {
                    $renderer->doc .= '<span class="wl-cal-time">' . date('H:i', $ev['start_ts']);
                    if ($ev['end_ts'] && $ev['end_ts'] > $ev['start_ts']) {
                        $renderer->doc .= '–' . date('H:i', $ev['end_ts']);
                    }
                    $renderer->doc .= '</span>';
                }
                $renderer->doc .= '<span class="wl-cal-title">'
                    . ($ev['page']
                        ? html_wikilink(':' . $ev['page'], $ev['title'])
                        : hsc($ev['title']))
                    . '</span>';
                $meta = [];
                if (!empty($d['host'])) {
                    $meta[] = hsc($wl->getLang('cal_host') . ': ' . $d['host']);
                }
                $cents = $wl->priceCents($d);
                if ($cents > 0) {
                    $meta[] = hsc(number_format($cents / 100, 2, ',', '') . ' €');
                }
                $counts = count($signups['signedup']) + count($signups['interested']);
                if ($counts) {
                    $meta[] = hsc(sprintf(
                        $wl->getLang('signup_counts'),
                        count($signups['signedup']),
                        count($signups['interested'])
                    ));
                }
                if ($meta) {
                    $renderer->doc .= '<span class="wl-cal-meta">' . implode(' · ', $meta) . '</span>';
                }
                $renderer->doc .= '</div>';
            }
            $renderer->doc .= '</div>';
        }
        $renderer->doc .= '</div>';
        $renderer->doc .= $this->newEventForm($wl, $lan, $renderer);
        return true;
    }

    /**
     * Link to the edition's bureaucracy "create event" form page (seeded by
     * install/lan-create) for logged-in users on non-archived editions.
     */
    protected function newEventForm(helper_plugin_wikilan $wl, array $lan, Doku_Renderer $renderer): string
    {
        global $ID;
        if ($wl->user() === '' || $lan['state'] === 'archived') return '';
        $lang = $wl->pageLang($ID);
        $formPid = (in_array($lang, $wl->languages(), true) ? "$lang:" : '')
            . $lan['namespace'] . ':events:new';
        if (!page_exists($formPid)) return '';
        $renderer->nocache(); // visibility depends on the viewer's login

        return '<p class="wl-newevent">'
            . html_wikilink(':' . $formPid, $wl->getLang('event_new'))
            . '</p>';
    }
}
