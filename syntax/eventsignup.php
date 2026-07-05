<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * ~~LAN:eventsignup~~ — Interested / Signed-up buttons + counts for an event page.
 * Signups are keyed by the language-neutral page id: signing up on either
 * translation counts once (§3.3).
 */
class syntax_plugin_wikilan_eventsignup extends SyntaxPlugin
{
    public function getType()  { return 'substition'; }
    public function getPType() { return 'block'; }
    public function getSort()  { return 155; }

    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~LAN:eventsignup\b[^~]*~~', $mode, 'plugin_wikilan_eventsignup');
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

        $pid = $wl->neutralId(!empty($data['event']) ? $data['event'] : $ID);
        $lan = $wl->contextLan($data, $ID);
        $live = $lan && $wl->isLive($lan);
        $user = $wl->user();
        $isMod = $wl->isMod();
        $lang = $wl->pageLang($ID);

        $eventData = $wl->structData($wl->localPage($pid) ?: $ID) ?? [];
        $lanStart = $lan ? $wl->lanDates($lan)['start'] : null;
        $cents = $wl->priceCents($eventData);
        $cutoff = $wl->eventCutoff($eventData, $lanStart);
        $closed = $cutoff !== null && time() > $cutoff;

        $rows = $wl->signupRows($pid);
        $byState = ['signedup' => [], 'interested' => []];
        foreach ($rows as $r) {
            if (isset($byState[$r['state']])) $byState[$r['state']][] = $r;
        }
        $mine = null;
        foreach ($rows as $r) {
            if ($r['user'] === $user) $mine = $r;
        }
        $mineState = $mine['state'] ?? 'none';

        $renderer->doc .= '<div class="wl-signup" data-event="' . hsc($pid) . '">';
        $renderer->doc .= '<p class="wl-signup-counts">' . hsc(sprintf(
            $wl->getLang('signup_counts'),
            count($byState['signedup']),
            count($byState['interested'])
        )) . '</p>';

        // price + how to pay (strichliste product, else comment code)
        $productId = (int)trim((string)($eventData['productid'] ?? ''));
        if ($cents > 0 || $productId > 0) {
            $price = $cents > 0 ? $this->euro($cents, $lang) : '';
            /** @var helper_plugin_wikilan_strichliste $sl */
            $sl = plugin_load('helper', 'wikilan_strichliste');
            $url = trim((string)$this->getConf('strichliste_url'));
            $link = $url !== ''
                ? '<a href="' . hsc($url) . '" target="_blank" rel="noopener">Strichliste</a>'
                : 'Strichliste';
            $productName = $productId > 0 ? $sl->articleName($productId) : null;
            if ($productName !== null) {
                $hint = sprintf(
                    $wl->getLang('event_pay_product'),
                    $link,
                    '<code>' . hsc($productName) . '</code>'
                );
            } else {
                $hint = sprintf(
                    $wl->getLang('event_pay_hint'),
                    $link,
                    '<code>' . hsc($sl->paymentCode($pid)) . '</code>',
                    hsc($price)
                );
            }
            $renderer->doc .= '<p class="wl-signup-price">'
                . ($price !== '' ? hsc(sprintf($wl->getLang('event_price'), $price)) . ' — ' : '')
                . $hint . '</p>';
        }

        // signup cutoff
        if ($cutoff !== null) {
            $renderer->doc .= '<p class="wl-signup-cutoff' . ($closed ? ' wl-closed' : '') . '">'
                . hsc(sprintf(
                    $wl->getLang($closed ? 'signup_closed' : 'signup_until'),
                    $wl->formatWhen($cutoff, $lang)
                )) . '</p>';
        }

        if ($live && $user !== '' && (!$closed || $isMod)) {
            foreach (['signedup', 'interested', 'none'] as $state) {
                $label = $wl->getLang('signup_' . ($state === 'none' ? 'none' : $state));
                $renderer->doc .= '<button class="wl-signup-btn' . ($mineState === $state ? ' wl-active' : '')
                    . '" data-state="' . $state . '">' . hsc($label) . '</button>';
            }
            $renderer->doc .= '<input class="wl-signup-comment" maxlength="140" placeholder="'
                . hsc($wl->getLang('signup_comment_ph')) . '" value="'
                . hsc($mine['comment'] ?? '') . '">';
        }

        $users = array_map(static fn($r) => $r['user'], $rows);
        $profiles = $wl->steamProfiles($users);
        foreach (['signedup', 'interested'] as $state) {
            if (!$byState[$state]) continue;
            $renderer->doc .= '<div class="wl-signup-list"><strong>'
                . hsc($wl->getLang('signup_' . $state)) . ':</strong><ul class="wl-userlist">';
            foreach ($byState[$state] as $r) {
                $u = $r['user'];
                $renderer->doc .= '<li class="wl-user-row">'
                    . $wl->userChip($u, $profiles[$u] ?? null, (string)$r['comment']);
                // payment state only matters for priced events + firm signups
                if ($cents > 0 && $state === 'signedup') {
                    $renderer->doc .= '<span class="wl-paid-tag'
                        . ($r['paid'] ? ' wl-paid' : ' wl-unpaid') . '"'
                        . ($r['paid_ref'] ? ' title="' . hsc($r['paid_ref']) . '"' : '') . '>'
                        . hsc($wl->getLang($r['paid'] ? 'paid' : 'unpaid')) . '</span>';
                    if ($isMod && $live) {
                        $renderer->doc .= '<button class="wl-paid-toggle" data-user="' . hsc($u)
                            . '" data-paid="' . ($r['paid'] ? 0 : 1) . '">'
                            . hsc($wl->getLang($r['paid'] ? 'mark_unpaid' : 'mark_paid'))
                            . '</button>';
                    }
                }
                $renderer->doc .= '</li>';
            }
            $renderer->doc .= '</ul></div>';
        }
        $renderer->doc .= '</div>';
        return true;
    }

    /** cents → localized euro string */
    protected function euro(int $cents, string $lang): string
    {
        $s = number_format($cents / 100, 2, $lang === 'de' ? ',' : '.', '');
        return "$s €";
    }
}
