<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Thread;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Link;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Exceptions\SessionGoneException;
use Modules\WordPressChat\Services\ContinueLink;
use Modules\WordPressChat\Services\InboundMessage;
use Modules\WordPressChat\Services\VisitorAuth;
use Modules\WordPressChat\Services\VisitorIdentity;
use Modules\WordPressChat\Tests\TestCase;

/**
 * Plan 5 Task 6: continue links of trashed conversations and expired
 * retries, a link only from the request body, account discovery without
 * trashed chats, the orphan-session cleanup, site deletion, and a session
 * row that vanishes while a request uses it.
 */
class SessionHygieneTest extends TestCase
{
    protected function tearDown(): void
    {
        // test_vanished_session_answers_401 listens to Session retrievals.
        Session::flushEventListeners();

        parent::tearDown();
    }

    private function mint(Conversation $conversation): string
    {
        $url = ContinueLink::mint($conversation);
        $this->assertSame(1, preg_match('/#lcff-continue=([A-Za-z0-9_-]{43})$/', $url, $m), $url);

        return $m[1];
    }

    private function linkRow(string $link): Link
    {
        return Link::where('token_hash', hash('sha256', $link))->firstOrFail();
    }

    private function backdate(Session $session, int $hours): void
    {
        Session::where('id', $session->id)->update(['created_at' => now()->subHours($hours)]);
    }

    /** Counts of every table a redemption or a send writes to. */
    private function snapshot(): array
    {
        return [
            'sessions'  => Session::count(),
            'access'    => Access::count(),
            'verified'  => Access::whereNotNull('verified_at')->count(),
            'used'      => Link::whereNotNull('used_at')->count(),
            'threads'   => Thread::count(),
            'messages'  => Message::count(),
            'customers' => Customer::count(),
            'cutoffs'   => ConversationState::whereNotNull('cutoff_message_id')->count(),
        ];
    }

    public function test_link_of_a_deleted_conversation_is_410()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $stranger = $this->startChat($site, 'Another visitor');
        $link = $this->mint($chat['conversation']);

        // Trashed by an agent; a merged-away conversation is trashed by core too.
        Conversation::where('id', $chat['conversation']->id)->update(['state' => Conversation::STATE_DELETED]);
        $before = $this->snapshot();

        $new = $this->newChatToken();
        $this->api('POST', $site, '/continue', null, ['link' => $link, 'new_token' => $new])
            ->assertStatus(410)->assertExactJson(['error' => 'link_invalid']);
        $this->api('POST', $site, '/continue', $stranger['token'], ['link' => $link])
            ->assertStatus(410)->assertExactJson(['error' => 'link_invalid']);
        $this->api('POST', $site, '/continue', $chat['token'], ['link' => $link])
            ->assertStatus(410)->assertExactJson(['error' => 'link_invalid']);

        $this->assertNull(ContinueLink::find($site, $link));
        $this->assertNull(ContinueLink::redeem($site, $chat['session'], $link));

        $this->assertNull(Session::where('token_hash', hash('sha256', $new))->first());
        $this->assertNull($this->linkRow($link)->used_at);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_used_and_expired_link_retry_is_410()
    {
        $site = $this->makeSite();
        $link = $this->mint($this->startChat($site)['conversation']);

        $new = $this->newChatToken();
        $this->api('POST', $site, '/continue', null, ['link' => $link, 'new_token' => $new])->assertStatus(200);
        $session = Session::where('token_hash', hash('sha256', $new))->firstOrFail();

        // While the link is valid, the redeeming browser may retry.
        $this->api('POST', $site, '/continue', $new, ['link' => $link])->assertStatus(200);
        $this->assertTrue(ContinueLink::usedBy($site, $link, $session));

        Link::where('id', $this->linkRow($link)->id)->update(['expires_at' => now()->subMinute()]);
        $before = $this->snapshot();

        $this->assertFalse(ContinueLink::usedBy($site, $link, $session));
        $this->api('POST', $site, '/continue', $new, ['link' => $link])
            ->assertStatus(410)->assertExactJson(['error' => 'link_invalid']);
        $this->api('POST', $site, '/continue', null, ['link' => $link, 'new_token' => $new])
            ->assertStatus(410)->assertExactJson(['error' => 'link_invalid']);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_link_in_query_string_is_ignored()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $link = $this->mint($chat['conversation']);
        $before = $this->snapshot();

        $this->api('POST', $site, '/continue?link='.$link, $chat['token'])
            ->assertStatus(422)->assertExactJson(['error' => 'validation', 'field' => 'link']);
        $this->api('POST', $site, '/continue?link='.$link, null, ['new_token' => $this->newChatToken()])
            ->assertStatus(422)->assertExactJson(['error' => 'validation', 'field' => 'link']);

        $this->assertNull($this->linkRow($link)->used_at);
        $this->assertSame($before, $this->snapshot());

        // The same link in the body still works.
        $this->api('POST', $site, '/continue', $chat['token'], ['link' => $link])->assertStatus(200);
    }

