<?php

use dokuwiki\Extension\CLIPlugin;
use splitbrain\phpcli\Options;

/**
 * WikiLAN CLI: cron dispatcher + install/maintenance commands.
 *
 * System crontab (single line, only does work while a LAN is active):
 *   * * * * *  www-data  flock -n /tmp/wikilan.cron.lock php /var/www/html/bin/plugin.php wikilan cron
 */
class cli_plugin_wikilan extends CLIPlugin
{
    protected function setup(Options $options)
    {
        $options->setHelp('WikiLAN LAN-party manager maintenance & cron');

        $options->registerCommand('cron', 'Run all due periodic jobs (call every minute)');
        $options->registerCommand('install', 'Initialize DB, struct schemas + assignments, VAPID keys');
        $options->registerCommand('lan-create', 'Create a LAN edition');
        $options->registerArgument('namespace', 'neutral namespace, e.g. msl:2026_2', true, 'lan-create');
        $options->registerArgument('title', 'display title', true, 'lan-create');
        $options->registerOption('buildup', 'buildup start "YYYY-MM-DD HH:MM"', null, true, 'lan-create');
        $options->registerOption('start', 'event start "YYYY-MM-DD HH:MM"', null, true, 'lan-create');
        $options->registerOption('end', 'event end/teardown start "YYYY-MM-DD HH:MM"', null, true, 'lan-create');
        $options->registerOption('plan', 'seating plan media id', null, true, 'lan-create');

        $options->registerCommand('lan-dates', 'Set the schedule of an edition');
        $options->registerArgument('namespace', 'edition namespace', true, 'lan-dates');
        $options->registerOption('buildup', 'buildup start "YYYY-MM-DD HH:MM"', null, true, 'lan-dates');
        $options->registerOption('start', 'event start "YYYY-MM-DD HH:MM"', null, true, 'lan-dates');
        $options->registerOption('end', 'event end/teardown start "YYYY-MM-DD HH:MM"', null, true, 'lan-dates');
        $options->registerCommand('lan-state', 'Set edition state (planned|active|archived)');
        $options->registerArgument('namespace', 'edition namespace', true, 'lan-state');
        $options->registerArgument('state', 'new state', true, 'lan-state');
        $options->registerCommand('seats-import', 'Seed seats from the plan SVG text labels');
        $options->registerArgument('namespace', 'edition namespace', true, 'seats-import');
        $options->registerCommand('port-set', 'Map a switch port to seat + IP');
        $options->registerArgument('namespace', 'edition namespace', true, 'port-set');
        $options->registerArgument('port', 'port label', true, 'port-set');
        $options->registerArgument('seat', 'seat id', true, 'port-set');
        $options->registerArgument('ip', 'deterministic client IP', true, 'port-set');
        $options->registerCommand('sync-nowplaying', 'Run the fast now-playing poll once');
        $options->registerCommand('sync-playtime', 'Sync recently-played playtimes once');
        $options->registerCommand('sync-owned', 'Sync owned games for the stalest (or given) user');
        $options->registerArgument('user', 'wiki user', false, 'sync-owned');
        $options->registerCommand('sync-appmeta', 'Resolve pending app metadata');
        $options->registerCommand('push-test', 'Queue and deliver a test push to a user');
        $options->registerArgument('user', 'wiki user', true, 'push-test');
    }

