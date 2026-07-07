<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;
use dokuwiki\Menu\Item\AbstractItem;

/**
 * Discord OAuth2 link/unlink flow, mirroring the Steam one.
 *
 * do=wikilan_discord         → account page: status, link/unlink, DM toggle
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
        $controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE', $this, 'renderPage');
        $controller->register_hook('MENU_ITEMS_ASSEMBLY', 'AFTER', $this, 'addMenuItem');
    }

    public function handleAct(Event $event, $param)
    {
        $act = is_array($event->data) ? key($event->data) : $event->data;
        $acts = ['wikilan_discord', 'wikilan_discordlink', 'wikilan_discordcb',
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
            case 'wikilan_discord':
                $event->data = $act; // rendered via TPL_ACT_UNKNOWN
                return;

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
        // land on the account page, which shows the new state
        send_redirect(wl($ID, ['do' => 'wikilan_discord'], true, '&'));
    }

    /** The ?do=wikilan_discord account page */
    public function renderPage(Event $event, $param)
    {
        if ($event->data !== 'wikilan_discord') return;
        $event->preventDefault();
        $event->stopPropagation();

        global $ID;
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        /** @var helper_plugin_wikilan_discord $dc */
        $dc = plugin_load('helper', 'wikilan_discord');
        $user = $wl->user();
        if ($user === '') return;

        $link = $dc->link($user);

        echo '<div class="wl-discordpage">';
        echo '<h1>' . hsc($wl->getLang('discord_fieldset')) . '</h1>';
        if ($link) {
            $avatar = $dc->avatarUrl($link);
            echo '<p class="wl-discord-id">';
            if ($avatar) {
                echo '<img class="wl-user-avatar" src="' . hsc($avatar) . '" alt="" width="40" height="40"> ';
            }
            echo '<strong>' . hsc($dc->displayName($link)) . '</strong>'
                . ' <span class="wl-profile-login">(' . hsc($link['username']) . ')</span></p>';
            echo '<p><a href="https://discord.com/users/' . hsc($link['discord_id'])
                . '" target="_blank" rel="noopener">' . hsc($wl->getLang('discord_view_profile')) . '</a></p>';

            // notification channel preference
            echo '<p>' . hsc($wl->getLang('discord_notify_intro')) . '</p>';
            $on = (int)$link['notify'] === 1;
            echo '<p><strong>' . hsc($wl->getLang($on ? 'discord_notify_on' : 'discord_notify_off')) . '</strong> — ';
            echo '<a class="button" href="' . wl($ID, [
                    'do' => 'wikilan_discordnotify',
                    'on' => $on ? 0 : 1,
                    'sectok' => getSecurityToken(),
                ], false, '&amp;') . '">'
                . hsc($wl->getLang($on ? 'discord_notify_disable' : 'discord_notify_enable'))
                . '</a></p>';

            echo '<p><a class="button wl-discord-unlink" href="'
                . wl($ID, ['do' => 'wikilan_discordunlink', 'sectok' => getSecurityToken()], false, '&amp;')
                . '">' . hsc($wl->getLang('discord_unlink')) . '</a></p>';
        } else {
            echo '<p>' . hsc($wl->getLang('discord_not_linked')) . '</p>';
            echo '<p>' . hsc($wl->getLang('discord_link_why')) . '</p>';
            if ($dc->configured()) {
                echo '<p><a class="button wl-discord-link" href="'
                    . wl($ID, ['do' => 'wikilan_discordlink'], false, '&amp;') . '">'
                    . hsc($wl->getLang('discord_link')) . '</a></p>';
            }
        }
        echo '</div>';
    }

    /** User Tools → Discord */
    public function addMenuItem(Event $event, $param)
    {
        if ($event->data['view'] !== 'user') return;
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        if ($wl->user() === '') return;
        array_splice($event->data['items'], -1, 0, [
            new wikilan_discord_menuitem($wl->getLang('discord_fieldset')),
        ]);
    }
}

/** User-menu entry pointing at the ?do=wikilan_discord account page */
class wikilan_discord_menuitem extends AbstractItem
{
    public function __construct(string $label)
    {
        parent::__construct();
        $this->label = $label;
        $this->svg = DOKU_PLUGIN . 'wikilan/images/discord.svg';
        $this->params = ['do' => 'wikilan_discord'];
    }

    public function getType()
    {
        return 'wikilan_discord';
    }
}
