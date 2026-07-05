<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

/**
 * Request-time arrival detection: while a LAN is active, a logged-in request
 * from a LAN-local IP that maps to a seat (deterministic IP per switch port,
 * buildup map in port_seat) marks that user as arrived at that seat.
 * Mismatches (wrong seat / conflicts / admin-only) surface as banners.
 */
class action_plugin_wikilan_arrival extends ActionPlugin
{
    public function register(EventHandler $controller)
    {
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'handleRequest');
    }

    public function handleRequest(Event $event, $param)
    {
        $this->detect(true);
    }

    /**
     * Run the IP→seat resolution. $banners=true additionally emits user-facing
     * messages (page views); the AJAX poll calls this with $banners=false just
     * to keep presence fresh.
     */
    public function detect(bool $banners): void
    {
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        $user = $wl->user();
        if ($user === '') return;
        $lan = $wl->activeLan();
        if (!$lan) return;

        $map = $wl->seatForIp((int)$lan['id'], $wl->clientIp());
        if (!$map) return;
        $seatId = $map['seat_id'];

        // fresh arrival at the same seat: skip the write, but still check mismatches
        $throttle = (int)$this->getConf('arrival_throttle');
        $cur = $wl->seatState((int)$lan['id'], $seatId);
        if (
            $cur && $cur['user'] === $user && $cur['state'] === 'arrived'
            && (time() - (int)$cur['ts']) < $throttle
        ) {
            $held = $this->heldElsewhere($wl, (int)$lan['id'], $user, $seatId);
            $info = [
                'claimed' => true,
                'conflict_user' => null,
                'mismatch_reserved' => $held ? $held['seat_id'] : null,
                'mismatch_state' => $held ? $held['state'] : null,
                'admin_only_warn' => false,
            ];
        } else {
            $info = $wl->markArrived((int)$lan['id'], $seatId, $user);
        }

        if (!$banners) return;

        if ($info['conflict_user']) {
            msg(hsc(sprintf(
                $wl->getLang('seat_taken'),
                $seatId,
                $wl->userName($info['conflict_user'])
            )), -1);
        }
        if ($info['mismatch_reserved']) {
            // held seat differs from the detected one — never moved silently;
            // the link is the user's explicit confirmation
            $key = ($info['mismatch_state'] ?? '') === 'arrived' ? 'seat_moved' : 'wrong_seat';
            msg(
                hsc(sprintf($wl->getLang($key), $info['mismatch_reserved'], $seatId))
                . ' <a href="#" class="wl-move-reservation" data-seat="' . hsc($seatId) . '">'
                . hsc($wl->getLang('wrong_seat_move')) . '</a>',
                2
            );
        }
        if ($info['admin_only_warn']) {
            msg(hsc(sprintf($wl->getLang('admin_seat_warn'), $seatId)), 2);
        }
    }

    protected function heldElsewhere(
        helper_plugin_wikilan $wl,
        int $lanId,
        string $user,
        string $seatId
    ): ?array {
        foreach ($wl->seatsOfUser($lanId, $user) as $r) {
            if ($r['seat_id'] !== $seatId) return $r;
        }
        return null;
    }
}
