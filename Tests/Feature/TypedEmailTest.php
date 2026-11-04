<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Thread;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Services\IdentityLineItem;
use Modules\WordPressChat\Services\Visibility;
use Modules\WordPressChat\Tests\TestCase;

/**
 * Spec §6.1 / Ruling P9: a typed email links the conversation to a customer
 * and never modifies an existing one.
 */
class TypedEmailTest extends TestCase
{
    private function identify($site, string $token, $email)
    {
        return $this->api('POST', $site, '/identity', $token, ['email' => $email]);
    }

    private function assertJsonResponse($response, int $status, array $subset): void
    {
        $response->assertStatus($status);
        $json = $response->json();
        foreach ($subset as $key => $value) {
            $this->assertSame($value, $json[$key] ?? null);
        }
    }

    private function lineItems(Conversation $conversation)
    {
        return Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_LINEITEM)->get()
            ->filter(function ($t) {
                return (bool) $t->getMeta(IdentityLineItem::LINEITEM_META);
            })->values();
    }

    public function test_existing_customer_is_attached_unchanged()
    {
        $site = $this->makeSite();
        $existing = Customer::create('real@example.test', ['first_name' => 'Real', 'last_name' => 'Person']);
        $chat = $this->startChat($site);
        $placeholderId = $chat['session']->customer_id;

        $response = $this->identify($site, $chat['token'], 'Real@Example.test');
        $response->assertStatus(200);
        $this->assertSame('email', $response->json()['identity']['level']);

        $conversation = $chat['conversation']->fresh();
        $this->assertSame((int) $existing->id, (int) $conversation->customer_id);
        $this->assertSame('real@example.test', $conversation->customer_email);

        $existing = $existing->fresh();
        $this->assertSame('Real', $existing->first_name);
        $this->assertSame('Person', $existing->last_name);

        $this->assertSame('', Customer::find($placeholderId)->getMainEmail());

        $threads = Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_CUSTOMER)->get();
        $this->assertCount(1, $threads);
        $this->assertSame((int) $existing->id, (int) $threads[0]->customer_id);
        $this->assertSame((int) $existing->id, (int) $threads[0]->created_by_customer_id);

        $items = $this->lineItems($conversation);
        $this->assertCount(1, $items);
        $this->assertStringContainsString('Visitor entered email real@example.test', $items[0]->body);
        $this->assertSame((int) $existing->id, (int) $chat['session']->fresh()->customer_id);
    }

    public function test_new_email_upgrades_the_placeholder()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $placeholder = Customer::find($chat['session']->customer_id);

        $this->identify($site, $chat['token'], 'new@example.test')->assertStatus(200);

        $placeholder = $placeholder->fresh();
        $this->assertSame('new@example.test', $placeholder->getMainEmail());
        $this->assertSame('Website visitor', $placeholder->first_name);

        $conversation = $chat['conversation']->fresh();
        $this->assertSame((int) $placeholder->id, (int) $conversation->customer_id);
        $this->assertSame('new@example.test', $conversation->customer_email);
        $this->assertCount(1, $this->lineItems($conversation));
    }

    public function test_placeholder_that_already_has_an_email_is_not_modified()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $old = Customer::find($chat['session']->customer_id);
        $old->addEmail('agent-added@example.test');
        $old->first_name = 'Edited';
        $old->save();

        $this->identify($site, $chat['token'], 'typed@example.test')->assertStatus(200);

        $old = $old->fresh();
        $this->assertSame('Edited', $old->first_name);
        $this->assertSame(['agent-added@example.test'], $old->emails()->pluck('email')->all());

        $new = Customer::getByEmail('typed@example.test');
        $this->assertNotNull($new);
        $this->assertNotSame((int) $old->id, (int) $new->id);
        $this->assertSame((int) $new->id, (int) $chat['conversation']->fresh()->customer_id);
        $this->assertSame((int) $new->id, (int) $chat['session']->fresh()->customer_id);
        $this->assertSame('typed@example.test', $chat['conversation']->fresh()->customer_email);
    }

    public function test_second_identity_is_409()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);

        $this->identify($site, $chat['token'], 'a@example.test')->assertStatus(200);
        $this->assertJsonResponse($this->identify($site, $chat['token'], 'b@example.test'), 409, ['error' => 'already_identified']);
        $this->assertCount(1, $this->lineItems($chat['conversation']));
        $this->assertNull(Customer::getByEmail('b@example.test'));
    }

    public function test_invalid_email_is_422_and_changes_nothing()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);

        foreach (['not-an-email', str_repeat('a', 190).'@example.test', 123, ['x'], 'ü@ü', 'a@.', 'anna@müller.de', 'Anna Maier@firma.de', 'a@b@c'] as $bad) {
            $this->assertJsonResponse($this->identify($site, $chat['token'], $bad), 422, ['error' => 'validation', 'field' => 'email']);
        }

        $this->assertCount(0, $this->lineItems($chat['conversation']));
        $this->assertSame('', Customer::find($chat['session']->customer_id)->getMainEmail());
        $this->assertSame(0, (int) $chat['session']->fresh()->identity);
    }

    public function test_identity_field_must_be_exactly_one()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);

        $this->assertJsonResponse($this->api('POST', $site, '/identity', $chat['token'], []), 422, ['field' => 'identity']);
        $this->assertJsonResponse($this->api('POST', $site, '/identity', $chat['token'], ['email' => 'a@example.test', 'order_token' => 'x']), 422, ['field' => 'identity']);
        $this->assertJsonResponse($this->api('POST', $site, '/identity', $chat['token'], ['order_token' => 'x']), 422, ['field' => 'identity']);
        $this->assertJsonResponse($this->api('POST', $site, '/identity', $chat['token'], ['login_token' => 'x']), 422, ['field' => 'identity']);
    }

    public function test_identity_without_token_is_401()
    {
        $site = $this->makeSite();
        $this->startChat($site);

        $this->assertJsonResponse($this->api('POST', $site, '/identity', null, ['email' => 'a@example.test']), 401, ['error' => 'unauthorized']);
        $this->api('POST', $site, '/identity', $this->newChatToken(), ['email' => 'a@example.test'])
            ->assertStatus(401);
    }

    public function test_email_level_carries_into_the_next_conversation()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $this->identify($site, $chat['token'], 'carry@example.test')->assertStatus(200);
        $linked = $chat['conversation']->fresh()->customer_id;

        $chat['conversation']->status = Conversation::STATUS_CLOSED;
        $chat['conversation']->closed_at = now()->subHour();
        $chat['conversation']->save();

        $response = $this->api('POST', $site, '/messages', $chat['token'], [
            'text' => 'Again', 'client_message_id' => bin2hex(random_bytes(8)),
        ]);
        $response->assertStatus(201);

        $new = Conversation::find(Message::find($response->json()['message']['id'])->conversation_id);
        $this->assertNotSame((int) $chat['conversation']->id, (int) $new->id);
        $this->assertSame((int) $linked, (int) $new->customer_id);
        $this->assertSame('email', $response->json()['identity']['level']);
    }

    public function test_typing_an_email_never_reveals_earlier_chats()
    {
        $site = $this->makeSite();
        $a = $this->startChat($site, 'Message of A');
        $this->identify($site, $a['token'], 'x@example.test')->assertStatus(200);

        $b = $this->startChat($site, 'Message of B');
        $this->identify($site, $b['token'], 'x@example.test')->assertStatus(200);

        $poll = $this->api('GET', $site, '/messages', $b['token']);
        $poll->assertStatus(200);
        $texts = array_map('trim', array_column($poll->json()['messages'], 'text'));
        $this->assertSame(['Message of B'], $texts);

        $this->assertSame([(int) $b['conversation']->id], array_keys(Visibility::grants($b['session']->fresh())));
        $this->assertSame([(int) $a['conversation']->id], array_keys(Visibility::grants($a['session']->fresh())));
        $this->assertSame(
            (int) $a['conversation']->fresh()->customer_id,
            (int) $b['conversation']->fresh()->customer_id
        );
    }
}
