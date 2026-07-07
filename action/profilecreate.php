<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

/**
 * No-markup profile page creation: when a logged-in user views their own,
 * not-yet-existing profile page (…:msl:users:<login>) — or the bare
 * skeleton auto-created on LAN signup (helper::ensureProfilePages) — a
 * plain HTML form (about me / clan / games / hardware) replaces the
 * "create this page" dead end. Submitting builds the wiki text (heading +
 * ~~LAN:profile~~ + one section per filled field) for EVERY language
 * variant (localized section headings) and saves them — the pages stay
 * normally editable afterwards for people who do know their way around.
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

    /** page content is (still) just the auto-created heading + profile card */
    protected function isSkeleton(string $id): bool
    {
        if (!page_exists($id)) return true;
        return (bool)preg_match(
            '/^====== .+ ======\s*~~LAN:profile~~$/s',
            trim(rawWiki($id))
        );
    }

    public function showForm(Event $event, $param)
    {
        global $ID, $INFO;
        if ($event->data !== 'show') return;
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        if (!$this->ownProfilePage($wl, $ID)) return;
        if (!$this->isSkeleton($ID)) return;
        if (auth_quickaclcheck($ID) < (empty($INFO['exists']) ? AUTH_CREATE : AUTH_EDIT)) return;

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
            || !$this->isSkeleton($ID)
            || auth_quickaclcheck($ID) < (page_exists($ID) ? AUTH_EDIT : AUTH_CREATE)
            || !checkSecurityToken()
        ) {
            $event->data = 'show';
            return;
        }

        // one page per language, section headings localized; the free-text
        // content itself is written once and shared between the variants
        /** @var helper_plugin_wikilan_lobby $lb */
        $lb = plugin_load('helper', 'wikilan_lobby');
        $neutral = $wl->neutralId($ID);
        foreach ($wl->languages() ?: [''] as $lang) {
            $L = $lb->langFor($lang ?: 'en');
            $text = '====== ' . $wl->userName($wl->user()) . " ======\n\n~~LAN:profile~~\n";
            foreach (['about', 'clan', 'games', 'hardware'] as $key) {
                $v = trim($INPUT->str('wl_' . $key));
                if ($v === '') continue;
                $text .= "\n===== " . $L['profile_f_' . $key] . " =====\n\n$v\n";
            }
            $pid = $wl->localId($neutral, $lang);
            // only fill variants that are still untouched skeletons
            if ($pid !== $ID && !$this->isSkeleton($pid)) continue;
            saveWikiText($pid, $text, $wl->getLang('profile_create_summary'));
        }
        send_redirect(wl($ID, [], true, '&'));
    }
}
