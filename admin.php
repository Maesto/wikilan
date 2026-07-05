<?php

use dokuwiki\Extension\AdminPlugin;

/**
 * Organizer tooling (§4.6): manage LAN editions & live/archived state, seats
 * (admin-only flags, assignments, overrides), the buildup port↔seat↔IP map,
 * occupancy/conflicts, and manual broadcasts.
 *
 * Admins get everything; the mod group gets seat overrides + broadcasts.
 */
class admin_plugin_wikilan extends AdminPlugin
{
    /** @var helper_plugin_wikilan */
    protected $wl;

    public function forAdminOnly()
    {
        return false; // mods (lanmod group) get a subset
    }

    public function getMenuText($language)
    {
        return $this->getLang('menu');
    }

    public function handle()
    {
        global $INPUT;
        $this->wl = plugin_load('helper', 'wikilan');
        if (!$this->wl->isMod()) return;
        if (!$INPUT->server->str('REQUEST_METHOD') === 'POST') return;
        $action = $INPUT->str('wl_action');
        if ($action === '' || !checkSecurityToken()) return;

        $db = $this->wl->getDB();
        $isAdmin = $this->wl->isAdmin();

        switch ($action) {
            case 'lan_create':
                if (!$isAdmin) break;
                $ns = cleanID($INPUT->str('namespace'));
                if ($ns === '' || $INPUT->str('title') === '') break;
                $db->exec(
                    "INSERT OR IGNORE INTO lans (namespace, title, buildup, start, end, state, plan_media)
                     VALUES (?, ?, ?, ?, ?, 'planned', ?)",
                    $ns,
                    $INPUT->str('title'),
                    $this->dtIn($INPUT->str('buildup')),
                    $this->dtIn($INPUT->str('start')),
                    $this->dtIn($INPUT->str('end')),
                    $INPUT->str('plan') ? cleanID($INPUT->str('plan')) : null
                );
                $this->wl->seedTemplates($ns);
                msg('LAN created', 1);
                break;

            case 'lan_dates':
                if (!$isAdmin) break;
                $db->exec(
                    "UPDATE lans SET buildup = ?, start = ?, end = ? WHERE id = ?",
                    $this->dtIn($INPUT->str('buildup')),
                    $this->dtIn($INPUT->str('start')),
                    $this->dtIn($INPUT->str('end')),
                    $INPUT->int('lan')
                );
                msg('Schedule updated', 1);
                break;

            case 'lan_state':
                if (!$isAdmin) break;
                $state = $INPUT->str('state');
                if (!in_array($state, ['planned', 'active', 'archived'])) break;
                if ($state === 'active') {
                    $db->exec("UPDATE lans SET state = 'archived' WHERE state = 'active'");
                }
                $db->exec("UPDATE lans SET state = ? WHERE id = ?", $state, $INPUT->int('lan'));
                msg('State updated', 1);
                break;

            case 'lan_plan':
                if (!$isAdmin) break;
                $db->exec(
                    "UPDATE lans SET plan_media = ? WHERE id = ?",
                    cleanID($INPUT->str('plan')),
                    $INPUT->int('lan')
                );
                msg('Plan set', 1);
                break;

            case 'seats_import': {
                $lan = $this->wl->getLan($INPUT->int('lan'));
                if ($lan) {
                    $n = $this->wl->importSeats($lan);
                    msg("Imported/updated $n seats from the plan", 1);
                }
                break;
            }

            case 'seat_adminonly':
                $db->exec(
                    "UPDATE seats SET admin_only = ? WHERE lan_id = ? AND seat_id = ?",
                    $INPUT->int('value'),
                    $INPUT->int('lan'),
                    $INPUT->str('seat')
                );
                break;

            case 'seat_clear':
                $db->exec(
                    "DELETE FROM seat_state WHERE lan_id = ? AND seat_id = ?",
                    $INPUT->int('lan'),
                    $INPUT->str('seat')
                );
                msg('Seat cleared', 1);
                break;

            case 'seat_setstate': {
                // flip the state of whoever currently holds the seat
                $state = $INPUT->str('state');
                if (!in_array($state, ['reserved', 'arrived', 'admin-assigned'])) break;
                $lanId = $INPUT->int('lan');
                $seat = $INPUT->str('seat');
                $cur = $db->queryRecord(
                    "SELECT * FROM seat_state WHERE lan_id = ? AND seat_id = ?",
                    $lanId,
                    $seat
                );
                if (!$cur) break;
                $db->exec(
                    "UPDATE seat_state SET state = ?, ts = ? WHERE lan_id = ? AND seat_id = ?",
                    $state,
                    time(),
                    $lanId,
                    $seat
                );
                msg("Seat $seat: {$cur['user']} is now $state", 1);
                break;
            }

            case 'seat_assign': {
                $user = trim($INPUT->str('user'));
                $state = $INPUT->str('state') === 'arrived' ? 'arrived' : 'admin-assigned';
                if ($user === '') break;
                $lanId = $INPUT->int('lan');
                $seat = $INPUT->str('seat');
                // override: free the seat and any other claim of this user
                $db->exec("DELETE FROM seat_state WHERE lan_id = ? AND seat_id = ?", $lanId, $seat);
                $db->exec("DELETE FROM seat_state WHERE lan_id = ? AND user = ?", $lanId, $user);
                $db->exec(
                    "INSERT INTO seat_state (lan_id, seat_id, user, state, ts) VALUES (?, ?, ?, ?, ?)",
                    $lanId,
                    $seat,
                    $user,
                    $state,
                    time()
                );
                $db->exec(
                    "INSERT OR IGNORE INTO lan_attendees (lan_id, user, ts) VALUES (?, ?, ?)",
                    $lanId,
                    $user,
                    time()
                );
                msg("Assigned $seat to $user ($state)", 1);
                break;
            }

            case 'port_set':
                $db->exec(
                    "REPLACE INTO port_seat (lan_id, port_id, seat_id, ip) VALUES (?, ?, ?, ?)",
                    $INPUT->int('lan'),
                    trim($INPUT->str('port')),
                    trim($INPUT->str('seat')),
                    trim($INPUT->str('ip'))
                );
                msg('Port mapped', 1);
                break;

            case 'port_del':
                $db->exec(
                    "DELETE FROM port_seat WHERE lan_id = ? AND port_id = ?",
                    $INPUT->int('lan'),
                    $INPUT->str('port')
                );
                break;

            case 'sl_map': {
                $u = trim($INPUT->str('user'));
                if ($u === '') break;
                /** @var helper_plugin_wikilan_strichliste $sl */
                $sl = plugin_load('helper', 'wikilan_strichliste');
                $name = trim($INPUT->str('sl_name'));
                $sl->setMap($u, $name);
                msg($name === ''
                    ? "Strichliste mapping for $u removed"
                    : "Strichliste: $u pays as $name", 1);
                break;
            }

            case 'broadcast': {
                $title = trim($INPUT->str('title'));
                if ($title === '') break;
                $lan = $this->wl->activeLan();
                $this->wl->addNotice(
                    $lan ? (int)$lan['id'] : null,
                    null,
                    'broadcast',
                    $title,
                    trim($INPUT->str('body')),
                    '',
                    $this->wl->user(),
                    time() + (int)$this->getConf('notice_ttl')
                );
                $n = $this->wl->queuePushBroadcast(null, [
                    'title' => $title,
                    'body' => trim($INPUT->str('body')),
                ]);
                try {
                    plugin_load('helper', 'wikilan_notify')->flushOutbox();
                    msg("Broadcast sent (push delivered to $n users)", 1);
                } catch (\Throwable $e) {
                    msg("Broadcast sent (push queued for $n users; delivery on next cron tick)", 1);
                }
                break;
            }
        }
    }

