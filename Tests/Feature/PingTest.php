<?php

namespace Modules\WordPressChat\Tests\Feature;

use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Services\IdentityToken;
use Modules\WordPressChat\Tests\TestCase;

/**
 * The plugin's "Test connection": a server-to-server call (no Origin
 * header) that says whether the Site ID, the secret and the site's address
 * match.
 */
class PingTest extends TestCase
{
    private function ping(Site $site, ?array $body = null, string $id = null)
    {
        return $this->call(
            'POST',
            '/wordpress-chat/api/v1/sites/'.($id === null ? $site->public_id : $id).'/ping',
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '198.51.100.'.rand(1, 250)],
            $body === null ? null : json_encode($body)
        );
    }

    private function pingToken(Site $site, array $over = [], ?string $secret = null): string
    {
        $now = time();

        return IdentityToken::seal(array_merge([
            'typ'    => 'ping',
            'sid'    => $site->public_id,
            'bind'   => str_repeat('0', 64),
            'iat'    => $now,
            'exp'    => $now + 300,
            'jti'    => bin2hex(random_bytes(16)),
            'origin' => 'https://shop.test',
        ], $over), $secret === null ? $site->getSecret() : $secret, $site->public_id);
    }

    public function test_ping_without_token_reports_missing_secret()
    {
        $site = $this->makeSite();

        $response = $this->ping($site, []);

        $response->assertStatus(200);
        $this->assertSame(['ok' => true, 'secret' => 'missing', 'origin' => null], $response->json());
    }

    public function test_ping_with_valid_token_and_origin()
    {
        $site = $this->makeSite();

        $response = $this->ping($site, ['token' => $this->pingToken($site)]);

        $response->assertStatus(200);
        $this->assertSame(['ok' => true, 'secret' => 'ok', 'origin' => 'ok'], $response->json());
    }

    public function test_ping_with_wrong_secret_is_invalid()
    {
        $site = $this->makeSite();

        $response = $this->ping($site, ['token' => $this->pingToken($site, [], 'another-secret')]);

        $response->assertStatus(200);
        $this->assertSame(['ok' => true, 'secret' => 'invalid', 'origin' => null], $response->json());
        $this->assertArrayNotHasKey('expected_origin', $response->json());
    }

    public function test_ping_origin_mismatch_names_expected_origin()
    {
        $site = $this->makeSite();

        $response = $this->ping($site, ['token' => $this->pingToken($site, ['origin' => 'https://other.test:8443'])]);

        $response->assertStatus(200);
        $this->assertSame(
            ['ok' => true, 'secret' => 'ok', 'origin' => 'mismatch', 'expected_origin' => 'https://shop.test'],
            $response->json()
        );
    }

    public function test_ping_token_of_another_type_is_invalid()
    {
        $site = $this->makeSite();

        $response = $this->ping($site, ['token' => $this->pingToken($site, ['typ' => 'login'])]);

        $this->assertSame('invalid', $response->json()['secret']);
    }

    public function test_ping_token_is_single_use()
    {
        $site = $this->makeSite();
        $token = $this->pingToken($site);

        $this->assertSame('ok', $this->ping($site, ['token' => $token])->json()['secret']);
        $this->assertSame('invalid', $this->ping($site, ['token' => $token])->json()['secret']);
    }

    public function test_ping_unknown_site_is_404()
    {
        $site = $this->makeSite();

        $response = $this->ping($site, [], str_repeat('a', 32));

        $response->assertStatus(404);
        $this->assertSame(['error' => 'not_found'], $response->json());
    }
}
