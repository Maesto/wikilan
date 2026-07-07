<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * ~~LAN:profile~~ / ~~LAN:profile user=somebody~~ — Steam-style profile card
 * for the user pages under …:msl:users:<user>: avatar + persona name, live
 * status (in-game / arrived), stats (LANs, hours, games) split into the
 * current LAN and lifetime, strichliste buy stats, LANs attended.
 * Linking/unlinking happens on the Steam page (User Tools → Steam), not here.
 */
class syntax_plugin_wikilan_profile extends SyntaxPlugin
{
    public function getType()  { return 'substition'; }
    public function getPType() { return 'block'; }
    public function getSort()  { return 155; }

    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~LAN:profile\b[^~]*~~', $mode, 'plugin_wikilan_profile');
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
        $renderer->nocache();

        // subject: explicit user= param, else derived from …:users:<name> page
        // id (cleanID lowercases, so recover the real login first)
        $subject = $wl->resolveLogin(!empty($data['user']) ? $data['user'] : noNS($ID));
        $viewer = $wl->user();
        $own = $viewer !== '' && $viewer === $subject;

        $profile = $wl->steamProfiles([$subject])[$subject] ?? null;
        $steamid = $profile['steamid64'] ?? null;
        $persona = trim((string)($profile['persona'] ?? ''));

        // live context: seat + now playing decide the Steam-style presence color
        $lan = $wl->activeLan();
        $seat = $lan ? $wl->seatOfUser((int)$lan['id'], $subject) : null;
        $np = $lan ? ($wl->nowPlaying((int)$lan['id'])[$subject] ?? null) : null;

        if ($np) {
            $state = 'ingame';
            $status = $wl->getLang('status_ingame') . ' — ' . ($np['name'] ?: ('#' . $np['appid']));
        } elseif ($seat && $seat['state'] === 'arrived') {
            $state = 'online';
            $status = $wl->getLang('arrived') . ' · ' . $seat['seat_id'];
        } elseif ($steamid) {
            $state = 'offline';
            $status = '';
        } else {
            $state = 'offline';
            $status = $wl->getLang('steam_not_linked');
        }

        $lans = $wl->lansOfUser($subject);
        $lifetime = $wl->gameStats($subject);
        $totalSecs = (int)array_sum(array_column($lifetime, 'secs'));

        $renderer->doc .= '<div class="wl-profile wl-user-' . $state . '">';

        // ---------------------------------------------- Steam-style header
        $renderer->doc .= '<div class="wl-profile-head">';
        $avatar = $profile['avatar_full'] ?? ($profile['avatar'] ?? null);
        if ($avatar) {
            $renderer->doc .= '<img class="wl-profile-avatar" src="' . hsc($avatar) . '" alt="" width="96" height="96">';
        } else {
            $initial = mb_strtoupper(mb_substr($persona !== '' ? $persona : $wl->userName($subject), 0, 1));
            $renderer->doc .= '<span class="wl-profile-avatar wl-user-avatar-ph">' . hsc($initial) . '</span>';
        }
        $renderer->doc .= '<div class="wl-profile-id">';
        $renderer->doc .= '<div class="wl-profile-persona">'
            . hsc($persona !== '' ? $persona : $wl->userName($subject)) . '</div>';
        if ($persona !== '') {
            $renderer->doc .= '<div class="wl-profile-wikiname">' . hsc($wl->userName($subject))
                . ' <span class="wl-profile-login">(' . hsc($subject) . ')</span></div>';
        }
        if ($status !== '') {
            $renderer->doc .= '<div class="wl-profile-status">' . hsc($status) . '</div>';
        }
        /** @var helper_plugin_wikilan_discord $dc */
        $dc = plugin_load('helper', 'wikilan_discord');
        $discord = $dc ? $dc->link($subject) : null;

        $links = [];
        if ($steamid) {
            $links[] = '<a class="wl-steam-deeplink" href="https://steamcommunity.com/profiles/'
                . hsc($steamid) . '" target="_blank" rel="noopener">'
                . hsc($wl->getLang('steam_view_profile')) . '</a>';
            if (!$own) {
                $links[] = '<a class="wl-steam-deeplink" href="steam://friends/add/'
                    . hsc($steamid) . '">' . hsc($wl->getLang('steam_add_friend')) . '</a>';
            }
        } elseif ($own) {
            $links[] = '<a class="wl-steam-deeplink" href="'
                . wl($ID, ['do' => 'wikilan_accounts'], false, '&amp;') . '">'
                . hsc($wl->getLang('steam_link')) . '</a>';
        }
        if ($discord) {
            $label = $own
                ? $wl->getLang('discord_view_profile')
                : $wl->getLang('discord_add_friend');
            $links[] = '<a class="wl-discord-deeplink" href="https://discord.com/users/'
                . hsc($discord['discord_id']) . '" target="_blank" rel="noopener">'
                . hsc($label) . '</a>';
        } elseif ($own) {
            $links[] = '<a class="wl-discord-deeplink" href="'
                . wl($ID, ['do' => 'wikilan_accounts'], false, '&amp;') . '">'
                . hsc($wl->getLang('discord_link')) . '</a>';
        }
        if ($links) {
            $renderer->doc .= '<div class="wl-profile-links">' . implode(' · ', $links) . '</div>';
        }
        $renderer->doc .= '</div>';

