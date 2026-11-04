<?php

namespace Modules\WordPressChat\Services;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Entities\TokenUse;

/**
 * Identity tokens: small encrypted statements the WordPress plugin
 * (LCFF_Crypto) seals for one visitor, such as "logged in as account X" or
 * "order number N belongs to customer Y".
 *
 * Scheme, identical on both sides:
 * - key: HKDF-SHA256 of the site's secret string, info "lcff identity v1";
 * - AES-256-GCM, random 12-byte IV, 16-byte tag, AAD "lcff identity v1|<site public id>";
 * - plaintext: the JSON payload, right-padded with spaces to 512 bytes for
 *   orders and to the next multiple of 256 bytes otherwise, so the token's
 *   length says little about its content;
 * - token: "v1." . base64url without padding of (IV . ciphertext . tag).
 */
class IdentityToken
{
    /** Longest accepted lifetime, exp - iat, in seconds. */
    const MAX_AGE = 300;

    /** How far iat may lie in the future (clock skew), in seconds. */
    const SKEW = 60;

    const PREFIX = 'v1.';
    const INFO = 'lcff identity v1';
    const CIPHER = 'aes-256-gcm';
    const IV_BYTES = 12;
    const TAG_BYTES = 16;
    const ORDER_BYTES = 512;
    const BLOCK_BYTES = 256;

    /** Longer tokens are rejected unread. A 512-byte plaintext makes a 723-character token. */
    const MAX_TOKEN_LENGTH = 16384;

    /** A consumed jti is kept this long after the token expired. */
    const KEEP_AFTER_EXPIRY = 60;

    /** token_uses rows whose expires_at is older than this are purged. */
    const PURGE_AFTER = 86400;

    const PURGE_BATCH = 100;

    /**
     * Seals $payload for the site with this secret and public id. $iv exists
     * for the shared test vectors only; real tokens always get a random one.
     *
     * @throws \InvalidArgumentException on a payload that can't be encoded,
     *         an order payload over 512 bytes or an IV that isn't 12 bytes.
     */
    public static function seal(array $payload, string $secret, string $sitePublicId, ?string $iv = null): string
    {
        $iv = $iv === null ? random_bytes(self::IV_BYTES) : $iv;
        if (strlen($iv) !== self::IV_BYTES) {
            throw new \InvalidArgumentException('The IV must be 12 bytes.');
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \InvalidArgumentException('The payload cannot be encoded as JSON.');
        }

        if (($payload['typ'] ?? null) === 'order') {
            if (strlen($json) > self::ORDER_BYTES) {
                throw new \InvalidArgumentException('An order payload must fit in 512 bytes.');
            }
            $size = self::ORDER_BYTES;
        } else {
            $size = (int) ceil(strlen($json) / self::BLOCK_BYTES) * self::BLOCK_BYTES;
        }

        $tag = '';
        $ciphertext = openssl_encrypt(
            str_pad($json, $size, ' '),
            self::CIPHER,
            self::key($secret),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::aad($sitePublicId),
            self::TAG_BYTES
        );
        if (!is_string($ciphertext)) {
            throw new \RuntimeException('Encryption failed.');
        }

        return self::PREFIX.rtrim(strtr(base64_encode($iv.$ciphertext.$tag), '+/', '-_'), '=');
    }

    /**
     * The payload of $token when it is authentic for $site, of type $type,
     * bound to the chat token whose SHA-256 hex is $bindHash, fresh at $now
     * (default: time()) and never used before. Consumes its jti then.
     * Returns null on any failure and never throws; why a token failed is
     * deliberately not exposed, and token contents are never logged.
     *
     * Callers must NOT wrap this in a DB::transaction: the jti insert may
     * fail on a unique violation, and on PostgreSQL a failed statement
     * aborts the transaction it sits in (Ruling R4). Call it before your
     * own transaction starts.
     */
    public static function open(Site $site, string $token, string $type, string $bindHash, ?int $now = null): ?array
    {
        try {
            $now = $now === null ? time() : $now;

            $payload = self::decrypt($site, $token);
            if ($payload === null || !self::valid($payload, $site, $type, $bindHash, $now)) {
                return null;
            }

            if (!self::consume($payload['jti'], $payload['exp'], $now)) {
                return null;
            }

            return $payload;
        } catch (\Throwable $e) {
            // The class only: a message could quote token contents.
            \Log::warning('WordPressChat: identity token check failed ('.get_class($e).').');

            return null;
        }
    }

