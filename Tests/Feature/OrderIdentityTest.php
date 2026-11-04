<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Thread;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Services\IdentityLineItem;
use Modules\WordPressChat\Services\VisitorAuth;
use Modules\WordPressChat\Tests\TestCase;

/**
 * Spec §5.1.7, §6.3 and Rulings P8/P9: an order number WordPress resolved
 * links the billing customer at level "Order number only"; an unresolved
 * one only leaves a line item.
 */
class OrderIdentityTest extends TestCase
{
    private function order($site, string $chatToken, $orderToken)
    {
        return $this->api('POST', $site, '/identity', $chatToken, ['order_token' => $orderToken]);
    }

    private function lineItems(Conversation $conversation): array
    {
        return Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_LINEITEM)->orderBy('id')->get()
            ->filter(function ($t) {
                return (bool) $t->getMeta(IdentityLineItem::LINEITEM_META);
            })
            ->map(function ($t) {
                return html_entity_decode($t->body, ENT_QUOTES, 'UTF-8');
            })->values()->all();
    }

    public function test_resolved_order_links_the_billing_customer()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $placeholder = (int) $chat['session']->customer_id;

        $response = $this->order($site, $chat['token'], $this->orderToken($site, $chat['token']));
        $response->assertStatus(200);
        $this->assertSame(['level' => 'order', 'account' => false], $response->json()['identity']);

        $conversation = $chat['conversation']->fresh();
        $this->assertSame(['Visitor entered order number 10482 → linked to Anna Example'], $this->lineItems($conversation));
        $this->assertSame('anna@example.test', $conversation->customer_email);

        // The placeholder had no email: it gets the billing email and name (P9).
        $this->assertSame($placeholder, (int) $conversation->customer_id);
        $customer = Customer::find($placeholder);
        $this->assertSame('anna@example.test', $customer->getMainEmail());
        $this->assertSame('Anna Example', $customer->first_name);

        $session = $chat['session']->fresh();
        $this->assertSame(Session::IDENTITY_ORDER, (int) $session->identity);
        $this->assertSame($placeholder, (int) $session->customer_id);
        $this->assertNull($session->wp_user_id);

        $poll = $this->api('GET', $site, '/messages', $chat['token'])->json();
        $this->assertSame(['level' => 'order', 'account' => false], $poll['identity']);
    }

    public function test_unresolved_order_writes_not_found_and_stays_anonymous()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $customers = Customer::count();

        $response = $this->order($site, $chat['token'], $this->orderToken($site, $chat['token'], ['found' => false, 'email' => null, 'name' => null]));
        $response->assertStatus(200);
        $this->assertSame(['level' => 'anonymous', 'account' => false], $response->json()['identity']);

        // Ruling 3: a found order without a valid email is malformed, never
        // a "not found" (WordPress sends found: false for such orders).
        foreach ([
            ['found' => true, 'email' => null, 'name' => null, 'num' => '10483'],
            ['found' => true, 'email' => '', 'name' => 'Nobody', 'num' => '10484'],
        ] as $over) {
            $this->order($site, $chat['token'], $this->orderToken($site, $chat['token'], $over))
                ->assertStatus(403)->assertExactJson(['error' => 'identity_rejected']);
        }

        $this->assertSame(['Order number 10482 not found'], $this->lineItems($chat['conversation']));

        $session = $chat['session']->fresh();
        $this->assertSame(Session::IDENTITY_NONE, (int) $session->identity);
        $this->assertSame('', Customer::find($session->customer_id)->getMainEmail());
        $this->assertSame($customers, Customer::count());
        $this->assertSame('', (string) $chat['conversation']->fresh()->customer_email);

        // Still anonymous, so a resolved order or an email may follow.
        $this->order($site, $chat['token'], $this->orderToken($site, $chat['token']))->assertStatus(200);
        $this->assertSame(Session::IDENTITY_ORDER, (int) $chat['session']->fresh()->identity);
    }

    /**
     * Final review I3: WordPress seals an answer that is no well-formed
     * order number as not found, with the visitor's own input as `num`.
     * The agent sees it; the identity stays as it is.
     */
    public function test_free_text_order_answer_reaches_the_agent()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $customers = Customer::count();

        $response = $this->order($site, $chat['token'], $this->orderToken($site, $chat['token'], [
            'num' => 'Order 12345', 'found' => false, 'email' => null, 'name' => null,
        ]));
        $response->assertStatus(200);
        $this->assertSame(['level' => 'anonymous', 'account' => false], $response->json()['identity']);

        $this->assertSame(['Order number Order 12345 not found'], $this->lineItems($chat['conversation']));

        $session = $chat['session']->fresh();
        $this->assertSame(Session::IDENTITY_NONE, (int) $session->identity);
        $this->assertNull($session->email);
        $this->assertSame('', Customer::find($session->customer_id)->getMainEmail());
        $this->assertSame($customers, Customer::count());
        $this->assertSame('', (string) $chat['conversation']->fresh()->customer_email);
    }

    public function test_order_token_replay_is_403()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $token = $this->orderToken($site, $chat['token'], ['found' => false, 'email' => null, 'name' => null]);

        $this->order($site, $chat['token'], $token)->assertStatus(200);
        $this->order($site, $chat['token'], $token)
            ->assertStatus(403)->assertExactJson(['error' => 'identity_rejected']);

        $this->assertCount(1, $this->lineItems($chat['conversation']));
    }

    public function test_login_token_sent_as_order_token_is_403()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);

        $this->order($site, $chat['token'], $this->loginToken($site, $chat['token']))
            ->assertStatus(403)->assertExactJson(['error' => 'identity_rejected']);

        $this->assertSame([], $this->lineItems($chat['conversation']));
        $this->assertSame(Session::IDENTITY_NONE, (int) $chat['session']->fresh()->identity);
        $this->assertNull($chat['session']->fresh()->wp_user_id);
    }

    public function test_bad_order_tokens_are_403_and_change_nothing()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);

        $tokens = [
            $this->orderToken($site, $this->newChatToken()),
            ['x'],
            123,
        ];
        foreach ([
            ['num' => ''],
            ['num' => str_repeat('1', 33)],
            ['num' => 10482],
            ['num' => "104\u{0000}82"],
            ['num' => '10:482'],
            ['num' => ':name', 'found' => false, 'email' => null, 'name' => null],
            ['found' => 'yes'],
            ['found' => 1],
            ['email' => 'not-an-email'],
            ['email' => ['anna@example.test']],
            ['name' => str_repeat('x', 101)],
            ['name' => 7],
        ] as $over) {
            $tokens[] = $this->orderToken($site, $chat['token'], $over);
        }

        foreach ($tokens as $token) {
            $this->order($site, $chat['token'], $token)
                ->assertStatus(403)->assertExactJson(['error' => 'identity_rejected']);
        }

        $this->assertSame([], $this->lineItems($chat['conversation']));
        $this->assertSame(Session::IDENTITY_NONE, (int) $chat['session']->fresh()->identity);
    }

    public function test_order_of_existing_customer_changes_no_profile()
    {
        $site = $this->makeSite();
        $existing = Customer::create('main@example.test', ['first_name' => 'Real', 'last_name' => 'Person']);
        $existing->addEmail('anna@example.test');
        $chat = $this->startChat($site);
        $placeholder = (int) $chat['session']->customer_id;

        $this->order($site, $chat['token'], $this->orderToken($site, $chat['token']))->assertStatus(200);

        $conversation = $chat['conversation']->fresh();
        $this->assertSame((int) $existing->id, (int) $conversation->customer_id);
        // The billing address the order carries, not the customer's main one.
        $this->assertSame('anna@example.test', $conversation->customer_email);

        $existing = $existing->fresh();
        $this->assertSame('Real', $existing->first_name);
        $this->assertSame('Person', $existing->last_name);
        $this->assertSame(['anna@example.test', 'main@example.test'], $existing->emails()->orderBy('email')->pluck('email')->all());
        $this->assertSame('', Customer::find($placeholder)->getMainEmail());
        $this->assertSame('Website visitor', Customer::find($placeholder)->first_name);
        $this->assertSame((int) $existing->id, (int) $chat['session']->fresh()->customer_id);

        $thread = Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_CUSTOMER)->first();
        $this->assertSame((int) $existing->id, (int) $thread->created_by_customer_id);
        $this->assertSame(['Visitor entered order number 10482 → linked to Anna Example'], $this->lineItems($conversation));
    }

    public function test_order_after_an_identity_is_409()
    {
        $site = $this->makeSite();

        $typed = $this->startChat($site);
        $this->api('POST', $site, '/identity', $typed['token'], ['email' => 'typed@example.test'])->assertStatus(200);
        $this->order($site, $typed['token'], $this->orderToken($site, $typed['token']))
            ->assertStatus(409)->assertExactJson(['error' => 'already_identified']);

        $ordered = $this->startChat($site);
        $this->order($site, $ordered['token'], $this->orderToken($site, $ordered['token']))->assertStatus(200);
        $this->order($site, $ordered['token'], $this->orderToken($site, $ordered['token'], ['num' => '1']))
            ->assertStatus(409);
        $this->api('POST', $site, '/identity', $ordered['token'], ['email' => 'later@example.test'])
            ->assertStatus(409);
        $this->assertCount(1, $this->lineItems($ordered['conversation']));
    }

    public function test_order_without_a_visible_conversation_links_the_session_only()
    {
        $site = $this->makeSite();
        $token = $this->newChatToken();
        $session = VisitorAuth::claim($site, $token);
        $threads = Thread::count();

        $response = $this->order($site, $token, $this->orderToken($site, $token));
        $response->assertStatus(200);
        $this->assertSame(['level' => 'order', 'account' => false], $response->json()['identity']);
        $this->assertNull($response->json()['conversation']);

        $session = $session->fresh();
        $this->assertSame(Session::IDENTITY_ORDER, (int) $session->identity);
        $this->assertSame('anna@example.test', Customer::find($session->customer_id)->getMainEmail());
        $this->assertSame($threads, Thread::count());

        $this->order($site, $token, $this->orderToken($site, $token))->assertStatus(409);
    }

    public function test_unresolved_order_without_a_visible_conversation_writes_nothing()
    {
        $site = $this->makeSite();
        $token = $this->newChatToken();
        $session = VisitorAuth::claim($site, $token);
        $threads = Thread::count();
        $customers = Customer::count();

        $this->order($site, $token, $this->orderToken($site, $token, ['found' => false, 'email' => null, 'name' => null]))
            ->assertStatus(200);

        $session = $session->fresh();
        $this->assertSame(Session::IDENTITY_NONE, (int) $session->identity);
        $this->assertNull($session->customer_id);
        $this->assertSame($threads, Thread::count());
        $this->assertSame($customers, Customer::count());
    }
}
