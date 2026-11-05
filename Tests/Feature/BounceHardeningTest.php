<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Console\Commands\FetchEmails;
use App\Conversation;
use App\Mailbox;
use App\SendLog;
use App\Thread;
use Illuminate\Support\Facades\Queue;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Listeners\RecordBounce;
use Modules\WordPressChat\Listeners\RecordCustomerEmail;
use Modules\WordPressChat\Services\DueEmails;
use Modules\WordPressChat\Services\IdentityLineItem;
use Modules\WordPressChat\Tests\TestCase;

/**
 * Plan 5, Task 4: a bounce item only for a reply that was sent by email, once
 * per reply, naming every address the transport accepted. Plan 6, Task 3:
 * the dedupe is transactional (a failed item write leaves it unset, two
 * DSNs at once write one item), a DSN may arrive while the row is still
 * being sent, and a bounce routed in after core's own path adds nothing.
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

    /** The row of a reply that core sent (or is sending) by email. */
    private function sentRow(Conversation $conversation, Thread $thread, int $status = Message::EMAIL_SENT): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'thread_id'       => $thread->id,
            'direction'       => Message::DIRECTION_OUT,
            'via'             => Message::VIA_CHAT,
            'published_at'    => now()->subMinutes(5),
            'email_status'    => $status,
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
        $this->assertTrue((bool) $thread->fresh()->getMeta(RecordBounce::BOUNCE_META));
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

    /** The item and the dedupe meta are written together: a failed item leaves the next DSN its turn. */
    public function test_failed_item_write_does_not_burn_the_dedupe()
    {
        $conversation = $this->chat();
        $thread = $this->reply($conversation);
        $this->sentRow($conversation, $thread);
        $this->accepted($thread, 'anna@example.test');
        $thrown = 0;

        Thread::creating(function ($created) use (&$thrown) {
            if (!$thrown && (int) $created->type === Thread::TYPE_LINEITEM) {
                $thrown++;

                throw new \RuntimeException('line item write failed');
            }
        });

        $this->dsn($thread);

        $this->assertSame(1, $thrown);
        $this->assertNull($thread->fresh()->getMeta(RecordBounce::BOUNCE_META));
        $this->assertCount(0, $this->texts($conversation));

        $this->dsn($thread);

        $this->assertCount(1, $this->texts($conversation));
        $this->assertTrue((bool) $thread->fresh()->getMeta(RecordBounce::BOUNCE_META));
    }

    /**
     * Two DSNs for one reply processed at once: the second one runs whole
     * between the first one's sent check and its write. The first re-reads
     * the reply under its row lock and finds the meta set.
     */
    public function test_competing_dsn_in_the_check_window_writes_one_item()
    {
        $conversation = $this->chat();
        $thread = $this->reply($conversation);
        $this->sentRow($conversation, $thread);
        $this->accepted($thread, 'anna@example.test');
        $raced = 0;

        \DB::listen(function ($query) use ($thread, &$raced) {
            if (!$raced && preg_match('/^select\s+exists\s*\(\s*select\s+\*\s+from\s+["`]?\w*wordpresschat_messages["`]?\s+where\s+["`]?email_thread_id["`]?\s*=/i', $query->sql) === 1) {
                $raced++;
                // A second DSN log: its created hook runs RecordBounce::handle.
                $this->dsn($thread);
            }
        });

        $this->dsn($thread);

        $this->assertSame(1, $raced);
        $this->assertSame(
            ['The email to anna@example.test bounced. The visitor may not have received the reply.'],
            $this->texts($conversation)
        );
    }

    /** A DSN can arrive before the job marked the row sent: the reply went out all the same. */
    public function test_dsn_while_row_is_sending_writes_the_item()
    {
        $conversation = $this->chat();
        $thread = $this->reply($conversation);
        $this->sentRow($conversation, $thread, Message::EMAIL_SENDING);
        $this->accepted($thread, 'anna@example.test');

        $this->dsn($thread);

        $this->assertCount(1, $this->texts($conversation));
    }

    /**
     * Core's path wrote the item; another module then routes the bounce
     * into the chat. The bounce names the bounced reply in its send status
     * data, and that reply already carries the item's meta.
     */
    public function test_routed_bounce_after_core_bounce_writes_no_second_item()
    {
        $conversation = $this->chat();
        $thread = $this->reply($conversation);
        $this->sentRow($conversation, $thread);
        $this->accepted($thread, 'anna@example.test');

        $this->dsn($thread);
        $this->assertCount(1, $this->texts($conversation));

        $bounce = new Thread();
        $bounce->conversation_id        = $conversation->id;
        $bounce->type                   = Thread::TYPE_CUSTOMER;
        $bounce->status                 = Thread::STATUS_ACTIVE;
        $bounce->state                  = Thread::STATE_PUBLISHED;
        $bounce->body                   = '<p>Delivery failed</p>';
        $bounce->from                   = DeliveryFailureTest::BOUNCE_FROM;
        $bounce->source_type            = Thread::SOURCE_TYPE_EMAIL;
        $bounce->source_via             = Thread::PERSON_CUSTOMER;
        $bounce->customer_id            = $conversation->customer_id;
        $bounce->created_by_customer_id = $conversation->customer_id;
        $bounce->updateSendStatusData([
            'is_bounce'               => true,
            'bounce_for_thread'       => $thread->id,
            'bounce_for_conversation' => $conversation->id,
        ]);
        $bounce->save();

        \Eventy::action('conversation.customer_replied', $conversation, $bounce, $conversation->customer);

        $this->assertCount(1, $this->texts($conversation));
        $this->assertSame('wordpresschat.bounce_item', RecordBounce::BOUNCE_META);
        $this->assertNotSame(RecordCustomerEmail::BOUNCE_NOTED_META, RecordBounce::BOUNCE_META);
    }

    /** No accepted send-log row: the item names the conversation's address. */
    public function test_zero_recipient_fallback_names_the_visitor_address()
    {
        $conversation = $this->chat();
        $thread = $this->reply($conversation);
        $this->sentRow($conversation, $thread);

        $this->dsn($thread);

        $this->assertSame(
            ['The email to anna@example.test bounced. The visitor may not have received the reply.'],
            $this->texts($conversation)
        );
    }

    public function test_zero_recipient_fallback_without_any_address_uses_the_generic_text()
    {
        $conversation = $this->chat();
        $conversation->customer_email = '';
        $conversation->save();
        $conversation = Conversation::find($conversation->id);
        $this->assertNull(DueEmails::address($conversation));
        $thread = $this->reply($conversation);
        $this->sentRow($conversation, $thread);

        $this->dsn($thread);

        $this->assertSame(
            ['An email to the visitor bounced. The visitor may not have received the reply.'],
            $this->texts($conversation)
        );
    }
}
