<?php

namespace Modules\WordPressChat\Tests\Feature;

use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Services\ConversationHeader;
use Modules\WordPressChat\Services\Presence;
use Modules\WordPressChat\Tests\TestCase;

/** Presence line for agents: polls record the page path, the header shows it. */
class PresenceTest extends TestCase
{
    private function poll($site, string $token, ?string $page)
    {
        return $this->api('GET', $site, '/messages?after=0', $token, null, $page === null ? [] : ['X-Chat-Page' => $page]);
    }

    private function backdate(Session $session, int $seconds): void
    {
        Session::where('id', $session->id)->update(['last_seen_at' => now()->subSeconds($seconds)]);
    }

    public function test_poll_records_the_page_path()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);

        $this->poll($site, $chat['token'], '/my-account/orders')->assertStatus(200);

        $session = Session::find($chat['session']->id);
        $this->assertSame('/my-account/orders', $session->page_path);
        $this->assertNotNull($session->last_seen_at);
    }

    public function test_send_and_seen_do_not_record_presence()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);

        $this->api('POST', $site, '/seen', $chat['token'], ['message_ids' => []], ['X-Chat-Page' => '/x'])->assertStatus(204);
        $this->api('POST', $site, '/messages', $chat['token'], [
            'text' => 'Hi again', 'client_message_id' => bin2hex(random_bytes(8)),
        ], ['X-Chat-Page' => '/x'])->assertStatus(201);

        $this->assertNull(Session::find($chat['session']->id)->last_seen_at);
    }

    public function test_query_and_fragment_are_never_stored()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);

        $this->poll($site, $chat['token'], '/order?email=a@example.test#lcff-continue=x');
        $this->assertSame('/order', Session::find($chat['session']->id)->page_path);

        $this->poll($site, $chat['token'], '/other#frag');
        $this->assertSame('/other', Session::find($chat['session']->id)->page_path);
    }

    public function test_a_value_that_is_not_a_path_is_ignored()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);

        $this->poll($site, $chat['token'], 'https://evil.test/x');
        $this->assertNull(Session::find($chat['session']->id)->page_path);

        $this->poll($site, $chat['token'], null);
        $this->assertNull(Session::find($chat['session']->id)->last_seen_at);
    }

    public function test_control_characters_are_dropped_and_length_is_cut()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $s = $chat['session'];

        Presence::record($s, "/a\x01b\x7Fc");
        $this->assertSame('/abc', Session::find($s->id)->page_path);

        $this->backdate(Session::find($s->id), 100);
        Presence::record(Session::find($s->id), '/'.str_repeat('é', 400));
        $this->assertSame(255, mb_strlen(Session::find($s->id)->page_path, 'UTF-8'));
    }

    public function test_writes_are_throttled()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);

        $this->poll($site, $chat['token'], '/a');
        $this->backdate($chat['session'], 5);
        $before = Session::find($chat['session']->id)->last_seen_at->timestamp;

        // Same path, 5 s old: no write, so the backdated value stays.
        $this->poll($site, $chat['token'], '/a');
        $this->assertSame($before, Session::find($chat['session']->id)->last_seen_at->timestamp);

        // A changed path writes at once.
        $this->poll($site, $chat['token'], '/b');
        $session = Session::find($chat['session']->id);
        $this->assertSame('/b', $session->page_path);
        $this->assertGreaterThan($before, $session->last_seen_at->timestamp);

        // Same path but older than 20 s: written again.
        $this->backdate($session, 30);
        $old = Session::find($session->id)->last_seen_at->timestamp;
        $this->poll($site, $chat['token'], '/b');
        $this->assertGreaterThan($old, Session::find($session->id)->last_seen_at->timestamp);
    }

    public function test_line_shows_on_the_site_within_90s()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);

        $this->poll($site, $chat['token'], '/my-account/orders');
        $this->backdate($chat['session'], 80);

        $this->assertSame('On the site now · /my-account/orders', Presence::line($chat['conversation']));
        $this->assertStringContainsString('On the site now · /my-account/orders', ConversationHeader::render($chat['conversation']));
    }

    public function test_line_shows_left_ago_after_90s()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);

        $this->poll($site, $chat['token'], '/my-account/orders');
        $this->backdate($chat['session'], 600);

        $line = Presence::line($chat['conversation']);
        $this->assertSame('Left 10 minutes ago', $line);
        $this->assertStringNotContainsString('/my-account', $line);
    }

    public function test_presence_line_in_german()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);

        $this->poll($site, $chat['token'], '/my-account/orders');
        $this->backdate($chat['session'], 600);

        $locale = app()->getLocale();
        try {
            \Helper::setLocale('de');
            $this->assertSame('de', app()->getLocale());
            $line = Presence::line($chat['conversation']);
            $this->assertStringContainsString('vor 10 Minuten', $line);
            $this->assertStringStartsWith('Hat die Seite', $line);
        } finally {
            // Carbon follows the app locale through its Laravel service provider.
            app()->setLocale($locale);
        }
    }

    public function test_newest_of_several_browsers_wins()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);

        $other = $this->startChat($site, 'Other browser')['session'];
        Access::where('session_id', $other->id)->delete();
        Access::create(['session_id' => $other->id, 'conversation_id' => $chat['conversation']->id]);

        Session::where('id', $chat['session']->id)->update(['last_seen_at' => now()->subSeconds(500), 'page_path' => '/old']);
        Session::where('id', $other->id)->update(['last_seen_at' => now()->subSeconds(10), 'page_path' => '/new']);

        $this->assertSame('On the site now · /new', Presence::line($chat['conversation']));
    }

    public function test_a_session_without_access_is_ignored()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $stranger = $this->startChat($site, 'Elsewhere')['session'];
        Session::where('id', $stranger->id)->update(['last_seen_at' => now(), 'page_path' => '/stranger']);

        $this->assertNull(Presence::line($chat['conversation']));
    }

    public function test_no_poll_no_line()
    {
        $chat = $this->startChat($this->makeSite());

        $this->assertNull(Presence::line($chat['conversation']));
        $this->assertStringNotContainsString('wpc-presence', ConversationHeader::render($chat['conversation']));
    }

    public function test_the_path_is_escaped_in_the_header()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        Session::where('id', $chat['session']->id)->update(['last_seen_at' => now(), 'page_path' => '/<script>alert(1)</script>']);

        $html = ConversationHeader::render($chat['conversation']);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_cors_allows_the_page_header()
    {
        $site = $this->makeSite();
        $response = $this->call('OPTIONS', '/wordpress-chat/api/v1/sites/'.$site->public_id.'/messages', [], [], [], ['HTTP_ORIGIN' => $site->origin]);

        $this->assertStringContainsString('X-Chat-Page', $response->headers->get('Access-Control-Allow-Headers'));
    }
}
