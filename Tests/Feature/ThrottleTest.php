<?php

namespace Modules\WordPressChat\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Services\RateLimiter;
use Modules\WordPressChat\Tests\TestCase;

/**
 * The limits are fixed windows on the real clock (no fake clock: see the
 * constraints). A test that expects a refusal first makes sure its requests
 * all fall into one window, see freshWindow().
 */
class ThrottleTest extends TestCase
{
    /** Temp files this test wrote; removed in tearDown(). */
    private $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_eleventh_new_chat_from_one_ip_is_429()
    {
        $site = $this->makeSite();
        $ip = ['REMOTE_ADDR' => '198.51.100.7'];
        $this->freshWindow(3600);

        for ($i = 1; $i <= 10; $i++) {
            $this->startChat($site, 'Hello '.$i, [], $ip);
        }

        $response = $this->api('POST', $site, '/messages', null, $this->newMessage(['new_token' => $this->newChatToken()]), [], $ip);

        $response->assertStatus(429);
        $retry = $response->json()['retry_after'];
        $this->assertSame(['error' => 'rate_limited', 'retry_after' => $retry], $response->json());
        $this->assertIsInt($retry);
        $this->assertGreaterThanOrEqual(1, $retry);
        $this->assertLessThanOrEqual(3600, $retry);
        $this->assertSame((string) $retry, $response->headers->get('Retry-After'));
        $this->assertSame($site->origin, $response->headers->get('Access-Control-Allow-Origin'));

        // Another address starts chats as before.
        $this->startChat($site, 'Hello', [], ['REMOTE_ADDR' => '198.51.100.8']);
    }

    public function test_twenty_first_message_per_token_per_minute_is_429()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $this->freshWindow(60);

        for ($i = 1; $i <= 20; $i++) {
            $this->api('POST', $site, '/messages', $chat['token'], $this->newMessage())->assertStatus(201);
        }

        $response = $this->api('POST', $site, '/messages', $chat['token'], $this->newMessage());
        $response->assertStatus(429);
        $this->assertSame('rate_limited', $response->json()['error']);

