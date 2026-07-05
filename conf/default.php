<?php
/**
 * Default settings for the wikilan plugin
 */

$conf['steamapikey']      = '';
$conf['trusted_proxies']  = '';      // comma-separated IPs allowed to set X-Forwarded-For
$conf['arrival_throttle'] = 60;      // seconds between per-session IP→seat re-checks
$conf['reminder_lead']    = 15;      // minutes before event start to send signup reminders
$conf['notice_poll']      = 30;      // seconds between widget polls for notices
$conf['notice_ttl']       = 7200;    // default seconds until a broadcast expires
$conf['steamsync_interval'] = 300;   // seconds between recently-played playtime syncs
$conf['owned_refresh_days'] = 7;     // days before a user's owned-games list is re-pulled
$conf['appmeta_batch']    = 15;      // appids resolved per cron tick (store API is rate limited)
$conf['push_contact']     = ''; // VAPID subject, e.g. mailto:admin@example.com (default: site host)
$conf['mod_group']        = 'lanmod';
$conf['strichliste_url']  = ''; // strichliste web UI, linked for paying priced events
$conf['strichliste_db_host'] = ''; // read-only MySQL for payment reconciliation;
$conf['strichliste_db_name'] = ''; // empty = reuse the sqlquery plugin's settings
$conf['strichliste_db_user'] = '';
$conf['strichliste_db_pass'] = '';
