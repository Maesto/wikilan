<?php

use dokuwiki\Extension\Plugin;
use dokuwiki\plugin\sqlite\SQLiteDB;

/**
 * WikiLAN service layer: DB access, LAN/edition state, language-neutral ids,
 * attendance, seats & arrival resolution, seating-plan SVG processing, notices.
 *
 * All syntax/action/admin/cli components go through this helper.
 */
class helper_plugin_wikilan extends Plugin
{
    protected ?SQLiteDB $db = null;

    /** @var array|null request-scoped cache of the active LAN row */
    protected $activeLan = false;

    /** @var array banners queued during this request (wrong seat etc.), read by action/inject */
    public $banners = [];

    // ---------------------------------------------------------------- DB

    public function getDB(): SQLiteDB
    {
        if ($this->db === null) {
            $this->db = new SQLiteDB('wikilan', DOKU_PLUGIN . 'wikilan/db/');
        }
        return $this->db;
    }

    // ---------------------------------------------------------------- users & roles

    public function user(): string
    {
        return $_SERVER['REMOTE_USER'] ?? '';
    }

    public function isAdmin(): bool
    {
        global $INFO;
        if (isset($INFO['isadmin'])) return (bool)$INFO['isadmin'];
        return auth_isadmin();
    }

    public function isMod(): bool
    {
        global $USERINFO;
        if ($this->isAdmin()) return true;
        $grps = $USERINFO['grps'] ?? [];
        return in_array($this->getConf('mod_group'), $grps);
    }

    /**
     * Auth backend — $auth is not set up under NOSESSION (CLI, maintenance
     * scripts), which would degrade display names in synced wiki text, so
     * fall back to loading the configured backend directly.
     */
    public function auth()
    {
        global $auth, $conf;
        if ($auth) return $auth;
        static $own = false;
        if ($own === false) {
            $own = plugin_load('auth', $conf['authtype'] ?: 'authplain');
        }
        return $own;
    }

    /** getUserData through auth(), false when unknown */
    public function userData(string $user)
    {
        $auth = $this->auth();
        return $auth ? $auth->getUserData($user) : false;
    }

    /** Display name for a wiki user */
    public function userName(string $user): string
    {
        static $cache = [];
        if (!isset($cache[$user])) {
            $info = $this->userData($user);
            $cache[$user] = $info && !empty($info['name']) ? $info['name'] : $user;
        }
        return $cache[$user];
    }

    /**
     * Recover the real (case-sensitive) login from a page-id-mangled name:
     * profile pages live at users:<login> but cleanID lowercases, so
     * users:niklaaaaaas must resolve back to the login "Niklaaaaaas".
     */
    public function resolveLogin(string $name): string
    {
        if ($this->userData($name)) return $name;
        $known = array_merge(
            $this->getDB()->queryAll("SELECT DISTINCT user FROM lan_attendees"),
            $this->getDB()->queryAll("SELECT user FROM steam_links")
        );
        foreach ($known as $r) {
            if (mb_strtolower($r['user']) === mb_strtolower($name)) return $r['user'];
        }
        return $name;
    }

    /**
     * Page id of a user's profile page in the current language — the existing
     * variant if there is one, else where it would be created.
     */
    public function profilePage(string $user, ?bool &$exists = null): string
    {
        $neutral = cleanID('msl:users:' . $user);
        $local = $this->localPage($neutral);
        $exists = $local !== null;
        return $local ?: $this->localId($neutral);
    }

    // ---------------------------------------------------------------- LAN editions

    /** The single active LAN row or null */
    public function activeLan(): ?array
    {
        if ($this->activeLan === false) {
            $this->activeLan = $this->getDB()->queryRecord(
                "SELECT * FROM lans WHERE state = 'active' LIMIT 1"
            );
        }
        return $this->activeLan ?: null;
    }

    public function getLan(int $id): ?array
    {
        return $this->getDB()->queryRecord("SELECT * FROM lans WHERE id = ?", $id) ?: null;
    }

    public function lanByNamespace(string $ns): ?array
    {
        return $this->getDB()->queryRecord("SELECT * FROM lans WHERE namespace = ?", $ns) ?: null;
    }

    /**
     * Resolve the LAN a syntax module should render: explicit lan= parameter,
     * else the edition whose namespace prefixes the current page, else the active one.
     */
    public function contextLan(array $params = [], string $pageId = ''): ?array
    {
        if (!empty($params['lan'])) {
            $lan = $this->lanByNamespace($params['lan']);
            if ($lan) return $lan;
        }
        if ($pageId) {
            $neutral = $this->neutralId($pageId);
            foreach ($this->getDB()->queryAll("SELECT * FROM lans") as $lan) {
                if (
                    $neutral === $lan['namespace'] ||
                    strpos($neutral, $lan['namespace'] . ':') === 0
                ) {
                    return $lan;
                }
            }
        }
        return $this->activeLan();
    }

    /** Whether a LAN renders live (active) or frozen (archived) */
    public function isLive(array $lan): bool
    {
        return $lan['state'] === 'active';
    }

    /**
     * Parsed schedule of an edition as unix timestamps (null where unset):
     * buildup = buildup start, start = event start, end = event end/teardown start.
     * Columns hold 'YYYY-MM-DD[ HH:MM]' strings; date-only values parse to 00:00.
     */
    public function lanDates(array $lan): array
    {
        $out = [];
        foreach (['buildup', 'start', 'end'] as $k) {
            $v = trim((string)($lan[$k] ?? ''));
            $out[$k] = $v !== '' ? (strtotime($v) ?: null) : null;
        }
        return $out;
    }

    /**
     * Seed a namespace _template.txt (the editor's skeleton for new pages)
     * unless one exists. $ns is a full namespace incl. language prefix.
     */
    public function seedTemplate(string $ns, string $content): void
    {
        $file = dirname(wikiFN("$ns:x")) . '/_template.txt';
        if (file_exists($file)) return;
        io_makeFileDir($file);
        io_saveFile($file, $content);
    }

