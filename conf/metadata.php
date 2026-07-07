<?php
/**
 * Options for the wikilan plugin
 */

$meta['steamapikey']      = ['string'];
$meta['trusted_proxies']  = ['string'];
$meta['arrival_throttle'] = ['numeric', '_min' => 0];
$meta['reminder_lead']    = ['numeric', '_min' => 1];
$meta['notice_poll']      = ['numeric', '_min' => 5];
$meta['notice_ttl']       = ['numeric', '_min' => 60];
$meta['steamsync_interval'] = ['numeric', '_min' => 60];
$meta['owned_refresh_days'] = ['numeric', '_min' => 1];
$meta['appmeta_batch']    = ['numeric', '_min' => 1];
$meta['push_contact']     = ['string'];
$meta['mod_group']        = ['string'];
$meta['strichliste_url']  = ['string'];
$meta['strichliste_db_host'] = ['string'];
$meta['strichliste_db_name'] = ['string'];
$meta['strichliste_db_user'] = ['string'];
$meta['strichliste_db_pass'] = ['password'];
$meta['discordclientid']  = ['string'];
$meta['discordclientkey'] = ['password'];
$meta['discordbottoken']  = ['password'];
