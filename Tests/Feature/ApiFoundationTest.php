<?php

namespace Modules\WordPressChat\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Services\VisitorAuth;
use Modules\WordPressChat\Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    private function url($publicId, $path = 'messages')
    {
        return '/wordpress-chat/api/v1/sites/'.$publicId.'/'.$path;
    }

    /** A visitor session from a token generated the way the widget does it: [session, token]. */
    private function claimSession(Site $site)
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [VisitorAuth::claim($site, $token), $token];
    }

    public function test_version_check_is_cached()
    {
        $manifest = json_decode(file_get_contents(__DIR__.'/../../module.json'), true);
        $provider = \Modules\WordPressChat\Providers\WordPressChatServiceProvider::class;

        // Seen: no read of the options table at all.
        \Cache::forever('wordpresschat.version_seen', $manifest['version']);
        $selects = $this->selectsOn('options', function () use ($provider, &$result) {
            $result = $provider::restartQueueIfVersionChanged();
        });
        $this->assertFalse($result);
        $this->assertSame([], $selects);

        // Key missing and the Option stale: restarts, then remembers.
        \Cache::forget('wordpresschat.version_seen');
        \Option::set('wordpresschat.version', '0.0.0-stale');
        $this->assertTrue($provider::restartQueueIfVersionChanged());
        $this->assertSame($manifest['version'], \Cache::get('wordpresschat.version_seen'));
        $this->assertFalse($provider::restartQueueIfVersionChanged());
    }

    public function test_unknown_site_is_404()
    {
        $this->call('GET', $this->url(str_repeat('c', 32)))
            ->assertStatus(404)
            ->assertExactJson(['error' => 'not_found']);
    }

    public function test_preflight_from_site_origin_gets_cors_headers()
    {
        $id = str_repeat('a', 32);
        $this->makeSite(['public_id' => $id]);

        $response = $this->call('OPTIONS', $this->url($id), [], [], [], ['HTTP_ORIGIN' => 'https://shop.test']);

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', 'https://shop.test');
        $response->assertHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->assertHeader('Access-Control-Allow-Headers', 'Content-Type, X-Chat-Token, X-Chat-Page');
        $response->assertHeader('Access-Control-Max-Age', '600');
        $response->assertHeader('Vary', 'Origin');
    }

    public function test_foreign_origin_is_403()
    {
        $id = str_repeat('a', 32);
        $this->makeSite(['public_id' => $id]);

        $response = $this->call('GET', $this->url($id), [], [], [], ['HTTP_ORIGIN' => 'https://evil.test']);

        $response->assertStatus(403)->assertExactJson(['error' => 'origin']);
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_error_responses_carry_cors_headers()
    {
        $id = str_repeat('a', 32);
        $this->makeSite(['public_id' => $id]);

        $response = $this->call('GET', $this->url($id), [], [], [], [
            'HTTP_ORIGIN'        => 'https://shop.test',
            'HTTP_X_CHAT_TOKEN'  => 'bogus',
        ]);

        $response->assertStatus(401);
        $response->assertHeader('Access-Control-Allow-Origin', 'https://shop.test');
    }

    public function test_request_without_origin_passes_without_cors_headers()
    {
        $id = str_repeat('a', 32);
        $this->makeSite(['public_id' => $id]);

        $response = $this->call('GET', $this->url($id));

        $response->assertStatus(401);
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_token_of_other_site_does_not_resolve()
    {
        $a = $this->makeSite(['public_id' => str_repeat('a', 32)]);
        $b = $this->makeSite(['public_id' => str_repeat('b', 32)]);

        list($session, $token) = $this->claimSession($a);

        $this->assertSame($session->id, VisitorAuth::resolve($a, $token)->id);
        $this->assertNull(VisitorAuth::resolve($b, $token));
        $this->assertNull(VisitorAuth::resolve($a, null));
        $this->assertNull(VisitorAuth::resolve($a, ''));
    }

    public function test_token_is_stored_hashed()
    {
        $site = $this->makeSite(['public_id' => str_repeat('a', 32)]);

        list($session, $token) = $this->claimSession($site);
        $row = DB::table('wordpresschat_sessions')->where('id', $session->id)->first();

        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertNotSame($token, $row->token_hash);
        $this->assertSame(32, strlen($row->public_id));
    }

    public function test_claim_returns_the_same_session_for_the_same_token()
    {
        $a = $this->makeSite(['public_id' => str_repeat('a', 32)]);
        $b = $this->makeSite(['public_id' => str_repeat('b', 32), 'url' => 'https://other.test/', 'origin' => 'https://other.test']);

        list($session, $token) = $this->claimSession($a);

        $this->assertSame($session->id, VisitorAuth::claim($a, $token)->id);
        $this->assertNull(VisitorAuth::claim($b, $token));
        $this->assertSame(1, DB::table('wordpresschat_sessions')->where('token_hash', hash('sha256', $token))->count());
    }

    public function test_token_of_site_a_is_401_on_every_endpoint_of_site_b()
    {
        $a = $this->makeSite(['public_id' => str_repeat('a', 32)]);
        $b = $this->makeSite(['public_id' => str_repeat('b', 32), 'url' => 'https://other.test/', 'origin' => 'https://other.test']);
        list(, $token) = $this->claimSession($a);

        $tables = ['wordpresschat_sessions', 'wordpresschat_access', 'wordpresschat_messages', 'customers', 'conversations', 'threads'];
        $counts = function () use ($tables) {
            $out = [];
            foreach ($tables as $table) {
                $out[$table] = DB::table($table)->count();
            }

            return $out;
        };
        $before = $counts();

        $server = [
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'HTTP_ORIGIN'       => 'https://other.test',
            'HTTP_X_CHAT_TOKEN' => $token,
        ];
        $id = $b->public_id;

        $send = $this->call('POST', $this->url($id), [], [], [], $server, json_encode(['text' => 'Hello', 'client_message_id' => 'cross-0001']));
        $index = $this->call('GET', $this->url($id), [], [], [], $server);
        $seen = $this->call('POST', $this->url($id, 'seen'), [], [], [], $server, json_encode(['message_ids' => [1]]));

        foreach (['send' => $send, 'index' => $index, 'seen' => $seen] as $name => $response) {
            $this->assertSame(401, $response->getStatusCode(), "$name on site B with site A's token");
            $this->assertSame(['error' => 'unauthorized'], $response->json(), $name);
            $this->assertSame('https://other.test', $response->headers->get('Access-Control-Allow-Origin'), $name);
        }

        $this->assertSame($before, $counts());
    }
}