    public function test_account_discovery_ignores_deleted_conversations()
    {
        $site = $this->makeSite();
        $new = $this->newChatToken();
        $chat = $this->startChat($site, 'From my account', [
            'new_token'   => $new,
            'login_token' => $this->loginToken($site, $new),
        ]);
        $this->assertTrue(VisitorIdentity::hasAccountChats((int) $site->id, 42));

        Conversation::where('id', $chat['conversation']->id)->update(['state' => Conversation::STATE_DELETED]);
        $this->assertFalse(VisitorIdentity::hasAccountChats((int) $site->id, 42));

        $sessions = Session::count();
        $device = $this->newChatToken();
        $this->api('POST', $site, '/identity', null, [
            'login_token' => $this->loginToken($site, $device),
            'new_token'   => $device,
        ])->assertStatus(200)->assertExactJson(['linked' => false, 'limits' => ['attachment_mb' => 10]]);

        $this->assertSame($sessions, Session::count());
    }

    public function test_cleanup_purges_only_old_orphan_sessions()
    {
        $site = $this->makeSite();

        $oldOrphan = VisitorAuth::claim($site, $this->newChatToken());
        $youngOrphan = VisitorAuth::claim($site, $this->newChatToken());

        // An Access row only (a continue link's or a login's), no message.
        $withAccess = VisitorAuth::claim($site, $this->newChatToken());
        $conversation = $this->startChat($site)['conversation'];
        Access::create(['session_id' => $withAccess->id, 'conversation_id' => $conversation->id]);

        // A message row only.
        $withMessage = $this->startChat($site, 'Message only')['session'];
        Access::where('session_id', $withMessage->id)->delete();

        $this->backdate($oldOrphan, 25);
        $this->backdate($withAccess, 25);
        $this->backdate($withMessage, 25);
        $this->backdate($youngOrphan, 1);

        $before = Session::count();
        $deleted = VisitorAuth::purgeOrphans();

        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertSame($before - $deleted, Session::count());
        $this->assertNull(Session::find($oldOrphan->id));
        $this->assertNotNull(Session::find($youngOrphan->id));
        $this->assertNotNull(Session::find($withAccess->id));
        $this->assertNotNull(Session::find($withMessage->id));

        // The clock is a parameter: 24 hours later the young one is old.
        VisitorAuth::purgeOrphans(now()->addHours(24));
        $this->assertNull(Session::find($youngOrphan->id));
        $this->assertNotNull(Session::find($withAccess->id));

        // The hourly command runs it and prints both counts.
        $another = VisitorAuth::claim($site, $this->newChatToken());
        $this->backdate($another, 25);
        $this->assertSame(0, \Artisan::call('wordpresschat:cleanup'));
        $output = \Artisan::output();
        $this->assertMatchesRegularExpression('/Rate-limit counters deleted: \d+/', $output);
        $this->assertMatchesRegularExpression('/Orphan sessions deleted: [1-9]\d*/', $output);
        $this->assertNull(Session::find($another->id));
    }