    /**
     * Default skeletons: user pages and the bureaucracy event template
     * always, plus an edition's event templates and "create event" form
     * when $lanNs is given. Safe to call repeatedly (existing files win).
     */
    public function seedTemplates(?string $lanNs = null): void
    {
        foreach ($this->languages() ?: [''] as $l) {
            $p = $l ? "$l:" : '';
            $this->seedTemplate("{$p}msl:users", "====== @PAGE@ ======\n\n~~LAN:profile~~\n");
            $this->seedPage(
                "{$p}msl:event_template",
                '====== @@' . ($l === 'de' ? 'Titel' : 'Title') . "@@ ======\n\n\n\n~~LAN:eventsignup~~\n"
            );
            if ($lanNs !== null) {
                $this->seedTemplate(
                    "$p$lanNs:events",
                    "====== @!PAGE@ ======\n\n\n\n~~LAN:eventsignup~~\n"
                );
                $this->seedPage("$p$lanNs:events:new", $this->eventFormText($l, $lanNs));
            }
        }
    }

    /** Create a wiki page with default content unless it exists */
    protected function seedPage(string $pid, string $text): void
    {
        if (page_exists($pid)) return;
        saveWikiText($pid, $text, 'wikilan default');
    }

    /** Bureaucracy "create event" form page content for one language */
    protected function eventFormText(string $lang, string $lanNs): string
    {
        $p = $lang ? "$lang:" : '';
        $fields = "wikilan_day \"=1\"\n"
            . "struct_field \"event.starttime\" \"=18:00\" !\n"
            . "struct_field \"event.duration\" \"=60\" !\n"
            . "wikilan_host \"=@USER@\" !\n"
            . "struct_field \"event.category\" !\n"
            . "struct_field \"event.price\" !\n"
            . "struct_field \"event.productid\" !\n"
            . "wikilan_day \"event.cutoffday\" !\n"
            . "struct_field \"event.cutofftime\" !\n";
        if ($lang === 'de') {
            return "====== Event anlegen ======\n\n"
                . "Tag 1 ist der Starttag der LAN (~~LAN:when start date~~); "
                . "0, -1, … sind Aufbautage.\n\n"
                . "**Tipp:** Nach dem Stromverbrauch der letzten beiden LANs ist ab "
                . "ca. 14 Uhr richtig was los, Spitze **20:00–02:00**; vor 11 Uhr "
                . "schläft noch alles.\n\n"
                . "<form>\n"
                . "action template {$p}msl:event_template $p$lanNs:events:\n"
                . "thanks \"Event angelegt. Öffne die Seite, um eine Beschreibung zu ergänzen.\"\n"
                . "textbox \"Titel\" @\n"
                . $fields
                . "submit \"Event anlegen\"\n"
                . "</form>\n";
        }
        return "====== Create event ======\n\n"
            . "Day 1 is the LAN's start day (~~LAN:when start date~~); "
            . "0, -1, … are buildup days.\n\n"
            . "**Tip:** Judging by power draw at the last two LANs, the floor "
            . "fills from ~14:00 with the peak at **20:00–02:00**; before 11:00 "
            . "everyone is asleep.\n\n"
            . "<form>\n"
            . "action template {$p}msl:event_template $p$lanNs:events:\n"
            . "thanks \"Event created. Open it to add a description.\"\n"
            . "textbox \"Title\" @\n"
            . $fields
            . "submit \"Create event\"\n"
            . "</form>\n";
    }

    /** Language of a page id (leading translation namespace), else the wiki default */
    public function pageLang(string $id): string
    {
        global $conf;
        $first = explode(':', $id, 2)[0];
        if (in_array($first, $this->languages(), true)) return $first;
        return $conf['lang'];
    }

    /**
     * Locale-aware date/time string for wiki output; $show: date | time | datetime.
     * Uses php-intl (system locales are not installed); ISO fallback without it.
     */
    public function formatWhen(int $ts, string $lang, string $show = 'datetime'): string
    {
        if (class_exists(\IntlDateFormatter::class)) {
            $dateStyle = $show === 'time' ? \IntlDateFormatter::NONE : \IntlDateFormatter::FULL;
            $timeStyle = $show === 'date' ? \IntlDateFormatter::NONE : \IntlDateFormatter::SHORT;
            $fmt = new \IntlDateFormatter($lang, $dateStyle, $timeStyle, date_default_timezone_get());
            $res = $fmt->format($ts);
            if ($res !== false) return $res;
        }
        switch ($show) {
            case 'date': return date('Y-m-d', $ts);
            case 'time': return date('H:i', $ts);
            default:     return date('Y-m-d H:i', $ts);
        }
    }

    // ---------------------------------------------------------------- language-neutral ids

    /** Configured translation language codes, e.g. ['en','de'] */
    public function languages(): array
    {
        global $conf;
        $t = $conf['plugin']['translation']['translations'] ?? '';
        $langs = preg_split('/[,\s]+/', $t, -1, PREG_SPLIT_NO_EMPTY);
        return $langs ?: [];
    }

    /** Strip the leading language namespace: en:msl:x → msl:x */
    public function neutralId(string $id): string
    {
        $id = cleanID($id);
        foreach ($this->languages() as $l) {
            if (strpos($id, $l . ':') === 0) return substr($id, strlen($l) + 1);
        }
        return $id;
    }

    /** Prefix a neutral id with the current (or given) language namespace */
    public function localId(string $neutral, string $lang = ''): string
    {
        global $conf;
        $langs = $this->languages();
        if (!$langs) return $neutral;
        if (!$lang) {
            global $ID;
            $cur = explode(':', (string)$ID)[0];
            $lang = in_array($cur, $langs) ? $cur : $conf['lang'];
        }
        if (!in_array($lang, $langs)) $lang = $langs[0];
        return $lang . ':' . $neutral;
    }

    /** All existing language variants of a neutral page id */
    public function pageVariants(string $neutral): array
    {
        $out = [];
        foreach ($this->languages() as $l) {
            $pid = $l . ':' . $neutral;
            if (page_exists($pid)) $out[$l] = $pid;
        }
        if (!$out && page_exists($neutral)) $out[''] = $neutral;
        return $out;
    }

    /** Best page variant for the current language, else any */
    public function localPage(string $neutral): ?string
    {
        $variants = $this->pageVariants($neutral);
        if (!$variants) return null;
        $local = $this->localId($neutral);
        foreach ($variants as $pid) {
            if ($pid === $local) return $pid;
        }
        return reset($variants);
    }

    // ---------------------------------------------------------------- attendance

