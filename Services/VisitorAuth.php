<?php

namespace Modules\WordPressChat\Services;

use Illuminate\Database\QueryException;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Entities\Site;

class VisitorAuth
{
    /**
     * The session for a token the widget generated itself and sent as
     * `new_token` with its first message. Only the token's SHA-256 hash is
     * stored, so the server could never hand the same token back on a retry;
     * because the client picks it, a retry of a first message whose response
     * was lost lands in the same session again.
     *
     * Returns null when the token's hash already belongs to another site.
     *
     * The insert runs outside any DB::transaction: on PostgreSQL a failed
     * statement aborts the transaction it sits in, so the re-read after a
     * unique violation must not share one with it (Ruling R4).
     */
    public static function claim(Site $site, string $token): ?Session
    {
        $hash = hash('sha256', $token);

        // token_hash is unique across all sites. One lookup by hash covers
        // both "a retry of a lost first response" (same site) and "this
        // token is taken elsewhere" (other site), so the insert below only
        // fails on a genuinely concurrent claim.
        $existing = Session::where('token_hash', $hash)->first();

        if ($existing) {
            return (int) $existing->site_id === (int) $site->id ? $existing : null;
        }

        try {
            return Session::create([
                'site_id'    => $site->id,
                'public_id'  => bin2hex(random_bytes(16)),
                'token_hash' => $hash,
            ]);
        } catch (QueryException $e) {
            // A concurrent request with the same token won the insert.
            $winner = self::findByHash($site, $hash);

            if ($winner) {
                return $winner;
            }

            // SQLSTATE class 23: integrity constraint violation, i.e. the
            // hash was claimed for another site meanwhile.
            if (strpos((string) $e->getCode(), '23') === 0) {
                return null;
            }

            throw $e;
        }
    }

    public static function resolve(Site $site, $token)
    {
        if (!is_string($token) || $token === '') {
            return null;
        }

        return self::findByHash($site, hash('sha256', $token));
    }

    private static function findByHash(Site $site, string $hash)
    {
        return Session::where('site_id', $site->id)
            ->where('token_hash', $hash)
            ->first();
    }
}