    public function html()
    {
        global $INPUT, $ID;
        $this->wl = plugin_load('helper', 'wikilan');
        if (!$this->wl->isMod()) {
            echo '<p>forbidden</p>';
            return;
        }
        $db = $this->wl->getDB();
        $isAdmin = $this->wl->isAdmin();

        $lans = $db->queryAll("SELECT * FROM lans ORDER BY id DESC");
        $curId = $INPUT->int('wl_lan');
        $cur = null;
        foreach ($lans as $l) {
            if ((int)$l['id'] === $curId) $cur = $l;
        }
        if (!$cur) {
            $cur = $this->wl->activeLan() ?: ($lans[0] ?? null);
        }

        echo '<div class="wl-admin"><h1>WikiLAN</h1>';

        // ---------------------------------------------------------- editions
        echo '<h2>LAN editions</h2><table class="inline"><thead><tr>'
            . '<th>ns</th><th>title</th><th>dates</th><th>state</th><th>plan</th><th></th>'
            . '</tr></thead><tbody>';
        foreach ($lans as $l) {
            echo '<tr' . ($cur && $l['id'] === $cur['id'] ? ' class="wl-current"' : '') . '>'
                . '<td><a href="' . wl($ID, ['do' => 'admin', 'page' => 'wikilan', 'wl_lan' => $l['id']], false, '&amp;')
                . '">' . hsc($l['namespace']) . '</a></td>'
                . '<td>' . hsc($l['title']) . '</td>'
                . '<td>' . hsc(trim(($l['buildup'] ?? '') . ' ⇒ ' . ($l['start'] ?? '') . ' → ' . ($l['end'] ?? ''))) . '</td>'
                . '<td><strong>' . hsc($l['state']) . '</strong></td>'
                . '<td>' . hsc($l['plan_media'] ?? '') . '</td><td>';
            if ($isAdmin) {
                foreach (['planned', 'active', 'archived'] as $st) {
                    if ($st === $l['state']) continue;
                    echo $this->actionForm('lan_state', [
                        'lan' => $l['id'],
                        'state' => $st,
                    ], '→ ' . $st);
                }
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        if ($isAdmin) {
            echo '<details><summary>Create edition</summary>'
                . $this->openForm('lan_create')
                . 'ns <input name="namespace" placeholder="msl:2026_2" required> '
                . 'title <input name="title" required> '
                . 'buildup <input name="buildup" type="datetime-local"> '
                . 'start <input name="start" type="datetime-local"> '
                . 'end/teardown <input name="end" type="datetime-local"> '
                . 'plan media <input name="plan" placeholder="en:msl:plan.svg"> '
                . '<button>create</button></form></details>';
        }

        if (!$cur) {
            echo '</div>';
            return;
        }
        $lanId = (int)$cur['id'];
        echo '<h2>' . hsc($cur['title']) . ' <small>(' . hsc($cur['state']) . ')</small></h2>';

        if ($isAdmin) {
            echo $this->openForm('lan_dates', $lanId)
                . 'buildup <input name="buildup" type="datetime-local" value="' . $this->dtOut($cur['buildup'] ?? '') . '"> '
                . 'start <input name="start" type="datetime-local" value="' . $this->dtOut($cur['start'] ?? '') . '"> '
                . 'end/teardown <input name="end" type="datetime-local" value="' . $this->dtOut($cur['end'] ?? '') . '"> '
                . '<button>save schedule</button></form> ';
            echo $this->openForm('lan_plan', $lanId)
                . 'plan media <input name="plan" value="' . hsc($cur['plan_media'] ?? '') . '"> '
                . '<button>set</button></form> ';
        }
        echo $this->actionForm('seats_import', ['lan' => $lanId], 'import seats from plan');

        // ---------------------------------------------------------- seats & occupancy
        $seats = $this->wl->seats($lanId);
        // attendee dropdown options for the assign forms, by display name
        $logins = array_column($this->wl->attendees($lanId), 'user');
        usort($logins, fn($a, $b) => strcasecmp($this->wl->userName($a), $this->wl->userName($b)));
        $userOpts = '<option value=""></option>';
        foreach ($logins as $u) {
            $label = $this->wl->userName($u);
            if ($label !== $u) $label .= " ($u)";
            $userOpts .= '<option value="' . hsc($u) . '">' . hsc($label) . '</option>';
        }
        echo '<h3>Seats (' . count($seats) . ')</h3><table class="inline"><thead><tr>'
            . '<th>seat</th><th>flags</th><th>state</th><th>user</th><th>since</th>'
            . '<th>occupant</th><th>assign</th>'
            . '</tr></thead><tbody>';
        foreach ($seats as $s) {
            echo '<tr><td>' . hsc($s['seat_id']) . '</td><td>'
                . $this->actionForm('seat_adminonly', [
                    'lan' => $lanId,
                    'seat' => $s['seat_id'],
                    'value' => $s['admin_only'] ? 0 : 1,
                ], $s['admin_only'] ? 'admin-only ✓' : 'admin-only ✗')
                . '</td>'
                . '<td>' . hsc($s['state'] ?? 'free') . '</td>'
                . '<td>' . ($s['user'] ? hsc($s['user']) : '') . '</td>'
                . '<td>' . ($s['ts'] ? dformat($s['ts'], '%d. %H:%M') : '') . '</td>'
                . '<td>';
            // one-click actions on whoever holds the seat right now
            if ($s['user']) {
                if (($s['state'] ?? '') === 'arrived') {
                    echo $this->actionForm('seat_setstate', [
                        'lan' => $lanId, 'seat' => $s['seat_id'], 'state' => 'reserved',
                    ], 'unarrive');
                } else {
                    echo $this->actionForm('seat_setstate', [
                        'lan' => $lanId, 'seat' => $s['seat_id'], 'state' => 'arrived',
                    ], 'set arrived');
                }
                echo $this->actionForm('seat_clear', ['lan' => $lanId, 'seat' => $s['seat_id']], 'clear');
            }
            echo '</td><td>';
            echo $this->openForm('seat_assign', $lanId)
                . '<input type="hidden" name="seat" value="' . hsc($s['seat_id']) . '">'
                . '<select name="user">' . $userOpts . '</select>'
                . '<select name="state"><option>admin-assigned</option><option>arrived</option></select>'
                . '<button>assign</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        // mismatches: users with a reservation and an arrival at different seats
        $mismatch = $db->queryAll(
            "SELECT a.user, a.seat_id AS arrived_at, r.seat_id AS reserved
               FROM seat_state a JOIN seat_state r
                 ON r.lan_id = a.lan_id AND r.user = a.user AND r.seat_id != a.seat_id
              WHERE a.lan_id = ? AND a.state = 'arrived' AND r.state != 'arrived'",
            $lanId
        );
        if ($mismatch) {
            echo '<h3>Wrong-seat mismatches</h3><ul>';
            foreach ($mismatch as $m) {
                echo '<li>' . hsc($m['user']) . ': reserved ' . hsc($m['reserved'])
                    . ', plugged in at ' . hsc($m['arrived_at']) . '</li>';
            }
            echo '</ul>';
        }

        // ---------------------------------------------------------- port map
        $ports = $db->queryAll(
            "SELECT * FROM port_seat WHERE lan_id = ? ORDER BY port_id",
            $lanId
        );
        echo '<h3>Port ↔ seat ↔ IP map (' . count($ports) . ')</h3>'
            . '<table class="inline"><thead><tr><th>port</th><th>seat</th><th>IP</th><th></th></tr></thead><tbody>';
        foreach ($ports as $p) {
            echo '<tr><td>' . hsc($p['port_id']) . '</td><td>' . hsc($p['seat_id']) . '</td>'
                . '<td>' . hsc($p['ip'] ?? '') . '</td><td>'
                . $this->actionForm('port_del', ['lan' => $lanId, 'port' => $p['port_id']], 'delete')
                . '</td></tr>';
        }
        echo '</tbody></table>'
            . $this->openForm('port_set', $lanId)
            . 'port <input name="port" placeholder="sw1/12" required> '
            . 'seat <input name="seat" size="4" required> '
            . 'ip <input name="ip" placeholder="10.0.0.42" required> '
            . '<button>map</button></form>';

        // ---------------------------------------------------------- strichliste mapping
        /** @var helper_plugin_wikilan_strichliste $sl */
        $sl = plugin_load('helper', 'wikilan_strichliste');
        echo '<h3>Strichliste name mapping</h3>'
            . '<p>Wiki logins double as strichliste account names. Add an override when '
            . 'someone registered under a different name — it applies to payment matching '
            . 'and buy statistics.</p>';
        $map = $sl->mapAll();
        if ($map) {
            echo '<table class="inline"><thead><tr>'
                . '<th>wiki user</th><th>strichliste name</th><th></th></tr></thead><tbody>';
            foreach ($map as $u => $slName) {
                echo '<tr><td>' . hsc($u) . '</td><td>' . hsc($slName) . '</td><td>'
                    . $this->actionForm('sl_map', ['user' => $u, 'sl_name' => ''], 'remove')
                    . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        $known = $db->queryAll(
            "SELECT DISTINCT user FROM lan_attendees
              UNION SELECT user FROM steam_links ORDER BY user"
        );
        $wikiDl = '';
        foreach ($known as $r) $wikiDl .= '<option value="' . hsc($r['user']) . '">';
        $slDl = '';
        foreach ($sl->slUsers() as $n) $slDl .= '<option value="' . hsc($n) . '">';
        echo $this->openForm('sl_map')
            . 'wiki user <input name="user" list="wl-wiki-users" required>'
            . '<datalist id="wl-wiki-users">' . $wikiDl . '</datalist> '
            . 'strichliste name <input name="sl_name" list="wl-sl-users">'
            . '<datalist id="wl-sl-users">' . $slDl . '</datalist> '
            . '<button>set (empty = remove)</button></form>';

        // ---------------------------------------------------------- broadcast
        echo '<h3>Broadcast</h3>'
            . $this->openForm('broadcast')
            . '<input name="title" placeholder="Food is ready!" size="30" required> '
            . '<input name="body" placeholder="details (optional)" size="40"> '
            . '<button>send + push</button></form>';

        echo '</div>';
    }

    /** datetime-local input value → stored 'YYYY-MM-DD HH:MM' (null when empty) */
    protected function dtIn(string $v): ?string
    {
        $v = trim($v);
        return $v === '' ? null : str_replace('T', ' ', $v);
    }

    /** stored schedule string → datetime-local input value */
    protected function dtOut(string $v): string
    {
        if (trim($v) === '') return '';
        $ts = strtotime($v);
        return $ts ? date('Y-m-d\TH:i', $ts) : '';
    }

    protected function openForm(string $action, ?int $lanId = null): string
    {
        global $ID;
        $html = '<form method="post" action="' . wl($ID) . '" class="wl-inline-form">'
            . '<input type="hidden" name="do" value="admin">'
            . '<input type="hidden" name="page" value="wikilan">'
            . '<input type="hidden" name="sectok" value="' . getSecurityToken() . '">'
            . '<input type="hidden" name="wl_action" value="' . hsc($action) . '">';
        if ($lanId !== null) {
            $html .= '<input type="hidden" name="lan" value="' . $lanId . '">'
                . '<input type="hidden" name="wl_lan" value="' . $lanId . '">';
        }
        return $html;
    }

    protected function actionForm(string $action, array $fields, string $label): string
    {
        $html = $this->openForm($action);
        foreach ($fields as $k => $v) {
            $html .= '<input type="hidden" name="' . hsc($k) . '" value="' . hsc((string)$v) . '">';
        }
        return $html . '<button>' . hsc($label) . '</button></form>';
    }
}
