<?php

use dokuwiki\Extension\Plugin;

/**
 * Notification triggers (§4.3): signup reminders before event start,
 * event time/location changes, and delivery of the push outbox.
 * Runs from the notify-tick cron while a LAN is active.
 */
class helper_plugin_wikilan_notify extends Plugin
{
    protected ?helper_plugin_wikilan $wl = null;

    protected function wl(): helper_plugin_wikilan
    {
        if (!$this->wl) $this->wl = plugin_load('helper', 'wikilan');
        return $this->wl;
    }

    /** One cron tick: returns [reminders, changes, pushes_sent] */
    public function tick(): array
    {
        $reminders = $this->fireReminders();
        $changes = $this->fireEventChanges();
        $sent = $this->flushOutbox();
        return [$reminders, $changes, $sent];
    }

    // ---------------------------------------------------------------- reminders

    protected function fireReminders(): int
    {
        $wl = $this->wl();
        $lan = $wl->activeLan();
        if (!$lan) return 0;

        $lead = (int)$this->getConf('reminder_lead') * 60;
        $now = time();
        $fired = 0;

        foreach ($wl->events($lan) as $neutral => $ev) {
            if (!$ev['start_ts'] || $ev['start_ts'] < $now || $ev['start_ts'] > $now + $lead) {
                continue;
            }
            $title = sprintf($wl->getLang('reminder_title'), $ev['title']);
            $body = sprintf($wl->getLang('reminder_body'), $ev['title'], date('H:i', $ev['start_ts']));
            $link = $ev['page'] ? wl($ev['page'], [], true) : '';
            $signups = $wl->signups($neutral);

            foreach ($signups['signedup'] as $user) {
                $key = "rem:$neutral:$user";
                if ($wl->queuePush($user, $key, ['title' => $title, 'body' => $body, 'url' => $link])) {
                    $wl->addNotice(
                        (int)$lan['id'],
                        $user,
                        'reminder',
                        $title,
                        $body,
                        $link,
                        '',
                        $ev['start_ts'] + 3600
                    );
                    $fired++;
                }
            }
        }
        return $fired;
    }

    // ---------------------------------------------------------------- event changes

    protected function fireEventChanges(): int
    {
        $wl = $this->wl();
        $lan = $wl->activeLan();
        if (!$lan) return 0;
        $db = $wl->getDB();
        $now = time();
        $fired = 0;

        foreach ($wl->events($lan) as $neutral => $ev) {
            $d = $ev['data'] ?? [];
            $cur = [
                'start' => $ev['start_ts'] ?: 0,
                'duration' => (int)($d['duration'] ?? 0),
                'location' => (string)($d['location'] ?? ''),
                'title' => $ev['title'],
            ];
            $known = $db->queryRecord(
                "SELECT * FROM event_sched WHERE event_pid = ?",
                $neutral
            );
            $db->exec(
                "REPLACE INTO event_sched (event_pid, start, duration, location, title)
                 VALUES (?, ?, ?, ?, ?)",
                $neutral,
                $cur['start'],
                $cur['duration'],
                $cur['location'],
                $cur['title']
            );
            if (!$known) continue; // first sighting: seed silently

            $changed = (int)$known['start'] !== $cur['start']
                || (int)$known['duration'] !== $cur['duration']
                || (string)$known['location'] !== $cur['location'];
            if (!$changed || !$cur['start'] || $cur['start'] < $now) continue;

            $title = sprintf($wl->getLang('event_change_title'), $ev['title']);
            $body = date('D H:i', $cur['start'])
                . ($cur['location'] !== '' ? ' · ' . $cur['location'] : '');
            $link = $ev['page'] ? wl($ev['page'], [], true) : '';
            $hash = substr(md5($cur['start'] . '|' . $cur['duration'] . '|' . $cur['location']), 0, 8);

            $signups = $wl->signups($neutral);
            foreach (array_merge($signups['signedup'], $signups['interested']) as $user) {
                $key = "chg:$neutral:$hash:$user";
                if ($wl->queuePush($user, $key, ['title' => $title, 'body' => $body, 'url' => $link])) {
                    $wl->addNotice(
                        (int)$lan['id'],
                        $user,
                        'event_change',
                        $title,
                        $body,
                        $link,
                        '',
                        $cur['start'] + 3600
                    );
                    $fired++;
                }
            }
        }
        return $fired;
    }

    // ---------------------------------------------------------------- outbox

    public function flushOutbox(int $limit = 100): int
    {
        $wl = $this->wl();
        $db = $wl->getDB();
        /** @var helper_plugin_wikilan_push $push */
        $push = plugin_load('helper', 'wikilan_push');
        /** @var helper_plugin_wikilan_discord $discord */
        $discord = plugin_load('helper', 'wikilan_discord');

        $rows = $db->queryAll(
            "SELECT * FROM push_outbox WHERE state = 'pending' AND tries < 3 ORDER BY id LIMIT ?",
            $limit
        );
        $sent = 0;
        foreach ($rows as $row) {
            $payload = json_decode($row['payload'], true) ?: [];

            // Discord DM instead of web push when the user opted in
            $link = $discord ? $discord->link($row['user']) : null;
            if ($link && (int)$link['notify'] === 1) {
                if ($discord->sendDM($link, $discord->formatPayload($payload))) {
                    $db->exec("UPDATE push_outbox SET state = 'sent' WHERE id = ?", $row['id']);
                    $sent++;
                } else {
                    $db->exec(
                        "UPDATE push_outbox SET tries = tries + 1,
                                state = CASE WHEN tries + 1 >= 3 THEN 'failed' ELSE 'pending' END
                          WHERE id = ?",
                        $row['id']
                    );
                }
                continue;
            }

            $subs = $db->queryAll(
                "SELECT * FROM push_subscriptions WHERE user = ?",
                $row['user']
            );
            if (!$subs) {
                // no browser subscribed: nothing to deliver, close the row
                $db->exec("UPDATE push_outbox SET state = 'sent' WHERE id = ?", $row['id']);
                continue;
            }
            $allOk = true;
            foreach ($subs as $sub) {
                try {
                    $status = $push->send($sub, $payload);
                } catch (\Throwable $e) {
                    $status = 0;
                }
                if (in_array($status, [404, 410])) {
                    $db->exec("DELETE FROM push_subscriptions WHERE id = ?", $sub['id']);
                } elseif ($status < 200 || $status >= 300) {
                    $allOk = false;
                }
            }
            if ($allOk) {
                $db->exec("UPDATE push_outbox SET state = 'sent' WHERE id = ?", $row['id']);
                $sent++;
            } else {
                $db->exec(
                    "UPDATE push_outbox SET tries = tries + 1,
                            state = CASE WHEN tries + 1 >= 3 THEN 'failed' ELSE 'pending' END
                      WHERE id = ?",
                    $row['id']
                );
            }
        }
        return $sent;
    }
}
