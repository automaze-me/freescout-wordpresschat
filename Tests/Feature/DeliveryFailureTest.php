<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\SendLog;
use App\Thread;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Services\DueEmails;
use Modules\WordPressChat\Services\IdentityLineItem;
use Modules\WordPressChat\Tests\TestCase;

/**
 * Spec anti-goal: a message never disappears silently. A bounce and a lost
 * reply (Rulings D8, D9) each become one red line item.
 */
class DeliveryFailureTest extends TestCase
{
    private function chat(): array
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Hello');
        $conversation = Conversation::find($chat['conversation']->id);
        $conversation->customer_email = 'anna@example.test';
        $conversation->save();

        return [$site, $chat['token'], $conversation];
    }

    private function bounceThread(Conversation $conversation): Thread
    {
        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type = Thread::TYPE_CUSTOMER;
        $thread->status = Thread::STATUS_ACTIVE;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->body = '<p>Delivery failed</p>';
        $thread->source_type = Thread::SOURCE_TYPE_EMAIL;
        $thread->source_via = Thread::PERSON_CUSTOMER;
        $thread->customer_id = $conversation->customer_id;
        $thread->created_by_customer_id = $conversation->customer_id;
        $thread->save();
        $thread->updateSendStatusData(['is_bounce' => true]);
        $thread->save();

        return $thread;
    }

    /** An agent reply with no row, created $minutes minutes ago. */
    private function agentThread(Conversation $conversation, int $minutes): Thread
    {
        $agent = $this->adminUser();

        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->type               = Thread::TYPE_MESSAGE;
        $thread->status             = Thread::STATUS_ACTIVE;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->body               = '<p>Reply</p>';
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->source_via         = Thread::PERSON_USER;
        $thread->customer_id        = $conversation->customer_id;
        $thread->user_id            = $agent->id;
        $thread->created_by_user_id = $agent->id;
        $thread->save();

        Thread::where('id', $thread->id)->update(['created_at' => now()->subMinutes($minutes)]);

        return $thread->fresh();
    }

    private function redItems(Conversation $conversation)
    {
        return Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_LINEITEM)->get()
            ->filter(function ($t) {
                return (bool) $t->getMeta(IdentityLineItem::LINEITEM_META)
                    && (bool) $t->getMeta('wordpresschat.failure');
            })->values();
    }

    public function test_bounce_writes_one_red_line_item()
    {
        list($site, $token, $conversation) = $this->chat();
        $conversation->status = Conversation::STATUS_PENDING;
        $conversation->save();
        $bounce = $this->bounceThread($conversation);

        \Eventy::action('conversation.customer_replied', $conversation, $bounce, $conversation->customer);
        \Eventy::action('conversation.customer_replied', $conversation, $bounce->fresh(), $conversation->customer);

        $items = $this->redItems($conversation);
        $this->assertCount(1, $items);
        $this->assertSame(
            'The email to anna@example.test bounced. The visitor may not have received the reply.',
            $items[0]->getActionText('', false)
        );
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) Conversation::find($conversation->id)->status);
        $this->assertSame(0, Message::where('thread_id', $bounce->id)->count());
        $this->assertNull(ConversationState::of($conversation)->cutoff_message_id);
        $this->assertTrue((bool) $bounce->fresh()->getMeta('wordpresschat.bounce_noted'));
    }

    public function test_lost_reply_is_marked_once()
    {
        list($site, $token, $conversation) = $this->chat();
        $conversation->status = Conversation::STATUS_CLOSED;
        $conversation->save();
        $thread = $this->agentThread($conversation, 120);

        $this->assertSame(1, DueEmails::markLost());

        $row = Message::where('thread_id', $thread->id)->firstOrFail();
        $this->assertNotNull($row->lost_at);
        $this->assertNull($row->published_at);
        $this->assertSame(Message::DIRECTION_OUT, (int) $row->direction);
        $this->assertSame(Message::VIA_CHAT, (int) $row->via);

        $items = $this->redItems($conversation);
        $this->assertCount(1, $items);
        $this->assertSame(
            'A reply did not reach the website chat. Send it again if it is still needed.',
            $items[0]->getActionText('', false)
        );
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) Conversation::find($conversation->id)->status);

        $this->assertSame(0, DueEmails::markLost());
        $this->assertSame(1, Message::where('thread_id', $thread->id)->count());
        $this->assertCount(1, $this->redItems($conversation));
    }

    public function test_recent_reply_is_not_lost()
    {
        list($site, $token, $conversation) = $this->chat();
        $thread = $this->agentThread($conversation, 30);

        $this->assertSame(0, DueEmails::markLost());
        $this->assertSame(0, Message::where('thread_id', $thread->id)->count());
    }

    public function test_reply_beyond_the_window_is_ignored()
    {
        list($site, $token, $conversation) = $this->chat();
        $thread = $this->agentThread($conversation, 8 * 24 * 60);

        $this->assertSame(0, DueEmails::markLost());
        $this->assertSame(0, Message::where('thread_id', $thread->id)->count());
    }

    public function test_core_emailed_reply_is_not_lost()
    {
        list($site, $token, $conversation) = $this->chat();
        $thread = $this->agentThread($conversation, 120);
        Thread::where('id', $thread->id)->update(['send_status' => SendLog::STATUS_ACCEPTED]);

        $this->assertSame(0, DueEmails::markLost());
        $this->assertSame(0, Message::where('thread_id', $thread->id)->count());
    }

    public function test_imported_reply_is_not_lost()
    {
        list($site, $token, $conversation) = $this->chat();
        $thread = $this->agentThread($conversation, 120);
        Thread::where('id', $thread->id)->update(['imported' => true]);

        $this->assertSame(0, DueEmails::markLost());
    }

    public function test_email_choice_row_is_not_lost()
    {
        list($site, $token, $conversation) = $this->chat();
        $thread = $this->agentThread($conversation, 120);
        Message::create([
            'conversation_id' => $conversation->id,
            'thread_id'       => $thread->id,
            'direction'       => Message::DIRECTION_OUT,
            'via'             => Message::VIA_EMAIL,
            'published_at'    => now(),
        ]);

        $this->assertSame(0, DueEmails::markLost());
        $this->assertCount(0, $this->redItems($conversation));
    }

    public function test_other_channels_are_ignored()
    {
        list($site, $token, $conversation) = $this->chat();
        $thread = $this->agentThread($conversation, 120);
        Conversation::where('id', $conversation->id)->update(['channel' => \Modules\WordPressChat\WordPressChat::CHANNEL + 1]);

        $this->assertSame(0, DueEmails::markLost());
        $this->assertSame(0, Message::where('thread_id', $thread->id)->count());
    }

    public function test_lost_row_never_reaches_the_widget()
    {
        list($site, $token, $conversation) = $this->chat();
        $thread = $this->agentThread($conversation, 120);
        DueEmails::markLost();
        $this->assertSame(1, Message::where('thread_id', $thread->id)->count());

        $response = $this->api('GET', $site, '/messages', $token);
        $response->assertStatus(200);
        $texts = array_map('trim', array_column($response->json()['messages'], 'text'));
        $this->assertNotContains('Reply', $texts);
    }
}
