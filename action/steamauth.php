<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;
use dokuwiki\HTTP\DokuHTTPClient;
use dokuwiki\Menu\Item\AbstractItem;

/**
 * Steam OpenID 2.0 "Sign in through Steam" link/unlink flow.
 *
 * do=wikilan_steam       → account page: status + link/unlink (works with any
 *                          auth backend, incl. LDAP where ?do=profile is off)
 * do=wikilan_steamlink   → redirect to Steam OpenID
 * do=wikilan_steamcb     → verify assertion, store SteamID64
 * do=wikilan_steamunlink → remove the link (sectok-protected)
 *
 * The account page is reachable via User Tools → Steam (MENU_ITEMS_ASSEMBLY).
 */
class action_plugin_wikilan_steamauth extends ActionPlugin
{
    protected const OPENID_ENDPOINT = 'https://steamcommunity.com/openid/login';

    public function register(EventHandler $controller)
    {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handleAct');
        $controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE', $this, 'renderSteamPage');
        $controller->register_hook('MENU_ITEMS_ASSEMBLY', 'AFTER', $this, 'addMenuItem');
    }

    public function handleAct(Event $event, $param)
    {
        $act = is_array($event->data) ? key($event->data) : $event->data;
        $acts = ['wikilan_steam', 'wikilan_steamlink', 'wikilan_steamcb', 'wikilan_steamunlink'];
        if (!in_array($act, $acts)) return;
        $event->preventDefault();
        $event->stopPropagation();

        global $ID, $INPUT;
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        $user = $wl->user();

        if ($user === '') {
            msg($wl->getLang('login_required'), -1);
            $event->data = 'show';
            return;
        }

        switch ($act) {
            case 'wikilan_steam':
                $event->data = $act; // rendered via TPL_ACT_UNKNOWN
                return;

            case 'wikilan_steamlink':
                $return = wl($ID, ['do' => 'wikilan_steamcb'], true, '&');
                $params = [
                    'openid.ns' => 'http://specs.openid.net/auth/2.0',
                    'openid.mode' => 'checkid_setup',
                    'openid.return_to' => $return,
                    'openid.realm' => DOKU_URL,
                    'openid.identity' => 'http://specs.openid.net/auth/2.0/identifier_select',
                    'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
                ];
                send_redirect(self::OPENID_ENDPOINT . '?' . buildURLparams($params, '&'));
                return; // unreachable, send_redirect exits

            case 'wikilan_steamcb':
                $steamid = $this->verifyAssertion();
                if ($steamid) {
                    $wl->setSteamLink($user, $steamid);
                    /** @var helper_plugin_wikilan_steam $steam */
                    $steam = plugin_load('helper', 'wikilan_steam');
                    $steam->refreshProfile($user); // snapshot persona + avatar now
                    msg($wl->getLang('steam_linked_as'), 1);
                } else {
                    msg($wl->getLang('error'), -1);
                }
                break;

            case 'wikilan_steamunlink':
                if (checkSecurityToken()) {
                    $wl->setSteamLink($user, null);
                    msg($wl->getLang('steam_unlink'), 1);
                }
                break;
        }
        // land on the account page, which shows the new state
        send_redirect(wl($ID, ['do' => 'wikilan_steam'], true, '&'));
    }

    /** The ?do=wikilan_steam account page */
    public function renderSteamPage(Event $event, $param)
    {
        if ($event->data !== 'wikilan_steam') return;
        $event->preventDefault();
        $event->stopPropagation();

        global $ID;
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        $user = $wl->user();
        if ($user === '') return;

        $profile = $wl->steamProfiles([$user])[$user] ?? null;

        echo '<div class="wl-steampage">';
        echo '<h1>' . hsc($wl->getLang('steam_fieldset')) . '</h1>';
        if ($profile) {
            echo '<p>' . $wl->userChip($user, $profile, $profile['steamid64']) . '</p>';
            echo '<p><a href="https://steamcommunity.com/profiles/' . hsc($profile['steamid64'])
                . '" target="_blank" rel="noopener">' . hsc($wl->getLang('steam_view_profile')) . '</a></p>';
            echo '<p><a class="button wl-steam-unlink" href="'
                . wl($ID, ['do' => 'wikilan_steamunlink', 'sectok' => getSecurityToken()], false, '&amp;')
                . '">' . hsc($wl->getLang('steam_unlink')) . '</a></p>';
        } else {
            echo '<p>' . hsc($wl->getLang('steam_not_linked')) . '</p>';
            echo '<p>' . hsc($wl->getLang('steam_link_why')) . '</p>';
            echo '<p><a class="button wl-steam-link" href="'
                . wl($ID, ['do' => 'wikilan_steamlink'], false, '&amp;') . '">'
                . hsc($wl->getLang('steam_link')) . '</a></p>';
        }
        echo '</div>';
    }

    /** User Tools → Steam (auth-backend independent, unlike ?do=profile) */
    public function addMenuItem(Event $event, $param)
    {
        if ($event->data['view'] !== 'user') return;
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        if ($wl->user() === '') return;
        // before the last entry (logout)
        array_splice($event->data['items'], -1, 0, [
            new wikilan_steam_menuitem($wl->getLang('steam_fieldset')),
        ]);
    }

    /**
     * Verify the OpenID positive assertion directly with Steam
     * (stateless check_authentication) and extract the SteamID64.
     */
    protected function verifyAssertion(): ?string
    {
        // PHP's $_GET mangles the dots in openid.* keys (and keys like claimed_id
        // legitimately contain underscores), so parse the raw query string instead
        $params = [];
        foreach (explode('&', (string)($_SERVER['QUERY_STRING'] ?? '')) as $pair) {
            if ($pair === '' || strpos($pair, '=') === false) continue;
            [$k, $v] = explode('=', $pair, 2);
            $k = rawurldecode($k);
            if (strpos($k, 'openid.') === 0) $params[$k] = rawurldecode($v);
        }
        if (($params['openid.mode'] ?? '') !== 'id_res') return null;

        $params['openid.mode'] = 'check_authentication';
        $http = new DokuHTTPClient();
        $http->timeout = 15;
        $resp = $http->post(self::OPENID_ENDPOINT, $params);
        if ($resp === false || strpos($resp, 'is_valid:true') === false) return null;

        $claimed = (string)($params['openid.claimed_id'] ?? '');
        if (preg_match('#^https?://steamcommunity\.com/openid/id/(\d{17})$#', $claimed, $m)) {
            return $m[1];
        }
        return null;
    }
}

/** User-menu entry pointing at the ?do=wikilan_steam account page */
class wikilan_steam_menuitem extends AbstractItem
{
    public function __construct(string $label)
    {
        parent::__construct();
        $this->label = $label;
        $this->svg = DOKU_PLUGIN . 'wikilan/images/steam.svg';
        $this->params = ['do' => 'wikilan_steam'];
    }

    public function getType()
    {
        return 'wikilan_steam';
    }
}