    protected function main(Options $options)
    {
        /** @var helper_plugin_wikilan $wl */
        $wl = plugin_load('helper', 'wikilan');
        /** @var helper_plugin_wikilan_steam $steam */
        $steam = plugin_load('helper', 'wikilan_steam');
        /** @var helper_plugin_wikilan_notify $notify */
        $notify = plugin_load('helper', 'wikilan_notify');

        switch ($options->getCmd()) {
            case 'cron':
                $this->cron($wl, $steam, $notify);
                break;

            case 'install':
                $this->install($wl);
                break;

            case 'lan-create': {
                $args = $options->getArgs();
                $wl->getDB()->exec(
                    "INSERT INTO lans (namespace, title, buildup, start, end, state, plan_media)
                     VALUES (?, ?, ?, ?, ?, 'planned', ?)",
                    cleanID($args[0]),
                    $args[1],
                    $options->getOpt('buildup') ?: null,
                    $options->getOpt('start') ?: null,
                    $options->getOpt('end') ?: null,
                    $options->getOpt('plan') ?: null
                );
                $wl->seedTemplates(cleanID($args[0]));
                $this->success('created ' . $args[0]);
                break;
            }

            case 'lan-dates': {
                $ns = cleanID($options->getArgs()[0]);
                $lan = $wl->lanByNamespace($ns);
                if (!$lan) $this->fatal('unknown edition');
                $db = $wl->getDB();
                foreach (['buildup', 'start', 'end'] as $k) {
                    $v = $options->getOpt($k);
                    if ($v === null || $v === false || $v === '') continue;
                    if (strtotime($v) === false) $this->fatal("cannot parse --$k '$v'");
                    $db->exec("UPDATE lans SET $k = ? WHERE id = ?", $v, $lan['id']);
                }
                $lan = $wl->lanByNamespace($ns);
                $this->success(sprintf(
                    '%s: buildup %s, start %s, end/teardown %s',
                    $ns,
                    $lan['buildup'] ?? '-',
                    $lan['start'] ?? '-',
                    $lan['end'] ?? '-'
                ));
                break;
            }

            case 'lan-state': {
                [$ns, $state] = $options->getArgs();
                if (!in_array($state, ['planned', 'active', 'archived'])) {
                    $this->fatal('bad state');
                }
                $db = $wl->getDB();
                if ($state === 'active') {
                    // only one active edition at a time (§5)
                    $db->exec("UPDATE lans SET state = 'archived' WHERE state = 'active'");
                }
                $db->exec("UPDATE lans SET state = ? WHERE namespace = ?", $state, cleanID($ns));
                $this->success("$ns → $state");
                break;
            }

            case 'seats-import': {
                $lan = $wl->lanByNamespace(cleanID($options->getArgs()[0]));
                if (!$lan) $this->fatal('unknown edition');
                $n = $wl->importSeats($lan);
                $this->success("imported/updated $n seats");
                break;
            }

            case 'port-set': {
                [$ns, $port, $seat, $ip] = $options->getArgs();
                $lan = $wl->lanByNamespace(cleanID($ns));
                if (!$lan) $this->fatal('unknown edition');
                $wl->getDB()->exec(
                    "REPLACE INTO port_seat (lan_id, port_id, seat_id, ip) VALUES (?, ?, ?, ?)",
                    $lan['id'],
                    $port,
                    $seat,
                    $ip
                );
                $this->success("$port → $seat ($ip)");
                break;
            }

            case 'sync-nowplaying':
                $this->info('polled ' . $steam->syncNowPlaying() . ' users');
                break;
            case 'sync-playtime':
                $this->info('synced ' . $steam->syncPlaytime() . ' users');
                break;
            case 'sync-owned': {
                $args = $options->getArgs();
                $user = $steam->syncOwnedNext($args[0] ?? null);
                $this->info($user ? "synced owned games of $user" : 'everyone fresh');
                break;
            }
            case 'sync-appmeta':
                $this->info('resolved ' . $steam->resolveAppMeta() . ' apps');
                break;

            case 'push-test': {
                $user = $options->getArgs()[0];
                $wl->queuePush($user, null, [
                    'title' => 'WikiLAN test',
                    'body' => 'Push works \o/',
                    'url' => DOKU_URL,
                ]);
                $sent = $notify->flushOutbox();
                $this->info("flushed, $sent delivered");
                break;
            }

            default:
                echo $options->help();
        }
    }