        // The limit is the token's: another visitor on the address goes on.
        $other = $this->startChat($site);
        $this->api('POST', $site, '/messages', $other['token'], $this->newMessage())->assertStatus(201);
    }

    /** Review Focus 1: visitors behind one carrier-grade NAT or office address. */
    public function test_three_visitors_behind_one_ip_are_not_limited()
    {
        $site = $this->makeSite();
        $ip = ['REMOTE_ADDR' => '203.0.113.9'];
        $this->freshWindow(60);

        $chats = [];
        for ($i = 0; $i < 3; $i++) {
            $chats[] = $this->startChat($site, 'Hello', [], $ip);
        }

        for ($i = 1; $i <= 15; $i++) {
            foreach ($chats as $chat) {
                $this->api('POST', $site, '/messages', $chat['token'], $this->newMessage(), [], $ip)->assertStatus(201);
            }
        }
    }

    public function test_upload_bytes_budget_per_token()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $this->freshWindow(3600);

        // 20,000 KiB each, over the site's 10 MB limit: the controller
        // refuses every one of them (422 too_large), and their bytes count
        // all the same, before any validation.
        for ($i = 1; $i <= 2; $i++) {
            $response = $this->upload($site, $chat['token'], $this->bigFile(20000));
            $response->assertStatus(422);
            $this->assertSame('too_large', $response->json()['reason']);
        }

        $response = $this->upload($site, $chat['token'], $this->bigFile(20000));
        $response->assertStatus(429);
        $this->assertSame('rate_limited', $response->json()['error']);

        // The budget is the token's: another visitor on the address goes on.
        $other = $this->startChat($site);
        $this->upload($site, $other['token'], $this->bigFile(20000))->assertStatus(422);
    }

    public function test_identity_typed_email_limits()
    {
        $site = $this->makeSite();
        $ip = ['REMOTE_ADDR' => '198.51.100.40'];
        $a = $this->startChat($site, 'Hello', [], $ip);
        $b = $this->startChat($site, 'Hello', [], $ip);
        $this->freshWindow(900);

        // Five per token...
        for ($i = 1; $i <= 5; $i++) {
            $this->assertNotSame(429, $this->typedEmail($site, $a['token'], $ip)->getStatusCode());
        }
        $this->typedEmail($site, $a['token'], $ip)->assertStatus(429);

        // ...and ten per address, refused attempts included: A's six and
        // B's first four make ten, so B's fifth is refused by the address
        // although B is still within its own five.
        for ($i = 1; $i <= 4; $i++) {
            $this->assertNotSame(429, $this->typedEmail($site, $b['token'], $ip)->getStatusCode());
        }
        $this->typedEmail($site, $b['token'], $ip)->assertStatus(429);

        // A login token is counted by its own rule.
        $this->api('POST', $site, '/identity', $b['token'], ['login_token' => $this->loginToken($site, $b['token'])], [], $ip)
            ->assertStatus(200);
    }

    public function test_polls_and_preflights_are_never_limited()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $rows = DB::table('wordpresschat_rate_limits')->count();

        for ($i = 1; $i <= 200; $i++) {
            $this->api('GET', $site, '/messages', $chat['token'])->assertStatus(200);
        }

        $path = '/wordpress-chat/api/v1/sites/'.$site->public_id.'/messages';
        $this->call('OPTIONS', $path, [], [], [], [
            'HTTP_ORIGIN'                        => $site->origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertStatus(204);
        $this->call('OPTIONS', $path)->assertStatus(204);

        $this->assertSame($rows, DB::table('wordpresschat_rate_limits')->count());
    }

    public function test_429_writes_nothing()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $ip = '198.51.100.30';

        $sessions = Session::count();
        $messages = Message::count();
        $threads = \App\Thread::count();
        $conversations = \App\Conversation::count();
        $session = $chat['session']->fresh()->toArray();

        // A new chat: no session for the token, no message.
        $this->fillBucket('chat', $ip, 3600, 10);
        $new = $this->newChatToken();
        $this->api('POST', $site, '/messages', null, $this->newMessage(['new_token' => $new]), [], ['REMOTE_ADDR' => $ip])
            ->assertStatus(429);
        $this->assertFalse(Session::where('token_hash', hash('sha256', $new))->exists());

        // A message on an existing chat.
        $this->fillBucket('message', hash('sha256', $chat['token']), 60, 20);
        $this->api('POST', $site, '/messages', $chat['token'], $this->newMessage())->assertStatus(429);

        // A typed email.
        $this->fillBucket('identity', hash('sha256', $chat['token']), 900, 5);
        $this->typedEmail($site, $chat['token'])->assertStatus(429);

        $this->assertSame($sessions, Session::count());
        $this->assertSame($messages, Message::count());
        $this->assertSame($threads, \App\Thread::count());
        $this->assertSame($conversations, \App\Conversation::count());
        $this->assertSame($session, $chat['session']->fresh()->toArray());
    }

    private function newMessage(array $body = []): array
    {
        return array_merge([
            'text'              => 'Hi there',
            'client_message_id' => bin2hex(random_bytes(8)),
        ], $body);
    }

    private function upload(Site $site, string $token, UploadedFile $file): \Illuminate\Foundation\Testing\TestResponse
    {
        return $this->apiMultipart('POST', $site, '/messages', $token, $this->newMessage(['text' => '']), ['file' => $file]);
    }

    /**
     * A $kilobytes KiB upload of zero bytes, as the test helper
     * UploadedFile::fake()->create('a.pdf', $kilobytes) would report it
     * (which Laravel 5.5 can no longer load on PHP 8.2). Sparse, so it costs
     * no disk space.
     */
    private function bigFile(int $kilobytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wpc-up-');
        $this->tmpFiles[] = $path;
        $handle = fopen($path, 'w');
        ftruncate($handle, $kilobytes * 1024);
        fclose($handle);

        return new UploadedFile($path, 'a.pdf', 'application/pdf', null, null, true);
    }

    private function typedEmail(Site $site, string $token, array $ip = []): \Illuminate\Foundation\Testing\TestResponse
    {
        return $this->api('POST', $site, '/identity', $token, ['email' => 'anna@example.test'], [], $ip);
    }

    /**
     * A full bucket for the current window and the next one: a request
     * that lands just after a window boundary still finds it full.
     */
    private function fillBucket(string $scope, string $key, int $window, int $hits): void
    {
        $now = time();

        foreach ([$now, $now + $window] as $at) {
            DB::table('wordpresschat_rate_limits')->insert([
                'bucket'     => RateLimiter::bucket($scope, $key, $window, $at),
                'hits'       => $hits,
                'expires_at' => date('Y-m-d H:i:s', $now + 2 * $window),
            ]);
        }
    }

    public function test_retry_after_is_the_longest_refusal()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $this->freshWindow(3600, 70);

        $this->fillBucket('message', hash('sha256', $chat['token']), 60, 20);
        $this->fillBucket('upload', hash('sha256', $chat['token']), 3600, 52428800);

        $response = $this->upload($site, $chat['token'], $this->bigFile(1));

        $response->assertStatus(429);
        $retry = $response->json()['retry_after'];
        $this->assertGreaterThan(60, $retry);
        $this->assertSame((string) $retry, $response->headers->get('Retry-After'));
    }

    public function test_seen_and_download_have_per_ip_caps()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $ip = '198.51.100.50';
        $other = '198.51.100.51';

        $this->fillBucket('seen-ip', $ip, 60, 180);
        $this->api('POST', $site, '/seen', $chat['token'], ['message_ids' => [2147483647]], [], ['REMOTE_ADDR' => $ip])->assertStatus(429);
        $this->api('POST', $site, '/seen', $chat['token'], ['message_ids' => [2147483647]], [], ['REMOTE_ADDR' => $other])->assertStatus(204);

        $this->fillBucket('download-ip', $ip, 60, 360);
        $this->api('GET', $site, '/attachments/1', $chat['token'], null, [], ['REMOTE_ADDR' => $ip])->assertStatus(429);
        $this->assertNotSame(429, $this->api('GET', $site, '/attachments/1', $chat['token'], null, [], ['REMOTE_ADDR' => $other])->getStatusCode());
    }

    public function test_shared_ip_allows_three_browsers_reading()
    {
        $site = $this->makeSite();
        $ip = ['REMOTE_ADDR' => '203.0.113.60'];
        $chats = [];
        for ($i = 0; $i < 3; $i++) {
            $chats[] = $this->startChat($site, 'Hello', [], $ip);
        }
        $this->freshWindow(60, 10);

        for ($i = 1; $i <= 60; $i++) {
            foreach ($chats as $chat) {
                $this->api('POST', $site, '/seen', $chat['token'], ['message_ids' => [2147483647]], [], $ip)->assertStatus(204);
            }
        }

        $this->api('POST', $site, '/seen', $chats[0]['token'], null, [], $ip)->assertStatus(429);
    }

    public function test_random_token_flood_is_bounded_by_the_ip_cap()
    {
        $site = $this->makeSite();
        $ip = ['REMOTE_ADDR' => '203.0.113.70'];
        $this->freshWindow(60);
        $rows = DB::table('wordpresschat_rate_limits')->count();

        for ($i = 0; $i < 100; $i++) {
            $this->api('POST', $site, '/messages', $this->newChatToken(), $this->newMessage(), [], $ip);
        }

        $this->assertLessThanOrEqual(62, DB::table('wordpresschat_rate_limits')->count() - $rows);
    }
}
