<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

/**
 * Discord OAuth2 link/unlink flow, mirroring the Steam one.
 *
 * do=wikilan_discordlink     → redirect to Discord authorize (identify scope)
 * do=wikilan_discordcb       → exchange code, store id/name/avatar
 * do=wikilan_discordunlink   → remove the link (sectok-protected)
 * do=wikilan_discordnotify   → toggle DM delivery of notifications (sectok)
 *
 * The OAuth redirect target is fixed (doku.php?do=wikilan_discordcb) and
 * must be registered in the Discord application; the CSRF state parameter
 * is the DokuWiki security token.
 */
class action_plugin_wikilan_discordauth extends ActionPlugin
{
    public function register(EventHandler $controller)
    {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handleAct');
    }

    public function handleAct(Event $event, $param)
    {
        $act = is_array($event->data) ? key($event->data) : $event->data;
        $acts = ['wikilan_discordlink', 'wikilan_discordcb',
                 'wikilan_discordunlink', 'wikilan_discordnotify'];
        if (!in_array($act, $acts)) return;
        $event->preventDefault();
        $event->stopPropagation();

        global $ID, $INPUT;
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        /** @var helper_plugin_wikilan_discord $dc */
        $dc = plugin_load('helper', 'wikilan_discord');
        $user = $wl->user();

        if ($user === '') {
            msg($wl->getLang('login_required'), -1);
            $event->data = 'show';
            return;
        }

        switch ($act) {
            case 'wikilan_discordlink':
                if (!$dc->configured()) {
                    msg($wl->getLang('error'), -1);
                    break;
                }
                send_redirect($dc->authorizeUrl(getSecurityToken()));
                return; // unreachable

            case 'wikilan_discordcb':
                $state = $INPUT->str('state');
                $code = $INPUT->str('code');
                if ($code === '' || !hash_equals(getSecurityToken(), $state)) {
                    msg($wl->getLang('error'), -1);
                    break;
                }
                $me = $dc->identify($code);
                if ($me) {
                    $dc->set($user, $me);
                    msg(sprintf($wl->getLang('discord_linked_as'), $dc->displayName($dc->link($user))), 1);
                } else {
                    msg($wl->getLang('error'), -1);
                }
                break;

            case 'wikilan_discordunlink':
                if (checkSecurityToken()) {
                    $dc->set($user, null);
                    msg($wl->getLang('discord_unlink'), 1);
                }
                break;

            case 'wikilan_discordnotify':
                if (checkSecurityToken() && $dc->link($user)) {
                    $on = $INPUT->bool('on');
                    $dc->setNotify($user, $on);
                    msg($wl->getLang($on ? 'discord_notify_on_msg' : 'discord_notify_off_msg'), 1);
                }
                break;
        }
        // land on the combined accounts page, which shows the new state
        send_redirect(wl($ID, ['do' => 'wikilan_accounts'], true, '&'));
    }

}
