<?php

namespace Modules\WordPressChat\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Entities\TokenUse;
use Modules\WordPressChat\Services\IdentityToken;
use Modules\WordPressChat\Tests\TestCase;

/**
 * The shared test vectors below must stay byte for byte identical to the
 * ones the WordPress plugin's LCFF_Crypto reproduces. Never "fix" them here.
 */
class IdentityTokenTest extends TestCase
{
    const SECRET = 'AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE';
    const SID = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    const BIND = '9529929cae075235a5b322e335e0eb5a144bacf59ff0893663b5f4e43cff8106';
    const NOW = 1700000100;

    const LOGIN_VECTOR = 'v1.AgICAgICAgICAgICn4h2GSQ3ZLA1PY6Ae_V96Bht-3mCt65FqGycLeYC76imKEobLAUArT4B9wHHyzNAhDtH05n1hZW3qG86Tx63pED6fodzwatMuTWIxW3uO0Ek_UGqSjTnA6eVm9NPsz6Mi-2T2J3XdjlpmocSzH1kV1CPbF2PEeyNqF54Jb03SMUcypFXu1m5O6iIL4i0ON4CnedXQBlfbMODjxO7l6ZZ4g6YDISRPDg-JPvwjLBk409CIt09JEftVvnWlPgp75qUJlJMWiLQgTV2PbRa6OL9U0wqBwoysQmhgeEk5NLJcMehWFR8YW3ozJ7VWKxchdcVWNCUtZmH64gSx9DlXh6IQiFPTOpj6TiayAQh6gvCFMp4ZZmGh0Lk64JlJgYQQDxGcJrN0ydc_14W-BPhGTUqLuJCg1R8VLGxuRpgW2JKdAfQEE2daE6oQO1uAK07R56Gl3exXiifpHI0jz5jnyLXd7T9_VirbwODdf51IVgUO4JC1j_ilgHqDOR_O51d-j04tShk9XjAfUyje7Q4bmK3E-iBRoHpw06lWnEO0qs15P_u6K_4yecR8jJkADZ36HOHPRmO52vR7YGUXjk34uUj4l9ane3KiojrDnOx_-gmMPB3Czlph-p1MUyclT2L9fIJrBiQz2VT4oRKz6ezR-Ld3FybGAsg9ZO51SUR2XzbumxZBvdY3QB0cWrmlD2MwGAs';

    const ORDER_VECTOR = 'v1.AgICAgICAgICAgICn4h2GSQ3ZLA2II2MZ_V96Bht-3mCt65FqGycLeYC76imKEobLAUArT4B9wHHyzNAhDtH05n1hZW3qG86Tx63pED6fodzwatMuTWIxW3uO0Ek_UGqSjTnA6eVm9NPsz6Mi-2T2J3XdjlpmocSzH1kV1CPbF2PEeyNqF54Jb03SMUcypFXu1m5O6iIL4i0ON4CnedXQBlfbMODjxO7l6ZZ4g6YDISRPDg-JPvw2uQysxkWLdIyKxO7Bq-Cwq59ucrCcl1DVS2E12UgaeJa6OLmT0UqBxwxrR_83qJhqpacJ8irFA9IdmDsjczcUONWhpgNVpOUupWirMpR687nWhCuVS0AEKRoqijfyh4ji0WMVcodPdjL1w6h6d9lJgYQQDxGcJrN0ydc_14W-BPhGTUqLuJCg1R8VLGxuRpgW2JKdAfQEE2daE6oQO1uAK07R56Gl3exXiifpHI0jz5jnyLXd7T9_VirbwODdf51IVgUO4JC1j_ilgHqDOR_O51d-j04tShk9XjAfUyje7Q4bmK3E-iBRoHpw06lWnEO0qs15P_u6K_4yecR8jJkADZ36HOHPRmO52vR7YGUXjk34uUj4l9ane3KiojrDnOx_-gmMPB3Czlph-p1MUyclT2L9fIJrBiQz2VT4oRKz6ezR-Ld3FybGAsg9ZO51SUR2XzbumxhE2HcGe04VsyyVwKGToml';

    private function vectorSite(string $publicId = self::SID): Site
    {
        $site = $this->makeSite(['public_id' => $publicId]);
        $site->setSecret(self::SECRET);
        $site->save();

        return $site;
    }

    private function loginPayload(array $over = []): array
    {
        return array_merge([
            'typ'   => 'login',
            'sid'   => self::SID,
            'bind'  => self::BIND,
            'iat'   => 1700000000,
            'exp'   => 1700000300,
            'jti'   => '0123456789abcdef0123456789abcdef',
            'uid'   => 42,
            'email' => 'anna@example.com',
            'name'  => 'Anna Example',
        ], $over);
    }

