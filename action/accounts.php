<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;
use dokuwiki\Menu\Item\AbstractItem;

/**
 * ?do=wikilan_accounts — all linkable external accounts on one page,
 * one card per provider (Steam, Discord, more over time). The single
 * User Tools → "Linked accounts" entry replaces the per-provider menu
 * items; the provider actions (action/steamauth.php, discordauth.php)
 * keep handling their link/unlink/callback flows and land back here.
 */
class action_plugin_wikilan_accounts extends ActionPlugin
{
    public function register(EventHandler $controller)
    {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'allowAct');
        $controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE', $this, 'output');
        $controller->register_hook('MENU_ITEMS_ASSEMBLY', 'AFTER', $this, 'addMenuItem');
    }

    public function allowAct(Event $event, $param)
    {
        if ($event->data !== 'wikilan_accounts') return;
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        if ($wl->user() === '') {
            $event->data = 'login';
            return;
        }
        $event->preventDefault();
    }

    public function output(Event $event, $param)
    {
        if ($event->data !== 'wikilan_accounts') return;
        $event->preventDefault();
        $event->stopPropagation();

        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        if ($wl->user() === '') return;

        echo '<div class="wl-accounts">';
        echo '<h1>' . hsc($wl->getLang('accounts_title')) . '</h1>';
        echo '<p>' . hsc($wl->getLang('accounts_intro')) . '</p>';
        echo '<div class="wl-accounts-grid">';
        $this->steamSection($wl);
        $this->discordSection($wl);
        echo '</div></div>';
    }

    // ---------------------------------------------------------------- Steam

    protected function steamSection(helper_plugin_wikilan $wl): void
    {
        global $ID;
        $user = $wl->user();
        $profile = $wl->steamProfiles([$user])[$user] ?? null;

        echo '<div class="wl-account-card"><h3>' . hsc($wl->getLang('steam_fieldset')) . '</h3>';
        if ($profile) {
            echo '<p>' . $wl->userChip($user, $profile, $profile['steamid64'], '', false) . '</p>';
            echo '<p><a href="https://steamcommunity.com/profiles/' . hsc($profile['steamid64'])
                . '" target="_blank" rel="noopener">' . hsc($wl->getLang('steam_view_profile')) . '</a></p>';
            echo '<p><a class="button" href="'
                . wl($ID, ['do' => 'wikilan_steamunlink', 'sectok' => getSecurityToken()], false, '&amp;')
                . '">' . hsc($wl->getLang('steam_unlink')) . '</a></p>';
        } else {
            echo '<p>' . hsc($wl->getLang('steam_not_linked')) . '</p>';
            echo '<p>' . hsc($wl->getLang('steam_link_why')) . '</p>';
            echo '<p><a class="button" href="'
                . wl($ID, ['do' => 'wikilan_steamlink'], false, '&amp;') . '">'
                . hsc($wl->getLang('steam_link')) . '</a></p>';
        }
        echo '</div>';
    }

    // ---------------------------------------------------------------- Discord

    protected function discordSection(helper_plugin_wikilan $wl): void
    {
        global $ID;
        /** @var helper_plugin_wikilan_discord $dc */
        $dc = plugin_load('helper', 'wikilan_discord');
        if (!$dc) return;
        $user = $wl->user();
        $link = $dc->link($user);

        echo '<div class="wl-account-card"><h3>' . hsc($wl->getLang('discord_fieldset')) . '</h3>';
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

            echo '<p>' . hsc($wl->getLang('discord_notify_intro')) . '</p>';
            $on = (int)$link['notify'] === 1;
            echo '<p><strong>' . hsc($wl->getLang($on ? 'discord_notify_on' : 'discord_notify_off')) . '</strong><br>';
            echo '<a class="button" href="' . wl($ID, [
                    'do' => 'wikilan_discordnotify',
                    'on' => $on ? 0 : 1,
                    'sectok' => getSecurityToken(),
                ], false, '&amp;') . '">'
                . hsc($wl->getLang($on ? 'discord_notify_disable' : 'discord_notify_enable'))
                . '</a></p>';

            echo '<p><a class="button" href="'
                . wl($ID, ['do' => 'wikilan_discordunlink', 'sectok' => getSecurityToken()], false, '&amp;')
                . '">' . hsc($wl->getLang('discord_unlink')) . '</a></p>';
        } else {
            echo '<p>' . hsc($wl->getLang('discord_not_linked')) . '</p>';
            echo '<p>' . hsc($wl->getLang('discord_link_why')) . '</p>';
            if ($dc->configured()) {
                echo '<p><a class="button" href="'
                    . wl($ID, ['do' => 'wikilan_discordlink'], false, '&amp;') . '">'
                    . hsc($wl->getLang('discord_link')) . '</a></p>';
            }
        }
        echo '</div>';
    }

    /** User Tools → Linked accounts (single entry for all providers) */
    public function addMenuItem(Event $event, $param)
    {
        if ($event->data['view'] !== 'user') return;
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        if ($wl->user() === '') return;
        array_splice($event->data['items'], -1, 0, [
            new wikilan_accounts_menuitem($wl->getLang('accounts_title')),
        ]);
    }
}

/** User-menu entry pointing at the ?do=wikilan_accounts page */
class wikilan_accounts_menuitem extends AbstractItem
{
    public function __construct(string $label)
    {
        parent::__construct();
        $this->label = $label;
        $this->svg = DOKU_PLUGIN . 'wikilan/images/accounts.svg';
        $this->params = ['do' => 'wikilan_accounts'];
    }

    public function getType()
    {
        return 'wikilan_accounts';
    }
}
