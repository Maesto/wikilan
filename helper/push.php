<?php

use dokuwiki\Extension\Plugin;

/**
 * Web Push delivery: VAPID (RFC 8292, ES256) + aes128gcm payload encryption
 * (RFC 8291), implemented on OpenSSL — no composer dependencies.
 *
 * VAPID keys are generated once and stored in the plugin's SQLite opts.
 */
class helper_plugin_wikilan_push extends Plugin
{
    protected const CURVE_PEM_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    protected ?helper_plugin_wikilan $wl = null;

    protected function wl(): helper_plugin_wikilan
    {
        if (!$this->wl) $this->wl = plugin_load('helper', 'wikilan');
        return $this->wl;
    }

    // ---------------------------------------------------------------- keys

    /** VAPID public key (base64url, uncompressed P-256 point) — generated on first use */
    public function publicKey(): string
    {
        $this->ensureKeys();
        return (string)$this->wl()->getDB()->getOpt('vapid_public');
    }

    public function ensureKeys(bool $regenerate = false): void
    {
        $db = $this->wl()->getDB();
        if (!$regenerate && $db->getOpt('vapid_public') && $db->getOpt('vapid_private')) return;

        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if (!$key) throw new \RuntimeException('EC keygen failed: ' . openssl_error_string());
        openssl_pkey_export($key, $pem);
        $details = openssl_pkey_get_details($key);
        $point = "\x04"
            . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        $db->setOpt('vapid_private', $pem);
        $db->setOpt('vapid_public', self::b64url($point));
    }

    // ---------------------------------------------------------------- send

    /**
     * Send one push message. Returns the HTTP status code (0 on transport error).
     * 404/410 mean the subscription is dead and should be deleted by the caller.
     */
    public function send(array $subscription, array $payload, int $ttl = 3600): int
    {
        $endpoint = $subscription['endpoint'];
        $body = $this->encrypt(
            json_encode($payload),
            self::b64urlDecode($subscription['p256dh']),
            self::b64urlDecode($subscription['auth'])
        );

        $parts = parse_url($endpoint);
        $aud = $parts['scheme'] . '://' . $parts['host'];
        $jwt = $this->vapidJwt($aud);

        // DokuHTTPClient instead of curl: php-curl is not a DokuWiki
        // requirement and is missing on minimal installs
        $http = new \dokuwiki\HTTP\DokuHTTPClient();
        $http->timeout = 15;
        $http->keep_alive = false;
        $http->headers['Content-Type'] = 'application/octet-stream';
        $http->headers['Content-Encoding'] = 'aes128gcm';
        $http->headers['TTL'] = (string)$ttl;
        $http->headers['Urgency'] = 'normal';
        $http->headers['Authorization'] = 'vapid t=' . $jwt . ', k=' . $this->publicKey();
        $http->sendRequest($endpoint, $body, 'POST');
        return (int)$http->status;
    }

    // ---------------------------------------------------------------- VAPID JWT (ES256)

    protected function vapidJwt(string $aud): string
    {
        $this->ensureKeys();
        $priv = openssl_pkey_get_private(
            (string)$this->wl()->getDB()->getOpt('vapid_private')
        );

        $header = self::b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64url(json_encode([
            'aud' => $aud,
            'exp' => time() + 12 * 3600,
            'sub' => $this->getConf('push_contact') ?: 'mailto:admin@' . parse_url(DOKU_URL, PHP_URL_HOST),
        ]));
        $signing = $header . '.' . $claims;
        openssl_sign($signing, $der, $priv, OPENSSL_ALGO_SHA256);
        return $signing . '.' . self::b64url(self::derToRaw($der));
    }

    /** DER ECDSA-Sig-Value → raw 64-byte r||s as JWT expects */
    protected static function derToRaw(string $der): string
    {
        // walk: 0x30 len 0x02 rlen r 0x02 slen s (len is short-form for P-256 sigs)
        $off = 2;
        $rl = ord($der[$off + 1]);
        $r = substr($der, $off + 2, $rl);
        $off = $off + 2 + $rl;
        $sl = ord($der[$off + 1]);
        $s = substr($der, $off + 2, $sl);
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
        return $r . $s;
    }

    // ---------------------------------------------------------------- RFC 8291 encryption

    /**
     * aes128gcm content encoding for Web Push: single record, ephemeral ECDH key.
     * $uaPublic = 65-byte uncompressed client point, $authSecret = 16 bytes.
     */
    public function encrypt(string $plaintext, string $uaPublic, string $authSecret): string
    {
        if (strlen($plaintext) > 3993) {
            $plaintext = substr($plaintext, 0, 3993); // fit a single 4096 record
        }

        $eph = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $det = openssl_pkey_get_details($eph);
        $asPublic = "\x04"
            . str_pad($det['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            . str_pad($det['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        $peer = openssl_pkey_get_public(self::pointToPem($uaPublic));
        if (!$peer) throw new \RuntimeException('bad p256dh key');
        $shared = openssl_pkey_derive($peer, $eph, 32);
        if ($shared === false) throw new \RuntimeException('ECDH failed');

        $salt = random_bytes(16);
        $keyInfo = "WebPush: info\x00" . $uaPublic . $asPublic;
        $ikm = hash_hkdf('sha256', $shared, 32, $keyInfo, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext . "\x02",     // 0x02 = last-record padding delimiter
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        // coding header: salt(16) | rs(4) | idlen(1) | keyid(as_public, 65)
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $ciphertext . $tag;
    }

    /** Raw uncompressed P-256 point → PEM SubjectPublicKeyInfo */
    protected static function pointToPem(string $point): string
    {
        $der = hex2bin(self::CURVE_PEM_PREFIX) . $point;
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    public static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $data): string
    {
        return (string)base64_decode(strtr($data, '-_', '+/'));
    }
}
