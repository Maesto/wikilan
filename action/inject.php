<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

/**
 * Site-wide wiring: exposes plugin config to the frontend via JSINFO so the
 * notice widget + push service worker load on every page (§4.3). The widget
 * DOM itself is built client-side by script.js.
 */
class action_plugin_wikilan_inject extends ActionPlugin
{
    public function register(EventHandler $controller)
    {
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'addJsinfo');
    }

    public function addJsinfo(Event $event, $param)
    {
        global $JSINFO;
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        $user = $wl->user();

        $info = [
            'poll' => (int)$this->getConf('notice_poll'),
            'user' => $user,
            'live' => $wl->activeLan() ? 1 : 0,
            'sw' => DOKU_BASE . 'lib/plugins/wikilan/sw.js',
        ];
        if ($user !== '') {
            $info['sectok'] = getSecurityToken();
        }
        $JSINFO['wikilan'] = $info;
    }
}
