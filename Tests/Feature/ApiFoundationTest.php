<?php

namespace Modules\WordPressChat\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Services\VisitorAuth;
use Modules\WordPressChat\Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    private function site($publicId, $origin = 'https://shop.test')
    {
        $site = new Site([
            'name'       => 'Shop',
            'url'        => $origin,
            'origin'     => $origin,
            'mailbox_id' => $this->testMailbox()->id,
            'public_id'  => $publicId,
        ]);
        $site->setSecret('s');
        $site->save();

        return $site;
    }

    private function url($publicId, $path = 'messages')
    {
        return '/wordpress-chat/api/v1/sites/'.$publicId.'/'.$path;
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
        $this->site($id);

        $response = $this->call('OPTIONS', $this->url($id), [], [], [], ['HTTP_ORIGIN' => 'https://shop.test']);

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', 'https://shop.test');
        $response->assertHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->assertHeader('Access-Control-Allow-Headers', 'Content-Type, X-Chat-Token');
        $response->assertHeader('Access-Control-Max-Age', '600');
        $response->assertHeader('Vary', 'Origin');
    }

    public function test_foreign_origin_is_403()
    {
        $id = str_repeat('a', 32);
        $this->site($id);

        $response = $this->call('GET', $this->url($id), [], [], [], ['HTTP_ORIGIN' => 'https://evil.test']);

        $response->assertStatus(403)->assertExactJson(['error' => 'origin']);
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_error_responses_carry_cors_headers()
    {
        $id = str_repeat('a', 32);
        $this->site($id);

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
        $this->site($id);

        $response = $this->call('GET', $this->url($id));

        $response->assertStatus(401);
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_token_of_other_site_does_not_resolve()
    {
        $a = $this->site(str_repeat('a', 32));
        $b = $this->site(str_repeat('b', 32));

        $issued = VisitorAuth::issue($a);

        $this->assertSame($issued['session']->id, VisitorAuth::resolve($a, $issued['token'])->id);
        $this->assertNull(VisitorAuth::resolve($b, $issued['token']));
        $this->assertNull(VisitorAuth::resolve($a, null));
        $this->assertNull(VisitorAuth::resolve($a, ''));
    }

    public function test_token_is_stored_hashed()
    {
        $site = $this->site(str_repeat('a', 32));

        $issued = VisitorAuth::issue($site);
        $row = DB::table('wordpresschat_sessions')->where('id', $issued['session']->id)->first();

        $this->assertSame(hash('sha256', $issued['token']), $row->token_hash);
        $this->assertNotSame($issued['token'], $row->token_hash);
        $this->assertSame(32, strlen($row->public_id));
        $this->assertSame(1, preg_match('/^[A-Za-z0-9_-]{43}$/', $issued['token']));
    }
}
