<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\Thread;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Services\IdentityLineItem;
use Modules\WordPressChat\Tests\TestCase;

/**
 * Spec §6.4 rule 3: a customer's email reply in a chat conversation becomes
 * an email-channel row and cuts off every unverified browser.
 */
class CustomerEmailTest extends TestCase
{
    private function emailThread(Conversation $conversation, string $text): Thread
    {
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

        return $thread;
    }

    private function fire(Conversation $conversation, Thread $thread): void
    {
        \Eventy::action('conversation.customer_replied', $conversation, $thread, $conversation->customer);
    }

    private function lineItems(Conversation $conversation)
    {
        return Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_LINEITEM)->get();
    }

    private function poll($site, string $token): array
    {
        $response = $this->api('GET', $site, '/messages', $token);
        $response->assertStatus(200);

        return $response->json();
    }

    public function test_customer_email_becomes_an_email_row_and_cuts_off()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Hello');
        $conversation = $chat['conversation'];
        $thread = $this->emailThread($conversation, 'Secret from the inbox');

        $this->fire($conversation, $thread);

        $row = Message::where('thread_id', $thread->id)->firstOrFail();
        $this->assertSame(Message::DIRECTION_IN, (int) $row->direction);
        $this->assertSame(Message::VIA_EMAIL, (int) $row->via);
        $this->assertNull($row->session_id);
        $this->assertNotNull($row->published_at);

        $state = ConversationState::of($conversation);
        $this->assertSame((int) $row->id, (int) $state->cutoff_message_id);
        $this->assertSame(ConversationState::CUTOFF_EMAIL, (int) $state->cutoff_reason);

        $items = $this->lineItems($conversation);
        $this->assertCount(1, $items);
        $this->assertTrue((bool) $items[0]->getMeta(IdentityLineItem::LINEITEM_META));
        $this->assertStringContainsString('Conversation continues by email', $items[0]->body);

        $json = $this->poll($site, $chat['token']);
        $texts = array_map('trim', array_column($json['messages'], 'text'));
        $this->assertSame(['Hello'], $texts);
        $this->assertSame('continues_by_email', $json['conversation']['notice']);

        // An agent reply published afterwards stays hidden from the originator.
        $agent = $this->adminUser();
        $reply = new Thread();
        $reply->conversation_id = $conversation->id;
        $reply->type = Thread::TYPE_MESSAGE;
        $reply->status = Thread::STATUS_ACTIVE;
        $reply->state = Thread::STATE_PUBLISHED;
        $reply->body = '<p>Agent answer</p>';
        $reply->source_type = Thread::SOURCE_TYPE_WEB;
        $reply->source_via = Thread::PERSON_USER;
        $reply->user_id = $agent->id;
        $reply->created_by_user_id = $agent->id;
        $reply->save();
        Message::create([
            'conversation_id' => $conversation->id,
            'thread_id'       => $reply->id,
            'direction'       => Message::DIRECTION_OUT,
            'published_at'    => now(),
        ]);

        $texts = array_map('trim', array_column($this->poll($site, $chat['token'])['messages'], 'text'));
        $this->assertSame(['Hello'], $texts);
    }

    /**
     * Final review I4: core files bounces and auto-replies as customer
     * threads and fires `conversation.customer_replied` for them. Neither is
     * the customer writing: no row, no cut-off; a bounce gets a red line item.
     */
    public function test_bounces_and_auto_replies_never_cut_off()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Hello');
        $conversation = $chat['conversation'];

        $bounce = $this->emailThread($conversation, 'Delivery to anna@gmial.com failed');
        $bounce->updateSendStatusData(['is_bounce' => true]);
        $bounce->save();
        $this->assertTrue($bounce->isBounce());
        $this->fire($conversation, $bounce);

        $auto = $this->emailThread($conversation, 'I am out of office');
        $auto->headers = "From: anna@example.test\nAuto-Submitted: auto-replied\nX-Autoreply: yes";
        $auto->save();
        $this->assertTrue($auto->isAutoResponder());
        $this->fire($conversation, $auto);

        $this->assertSame(0, Message::whereIn('thread_id', [$bounce->id, $auto->id])->count());
        $this->assertNull(ConversationState::of($conversation)->cutoff_message_id);
        // Only the bounce gets a (red failure) line item, Plan 3 Ruling D9.
        $this->assertCount(1, $this->lineItems($conversation));

        $json = $this->poll($site, $chat['token']);
        $this->assertNull($json['conversation']['notice']);

        // The customer's own email afterwards still cuts off.
        $real = $this->emailThread($conversation, 'Real answer');
        $this->fire($conversation, $real);
        $row = Message::where('thread_id', $real->id)->firstOrFail();
        $this->assertSame((int) $row->id, (int) ConversationState::of($conversation)->cutoff_message_id);
        $this->assertCount(2, $this->lineItems($conversation));
    }

    public function test_chat_messages_never_cut_off()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'First');
        $this->api('POST', $site, '/messages', $chat['token'], [
            'text'              => 'Second',
            'client_message_id' => bin2hex(random_bytes(8)),
        ])->assertStatus(201);

        $this->assertSame(0, Message::where('conversation_id', $chat['conversation']->id)
            ->where('via', Message::VIA_EMAIL)->count());
        $this->assertNull(ConversationState::of($chat['conversation'])->cutoff_message_id);
        $this->assertCount(0, $this->lineItems($chat['conversation']));
    }

    public function test_second_email_keeps_the_first_cutoff()
    {
        $site = $this->makeSite();
        $conversation = $this->startChat($site)['conversation'];

        $first = $this->emailThread($conversation, 'One');
        $this->fire($conversation, $first);
        $cutoff = ConversationState::of($conversation)->cutoff_message_id;

        $second = $this->emailThread($conversation, 'Two');
        $this->fire($conversation, $second);

        $this->assertSame((int) $cutoff, (int) ConversationState::of($conversation)->cutoff_message_id);
        $this->assertSame(2, Message::where('conversation_id', $conversation->id)
            ->where('via', Message::VIA_EMAIL)->count());
        $this->assertCount(1, $this->lineItems($conversation));

        // Firing the same thread again is a no-op.
        $this->fire($conversation, $second);
        $this->assertSame(2, Message::where('conversation_id', $conversation->id)
            ->where('via', Message::VIA_EMAIL)->count());
    }

    public function test_other_channels_are_ignored()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $conversation = Conversation::find($chat['conversation']->id);
        $conversation->channel = 1;
        $conversation->save();

        $thread = $this->emailThread($conversation, 'Plain email');
        $this->fire($conversation, $thread);

        $this->assertSame(0, Message::where('thread_id', $thread->id)->count());
        $this->assertCount(0, $this->lineItems($conversation));
    }

    public function test_line_item_text_renders_through_the_filter()
    {
        $site = $this->makeSite();
        $conversation = $this->startChat($site)['conversation'];

        $item = IdentityLineItem::create($conversation, 'Visitor <script>alert(1)</script>');

        $html = \Eventy::filter('thread.action_text', '', $item, 1, true, null);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);

        $other = new Thread();
        $other->type = Thread::TYPE_LINEITEM;
        $this->assertSame('x', \Eventy::filter('thread.action_text', 'x', $other, 1, true, null));
    }

    /**
     * Final review M3: the body is stored escaped. Escaped text for a
     * caller that asks for it; decoded text for one that escapes the result
     * itself (getActionDescription()), so it is never escaped twice.
     */
    public function test_line_item_text_is_decoded_when_not_escaped()
    {
        $site = $this->makeSite();
        $conversation = $this->startChat($site)['conversation'];

        $item = IdentityLineItem::create($conversation, "Visitor entered order number 1 \u{2192} linked to O'Brien");

        $escaped = \Eventy::filter('thread.action_text', '', $item, 1, true, null);
        $this->assertStringContainsString('O&#039;Brien', $escaped);

        $plain = \Eventy::filter('thread.action_text', '', $item, 1, false, null);
        $this->assertSame("Visitor entered order number 1 \u{2192} linked to O'Brien", $plain);

        // Through core: escaped exactly once on either path.
        $this->assertStringContainsString('O&#039;Brien', $item->getActionText('', true));
        $this->assertStringContainsString("O'Brien", $item->getActionText('', false));
        $description = $item->getActionDescription(1);
        $this->assertStringContainsString('O&#039;Brien', $description);
        $this->assertStringNotContainsString('&amp;#039;', $description);
    }
}
