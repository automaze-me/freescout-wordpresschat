<?php

namespace Modules\WordPressChat\Services;

use Modules\WordPressChat\Entities\Site;

/**
 * Opens identity tokens taken from request input and checks the fields of
 * their type. IdentityToken::open() checks only the common envelope; a
 * payload that is authentic but malformed (a plugin bug) must not reach the
 * database. Every failure is null, so the API answers one 403 for all of
 * them.
 *
 * Call these before any DB::transaction starts: open() consumes the token's
 * jti with an insert whose unique violation is caught (Ruling R4).
 */
class IdentityPayload
{
    /** wp_user_id is a 4-byte integer; PostgreSQL rejects a larger bound value. */
    const MAX_UID = 2147483647;

    const MAX_EMAIL_LENGTH = 191;
    const MAX_NAME_LENGTH = 100;
    const MAX_NUMBER_LENGTH = 32;

    /**
     * A login token's payload as ['uid' => int, 'email' => string,
     * 'name' => string], or null. The email is in the sanitised form
     * FreeScout stores; the name may be ''.
     */
    public static function login(Site $site, $token, string $bindHash): ?array
    {
        $payload = self::open($site, $token, 'login', $bindHash);

        if ($payload === null) {
            return null;
        }

        $uid = $payload['uid'] ?? null;
        if (!is_int($uid) || $uid < 1 || $uid > self::MAX_UID) {
            return null;
        }

        $email = isset($payload['email']) && is_string($payload['email']) ? self::cleanEmail($payload['email']) : null;
        $name = isset($payload['name']) && is_string($payload['name']) ? self::cleanName($payload['name']) : null;

        if ($email === null || $name === null) {
            return null;
        }

        return ['uid' => $uid, 'email' => $email, 'name' => $name];
    }

    /**
     * An order token's payload as ['num' => string, 'found' => bool,
     * 'email' => ?string, 'name' => string], or null. A found order must
     * carry a valid email; null, '' or an invalid one makes the payload
     * malformed (WordPress reports an order without a billing email as not
     * found). A not-found order's email is dropped.
     */
    public static function order(Site $site, $token, string $bindHash): ?array
    {
        $payload = self::open($site, $token, 'order', $bindHash);

        if ($payload === null) {
            return null;
        }

        $num = $payload['num'] ?? null;
        if (!is_string($num)) {
            return null;
        }
        // 1-32 characters, no control characters, and no ':', so the
        // ":number" and ":name" placeholders of the line item stay safe.
        // WordPress sends the visitor's own input for an order it could not
        // resolve, cleaned the same way.
        $num = trim($num);
        $length = mb_strlen($num, 'UTF-8');
        if ($length < 1 || $length > self::MAX_NUMBER_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/', $num)
            || strpos($num, ':') !== false
        ) {
            return null;
        }

        $found = $payload['found'] ?? null;
        if (!is_bool($found)) {
            return null;
        }

        $email = $payload['email'] ?? null;
        $name = $payload['name'] ?? null;
        if (($email !== null && !is_string($email)) || ($name !== null && !is_string($name))) {
            return null;
        }

        $name = $name === null ? '' : self::cleanName($name);
        if ($name === null) {
            return null;
        }

        if (!$found) {
            $email = null;
        } else {
            $email = $email === null ? null : self::cleanEmail($email);

            if ($email === null) {
                return null;
            }
        }

        return ['num' => $num, 'found' => $found, 'email' => $email, 'name' => $name];
    }

    /**
     * A ping token's payload as ['origin' => string], or null. A ping is not
     * tied to a visitor, so it is bound to 64 zeros. The origin is as the
     * plugin sent it; the caller normalizes it.
     */
    public static function ping(Site $site, $token): ?array
    {
        $payload = self::open($site, $token, 'ping', str_repeat('0', 64));

        if ($payload === null) {
            return null;
        }

        $origin = $payload['origin'] ?? null;
        if (!is_string($origin) || strlen($origin) > 255) {
            return null;
        }

        return ['origin' => $origin];
    }

    /**
     * $raw in the form FreeScout stores it (lower case), or null when it is
     * not a valid address or when sanitising would rewrite it: linking an
     * address nobody gave is worse than rejecting it.
     */
    public static function cleanEmail(string $raw): ?string
    {
        if (!mb_check_encoding($raw, 'UTF-8') || mb_strlen($raw, 'UTF-8') > self::MAX_EMAIL_LENGTH) {
            return null;
        }

        $clean = \App\Email::sanitizeEmail($raw);

        if (!$clean || $clean !== mb_strtolower($raw, 'UTF-8') || !filter_var($clean, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $clean;
    }

    /**
     * Control characters become spaces (PostgreSQL rejects a NUL byte in a
     * text column), then the name is trimmed. Null when it is too long.
     */
    private static function cleanName(string $name): ?string
    {
        if (mb_strlen($name, 'UTF-8') > self::MAX_NAME_LENGTH) {
            return null;
        }

        return trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $name));
    }

    /**
     * Input is checked before IdentityToken::open(), whose string parameter
     * would turn anything else into a TypeError.
     */
    private static function open(Site $site, $token, string $type, string $bindHash): ?array
    {
        if (!is_string($token) || $token === '' || strlen($token) > IdentityToken::MAX_TOKEN_LENGTH) {
            return null;
        }

        return IdentityToken::open($site, $token, $type, $bindHash);
    }
}
