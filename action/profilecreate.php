<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

/**
 * No-markup profile page creation: when a logged-in user views their own,
 * not-yet-existing profile page (…:msl:users:<login>), a plain HTML form
 * (about me / clan / games / hardware) replaces the "create this page" dead
 * end. Submitting builds the wiki text (heading + ~~LAN:profile~~ + one
 * section per filled field) and saves it — the page stays normally editable
 * afterwards for people who do know their way around.
 */
class action_plugin_wikilan_profilecreate extends ActionPlugin
{
    public function register(EventHandler $controller)
    {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handleCreate');
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, 'showForm');
    }

    /** The viewed page is the current user's own profile page */
    protected function ownProfilePage(helper_plugin_wikilan $wl, string $id): bool
    {
        $user = $wl->user();
        if ($user === '') return false;
        return $wl->neutralId($id) === cleanID('msl:users:' . $user);
    }

    public function showForm(Event $event, $param)
    {
        global $ID, $INFO;
        if ($event->data !== 'show' || !empty($INFO['exists'])) return;
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        if (!$this->ownProfilePage($wl, $ID)) return;
        if (auth_quickaclcheck($ID) < AUTH_CREATE) return;

        $fields = [
            ['about', 'textarea'],
            ['clan', 'input'],
            ['games', 'input'],
            ['hardware', 'textarea'],
        ];
        echo '<div class="wl-profile-create">';
        echo '<h3>' . hsc($wl->getLang('profile_create_title')) . '</h3>';
        echo '<p>' . hsc($wl->getLang('profile_create_intro')) . '</p>';
        echo '<form method="post" action="' . wl($ID) . '">'
            . '<input type="hidden" name="do" value="wikilan_mkprofile">'
            . '<input type="hidden" name="sectok" value="' . getSecurityToken() . '">';
        foreach ($fields as [$key, $kind]) {
            $label = $wl->getLang('profile_f_' . $key);
            echo '<label>' . hsc($label);
            if ($kind === 'textarea') {
                echo '<textarea name="wl_' . $key . '" rows="4"></textarea>';
            } else {
                echo '<input name="wl_' . $key . '" maxlength="120">';
            }
            echo '</label>';
        }
        echo '<button type="submit">' . hsc($wl->getLang('profile_create_btn')) . '</button>';
        echo '</form></div>';
    }

    public function handleCreate(Event $event, $param)
    {
        global $ID, $INPUT;
        if ($event->data !== 'wikilan_mkprofile') return;
        $event->preventDefault();

        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        if (
            !$this->ownProfilePage($wl, $ID)
            || page_exists($ID)
            || auth_quickaclcheck($ID) < AUTH_CREATE
            || !checkSecurityToken()
        ) {
            $event->data = 'show';
            return;
        }

        $text = '====== ' . $wl->userName($wl->user()) . " ======\n\n~~LAN:profile~~\n";
        foreach (['about', 'clan', 'games', 'hardware'] as $key) {
            $v = trim($INPUT->str('wl_' . $key));
            if ($v === '') continue;
            $text .= "\n===== " . $wl->getLang('profile_f_' . $key) . " =====\n\n$v\n";
        }
        saveWikiText($ID, $text, $wl->getLang('profile_create_summary'));
        send_redirect(wl($ID, [], true, '&'));
    }
}
