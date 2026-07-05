<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

/**
 * AJAX endpoints (call=plugin_wikilan&fn=...). All state-changing calls require
 * a valid security token; role checks happen per endpoint.
 */
class action_plugin_wikilan_ajax extends ActionPlugin
{
    /** @var helper_plugin_wikilan */
    protected $wl;

    public function register(EventHandler $controller)
    {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjax');
    }

    public function handleAjax(Event $event, $param)
    {
        if ($event->data !== 'plugin_wikilan') return;
        $event->preventDefault();
        $event->stopPropagation();

        global $INPUT;
        $this->wl = plugin_load('helper', 'wikilan');
        $fn = $INPUT->str('fn');

        header('Content-Type: application/json; charset=utf-8');
        try {
            $out = $this->dispatch($fn);
        } catch (\Throwable $e) {
            http_status(500);
            $out = ['error' => $this->wl->getLang('error')];
        }
        echo json_encode($out);
    }

    protected function dispatch(string $fn): array
    {
        global $INPUT;
        $wl = $this->wl;
        $user = $wl->user();

        // read-only endpoints
        switch ($fn) {
            case 'notices':
                // keep presence fresh while people idle on any page
                if ($user !== '') {
                    /** @var action_plugin_wikilan_arrival $arr */
                    $arr = plugin_load('action', 'wikilan_arrival');
                    if ($arr) $arr->detect(false);
                }
                $rows = $wl->noticesFor($user, $INPUT->int('since'));
                return ['notices' => array_reverse($rows)];

            case 'seat_states':
                $lan = $this->requireLan();
                $seats = $wl->seats((int)$lan['id']);
                $profiles = $wl->steamProfiles(array_values(array_filter(array_column($seats, 'user'))));
                $states = [];
                foreach ($seats as $s) {
                    $states[$s['seat_id']] = [
                        'state' => $s['state'],
                        'user' => $s['user'],
                        'username' => $s['user'] ? $wl->userName($s['user']) : null,
                        'avatar' => $s['user'] ? ($profiles[$s['user']]['avatar'] ?? null) : null,
                        'profile' => $s['user'] ? wl($wl->profilePage($s['user'])) : null,
                        'admin_only' => (int)$s['admin_only'],
                    ];
                }
                return ['live' => $wl->isLive($lan), 'seats' => $states];

            case 'push_pubkey':
                /** @var helper_plugin_wikilan_push $push */
                $push = plugin_load('helper', 'wikilan_push');
                return ['key' => $push->publicKey()];

            case 'sharedgames':
                $users = array_filter(explode(',', $INPUT->str('users')));
                /** @var helper_plugin_wikilan_steam $steam */
                $steam = plugin_load('helper', 'wikilan_steam');
                $games = $steam->sharedGames(
                    $users,
                    $INPUT->bool('mponly'),
                    max(0, $INPUT->int('minplayers'))
                );
                return ['games' => $games];

            case 'attendee_options':
                // linked attendees of the context LAN, for the shared-games picker
                $lan = $this->requireLan();
                $att = array_column($wl->attendees((int)$lan['id']), 'user');
                $linked = $wl->steamLinks($att);
                $out = [];
                foreach ($linked as $u => $sid) {
                    $out[] = ['user' => $u, 'name' => $wl->userName($u)];
                }
                return ['users' => $out];
        }

        // everything below mutates state
        if ($user === '') {
            http_status(403);
            return ['error' => $wl->getLang('login_required')];
        }
        if (!checkSecurityToken()) {
            http_status(403);
            return ['error' => 'bad token'];
        }

        switch ($fn) {
            case 'attend': {
                $lan = $this->requireLan(true);
                $wl->setAttending((int)$lan['id'], $user, $INPUT->bool('attending'));
                return ['ok' => true, 'attending' => $INPUT->bool('attending')];
            }

            case 'signup': {
                $pid = $wl->neutralId($INPUT->str('event'));
                $state = $INPUT->str('state');

                // signup cutoff (food orders): no state changes after it,
                // in either direction — organizers can still fix things
                if (!$wl->isMod()) {
                    $local = $wl->localPage($pid);
                    $data = $local ? ($wl->structData($local) ?? []) : [];
                    $lan = $wl->contextLan([], $local ?: $pid);
                    $cutoff = $lan
                        ? $wl->eventCutoff($data, $wl->lanDates($lan)['start'])
                        : null;
                    if ($cutoff !== null && time() > $cutoff) {
                        return ['error' => $wl->getLang('signup_closed_err')];
                    }
                }

                $wl->setSignup($pid, $user, $state, $INPUT->str('comment'));
                $s = $wl->signups($pid);
                return [
                    'ok' => true,
                    'state' => $wl->signupState($pid, $user),
                    'signedup' => count($s['signedup']),
                    'interested' => count($s['interested']),
                ];
            }

            case 'signup_paid': {
                if (!$wl->isMod()) {
                    http_status(403);
                    return ['error' => 'forbidden'];
                }
                $pid = $wl->neutralId($INPUT->str('event'));
                $who = trim($INPUT->str('user'));
                $paid = $INPUT->bool('paid');
                if ($who === '') return ['error' => 'missing user'];
                $wl->setSignupPaid($pid, $who, $paid, 'manual:' . $user);
                return ['ok' => true, 'paid' => $paid];
            }

            case 'seat_reserve': {
                $lan = $this->requireLan(true);
                $seat = $INPUT->str('seat');
                $move = $INPUT->bool('move');
                // occupied seats are off limits for regular users (mods move
                // people via the admin page) — reject before offering a move
                $cur = $wl->seatState((int)$lan['id'], $seat);
                if ($cur && $cur['user'] !== $user) {
                    return ['error' => sprintf(
                        $wl->getLang('seat_taken'),
                        $seat,
                        $wl->userName($cur['user'])
                    )];
                }
                if (!$move) {
                    // already holding another seat (reserved or arrived)?
                    // then don't touch anything — ask the user first
                    foreach ($wl->seatsOfUser((int)$lan['id'], $user) as $r) {
                        if ($r['seat_id'] === $seat) continue;
                        $state = $wl->getLang('seat_' . str_replace('-', '_', $r['state']))
                            ?: $r['state'];
                        return ['confirm' => sprintf(
                            $wl->getLang('seat_move_confirm'),
                            $r['seat_id'],
                            $state,
                            $seat
                        )];
                    }
                }
                $err = $wl->reserveSeat((int)$lan['id'], $seat, $user, false, $move);
                return $err ? ['error' => $err] : ['ok' => true];
            }

            case 'seat_release': {
                $lan = $this->requireLan(true);
                $err = $wl->releaseSeat((int)$lan['id'], $INPUT->str('seat'), $user);
                return $err ? ['error' => $err] : ['ok' => true];
            }

            case 'seat_move': {
                // user-confirmed seat change from the mismatch banner: the
                // target is re-resolved from the client IP, never taken from
                // the request, so only the physically detected seat is claimable
                $lan = $this->requireLan(true);
                $map = $wl->seatForIp((int)$lan['id'], $wl->clientIp());
                if (!$map) return ['error' => 'no seat detected for your address'];
                $info = $wl->confirmSeatMove((int)$lan['id'], $user, $map['seat_id']);
                if (!$info['claimed']) return ['error' => 'seat not claimable'];
                return ['ok' => true];
            }

            case 'push_subscribe': {
                $endpoint = $INPUT->str('endpoint');
                $p256dh = $INPUT->str('p256dh');
                $auth = $INPUT->str('auth');
                if (!$endpoint || !$p256dh || !$auth) return ['error' => 'missing fields'];
                $wl->getDB()->exec(
                    "REPLACE INTO push_subscriptions (user, endpoint, p256dh, auth, ua, ts)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    $user,
                    $endpoint,
                    $p256dh,
                    $auth,
                    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
                    time()
                );
                return ['ok' => true];
            }

            case 'push_unsubscribe': {
                $wl->getDB()->exec(
                    "DELETE FROM push_subscriptions WHERE user = ? AND endpoint = ?",
                    $user,
                    $INPUT->str('endpoint')
                );
                return ['ok' => true];
            }

            case 'broadcast': {
                if (!$wl->isMod()) {
                    http_status(403);
                    return ['error' => 'forbidden'];
                }
                $title = trim($INPUT->str('title'));
                if ($title === '') return ['error' => 'empty'];
                $body = trim($INPUT->str('body'));
                $lan = $wl->activeLan();
                $wl->addNotice(
                    $lan ? (int)$lan['id'] : null,
                    null,
                    'broadcast',
                    $title,
                    $body,
                    '',
                    $user,
                    time() + (int)$this->getConf('notice_ttl')
                );
                $n = $wl->queuePushBroadcast(null, ['title' => $title, 'body' => $body]);
                $sent = $this->flushNow();
                return ['ok' => true, 'push_queued' => $n, 'push_sent' => $sent];
            }
        }

        http_status(404);
        return ['error' => 'unknown fn'];
    }

    /**
     * Deliver pending pushes right away — manual announcements must not wait
     * for the next cron tick. Failures are left in the outbox for cron.
     */
    protected function flushNow(): int
    {
        try {
            return plugin_load('helper', 'wikilan_notify')->flushOutbox();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Context LAN via lan= param or the active one; optionally must be live */
    protected function requireLan(bool $mustBeLive = false): array
    {
        global $INPUT;
        $lan = $this->wl->contextLan(['lan' => $INPUT->str('lan')]);
        if (!$lan || ($mustBeLive && !$this->wl->isLive($lan))) {
            throw new \RuntimeException('no active lan');
        }
        return $lan;
    }
}
