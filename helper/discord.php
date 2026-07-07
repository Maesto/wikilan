<?php

use dokuwiki\Extension\Plugin;
use dokuwiki\HTTP\DokuHTTPClient;

/**
 * Discord integration: OAuth2 account linking (identify scope) and DM
 * delivery of push notifications through the configured bot.
 *
 * Linking mirrors the Steam flow (action/discordauth.php); the link row
 * stores id + names + avatar hash and a notify flag that reroutes the
 * user's push_outbox entries to a Discord DM instead of web push
 * (helper/notify.php). DMs require the bot to share a server with the
 * user and the user to accept DMs from server members.
 */
class helper_plugin_wikilan_discord extends Plugin
{
    protected const API = 'https://discord.com/api/v10';

    /** @var helper_plugin_wikilan */
    protected $wl;

    public function __construct()
    {
        $this->wl = plugin_load('helper', 'wikilan');
    }

    public function configured(): bool
    {
        return trim((string)$this->getConf('discordclientid')) !== ''
            && trim((string)$this->getConf('discordclientkey')) !== '';
    }

    /** fixed OAuth2 redirect target — must be registered in the Discord app */
    public function redirectUri(): string
    {
        return DOKU_URL . 'doku.php?do=wikilan_discordcb';
    }

    // ---------------------------------------------------------------- links

    public function link(string $user): ?array
    {
        return $this->wl->getDB()->queryRecord(
            "SELECT * FROM discord_links WHERE user = ?", $user
        ) ?: null;
    }

    /** user => link row for a set of users (or all when null) */
    public function links(?array $users = null): array
    {
        if ($users === null) {
            $rows = $this->wl->getDB()->queryAll("SELECT * FROM discord_links");
        } else {
            if (!$users) return [];
            $ph = implode(',', array_fill(0, count($users), '?'));
            $rows = $this->wl->getDB()->queryAll(
                "SELECT * FROM discord_links WHERE user IN ($ph)", ...$users
            );
        }
        $out = [];
        foreach ($rows as $r) $out[$r['user']] = $r;
        return $out;
    }

    public function set(string $user, ?array $me): void
    {
        if ($me === null) {
            $this->wl->getDB()->exec("DELETE FROM discord_links WHERE user = ?", $user);
            return;
        }
        $this->wl->getDB()->exec(
            "REPLACE INTO discord_links (user, discord_id, username, global_name, avatar, notify, dm_channel, ts)
             VALUES (?, ?, ?, ?, ?,
                     COALESCE((SELECT notify FROM discord_links WHERE user = ?), 0),
                     COALESCE((SELECT dm_channel FROM discord_links WHERE user = ?), ''),
                     ?)",
            $user,
            (string)$me['id'],
            (string)($me['username'] ?? ''),
            (string)($me['global_name'] ?? ''),
            (string)($me['avatar'] ?? ''),
            $user,
            $user,
            time()
        );
    }

    public function setNotify(string $user, bool $on): void
    {
        $this->wl->getDB()->exec(
            "UPDATE discord_links SET notify = ? WHERE user = ?", (int)$on, $user
        );
    }

    /** display name of a link row */
    public function displayName(array $link): string
    {
        return $link['global_name'] !== '' ? $link['global_name'] : $link['username'];
    }

    public function avatarUrl(array $link, int $size = 64): ?string
    {
        if ($link['avatar'] === '') return null;
        return 'https://cdn.discordapp.com/avatars/' . $link['discord_id'] . '/'
            . $link['avatar'] . '.png?size=' . $size;
    }

    // ---------------------------------------------------------------- OAuth2

    public function authorizeUrl(string $state): string
    {
        return 'https://discord.com/oauth2/authorize?' . buildURLparams([
            'client_id' => trim((string)$this->getConf('discordclientid')),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'scope' => 'identify',
            'state' => $state,
        ], '&');
    }

    /** code → /users/@me identity array, or null on any failure */
    public function identify(string $code): ?array
    {
        $http = new DokuHTTPClient();
        $http->timeout = 15;
        $resp = $http->post(self::API . '/oauth2/token', [
            'client_id' => trim((string)$this->getConf('discordclientid')),
            'client_secret' => trim((string)$this->getConf('discordclientkey')),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ]);
        if ($resp === false) return null;
        $token = json_decode($resp, true)['access_token'] ?? null;
        if (!$token) return null;

        $http = new DokuHTTPClient();
        $http->timeout = 15;
        $http->headers['Authorization'] = 'Bearer ' . $token;
        $resp = $http->get(self::API . '/users/@me');
        if ($resp === false) return null;
        $me = json_decode($resp, true);
        return !empty($me['id']) ? $me : null;
    }

    // ---------------------------------------------------------------- bot DMs

    /** authenticated bot request; returns decoded json or null */
    protected function bot(string $method, string $path, ?array $body = null): ?array
    {
        $token = trim((string)$this->getConf('discordbottoken'));
        if ($token === '') return null;
        $http = new DokuHTTPClient();
        $http->timeout = 15;
        $http->headers['Authorization'] = 'Bot ' . $token;
        $http->headers['Content-Type'] = 'application/json';
        $ok = $http->sendRequest(
            self::API . $path,
            $body === null ? '' : json_encode($body),
            $method
        );
        if (!$ok) return null;
        return json_decode($http->resp_body, true) ?: null;
    }

    /**
     * DM a linked user; caches the DM channel id on first success.
     * Returns true when Discord accepted the message.
     */
    public function sendDM(array $link, string $content): bool
    {
        $channel = $link['dm_channel'];
        if ($channel === '') {
            $resp = $this->bot('POST', '/users/@me/channels', [
                'recipient_id' => $link['discord_id'],
            ]);
            $channel = (string)($resp['id'] ?? '');
            if ($channel === '') return false;
            $this->wl->getDB()->exec(
                "UPDATE discord_links SET dm_channel = ? WHERE user = ?",
                $channel, $link['user']
            );
        }
        $resp = $this->bot('POST', '/channels/' . $channel . '/messages', [
            'content' => $content,
        ]);
        return !empty($resp['id']);
    }

    /** payload (title/body/url) → DM text */
    public function formatPayload(array $payload): string
    {
        $parts = ['**' . ($payload['title'] ?? '') . '**'];
        if (!empty($payload['body'])) $parts[] = $payload['body'];
        if (!empty($payload['url'])) $parts[] = '<' . $payload['url'] . '>';
        return trim(implode("\n", $parts));
    }
}
