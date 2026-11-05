<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Thread;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Services\IdentityLineItem;
use Modules\WordPressChat\Services\VisitorAuth;
use Modules\WordPressChat\Services\VisitorIdentity;
use Modules\WordPressChat\Tests\TestCase;

/**
 * Spec §5.1.5, §5.5, §6.2 and Rulings P4–P7, P12: a WordPress login token
 * makes chats account-bound, visible on every device holding a fresh login
 * of that account, and gone after logout or once the login lapsed.
 */
class AccountIdentityTest extends TestCase
{
    private $n = 0;

    private function cmid(): string
    {
        return 'acc-'.str_pad(++$this->n, 6, '0', STR_PAD_LEFT);
    }

    /** A first send that carries a login token sealed for its new chat token. */
    private function startAccountChat(Site $site, string $text = 'Hello', array $login = []): array
    {
        $new = $this->newChatToken();

        return $this->startChat($site, $text, [
            'new_token'   => $new,
            'login_token' => $this->loginToken($site, $new, $login),
        ]);
    }

    private function say(Site $site, string $token, string $text, array $body = [])
    {
        return $this->api('POST', $site, '/messages', $token, array_merge([
            'text'              => $text,
            'client_message_id' => $this->cmid(),
        ], $body));
    }

    private function poll(Site $site, string $token): array
    {
        $response = $this->api('GET', $site, '/messages', $token);
        $response->assertStatus(200);

        return $response->json();
    }

    private function texts(array $json): array
    {
        return array_map('trim', array_column($json['messages'], 'text'));
    }