    public function isAttending(int $lanId, string $user): bool
    {
        return (bool)$this->getDB()->queryValue(
            "SELECT 1 FROM lan_attendees WHERE lan_id = ? AND user = ?",
            $lanId,
            $user
        );
    }

    public function setAttending(int $lanId, string $user, bool $attending): void
    {
        if ($attending) {
            $this->getDB()->exec(
                "INSERT OR IGNORE INTO lan_attendees (lan_id, user, ts) VALUES (?, ?, ?)",
                $lanId,
                $user,
                time()
            );
            $this->ensureProfilePages($user);
        } else {
            $this->getDB()->exec(
                "DELETE FROM lan_attendees WHERE lan_id = ? AND user = ?",
                $lanId,
                $user
            );
            // giving up attendance also frees a self-reserved (not arrived) seat
            $this->getDB()->exec(
                "DELETE FROM seat_state WHERE lan_id = ? AND user = ? AND state = 'reserved'",
                $lanId,
                $user
            );
        }
    }

    /**
     * Create bare profile pages (heading + profile card) in every language
     * for a user who doesn't have them yet — the guided creation form
     * (action/profilecreate.php) still offers to fill such skeletons.
     */
    public function ensureProfilePages(string $user): void
    {
        $neutral = cleanID('msl:users:' . $user);
        $skeleton = '====== ' . $this->userName($user) . " ======\n\n~~LAN:profile~~\n";
        foreach ($this->languages() ?: [''] as $l) {
            $pid = $this->localId($neutral, $l);
            if (page_exists($pid)) continue;
            saveWikiText($pid, $skeleton, $this->getLang('profile_create_summary'));
        }
    }

    public function attendees(int $lanId): array
    {
        return $this->getDB()->queryAll(
            "SELECT a.user, a.ts,
                    (SELECT seat_id FROM seat_state s
                      WHERE s.lan_id = a.lan_id AND s.user = a.user
                      ORDER BY s.state = 'arrived' DESC LIMIT 1) AS seat_id,
                    (SELECT state FROM seat_state s
                      WHERE s.lan_id = a.lan_id AND s.user = a.user
                      ORDER BY s.state = 'arrived' DESC LIMIT 1) AS seat_state
               FROM lan_attendees a WHERE a.lan_id = ? ORDER BY a.ts",
            $lanId
        );
    }

    // ---------------------------------------------------------------- seats

    public function seats(int $lanId): array
    {
        return $this->getDB()->queryAll(
            "SELECT s.*, st.user, st.state, st.ts
               FROM seats s
          LEFT JOIN seat_state st ON st.lan_id = s.lan_id AND st.seat_id = s.seat_id
              WHERE s.lan_id = ? ORDER BY s.seat_id",
            $lanId
        );
    }

    /** The user's primary seat row (arrived beats reserved) */
    public function seatOfUser(int $lanId, string $user): ?array
    {
        return $this->getDB()->queryRecord(
            "SELECT * FROM seat_state WHERE lan_id = ? AND user = ?
              ORDER BY state = 'arrived' DESC LIMIT 1",
            $lanId,
            $user
        ) ?: null;
    }

    /** All seat rows of a user (may be a reservation plus an arrival elsewhere) */
    public function seatsOfUser(int $lanId, string $user): array
    {
        return $this->getDB()->queryAll(
            "SELECT * FROM seat_state WHERE lan_id = ? AND user = ?",
            $lanId,
            $user
        );
    }

    public function seatState(int $lanId, string $seatId): ?array
    {
        return $this->getDB()->queryRecord(
            "SELECT * FROM seat_state WHERE lan_id = ? AND seat_id = ?",
            $lanId,
            $seatId
        ) ?: null;
    }

    /**
     * Reserve a seat for a user. Returns '' on success or a translated error.
     * $force = admin/mod override (also used for admin-assigned).
     * $move = user-confirmed move off a seat they already hold (any state).
     */
    public function reserveSeat(int $lanId, string $seatId, string $user, bool $force = false, bool $move = false): string
    {
        $db = $this->getDB();
        $seat = $db->queryRecord(
            "SELECT * FROM seats WHERE lan_id = ? AND seat_id = ?",
            $lanId,
            $seatId
        );
        if (!$seat) return $this->getLang('error');

        if (!$force) {
            if (!$this->isAttending($lanId, $user)) return $this->getLang('seat_need_attend');
            if ($seat['admin_only']) return sprintf($this->getLang('admin_seat_warn'), $seatId);
            // buddy spots are handed out by the seat holder (shareSeat) or orga
            if (!empty($seat['buddy_of'])) {
                return sprintf($this->getLang('buddy_direct'), $seat['buddy_of']);
            }
        }

        $cur = $this->seatState($lanId, $seatId);
        if ($cur && $cur['user'] !== $user && !$force) {
            return sprintf(
                $this->getLang('seat_taken'),
                $seatId,
                $this->userName($cur['user'])
            );
        }

        // one seat per user: leaving a held seat (reserved OR arrived) needs
        // an explicit confirmation ($move, asked client-side) or an orga
        // override ($force)
        if (!$force && !$move) {
            foreach ($this->seatsOfUser($lanId, $user) as $r) {
                if ($r['seat_id'] !== $seatId) {
                    return sprintf($this->getLang('seat_have_other'), $r['seat_id']);
                }
            }
        }
        // a confirmed move releases everything, an admin assignment leaves a
        // detected arrival elsewhere alone (the wrong-seat banner covers it)
        $db->exec(
            "DELETE FROM seat_state WHERE lan_id = ? AND user = ?"
                . ($move ? "" : " AND state != 'arrived'"),
            $lanId,
            $user
        );
        $db->exec(
            "REPLACE INTO seat_state (lan_id, seat_id, user, state, ts) VALUES (?, ?, ?, ?, ?)",
            $lanId,
            $seatId,
            $user,
            $force ? 'admin-assigned' : 'reserved',
            time()
        );
        return '';
    }