        // stat tiles on the right of the header
        $renderer->doc .= '<div class="wl-profile-statbar">'
            . $this->tile(count($lans), $wl->getLang('profile_stat_lans'))
            . $this->tile($this->hours($totalSecs), $wl->getLang('profile_stat_hours'))
            . $this->tile(count($lifetime), $wl->getLang('profile_stat_games'))
            . '</div>';
        $renderer->doc .= '</div>';

        // ---------------------------------------------- body: stat columns
        $renderer->doc .= '<div class="wl-profile-body wl-profile-cols">';

        // current LAN (only while one is active and the user takes part)
        if ($lan && ($seat || $wl->isAttending((int)$lan['id'], $subject))) {
            $renderer->doc .= '<div class="wl-profile-sec"><h4>'
                . hsc(sprintf($wl->getLang('profile_this_lan'), $lan['title'])) . '</h4>';
            if ($seat) {
                $stateLabel = $wl->getLang('seat_' . str_replace('-', '_', $seat['state']));
                $renderer->doc .= '<p><strong>' . hsc($wl->getLang('profile_seat')) . ':</strong> '
                    . hsc(sprintf($wl->getLang('seat_tag'), $seat['seat_id']))
                    . ' <em>(' . hsc($stateLabel) . ')</em></p>';
            }
            $renderer->doc .= $this->gameList($wl->gameStats($subject, (int)$lan['id']), $wl);
            $renderer->doc .= '</div>';
        }

        // lifetime games
        if ($lifetime) {
            $renderer->doc .= '<div class="wl-profile-sec"><h4>'
                . hsc($wl->getLang('profile_alltime')) . '</h4>'
                . $this->gameList($lifetime, $wl)
                . '</div>';
        }

        // LANs attended
        if ($lans) {
            $renderer->doc .= '<div class="wl-profile-sec"><h4>'
                . hsc($wl->getLang('profile_lans')) . '</h4><ul>';
            foreach ($lans as $l) {
                $home = $wl->localPage($l['namespace'] . ':start') ?: $wl->localPage($l['namespace']);
                $renderer->doc .= '<li>'
                    . ($home ? html_wikilink(':' . $home, $l['title']) : hsc($l['title']))
                    . '</li>';
            }
            $renderer->doc .= '</ul></div>';
        }

        // strichliste buy stats
        /** @var helper_plugin_wikilan_strichliste $sl */
        $sl = plugin_load('helper', 'wikilan_strichliste');
        $stats = $sl ? $sl->userStats($subject) : null;
        if ($stats) {
            $renderer->doc .= '<div class="wl-profile-sec"><h4>'
                . hsc($wl->getLang('sl_title')) . '</h4>'
                . '<p>' . hsc(sprintf(
                    $wl->getLang('sl_summary'),
                    $stats['purchases'],
                    $this->euro($stats['cents'])
                )) . '</p>';
            if ($stats['top']) {
                $renderer->doc .= '<ul>';
                foreach ($stats['top'] as $t) {
                    $renderer->doc .= '<li>' . hsc($t['name']) . ' <span class="wl-hours">× '
                        . (int)$t['qty'] . '</span></li>';
                }
                $renderer->doc .= '</ul>';
            }
            $renderer->doc .= '<p class="wl-sl-balance">' . hsc($wl->getLang('sl_balance')) . ': '
                . hsc($this->euro($stats['balance'])) . '</p>';
            $renderer->doc .= '</div>';
        }

        $renderer->doc .= '</div>';

        $renderer->doc .= '</div>';
        return true;
    }

    protected function tile($value, string $label): string
    {
        return '<div class="wl-profile-tile"><span class="wl-tile-num">' . hsc((string)$value)
            . '</span><span class="wl-tile-label">' . hsc($label) . '</span></div>';
    }

    /** grouped session rows → hour list */
    protected function gameList(array $rows, helper_plugin_wikilan $wl): string
    {
        if (!$rows) {
            return '<p class="wl-empty">' . hsc($wl->getLang('profile_no_games')) . '</p>';
        }
        $html = '<ul>';
        foreach (array_slice($rows, 0, 12) as $s) {
            $html .= '<li>' . hsc($s['name'] ?: ('#' . $s['appid']))
                . ' <span class="wl-hours">' . $this->hours((int)$s['secs']) . ' h</span></li>';
        }
        $html .= '</ul>';
        return $html;
    }

    protected function hours(int $secs): string
    {
        $h = $secs / 3600;
        return $h >= 10 ? (string)round($h) : (string)round($h, 1);
    }

    protected function euro(int $cents): string
    {
        global $ID;
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        $sep = $wl->pageLang($ID) === 'de' ? ',' : '.';
        return number_format($cents / 100, 2, $sep, '') . ' €';
    }
}
