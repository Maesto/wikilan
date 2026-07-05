<?php

use dokuwiki\Extension\Plugin;

/**
 * Read-only strichliste reconciliation. The wiki cannot reach the strichliste
 * API (ModSpace-internal), but it has a SELECT-only MySQL account (the same
 * one the sqlquery plugin uses for the strichliste stats page). Payments are
 * matched by: strichliste user name == wiki login (site convention; explicit
 * per-user overrides live in the strichliste_map table, admin page), exact
 * debit amount, and the event's payment code in the transaction comment.
 *
 * The wiki never writes to strichliste — the paid flag lives in wikilan's own
 * event_signups table.
 */
class helper_plugin_wikilan_strichliste extends Plugin
{
    protected $db = null;
    protected $dbFailed = false;

    /** MySQL handle from own conf, falling back to the sqlquery plugin's */
    protected function db(): ?mysqli
    {
        if ($this->db || $this->dbFailed) return $this->db;
        global $conf;
        $c = $conf['plugin']['sqlquery'] ?? [];
        $host = $this->getConf('strichliste_db_host') ?: ($c['Host'] ?? '');
        $name = $this->getConf('strichliste_db_name') ?: ($c['DB'] ?? '');
        $user = $this->getConf('strichliste_db_user') ?: ($c['user'] ?? '');
        $pass = $this->getConf('strichliste_db_pass') ?: ($c['password'] ?? '');
        if ($host === '' || $name === '') {
            $this->dbFailed = true;
            return null;
        }
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $db = new mysqli($host, $user, $pass, $name);
            $db->set_charset('utf8mb4');
            $this->db = $db;
        } catch (\Throwable $e) {
            $this->dbFailed = true;
        }
        return $this->db;
    }

    public function available(): bool
    {
        return $this->db() !== null;
    }

    // ------------------------------------------------- wiki ↔ strichliste names

    /**
     * Strichliste account name for a wiki user. Site convention is
     * "same name", but people who registered differently get an explicit
     * mapping (admin page). Matching in MySQL is case-insensitive anyway.
     */
    public function slName(string $user): string
    {
        $map = $this->mapAll();
        return $map[$user] ?? $user;
    }

    /** All explicit mappings: wiki user => strichliste name */
    public function mapAll(): array
    {
        static $cache = null;
        if ($cache === null) {
            /** @var helper_plugin_wikilan $wl */
            $wl = plugin_load('helper', 'wikilan');
            $cache = $wl->getDB()->queryKeyValueList(
                "SELECT user, sl_name FROM strichliste_map"
            );
        }
        return $cache;
    }

    /** Set (or with empty $slName: remove) a wiki user → strichliste mapping */
    public function setMap(string $user, string $slName): void
    {
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        if (trim($slName) === '') {
            $wl->getDB()->exec("DELETE FROM strichliste_map WHERE user = ?", $user);
        } else {
            $wl->getDB()->exec(
                "REPLACE INTO strichliste_map (user, sl_name) VALUES (?, ?)",
                $user,
                trim($slName)
            );
        }
    }

    /** Reverse lookup: wiki login for a strichliste name, null if unknown */
    public function wikiUserForSl(string $slName): ?string
    {
        foreach ($this->mapAll() as $user => $sl) {
            if (strcasecmp($sl, $slName) === 0) return $user;
        }
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        $known = array_merge(
            $wl->getDB()->queryAll("SELECT DISTINCT user FROM lan_attendees"),
            $wl->getDB()->queryAll("SELECT user FROM steam_links")
        );
        foreach ($known as $r) {
            if (strcasecmp($r['user'], $slName) === 0) return $r['user'];
        }
        return null;
    }

    /** Enabled strichliste account names (for the mapping datalist) */
    public function slUsers(): array
    {
        $db = $this->db();
        if (!$db) return [];
        try {
            return array_column(
                $db->query(
                    "SELECT name FROM user WHERE disabled = 0 ORDER BY name"
                )->fetch_all(MYSQLI_ASSOC),
                'name'
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ------------------------------------------------- buy statistics

    /**
     * Buy statistics of one wiki user (mapping applied): purchase count,
     * items, cents spent, balance, top articles. Optional [$from,$to] unix
     * window. Null when strichliste is unreachable or the account is unknown.
     */
    public function userStats(string $user, int $from = 0, int $to = 0): ?array
    {
        $db = $this->db();
        if (!$db) return null;
        $sl = $this->slName($user);
        try {
            $stmt = $db->prepare("SELECT id, balance FROM user WHERE name = ? LIMIT 1");
            $stmt->bind_param('s', $sl);
            $stmt->execute();
            $u = $stmt->get_result()->fetch_assoc();
            if (!$u) return null;

            $win = $this->windowSql($from, $to);
            $row = $db->query(
                "SELECT COUNT(*) purchases, COALESCE(SUM(t.quantity),0) items,
                        COALESCE(SUM(-t.amount),0) cents
                   FROM transactions t
                  WHERE t.user_id = {$u['id']} AND t.article_id IS NOT NULL
                    AND t.deleted = 0 $win"
            )->fetch_assoc();

            $top = $db->query(
                "SELECT a.name, SUM(t.quantity) qty
                   FROM transactions t JOIN article a ON a.id = t.article_id
                  WHERE t.user_id = {$u['id']} AND t.deleted = 0 $win
               GROUP BY a.name ORDER BY qty DESC LIMIT 5"
            )->fetch_all(MYSQLI_ASSOC);

            return [
                'sl_name' => $sl,
                'balance' => (int)$u['balance'],
                'purchases' => (int)$row['purchases'],
                'items' => (int)$row['items'],
                'cents' => (int)$row['cents'],
                'top' => $top,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Most-bought articles: [name, qty, cents], optional unix window */
    public function topArticles(int $from = 0, int $to = 0, int $limit = 10): array
    {
        $db = $this->db();
        if (!$db) return [];
        try {
            $win = $this->windowSql($from, $to);
            return $db->query(
                "SELECT a.name, SUM(t.quantity) qty, SUM(-t.amount) cents
                   FROM transactions t JOIN article a ON a.id = t.article_id
                  WHERE t.deleted = 0 $win
               GROUP BY a.name ORDER BY qty DESC LIMIT " . (int)$limit
            )->fetch_all(MYSQLI_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Biggest buyers: [name (strichliste), purchases, items, cents] */
    public function topBuyers(int $from = 0, int $to = 0, int $limit = 10): array
    {
        $db = $this->db();
        if (!$db) return [];
        try {
            $win = $this->windowSql($from, $to);
            return $db->query(
                "SELECT u.name, COUNT(*) purchases, SUM(t.quantity) items, SUM(-t.amount) cents
                   FROM transactions t JOIN user u ON u.id = t.user_id
                  WHERE t.article_id IS NOT NULL AND t.deleted = 0 $win
               GROUP BY u.id ORDER BY cents DESC LIMIT " . (int)$limit
            )->fetch_all(MYSQLI_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** AND-clause constraining t.created to a unix window ('' = no window) */
    protected function windowSql(int $from, int $to): string
    {
        $sql = '';
        if ($from > 0) $sql .= " AND t.created >= FROM_UNIXTIME($from)";
        if ($to > 0) $sql .= " AND t.created < FROM_UNIXTIME($to)";
        return $sql;
    }

    /** Payment code users must put in the strichliste transaction comment */
    public function paymentCode(string $neutralPid): string
    {
        return noNS($neutralPid);
    }

    /**
     * All article ids in a product's version chain. Editing a strichliste
     * article creates a NEW row whose precursor_id points at the old one, so
     * a stored product id may be any generation — walk the chain both ways.
     */
    public function articleChain(int $id): array
    {
        $db = $this->db();
        if (!$db || $id <= 0) return [];
        $ids = [$id];
        try {
            for ($i = 0; $i < 20; $i++) {
                $in = implode(',', array_map('intval', $ids));
                $rows = $db->query(
                    "SELECT id, precursor_id FROM article
                      WHERE id IN ($in) OR precursor_id IN ($in)"
                )->fetch_all(MYSQLI_ASSOC);
                $before = count($ids);
                foreach ($rows as $r) {
                    foreach ([(int)$r['id'], (int)$r['precursor_id']] as $x) {
                        if ($x > 0 && !in_array($x, $ids, true)) $ids[] = $x;
                    }
                }
                if (count($ids) === $before) break;
            }
        } catch (\Throwable $e) {
            return [$id];
        }
        return $ids;
    }

    /** Display name of the newest article in the chain, null if unknown */
    public function articleName(int $id): ?string
    {
        $db = $this->db();
        $ids = $this->articleChain($id);
        if (!$db || !$ids) return null;
        try {
            $in = implode(',', array_map('intval', $ids));
            $row = $db->query(
                "SELECT name FROM article WHERE id IN ($in)
                  ORDER BY active DESC, id DESC LIMIT 1"
            )->fetch_row();
            return $row ? (string)$row[0] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Find a purchase of any article in the chain by the named user after
     * $sinceTs (kiosks can't add comments — buying the product IS the
     * payment). Returns the transaction id or null.
     */
    public function findArticlePayment(string $user, array $articleIds, int $sinceTs): ?string
    {
        $db = $this->db();
        if (!$db || !$articleIds) return null;
        try {
            $in = implode(',', array_map('intval', $articleIds));
            $stmt = $db->prepare(
                "SELECT t.id FROM transactions t
                   JOIN user u ON u.id = t.user_id
                  WHERE u.name = ? AND t.article_id IN ($in) AND t.deleted = 0
                    AND t.created >= FROM_UNIXTIME(?)
                  ORDER BY t.id LIMIT 1"
            );
            $stmt->bind_param('si', $user, $sinceTs);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_row();
            return $res ? (string)$res[0] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Find a matching payment: a non-deleted debit of exactly $cents by the
     * named user whose comment contains the code, made after $sinceTs.
     * Returns the transaction id or null.
     */
    public function findPayment(string $user, int $cents, string $code, int $sinceTs): ?string
    {
        $db = $this->db();
        if (!$db || $cents <= 0) return null;
        try {
            $stmt = $db->prepare(
                "SELECT t.id FROM transactions t
                   JOIN user u ON u.id = t.user_id
                  WHERE u.name = ? AND t.amount = ? AND t.deleted = 0
                    AND t.comment LIKE CONCAT('%', ?, '%')
                    AND t.created >= FROM_UNIXTIME(?)
                  ORDER BY t.id LIMIT 1"
            );
            $amount = -$cents;
            $stmt->bind_param('sisi', $user, $amount, $code, $sinceTs);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_row();
            return $res ? (string)$res[0] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Reconcile unpaid signed-up rows of the active edition's priced events.
     * Called from cron; returns the number of newly-marked payments.
     */
    public function reconcile(): int
    {
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        $lan = $wl->activeLan();
        if (!$lan || !$this->available()) return 0;

        $marked = 0;
        foreach ($wl->events($lan) as $neutral => $ev) {
            $data = $ev['data'] ?? [];
            $cents = $wl->priceCents($data);
            $productId = (int)trim((string)($data['productid'] ?? ''));
            if ($cents <= 0 && $productId <= 0) continue;
            $chain = $productId > 0 ? $this->articleChain($productId) : [];
            $code = $this->paymentCode($neutral);
            foreach ($wl->signupRows($neutral) as $row) {
                if ($row['state'] !== 'signedup' || $row['paid']) continue;
                // small grace window before the signup for pre-payers
                $since = (int)$row['ts'] - 86400;
                $slUser = $this->slName($row['user']);
                // article purchase (kiosk flow) first, comment code (web) second
                $tx = $chain ? $this->findArticlePayment($slUser, $chain, $since) : null;
                if ($tx === null && $cents > 0) {
                    $tx = $this->findPayment($slUser, $cents, $code, $since);
                }
                if ($tx !== null) {
                    $wl->setSignupPaid($neutral, $row['user'], true, "strichliste:$tx");
                    $marked++;
                }
            }
        }
        return $marked;
    }
}