    /**
     * Share the caller's seat: put $buddyUser on the seat's buddy spot
     * (the 'A1b' row). Returns '' on success or a translated error.
     */
    public function shareSeat(int $lanId, string $host, string $buddyUser): string
    {
        $held = $this->seatOfUser($lanId, $host);
        if (!$held) return $this->getLang('error');
        $buddySeat = $this->getDB()->queryRecord(
            "SELECT * FROM seats WHERE lan_id = ? AND buddy_of = ?",
            $lanId,
            $held['seat_id']
        );
        if (!$buddySeat) return sprintf($this->getLang('buddy_not_capable'), $held['seat_id']);
        $cur = $this->seatState($lanId, $buddySeat['seat_id']);
        if ($cur) {
            return sprintf(
                $this->getLang('seat_taken'),
                $buddySeat['seat_id'],
                $this->userName($cur['user'])
            );
        }
        if ($buddyUser === '' || $buddyUser === $host) return $this->getLang('error');
        if (!$this->isAttending($lanId, $buddyUser)) return $this->getLang('buddy_not_attending');
        if ($this->seatsOfUser($lanId, $buddyUser)) {
            return sprintf($this->getLang('buddy_has_seat'), $this->userName($buddyUser));
        }
        $this->getDB()->exec(
            "INSERT INTO seat_state (lan_id, seat_id, user, state, ts) VALUES (?, ?, ?, 'reserved', ?)",
            $lanId,
            $buddySeat['seat_id'],
            $buddyUser,
            time()
        );
        $this->addNotice(
            $lanId,
            $buddyUser,
            'buddy',
            $this->getLang('buddy_notice_title'),
            sprintf(
                $this->getLang('buddy_notice_body'),
                $this->userName($host),
                $held['seat_id'],
                $buddySeat['seat_id']
            )
        );
        $this->queuePush($buddyUser, null, [
            'title' => $this->getLang('buddy_notice_title'),
            'body' => sprintf(
                $this->getLang('buddy_notice_body'),
                $this->userName($host),
                $held['seat_id'],
                $buddySeat['seat_id']
            ),
        ]);
        return '';
    }

    public function releaseSeat(int $lanId, string $seatId, string $user, bool $force = false): string
    {
        $cur = $this->seatState($lanId, $seatId);
        if (!$cur) return '';
        if ($cur['user'] !== $user && !$force) return $this->getLang('error');
        $this->getDB()->exec(
            "DELETE FROM seat_state WHERE lan_id = ? AND seat_id = ?",
            $lanId,
            $seatId
        );
        return '';
    }

    // ---------------------------------------------------------------- IP → seat (arrival)

    /** Client IP, honoring X-Forwarded-For only from configured trusted proxies */
    public function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $trusted = preg_split(
            '/[,\s]+/',
            (string)$this->getConf('trusted_proxies'),
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        if ($remote && in_array($remote, $trusted) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $hops = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            if ($hops && filter_var($hops[0], FILTER_VALIDATE_IP)) return $hops[0];
        }
        return $remote;
    }

    public function seatForIp(int $lanId, string $ip): ?array
    {
        if ($ip === '') return null;
        return $this->getDB()->queryRecord(
            "SELECT * FROM port_seat WHERE lan_id = ? AND ip = ?",
            $lanId,
            $ip
        ) ?: null;
    }

    /**
     * Record that a user is physically present at a seat (IP→seat detection or
     * admin override). Presence never steals a seat someone else holds; an
     * existing reservation of the user at another seat is kept so the wrong-seat
     * banner stays visible until resolved.
     *
     * @return array {claimed: bool, conflict_user: ?string,
     *                mismatch_reserved: ?string, admin_only_warn: bool}
     */
    public function markArrived(int $lanId, string $seatId, string $user): array
    {
        $db = $this->getDB();
        $res = [
            'claimed' => false,
            'conflict_user' => null,
            'mismatch_reserved' => null,
            'admin_only_warn' => false,
        ];

        $seat = $db->queryRecord(
            "SELECT * FROM seats WHERE lan_id = ? AND seat_id = ?",
            $lanId,
            $seatId
        );
        $occupant = $this->seatState($lanId, $seatId);
        $held = null;
        foreach ($this->seatsOfUser($lanId, $user) as $r) {
            if ($r['seat_id'] !== $seatId) {
                $held = $r;
                break;
            }
        }
        $res['mismatch_reserved'] = $held ? $held['seat_id'] : null;
        $res['mismatch_state'] = $held ? $held['state'] : null;

        if ($occupant && $occupant['user'] !== $user) {
            // someone else holds this seat — surface it, let organizers resolve
            $res['conflict_user'] = $occupant['user'];
            return $res;
        }

        if ($held) {
            // the user already holds a different seat: auto-assignment only
            // happens for the first seat — changing seats needs explicit
            // confirmation (seat_move) or an organizer override
            return $res;
        }

        // admin-only seat without an admin assignment for this user → warn but record
        $hadAssignment = $occupant && $occupant['user'] === $user;
        if ($seat && $seat['admin_only'] && !$hadAssignment) {
            $res['admin_only_warn'] = true;
        }

        $db->exec(
            "REPLACE INTO seat_state (lan_id, seat_id, user, state, ts) VALUES (?, ?, ?, 'arrived', ?)",
            $lanId,
            $seatId,
            $user,
            time()
        );
        // physically present implies attending
        $db->exec(
            "INSERT OR IGNORE INTO lan_attendees (lan_id, user, ts) VALUES (?, ?, ?)",
            $lanId,
            $user,
            time()
        );
        $res['claimed'] = true;
        return $res;
    }

    /**
     * User-confirmed seat change: give up all currently held seats and
     * arrive at $seatId (the seat detected for the client's IP).
     */
    public function confirmSeatMove(int $lanId, string $user, string $seatId): array
    {
        // never steal a seat someone else holds — and check BEFORE releasing
        // anything, so a failed move doesn't cost the user their current seat
        $occupant = $this->seatState($lanId, $seatId);
        if ($occupant && $occupant['user'] !== $user) {
            return [
                'claimed' => false,
                'conflict_user' => $occupant['user'],
                'mismatch_reserved' => null,
                'admin_only_warn' => false,
            ];
        }
        $this->getDB()->exec(
            "DELETE FROM seat_state WHERE lan_id = ? AND user = ?",
            $lanId,
            $user
        );
        return $this->markArrived($lanId, $seatId, $user);
    }

    /** Users currently marked arrived at a LAN */
    public function arrivedUsers(int $lanId): array
    {
        return $this->getDB()->queryAll(
            "SELECT user, seat_id FROM seat_state WHERE lan_id = ? AND state IN ('arrived')",
            $lanId
        );
    }

