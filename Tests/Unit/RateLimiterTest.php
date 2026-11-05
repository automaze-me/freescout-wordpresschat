<?php

namespace Modules\WordPressChat\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Modules\WordPressChat\Services\RateLimiter;
use Modules\WordPressChat\Tests\TestCase;

/**
 * The counters live in the database, so this "unit" test needs the module's
 * TestCase (testing connection, rolled back after every test).
 */
class RateLimiterTest extends TestCase
{
    public function test_ip_key_buckets_ipv6_by_64()
    {
        $a = RateLimiter::ipKey('2001:db8:1:2:aaaa::1');

        $this->assertSame('2001:db8:1:2::/64', $a);
        $this->assertSame($a, RateLimiter::ipKey('2001:db8:1:2:ffff::9'));
        $this->assertSame($a, RateLimiter::ipKey('2001:DB8:1:2::'));
        $this->assertNotSame($a, RateLimiter::ipKey('2001:db8:1:3::1'));

        // IPv4 as is.
        $this->assertSame('203.0.113.5', RateLimiter::ipKey('203.0.113.5'));
        $this->assertNotSame(RateLimiter::ipKey('203.0.113.5'), RateLimiter::ipKey('203.0.113.6'));
    }

    public function test_ip_key_maps_ipv4_mapped_addresses()
    {
        $this->assertSame('203.0.113.5', RateLimiter::ipKey('::ffff:203.0.113.5'));
        $this->assertSame('203.0.113.5', RateLimiter::ipKey('::FFFF:cb00:7105'));
    }

    public function test_hit_allows_up_to_max_then_returns_retry_after()
    {
        $key = $this->uniqueKey();

        $this->assertNull(RateLimiter::hit('test', $key, 3, 60));
        $this->assertNull(RateLimiter::hit('test', $key, 3, 60));
        $this->assertNull(RateLimiter::hit('test', $key, 3, 60));

        $retry = RateLimiter::hit('test', $key, 3, 60);
        $this->assertIsInt($retry);
        $this->assertGreaterThanOrEqual(1, $retry);
        $this->assertLessThanOrEqual(60, $retry);

        // A refused attempt counts too: the window stays full.
        $this->assertIsInt(RateLimiter::hit('test', $key, 3, 60));
    }

    public function test_hit_counts_amount()
    {
        $key = $this->uniqueKey();

        $this->assertNull(RateLimiter::hit('test', $key, 100, 3600, 40));
        $this->assertNull(RateLimiter::hit('test', $key, 100, 3600, 40));
        $this->assertIsInt(RateLimiter::hit('test', $key, 100, 3600, 40));
    }

    public function test_existing_bucket_row_is_incremented()
    {
        $key = $this->uniqueKey();
        // A day-long window: the bucket named here and the one hit()
        // computes a moment later can only differ across midnight UTC.
        $window = 86400;
        $bucket = RateLimiter::bucket('test', $key, $window);

        // As if a concurrent request had inserted the row first: hit()
        // must take the increment path, never a second insert.
        DB::table('wordpresschat_rate_limits')->insert([
            'bucket'     => $bucket,
            'hits'       => 7,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);

        $this->assertNull(RateLimiter::hit('test', $key, 10, $window));
        $this->assertSame(8, $this->hits($bucket));

        $this->assertNull(RateLimiter::hit('test', $key, 10, $window, 2));
        $this->assertSame(10, $this->hits($bucket));

        $this->assertIsInt(RateLimiter::hit('test', $key, 10, $window));
        $this->assertSame(11, $this->hits($bucket));
        $this->assertSame(1, DB::table('wordpresschat_rate_limits')->where('bucket', $bucket)->count());
    }

    public function test_bucket_follows_the_fixed_formula()
    {
        $this->assertSame(
            hash('sha256', 'chat|203.0.113.5|'.floor(1700000000 / 3600)),
            RateLimiter::bucket('chat', '203.0.113.5', 3600, 1700000000)
        );
        $this->assertSame(
            RateLimiter::bucket('chat', 'k', 60, 120),
            RateLimiter::bucket('chat', 'k', 60, 179)
        );
        $this->assertNotSame(
            RateLimiter::bucket('chat', 'k', 60, 179),
            RateLimiter::bucket('chat', 'k', 60, 180)
        );
    }

    public function test_purge_deletes_only_expired_rows()
    {
        $expired = RateLimiter::bucket('test', $this->uniqueKey(), 60);
        $current = RateLimiter::bucket('test', $this->uniqueKey(), 60);

        DB::table('wordpresschat_rate_limits')->insert([
            ['bucket' => $expired, 'hits' => 3, 'expires_at' => date('Y-m-d H:i:s', time() - 120)],
            ['bucket' => $current, 'hits' => 3, 'expires_at' => date('Y-m-d H:i:s', time() + 120)],
        ]);

        $expiredCount = DB::table('wordpresschat_rate_limits')->where('expires_at', '<=', date('Y-m-d H:i:s'))->count();

        $this->assertSame($expiredCount, RateLimiter::purge());
        $this->assertFalse(DB::table('wordpresschat_rate_limits')->where('bucket', $expired)->exists());
        $this->assertTrue(DB::table('wordpresschat_rate_limits')->where('bucket', $current)->exists());
    }

    private function uniqueKey(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function hits(string $bucket): int
    {
        return (int) DB::table('wordpresschat_rate_limits')->where('bucket', $bucket)->value('hits');
    }
}
