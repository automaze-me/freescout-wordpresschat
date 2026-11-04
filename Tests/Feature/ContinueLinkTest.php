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
use Modules\WordPressChat\Level;
use Modules\WordPressChat\Services\AccessLevel;
use Modules\WordPressChat\Services\ContinueLink;
use Modules\WordPressChat\Services\IdentityLineItem;
use Modules\WordPressChat\Services\Visibility;
use Modules\WordPressChat\Tests\TestCase;

/**
 * Spec §6.2 and §6.4 rule 8: a single-use continue link, redeemed by an
 * explicit POST, verifies that browser for the conversation and cuts off
 * every other unverified browser on it.
 */
class ContinueLinkTest extends TestCase
{
    private $n = 0;

    /** The token of a minted continue-link URL. */
    private function tokenOf(string $url): string
    {
        $this->assertSame(1, preg_match('/#lcff-continue=([A-Za-z0-9_-]{43})$/', $url, $m), $url);

        return $m[1];
    }

    private function mint(Conversation $conversation): string
    {
        return $this->tokenOf(ContinueLink::mint($conversation));
    }

    /** Redeems $link from a browser without a chat token. Returns [response, new token]. */
    private function redeemAsNewBrowser(Site $site, string $link): array
    {
        $new = $this->newChatToken();

        return [$this->api('POST', $site, '/continue', null, ['link' => $link, 'new_token' => $new]), $new];
    }

    private function poll(Site $site, string $token): array
    {
        $response = $this->api('GET', $site, '/messages', $token);
        $response->assertStatus(200);

        return $response->json();
    }

    private function texts(array $json): array
    {
        return array_map(function ($text) {
            return trim(strip_tags($text));
        }, array_column($json['messages'], 'text'));
    }

    private function say(Site $site, string $token, string $text)
    {
        $response = $this->api('POST', $site, '/messages', $token, [
            'text'              => $text,
            'client_message_id' => 'cont-'.str_pad(++$this->n, 6, '0', STR_PAD_LEFT),
        ]);
        $response->assertStatus(201);

        return $response;
    }

    /** A published agent reply in the chat, with its OUT row. */
    private function agentReply(Conversation $conversation, string $text): Message
    {
        $agent = $this->adminUser();
        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type = Thread::TYPE_MESSAGE;
        $thread->status = Thread::STATUS_ACTIVE;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->body = '<p>'.$text.'</p>';
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->source_via = Thread::PERSON_USER;
        $thread->user_id = $agent->id;
        $thread->created_by_user_id = $agent->id;
        $thread->save();

        return Message::create([
            'conversation_id' => $conversation->id,
            'thread_id'       => $thread->id,
            'direction'       => Message::DIRECTION_OUT,
            'published_at'    => now(),
        ]);
    }

    /** A customer email reply, recorded by Task 4's listener. Returns its via-email row. */
    private function customerEmail(Conversation $conversation, string $text): Message
    {
        $conversation = Conversation::find($conversation->id);
        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type = Thread::TYPE_CUSTOMER;
        $thread->status = Thread::STATUS_ACTIVE;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->body = '<p>'.$text.'</p>';
        $thread->source_type = Thread::SOURCE_TYPE_EMAIL;
        $thread->source_via = Thread::PERSON_CUSTOMER;
        $thread->customer_id = $conversation->customer_id;
        $thread->created_by_customer_id = $conversation->customer_id;
        $thread->save();

        \Eventy::action('conversation.customer_replied', $conversation, $thread, $conversation->customer);

        return Message::where('thread_id', $thread->id)->where('via', Message::VIA_EMAIL)->firstOrFail();
    }

    private function typeEmail(Site $site, string $token, string $email): void
    {
        $this->api('POST', $site, '/identity', $token, ['email' => $email])->assertStatus(200);
    }

    private function sessionOf(string $token): Session
    {
        return Session::where('token_hash', hash('sha256', $token))->firstOrFail();
    }

    public function test_mint_returns_the_site_url_with_a_fragment_token()
    {
        $site = $this->makeSite(['url' => 'https://shop.test/']);
        $conversation = $this->startChat($site)['conversation'];

        $url = ContinueLink::mint($conversation);

        $this->assertMatchesRegularExpression('/\Ahttps:\/\/shop\.test\/#lcff-continue=[A-Za-z0-9_-]{43}$/', $url);
        $token = $this->tokenOf($url);

        $link = Link::where('conversation_id', $conversation->id)->firstOrFail();
        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertSame((int) $site->id, (int) $link->site_id);
        $this->assertNull($link->used_at);
        $this->assertNull($link->used_by_session_id);
        foreach ($link->getAttributes() as $value) {
            $this->assertStringNotContainsString($token, (string) $value);
        }

        $expected = now()->addDays(ContinueLink::TTL_DAYS);
        $this->assertSame(30, ContinueLink::TTL_DAYS);
        $this->assertLessThanOrEqual(60, abs($link->expires_at->diffInSeconds($expected, false)));

        // Every call mints a fresh token.
        $this->assertNotSame($token, $this->mint($conversation));
        $this->assertSame(2, Link::where('conversation_id', $conversation->id)->count());
    }

