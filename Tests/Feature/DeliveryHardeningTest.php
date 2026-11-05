<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\Thread;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Jobs\SendChatEmail;
use Modules\WordPressChat\Listeners\RouteReplyChannel;
use Modules\WordPressChat\Services\ConversationHeader;
use Modules\WordPressChat\Services\CoreReplyMailer;
use Modules\WordPressChat\Services\DeliveryClock;
use Modules\WordPressChat\Services\DeliveryMarker;
use Modules\WordPressChat\Services\DueEmails;
use Modules\WordPressChat\Services\FailureLineItem;
use Modules\WordPressChat\Tests\FakeCoreReplyMailer;
use Modules\WordPressChat\Tests\TestCase;
use Modules\WordPressChat\WordPressChat;

/**
 * Plan 5, delivery hardening: queued rows whose job is gone fail visibly,
 * core's forwarded copies (channel 106, email type) are left alone, an
 * email choice reaches verified browsers only once it was sent, and a
 * merged chat's undelivered replies get one red line item. Plan 6: a reply
 * published after its chat was merged into another visitor's chat never
 * reaches that visitor.
 */
class DeliveryHardeningTest extends TestCase
{
    /** The stuck-queued age (constraints: 10,800 s). */
    const THREE_HOURS = 10800;

    protected function setUp(): void
    {
        parent::setUp();

        DeliveryMarker::clearCache();
    }

    /** A chat started through the API, with customer_email anna@example.test. */
    private function chat(): array
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $conversation = Conversation::find($chat['conversation']->id);
        $conversation->customer_email = 'anna@example.test';
        $conversation->save();

