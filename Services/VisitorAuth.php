<?php

namespace Modules\WordPressChat\Services;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Entities\Site;

class VisitorAuth
{
    /** Hours an orphan session is kept (see purgeOrphans()). */
    const ORPHAN_HOURS = 24;

    /** Candidate sessions read per query by purgeOrphans(). */
    const PURGE_BATCH = 500;

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

    /**
     * Deletes orphan sessions: older than ORPHAN_HOURS, with no Access row
     * and no message row. A claim whose request then failed leaves one, as
     * does a continue or a discovery that stopped after its claim. Returns
     * the number deleted.
     *
     * Each one is deleted in its own transaction whose first statement
     * locks its row, the lock every writer of a session's Access and
     * message rows takes first (Ruling C10), and the conditions are checked
     * again under it. A request writing that session's first row right now
     * either commits first, and the session stays, or finds the row gone
     * and answers 401 (SessionGoneException). Nothing in it is expected to
     * fail (Ruling R4).
     */
    public static function purgeOrphans(?Carbon $now = null): int
    {
        $before = ($now ? $now->copy() : now())->subHours(self::ORPHAN_HOURS);
        $deleted = 0;
        $after = 0;

        do {
            $ids = self::orphans($before)
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit(self::PURGE_BATCH)
                ->pluck('id')
                ->all();

            foreach ($ids as $id) {
                $deleted += DB::transaction(function () use ($id, $before) {
                    if (!Session::where('id', $id)->lockForUpdate()->first()
                        || !self::orphans($before)->where('id', $id)->exists()
                    ) {
                        return 0;
                    }

                    Session::where('id', $id)->delete();

                    return 1;
                });

                $after = (int) $id;
            }
        } while (count($ids) === self::PURGE_BATCH);

        return $deleted;
    }

    /** Sessions created before $before without any Access or message row. */
    private static function orphans(Carbon $before)
    {
        return Session::where('created_at', '<', $before)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('wordpresschat_access')
                    ->whereColumn('wordpresschat_access.session_id', 'wordpresschat_sessions.id');
            })
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('wordpresschat_messages')
                    ->whereColumn('wordpresschat_messages.session_id', 'wordpresschat_sessions.id');
            });
    }

    private static function findByHash(Site $site, string $hash)
    {
        return Session::where('site_id', $site->id)
            ->where('token_hash', $hash)
            ->first();
    }
}
