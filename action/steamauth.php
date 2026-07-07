<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;
use dokuwiki\HTTP\DokuHTTPClient;

/**
 * Steam OpenID 2.0 "Sign in through Steam" link/unlink flow.
 *
 * do=wikilan_steamlink   → redirect to Steam OpenID
 * do=wikilan_steamcb     → verify assertion, store SteamID64
 * do=wikilan_steamunlink → remove the link (sectok-protected)
 *
 * Status display lives on the combined accounts page (action/accounts.php).
 */
class action_plugin_wikilan_steamauth extends ActionPlugin
{
    protected const OPENID_ENDPOINT = 'https://steamcommunity.com/openid/login';

    public function register(EventHandler $controller)
    {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handleAct');
    }

    public function handleAct(Event $event, $param)
    {
        $act = is_array($event->data) ? key($event->data) : $event->data;
        $acts = ['wikilan_steamlink', 'wikilan_steamcb', 'wikilan_steamunlink'];
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
        // land on the combined accounts page, which shows the new state
        send_redirect(wl($ID, ['do' => 'wikilan_accounts'], true, '&'));
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