    private function loginLineItems(Conversation $conversation)
    {
        return Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_LINEITEM)->get()
            ->filter(function ($t) {
                return (bool) $t->getMeta(IdentityLineItem::LINEITEM_META)
                    && strpos($t->body, 'Logged in as account') !== false;
            })->values();
    }

    private function stateOf(Conversation $conversation): ConversationState
    {
        return ConversationState::where('conversation_id', $conversation->id)->firstOrFail();
    }

    /** The token with one character of its ciphertext changed. */
    private function tamper(string $token): string
    {
        $token[20] = $token[20] === 'A' ? 'B' : 'A';

        return $token;
    }

    private function lapse(Session $session): void
    {
        $session = $session->fresh();
        $session->account_until = now()->subMinute();
        $session->save();
    }

    public function test_login_token_on_first_send_creates_an_account_conversation()
    {
        $site = $this->makeSite();
        $chat = $this->startAccountChat($site, 'Hello from my account');

        $json = $chat['response']->json();
        $this->assertSame(['level' => 'account', 'account' => true], $json['identity']);
        $this->assertNotNull($json['conversation']);

        $conversation = $chat['conversation']->fresh();
        $customer = Customer::find($conversation->customer_id);
        $this->assertSame('anna@example.test', $customer->getMainEmail());
        $this->assertSame('Anna Example', $customer->first_name);
        $this->assertSame('anna@example.test', $conversation->customer_email);
        $this->assertSame(42, (int) $this->stateOf($conversation)->wp_user_id);

        $items = $this->loginLineItems($conversation);
        $this->assertCount(1, $items);
        $this->assertStringContainsString('Logged in as account anna@example.test', $items[0]->body);

        // The thread is the account customer's, so core's events saw it (P7).
        $thread = Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_CUSTOMER)->first();
        $this->assertSame((int) $customer->id, (int) $thread->created_by_customer_id);

        $session = $chat['session']->fresh();
        $this->assertSame(42, (int) $session->wp_user_id);
        $this->assertTrue($session->accountFresh());
        $this->assertEqualsWithDelta(now()->addSeconds(VisitorIdentity::ACCOUNT_TTL)->timestamp, $session->account_until->timestamp, 5);
    }

    public function test_rejected_login_token_on_send_still_stores_the_message()
    {
        $site = $this->makeSite();

        foreach ([
            function ($site, $new) { return $this->tamper($this->loginToken($site, $new)); },
            function ($site, $new) { return $this->loginToken($site, $this->newChatToken()); },
            function ($site, $new) { return $this->orderToken($site, $new); },
            function ($site, $new) { return $this->loginToken($site, $new, ['uid' => 0]); },
            function ($site, $new) { return 12345; },
            function ($site, $new) { return ['x']; },
        ] as $i => $make) {
            $new = $this->newChatToken();
            $response = $this->api('POST', $site, '/messages', null, [
                'text'              => 'Still here '.$i,
                'client_message_id' => $this->cmid(),
                'new_token'         => $new,
                'login_token'       => $make($site, $new),
            ]);

            $response->assertStatus(201);
            $this->assertSame(['level' => 'anonymous', 'account' => false], $response->json()['identity'], 'case '.$i);

            $message = Message::find($response->json()['message']['id']);
            $this->assertNull($this->stateOf(Conversation::find($message->conversation_id))->wp_user_id);
            $this->assertNull(Session::find($message->session_id)->wp_user_id);
        }
    }

    public function test_login_token_on_a_later_send_binds_the_open_conversation()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Anonymous first');

        $this->say($site, $chat['token'], 'Now logged in', [
            'login_token' => $this->loginToken($site, $chat['token']),
        ])->assertStatus(201)->assertJsonFragment(['identity' => ['level' => 'account', 'account' => true]]);

        $conversation = $chat['conversation']->fresh();
        $this->assertSame(42, (int) $this->stateOf($conversation)->wp_user_id);
        $this->assertCount(1, $this->loginLineItems($conversation));
        $this->assertSame(1, Access::where('session_id', $chat['session']->id)->count());
    }

    /**
     * Final review M1 (Ruling P12, tightened): an anonymous chat this
     * browser last wrote in more than ACCOUNT_TTL ago was left by someone
     * else. A login neither binds it nor upgrades its placeholder.
     */
    public function test_login_does_not_bind_an_old_anonymous_chat()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Left here three hours ago');
        $placeholder = (int) $chat['session']->customer_id;
        Message::where('conversation_id', $chat['conversation']->id)->update(['created_at' => now()->subHours(3)]);

        $this->api('POST', $site, '/identity', $chat['token'], [
            'login_token' => $this->loginToken($site, $chat['token']),
        ])->assertStatus(200);

        $conversation = $chat['conversation']->fresh();
        $this->assertNull($this->stateOf($conversation)->wp_user_id);
        $this->assertCount(0, $this->loginLineItems($conversation));
        $this->assertSame($placeholder, (int) $conversation->customer_id);
        $this->assertSame('', (string) $conversation->customer_email);
        $this->assertSame('', Customer::find($placeholder)->getMainEmail());
        $this->assertSame('Website visitor', Customer::find($placeholder)->first_name);

        // The session itself is logged in, under the account's customer.
        $session = $chat['session']->fresh();
        $this->assertTrue($session->accountFresh());
        $this->assertNotSame($placeholder, (int) $session->customer_id);
        $this->assertSame('anna@example.test', Customer::find($session->customer_id)->getMainEmail());
    }

    /** M1: within ACCOUNT_TTL the open chat is still bound (P12). */
    public function test_login_binds_an_anonymous_chat_written_in_recently()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Written in an hour ago');
        Message::where('conversation_id', $chat['conversation']->id)->update(['created_at' => now()->subHour()]);

        $this->api('POST', $site, '/identity', $chat['token'], [
            'login_token' => $this->loginToken($site, $chat['token']),
        ])->assertStatus(200);

        $conversation = $chat['conversation']->fresh();
        $this->assertSame(42, (int) $this->stateOf($conversation)->wp_user_id);
        $this->assertCount(1, $this->loginLineItems($conversation));
        $this->assertSame('anna@example.test', $conversation->customer_email);
    }

    /** Re-review N3: a login-declined stale chat is not continued under the account. */
    public function test_a_declined_stale_chat_is_not_continued()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Left behind');
        $placeholder = (int) $chat['session']->customer_id;

        // Backdated rather than Carbon::setTestNow(): a frozen clock makes
        // core's TerminateHandler throw a deprecation on every request.
        Message::where('conversation_id', $chat['conversation']->id)
            ->update(['created_at' => now()->subSeconds(VisitorIdentity::ACCOUNT_TTL + 60)]);

        $this->api('POST', $site, '/identity', $chat['token'], [
            'login_token' => $this->loginToken($site, $chat['token'], ['uid' => 7]),
        ])->assertStatus(200);

        $sent = $this->say($site, $chat['token'], 'Hello again');
        $sent->assertStatus(201);

        $message = Message::find($sent->json()['message']['id']);
        $this->assertNotSame((int) $chat['conversation']->id, (int) $message->conversation_id);
        $y = Conversation::find($message->conversation_id);
        $this->assertSame('anna@example.test', Customer::find($y->customer_id)->getMainEmail());
        $this->assertSame((int) $chat['session']->fresh()->customer_id, (int) $y->customer_id);

        $x = $chat['conversation']->fresh();
        $this->assertSame(0, Thread::where('conversation_id', $x->id)->where('body', 'like', '%Hello again%')->count());
        $this->assertSame($placeholder, (int) $x->customer_id);
        $this->assertSame('', Customer::find($placeholder)->getMainEmail());
    }

    /** Guards Ruling P12: a recent anonymous chat is bound and continued. */
    public function test_a_recent_anonymous_chat_is_still_bound_and_continued()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Just started');

        $this->api('POST', $site, '/identity', $chat['token'], [
            'login_token' => $this->loginToken($site, $chat['token'], ['uid' => 7]),
        ])->assertStatus(200);

        $sent = $this->say($site, $chat['token'], 'Hello again');
        $sent->assertStatus(201);

        $message = Message::find($sent->json()['message']['id']);
        $this->assertSame((int) $chat['conversation']->id, (int) $message->conversation_id);
        $this->assertSame(7, (int) $this->stateOf($chat['conversation'])->wp_user_id);
    }

    public function test_second_device_discovers_account_chats()
    {
        $site = $this->makeSite();
        $a = $this->startAccountChat($site, 'Message from device A');

        $newB = $this->newChatToken();
        $response = $this->api('POST', $site, '/identity', null, [
            'login_token' => $this->loginToken($site, $newB),
            'new_token'   => $newB,
        ]);
        $response->assertStatus(200);

        $json = $response->json();
        $this->assertSame($newB, $json['token']);
        $this->assertSame(['level' => 'account', 'account' => true], $json['identity']);
        $this->assertSame($this->stateOf($a['conversation'])->public_id, $json['conversation']['key']);
        // Final review I1: discovery puts the new session in that chat.
        $this->assertSame(
            (int) $a['conversation']->id,
            (int) Session::where('token_hash', hash('sha256', $newB))->value('current_conversation_id')
        );

        $this->assertSame(['Message from device A'], $this->texts($this->poll($site, $newB)));

        // Device B writes into the same account conversation.
        $this->say($site, $newB, 'Reply from device B')->assertStatus(201);
        $this->assertSame(
            ['Message from device A', 'Reply from device B'],
            $this->texts($this->poll($site, $a['token']))
        );
    }

    /**
     * Re-review N1: device B is still on a closed account chat when device
     * A starts a new one. B's next login refresh moves it into A's open
     * chat, so B neither forks a third conversation nor stops polling.
     */
    public function test_refresh_moves_off_a_closed_chat_into_the_open_account_chat()
    {
        $site = $this->makeSite();
        $b = $this->startAccountChat($site, 'Earlier question from device B');
        $x = $b['conversation']->fresh();
        $x->status = Conversation::STATUS_CLOSED;
        $x->closed_at = now()->subHour();
        $x->save();

        $a = $this->startAccountChat($site, 'New question from device A');
        $y = $a['conversation'];
        $this->assertNotSame((int) $x->id, (int) $y->id);

        $response = $this->api('POST', $site, '/identity', $b['token'], [
            'login_token' => $this->loginToken($site, $b['token']),
        ]);
        $response->assertStatus(200);
        $this->assertSame($this->stateOf($y)->public_id, $response->json()['conversation']['key']);
        $this->assertSame('open', $response->json()['conversation']['status']);
        $this->assertTrue($response->json()['conversation']['poll']);

        $sent = $this->say($site, $b['token'], 'Follow-up from device B');
        $sent->assertStatus(201);
        $this->assertSame((int) $y->id, (int) Message::find($sent->json()['message']['id'])->conversation_id);
        $this->assertSame(2, Conversation::whereIn('id', Access::where('session_id', $b['session']->id)->pluck('conversation_id'))->count());
        $this->assertContains('Follow-up from device B', $this->texts($this->poll($site, $a['token'])));
    }

    /** N1 guard: an open conversation the session is in stays current. */
    public function test_refresh_keeps_an_open_current_conversation()
    {
        $site = $this->makeSite();
        $b = $this->startAccountChat($site, 'Question from device B');
        $x = $b['conversation']->fresh();
        $x->status = Conversation::STATUS_CLOSED;
        $x->closed_at = now()->subHour();
        $x->save();
        $a = $this->startAccountChat($site, 'Question from device A');

        // An agent reopens B's chat: both account chats are open now.
        $x->status = Conversation::STATUS_ACTIVE;
        $x->save();

        $this->api('POST', $site, '/identity', $b['token'], [
            'login_token' => $this->loginToken($site, $b['token']),
        ])->assertStatus(200)->assertJsonFragment(['key' => $this->stateOf($x)->public_id]);

        $sent = $this->say($site, $b['token'], 'Still about my question');
        $sent->assertStatus(201);
        $this->assertSame((int) $x->id, (int) Message::find($sent->json()['message']['id'])->conversation_id);
        $this->assertNotSame((int) $x->id, (int) $a['conversation']->id);
    }

    public function test_discovery_without_account_chats_creates_nothing()
    {
        $site = $this->makeSite();
        // Another account's chat on this site, and this account's chat on another site.
        $this->startAccountChat($site, 'Other account', ['uid' => 9, 'email' => 'nine@example.test']);
        $other = $this->makeSite(['origin' => 'https://other.test', 'url' => 'https://other.test/']);
        $this->startAccountChat($other, 'Other site');

        $sessions = Session::count();
        $customers = Customer::count();
        $new = $this->newChatToken();

        $this->api('POST', $site, '/identity', null, [
            'login_token' => $this->loginToken($site, $new),
            'new_token'   => $new,
        ])->assertStatus(200)->assertExactJson(['linked' => false, 'limits' => ['attachment_mb' => 10]]);

        $this->assertSame($sessions, Session::count());
        $this->assertSame($customers, Customer::count());
        $this->api('GET', $site, '/messages', $new)->assertStatus(401);
    }

    public function test_discovery_needs_a_new_token_and_a_valid_login_token()
    {
        $site = $this->makeSite();
        $this->startAccountChat($site);
        $new = $this->newChatToken();

        foreach ([null, 'short', str_repeat('a', 44), 123] as $bad) {
            $body = ['login_token' => $this->loginToken($site, $new)];
            if ($bad !== null) {
                $body['new_token'] = $bad;
            }
            $this->api('POST', $site, '/identity', null, $body)
                ->assertStatus(422)->assertExactJson(['error' => 'validation', 'field' => 'new_token']);
        }

        // Bound to another chat token, tampered, not a string.
        foreach ([$this->loginToken($site, $this->newChatToken()), $this->tamper($this->loginToken($site, $new)), ['x'], 7] as $bad) {
            $this->api('POST', $site, '/identity', null, ['login_token' => $bad, 'new_token' => $new])
                ->assertStatus(403)->assertExactJson(['error' => 'identity_rejected']);
        }

        $this->assertNull(Session::where('token_hash', hash('sha256', $new))->first());
    }

    public function test_discovery_with_a_token_taken_on_another_site_is_409()
    {
        $site = $this->makeSite();
        $this->startAccountChat($site);
        $other = $this->makeSite(['origin' => 'https://other.test', 'url' => 'https://other.test/']);
        $taken = $this->newChatToken();
        VisitorAuth::claim($other, $taken);

        $this->api('POST', $site, '/identity', null, [
            'login_token' => $this->loginToken($site, $taken),
            'new_token'   => $taken,
        ])->assertStatus(409)->assertExactJson(['error' => 'conflict']);
    }

    public function test_tokenless_email_and_order_are_401()
    {
        $site = $this->makeSite();
        $new = $this->newChatToken();

        $this->api('POST', $site, '/identity', null, ['email' => 'a@example.test', 'new_token' => $new])
            ->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);
        $this->api('POST', $site, '/identity', null, ['order_token' => $this->orderToken($site, $new), 'new_token' => $new])
            ->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);
        $this->api('POST', $site, '/identity', $this->newChatToken(), ['login_token' => $this->loginToken($site, $new), 'new_token' => $new])
            ->assertStatus(401);
    }

    public function test_logout_ends_account_access()
    {
        $site = $this->makeSite();
        $chat = $this->startAccountChat($site, 'Account chat');
        $this->assertSame(['Account chat'], $this->texts($this->poll($site, $chat['token'])));

        $this->api('POST', $site, '/logout', $chat['token'])->assertStatus(204);

        $json = $this->poll($site, $chat['token']);
        $this->assertSame([], $json['messages']);
        $this->assertNull($json['conversation']);
        $this->assertSame(['level' => 'anonymous', 'account' => false], $json['identity']);

        $session = $chat['session']->fresh();
        $this->assertNull($session->wp_user_id);
        $this->assertNull($session->account_until);
        $this->assertNull($session->customer_id);

        // Whoever writes next starts a new chat under a new placeholder.
        $account = $chat['conversation']->fresh()->customer_id;
        $next = $this->say($site, $chat['token'], 'Someone else');
        $next->assertStatus(201);
        $conversation = Conversation::find(Message::find($next->json()['message']['id'])->conversation_id);
        $this->assertNotSame((int) $chat['conversation']->id, (int) $conversation->id);
        $this->assertNotSame((int) $account, (int) $conversation->customer_id);
        $this->assertSame('', Customer::find($conversation->customer_id)->getMainEmail());
        $this->assertSame(['Someone else'], $this->texts($this->poll($site, $chat['token'])));
    }

    public function test_logout_keeps_a_typed_identity()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $this->api('POST', $site, '/identity', $chat['token'], ['email' => 'typed@example.test'])->assertStatus(200);
        $this->api('POST', $site, '/identity', $chat['token'], ['login_token' => $this->loginToken($site, $chat['token'])])
            ->assertStatus(200);
        $customer = $chat['session']->fresh()->customer_id;

        $this->api('POST', $site, '/logout', $chat['token'])->assertStatus(204);

        $session = $chat['session']->fresh();
        $this->assertNull($session->wp_user_id);
        $this->assertSame((int) $customer, (int) $session->customer_id);
        $this->assertSame(Session::IDENTITY_EMAIL, (int) $session->identity);
    }

    public function test_logout_needs_a_known_chat_token()
    {
        $site = $this->makeSite();

        $this->api('POST', $site, '/logout')->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);
        $this->api('POST', $site, '/logout', $this->newChatToken())->assertStatus(401);
    }

    public function test_lapsed_ttl_ends_account_access()
    {
        $site = $this->makeSite();
        $chat = $this->startAccountChat($site, 'Account chat');

        $this->lapse($chat['session']);

        $json = $this->poll($site, $chat['token']);
        $this->assertSame([], $json['messages']);
        $this->assertNull($json['conversation']);
        $this->assertSame(['level' => 'anonymous', 'account' => false], $json['identity']);

        // A fresh login of the same account brings it back.
        $this->api('POST', $site, '/identity', $chat['token'], ['login_token' => $this->loginToken($site, $chat['token'])])
            ->assertStatus(200);
        $this->assertSame(['Account chat'], $this->texts($this->poll($site, $chat['token'])));
    }

    public function test_lapsed_login_releases_the_account_customer()
    {
        $site = $this->makeSite();
        $chat = $this->startAccountChat($site, 'Account chat');
        $account = Customer::find($chat['conversation']->fresh()->customer_id);
        $this->lapse($chat['session']);

        $response = $this->say($site, $chat['token'], 'Whoever sits here now');
        $response->assertStatus(201);
        $this->assertSame(['level' => 'anonymous', 'account' => false], $response->json()['identity']);

        $conversation = Conversation::find(Message::find($response->json()['message']['id'])->conversation_id);
        $this->assertNotSame((int) $chat['conversation']->id, (int) $conversation->id);
        $this->assertNotSame((int) $account->id, (int) $conversation->customer_id);
        $this->assertSame('', Customer::find($conversation->customer_id)->getMainEmail());
        $this->assertNull($this->stateOf($conversation)->wp_user_id);

        $session = $chat['session']->fresh();
        $this->assertNull($session->wp_user_id);
        $this->assertNull($session->account_until);
        $this->assertSame((int) $conversation->customer_id, (int) $session->customer_id);

        // The account customer itself is untouched.
        $this->assertSame('anna@example.test', $account->fresh()->getMainEmail());
        $this->assertSame((int) $account->id, (int) $chat['conversation']->fresh()->customer_id);
        $this->assertSame(['Whoever sits here now'], $this->texts($this->poll($site, $chat['token'])));
    }

    public function test_another_account_on_the_same_browser_sees_nothing()
    {
        $site = $this->makeSite();
        $chat = $this->startAccountChat($site, 'Chat of account 7', ['uid' => 7, 'email' => 'seven@example.test']);
        $seven = (int) $chat['conversation']->fresh()->customer_id;

        $response = $this->api('POST', $site, '/identity', $chat['token'], [
            'login_token' => $this->loginToken($site, $chat['token'], ['uid' => 8, 'email' => 'eight@example.test', 'name' => 'Eight']),
        ]);
        $response->assertStatus(200);
        $this->assertNull($response->json()['conversation']);

        $json = $this->poll($site, $chat['token']);
        $this->assertSame([], $json['messages']);
        $this->assertNull($json['conversation']);

        // Account 8's chats are never filed under account 7's customer.
        $session = $chat['session']->fresh();
        $this->assertSame(8, (int) $session->wp_user_id);
        $this->assertNotSame($seven, (int) $session->customer_id);
        $this->assertSame('eight@example.test', Customer::find($session->customer_id)->getMainEmail());

        $next = $this->say($site, $chat['token'], 'Chat of account 8');
        $next->assertStatus(201);
        $conversation = Conversation::find(Message::find($next->json()['message']['id'])->conversation_id);
        $this->assertSame(8, (int) $this->stateOf($conversation)->wp_user_id);
        $this->assertSame((int) $session->customer_id, (int) $conversation->customer_id);
        $this->assertSame(['Chat of account 8'], $this->texts($this->poll($site, $chat['token'])));
        $this->assertSame(7, (int) $this->stateOf($chat['conversation'])->wp_user_id);
    }

    public function test_refresh_writes_no_second_line_item()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Anonymous first');
        $placeholder = (int) $chat['session']->customer_id;

        for ($i = 0; $i < 2; $i++) {
            $response = $this->api('POST', $site, '/identity', $chat['token'], [
                'login_token' => $this->loginToken($site, $chat['token']),
            ]);
            $response->assertStatus(200);
            $this->assertSame(['level' => 'account', 'account' => true], $response->json()['identity']);
        }

        $conversation = $chat['conversation']->fresh();
        $this->assertCount(1, $this->loginLineItems($conversation));
        $this->assertSame(42, (int) $this->stateOf($conversation)->wp_user_id);
        // The placeholder had no email: upgraded in place with the account's (P9).
        $this->assertSame($placeholder, (int) $conversation->customer_id);
        $this->assertSame('anna@example.test', Customer::find($placeholder)->getMainEmail());
        $this->assertSame('Anna Example', Customer::find($placeholder)->first_name);
        $this->assertSame('anna@example.test', $conversation->customer_email);

        // A login on the first send plus a refresh: still one line item.
        $other = $this->startAccountChat($site, 'With login', ['uid' => 43, 'email' => 'b@example.test']);
        $this->api('POST', $site, '/identity', $other['token'], [
            'login_token' => $this->loginToken($site, $other['token'], ['uid' => 43, 'email' => 'b@example.test']),
        ])->assertStatus(200);
        $this->assertCount(1, $this->loginLineItems($other['conversation']));
    }

    public function test_login_of_an_existing_customer_attaches_without_changes()
    {
        $site = $this->makeSite();
        $existing = Customer::create('main@example.test', ['first_name' => 'Real', 'last_name' => 'Person']);
        $existing->addEmail('anna@example.test');
        $chat = $this->startChat($site, 'Anonymous first');
        $placeholder = (int) $chat['session']->customer_id;

        $this->api('POST', $site, '/identity', $chat['token'], [
            'login_token' => $this->loginToken($site, $chat['token']),
        ])->assertStatus(200);

        $conversation = $chat['conversation']->fresh();
        $this->assertSame((int) $existing->id, (int) $conversation->customer_id);
        // The address the account uses, not the customer's main one.
        $this->assertSame('anna@example.test', $conversation->customer_email);
        $existing = $existing->fresh();
        $this->assertSame('Real', $existing->first_name);
        $this->assertSame('Person', $existing->last_name);
        $this->assertSame(['anna@example.test', 'main@example.test'], $existing->emails()->orderBy('email')->pluck('email')->all());
        $this->assertSame('', Customer::find($placeholder)->getMainEmail());

        $thread = Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_CUSTOMER)->first();
        $this->assertSame((int) $existing->id, (int) $thread->created_by_customer_id);
    }

    public function test_typed_email_conversation_stays_session_bound_on_login()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Typed first');
        $this->api('POST', $site, '/identity', $chat['token'], ['email' => 'typed@example.test'])->assertStatus(200);

        $this->api('POST', $site, '/identity', $chat['token'], [
            'login_token' => $this->loginToken($site, $chat['token']),
        ])->assertStatus(200)->assertJsonFragment(['identity' => ['level' => 'email', 'account' => true]]);

        $conversation = $chat['conversation']->fresh();
        $this->assertNull($this->stateOf($conversation)->wp_user_id);
        $this->assertCount(0, $this->loginLineItems($conversation));
        $this->assertSame('typed@example.test', Customer::find($conversation->customer_id)->getMainEmail());
    }

    public function test_login_token_bound_to_another_chat_token_is_403()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);

        foreach ([
            $this->loginToken($site, $this->newChatToken()),
            $this->tamper($this->loginToken($site, $chat['token'])),
            $this->orderToken($site, $chat['token']),
            ['x'],
            42,
        ] as $bad) {
            $this->api('POST', $site, '/identity', $chat['token'], ['login_token' => $bad])
                ->assertStatus(403)->assertExactJson(['error' => 'identity_rejected']);
        }

        $session = $chat['session']->fresh();
        $this->assertNull($session->wp_user_id);
        $this->assertNull($this->stateOf($chat['conversation'])->wp_user_id);
        $this->assertCount(0, $this->loginLineItems($chat['conversation']));
    }

    public function test_login_token_replay_is_403()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $token = $this->loginToken($site, $chat['token']);

        $this->api('POST', $site, '/identity', $chat['token'], ['login_token' => $token])->assertStatus(200);
        $this->api('POST', $site, '/identity', $chat['token'], ['login_token' => $token])
            ->assertStatus(403)->assertExactJson(['error' => 'identity_rejected']);
    }

    public function test_malformed_login_payload_is_403()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);

        foreach ([
            ['uid' => 0],
            ['uid' => -1],
            ['uid' => 2147483648],
            ['uid' => '42'],
            ['uid' => 4.2],
            ['email' => 'not-an-email'],
            ['email' => 'Anna Maier@example.test'],
            ['email' => str_repeat('a', 180).'@example.test'],
            ['email' => null],
            ['name' => str_repeat('x', 101)],
            ['name' => null],
            ['name' => ['x']],
        ] as $over) {
            $this->api('POST', $site, '/identity', $chat['token'], [
                'login_token' => $this->loginToken($site, $chat['token'], $over),
            ])->assertStatus(403)->assertExactJson(['error' => 'identity_rejected']);
        }

        $this->assertNull($chat['session']->fresh()->wp_user_id);
        $this->assertSame('', Customer::find($chat['session']->customer_id)->getMainEmail());
    }

    public function test_account_access_is_bound_to_the_site()
    {
        $site = $this->makeSite();
        $chat = $this->startAccountChat($site, 'Site one');
        $other = $this->makeSite(['origin' => 'https://other.test', 'url' => 'https://other.test/']);

        // A session of the same WordPress user id on another site, with a
        // (forged) Access row to the first site's conversation.
        $token = $this->newChatToken();
        $foreign = VisitorAuth::claim($other, $token);
        $foreign->wp_user_id = 42;
        $foreign->account_until = now()->addHour();
        $foreign->save();
        Access::create(['session_id' => $foreign->id, 'conversation_id' => $chat['conversation']->id]);

        $json = $this->poll($other, $token);
        $this->assertSame([], $json['messages']);
        $this->assertNull($json['conversation']);

        // A login there fans out to that site's account chats only.
        $this->api('POST', $other, '/identity', $token, ['login_token' => $this->loginToken($other, $token)])
            ->assertStatus(200);
        $this->assertSame([], $this->poll($other, $token)['messages']);
        $this->assertSame(1, Access::where('session_id', $foreign->id)->count());
    }
}