    private function orderPayload(): array
    {
        return [
            'typ'   => 'order',
            'sid'   => self::SID,
            'bind'  => self::BIND,
            'iat'   => 1700000000,
            'exp'   => 1700000300,
            'jti'   => 'fedcba9876543210fedcba9876543210',
            'num'   => '10482',
            'found' => true,
            'email' => 'anna@example.com',
            'name'  => 'Anna Example',
        ];
    }

    private function iv(): string
    {
        return str_repeat("\x02", 12);
    }

    private function openLogin(Site $site, string $token, int $now = self::NOW)
    {
        return IdentityToken::open($site, $token, 'login', self::BIND, $now);
    }

    public function test_seal_reproduces_both_vectors()
    {
        $this->assertSame(self::LOGIN_VECTOR, IdentityToken::seal($this->loginPayload(), self::SECRET, self::SID, $this->iv()));
        $this->assertSame(self::ORDER_VECTOR, IdentityToken::seal($this->orderPayload(), self::SECRET, self::SID, $this->iv()));
    }

    public function test_open_returns_the_login_payload()
    {
        $payload = $this->openLogin($this->vectorSite(), self::LOGIN_VECTOR);

        $this->assertIsArray($payload);
        $this->assertSame(42, $payload['uid']);
        $this->assertSame('anna@example.com', $payload['email']);
        $this->assertSame($this->loginPayload(), $payload);
    }

    public function test_open_returns_the_order_payload()
    {
        $payload = IdentityToken::open($this->vectorSite(), self::ORDER_VECTOR, 'order', self::BIND, self::NOW);

        $this->assertSame($this->orderPayload(), $payload);
    }

    public function test_tampered_token_is_rejected()
    {
        $site = $this->vectorSite();
        $last = substr(self::LOGIN_VECTOR, -1);
        $tampered = substr(self::LOGIN_VECTOR, 0, -1).($last === 'A' ? 'B' : 'A');

        $this->assertNull($this->openLogin($site, $tampered));
    }

    public function test_other_site_is_rejected()
    {
        $this->vectorSite();
        $other = $this->vectorSite('0f0e0d0c0b0a09080706050403020100');

        // Same secret, other public id: the AAD no longer matches.
        $this->assertNull($this->openLogin($other, self::LOGIN_VECTOR));

        // Sealed with the other site's AAD but naming the vector site in sid.
        $foreignSid = IdentityToken::seal($this->loginPayload(), self::SECRET, $other->public_id, $this->iv());
        $this->assertNull($this->openLogin($other, $foreignSid));
    }

    public function test_wrong_type_is_rejected()
    {
        $site = $this->vectorSite();

        $this->assertNull(IdentityToken::open($site, self::LOGIN_VECTOR, 'order', self::BIND, self::NOW));
        $this->assertNull(IdentityToken::open($site, self::ORDER_VECTOR, 'login', self::BIND, self::NOW));
    }

    public function test_bind_mismatch_is_rejected()
    {
        $site = $this->vectorSite();

        $this->assertNull(IdentityToken::open($site, self::LOGIN_VECTOR, 'login', hash('sha256', 'another-chat-token'), self::NOW));
        $this->assertNull(IdentityToken::open($site, self::LOGIN_VECTOR, 'login', '', self::NOW));
    }

    public function test_expired_is_rejected()
    {
        $site = $this->vectorSite();

        $this->assertNull($this->openLogin($site, self::LOGIN_VECTOR, 1700000301));
        // exp itself is still valid.
        $this->assertIsArray($this->openLogin($site, self::LOGIN_VECTOR, 1700000300));
    }

    public function test_future_iat_beyond_skew_is_rejected()
    {
        $site = $this->vectorSite();

        $this->assertNull($this->openLogin($site, self::LOGIN_VECTOR, 1699999939));
        $this->assertIsArray($this->openLogin($site, self::LOGIN_VECTOR, 1699999940));
    }

    public function test_overlong_lifetime_is_rejected()
    {
        $site = $this->vectorSite();
        $token = IdentityToken::seal($this->loginPayload(['exp' => 1700000301]), self::SECRET, self::SID, $this->iv());

        $this->assertNull($this->openLogin($site, $token));
    }

    public function test_malformed_tokens_are_rejected_without_exceptions()
    {
        $site = $this->vectorSite();
        $body20 = rtrim(strtr(base64_encode(random_bytes(20)), '+/', '-_'), '=');

        foreach (['', 'v2.abc', 'v1.', 'v1.!!!', 'v1.'.$body20, 'v2.'.substr(self::LOGIN_VECTOR, 3), self::LOGIN_VECTOR.'='] as $token) {
            $this->assertNull($this->openLogin($site, $token), 'Accepted: '.$token);
        }

        $this->assertSame(0, TokenUse::count());
    }

