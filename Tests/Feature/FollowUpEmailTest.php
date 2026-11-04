<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\Customer;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Services\ContinueLink;
use Modules\WordPressChat\Tests\TestCase;

/**
 * Final review I5: once a visitor is identified, every later conversation
 * of that browser carries the address the visitor was linked by as its
 * customer_email (the switch for Plan 3's fallback email), not only the
 * conversation that was current when the identity arrived.
 *
 * Each case links an existing customer through a second address, so the
 * linked address and the customer's main email differ.
 */
class FollowUpEmailTest extends TestCase
{
    private $n = 0;

    private function existing(): Customer
    {
        $customer = Customer::create('main@example.test', ['first_name' => 'Real']);
        $customer->addEmail('anna@example.test');

        return $customer;
    }

    private function close(Conversation $conversation): void
    {
        $conversation = Conversation::find($conversation->id);
        $conversation->status = Conversation::STATUS_CLOSED;
        $conversation->closed_at = now()->subHour();
        $conversation->save();
    }

    /** Sends without a login token; returns the conversation the message went into. */
    private function follow(Site $site, string $token, Conversation $previous): Conversation
    {
        $response = $this->api('POST', $site, '/messages', $token, [
            'text'              => 'Again',
            'client_message_id' => 'fol-'.str_pad(++$this->n, 6, '0', STR_PAD_LEFT),
        ]);
        $response->assertStatus(201);

        $conversation = Conversation::find(Message::find($response->json()['message']['id'])->conversation_id);
        $this->assertNotSame((int) $previous->id, (int) $conversation->id);

        return $conversation;
    }

    public function test_typed_email_carries_into_the_next_conversation()
    {
        $site = $this->makeSite();
        $customer = $this->existing();
        $chat = $this->startChat($site);
        $this->api('POST', $site, '/identity', $chat['token'], ['email' => 'Anna@example.test'])->assertStatus(200);
        $this->assertSame('anna@example.test', $chat['conversation']->fresh()->customer_email);
        $this->close($chat['conversation']);

        $next = $this->follow($site, $chat['token'], $chat['conversation']);

        $this->assertSame((int) $customer->id, (int) $next->customer_id);
        $this->assertSame('anna@example.test', $next->customer_email);
    }

    public function test_order_billing_email_carries_into_the_next_conversation()
    {
        $site = $this->makeSite();
        $customer = $this->existing();
        $chat = $this->startChat($site);
        $this->api('POST', $site, '/identity', $chat['token'], [
            'order_token' => $this->orderToken($site, $chat['token']),
        ])->assertStatus(200);
        $this->close($chat['conversation']);

        $next = $this->follow($site, $chat['token'], $chat['conversation']);

        $this->assertSame((int) $customer->id, (int) $next->customer_id);
        $this->assertSame('anna@example.test', $next->customer_email);
    }

    public function test_account_email_carries_into_the_next_conversation_without_a_login_token()
    {
        $site = $this->makeSite();
        $customer = $this->existing();
        $new = $this->newChatToken();
        $chat = $this->startChat($site, 'Hello', [
            'new_token'   => $new,
            'login_token' => $this->loginToken($site, $new),
        ]);
        $this->assertSame('anna@example.test', $chat['conversation']->fresh()->customer_email);
        $this->close($chat['conversation']);

        // The login is still fresh; this send carries no login token.
        $next = $this->follow($site, $chat['token'], $chat['conversation']);

        $this->assertSame((int) $customer->id, (int) $next->customer_id);
        $this->assertSame('anna@example.test', $next->customer_email);
        $this->assertSame(42, (int) ConversationState::of($next)->wp_user_id);
    }

    public function test_verified_browser_carries_the_conversation_email_into_the_next_one()
    {
        $site = $this->makeSite();
        $customer = $this->existing();
        $chat = $this->startChat($site);
        $this->api('POST', $site, '/identity', $chat['token'], ['email' => 'anna@example.test'])->assertStatus(200);

        preg_match('/#lcff-continue=([A-Za-z0-9_-]{43})$/', ContinueLink::mint($chat['conversation']), $m);
        $token = $this->newChatToken();
        $this->api('POST', $site, '/continue', null, ['link' => $m[1], 'new_token' => $token])->assertStatus(200);
        $this->close($chat['conversation']);

        $next = $this->follow($site, $token, $chat['conversation']);

        $this->assertSame((int) $customer->id, (int) $next->customer_id);
        $this->assertSame('anna@example.test', $next->customer_email);
    }

    public function test_anonymous_follow_up_has_no_email()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $this->close($chat['conversation']);

        $next = $this->follow($site, $chat['token'], $chat['conversation']);

        $this->assertSame((int) $chat['conversation']->customer_id, (int) $next->customer_id);
        $this->assertSame('', (string) $next->customer_email);
    }
}