    // ---------------------------------------------------------------- seating-plan SVG

    /** Seat-code pattern for labels in the plan (trailing 'b' = buddy spot) */
    public const SEAT_PATTERN = '/^[A-Z]{1,2}\d{1,3}b?$/';

    /** Pattern matching buddy-spot labels, capturing the host seat code */
    public const BUDDY_PATTERN = '/^([A-Z]{1,2}\d{1,3})b$/';

    /**
     * Parse a plan SVG and return [seatId => ['x'=>..,'y'=>..]] from text labels
     * (and any element carrying inkscape:label with a seat code).
     */
    public function parseSeatPlan(string $svg): array
    {
        $doc = $this->loadSvg($svg);
        if (!$doc) return [];
        $seats = [];
        foreach ($this->findSeatNodes($doc) as $seatId => $node) {
            $seats[$seatId] = [
                'x' => (float)$node->getAttribute('x'),
                'y' => (float)$node->getAttribute('y'),
            ];
        }
        return $seats;
    }

    /**
     * Render the plan SVG inline with an interactive hotspot per seat label.
     * $states: seatId => ['state' => .., 'user' => .., 'admin_only' => 0/1]
     * $profiles: user => steam profile row (avatars shown on occupied seats;
     * script.js draws them from the data attributes, sized via getBBox)
     */
    public function renderSeatPlan(string $svg, array $states, string $viewer = '', array $profiles = []): string
    {
        $doc = $this->loadSvg($svg);
        if (!$doc) return '';
        $svgNs = 'http://www.w3.org/2000/svg';

        // buddy spots by host seat: seatId => buddy seat id
        $buddyOf = [];
        foreach ($states as $sid => $st) {
            if (!empty($st['buddy_of'])) $buddyOf[$st['buddy_of']] = $sid;
        }

        $decorate = function (DOMElement $hot, string $seatId, ?array $st) use ($viewer, $profiles) {
            $state = $st['state'] ?? null;
            $cls = 'wl-seat wl-seat-' . ($state ?: (($st['admin_only'] ?? 0) ? 'adminonly' : 'free'));
            if ($st && !empty($st['user']) && $st['user'] === $viewer) $cls .= ' wl-seat-mine';
            $hot->setAttribute('class', $cls);
            $hot->setAttribute('data-seat', $seatId);
            if ($st && !empty($st['user'])) {
                $hot->setAttribute('data-user', $st['user']);
                $hot->setAttribute('data-username', $this->userName($st['user']));
                $hot->setAttribute('data-profile', wl($this->profilePage($st['user'])));
                $avatar = $profiles[$st['user']]['avatar'] ?? '';
                if ($avatar) $hot->setAttribute('data-avatar', $avatar);
            }
        };

        // buddy labels ('A1b') are position markers, not seats of their own:
        // remember where they sit, hide them, and never give them a hotspot —
        // the host renders the pair
        $nodes = $this->findSeatNodes($doc);
        $buddyPos = [];
        foreach ($nodes as $sid => $node) {
            if (!preg_match(self::BUDDY_PATTERN, $sid)) continue;
            if ($node->localName === 'text') {
                $buddyPos[$sid] = [
                    'x' => (float)$node->getAttribute('x') + 10,
                    'y' => (float)$node->getAttribute('y') - 5.5,
                ];
                $node->setAttribute('display', 'none');
            }
            unset($nodes[$sid]);
        }

        foreach ($nodes as $seatId => $node) {
            $st = $states[$seatId] ?? null;
            $buddyId = $buddyOf[$seatId] ?? null;
            $buddySt = $buddyId !== null ? ($states[$buddyId] ?? null) : null;
            $buddyOccupied = $buddySt && !empty($buddySt['user']);

            if ($node->localName === 'text') {
                // 16px monospace label, baseline-anchored: center the hotspot on it
                $x = (float)$node->getAttribute('x') + 10;
                $y = (float)$node->getAttribute('y') - 5.5;
                // buddy geometry comes from the plan itself: solo, the host
                // circle sits at the midpoint of the two labeled spots; with a
                // buddy seated, two full-size circles sit at their own labels
                $bp = $buddyId !== null ? ($buddyPos[$buddyId] ?? ['x' => $x + 32, 'y' => $y]) : null;
                $mid = $bp ? ['x' => ($x + $bp['x']) / 2, 'y' => ($y + $bp['y']) / 2] : null;

                $hot = $doc->createElementNS($svgNs, 'circle');
                $hot->setAttribute('cx', (string)($buddyOccupied || !$mid ? $x : $mid['x']));
                $hot->setAttribute('cy', (string)($buddyOccupied || !$mid ? $y : $mid['y']));
                $hot->setAttribute('r', '15');
                $node->parentNode->insertBefore($hot, $node->nextSibling);
                if ($buddyId !== null) {
                    // script.js re-splits/merges the pair on live state changes
                    $hot->setAttribute('data-buddy', $buddyId);
                    $hot->setAttribute('data-home', $x . ',' . $y);
                    $hot->setAttribute('data-mid', $mid['x'] . ',' . $mid['y']);
                    $hot->setAttribute('data-bpos', $bp['x'] . ',' . $bp['y']);
                    // the visible label follows the circle: centered under the
                    // solo midpoint circle, back at its own spot when paired
                    $node->setAttribute('data-label-for', $seatId);
                    if (!$buddyOccupied) {
                        $this->moveLabel($node, $mid['x'] - 10, $mid['y'] + 5.5);
                    }
                }
                if ($buddyOccupied) {
                    $bhot = $doc->createElementNS($svgNs, 'circle');
                    $bhot->setAttribute('cx', (string)$bp['x']);
                    $bhot->setAttribute('cy', (string)$bp['y']);
                    $bhot->setAttribute('r', '15');
                    $bhot->setAttribute('data-host', $seatId);
                    $node->parentNode->insertBefore($bhot, $hot->nextSibling);
                    $decorate($bhot, $buddyId, $buddySt);
                }
            } else {
                $hot = $node; // explicit mode: the labeled shape is the hit area
                              // (no buddy rendering — needs the computed circle)
            }
            $decorate($hot, $seatId, $st);
        }

        $root = $doc->documentElement;
        $root->removeAttribute('width');
        $root->removeAttribute('height');
        $root->setAttribute('class', 'wl-plan');
        $root->setAttribute('preserveAspectRatio', 'xMidYMid meet');
        return $doc->saveXML($root);
    }