    /**
     * Final review M4: one purge failing doesn't stop the other; the
     * failure is logged and the command exits with 1.
     */
    public function test_cleanup_runs_each_purge_even_when_the_other_fails()
    {
        $site = $this->makeSite();
        $kernel = $this->app[\Illuminate\Contracts\Console\Kernel::class];
        $expiredBucket = function () {
            $bucket = hash('sha256', random_bytes(16));
            \DB::table('wordpresschat_rate_limits')->insert([
                'bucket'     => $bucket,
                'hits'       => 1,
                'expires_at' => now()->subHour()->format('Y-m-d H:i:s'),
            ]);

            return $bucket;
        };

        // The rate-limit purge fails: the orphan sessions still go.
        $orphan = VisitorAuth::claim($site, $this->newChatToken());
        $this->backdate($orphan, 25);
        $kernel->registerCommand(new class() extends \Modules\WordPressChat\Console\Cleanup {
            protected function purgeRateLimits(): int
            {
                throw new \RuntimeException('rate limits broke');
            }
        });
        \Log::spy();

        $this->assertSame(1, \Artisan::call('wordpresschat:cleanup'));
        $output = \Artisan::output();
        $this->assertMatchesRegularExpression('/Orphan sessions deleted: [1-9]\d*/', $output);
        $this->assertNull(Session::find($orphan->id));
        \Log::shouldHaveReceived('error')->once()->withArgs(function ($message) {
            return strpos($message, 'WordPressChat: ') === 0 && strpos($message, 'rate limits broke') !== false;
        });

        // The orphan purge fails: the expired counters still go.
        $bucket = $expiredBucket();
        $kernel->registerCommand(new class() extends \Modules\WordPressChat\Console\Cleanup {
            protected function purgeOrphanSessions(): int
            {
                throw new \RuntimeException('sessions broke');
            }
        });

        $this->assertSame(1, \Artisan::call('wordpresschat:cleanup'));
        $output = \Artisan::output();
        $this->assertMatchesRegularExpression('/Rate-limit counters deleted: [1-9]\d*/', $output);
        $this->assertSame(0, \DB::table('wordpresschat_rate_limits')->where('bucket', $bucket)->count());
        \Log::shouldHaveReceived('error')->withArgs(function ($message) {
            return strpos($message, 'sessions broke') !== false;
        });
    }

    public function test_deleting_a_site_purges_visitor_data_and_keeps_conversations()
    {
        $site = $this->makeSite();
        $other = $this->makeSite(['url' => 'https://other.test/', 'origin' => 'https://other.test']);

        $a = $this->startChat($site, 'First visitor');
        $b = $this->startChat($site, 'Second visitor');
        $this->mint($a['conversation']);
        $verified = $this->newChatToken();
        $this->api('POST', $site, '/continue', null, ['link' => $this->mint($b['conversation']), 'new_token' => $verified])
            ->assertStatus(200);
        $orphan = VisitorAuth::claim($site, $this->newChatToken());

        $keep = $this->startChat($other, 'Other site');
        $keepLink = $this->mint($keep['conversation']);

        $sessionIds = Session::where('site_id', $site->id)->pluck('id')->all();
        $this->assertCount(4, $sessionIds);
        $conversationIds = [(int) $a['conversation']->id, (int) $b['conversation']->id];
        $messageIds = Message::whereIn('conversation_id', $conversationIds)->pluck('id')->all();
        $this->assertNotEmpty($messageIds);

        $admin = $this->adminUser();
        $html = $this->actingAs($admin)->get("/wordpress-chat/sites/{$site->id}/edit")->assertStatus(200)->getContent();
        $this->assertStringContainsString(
            'data-confirm="Delete this website? Its conversations stay in FreeScout, but the chat on the website stops working."',
            $html
        );

        $this->actingAs($admin)->post("/wordpress-chat/sites/{$site->id}/delete")
            ->assertRedirect(route('wordpresschat.sites.index'));

        $this->assertNull(Site::find($site->id));
        $this->assertSame(0, Session::whereIn('id', $sessionIds)->count());
        $this->assertSame(0, Access::whereIn('session_id', $sessionIds)->count());
        $this->assertSame(0, Link::where('site_id', $site->id)->count());

        // Conversations, their states and message rows stay; the rows
        // no longer name a session.
        foreach ($conversationIds as $id) {
            $this->assertSame(Conversation::STATE_PUBLISHED, (int) Conversation::find($id)->state);
            $this->assertTrue(ConversationState::where('conversation_id', $id)->exists());
        }
        $this->assertSame(count($messageIds), Message::whereIn('id', $messageIds)->count());
        $this->assertSame(0, Message::whereIn('id', $messageIds)->whereNotNull('session_id')->count());
        $this->assertNull(Session::find($orphan->id));

        // The other site keeps everything.
        $this->assertNotNull(Session::find($keep['session']->id));
        $this->assertSame(1, Access::where('session_id', $keep['session']->id)->count());
        $this->assertSame(1, Message::where('session_id', $keep['session']->id)->count());
        $this->assertNotNull(ContinueLink::find($other, $keepLink));
    }

