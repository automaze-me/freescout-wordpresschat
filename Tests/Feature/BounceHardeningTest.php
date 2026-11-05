<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Console\Commands\FetchEmails;
use App\Conversation;
use App\Mailbox;
use App\SendLog;
use App\Thread;
use Illuminate\Support\Facades\Queue;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Services\IdentityLineItem;
use Modules\WordPressChat\Tests\TestCase;

/**
 * Plan 5, Task 4: a bounce item only for a reply that was sent by email, once
 * per reply, naming every address the transport accepted.
 */
class BounceHardeningTest extends TestCase
{
    private function chat(): Conversation
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Hello');
        $conversation = Conversation::find($chat['conversation']->id);
        $conversation->customer_email = 'anna@example.test';
        $conversation->save();

        return $conversation;
    }

    private function reply(Conversation $conversation): Thread
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

        return $thread->fresh();
    }

    /** The row of a reply that core sent by email. */
    private function sentRow(Conversation $conversation, Thread $thread): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'thread_id'       => $thread->id,
            'direction'       => Message::DIRECTION_OUT,
            'via'             => Message::VIA_CHAT,
            'published_at'    => now()->subMinutes(5),
            'email_status'    => Message::EMAIL_SENT,
            'email_thread_id' => $thread->id,
        ]);
    }

    private function accepted(Thread $thread, string $email): void
    {
        SendLog::log($thread->id, 'x@y', $email, SendLog::MAIL_TYPE_EMAIL_TO_CUSTOMER, SendLog::STATUS_ACCEPTED);
    }

    private function dsn(Thread $thread): void
    {
        SendLog::log($thread->id, null, DeliveryFailureTest::BOUNCE_FROM, SendLog::MAIL_TYPE_EMAIL_TO_CUSTOMER, SendLog::STATUS_DELIVERY_ERROR);
    }

    private function texts(Conversation $conversation): array
    {
        return Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_LINEITEM)->get()
            ->filter(function ($t) {
                return (bool) $t->getMeta(IdentityLineItem::LINEITEM_META)
                    && (bool) $t->getMeta('wordpresschat.failure');
            })->map(function ($t) {
                return $t->getActionText('', false);
            })->values()->all();
    }

    public function test_dsn_for_a_reply_never_sent_by_email_writes_nothing()
    {
        $conversation = $this->chat();
        $thread = $this->reply($conversation);
        Message::create([
            'conversation_id' => $conversation->id,
            'thread_id'       => $thread->id,
            'direction'       => Message::DIRECTION_OUT,
            'via'             => Message::VIA_CHAT,
            'published_at'    => now(),
        ]);

        $this->dsn($thread);

        $this->assertSame([], $this->texts($conversation));
    }

    public function test_second_dsn_for_the_same_reply_writes_one_item()
    {
        $conversation = $this->chat();
        $thread = $this->reply($conversation);
        $this->sentRow($conversation, $thread);
        $this->accepted($thread, 'anna@example.test');

        $this->dsn($thread);
        $this->dsn($thread);

        $this->assertCount(1, $this->texts($conversation));
        $this->assertTrue((bool) $thread->fresh()->getMeta(\Modules\WordPressChat\Listeners\RecordBounce::BOUNCE_META));
    }

    public function test_bounce_names_every_accepted_recipient()
    {
        $conversation = $this->chat();
        $thread = $this->reply($conversation);
        $this->sentRow($conversation, $thread);
        $this->accepted($thread, 'a@example.test');
        $this->accepted($thread, 'c@example.test');
        $this->accepted($thread, 'c@example.test');

        $this->dsn($thread);

        $this->assertSame(
            ['The email to a@example.test, c@example.test bounced. At least one of these addresses may not have received the reply.'],
            $this->texts($conversation)
        );
    }

    public function test_single_recipient_keeps_existing_copy()
    {
        $conversation = $this->chat();
        $thread = $this->reply($conversation);
        $this->sentRow($conversation, $thread);
        $this->accepted($thread, 'anna@example.test');

        $this->dsn($thread);

        $this->assertSame(
            ['The email to anna@example.test bounced. The visitor may not have received the reply.'],
            $this->texts($conversation)
        );
    }

    public function test_bounce_in_non_chat_conversation_is_ignored()
    {
        $conversation = $this->chat();
        $plain = Conversation::find($conversation->id)->replicate();
        $plain->channel = 1; // plain email channel
        $plain->type = Conversation::TYPE_EMAIL;
        $plain->threads_count = 0;
        $plain->save();
        $plain = Conversation::find($plain->id);
        $thread = $this->reply($plain);
        $this->sentRow($plain, $thread);
        $this->accepted($thread, 'anna@example.test');

        $this->dsn($thread);

        $this->assertSame([], $this->texts($plain));
    }
}
