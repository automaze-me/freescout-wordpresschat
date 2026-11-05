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
            $this->assertSame($site->origin, $response->headers->get('Access-Control-Allow-Origin'));
        }

        foreach (\DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('wordpresschat_rate_limits', $query['query']);
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

        $this->makePending();
        foreach (['/wordpress-chat/sites', "/wordpress-chat/sites/{$site->id}/edit"] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertStatus(200)->getContent();
            $this->assertStringContainsString($notice, $html);
            $this->assertStringContainsString('Migrate DB', $html);
        }
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
}
