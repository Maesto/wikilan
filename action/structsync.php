<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

/**
 * Keep event struct data identical across translations.
 *
 * Struct attaches data per page, so the German variant of an event page has
 * its own (initially empty) record — editing it neither shows nor changes
 * the schedule stored on the English page. After every save of an event
 * page this hook copies its struct data to all other language variants, so
 * whichever translation gets edited, the struct form always shows the
 * current shared values and a change propagates everywhere.
 *
 * Struct persists the edit-form data in COMMON_WIKIPAGE_SAVE/AFTER and
 * registers before this plugin (alphabetical load order), so the data is
 * already in the DB when this handler fires. Mirroring uses struct's API
 * directly and does not save wiki text, hence cannot loop.
 */
class action_plugin_wikilan_structsync extends ActionPlugin
{
    public function register(EventHandler $controller)
    {
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'AFTER', $this, 'mirror');
    }

    public function mirror(Event $event, $param)
    {
        $id = (string)($event->data['id'] ?? '');
        if ($id === '' || strpos($id, ':events:') === false) return;
        $rel = substr($id, strrpos($id, ':') + 1);
        if (in_array($rel, ['start', 'new'], true)) return;
        if (!class_exists('\dokuwiki\plugin\struct\meta\AccessTable')) return;

        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        $data = $wl->structData($id);
        if ($data === null) return;

        $neutral = $wl->neutralId($id);
        foreach ($wl->languages() as $l) {
            $sibling = $wl->localId($neutral, $l);
            if ($sibling === $id || !page_exists($sibling)) continue;
            if ($wl->structData($sibling) == $data) continue;
            // struct keys data by (pid, rid, rev): bump the revision when a
            // save in the same second already claimed it
            for ($rev = time(), $tries = 0; $tries < 3; $rev++, $tries++) {
                try {
                    \dokuwiki\plugin\struct\meta\AccessTable::getPageAccess('event', $sibling, $rev)
                        ->saveData($data);
                    break;
                } catch (\PDOException $e) {
                    // revision collision — retry with the next second
                } catch (\Throwable $e) {
                    // struct hiccup — the sibling stays unsynced until the next save
                    break;
                }
            }
        }
    }
}
