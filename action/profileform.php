<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

/**
 * Adds the Steam link/unlink control to DokuWiki's own profile form
 * (?do=profile), next to the ~~LAN:profile~~ widget on user pages.
 */
class action_plugin_wikilan_profileform extends ActionPlugin
{
    public function register(EventHandler $controller)
    {
        $controller->register_hook('FORM_UPDATEPROFILE_OUTPUT', 'BEFORE', $this, 'addSteamField');
    }

    public function addSteamField(Event $event, $param)
    {
        global $ID;
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        $user = $wl->user();
        if ($user === '') return;

        $steamid = $wl->steamLink($user);
        if ($steamid) {
            $html = '<p><a href="https://steamcommunity.com/profiles/' . hsc($steamid)
                . '" target="_blank" rel="noopener">' . hsc($wl->getLang('steam_linked_as'))
                . '</a> (' . hsc($steamid) . ') &mdash; <a href="'
                . wl($ID, ['do' => 'wikilan_steamunlink', 'sectok' => getSecurityToken()], false, '&amp;')
                . '">' . hsc($wl->getLang('steam_unlink')) . '</a></p>';
        } else {
            $html = '<p><a class="button" href="'
                . wl($ID, ['do' => 'wikilan_steamlink'], false, '&amp;') . '">'
                . hsc($wl->getLang('steam_link')) . '</a></p>';
        }

        /** @var dokuwiki\Form\Form $form */
        $form = $event->data;
        $pos = $form->elementCount();
        $form->addFieldsetOpen($wl->getLang('steam_fieldset'), $pos)->addClass('wl-steam-fieldset');
        $form->addHTML($html, $pos + 1);
        $form->addFieldsetClose($pos + 2);
    }
}