    /** Periodic dispatcher: cheap when idle, interval bookkeeping in sqlite opts */
    protected function cron(
        helper_plugin_wikilan $wl,
        helper_plugin_wikilan_steam $steam,
        helper_plugin_wikilan_notify $notify
    ): void {
        $db = $wl->getDB();
        if (!$wl->activeLan()) return; // jobs only run while a LAN is active (§6)
        $now = time();

        // fast now-playing poll + notification tick: every call (~1 min)
        try {
            $steam->syncNowPlaying();
        } catch (\Throwable $e) {
            $this->error('nowplaying: ' . $e->getMessage());
        }
        try {
            [$rem, $chg, $sent] = $notify->tick();
            if ($rem + $chg + $sent) $this->info("notify: $rem reminders, $chg changes, $sent pushes");
        } catch (\Throwable $e) {
            $this->error('notify: ' . $e->getMessage());
        }

        // slower playtime sync
        if ($now - (int)$db->getOpt('last_playtime', 0) >= (int)$this->getConf('steamsync_interval')) {
            $db->setOpt('last_playtime', $now);
            try {
                $steam->syncPlaytime();
            } catch (\Throwable $e) {
                $this->error('playtime: ' . $e->getMessage());
            }
        }

        // background incremental owned-games (one stale user per tick)
        try {
            $steam->syncOwnedNext();
        } catch (\Throwable $e) {
            $this->error('owned: ' . $e->getMessage());
        }

        // lazy app-metadata resolution
        try {
            $steam->resolveAppMeta();
        } catch (\Throwable $e) {
            $this->error('appmeta: ' . $e->getMessage());
        }

        // max-player counts from PCGamingWiki (Steam has none)
        try {
            $steam->resolveMaxPlayers();
        } catch (\Throwable $e) {
            $this->error('maxplayers: ' . $e->getMessage());
        }

        // strichliste payment reconciliation (read-only DB match)
        try {
            $n = plugin_load('helper', 'wikilan_strichliste')->reconcile();
            if ($n) $this->success("$n payment(s) reconciled from strichliste");
        } catch (\Throwable $e) {
            $this->error('strichliste: ' . $e->getMessage());
        }
    }

    protected function install(helper_plugin_wikilan $wl): void
    {
        // 1. DB: instantiating applies migrations
        $wl->getDB();
        $this->success('sqlite schema up to date');

        // 2. VAPID keys
        /** @var helper_plugin_wikilan_push $push */
        $push = plugin_load('helper', 'wikilan_push');
        $push->ensureKeys();
        $this->success('VAPID public key: ' . $push->publicKey());

        // 3. struct schemas + namespace assignments
        if (!class_exists('\dokuwiki\plugin\struct\meta\SchemaImporter')) {
            $this->error('struct plugin not available, skipping schemas');
            return;
        }
        foreach (['event'] as $schema) {
            $json = file_get_contents(__DIR__ . "/struct/$schema.struct.json");
            // importing appends a fresh column set each time; skip when the
            // enabled columns already match to keep the schema history clean
            if ($this->schemaMatches($schema, json_decode($json, true))) {
                $this->success("struct schema '$schema' up to date");
                continue;
            }
            $importer = new \dokuwiki\plugin\struct\meta\SchemaImporter($schema, $json);
            $importer->build();
            $this->success("struct schema '$schema' imported");
        }
        $assignments = \dokuwiki\plugin\struct\meta\Assignments::getInstance();
        $existing = array_map(
            static fn($r) => $r['pattern'] . '|' . $r['tbl'],
            $assignments->getAllPatterns()
        );
        $langs = $wl->languages() ?: [''];
        foreach ($langs as $l) {
            $p = $l ? "$l:" : '';
            // struct wildcards only work as suffix — a mid-pattern star like
            // 'msl:*:events:*' never matches, events need a regex pattern.
            // Drop the broken/stale patterns from earlier installs.
            $assignments->removePattern($p . 'msl:*:events:*', 'event');
            $eventPat = '/^:' . $p . 'msl:[^:]+:events:(?!(start|new)$)[^:]+$/';
            if (!in_array($eventPat . '|event', $existing, true)) {
                $assignments->addPattern($eventPat, 'event');
            }
        }
        $this->success('struct assignments added');

        // 4. namespace templates: skeletons for new user and event pages
        $wl->seedTemplates();
        foreach ($wl->getDB()->queryAll("SELECT namespace FROM lans") as $l) {
            $wl->seedTemplates($l['namespace']);
        }
        $this->success('namespace templates seeded');
    }

    /** Whether the enabled columns (class + label, in order) match the shipped definition */
    protected function schemaMatches(string $schema, array $def): bool
    {
        $s = new \dokuwiki\plugin\struct\meta\Schema($schema);
        if (!$s->getId()) return false;
        $have = [];
        foreach ($s->getColumns() as $col) {
            if (!$col->isEnabled()) continue;
            $class = preg_replace('/.*\\\\/', '', get_class($col->getType()));
            $have[] = $class . ':' . $col->getLabel();
        }
        $want = array_map(
            static fn($c) => $c['class'] . ':' . $c['label'],
            array_values(array_filter($def['columns'], static fn($c) => !empty($c['isenabled'])))
        );
        return $have === $want;
    }
}
