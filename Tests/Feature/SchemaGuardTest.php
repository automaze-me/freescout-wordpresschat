<?php

namespace Modules\WordPressChat\Tests\Feature;

use Modules\WordPressChat\Services\SchemaGuard;
use Modules\WordPressChat\Services\SystemChecks;
use Modules\WordPressChat\Tests\TestCase;

class SchemaGuardTest extends TestCase
{
    const NEWEST = '2026_11_04_000010_create_wordpresschat_rate_limits_table';

    private function makePending()
    {
        \DB::table('migrations')->where('migration', self::NEWEST)->delete();
        \Cache::forget(SchemaGuard::cacheKey());
    }

    public function test_pending_migration_answers_503_everywhere()
    {
        $site = $this->makeSite();
        $token = $this->newChatToken();
        $this->makePending();
        \DB::enableQueryLog();

        $calls = [
            $this->api('POST', $site, '/messages', null, ['text' => 'Hi', 'new_token' => $token]),
            $this->api('GET', $site, '/messages', $token),
            $this->api('POST', $site, '/identity', $token, ['email' => 'a@example.test']),
            $this->api('POST', $site, '/continue', null, ['token' => 'x']),
        ];

        foreach ($calls as $response) {
            $response->assertStatus(503);
            $this->assertSame(['error' => 'maintenance'], json_decode($response->getContent(), true));
            $this->assertSame('300', $response->headers->get('Retry-After'));
        }

        // ResolveSite answers before it looks the site up (final review
        // M3): on a first install whose migrations were skipped, the sites
        // table doesn't exist yet. So there is no origin for CORS headers.
        foreach (\DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('wordpresschat_rate_limits', $query['query']);
            $this->assertStringNotContainsString('wordpresschat_sites', $query['query']);
        }
    }

    public function test_pending_lists_the_missing_basename()
    {
        $this->assertSame([], SchemaGuard::pending());
        $this->makePending();
        $this->assertSame([self::NEWEST], SchemaGuard::pending());
        $this->assertFalse(SchemaGuard::ok());
        // A false verdict is never cached.
        \DB::table('migrations')->insert(['migration' => self::NEWEST, 'batch' => 1]);
        $this->assertTrue(SchemaGuard::ok());
    }

    public function test_ok_verdict_is_cached()
    {
        \Cache::forget(SchemaGuard::cacheKey());
        $this->assertTrue(SchemaGuard::ok());

        \DB::enableQueryLog();
        $this->assertTrue(SchemaGuard::ok());
        foreach (\DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('migrations', $query['query']);
        }
    }

    public function test_admin_pages_show_the_notice_when_pending()
    {
        $admin = $this->adminUser();
        $site = $this->makeSite();
        $notice = 'Website Chat needs a database update.';

        $this->assertStringNotContainsString($notice, $this->actingAs($admin)->get('/wordpress-chat/sites')->getContent());

        $before = $site->fresh()->toArray();
        $this->makePending();
        \DB::enableQueryLog();
        foreach (['/wordpress-chat/sites', '/wordpress-chat/sites/create', "/wordpress-chat/sites/{$site->id}/edit"] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertStatus(200)->getContent();
            $this->assertStringContainsString($notice, $html, $url);
            $this->assertStringContainsString('Migrate DB', $html, $url);
            // Only the notice: no list, no form (final review M3).
            $this->assertStringNotContainsString('name="mailbox_id"', $html, $url);
            $this->assertStringNotContainsString('No websites connected yet.', $html, $url);
        }

        // Writes change nothing and lead to the notice.
        $posts = [
            '/wordpress-chat/sites'                     => ['name' => 'X', 'url' => 'https://x.test', 'mailbox_id' => $site->mailbox_id],
            "/wordpress-chat/sites/{$site->id}"        => ['name' => 'Renamed', 'url' => 'https://x.test', 'mailbox_id' => $site->mailbox_id],
            "/wordpress-chat/sites/{$site->id}/secret" => [],
            "/wordpress-chat/sites/{$site->id}/delete" => [],
        ];
        foreach ($posts as $url => $data) {
            $this->actingAs($admin)->post($url, $data)->assertRedirect(route('wordpresschat.sites.index'));
        }

        // The sites table is never read while a migration is pending: on a
        // first install whose migrations were skipped it doesn't exist.
        foreach (\DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('wordpresschat_', $query['query']);
        }
        \DB::disableQueryLog();

        $this->assertSame($before, $site->fresh()->toArray());
        $this->assertSame(0, \Modules\WordPressChat\Entities\Site::where('origin', 'https://x.test')->count());
    }

    public function test_admin_pages_still_need_an_admin_when_pending()
    {
        $user = $this->regularUser();
        $this->makePending();

        $this->actingAs($user)->get('/wordpress-chat/sites')->assertStatus(403);
        $this->actingAs($user)->post('/wordpress-chat/sites/1/delete')->assertStatus(403);
    }

    public function test_cleanup_skips_both_purges_while_a_migration_is_pending()
    {
        $this->makePending();
        \DB::enableQueryLog();

        $this->assertSame(0, \Artisan::call('wordpresschat:cleanup'));
        $output = \Artisan::output();

        $this->assertStringContainsString('Skipped', $output);
        $this->assertStringContainsString('Migrate DB', $output);
        $this->assertStringNotContainsString('deleted:', $output);
        foreach (\DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('wordpresschat_', $query['query']);
        }
    }

    public function test_unreadable_migrations_table_logs_a_warning()
    {
        if (\DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('A failing query would abort the PostgreSQL test transaction.');
        }

        \Cache::forget(SchemaGuard::cacheKey());
        SchemaGuard::$table = 'wordpresschat_no_such_table';
        \Log::spy();

        try {
            $this->assertFalse(SchemaGuard::ok());
        } finally {
            SchemaGuard::$table = 'migrations';
        }

        \Log::shouldHaveReceived('warning')->once()->withArgs(function ($message) {
            return strpos($message, 'WordPressChat: ') === 0
                && strpos($message, 'wordpresschat_no_such_table') !== false;
        });
    }

    public function test_system_status_rows()
    {
        $rows = SystemChecks::rows();
        $labels = array_column($rows, 'label');
        foreach (['Database', 'pcntl', 'WebP detection', 'Upload limits'] as $label) {
            $this->assertContains($label, $labels);
        }
        foreach ($rows as $row) {
            $this->assertArrayHasKey('ok', $row);
            $this->assertIsString($row['detail']);
        }
        $db = $rows[array_search('Database', $labels)];
        $this->assertTrue($db['ok']);

        $html = $this->actingAs($this->adminUser())->get('/system/status')->assertStatus(200)->getContent();
        $this->assertStringContainsString('Website Chat', $html);
        $this->assertStringContainsString('WebP detection', $html);
    }

    public function test_unreadable_migrations_table_is_not_ok_and_answers_503()
    {
        if (\DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('A failing query would abort the PostgreSQL test transaction.');
        }

        $site = $this->makeSite();
        \Cache::forget(SchemaGuard::cacheKey());
        SchemaGuard::$table = 'wordpresschat_no_such_table';

        try {
            $this->assertFalse(SchemaGuard::ok());
            $this->assertNull(\Cache::get(SchemaGuard::cacheKey()));

            $response = $this->api('GET', $site, '/messages', $this->newChatToken());
            $response->assertStatus(503);
            $this->assertSame(['error' => 'maintenance'], json_decode($response->getContent(), true));
            $this->assertSame('300', $response->headers->get('Retry-After'));
        } finally {
            SchemaGuard::$table = 'migrations';
        }
    }
}