    public function test_mint_needs_the_conversations_website()
    {
        $site = $this->makeSite();
        $conversation = $this->startChat($site)['conversation'];
        ConversationState::where('conversation_id', $conversation->id)->update(['site_id' => 0]);

        try {
            ContinueLink::mint($conversation);
            $this->fail('mint() must not make a link without a website.');
        } catch (\RuntimeException $e) {
            $this->assertSame(0, Link::where('conversation_id', $conversation->id)->count());
        }
    }

    public function test_new_browser_redeems_and_sees_full_history()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Hello');
        $conversation = $chat['conversation'];
        $this->agentReply($conversation, 'Agent reply');
        $email = $this->customerEmail($conversation, 'Reply from the inbox');
        $link = $this->mint($conversation);

        list($response, $new) = $this->redeemAsNewBrowser($site, $link);

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame($new, $json['token']);
        $this->assertSame(['level' => 'verified', 'account' => false], $json['identity']);
        $this->assertSame(ConversationState::of($conversation)->public_id, $json['conversation']['key']);
        $this->assertNull($json['conversation']['notice']);

        $session = $this->sessionOf($new);
        $access = Access::where('session_id', $session->id)->where('conversation_id', $conversation->id)->firstOrFail();
        $this->assertNotNull($access->verified_at);
        $this->assertSame((int) Conversation::find($conversation->id)->customer_id, (int) $session->customer_id);

        $stored = Link::where('token_hash', hash('sha256', $link))->firstOrFail();
        $this->assertNotNull($stored->used_at);
        $this->assertSame((int) $session->id, (int) $stored->used_by_session_id);

