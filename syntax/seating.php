<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * ~~LAN:seating~~ / ~~LAN:seating lan=msl:2026_2 plan=en:msl:plan.svg show=plan~~
 *
 * Inline SVG seating plan with per-seat hotspots colored by state, plus an
 * accessible table fallback. Live LANs render fresh state and allow
 * reserve/release; archived LANs render the frozen final state.
 *
 * show=both (default) | plan (SVG + legend only) | table (table only) —
 * lets a page place plan and table side by side in its own layout boxes.
 * table=0 is kept as an alias for show=plan.
 */
class syntax_plugin_wikilan_seating extends SyntaxPlugin
{
    public function getType()  { return 'substition'; }
    public function getPType() { return 'block'; }
    public function getSort()  { return 155; }

    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~LAN:seating\b[^~]*~~', $mode, 'plugin_wikilan_seating');
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

        $seats = $wl->seats((int)$lan['id']);
        $states = [];
        foreach ($seats as $s) {
            $states[$s['seat_id']] = [
                'state' => $s['state'],
                'user' => $s['user'],
                'admin_only' => (int)$s['admin_only'],
            ];
        }

        $show = $data['show'] ?? ((isset($data['table']) && !$data['table']) ? 'plan' : 'both');

        $renderer->doc .= '<div class="wl-seating" data-lan="' . hsc($lan['namespace']) . '"'
            . ' data-live="' . ($live ? '1' : '0') . '">';

        if ($show !== 'table') {
            $svgSrc = !empty($data['plan'])
                ? (is_readable(mediaFN(cleanID($data['plan'])))
                    ? file_get_contents(mediaFN(cleanID($data['plan']))) : '')
                : $wl->planSvg($lan);
            $profiles = $wl->steamProfiles(array_values(array_filter(array_column($seats, 'user'))));
            $svg = $wl->renderSeatPlan((string)$svgSrc, $states, $wl->user(), $profiles);
            if ($svg) {
                $renderer->doc .= '<div class="wl-plan-wrap">' . $svg . '</div>';
            }

            // legend
            $renderer->doc .= '<div class="wl-legend">';
            foreach (
                [
                    'free' => 'seat_free',
                    'reserved' => 'seat_reserved',
                    'arrived' => 'seat_arrived',
                    'admin-assigned' => 'seat_admin_assigned',
                    'adminonly' => 'seat_admin_only',
                ] as $cls => $key
            ) {
                $renderer->doc .= '<span class="wl-legend-item"><i class="wl-dot wl-seat-'
                    . $cls . '"></i>' . hsc($wl->getLang($key)) . '</span>';
            }
            $renderer->doc .= '</div>';
        }

        // table view (accessibility / mobile / side-by-side layouts)
        if ($show !== 'plan') {
            $renderer->doc .= '<table class="inline wl-seat-table"><thead><tr>'
                . '<th>' . hsc($wl->getLang('seat_table_hdr')) . '</th><th></th><th></th>'
                . '</tr></thead><tbody>';
            foreach ($seats as $s) {
                $state = $s['state']
                    ?: ($s['admin_only'] ? 'adminonly' : 'free');
                $stateLabel = $s['state']
                    ? $wl->getLang('seat_' . str_replace('-', '_', $s['state']))
                    : ($s['admin_only'] ? $wl->getLang('seat_admin_only') : $wl->getLang('seat_free'));
                $renderer->doc .= '<tr data-seat="' . hsc($s['seat_id']) . '">'
                    . '<td>' . hsc($s['seat_id']) . '</td>'
                    . '<td class="wl-state wl-seat-' . hsc($state) . '">' . hsc($stateLabel) . '</td>'
                    . '<td>' . ($s['user']
                        ? '<a href="' . wl($wl->profilePage($s['user'])) . '">'
                            . hsc($wl->userName($s['user'])) . '</a>'
                        : '') . '</td>'
                    . '</tr>';
            }
            $renderer->doc .= '</tbody></table>';
        }

        $renderer->doc .= '</div>';
        return true;
    }
}

if (!function_exists('wikilan_parse_params')) {
    /** Parse "~~LAN:name key=value key=value~~" into an assoc array */
    function wikilan_parse_params(string $match): array
    {
        $inner = trim(substr($match, 2, -2)); // strip ~~ ~~
        $parts = preg_split('/\s+/', $inner);
        array_shift($parts); // LAN:name
        $params = [];
        foreach ($parts as $p) {
            if (strpos($p, '=') !== false) {
                [$k, $v] = explode('=', $p, 2);
                $params[trim($k)] = trim($v);
            } elseif ($p !== '') {
                $params[trim($p)] = true;
            }
        }
        return $params;
    }
}