    /**
     * Final review I2: MySQL <= 5.7 and MariaDB < 10.2.4 reset
     * AUTO_INCREMENT to max(id) + 1 on a restart, so a site added after the
     * newest one was deleted can get its id. The deleted site's account
     * chats must not become the new site's.
     */
    public function test_a_new_site_that_reuses_a_deleted_sites_id_inherits_no_account_chats()
    {
        $site = $this->makeSite();
        $new = $this->newChatToken();
        $old = $this->startChat($site, 'From my account', [
            'new_token'   => $new,
            'login_token' => $this->loginToken($site, $new),
        ]);
        $oldConversationId = (int) $old['conversation']->id;
        $state = ConversationState::where('conversation_id', $oldConversationId)->firstOrFail();
        $this->assertSame(42, (int) $state->wp_user_id);
        $this->assertTrue(VisitorIdentity::hasAccountChats((int) $site->id, 42));

        $oldId = (int) $site->id;
        $this->actingAs($this->adminUser())->post("/wordpress-chat/sites/{$oldId}/delete")
            ->assertRedirect(route('wordpresschat.sites.index'));

        // The state stays (agents keep the history) but names no site.
        $this->assertSame(0, (int) $state->fresh()->site_id);
        $this->assertSame(42, (int) $state->fresh()->wp_user_id);

        // A new site with the old id.
        $reused = new Site([
            'name'       => 'New shop',
            'url'        => 'https://new-shop.test/',
            'origin'     => 'https://new-shop.test',
            'mailbox_id' => $this->testMailbox()->id,
            'public_id'  => bin2hex(random_bytes(16)),
        ]);
        $reused->id = $oldId;
        $reused->setSecret('s2');
        $reused->save();
        $this->assertSame($oldId, (int) Site::where('public_id', $reused->public_id)->value('id'));

        $this->assertFalse(VisitorIdentity::hasAccountChats($oldId, 42));

        // Discovery: WordPress user 42 of the new site has no chats there.
        $sessions = Session::count();
        $device = $this->newChatToken();
        $this->api('POST', $reused, '/identity', null, [
            'login_token' => $this->loginToken($reused, $device),
            'new_token'   => $device,
        ])->assertStatus(200)->assertExactJson(['linked' => false, 'limits' => ['attachment_mb' => 10]]);
        $this->assertSame($sessions, Session::count());

        // A browser with a chat of its own logs in as user 42: it gets no
        // Access row on the deleted site's conversation.
        $chat = $this->startChat($reused, 'Hello from the new shop');
        $this->api('POST', $reused, '/identity', $chat['token'], [
            'login_token' => $this->loginToken($reused, $chat['token']),
        ])->assertStatus(200);

        $granted = Access::where('session_id', $chat['session']->id)->pluck('conversation_id')->map(function ($id) {
            return (int) $id;
        })->all();
        $this->assertContains((int) $chat['conversation']->id, $granted);
        $this->assertNotContains($oldConversationId, $granted);
        $this->assertSame(0, Access::where('conversation_id', $oldConversationId)->count());
    }

    public function test_vanished_session_is_handled_not_500()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $session = $chat['session'];
        $link = $this->mint($chat['conversation']);
        $first = Message::where('session_id', $session->id)->firstOrFail();

        // Deleted by the cleanup or with its site while a request holds it.
        Session::where('id', $session->id)->delete();
        $before = $this->snapshot();