        $items = Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_LINEITEM)->get()
            ->filter(function ($item) {
                return strpos($item->body, 'Email verified via link') !== false;
            });
        $this->assertCount(1, $items);
        $this->assertTrue((bool) $items->first()->getMeta(IdentityLineItem::LINEITEM_META));

        $poll = $this->poll($site, $new);
        $this->assertSame(['Hello', 'Agent reply', 'Reply from the inbox'], $this->texts($poll));
        $this->assertSame(['chat', 'chat', 'email'], array_column($poll['messages'], 'via'));
        $this->assertContains((int) $email->id, array_column($poll['messages'], 'id'));

        // The email's cut-off came first and stays.
        $state = ConversationState::of($conversation);
        $this->assertSame((int) $email->id, (int) $state->cutoff_message_id);
        $this->assertSame(ConversationState::CUTOFF_EMAIL, (int) $state->cutoff_reason);
    }

    public function test_redeeming_cuts_off_the_stranger()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Hello');
        $conversation = $chat['conversation'];
        $this->typeEmail($site, $chat['token'], 'anna@example.test');
        $before = $this->agentReply($conversation, 'Before the link');
        $this->assertSame(Level::EMAIL, AccessLevel::badge($conversation));

        $link = $this->mint($conversation);
        $idBeforeRedeem = (int) Message::where('conversation_id', $conversation->id)->max('id');

        // The second browser already chats on this site under its own token.
        $other = $this->startChat($site, 'Other chat');
        $response = $this->api('POST', $site, '/continue', $other['token'], ['link' => $link]);
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('token', $response->json());

        $state = ConversationState::of($conversation);
        $this->assertSame($idBeforeRedeem + 1, (int) $state->cutoff_message_id);
        $this->assertSame(ConversationState::CUTOFF_VERIFIED, (int) $state->cutoff_reason);

        $after = $this->agentReply($conversation, 'After the link');

        $json = $this->poll($site, $chat['token']);
        $this->assertSame(['Hello', 'Before the link'], $this->texts($json));
        $this->assertNotContains((int) $after->id, array_column($json['messages'], 'id'));
        $this->assertSame('continues_elsewhere', $json['conversation']['notice']);
        $this->assertSame('email', $json['identity']['level']);

        // The verified browser sees both replies, and its own other chat.
        $json = $this->poll($site, $other['token']);
        $this->assertSame(['Hello', 'Before the link', 'Other chat', 'After the link'], $this->texts($json));
        $this->assertContains((int) $before->id, array_column($json['messages'], 'id'));
        $this->assertContains((int) $after->id, array_column($json['messages'], 'id'));

        $this->assertSame(Level::VERIFIED, AccessLevel::badge($conversation));
    }

    public function test_an_earlier_customer_email_keeps_its_cutoff()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Hello');
        $conversation = $chat['conversation'];
        $this->typeEmail($site, $chat['token'], 'anna@example.test');
        $email = $this->customerEmail($conversation, 'Reply from the inbox');
        $link = $this->mint($conversation);

        list($response) = $this->redeemAsNewBrowser($site, $link);
        $response->assertStatus(200);

        $state = ConversationState::of($conversation);
        $this->assertSame((int) $email->id, (int) $state->cutoff_message_id);
        $this->assertSame(ConversationState::CUTOFF_EMAIL, (int) $state->cutoff_reason);

        $this->agentReply($conversation, 'After the link');
        $json = $this->poll($site, $chat['token']);
        $this->assertSame(['Hello'], $this->texts($json));
        $this->assertSame('continues_by_email', $json['conversation']['notice']);
        $this->assertSame(Level::VERIFIED, AccessLevel::badge($conversation));
    }

    /**
     * Controller Ruling 1: one session holds a verified grant on A and a
     * cut-off chat grant on B. Each conversation keeps its own rules.
     */
    public function test_mixed_grants_stay_per_conversation()
    {
        $site = $this->makeSite();

        // B: this browser's own chat, cut off by a customer email.
        $mine = $this->startChat($site, 'B chat');
        $b = $mine['conversation'];
        $bEmail = $this->customerEmail($b, 'B inbox');

        // A: another browser's chat, with an email row of its own.
        $theirs = $this->startChat($site, 'A chat');
        $a = $theirs['conversation'];
        $aEmail = $this->customerEmail($a, 'A inbox');
        $this->assertGreaterThan((int) $b->id, (int) $a->id);

        $this->api('POST', $site, '/continue', $mine['token'], ['link' => $this->mint($a)])->assertStatus(200);

        $aAfter = $this->agentReply($a, 'A after');
        $bAfter = $this->agentReply($b, 'B after');
        $this->say($site, $mine['token'], 'Mine after');

        $grants = Visibility::grants($this->sessionOf($mine['token']));
        $this->assertTrue($grants[$a->id]['full']);
        $this->assertFalse($grants[$b->id]['full']);
        $this->assertNotNull($grants[$b->id]['cutoff']);

        $json = $this->poll($site, $mine['token']);
        $ids = array_column($json['messages'], 'id');
        $this->assertSame(['B chat', 'A chat', 'A inbox', 'A after', 'Mine after'], $this->texts($json));
        $this->assertContains((int) $aEmail->id, $ids);
        $this->assertContains((int) $aAfter->id, $ids);
        $this->assertNotContains((int) $bEmail->id, $ids);
        $this->assertNotContains((int) $bAfter->id, $ids);

        // "Mine after" went into A, the latest conversation this browser sees.
        $this->assertSame(
            (int) $a->id,
            (int) Message::where('session_id', $this->sessionOf($mine['token'])->id)->orderBy('id', 'desc')->first()->conversation_id
        );
    }

    public function test_link_is_single_use()
    {
        $site = $this->makeSite();
        $conversation = $this->startChat($site)['conversation'];
        $link = $this->mint($conversation);

        list($first, $firstToken) = $this->redeemAsNewBrowser($site, $link);
        $first->assertStatus(200);
        $usedAt = Link::where('token_hash', hash('sha256', $link))->firstOrFail()->used_at;

        list($second, $secondToken) = $this->redeemAsNewBrowser($site, $link);
        $second->assertStatus(410)->assertExactJson(['error' => 'link_invalid']);
        $this->assertNull(Session::where('token_hash', hash('sha256', $secondToken))->first());

        // Not even the browser that redeemed it can use it twice.
        $this->api('POST', $site, '/continue', $firstToken, ['link' => $link])
            ->assertStatus(410)->assertExactJson(['error' => 'link_invalid']);

        $stored = Link::where('token_hash', hash('sha256', $link))->firstOrFail();
        $this->assertSame((int) $this->sessionOf($firstToken)->id, (int) $stored->used_by_session_id);
        $this->assertEquals($usedAt, $stored->used_at);
        $this->assertSame(1, Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_LINEITEM)->where('body', 'like', '%Email verified via link%')->count());
    }

    public function test_expired_other_site_and_unknown_links_are_410()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);

        $expired = $this->mint($chat['conversation']);
        Link::where('token_hash', hash('sha256', $expired))->update(['expires_at' => now()->subMinute()]);

        $otherSite = $this->makeSite(['url' => 'https://other.test/', 'origin' => 'https://other.test']);
        $foreign = $this->mint($this->startChat($otherSite)['conversation']);

        $unknown = $this->newChatToken();

        foreach ([$expired, $foreign, $unknown] as $link) {
            list($response, $new) = $this->redeemAsNewBrowser($site, $link);
            $response->assertStatus(410)->assertExactJson(['error' => 'link_invalid']);
            // An invalid link never creates a session.
            $this->assertNull(Session::where('token_hash', hash('sha256', $new))->first());

            $this->api('POST', $site, '/continue', $chat['token'], ['link' => $link])
                ->assertStatus(410)->assertExactJson(['error' => 'link_invalid']);
        }

        $this->assertSame(0, Link::whereNotNull('used_at')->whereIn('token_hash', [
            hash('sha256', $expired), hash('sha256', $foreign),
        ])->count());
        $this->assertSame(0, Access::where('session_id', $chat['session']->id)->whereNotNull('verified_at')->count());
        $this->assertNull(ConversationState::of($chat['conversation'])->cutoff_message_id);
    }

    public function test_malformed_input_is_422_and_unknown_chat_token_401()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $link = $this->mint($chat['conversation']);

        foreach ([null, '', 'short', $link.'x', str_repeat('!', 43), ['x'], 42] as $bad) {
            $body = $bad === null ? [] : ['link' => $bad];
            $this->api('POST', $site, '/continue', $chat['token'], $body)
                ->assertStatus(422)->assertExactJson(['error' => 'validation', 'field' => 'link']);
        }

        foreach ([null, 'short', str_repeat('!', 43), ['x']] as $bad) {
            $body = ['link' => $link];
            if ($bad !== null) {
                $body['new_token'] = $bad;
            }
            $this->api('POST', $site, '/continue', null, $body)
                ->assertStatus(422)->assertExactJson(['error' => 'validation', 'field' => 'new_token']);
        }

        $this->api('POST', $site, '/continue', $this->newChatToken(), ['link' => $link])
            ->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);

        $this->assertNull(Link::where('token_hash', hash('sha256', $link))->firstOrFail()->used_at);
    }

    public function test_verified_browser_writes_as_the_conversation_customer()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Hello');
        $conversation = $chat['conversation'];
        $this->typeEmail($site, $chat['token'], 'anna@example.test');
        $customerId = (int) Conversation::find($conversation->id)->customer_id;

        list($response, $token) = $this->redeemAsNewBrowser($site, $this->mint($conversation));
        $response->assertStatus(200);

        $message = Message::find($this->say($site, $token, 'From the verified browser')->json()['message']['id']);
        $this->assertSame((int) $conversation->id, (int) $message->conversation_id);
        $this->assertSame($customerId, (int) $message->thread->created_by_customer_id);
        $this->assertSame($customerId, (int) $message->thread->customer_id);

        // Controller Ruling 2: a lapsed login clears the session's customer
        // (Task 6); the verified browser still writes as the conversation's
        // customer and no new placeholder is made.
        $session = $this->sessionOf($token);
        $session->wp_user_id = 7;
        $session->account_until = now()->subMinute();
        $session->save();
        $customers = Customer::count();

        $message = Message::find($this->say($site, $token, 'After the lapse')->json()['message']['id']);

        $this->assertNull($this->sessionOf($token)->customer_id);
        $this->assertSame((int) $conversation->id, (int) $message->conversation_id);
        $this->assertSame($customerId, (int) $message->thread->created_by_customer_id);
        $this->assertSame($customerId, (int) $message->thread->customer_id);
        $this->assertSame($customers, Customer::count());
    }

    public function test_get_does_not_redeem()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $link = $this->mint($chat['conversation']);

        $response = $this->call(
            'GET',
            '/wordpress-chat/api/v1/sites/'.$site->public_id.'/continue?link='.$link.'&new_token='.$this->newChatToken(),
            [], [], [],
            ['HTTP_ORIGIN' => $site->origin, 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CHAT_TOKEN' => $chat['token']]
        );

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertNull(Link::where('token_hash', hash('sha256', $link))->firstOrFail()->used_at);
        $this->assertSame(0, Access::where('session_id', $chat['session']->id)->whereNotNull('verified_at')->count());
    }
}