        return [$site, $chat['token'], $conversation, $chat['session']];
    }

    private function agentThread(Conversation $conversation, string $text = 'Reply'): Thread
    {
        $agent = $this->adminUser();

        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->type               = Thread::TYPE_MESSAGE;
        $thread->status             = Thread::STATUS_ACTIVE;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->body               = '<p>'.$text.'</p>';
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->source_via         = Thread::PERSON_USER;
        $thread->customer_id        = $conversation->customer_id;
        $thread->user_id            = $agent->id;
        $thread->created_by_user_id = $agent->id;
        $thread->save();

        return $thread;
    }

    private function customerEmail(Conversation $conversation): Thread
    {
        $thread = new Thread();
        $thread->conversation_id        = $conversation->id;
        $thread->type                   = Thread::TYPE_CUSTOMER;
        $thread->status                 = Thread::STATUS_ACTIVE;
        $thread->state                  = Thread::STATE_PUBLISHED;
        $thread->body                   = '<p>Answer from the inbox</p>';
        $thread->source_type            = Thread::SOURCE_TYPE_EMAIL;
        $thread->source_via             = Thread::PERSON_CUSTOMER;
        $thread->customer_id            = $conversation->customer_id;
        $thread->created_by_customer_id = $conversation->customer_id;
        $thread->save();

        return $thread;
    }

    /**
     * An agent reply published to the chat by the real listener, published
     * 200 s ago, so it is due (its due time keeps the listener's offset).
     */
    private function reply(Conversation $conversation, string $text = 'Reply'): array
    {
        $thread = $this->agentThread($conversation, $text);
        \Eventy::action('chat_conversation.send_reply', $conversation, collect([$thread]), $conversation->customer);

        $row = Message::where('thread_id', $thread->id)->firstOrFail();
        $publishedAt = now()->subSeconds(200)->startOfSecond();
        Message::where('id', $row->id)->update([
            'published_at'    => $publishedAt,
            'fallback_due_at' => $publishedAt->copy()->addSeconds(DeliveryClock::delayFor($conversation)),
        ]);

        return [$thread, $row->fresh()];
    }

    /** Rows of one batch, queued by the sweep and last touched $secondsAgo. */
    private function queued(array $rows, int $threadId, int $secondsAgo): void
    {
        Message::whereIn('id', array_map(function ($row) {
            return $row->id;
        }, $rows))->update([
            'email_status'    => Message::EMAIL_QUEUED,
            'email_thread_id' => $threadId,
            'updated_at'      => now()->subSeconds($secondsAgo),
        ]);
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

    /** A plain email conversation in the same mailbox, for the same customer. */
    private function emailConversation(Conversation $like): Conversation
    {
        $email = $like->replicate();
        $email->type = Conversation::TYPE_EMAIL;
        $email->channel = null;
        $email->threads_count = 0;
        $email->subject = 'Order question';
        $email->save();

        return Conversation::find($email->id);
    }

    private function fake($outcome = null, ?callable $during = null): FakeCoreReplyMailer
    {
        $mailer = new FakeCoreReplyMailer($outcome, $during);
        app()->instance(CoreReplyMailer::class, $mailer);

        return $mailer;
    }

    /**
     * An agent reply sent by email (the picker), as core routes it: captured,
     * intercepted, the job pushed. Returns the thread and the pushed job.
     */
    private function emailChoice(Conversation $conversation): array
    {
        $thread = $this->agentThread($conversation, 'Sensitive');
        RouteReplyChannel::capture($thread, new Request(['wpc_channel' => 'email']));
        $thread->save();
        $thread = Thread::find($thread->id);

        Queue::fake();
        $this->assertTrue(\Eventy::filter('conversation.skip_send_reply_to_customer', false, $conversation, collect([$thread])));
        $jobs = Queue::pushed(SendChatEmail::class);
        $this->assertCount(1, $jobs);

        return [$thread, $jobs->first()];
    }

    private function redItems(Conversation $conversation)
    {
        return Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_LINEITEM)
            ->get()
            ->filter(function ($thread) {
                return (bool) $thread->getMeta(FailureLineItem::FAILURE_META);
            })
            ->values();
    }

    private function emailStatus(Message $row): ?int
    {
        $status = $row->fresh()->email_status;

        return $status === null ? null : (int) $status;
    }

    private function pollIds($site, string $token): array
    {
        $response = $this->api('GET', $site, '/messages', $token);
        $response->assertStatus(200);

        return array_column($response->json()['messages'], 'id');
    }

    private function marker(Conversation $conversation, Thread $thread): string
    {
        ob_start();
        \Eventy::action('thread.meta', $thread, 0, collect([$thread]), $conversation, $conversation->mailbox);

        return ob_get_clean();
    }

    // ---- Stuck queued rows ----

    public function test_queued_row_older_than_three_hours_fails_visibly()
    {
        list(, , $conversation) = $this->chat();
        list(, $row1) = $this->reply($conversation, 'First');
        list($r2, $row2) = $this->reply($conversation, 'Second');
        $this->queued([$row1, $row2], (int) $r2->id, self::THREE_HOURS + 60);
        Queue::fake();

        $this->assertSame(0, DueEmails::sweep());
        $this->assertSame(0, DueEmails::sweep());

        $this->assertSame(Message::EMAIL_FAILED, $this->emailStatus($row1));
        $this->assertSame(Message::EMAIL_FAILED, $this->emailStatus($row2));
        $items = $this->redItems($conversation);
        $this->assertCount(1, $items);
        $this->assertSame('Email to the visitor failed: the email job was lost', $items[0]->getActionText('', false));
        Queue::assertNotPushed(SendChatEmail::class);
        $this->assertSame(self::THREE_HOURS, DueEmails::STALE_QUEUED);
    }

    public function test_fresh_queued_row_is_untouched()
    {
        list(, , $conversation) = $this->chat();
        list($thread, $row) = $this->reply($conversation);
        $this->queued([$row], (int) $thread->id, self::THREE_HOURS - 600);

        // A batch is handled only once every row of it is stale.
        list(, , $other) = $this->chat();
        list(, $old) = $this->reply($other, 'Old');
        list($t2, $recent) = $this->reply($other, 'Recent');
        $this->queued([$old, $recent], (int) $t2->id, self::THREE_HOURS + 60);
        Message::where('id', $recent->id)->update(['updated_at' => now()->subMinutes(5)]);

        $this->assertSame(0, DueEmails::resolveStale());

        $this->assertSame(Message::EMAIL_QUEUED, $this->emailStatus($row));
        $this->assertSame(Message::EMAIL_QUEUED, $this->emailStatus($old));
        $this->assertSame(Message::EMAIL_QUEUED, $this->emailStatus($recent));
        $this->assertCount(0, $this->redItems($conversation));
        $this->assertCount(0, $this->redItems($other));
    }

    /** M5: a slow `emails` queue is no lost job. */
    public function test_slow_emails_queue_is_not_a_lost_job()
    {
        list(, , $conversation) = $this->chat();
        list($thread, $row) = $this->reply($conversation);
        $this->queued([$row], (int) $thread->id, self::THREE_HOURS + 60);
        config(['queue.default' => 'database']);
        $now = time();
        \DB::table(config('queue.connections.database.table', 'jobs'))->insert([
            'queue' => 'emails', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => $now - 1200, 'created_at' => $now - 1200,
        ]);

        $this->assertSame(0, DueEmails::resolveStale());

        $this->assertSame(Message::EMAIL_QUEUED, $this->emailStatus($row));
        $this->assertCount(0, $this->redItems($conversation));
    }

    /** A retry re-queues the row between the scan and the fail: it belongs to a live job. */
    public function test_requeued_row_between_scan_and_fail_is_kept()
    {
        list(, , $conversation) = $this->chat();
        list($thread, $row) = $this->reply($conversation);
        $this->queued([$row], (int) $thread->id, self::THREE_HOURS + 60);
        $raced = 0;

        // The batch's row read is the scan's last look at updated_at; the bump
        // lands right after it, before the fail re-reads the rows.
        \DB::listen(function ($query) use ($row, &$raced) {
            if (!$raced && preg_match('/^select\s+["`]?id["`]?,\s*["`]?conversation_id["`]?,\s*["`]?updated_at["`]?\s+from\s+["`]?wordpresschat_messages/i', $query->sql) === 1) {
                $raced++;
                Message::where('id', $row->id)->update(['updated_at' => now()]);
            }
        });

        $this->assertSame(0, DueEmails::resolveStale());

        $this->assertSame(1, $raced);
        $this->assertSame(Message::EMAIL_QUEUED, $this->emailStatus($row));
        $this->assertCount(0, $this->redItems($conversation));
    }

    public function test_late_job_after_stuck_fail_sends_nothing()
    {
        list(, , $conversation) = $this->chat();
        list($thread, $row) = $this->reply($conversation);
        $this->queued([$row], (int) $thread->id, self::THREE_HOURS + 60);
        DueEmails::resolveStale();

        $mailer = $this->fake();
        app()->call([new SendChatEmail((int) $conversation->id, (int) $thread->id), 'handle']);

        $this->assertCount(0, $mailer->calls);
        $this->assertSame(Message::EMAIL_FAILED, $this->emailStatus($row));
        $this->assertCount(1, $this->redItems($conversation));
    }

    /** As the job's failed() hook: a row the visitor saw meanwhile needs no email and is no failure. */
    public function test_stuck_batch_drops_rows_that_need_no_email()
    {
        list(, , $conversation) = $this->chat();
        list(, $seen) = $this->reply($conversation, 'Seen');
        list($t2, $unseen) = $this->reply($conversation, 'Unseen');
        $this->queued([$seen, $unseen], (int) $t2->id, self::THREE_HOURS + 60);
        Message::where('id', $seen->id)->update(['seen_at' => now()->subHours(4), 'updated_at' => now()->subHours(4)]);

        $this->assertSame(1, DueEmails::resolveStale());

        $this->assertNull($this->emailStatus($seen));
        $this->assertNull($seen->fresh()->email_thread_id);
        $this->assertSame(Message::EMAIL_FAILED, $this->emailStatus($unseen));
        $this->assertCount(1, $this->redItems($conversation));

        list(, , $other) = $this->chat();
        list($t3, $allSeen) = $this->reply($other, 'Seen too');
        $this->queued([$allSeen], (int) $t3->id, self::THREE_HOURS + 60);
        Message::where('id', $allSeen->id)->update(['seen_at' => now()->subHours(4), 'updated_at' => now()->subHours(4)]);

        $this->assertSame(0, DueEmails::resolveStale());
        $this->assertNull($this->emailStatus($allSeen));
        $this->assertCount(0, $this->redItems($other));
    }

    // ---- Forwarded copies ----

    public function test_forwarded_email_copy_on_channel_106_is_ignored()
    {
        list(, , $conversation) = $this->chat();
        $copy = $this->forwardedCopy($conversation);
        $this->assertSame(WordPressChat::CHANNEL, (int) $copy->channel);

        // No delivery marker, even with a row.
        $marked = $this->agentThread($copy, 'Marked');
        Message::create([
            'conversation_id' => $copy->id,
            'thread_id'       => $marked->id,
            'direction'       => Message::DIRECTION_OUT,
            'via'             => Message::VIA_CHAT,
            'published_at'    => now(),
        ]);
        $this->assertSame('', $this->marker($copy, $marked));

        // No header, and no "via email" chip.
        $this->assertSame('', ConversationHeader::render($copy));
        $chipped = $this->customerEmail($copy);
        Message::create([
            'conversation_id' => $copy->id,
            'thread_id'       => $chipped->id,
            'direction'       => Message::DIRECTION_IN,
            'via'             => Message::VIA_EMAIL,
            'published_at'    => now(),
        ]);
        $this->assertSame('', ConversationHeader::threadChip($chipped));

        // No row when core fires the chat publish.
        $reply = $this->agentThread($copy, 'Published');
        \Eventy::action('chat_conversation.send_reply', $copy, collect([$reply]), $copy->customer);
        $this->assertSame(0, Message::where('thread_id', $reply->id)->count());

        // No row and no cut-off on a customer's email.
        $email = $this->customerEmail($copy);
        \Eventy::action('conversation.customer_replied', $copy, $email, $copy->customer);
        $this->assertSame(0, Message::where('thread_id', $email->id)->count());
        $this->assertNull(ConversationState::where('conversation_id', $copy->id)->value('cutoff_message_id'));
        $this->assertNull(ConversationState::where('conversation_id', $conversation->id)->value('cutoff_message_id'));

        $this->assertTrue(WordPressChat::isWebsiteChat($conversation));
        $this->assertFalse(WordPressChat::isWebsiteChat($copy));
        $conversation->channel = WordPressChat::CHANNEL + 1;
        $this->assertFalse(WordPressChat::isWebsiteChat($conversation));
    }

    // ---- Email-choice rows ----

    public function test_failed_email_choice_row_is_never_visible()
    {
        list($site, $token, $conversation, $session) = $this->chat();
        Access::where('session_id', $session->id)->update(['verified_at' => now()]);
        list($thread, $job) = $this->emailChoice($conversation);
        $mailer = $this->fake('Mailbox unavailable');

        app()->call([$job, 'handle']);

        $this->assertCount(1, $mailer->calls);
        $row = Message::where('thread_id', $thread->id)->firstOrFail();
        $this->assertSame(Message::EMAIL_FAILED, (int) $row->email_status);
        $this->assertNull($row->published_at);
        $this->assertNotContains((int) $row->id, $this->pollIds($site, $token));
        $this->assertCount(1, $this->redItems($conversation));
    }

    public function test_sent_email_choice_row_becomes_visible()
    {
        list($site, $token, $conversation, $session) = $this->chat();
        Access::where('session_id', $session->id)->update(['verified_at' => now()]);
        list($thread, $job) = $this->emailChoice($conversation);

        $row = Message::where('thread_id', $thread->id)->firstOrFail();
        $this->assertNull($row->published_at, 'intercept() must not publish');
        $this->assertNotContains((int) $row->id, $this->pollIds($site, $token));

        $during = 'not called';
        $mailer = $this->fake(null, function () use ($row, &$during) {
            $during = Message::where('id', $row->id)->value('published_at');
        });
        app()->call([$job, 'handle']);

        $this->assertCount(1, $mailer->calls);
        $this->assertNull($during, 'published while the email was still being sent');
        $row = $row->fresh();
        $this->assertSame(Message::EMAIL_SENT, (int) $row->email_status);
        $this->assertNotNull($row->published_at);
        $this->assertContains((int) $row->id, $this->pollIds($site, $token));
    }

    /** markSent() publishes email-choice rows that have no published_at, and nothing else. */
    public function test_mark_sent_publishes_only_unpublished_email_choice_rows()
    {
        list(, , $conversation) = $this->chat();
        $past = now()->subHour()->startOfSecond();
        $rows = [];

        foreach ([[Message::VIA_EMAIL, null], [Message::VIA_EMAIL, $past], [Message::VIA_CHAT, $past]] as $i => $case) {
            $thread = $this->agentThread($conversation, 'Reply '.$i);
            $rows[] = Message::create([
                'conversation_id' => $conversation->id,
                'thread_id'       => $thread->id,
                'direction'       => Message::DIRECTION_OUT,
                'via'             => $case[0],
                'published_at'    => $case[1],
                'email_status'    => Message::EMAIL_SENDING,
                'email_thread_id' => $thread->id,
            ]);
        }

        $this->assertSame(3, SendChatEmail::markSent(array_map(function ($row) {
            return (int) $row->id;
        }, $rows)));

        foreach ($rows as $row) {
            $this->assertSame(Message::EMAIL_SENT, $this->emailStatus($row));
        }
        $this->assertNotNull($rows[0]->fresh()->published_at);
        $this->assertSame($past->timestamp, $rows[1]->fresh()->published_at->timestamp);
        $this->assertSame($past->timestamp, $rows[2]->fresh()->published_at->timestamp);
    }

    // ---- Merge ----

    public function test_merge_marks_undelivered_replies_failed_with_one_item()
    {
        list(, , $source) = $this->chat();
        list(, $due1) = $this->reply($source, 'First');
        list(, $due2) = $this->reply($source, 'Second');
        list(, $seen) = $this->reply($source, 'Seen');
        Message::where('id', $seen->id)->update(['seen_at' => now()->subMinute()]);
        list($t4, $sent) = $this->reply($source, 'Emailed');
        Message::where('id', $sent->id)->update(['email_status' => Message::EMAIL_SENT, 'email_thread_id' => $t4->id]);
        // An undone email choice: its row stays, its thread is a draft again.
        $undone = $this->agentThread($source, 'Undone');
        $undone->state = Thread::STATE_DRAFT;
        $undone->save();
        $draftRow = Message::create([
            'conversation_id' => $source->id,
            'thread_id'       => $undone->id,
            'direction'       => Message::DIRECTION_OUT,
            'via'             => Message::VIA_EMAIL,
        ]);
        $target = $this->emailConversation($source);
        $user = $this->adminUser();

        $this->assertTrue($target->mergeConversations(Conversation::find($source->id), $user));

        foreach ([$due1, $due2] as $row) {
            $this->assertSame(Message::EMAIL_FAILED, $this->emailStatus($row));
            $this->assertNull($row->fresh()->fallback_due_at);
        }
        $this->assertNull($this->emailStatus($seen));
        $this->assertNotNull($seen->fresh()->fallback_due_at);
        $this->assertSame(Message::EMAIL_SENT, $this->emailStatus($sent));
        $this->assertNull($this->emailStatus($draftRow));

        $items = $this->redItems($target);
        $this->assertCount(1, $items);
        $this->assertSame(
            '2 website chat replies had not reached the visitor when this conversation was merged. They were not sent by email.',
            $items[0]->getActionText('', false)
        );
        $this->assertCount(0, $this->redItems($source));
    }

    /** A queued email choice is taken too, and one reply reads in the singular. */
    public function test_merge_with_one_queued_email_choice_says_so_once()
    {
        list(, , $source) = $this->chat();
        list($thread, $job) = $this->emailChoice($source);
        $target = $this->emailConversation($source);
        $user = $this->adminUser();

        $this->assertTrue($target->mergeConversations(Conversation::find($source->id), $user));

        $row = Message::where('thread_id', $thread->id)->firstOrFail();
        $this->assertSame(Message::EMAIL_FAILED, (int) $row->email_status);
        $items = $this->redItems($target);
        $this->assertCount(1, $items);
        $this->assertSame(
            'A website chat reply had not reached the visitor when this conversation was merged. It was not sent by email.',
            $items[0]->getActionText('', false)
        );

        // The job, when it runs, sends nothing.
        $mailer = $this->fake();
        app()->call([$job, 'handle']);
        $this->assertCount(0, $mailer->calls);
    }

    /**
     * Core publishes a chat reply 15 s after it was sent (its undo window).
     * A merge inside that window leaves no row for MergeChat to fail: the
     * publish, when it runs, finds the reply in an email conversation.
     */
    public function test_reply_published_after_its_chat_was_merged_away_gets_one_item()
    {
        list(, , $source) = $this->chat();
        $reply = $this->agentThread($source, 'Sent just before the merge');
        $target = $this->emailConversation($source);
        $user = $this->adminUser();

        $this->assertTrue($target->mergeConversations(Conversation::find($source->id), $user));
        $this->assertSame((int) $target->id, (int) Thread::find($reply->id)->conversation_id);
        $this->assertCount(0, $this->redItems($target));

        // Core's delayed publish, with the snapshot it was queued with.
        \Eventy::action('chat_conversation.send_reply', $source, collect([$reply]), $source->customer);
        \Eventy::action('chat_conversation.send_reply', $source, collect([$reply]), $source->customer);

        $rows = Message::where('thread_id', $reply->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(Message::EMAIL_FAILED, (int) $rows[0]->email_status);
        $this->assertSame(Message::DIRECTION_OUT, (int) $rows[0]->direction);
        $this->assertSame((int) $source->id, (int) $rows[0]->conversation_id);
        $this->assertNull($rows[0]->published_at);
        $this->assertNull($rows[0]->fallback_due_at);

        $items = $this->redItems($target);
        $this->assertCount(1, $items);
        $this->assertSame(
            'A website chat reply had not reached the visitor when this conversation was merged. It was not sent by email.',
            $items[0]->getActionText('', false)
        );
        $this->assertCount(0, $this->redItems($source));

        Queue::fake();
        $this->assertSame(0, DueEmails::sweep());
        Queue::assertNotPushed(SendChatEmail::class);
    }

    /**
     * Review Focus 1: a reply written to visitor A's chat, published after
     * A's chat was merged into visitor B's chat, must never reach B. No
     * browser holds both chats, so it fails like a reply whose chat was
     * merged into an email conversation.
     */
    public function test_late_reply_after_a_chat_merge_never_reaches_the_other_visitor()
    {
        $site = $this->makeSite();
        $a = $this->startChat($site, 'Question from A');
        $b = $this->startChat($site, 'Question from B');
        $this->assertNotEquals((int) $a['session']->id, (int) $b['session']->id);
        $source = Conversation::find($a['conversation']->id);
        $target = Conversation::find($b['conversation']->id);
        $reply = $this->agentThread($source, 'Private answer for A');
        $user = $this->adminUser();

        $this->assertTrue($target->mergeConversations(Conversation::find($source->id), $user));
        $this->assertSame((int) $target->id, (int) Thread::find($reply->id)->conversation_id);

        // Core's delayed publish, with the snapshot it was queued with.
        \Eventy::action('chat_conversation.send_reply', $source, collect([$reply]), $source->customer);

        $rows = Message::where('thread_id', $reply->id)->get();
        $this->assertCount(1, $rows);
        $response = $this->api('GET', $site, '/messages', $b['token']);
        $response->assertStatus(200);
        $this->assertNotContains((int) $rows[0]->id, array_column($response->json()['messages'], 'id'));
        $this->assertStringNotContainsString('Private answer for A', $response->getContent());

        $this->assertSame(Message::EMAIL_FAILED, (int) $rows[0]->email_status);
        $this->assertSame((int) $source->id, (int) $rows[0]->conversation_id);
        $this->assertNull($rows[0]->published_at);
        $this->assertNull($rows[0]->fallback_due_at);

        $items = $this->redItems($target);
        $this->assertCount(1, $items);
        $this->assertSame(
            'A website chat reply had not reached the visitor when this conversation was merged. It was not sent by email.',
            $items[0]->getActionText('', false)
        );
    }

    /**
     * Review Focus 1, the other order: the reply was published to A's chat
     * before the merge, and core's publish runs again afterwards (a queue
     * retry). The row stays A's; B's browser never receives it.
     */
    public function test_repeated_publish_after_a_chat_merge_keeps_the_reply_private()
    {
        $site = $this->makeSite();
        $a = $this->startChat($site, 'Question from A');
        $b = $this->startChat($site, 'Question from B');
        $source = Conversation::find($a['conversation']->id);
        $target = Conversation::find($b['conversation']->id);
        $reply = $this->agentThread($source, 'Private answer for A');
        \Eventy::action('chat_conversation.send_reply', $source, collect([$reply]), $source->customer);
        $row = Message::where('thread_id', $reply->id)->firstOrFail();
        $this->assertContains((int) $row->id, $this->pollIds($site, $a['token']));
        $user = $this->adminUser();

        $this->assertTrue($target->mergeConversations(Conversation::find($source->id), $user));
        \Eventy::action('chat_conversation.send_reply', $source, collect([$reply]), $source->customer);

        $this->assertSame((int) $source->id, (int) $row->fresh()->conversation_id);
        $response = $this->api('GET', $site, '/messages', $b['token']);
        $response->assertStatus(200);
        $this->assertNotContains((int) $row->id, array_column($response->json()['messages'], 'id'));
        $this->assertStringNotContainsString('Private answer for A', $response->getContent());
        // MergeChat's item for the unseen reply, and no second one.
        $this->assertCount(1, $this->redItems($target));
    }

    /** Both chats are one browser's: the late reply follows the merge into the target. */
    public function test_late_reply_after_a_same_browser_merge_is_published()
    {
        $site = $this->makeSite();
        $first = $this->startChat($site, 'First question');
        $source = Conversation::find($first['conversation']->id);
        $source->status = Conversation::STATUS_CLOSED;
        $source->save();
        $second = $this->api('POST', $site, '/messages', $first['token'], [
            'text'              => 'Second question',
            'client_message_id' => bin2hex(random_bytes(8)),
        ]);
        $second->assertStatus(201);
        $target = Conversation::find(Message::findOrFail($second->json()['message']['id'])->conversation_id);
        $this->assertNotEquals((int) $source->id, (int) $target->id);
        $this->assertSame(2, Access::where('session_id', $first['session']->id)->count());
        $reply = $this->agentThread($source, 'Answer to the first question');
        $user = $this->adminUser();

        $this->assertTrue($target->mergeConversations(Conversation::find($source->id), $user));
        \Eventy::action('chat_conversation.send_reply', $source, collect([$reply]), $source->customer);

        $row = Message::where('thread_id', $reply->id)->firstOrFail();
        $this->assertSame((int) $target->id, (int) $row->conversation_id);
        $this->assertSame(Message::VIA_CHAT, (int) $row->via);
        $this->assertNotNull($row->published_at);
        $this->assertNull($this->emailStatus($row));
        $this->assertContains((int) $row->id, $this->pollIds($site, $first['token']));
        $this->assertCount(0, $this->redItems($target));
    }

    /**
     * Two publishes of one merged-away reply at once: the other run inserts
     * its row right after this run's existence check. This run's insert
     * loses on the unique thread_id and writes no item.
     */
    public function test_merged_away_unique_violation_writes_one_row()
    {
        list(, , $source) = $this->chat();
        $reply = $this->agentThread($source, 'Sent just before the merge');
        $target = $this->emailConversation($source);
        $user = $this->adminUser();
        $this->assertTrue($target->mergeConversations(Conversation::find($source->id), $user));
        $raced = 0;

        \DB::listen(function ($query) use ($reply, $source, &$raced) {
            if (!$raced && preg_match('/^select\s+exists\s*\(\s*select\s+\*\s+from\s+["`]?\w*wordpresschat_messages["`]?\s+where\s+["`]?thread_id["`]?\s*=/i', $query->sql) === 1) {
                $raced++;
                Message::create([
                    'conversation_id' => $source->id,
                    'thread_id'       => $reply->id,
                    'direction'       => Message::DIRECTION_OUT,
                    'via'             => Message::VIA_CHAT,
                    'email_status'    => Message::EMAIL_FAILED,
                ]);
            }
        });

        \Eventy::action('chat_conversation.send_reply', $source, collect([$reply]), $source->customer);

        $this->assertSame(1, $raced);
        $this->assertSame(1, Message::where('thread_id', $reply->id)->count());
        $this->assertCount(0, $this->redItems($target));
    }

    public function test_merge_of_non_chat_source_does_nothing()
    {
        list(, , $chat) = $this->chat();
        list(, , $otherChannel) = $this->chat();
        $otherChannel->channel = WordPressChat::CHANNEL + 1;
        $otherChannel->save();
        $user = $this->adminUser();

        $sources = [
            'forwarded copy' => $this->forwardedCopy($chat),
            'other channel'  => $otherChannel,
        ];

        foreach ($sources as $label => $source) {
            $thread = $this->agentThread($source);
            $due = now()->subMinutes(5)->startOfSecond();
            $row = Message::create([
                'conversation_id' => $source->id,
                'thread_id'       => $thread->id,
                'direction'       => Message::DIRECTION_OUT,
                'via'             => Message::VIA_CHAT,
                'published_at'    => $due,
                'fallback_due_at' => $due,
            ]);
            $target = $this->emailConversation($chat);

            $this->assertTrue($target->mergeConversations(Conversation::find($source->id), $user), $label);

            $this->assertNull($this->emailStatus($row), $label);
            $this->assertSame($due->timestamp, $row->fresh()->fallback_due_at->timestamp, $label);
            $this->assertCount(0, $this->redItems($target), $label);
        }
    }
}
