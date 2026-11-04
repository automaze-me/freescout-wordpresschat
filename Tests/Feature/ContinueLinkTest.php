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

    public function test_originator_redeeming_its_own_link_keeps_one_access_row()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $link = $this->mint($chat['conversation']);

        $response = $this->api('POST', $site, '/continue', $chat['token'], ['link' => $link]);
        $response->assertStatus(200);
        $this->assertSame('verified', $response->json()['identity']['level']);

        $rows = Access::where('session_id', $chat['session']->id)->where('conversation_id', $chat['conversation']->id)->get();
        $this->assertCount(1, $rows);
        $this->assertNotNull($rows[0]->verified_at);
    }

    public function test_new_token_taken_on_another_site_is_409()
    {
        $siteA = $this->makeSite(['url' => 'https://a.test/']);
        $siteB = $this->makeSite(['url' => 'https://b.test/']);
        $taken = $this->startChat($siteA)['token'];
        $conversationB = $this->startChat($siteB)['conversation'];
        $link = $this->mint($conversationB);

        $this->api('POST', $siteB, '/continue', null, ['link' => $link, 'new_token' => $taken])
            ->assertStatus(409)->assertExactJson(['error' => 'conflict']);

        $this->assertNull(Link::where('token_hash', hash('sha256', $link))->firstOrFail()->used_at);
    }

    public function test_redeeming_shows_none_of_the_customers_other_conversations()
    {
        $site = $this->makeSite();
        $one = $this->startChat($site, 'First chat');
        $two = $this->startChat($site, 'Second chat secret');
        $this->typeEmail($site, $one['token'], 'same@example.test');
        $this->typeEmail($site, $two['token'], 'same@example.test');
        $this->assertSame(
            (int) $one['conversation']->fresh()->customer_id,
            (int) $two['conversation']->fresh()->customer_id
        );
        $link = $this->mint($one['conversation']);

        list($response, $token) = $this->redeemAsNewBrowser($site, $link);
        $response->assertStatus(200);

        $access = Access::where('session_id', $this->sessionOf($token)->id)->pluck('conversation_id')->all();
        $this->assertSame([(int) $one['conversation']->id], array_map('intval', $access));

        $texts = $this->texts($this->poll($site, $token));
        $this->assertContains('First chat', $texts);
        $this->assertNotContains('Second chat secret', $texts);
    }

    public function test_mint_without_a_state_writes_nothing()
    {
        $site = $this->makeSite();
        $conversation = $this->startChat($site)['conversation'];
        ConversationState::where('conversation_id', $conversation->id)->delete();
        $states = ConversationState::count();
        $links = Link::count();

        try {
            ContinueLink::mint($conversation);
            $this->fail('mint() must throw without a state row.');
        } catch (\RuntimeException $e) {
            $this->assertSame($states, ConversationState::count());
            $this->assertSame($links, Link::count());
        }
    }

    public function test_retry_after_a_lost_answer_gets_200()
    {
        $site = $this->makeSite();
        $conversation = $this->startChat($site)['conversation'];
        $link = $this->mint($conversation);

        list($first, $token) = $this->redeemAsNewBrowser($site, $link);
        $first->assertStatus(200);
        $key = $first->json()['conversation']['key'];

        $retry = $this->api('POST', $site, '/continue', $token, ['link' => $link]);
        $retry->assertStatus(200);
        $this->assertSame($key, $retry->json()['conversation']['key']);

        $other = $this->startChat($site)['token'];
        $this->api('POST', $site, '/continue', $other, ['link' => $link])
            ->assertStatus(410)->assertExactJson(['error' => 'link_invalid']);
    }

    public function test_new_browser_retry_after_a_lost_answer_gets_200_with_its_token()
    {
        $site = $this->makeSite();
        $link = $this->mint($this->startChat($site)['conversation']);

        $new = $this->newChatToken();
        $first = $this->api('POST', $site, '/continue', null, ['link' => $link, 'new_token' => $new]);
        $first->assertStatus(200);
        $sessions = Session::count();

        $retry = $this->api('POST', $site, '/continue', null, ['link' => $link, 'new_token' => $new]);
        $retry->assertStatus(200);
        $this->assertSame($new, $retry->json()['token']);
        $this->assertSame($first->json()['conversation']['key'], $retry->json()['conversation']['key']);
        $this->assertSame($sessions, Session::count());

        $this->api('POST', $site, '/continue', null, ['link' => $link, 'new_token' => $this->newChatToken()])
            ->assertStatus(410)->assertExactJson(['error' => 'link_invalid']);
        $this->assertSame($sessions, Session::count());
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

        // The browser that redeemed it may retry (a lost answer): see
        // test_retry_after_a_lost_answer_gets_200. Writes nothing.
        $this->api('POST', $site, '/continue', $firstToken, ['link' => $link])->assertStatus(200);

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

    /**
     * Final review I1: "Continue chat" continues that conversation, also
     * when the browser has a newer open chat of its own.
     */
    public function test_redeemed_conversation_wins_over_a_newer_open_one()
    {
        $site = $this->makeSite();
        $old = $this->startChat($site, 'Old chat');
        $conversation = $old['conversation'];
        $this->typeEmail($site, $old['token'], 'anna@example.test');
        $customerId = (int) Conversation::find($conversation->id)->customer_id;
        $link = $this->mint($conversation);

        // This browser's own chat, newer and still open.
        $mine = $this->startChat($site, 'Newer chat');
        $newer = $mine['conversation'];
        $this->assertGreaterThan((int) $conversation->id, (int) $newer->id);
        $this->assertNotSame($customerId, (int) $newer->customer_id);

        $key = ConversationState::of($conversation)->public_id;
        $response = $this->api('POST', $site, '/continue', $mine['token'], ['link' => $link]);
        $response->assertStatus(200);
        $this->assertSame($key, $response->json()['conversation']['key']);
        $this->assertSame((int) $conversation->id, (int) $this->sessionOf($mine['token'])->current_conversation_id);

        $sent = $this->say($site, $mine['token'], 'After redeeming');
        $message = Message::find($sent->json()['message']['id']);
        $this->assertSame((int) $conversation->id, (int) $message->conversation_id);
        $this->assertSame($customerId, (int) $message->thread->customer_id);
        $this->assertSame($customerId, (int) $message->thread->created_by_customer_id);
        $this->assertSame($key, $sent->json()['conversation']['key']);

        $poll = $this->poll($site, $mine['token']);
        $this->assertSame($key, $poll['conversation']['key']);
        $this->assertSame('verified', $poll['identity']['level']);
        $this->assertSame(1, Message::where('conversation_id', $newer->id)->count());
    }

    /** I1: a newer closed chat neither stops polling nor takes the next message. */
    public function test_redeemed_open_conversation_wins_over_a_newer_closed_one()
    {
        $site = $this->makeSite();
        $old = $this->startChat($site, 'Old chat');
        $conversation = $old['conversation'];
        $this->typeEmail($site, $old['token'], 'anna@example.test');
        $customerId = (int) Conversation::find($conversation->id)->customer_id;
        $link = $this->mint($conversation);

        $mine = $this->startChat($site, 'Newer chat');
        $newer = Conversation::find($mine['conversation']->id);
        $newer->status = Conversation::STATUS_CLOSED;
        $newer->closed_at = now()->subHour();
        $newer->save();
        $this->assertFalse($this->poll($site, $mine['token'])['conversation']['poll']);

        $key = ConversationState::of($conversation)->public_id;
        $response = $this->api('POST', $site, '/continue', $mine['token'], ['link' => $link]);
        $response->assertStatus(200);
        $this->assertSame($key, $response->json()['conversation']['key']);
        $this->assertSame('open', $response->json()['conversation']['status']);
        $this->assertTrue($response->json()['conversation']['poll']);

        $poll = $this->poll($site, $mine['token']);
        $this->assertSame($key, $poll['conversation']['key']);
        $this->assertTrue($poll['conversation']['poll']);

        $conversations = Conversation::count();
        $message = Message::find($this->say($site, $mine['token'], 'After redeeming')->json()['message']['id']);
        $this->assertSame((int) $conversation->id, (int) $message->conversation_id);
        $this->assertSame($customerId, (int) $message->thread->customer_id);
        $this->assertSame($conversations, Conversation::count());
    }

    /**
     * I1: a session holding a fresh account login keeps the account's
     * customer when it redeems another customer's link; it still writes as
     * the redeemed conversation's customer there, and a later conversation
     * is the account's, with the account's address.
     */
    public function test_fresh_account_session_keeps_its_customer_on_redeeming()
    {
        $site = $this->makeSite();
        $old = $this->startChat($site, 'Old chat');
        $conversation = $old['conversation'];
        $this->typeEmail($site, $old['token'], 'anna@example.test');
        $customerId = (int) Conversation::find($conversation->id)->customer_id;
        $link = $this->mint($conversation);

        $new = $this->newChatToken();
        $acct = $this->startChat($site, 'Account chat', [
            'new_token'   => $new,
            'login_token' => $this->loginToken($site, $new, ['uid' => 77, 'email' => 'acct@example.test', 'name' => 'Acct']),
        ]);
        $session = $this->sessionOf($acct['token']);
        $accountCustomer = (int) $session->customer_id;
        $this->assertTrue($session->accountFresh());
        $this->assertNotSame($customerId, $accountCustomer);

        $this->api('POST', $site, '/continue', $acct['token'], ['link' => $link])->assertStatus(200);

        $session = $this->sessionOf($acct['token']);
        $this->assertSame($accountCustomer, (int) $session->customer_id);
        $this->assertSame('acct@example.test', $session->email);
        $this->assertSame((int) $conversation->id, (int) $session->current_conversation_id);

        $message = Message::find($this->say($site, $acct['token'], 'In the redeemed chat')->json()['message']['id']);
        $this->assertSame((int) $conversation->id, (int) $message->conversation_id);
        $this->assertSame($customerId, (int) $message->thread->customer_id);

        // Both closed: the next message starts the account's own conversation.
        foreach ([$conversation->id, $acct['conversation']->id] as $id) {
            $closed = Conversation::find($id);
            $closed->status = Conversation::STATUS_CLOSED;
            $closed->closed_at = now()->subHour();
            $closed->save();
        }

        $message = Message::find($this->say($site, $acct['token'], 'Later')->json()['message']['id']);
        $next = Conversation::find($message->conversation_id);
        $this->assertNotContains((int) $next->id, [(int) $conversation->id, (int) $acct['conversation']->id]);
        $this->assertSame($accountCustomer, (int) $next->customer_id);
        $this->assertSame('acct@example.test', $next->customer_email);
        $this->assertSame(77, (int) ConversationState::of($next)->wp_user_id);
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
