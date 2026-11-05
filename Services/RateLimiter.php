<?php

namespace Modules\WordPressChat\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Fixed-window counters in `wordpresschat_rate_limits`, one row per
 * window: the bucket is hash(scope|key|window index). The counters live in
 * the database, so a limit holds across every PHP worker and app server.
 *
 * Every attempt counts, refused ones too: a client that keeps going past
 * its limit keeps its window full instead of earning a fresh allowance.
 */
class RateLimiter
{
    const TABLE = 'wordpresschat_rate_limits';

    /**
     * The bucket of $scope and $key for the window that holds $now
     * (default: the current time).
     */
    public static function bucket(string $scope, string $key, int $window, ?int $now = null): string
    {
        $now = $now === null ? time() : $now;

        return hash('sha256', $scope.'|'.$key.'|'.(int) floor($now / $window));
    }

    /**
     * Counts $amount against $scope and $key in the current window of
     * $window seconds. Null when the window's total is still at most $max,
     * else the seconds until the window ends (at least 1).
     *
     * Runs outside any transaction: on PostgreSQL the failed insert of a
     * concurrent first hit would abort the transaction it sits in (Ruling
     * R4). The increment comes first, so only the very first hits of a
     * window ever try an insert.
     */
    public static function hit(string $scope, string $key, int $max, int $window, int $amount = 1): ?int
    {
        $now = time();
        $bucket = self::bucket($scope, $key, $window, $now);
        $end = ((int) floor($now / $window) + 1) * $window;

        // Nothing to add (an upload rule's non-file): only the total counts.
        if ($amount > 0 && !self::increment($bucket, $amount)) {
            try {
                DB::table(self::TABLE)->insert([
                    'bucket'     => $bucket,
                    'hits'       => $amount,
                    'expires_at' => date('Y-m-d H:i:s', $end),
                ]);
            } catch (QueryException $e) {
                // SQLSTATE class 23: a concurrent first hit inserted the
                // row in between. Count on it.
                if (strpos((string) $e->getCode(), '23') !== 0) {
                    throw $e;
                }

                self::increment($bucket, $amount);
            }
        }

        $hits = (int) DB::table(self::TABLE)->where('bucket', $bucket)->value('hits');

        return $hits > $max ? max(1, $end - $now) : null;
    }

    /**
     * The key of a client address: IPv4 as is, an IPv4-mapped IPv6 address
     * (::ffff:a.b.c.d) as its IPv4, any other IPv6 address as its /64 (one
     * home or office network usually gets a whole /64, so a single address
     * says little). Anything else as is.
     */
    public static function ipKey(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }

        $packed = inet_pton($ip);

        if (substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            return inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }

    /** Deletes the rows of windows that have ended; returns how many. */
    public static function purge(): int
    {
        return (int) DB::table(self::TABLE)->where('expires_at', '<=', date('Y-m-d H:i:s'))->delete();
    }

    /** Whether the bucket's row existed (and now counts $amount more). */
    private static function increment(string $bucket, int $amount): bool
    {
        return DB::table(self::TABLE)->where('bucket', $bucket)->increment('hits', $amount) > 0;
    }
}
