<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Console\Commands\FetchEmails;
use App\Conversation;
use App\Mailbox;
use App\SendLog;
use App\Thread;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
    /**
     * The headers of a real Postfix bounce (RFC 3464 DSN), as FetchEmails
     * stores them on the thread: Auto-Submitted makes core's
     * isAutoResponder() true, and multipart/report, the MAILER-DAEMON
     * sender and the empty Return-Path each make FetchEmails flag a bounce.
     */
    const BOUNCE_HEADERS = "Return-Path: <>\n"
        ."Delivered-To: support@example.test\n"
        ."Date: Wed, 4 Nov 2026 12:00:00 +0000 (UTC)\n"
        ."From: MAILER-DAEMON@mx.example.test (Mail Delivery System)\n"
        ."Subject: Undelivered Mail Returned to Sender\n"
        ."To: support@example.test\n"
        ."Auto-Submitted: auto-replied\n"
        ."MIME-Version: 1.0\n"
        ."Content-Type: multipart/report; report-type=delivery-status;\n"
        ."\tboundary=\"8A1B2C3D4E.1793793600/mx.example.test\"\n"
        ."Message-Id: <20261104120000.8A1B2C3D4E@mx.example.test>";

    const BOUNCE_FROM = 'mailer-daemon@mx.example.test';

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
        $thread->from = self::BOUNCE_FROM;
        $thread->headers = self::BOUNCE_HEADERS;
        $thread->source_type = Thread::SOURCE_TYPE_EMAIL;
        $thread->source_via = Thread::PERSON_CUSTOMER;
        $thread->customer_id = $conversation->customer_id;
        $thread->created_by_customer_id = $conversation->customer_id;
        // As FetchEmails::saveCustomerThread() flags it before the events.
        $thread->updateSendStatusData(['is_bounce' => true]);
        $thread->save();

        return $thread;
    }

    /**
     * Core's own bounce processing (FetchEmails, minus IMAP): the bounce is
     * saved as a customer thread with the flag, then saveBounceData() finds
     * the bounced reply by the Message-ID core gave its email and marks it.
     */
    private function coreBounce(Mailbox $mailbox, Thread $bounced): Thread
    {
        Queue::fake();
        $fetch = new FetchEmails();

        $bounce = $fetch->saveCustomerThread(
            $mailbox,
            '20261104120000.'.bin2hex(random_bytes(5)).'@mx.example.test',
            null,
            self::BOUNCE_FROM,
            [$mailbox->email],
            [],
            [],
            'Undelivered Mail Returned to Sender',
            '<p>Delivery to anna@example.test failed permanently.</p>',
            [],
            self::BOUNCE_HEADERS,
            now(),
            true
        );
        $fetch->saveBounceData($bounce, $bounced->getMessageId($mailbox), self::BOUNCE_FROM);

        return $bounce;
    }

    /** The conversation as core's forward leaves its copy: channel kept, type email. */
    private function forwardedCopy(Conversation $conversation): Conversation
    {
        $copy = $conversation->replicate();
        $copy->type = Conversation::TYPE_EMAIL;
        $copy->threads_count = 0;
        $copy->customer_email = 'boss@example.test';
        $copy->subject = 'Fwd: '.$conversation->subject;
        $copy->save();

        return Conversation::find($copy->id);
    }

    /**
     * A job row in the database queue, $age seconds past its available_at;
     * reserved $reservedAgo seconds ago, or unreserved when null.
     */
    private function queuedJob(string $queue, int $age, ?int $reservedAgo = null): void
    {
        config(['queue.default' => 'database']);
        $now = time();

        DB::table(config('queue.connections.database.table', 'jobs'))->insert([
            'queue'        => $queue,
            'payload'      => '{}',
            'attempts'     => 0,
            'reserved_at'  => $reservedAgo === null ? null : $now - $reservedAgo,
            'available_at' => $now - $age,
            'created_at'   => $now - $age,
        ]);
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

    /**
     * Final review I1: a real bounce carries Auto-Submitted, so core's
     * isAutoResponder() is true for it too. It must still get its item.
     */
    public function test_bounce_with_real_bounce_headers_gets_one_red_line_item()
    {
        list($site, $token, $conversation) = $this->chat();
        $bounce = $this->bounceThread($conversation);
        $this->assertTrue($bounce->isAutoResponder());
        $this->assertTrue($bounce->isBounce());

        \Eventy::action('conversation.customer_replied', $conversation, $bounce, $conversation->customer);

        $items = $this->redItems($conversation);
        $this->assertCount(1, $items);
        $this->assertSame(
            'The email to anna@example.test bounced. The visitor may not have received the reply.',
            $items[0]->getActionText('', false)
        );
        $this->assertSame(0, Message::where('thread_id', $bounce->id)->count());
        $this->assertNull(ConversationState::of($conversation)->cutoff_message_id);
    }

    public function test_auto_reply_that_is_no_bounce_gets_nothing()
    {
        list($site, $token, $conversation) = $this->chat();
        $auto = $this->bounceThread($conversation);
        $auto->from = 'anna@example.test';
        $auto->headers = "From: Anna <anna@example.test>\nSubject: Out of office\nAuto-Submitted: auto-replied\nX-Autoreply: yes";
        $auto->send_status_data = null;
        $auto->save();
        $this->assertTrue($auto->isAutoResponder());
        $this->assertFalse($auto->isBounce());

        \Eventy::action('conversation.customer_replied', $conversation, $auto, $conversation->customer);

        $this->assertCount(0, $this->redItems($conversation));
        $this->assertSame(0, Message::where('thread_id', $auto->id)->count());
        $this->assertNull(ConversationState::of($conversation)->cutoff_message_id);
    }

    public function test_bounce_without_customer_email_names_the_customers_address()
    {
        list($site, $token, $conversation) = $this->chat();
        $conversation->customer->addEmail('anna.main@example.test');
        $conversation->customer_email = '';
        $conversation->save();
        $bounce = $this->bounceThread($conversation);

        \Eventy::action('conversation.customer_replied', $conversation, $bounce, $conversation->customer);

        $items = $this->redItems($conversation);
        $this->assertCount(1, $items);
        $this->assertSame(
            'The email to anna.main@example.test bounced. The visitor may not have received the reply.',
            $items[0]->getActionText('', false)
        );
    }

    public function test_bounce_without_any_address_names_none()
    {
        list($site, $token, $conversation) = $this->chat();
        $conversation->customer_email = '';
        $conversation->save();
        $bounce = $this->bounceThread($conversation);

        \Eventy::action('conversation.customer_replied', $conversation, $bounce, $conversation->customer);

        $items = $this->redItems($conversation);
        $this->assertCount(1, $items);
        $this->assertSame(
            'An email to the visitor bounced. The visitor may not have received the reply.',
            $items[0]->getActionText('', false)
        );
    }

    /**
     * Final review I1, core's real path: FetchEmails never threads a bounce
     * into the conversation it bounced from (its reply lookup is skipped for
     * bounces), so `conversation.customer_replied` never fires there. The
     * bounce lands in a new email conversation, and saveBounceData() marks
     * the bounced reply and logs STATUS_DELIVERY_ERROR.
     */
    public function test_core_bounce_processing_writes_one_red_line_item()
    {
        list($site, $token, $conversation) = $this->chat();
        $conversation->status = Conversation::STATUS_PENDING;
        $conversation->save();
        $reply = $this->agentThread($conversation, 5);
        // The reply went out by email: the gate for a bounce item.
        Message::create([
            'conversation_id' => $conversation->id,
            'thread_id'       => $reply->id,
            'direction'       => Message::DIRECTION_OUT,
            'via'             => Message::VIA_CHAT,
            'published_at'    => now()->subMinutes(5),
            'email_status'    => Message::EMAIL_SENT,
            'email_thread_id' => $reply->id,
        ]);

        $bounce = $this->coreBounce($conversation->mailbox, $reply);

        $this->assertNotEquals((int) $conversation->id, (int) $bounce->conversation_id);
        $this->assertSame(SendLog::STATUS_DELIVERY_ERROR, (int) $reply->fresh()->send_status);

        $items = $this->redItems($conversation);
        $this->assertCount(1, $items);
        $this->assertSame(
            'The email to anna@example.test bounced. The visitor may not have received the reply.',
            $items[0]->getActionText('', false)
        );
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) Conversation::find($conversation->id)->status);
        $this->assertSame('anna@example.test', Conversation::find($conversation->id)->customer_email);
        $this->assertSame(0, Message::where('thread_id', $bounce->id)->count());
        $this->assertNull(ConversationState::of($conversation)->cutoff_message_id);

        // Later saves of the bounced reply add nothing.
        $again = $reply->fresh();
        $again->body = '<p>Reply, edited</p>';
        $again->save();
        $this->assertCount(1, $this->redItems($conversation));
    }

    /** A bounce that did reach customer_replied here is noted once, not again by its send log. */
    public function test_bounce_seen_by_both_paths_gets_one_item()
    {
        list($site, $token, $conversation) = $this->chat();
        $reply = $this->agentThread($conversation, 5);
        $bounce = $this->bounceThread($conversation);

        \Eventy::action('conversation.customer_replied', $conversation, $bounce, $conversation->customer);
        (new FetchEmails())->saveBounceData($bounce, $reply->getMessageId($conversation->mailbox), self::BOUNCE_FROM);

        $this->assertSame(SendLog::STATUS_DELIVERY_ERROR, (int) $reply->fresh()->send_status);
        $this->assertCount(1, $this->redItems($conversation));
    }

    /** Final review M1: core's forward keeps channel 106 on its email copy. */
    public function test_bounce_from_a_forwarded_copy_gets_nothing()
    {
        list($site, $token, $conversation) = $this->chat();
        $copy = $this->forwardedCopy($conversation);
        $reply = $this->agentThread($copy, 5);

        $this->coreBounce($copy->mailbox, $reply);

        $this->assertSame(SendLog::STATUS_DELIVERY_ERROR, (int) $reply->fresh()->send_status);
        $this->assertCount(0, $this->redItems($copy));
        $this->assertCount(0, $this->redItems($conversation));
    }

    public function test_forwarded_copy_is_never_marked_lost()
    {
        list($site, $token, $conversation) = $this->chat();
        $copy = $this->forwardedCopy($conversation);
        $thread = $this->agentThread($copy, 120);

        $this->assertSame(0, DueEmails::markLost());
        $this->assertSame(0, Message::where('thread_id', $thread->id)->count());
        $this->assertCount(0, $this->redItems($copy));
    }

    /**
     * Final review I4: while the queue is backed up, a reply's publish job
     * (core's 15 s job on `default`) has not run yet. Marking it lost then
     * would make PublishReplyToChat skip it forever.
     */
    public function test_queue_backlog_skips_lost_marking()
    {
        list($site, $token, $conversation) = $this->chat();
        $thread = $this->agentThread($conversation, 120);
        $this->queuedJob('default', 1200);

        $this->assertSame(0, DueEmails::markLost());
        $this->assertSame(0, Message::where('thread_id', $thread->id)->count());
        $this->assertCount(0, $this->redItems($conversation));
    }

    public static function backlogFree(): array
    {
        return [
            'reserved'     => ['default', 1200, 0],
            'emails queue' => ['emails', 1200, null],
            'other queue'  => ['sms', 1200, null],
            'fresh'        => ['default', 300, null],
        ];
    }

    /** @dataProvider backlogFree */
    public function test_jobs_that_are_no_backlog_do_not_stop_lost_marking(string $queue, int $age, ?int $reservedAgo)
    {
        list($site, $token, $conversation) = $this->chat();
        $thread = $this->agentThread($conversation, 120);
        $this->queuedJob($queue, $age, $reservedAgo);

        $this->assertSame(1, DueEmails::markLost());
        $this->assertNotNull(Message::where('thread_id', $thread->id)->value('lost_at'));
    }

    public function test_backlog_is_only_read_from_a_database_queue()
    {
        list($site, $token, $conversation) = $this->chat();
        $thread = $this->agentThread($conversation, 120);
        $this->queuedJob('default', 1200);
        config(['queue.default' => 'sync']);

        $this->assertSame(1, DueEmails::markLost());
    }

    /** A crashed worker leaves its job reserved: that is a backlog too. */
    public function test_job_reserved_long_ago_is_a_backlog()
    {
        list($site, $token, $conversation) = $this->chat();
        $thread = $this->agentThread($conversation, 120);
        $this->queuedJob('default', 1200, 1200);

        $this->assertSame(0, DueEmails::markLost());
        $this->assertSame(0, Message::where('thread_id', $thread->id)->count());
    }

    public function test_mark_lost_works_in_batches_of_200()
    {
        list($site, $token, $conversation) = $this->chat();

        for ($i = 0; $i < 201; $i++) {
            $this->agentThread($conversation, 120);
        }

        $this->assertSame(200, DueEmails::markLost());
        $this->assertSame(200, Message::where("conversation_id", $conversation->id)->whereNotNull("lost_at")->count());
        $this->assertSame(1, DueEmails::markLost());
        $this->assertSame(0, DueEmails::markLost());
    }

    /** A competing run inserts the row after our scan: its row stays, we write no item. */
    public function test_mark_lost_race_keeps_the_competing_row()
    {
        list($site, $token, $conversation) = $this->chat();
        $thread = $this->agentThread($conversation, 120);
        $raced = 0;

        \DB::listen(function ($query) use ($thread, &$raced) {
            if ($raced || stripos($query->sql, 'select') !== 0 || strpos($query->sql, 'wordpresschat_messages') === false) {
                return;
            }
            $raced++;
            Message::create([
                'conversation_id' => $thread->conversation_id,
                'thread_id'       => $thread->id,
                'direction'       => Message::DIRECTION_OUT,
                'via'             => Message::VIA_CHAT,
                'lost_at'         => now(),
            ]);
        });

        $this->assertSame(0, DueEmails::markLost());
        $this->assertSame(1, $raced);
        $this->assertSame(1, Message::where('thread_id', $thread->id)->count());
        $this->assertCount(0, $this->redItems($conversation));
    }
}