    /** Reposition an SVG text label (Inkscape puts x/y on the tspans too) */
    protected function moveLabel(DOMElement $text, float $x, float $y): void
    {
        $text->setAttribute('x', (string)$x);
        $text->setAttribute('y', (string)$y);
        foreach ($text->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'tspan') {
                $child->setAttribute('x', (string)$x);
                $child->setAttribute('y', (string)$y);
            }
        }
    }

    protected function loadSvg(string $svg): ?DOMDocument
    {
        if ($svg === '') return null;
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) return null;

        // defuse embedded scripting: uploaded media is rendered inline
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('svg', 'http://www.w3.org/2000/svg');
        foreach (iterator_to_array($xp->query('//svg:script')) as $s) {
            $s->parentNode->removeChild($s);
        }
        foreach ($xp->query('//*') as $el) {
            foreach (iterator_to_array($el->attributes) as $attr) {
                if (stripos($attr->name, 'on') === 0) $el->removeAttribute($attr->name);
            }
        }
        return $doc;
    }

    /** @return array seatId => DOMElement (text label or explicitly labeled shape) */
    protected function findSeatNodes(DOMDocument $doc): array
    {
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('svg', 'http://www.w3.org/2000/svg');
        $xp->registerNamespace('inkscape', 'http://www.inkscape.org/namespaces/inkscape');
        $nodes = [];

        // explicit mode first: any shape labeled with a seat code in Inkscape's Objects panel
        foreach ($xp->query('//*[@inkscape:label]') as $el) {
            $label = trim($el->getAttributeNS(
                'http://www.inkscape.org/namespaces/inkscape',
                'label'
            ));
            if (preg_match(self::SEAT_PATTERN, $label) && $el->localName !== 'text') {
                $nodes[$label] = $el;
            }
        }
        // default mode: text labels whose content is a seat code
        foreach ($xp->query('//svg:text') as $t) {
            $label = trim($t->textContent);
            if (preg_match(self::SEAT_PATTERN, $label) && !isset($nodes[$label])) {
                $nodes[$label] = $t;
            }
        }
        return $nodes;
    }

    /** Load the plan SVG media file for a LAN ('' if unset/missing) */
    public function planSvg(array $lan): string
    {
        if (empty($lan['plan_media'])) return '';
        $file = mediaFN(cleanID($lan['plan_media']));
        if (!is_readable($file)) return '';
        return (string)file_get_contents($file);
    }

    /** (Re)seed the seats table from the plan SVG. Returns number of seats found. */
    public function importSeats(array $lan): int
    {
        $found = $this->parseSeatPlan($this->planSvg($lan));
        $db = $this->getDB();
        foreach ($found as $seatId => $pos) {
            // 'A1b' labels mark the buddy spot of seat A1
            $buddyOf = preg_match(self::BUDDY_PATTERN, $seatId, $m) ? $m[1] : null;
            $db->exec(
                "INSERT INTO seats (lan_id, seat_id, svg_ref, buddy_of)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT(lan_id, seat_id) DO UPDATE
                 SET svg_ref = excluded.svg_ref, buddy_of = excluded.buddy_of",
                $lan['id'],
                $seatId,
                round($pos['x'], 1) . ',' . round($pos['y'], 1),
                $buddyOf
            );
        }
        return count($found);
    }

    // ---------------------------------------------------------------- steam links

    public function steamLink(string $user): ?string
    {
        $v = $this->getDB()->queryValue("SELECT steamid64 FROM steam_links WHERE user = ?", $user);
        return $v ?: null;
    }

    public function setSteamLink(string $user, ?string $steamid64): void
    {
        if ($steamid64) {
            $this->getDB()->exec(
                "REPLACE INTO steam_links (user, steamid64, ts) VALUES (?, ?, ?)",
                $user,
                $steamid64,
                time()
            );
        } else {
            $this->getDB()->exec("DELETE FROM steam_links WHERE user = ?", $user);
        }
    }

    /** user => steamid64 for a set of users (or all linked users if $users is null) */
    public function steamLinks(?array $users = null): array
    {
        if ($users === null) {
            return $this->getDB()->queryKeyValueList("SELECT user, steamid64 FROM steam_links");
        }
        if (!$users) return [];
        $ph = implode(',', array_fill(0, count($users), '?'));
        return $this->getDB()->queryKeyValueList(
            "SELECT user, steamid64 FROM steam_links WHERE user IN ($ph)",
            ...$users
        );
    }

    /**
     * Cached Steam profile snapshot per user:
     * user => [steamid64, persona, avatar, avatar_full, profile_ts]
     * (or all linked users if $users is null)
     */
    public function steamProfiles(?array $users = null): array
    {
        $sql = "SELECT user, steamid64, persona, avatar, avatar_full, profile_ts FROM steam_links";
        if ($users === null) {
            $rows = $this->getDB()->queryAll($sql);
        } else {
            if (!$users) return [];
            $ph = implode(',', array_fill(0, count($users), '?'));
            $rows = $this->getDB()->queryAll("$sql WHERE user IN ($ph)", ...$users);
        }
        $out = [];
        foreach ($rows as $r) $out[$r['user']] = $r;
        return $out;
    }

    /** Store a persona/avatar snapshot for an already-linked user */
    public function setSteamProfile(string $user, ?string $persona, ?string $avatar, ?string $avatarFull): void
    {
        $this->getDB()->exec(
            "UPDATE steam_links SET persona = ?, avatar = ?, avatar_full = ?, profile_ts = ?
              WHERE user = ?",
            $persona,
            $avatar,
            $avatarFull,
            time(),
            $user
        );
    }

    /**
     * Steam-friend-style user chip: avatar (or lettered placeholder), persona
     * name if known — else the wiki name — plus an optional status line.
     * $state colors the chip like Steam: '' | 'ingame' | 'online' | 'offline'.
     * The whole chip links to the user's profile page unless $link is false.
     */
    public function userChip(string $user, ?array $profile = null, string $status = '', string $state = '', bool $link = true): string
    {
        $persona = trim((string)($profile['persona'] ?? ''));
        $primary = $persona !== '' ? $persona : $this->userName($user);
        $sub = $status !== '' ? $status : ($persona !== '' ? $this->userName($user) : '');

        $tag = $link ? 'a' : 'span';
        $html = '<' . $tag . ' class="wl-user' . ($state ? ' wl-user-' . hsc($state) : '') . '"';
        if ($link) {
            $html .= ' href="' . wl($this->profilePage($user)) . '"';
        }
        $html .= '>';
        if (!empty($profile['avatar'])) {
            $html .= '<img class="wl-user-avatar" src="' . hsc($profile['avatar'])
                . '" alt="" loading="lazy" width="40" height="40">';
        } else {
            $html .= '<span class="wl-user-avatar wl-user-avatar-ph">'
                . hsc(mb_strtoupper(mb_substr($primary, 0, 1))) . '</span>';
        }
        $html .= '<span class="wl-user-names"><span class="wl-user-primary">' . hsc($primary) . '</span>';
        if ($sub !== '') {
            $html .= '<span class="wl-user-sub">' . hsc($sub) . '</span>';
        }
        $html .= '</span></' . $tag . '>';
        return $html;
    }

    /**
     * Played games of a user, grouped by app with summed hours —
     * one LAN when $lanId is given, lifetime across all LANs otherwise.
     */
    public function gameStats(string $user, ?int $lanId = null): array
    {
        $sql = "SELECT s.appid, a.name,
                       SUM(COALESCE(s.end, s.last_seen) - s.start) AS secs
                  FROM sessions s LEFT JOIN steam_app_cache a ON a.appid = s.appid
                 WHERE s.user = ?" . ($lanId !== null ? " AND s.lan_id = ?" : "") . "
              GROUP BY s.appid ORDER BY secs DESC";
        $args = $lanId !== null ? [$sql, $user, $lanId] : [$sql, $user];
        return $this->getDB()->queryAll(...$args);
    }

    /** LANs a user attended (lan rows, oldest first) */
    public function lansOfUser(string $user): array
    {
        return $this->getDB()->queryAll(
            "SELECT l.* FROM lan_attendees a
               JOIN lans l ON l.id = a.lan_id WHERE a.user = ? ORDER BY l.start",
            $user
        );
    }

    /** Open (now-playing) session per user for a LAN: user => row incl. app name */
    public function nowPlaying(int $lanId): array
    {
        $rows = $this->getDB()->queryAll(
            "SELECT s.user, s.appid, s.start, a.name
               FROM sessions s LEFT JOIN steam_app_cache a ON a.appid = s.appid
              WHERE s.lan_id = ? AND s.end IS NULL",
            $lanId
        );
        $out = [];
        foreach ($rows as $r) $out[$r['user']] = $r;
        return $out;
    }

    // ---------------------------------------------------------------- events (struct-backed)

    /**
     * All event pages of a LAN, merged across languages by neutral id.
     * Returns neutral_pid => [pages => [lang=>pid], data => struct fields, title, start_ts, end_ts]
     */
    public function events(array $lan): array
    {
        $out = [];
        foreach ($this->languages() ?: [''] as $l) {
            $base = ($l ? "$l:" : '') . $lan['namespace'] . ':events';
            foreach ($this->pagesUnder($base) as $pid) {
                // reserved, non-event pages in the namespace: the calendar
                // page itself and the bureaucracy "create event" form
                $rel = substr($pid, strlen($base) + 1);
                if ($rel === 'start' || $rel === 'new') continue;
                $neutral = $this->neutralId($pid);
                if (!isset($out[$neutral])) {
                    $out[$neutral] = ['pages' => [], 'data' => null, 'mtime' => 0];
                }
                $out[$neutral]['pages'][$l] = $pid;
                $mtime = @filemtime(wikiFN($pid)) ?: 0;
                // canonical schedule: most recently saved language variant wins,
                // so a stale translation can't drift the shared schedule
                if ($mtime > $out[$neutral]['mtime']) {
                    $data = $this->structData($pid);
                    if ($data !== null) {
                        $out[$neutral]['data'] = $data;
                        $out[$neutral]['mtime'] = $mtime;
                    }
                }
            }
        }

        foreach ($out as $neutral => &$ev) {
            $local = $this->localPage($neutral);
            $ev['page'] = $local;
            $ev['title'] = $local ? (p_get_first_heading($local) ?: noNS($neutral)) : noNS($neutral);
            $d = $ev['data'] ?? [];
            $ev['start_ts'] = $this->eventStart($d, $this->lanDates($lan)['start']);
            $dur = (int)($d['duration'] ?? 0);
            $ev['end_ts'] = $ev['start_ts'] ? $ev['start_ts'] + $dur * 60 : null;
        }
        unset($ev);

        uasort($out, fn($a, $b) => ($a['start_ts'] ?? PHP_INT_MAX) <=> ($b['start_ts'] ?? PHP_INT_MAX));
        return $out;
    }

    /**
     * Fahrplan-relative event start: day 1 = the edition's start day, 0 and
     * negative numbers are buildup days before it (day N → start + (N-1) days).
     * Absolute dates follow the edition schedule, so rescheduling the LAN
     * moves every event automatically.
     */
    protected function eventStart(array $data, ?int $lanStart): ?int
    {
        $day = trim((string)($data['day'] ?? ''));
        if ($day === '' || !is_numeric($day) || $lanStart === null) return null;
        $time = trim((string)($data['starttime'] ?? '00:00'));
        if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) $time = '00:00';
        $offset = (int)floor((float)$day) - 1;
        $ts = strtotime(
            sprintf('%+d days', $offset),
            strtotime(date('Y-m-d', $lanStart) . " $time")
        );
        return $ts ?: null;
    }

    /**
     * Signup cutoff of an event (fahrplan-relative like eventStart); null
     * when the event has no cutoff. Time defaults to end of the cutoff day.
     */
    public function eventCutoff(array $data, ?int $lanStart): ?int
    {
        $day = trim((string)($data['cutoffday'] ?? ''));
        if ($day === '' || !is_numeric($day) || $lanStart === null) return null;
        $time = trim((string)($data['cutofftime'] ?? ''));
        if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) $time = '23:59';
        $offset = (int)floor((float)$day) - 1;
        $ts = strtotime(
            sprintf('%+d days', $offset),
            strtotime(date('Y-m-d', $lanStart) . " $time")
        );
        return $ts ?: null;
    }

    /** Event price in cents (from the euro Decimal), 0 = free */
    public function priceCents(array $data): int
    {
        $p = trim((string)($data['price'] ?? ''));
        if ($p === '' || !is_numeric(str_replace(',', '.', $p))) return 0;
        return max(0, (int)round((float)str_replace(',', '.', $p) * 100));
    }

    /** Fahrplan day number of a timestamp relative to the edition start day */
    public function dayNumber(int $ts, int $lanStart): int
    {
        $diff = (strtotime(date('Y-m-d', $ts)) - strtotime(date('Y-m-d', $lanStart))) / 86400;
        return (int)round($diff) + 1;
    }

    /** struct 'event' data for one page, null if none */
    public function structData(string $pid, string $schema = 'event'): ?array
    {
        if (!class_exists('\dokuwiki\plugin\struct\meta\AccessTable')) return null;
        try {
            $access = \dokuwiki\plugin\struct\meta\AccessTable::getPageAccess($schema, $pid);
            if (!$access->getSchema()->getId()) return null;
            $arr = $access->getDataArray();
            // struct returns all-empty rows for pages without data; treat as none
            $hasData = false;
            foreach ($arr as $v) {
                if ($v !== '' && $v !== null && $v !== []) { $hasData = true; break; }
            }
            return $hasData ? $arr : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** page ids below a namespace */
    public function pagesUnder(string $ns): array
    {
        global $conf;
        $ns = cleanID($ns);
        $dir = str_replace(':', '/', $ns);
        $data = [];
        if (!is_dir($conf['datadir'] . '/' . utf8_encodeFN($dir))) return [];
        search($data, $conf['datadir'], 'search_allpages', ['skipacl' => true], $dir);
        return array_map(fn($r) => $r['id'], $data);
    }

    // ---------------------------------------------------------------- event signups

    public function signupState(string $neutralPid, string $user): string
    {
        $v = $this->getDB()->queryValue(
            "SELECT state FROM event_signups WHERE event_pid = ? AND user = ?",
            $neutralPid,
            $user
        );
        return $v ?: 'none';
    }

    public function setSignup(string $neutralPid, string $user, string $state, string $comment = ''): void
    {
        if (!in_array($state, ['interested', 'signedup', 'none'])) return;
        if ($state === 'none') {
            $this->getDB()->exec(
                "DELETE FROM event_signups WHERE event_pid = ? AND user = ?",
                $neutralPid,
                $user
            );
        } else {
            // keep an existing paid flag when the user just edits the comment
            $this->getDB()->exec(
                "INSERT INTO event_signups (event_pid, user, state, ts, comment)
                 VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT(event_pid, user) DO UPDATE
                 SET state = excluded.state, ts = excluded.ts, comment = excluded.comment",
                $neutralPid,
                $user,
                $state,
                time(),
                mb_substr(trim($comment), 0, 140)
            );
        }
    }

    /** Full signup rows (user, state, comment, paid, paid_ref) ordered by signup time */
    public function signupRows(string $neutralPid): array
    {
        return $this->getDB()->queryAll(
            "SELECT * FROM event_signups WHERE event_pid = ? ORDER BY ts",
            $neutralPid
        );
    }

    /** Mark a signup paid/unpaid; $ref names the source (tx id or the mod) */
    public function setSignupPaid(string $neutralPid, string $user, bool $paid, string $ref = ''): void
    {
        $this->getDB()->exec(
            "UPDATE event_signups SET paid = ?, paid_ref = ? WHERE event_pid = ? AND user = ?",
            $paid ? 1 : 0,
            $paid ? $ref : null,
            $neutralPid,
            $user
        );
    }

    /** ['signedup' => [users], 'interested' => [users]] */
    public function signups(string $neutralPid): array
    {
        $out = ['signedup' => [], 'interested' => []];
        $rows = $this->getDB()->queryAll(
            "SELECT user, state FROM event_signups WHERE event_pid = ? ORDER BY ts",
            $neutralPid
        );
        foreach ($rows as $r) $out[$r['state']][] = $r['user'];
        return $out;
    }

    // ---------------------------------------------------------------- notices & push queue

    public function addNotice(
        ?int $lanId,
        ?string $user,
        string $kind,
        string $title,
        string $body = '',
        string $link = '',
        string $author = '',
        ?int $expires = null
    ): int {
        $db = $this->getDB();
        $db->exec(
            "INSERT INTO notices (lan_id, user, kind, title, body, link, author, ts, expires)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            $lanId,
            $user,
            $kind,
            $title,
            $body,
            $link,
            $author,
            time(),
            $expires
        );
        return (int)$db->getPdo()->lastInsertId();
    }

    /** Public broadcasts + the user's personal notices, newest first */
    public function noticesFor(string $user, int $sinceId = 0, int $limit = 20): array
    {
        return $this->getDB()->queryAll(
            "SELECT * FROM notices
              WHERE id > ? AND (user IS NULL OR user = ?)
                AND (expires IS NULL OR expires > ?)
              ORDER BY id DESC LIMIT ?",
            $sinceId,
            $user,
            time(),
            $limit
        );
    }

    /**
     * Queue a Web Push for a user. $key makes it idempotent (NULL = always send).
     * Actual delivery happens in the notify-tick cron.
     * Returns true if a new row was queued (false = this key already fired).
     */
    public function queuePush(string $user, ?string $key, array $payload): bool
    {
        try {
            $n = $this->getDB()->exec(
                "INSERT OR IGNORE INTO push_outbox (user, trigger_key, payload, ts) VALUES (?, ?, ?, ?)",
                $user,
                $key,
                json_encode($payload),
                time()
            );
            return $n > 0;
        } catch (\Exception $e) {
            return false; // unique trigger_key race: already queued
        }
    }

    /** Queue a push for every reachable user (web push subscription or Discord DM opt-in) */
    public function queuePushBroadcast(?string $key, array $payload): int
    {
        $users = $this->getDB()->queryAll(
            "SELECT user FROM push_subscriptions
              UNION
             SELECT user FROM discord_links WHERE notify = 1"
        );
        foreach ($users as $u) {
            $this->queuePush($u['user'], $key ? $key . ':' . $u['user'] : null, $payload);
        }
        return count($users);
    }
}