    private static function decrypt(Site $site, string $token): ?array
    {
        if (strlen($token) > self::MAX_TOKEN_LENGTH || strncmp($token, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return null;
        }

        $body = substr($token, strlen(self::PREFIX));
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $body)) {
            return null;
        }

        $raw = base64_decode(strtr($body, '-_', '+/'), true);
        if (!is_string($raw) || strlen($raw) <= self::IV_BYTES + self::TAG_BYTES) {
            return null;
        }

        // Only now, so malformed tokens never trigger the unreadable-secret warning.
        $secret = $site->secretOrNull();
        if ($secret === null) {
            return null;
        }

        $plain = openssl_decrypt(
            substr($raw, self::IV_BYTES, -self::TAG_BYTES),
            self::CIPHER,
            self::key($secret),
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_BYTES),
            substr($raw, -self::TAG_BYTES),
            self::aad((string) $site->public_id)
        );
        if (!is_string($plain)) {
            return null;
        }

        $payload = json_decode(rtrim($plain, ' '), true);

        return is_array($payload) ? $payload : null;
    }

    private static function valid(array $payload, Site $site, string $type, string $bindHash, int $now): bool
    {
        foreach (['typ', 'sid', 'bind', 'jti'] as $key) {
            if (!isset($payload[$key]) || !is_string($payload[$key])) {
                return false;
            }
        }
        foreach (['iat', 'exp'] as $key) {
            if (!isset($payload[$key]) || !is_int($payload[$key])) {
                return false;
            }
        }

        return $payload['typ'] === $type
            && hash_equals((string) $site->public_id, $payload['sid'])
            && preg_match('/^[0-9a-f]{64}$/', $payload['bind'])
            && hash_equals($bindHash, $payload['bind'])
            && $payload['iat'] <= $now + self::SKEW
            && $payload['exp'] >= $now
            && $payload['exp'] - $payload['iat'] <= self::MAX_AGE
            && preg_match('/^[0-9a-f]{32}$/', $payload['jti']);
    }

    /**
     * Records the jti as used; false when it already was. The lookup first
     * keeps the common replay from ever reaching a failing INSERT; the
     * unique index stays the guard against two concurrent requests.
     */
    private static function consume(string $jti, int $exp, int $now): bool
    {
        if (TokenUse::where('jti', $jti)->exists()) {
            return false;
        }

        try {
            TokenUse::create([
                'jti'        => $jti,
                'expires_at' => Carbon::createFromTimestamp($exp + self::KEEP_AFTER_EXPIRY),
            ]);
        } catch (QueryException $e) {
            // SQLSTATE class 23 (integrity constraint): a concurrent request
            // consumed the jti first. Anything else is worth a warning.
            if (strpos((string) $e->getCode(), '23') !== 0) {
                \Log::warning('WordPressChat: could not record an identity token use (SQLSTATE '.$e->getCode().').');
            }

            return false;
        }

        self::purge($now);

        return true;
    }

    /**
     * Deletes up to PURGE_BATCH rows that expired more than a day before
     * $now, by the same clock as the checks. Ids are selected first because
     * PostgreSQL's query grammar ignores a LIMIT on DELETE.
     */
    private static function purge(int $now): void
    {
        try {
            $ids = TokenUse::where('expires_at', '<', Carbon::createFromTimestamp($now - self::PURGE_AFTER))
                ->orderBy('id')
                ->limit(self::PURGE_BATCH)
                ->pluck('id')
                ->all();

            if ($ids) {
                TokenUse::whereIn('id', $ids)->delete();
            }
        } catch (\Throwable $e) {
            // The token itself is fine; a failed cleanup is retried next time.
            \Log::warning('WordPressChat: could not purge old identity token uses ('.get_class($e).').');
        }
    }

    private static function key(string $secret): string
    {
        return hash_hkdf('sha256', $secret, 32, self::INFO, '');
    }

    private static function aad(string $sitePublicId): string
    {
        return self::INFO.'|'.$sitePublicId;
    }
}