    public function test_replay_is_rejected()
    {
        $site = $this->vectorSite();

        $this->assertIsArray($this->openLogin($site, self::LOGIN_VECTOR));
        $this->assertNull($this->openLogin($site, self::LOGIN_VECTOR));
        $this->assertSame(1, TokenUse::where('jti', '0123456789abcdef0123456789abcdef')->count());
    }

    /**
     * Two requests with the same token: the other one consumes the jti
     * between our lookup and our insert. The insert then hits the unique
     * index, and that must reject the token, not throw.
     */
    public function test_concurrent_consumption_is_rejected()
    {
        $site = $this->vectorSite();
        $raced = false;
        DB::listen(function ($query) use (&$raced) {
            if ($raced
                || stripos($query->sql, 'select') !== 0
                || strpos($query->sql, 'wordpresschat_token_uses') === false
            ) {
                return;
            }
            $raced = true;
            TokenUse::create(['jti' => '0123456789abcdef0123456789abcdef', 'expires_at' => now()->addMinutes(6)]);
        });

        // A savepoint stands in for "no transaction": on PostgreSQL the
        // failed insert would otherwise abort the test's own transaction.
        DB::beginTransaction();
        $payload = $this->openLogin($site, self::LOGIN_VECTOR);
        DB::rollBack();

        $this->assertTrue($raced);
        $this->assertNull($payload);
    }

    public function test_old_token_uses_are_purged_by_the_same_clock()
    {
        $site = $this->vectorSite();
        $stale = TokenUse::create(['jti' => str_repeat('a', 32), 'expires_at' => \Carbon\Carbon::createFromTimestamp(self::NOW - 86401)]);
        $recent = TokenUse::create(['jti' => str_repeat('b', 32), 'expires_at' => \Carbon\Carbon::createFromTimestamp(self::NOW - 86399)]);

        $this->assertIsArray($this->openLogin($site, self::LOGIN_VECTOR));

        $this->assertNull(TokenUse::find($stale->id));
        $this->assertNotNull(TokenUse::find($recent->id));
        $this->assertSame(1, TokenUse::where('jti', '0123456789abcdef0123456789abcdef')->count());
    }

    public function test_helper_tokens_open_with_the_real_clock()
    {
        $site = $this->makeSite();
        $chat = $this->newChatToken();

        $login = IdentityToken::open($site, $this->loginToken($site, $chat), 'login', hash('sha256', $chat));
        $order = IdentityToken::open($site, $this->orderToken($site, $chat, ['found' => false, 'email' => null, 'name' => null]), 'order', hash('sha256', $chat));

        $this->assertSame(42, $login['uid']);
        $this->assertSame('10482', $order['num']);
        $this->assertFalse($order['found']);
        $this->assertNull($order['email']);
    }

    public function test_unreadable_secret_rejects_and_edit_page_still_renders()
    {
        $site = $this->vectorSite();
        DB::table('wordpresschat_sites')->where('id', $site->id)->update(['secret' => 'garbage']);
        $site = Site::find($site->id);

        $this->assertNull($site->secretOrNull());
        $this->assertNull($this->openLogin($site, self::LOGIN_VECTOR));

        $html = $this->actingAs($this->adminUser())
            ->get(route('wordpresschat.sites.edit', ['id' => $site->id]))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString(e(__("The secret can't be read (the FreeScout app key changed?). Regenerate it and update the WordPress plugin.")), $html);
        $this->assertStringNotContainsString('id="wpc-secret"', $html);
    }

    public function test_token_with_trailing_newline_is_rejected()
    {
        $site = $this->vectorSite();

        $this->assertNull($this->openLogin($site, self::LOGIN_VECTOR."\n"));
        $this->assertNotNull($this->openLogin($site, self::LOGIN_VECTOR));
    }

    public function test_unreadable_secret_warns_once()
    {
        $site = $this->vectorSite();
        DB::table('wordpresschat_sites')->where('id', $site->id)->update(['secret' => 'garbage']);
        $site = Site::find($site->id);
        \Cache::forget('wordpresschat.secret_warned.'.$site->id);

        \Log::shouldReceive('warning')->once();

        $this->assertNull($site->secretOrNull());
        $this->assertNull($site->secretOrNull());
    }

    public function test_over_long_token_is_rejected_quietly()
    {
        $site = $this->vectorSite();
        \Log::shouldReceive('warning')->never();

        $token = 'v1.'.str_repeat('A', IdentityToken::MAX_TOKEN_LENGTH);

        $this->assertNull($this->openLogin($site, $token));
        $this->assertSame(0, TokenUse::count());
    }
}