        try {
            InboundMessage::receive($site, $session, 'Still there?', 'gone-000001');
            $this->fail('receive() should throw SessionGoneException.');
        } catch (SessionGoneException $e) {
            // Expected: the API answers 401.
        }

        try {
            ContinueLink::redeem($site, $session, $link);
            $this->fail('redeem() should throw SessionGoneException.');
        } catch (SessionGoneException $e) {
            // Expected: the API answers 401.
        }

        $login = ['uid' => 42, 'email' => 'anna@example.test', 'name' => 'Anna Example'];
        $this->assertFalse(VisitorIdentity::login($session, $login));
        $this->assertFalse(VisitorIdentity::typedEmail($session, 'anna@example.test'));
        $this->assertFalse(VisitorIdentity::order($session, [
            'num' => '10482', 'found' => true, 'email' => 'anna@example.test', 'name' => 'Anna Example',
        ]));
        $this->assertFalse(VisitorIdentity::logout($session));

        $first->wasRecentlyCreated = true;
        VisitorIdentity::accountConversationStarted($session, $first, $login);

        $this->assertNull($this->linkRow($link)->used_at);
        $this->assertSame($before, $this->snapshot());
    }

    /**
     * The same through the API: the row is deleted right after the
     * controller resolved the token (a listener in this test, no hook in
     * the module), so the session lock comes back empty.
     */
    public function test_vanished_session_answers_401()
    {
        $site = $this->makeSite();
        $chats = [];
        foreach (['send', 'continue', 'email', 'login', 'logout'] as $i => $case) {
            $chats[$case] = $this->startChat($site, 'Chat '.$case, [], ['REMOTE_ADDR' => '198.51.100.'.(20 + $i)]);
        }
        $link = $this->mint($chats['continue']['conversation']);

        Session::retrieved(function (Session $session) {
            Session::where('id', $session->id)->delete();
        });

        $before = $this->snapshot();

        $this->api('POST', $site, '/messages', $chats['send']['token'], [
            'text' => 'Anyone?', 'client_message_id' => 'gone-000002',
        ])->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);

        $this->api('POST', $site, '/continue', $chats['continue']['token'], ['link' => $link])
            ->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);

        $this->api('POST', $site, '/identity', $chats['email']['token'], ['email' => 'anna@example.test'])
            ->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);

        $this->api('POST', $site, '/identity', $chats['login']['token'], [
            'login_token' => $this->loginToken($site, $chats['login']['token']),
        ])->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);

        $this->api('POST', $site, '/logout', $chats['logout']['token'])
            ->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);

        $this->assertNull($this->linkRow($link)->used_at);
        $after = $this->snapshot();
        $this->assertSame($before['sessions'] - 5, $after['sessions']);
        unset($before['sessions'], $after['sessions']);
        $this->assertSame($before, $after);
    }

    public function test_site_purge_deletes_links_in_batches()
    {
        $site = $this->makeSite();
        $other = $this->makeSite(['url' => 'https://other.test/', 'origin' => 'https://other.test']);
        $conversation = $this->startChat($site)['conversation'];
        $keep = $this->mint($this->startChat($other)['conversation']);

        $rows = [];
        for ($i = 0; $i < 2500; $i++) {
            $rows[] = [
                'site_id'         => $site->id,
                'conversation_id' => $conversation->id,
                'token_hash'      => hash('sha256', 'bulk-'.$i),
                'expires_at'      => now()->addDay(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Link::insert($chunk);
        }
        $this->assertSame(2500, Link::where('site_id', $site->id)->count());

        $deletes = [];
        \DB::listen(function ($query) use (&$deletes) {
            if (preg_match('/^delete from [`"]wordpresschat_links[`"]/i', $query->sql)) {
                $deletes[] = count($query->bindings);
            }
        });

        $site->purgeVisitorData();

        $this->assertSame(0, Link::where('site_id', $site->id)->count());
        $this->assertNotNull($this->linkRow($keep));
        $this->assertGreaterThanOrEqual(3, count($deletes));
        $this->assertLessThanOrEqual(1000, max($deletes));
    }
}
