<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\SendLog;
use App\Thread;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\Queue;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Link;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Jobs\SendChatEmail;
use Modules\WordPressChat\Services\CoreReplyMailer;
use Modules\WordPressChat\Services\DeliveryClock;
use Modules\WordPressChat\Services\DueEmails;
use Modules\WordPressChat\Services\FailureLineItem;
use Modules\WordPressChat\Tests\FakeCoreReplyMailer;
use Modules\WordPressChat\Tests\TestCase;

/**
 * Spec §7: an agent reply the visitor did not see in the chat goes out as
 * one email after the site's delay, exactly once, and every failure shows
 * up for the agent as a red line item (Rulings D1, D2, D4, D5, D6, D10).
 */
class FallbackEmailTest extends TestCase
{
    /** A chat started through the API, with customer_email anna@example.test. */
    private function chat(string $email = 'anna@example.test'): array
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $conversation = Conversation::find($chat['conversation']->id);
        $conversation->customer_email = $email;
        $conversation->save();

        return [$site, $chat['token'], $conversation];
    }

    private function agentThread(Conversation $conversation, string $text): Thread
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

    /**
     * An agent reply published to the chat by the real listener, then moved
     * to $publishedAt (default: 200 s ago, so it is due). Its due time keeps
     * the listener's offset (published + the site's delay).
     */
    private function reply(Conversation $conversation, string $text = 'Reply', ?Carbon $publishedAt = null): array
    {
        $thread = $this->agentThread($conversation, $text);
        \Eventy::action('chat_conversation.send_reply', $conversation, collect([$thread]), $conversation->customer);

        $row = Message::where('thread_id', $thread->id)->firstOrFail();
        $this->assertNotNull($row->fallback_due_at);

        $publishedAt = $publishedAt ?: now()->subSeconds(200)->startOfSecond();
        Message::where('id', $row->id)->update([
            'published_at'    => $publishedAt,
            'fallback_due_at' => $publishedAt->copy()->addSeconds(DeliveryClock::delayFor($conversation)),
        ]);

        return [$thread, $row->fresh()];
    }

    /**
     * Runs the sweep with its clock at $at. Not Carbon::setTestNow(): a
     * frozen clock breaks now() itself in this app (core's Carbon override).
     */
    private function sweepAt(Carbon $at): int
    {
        return DueEmails::sweep($at);
    }

    private function fake($outcome = null, ?callable $during = null): FakeCoreReplyMailer
    {
        $mailer = new FakeCoreReplyMailer($outcome, $during);
        app()->instance(CoreReplyMailer::class, $mailer);

        return $mailer;
    }

    private function runJob(SendChatEmail $job): void
    {
        app()->call([$job, 'handle']);
    }

    /** The one job the sweep pushed. */
    private function pushedJob(): SendChatEmail
    {
        $jobs = Queue::pushed(SendChatEmail::class);
        $this->assertCount(1, $jobs);

        return $jobs->first();
    }

    /** A queue job whose attempts() is fixed; release() expected once with $delay, or never. */
    private function queueJob(int $attempts, ?int $delay)
    {
        $job = \Mockery::mock(QueueJob::class);
        $job->shouldReceive('attempts')->andReturn($attempts);

        if ($delay === null) {
            $job->shouldNotReceive('release');
        } else {
            $job->shouldReceive('release')->once()->with($delay);
        }

        return $job;
    }

    private function failureItems(Conversation $conversation)
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

    /** Rows of one batch, as the sweep would have queued them. */
    private function queue(array $rows, int $threadId): void
    {
        Message::whereIn('id', array_map(function ($row) {
            return $row->id;
        }, $rows))->update(['email_status' => Message::EMAIL_QUEUED, 'email_thread_id' => $threadId]);
    }

    /** Rows of one batch left in "sending", last touched $secondsAgo. */
    private function sending(array $rows, int $threadId, int $secondsAgo): void
    {
        Message::whereIn('id', array_map(function ($row) {
            return $row->id;
        }, $rows))->update([
            'email_status'    => Message::EMAIL_SENDING,
            'email_thread_id' => $threadId,
            'updated_at'      => now()->subSeconds($secondsAgo),
        ]);
    }

    private function close(Conversation $conversation): void
    {
        $conversation = Conversation::find($conversation->id);
        $conversation->status = Conversation::STATUS_CLOSED;
        $conversation->closed_at = now();
        $conversation->save();
    }

    public function test_due_reply_is_queued_once()
    {
        list(, , $conversation) = $this->chat();
        $t = now()->subMinutes(10)->startOfSecond();
        list($thread, $row) = $this->reply($conversation, 'Reply', $t);
        Queue::fake();

        $this->assertSame(0, $this->sweepAt($t->copy()->addSeconds(119)));
        $this->assertNull($this->emailStatus($row));
        Queue::assertNotPushed(SendChatEmail::class);

        $this->assertSame(1, $this->sweepAt($t->copy()->addSeconds(121)));
        Queue::assertPushedOn('emails', SendChatEmail::class, function ($job) use ($conversation, $thread) {
            return $job->conversationId === (int) $conversation->id && $job->threadId === (int) $thread->id;
        });
        $this->assertSame(Message::EMAIL_QUEUED, $this->emailStatus($row));
        $this->assertSame((int) $thread->id, (int) $row->fresh()->email_thread_id);

        $this->assertSame(0, $this->sweepAt($t->copy()->addSeconds(181)));
        $this->assertCount(1, Queue::pushed(SendChatEmail::class));
    }

    public function test_seen_reply_is_never_queued()
    {
        list(, , $conversation) = $this->chat();
        list(, $row) = $this->reply($conversation);
        Message::where('id', $row->id)->update(['seen_at' => now()->subSeconds(150)]);
        Queue::fake();

        $this->assertSame(0, DueEmails::sweep());

        $this->assertNull($this->emailStatus($row));
        Queue::assertNotPushed(SendChatEmail::class);
    }

    public function test_anonymous_conversation_waits()
    {
        list(, , $conversation) = $this->chat('');
        list(, $row) = $this->reply($conversation);
        $this->assertNull(DueEmails::address($conversation));
        Queue::fake();

        $this->assertSame(0, DueEmails::sweep());

        $this->assertNull($this->emailStatus($row));
        Queue::assertNotPushed(SendChatEmail::class);
    }

    public function test_rows_without_due_time_are_never_swept()
    {
        list(, , $conversation) = $this->chat();
        $thread = $this->agentThread($conversation, 'From before Plan 3');
        $row = Message::create([
            'conversation_id' => $conversation->id,
            'thread_id'       => $thread->id,
            'direction'       => Message::DIRECTION_OUT,
            'via'             => Message::VIA_CHAT,
            'published_at'    => now()->subDay(),
        ]);
        Queue::fake();

        $this->assertSame(0, DueEmails::sweep());

        $this->assertNull($this->emailStatus($row));
        Queue::assertNotPushed(SendChatEmail::class);
    }

    public function test_due_rows_older_than_the_window_are_skipped()
    {
        list(, , $conversation) = $this->chat();
        list(, $row) = $this->reply($conversation, 'Old', now()->subDays(DueEmails::WINDOW_DAYS)->subMinutes(5));
        Queue::fake();

        $this->assertSame(0, DueEmails::sweep());

        $this->assertNull($this->emailStatus($row));
        Queue::assertNotPushed(SendChatEmail::class);
    }

    public function test_one_email_carries_every_due_reply()
    {
        list($site, , $conversation) = $this->chat();
        list($r1, $row1) = $this->reply($conversation, 'First');
        list($r2, $row2) = $this->reply($conversation, 'Second');
        Queue::fake();

        $this->assertSame(1, DueEmails::sweep());
        $mailer = $this->fake();
        $this->runJob($this->pushedJob());

        $this->assertCount(1, $mailer->calls);
        $call = $mailer->calls[0];
        $this->assertSame((int) $conversation->id, $call['conversation_id']);
        $this->assertSame((int) $r2->id, $call['first_reply_id']);

        $threadIds = $call['context']['thread_ids'];
        sort($threadIds);
        $this->assertSame([(int) $r1->id, (int) $r2->id], $threadIds);
        $this->assertStringStartsWith(rtrim($site->url, '/').'/#lcff-continue=', $call['context']['link']);

        $this->assertSame(Message::EMAIL_SENT, $this->emailStatus($row1));
        $this->assertSame(Message::EMAIL_SENT, $this->emailStatus($row2));
        $this->assertSame(1, Link::where('conversation_id', $conversation->id)->count());
        $this->assertCount(0, $this->failureItems($conversation));
    }

    public function test_reply_seen_before_the_job_is_dropped()
    {
        list(, , $conversation) = $this->chat();
        list($r1, $row1) = $this->reply($conversation, 'First');
        list(, $row2) = $this->reply($conversation, 'Second');
        Queue::fake();

        $this->assertSame(1, DueEmails::sweep());
        Message::where('id', $row2->id)->update(['seen_at' => now()]);
        $mailer = $this->fake();
        $this->runJob($this->pushedJob());

        $this->assertCount(1, $mailer->calls);
        $this->assertSame((int) $r1->id, $mailer->calls[0]['first_reply_id']);
        $this->assertSame([(int) $r1->id], $mailer->calls[0]['context']['thread_ids']);
        $this->assertSame(Message::EMAIL_SENT, $this->emailStatus($row1));
        $this->assertNull($this->emailStatus($row2));
        $this->assertNull($row2->fresh()->email_thread_id);
    }

    public function test_all_seen_before_the_job_sends_nothing()
    {
        list(, , $conversation) = $this->chat();
        list(, $row1) = $this->reply($conversation, 'First');
        list(, $row2) = $this->reply($conversation, 'Second');
        Queue::fake();

        $this->assertSame(1, DueEmails::sweep());
        Message::whereIn('id', [$row1->id, $row2->id])->update(['seen_at' => now()]);
        $mailer = $this->fake();
        $this->runJob($this->pushedJob());

        $this->assertCount(0, $mailer->calls);
        $this->assertNull($this->emailStatus($row1));
        $this->assertNull($this->emailStatus($row2));
        $this->assertSame(0, Link::where('conversation_id', $conversation->id)->count());

        // Seen rows are never swept again.
        $this->assertSame(0, DueEmails::sweep());
    }

    /** A seen lands after drop() read the rows and before the claim: the claim leaves the row out. */
    public function test_seen_between_drop_and_claim_sends_nothing()
    {
        list(, , $conversation) = $this->chat();
        list(, $row) = $this->reply($conversation);
        Queue::fake();

        $this->assertSame(1, DueEmails::sweep());
        $job = $this->pushedJob();
        $mailer = $this->fake();
        $raced = 0;

        // drop()'s read is the job's first select on the messages table.
        \DB::listen(function ($query) use ($row, &$raced) {
            if ($raced || stripos($query->sql, 'select') !== 0 || strpos($query->sql, 'wordpresschat_messages') === false) {
                return;
            }
            $raced++;
            Message::where('id', $row->id)->update(['seen_at' => now()]);
        });
        $this->runJob($job);

        $this->assertSame(1, $raced);
        $this->assertCount(0, $mailer->calls);
        $this->assertNull($this->emailStatus($row));
        $this->assertNull($row->fresh()->email_thread_id);
    }

    public function test_seen_after_the_claim_does_not_cancel()
    {
        list(, , $conversation) = $this->chat();
        list(, $row) = $this->reply($conversation);
        Queue::fake();

        $this->assertSame(1, DueEmails::sweep());
        $mailer = $this->fake(null, function () use ($row) {
            Message::where('id', $row->id)->update(['seen_at' => now()]);
        });
        $this->runJob($this->pushedJob());

        $this->assertCount(1, $mailer->calls);
        $this->assertNotNull($row->fresh()->seen_at);
        $this->assertSame(Message::EMAIL_SENT, $this->emailStatus($row));
    }

    public function test_job_twice_sends_once()
    {
        list(, , $conversation) = $this->chat();
        list($thread, $row) = $this->reply($conversation);
        Queue::fake();

        $this->assertSame(1, DueEmails::sweep());
        $this->assertSame(0, DueEmails::sweep());
        $mailer = $this->fake();

        $this->runJob($this->pushedJob());
        $this->runJob(new SendChatEmail((int) $conversation->id, (int) $thread->id));

        $this->assertCount(1, $mailer->calls);
        $this->assertSame(Message::EMAIL_SENT, $this->emailStatus($row));
        $this->assertSame(1, Link::where('conversation_id', $conversation->id)->count());
        $this->assertSame(0, DueEmails::sweep());
    }

    public function test_permanent_failure_writes_one_red_line_item_and_reactivates()
    {
        list(, , $conversation) = $this->chat();
        list($r1, $row1) = $this->reply($conversation, 'First');
        list($r2, $row2) = $this->reply($conversation, 'Second');
        $conversation = Conversation::find($conversation->id);
        $conversation->status = Conversation::STATUS_CLOSED;
        $conversation->closed_at = now();
        $conversation->save();
        Queue::fake();

        $this->assertSame(1, DueEmails::sweep());
        $mailer = $this->fake('Address rejected');
        $this->runJob($this->pushedJob());
        // A duplicate job finds the batch failed: no second line item.
        $this->runJob(new SendChatEmail((int) $conversation->id, (int) $r2->id));

        $this->assertCount(1, $mailer->calls);
        $this->assertSame(Message::EMAIL_FAILED, $this->emailStatus($row1));
        $this->assertSame(Message::EMAIL_FAILED, $this->emailStatus($row2));

        $items = $this->failureItems($conversation);
        $this->assertCount(1, $items);
        $this->assertSame(
            '<span class="text-danger">Email to the visitor failed: Address rejected</span>',
            $items[0]->getActionText('', true)
        );
        $this->assertSame('Email to the visitor failed: Address rejected', $items[0]->getActionText('', false));
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) Conversation::find($conversation->id)->status);
    }

    public function test_long_failure_reason_is_cut_to_200_characters()
    {
        list(, , $conversation) = $this->chat();
        $this->reply($conversation);
        Queue::fake();

        $this->assertSame(1, DueEmails::sweep());
        $this->fake(str_repeat('x', 500));
        $this->runJob($this->pushedJob());

        $items = $this->failureItems($conversation);
        $this->assertCount(1, $items);
        $this->assertSame(
            'Email to the visitor failed: '.str_repeat('x', 200),
            $items[0]->getActionText('', false)
        );
    }

    public function test_transient_failure_is_retried_then_fails()
    {
        list(, , $conversation) = $this->chat();
        list($thread, $row) = $this->reply($conversation);
        Queue::fake();

        $this->assertSame(1, DueEmails::sweep());
        $mailer = $this->fake(new \RuntimeException('Connection could not be established'));
        \Log::spy();

        $first = $this->pushedJob();
        $first->setJob($this->queueJob(1, 300));
        $this->runJob($first);

        $this->assertSame(Message::EMAIL_QUEUED, $this->emailStatus($row));
        $this->assertSame((int) $thread->id, (int) $row->fresh()->email_thread_id);
        $this->assertCount(0, $this->failureItems($conversation));

        $second = new SendChatEmail((int) $conversation->id, (int) $thread->id);
        $second->setJob($this->queueJob(2, 900));
        $this->runJob($second);

        $this->assertSame(Message::EMAIL_QUEUED, $this->emailStatus($row));
        $this->assertCount(0, $this->failureItems($conversation));

        $last = new SendChatEmail((int) $conversation->id, (int) $thread->id);
        $last->setJob($this->queueJob(SendChatEmail::TRIES, null));
        $this->runJob($last);

        $this->assertCount(3, $mailer->calls);
        $this->assertSame(Message::EMAIL_FAILED, $this->emailStatus($row));
        $items = $this->failureItems($conversation);
        $this->assertCount(1, $items);
        $this->assertSame(
            'Email to the visitor failed: the mail server could not be reached',
            $items[0]->getActionText('', false)
        );

        // One warning per non-final attempt, with the raw error.
        \Log::shouldHaveReceived('warning')->twice();
        \Log::shouldHaveReceived('warning')->atLeast()->once()->withArgs(function ($message) use ($conversation) {
            return strpos($message, 'fallback email attempt 1 of 5 failed, retrying in 300 s') !== false
                && strpos($message, 'Connection could not be established') !== false
                && strpos($message, '#'.$conversation->id) !== false;
        });
        \Log::shouldHaveReceived('warning')->atLeast()->once()->withArgs(function ($message) {
            return strpos($message, 'attempt 2 of 5 failed, retrying in 900 s') !== false;
        });
    }

    /**
     * Core accepted the email, then threw (e.g. writing its send log). A
     * retry could pick a different newest thread (a reply seen meanwhile is
     * dropped) and email again; the batch is sent.
     */
    public function test_throw_after_core_accepted_counts_as_sent()
    {
        list(, , $conversation) = $this->chat();
        list(, $row1) = $this->reply($conversation, 'First');
        list($r2, $row2) = $this->reply($conversation, 'Second');
        Queue::fake();

        $this->assertSame(1, DueEmails::sweep());
        $mailer = $this->fake(new \RuntimeException('Send log write failed'), function () use ($r2) {
            Thread::where('id', $r2->id)->update(['send_status' => SendLog::STATUS_ACCEPTED]);
        });
        $job = $this->pushedJob();
        $job->setJob($this->queueJob(1, null));
        $this->runJob($job);

        $this->assertCount(1, $mailer->calls);
        $this->assertSame(Message::EMAIL_SENT, $this->emailStatus($row1));
        $this->assertSame(Message::EMAIL_SENT, $this->emailStatus($row2));
        $this->assertCount(0, $this->failureItems($conversation));
    }

    public function test_no_usable_address_fails_without_mailing()
    {
        list(, , $conversation) = $this->chat('not-an-address');
        list($thread, $row) = $this->reply($conversation);
        $this->assertNull(DueEmails::address($conversation));
        $this->queue([$row], (int) $thread->id);
        Queue::fake();

        $mailer = $this->fake();
        $this->runJob(new SendChatEmail((int) $conversation->id, (int) $thread->id));

        $this->assertCount(0, $mailer->calls);
        $this->assertSame(Message::EMAIL_FAILED, $this->emailStatus($row));
        $items = $this->failureItems($conversation);
        $this->assertCount(1, $items);
        $this->assertSame('Email to the visitor failed: no usable email address', $items[0]->getActionText('', false));
    }

    public function test_empty_customer_email_is_backfilled()
    {
        list(, , $conversation) = $this->chat('');
        $conversation->customer->addEmail('anna@example.test');
        list(, $row) = $this->reply($conversation);
        $this->assertSame('anna@example.test', DueEmails::address(Conversation::find($conversation->id)));
        Queue::fake();

        $this->assertSame(1, DueEmails::sweep());
        $mailer = $this->fake();
        $this->runJob($this->pushedJob());

        $this->assertSame('anna@example.test', Conversation::find($conversation->id)->customer_email);
        $this->assertCount(1, $mailer->calls);
        $this->assertSame(Message::EMAIL_SENT, $this->emailStatus($row));
    }

    /** Spec §6.4 rule 5: an emailed chat reply stays in the chat for the browser that was there. */
    public function test_emailed_chat_reply_stays_in_the_chat()
    {
        list($site, $token, $conversation) = $this->chat();
        list(, $row) = $this->reply($conversation, 'Still here');
        Queue::fake();

        $this->assertSame(1, DueEmails::sweep());
        $this->fake();
        $this->runJob($this->pushedJob());
        $this->assertSame(Message::EMAIL_SENT, $this->emailStatus($row));

        $response = $this->api('GET', $site, '/messages', $token);
        $response->assertStatus(200);
        $this->assertContains((int) $row->id, array_map('intval', array_column($response->json()['messages'], 'id')));
    }

    public function test_missing_state_still_sends_without_a_link()
    {
        list(, , $conversation) = $this->chat();
        list(, $row) = $this->reply($conversation);
        ConversationState::where('conversation_id', $conversation->id)->delete();
        Queue::fake();

        $this->assertSame(1, DueEmails::sweep());
        $mailer = $this->fake();
        $this->runJob($this->pushedJob());

        $this->assertCount(1, $mailer->calls);
        $this->assertArrayHasKey('link', $mailer->calls[0]['context']);
        $this->assertNull($mailer->calls[0]['context']['link']);
        $this->assertSame(Message::EMAIL_SENT, $this->emailStatus($row));
        $this->assertSame(0, Link::where('conversation_id', $conversation->id)->count());
    }

    /**
     * Final review I2 (Ruling D5: a link only ever goes to the
     * conversation's own address). Core's job sends one message to To plus
     * the newest reply's Cc and Bcc plus the mailbox's auto Bcc.
     */
    public static function extraRecipients(): array
    {
        return [
            'cc'       => ['cc', ['boss@example.test']],
            'bcc'      => ['bcc', ['audit@example.test']],
            'two to'   => ['to', ['anna@example.test', 'bob@example.test']],
            'auto bcc' => ['auto_bcc', 'archive@example.test'],
        ];
    }

    /** @dataProvider extraRecipients */
    public function test_extra_recipients_get_the_email_without_a_link(string $field, $value)
    {
        list(, , $conversation) = $this->chat();
        list(, $row1) = $this->reply($conversation, 'First');
        list($r2, $row2) = $this->reply($conversation, 'Second');

        if ($field === 'auto_bcc') {
            $mailbox = $conversation->mailbox;
            $mailbox->auto_bcc = $value;
            $mailbox->save();
        } else {
            $thread = Thread::find($r2->id);
            $thread->{'set'.ucfirst($field)}($value);
            $thread->save();
        }

        Queue::fake();
        $this->assertSame(1, DueEmails::sweep());
        $mailer = $this->fake();
        $this->runJob($this->pushedJob());

        $this->assertCount(1, $mailer->calls);
        $this->assertArrayHasKey('link', $mailer->calls[0]['context']);
        $this->assertNull($mailer->calls[0]['context']['link']);
        $this->assertSame(0, Link::where('conversation_id', $conversation->id)->count());
        $this->assertSame(Message::EMAIL_SENT, $this->emailStatus($row1));
        $this->assertSame(Message::EMAIL_SENT, $this->emailStatus($row2));
    }

    /** The customer and the mailbox itself in Cc or Bcc are no extra recipients: core drops them. */
    public function test_customer_and_mailbox_in_cc_keep_the_link()
    {
        list($site, , $conversation) = $this->chat();
        list($r1, $row1) = $this->reply($conversation, 'Only');
        $thread = Thread::find($r1->id);
        $thread->setTo(['anna@example.test']);
        $thread->setCc(['anna@example.test', $conversation->mailbox->email]);
        $thread->setBcc([$conversation->mailbox->email]);
        $thread->save();

        Queue::fake();
        $this->assertSame(1, DueEmails::sweep());
        $mailer = $this->fake();
        $this->runJob($this->pushedJob());

        $this->assertStringStartsWith(rtrim($site->url, '/').'/#lcff-continue=', $mailer->calls[0]['context']['link']);
        $this->assertSame(1, Link::where('conversation_id', $conversation->id)->count());
        $this->assertSame(Message::EMAIL_SENT, $this->emailStatus($row1));
    }

    /** The stale check reads updated_at: the claim must stamp it. */
    public function test_claim_stamps_updated_at()
    {
        list(, , $conversation) = $this->chat();
        list(, $row) = $this->reply($conversation);
        Queue::fake();

        $this->assertSame(1, DueEmails::sweep());
        Message::where('id', $row->id)->update(['updated_at' => now()->subHour()]);
        $before = now()->subSeconds(5);
        $stamped = null;
        $this->fake(null, function () use ($row, &$stamped) {
            $stamped = Message::find($row->id)->updated_at;
        });
        $this->runJob($this->pushedJob());

        $this->assertNotNull($stamped);
        $this->assertGreaterThanOrEqual($before->timestamp, $stamped->timestamp);
    }

    public function test_stale_sending_batch_with_accepted_mail_becomes_sent()
    {
        list(, , $conversation) = $this->chat();
        list(, $row1) = $this->reply($conversation, 'First');
        list($r2, $row2) = $this->reply($conversation, 'Second');
        $this->sending([$row1, $row2], (int) $r2->id, DueEmails::STALE_SENDING + 1);
        Thread::where('id', $r2->id)->update(['send_status' => SendLog::STATUS_ACCEPTED]);
        Queue::fake();

        $this->assertSame(0, DueEmails::sweep());

        $this->assertSame(Message::EMAIL_SENT, $this->emailStatus($row1));
        $this->assertSame(Message::EMAIL_SENT, $this->emailStatus($row2));
        $this->assertCount(0, $this->failureItems($conversation));
        Queue::assertNotPushed(SendChatEmail::class);
    }

    public function test_stale_sending_batch_without_accepted_mail_fails_once()
    {
        list(, , $conversation) = $this->chat();
        list(, $row1) = $this->reply($conversation, 'First');
        list($r2, $row2) = $this->reply($conversation, 'Second');
        $this->close($conversation);
        $this->sending([$row1, $row2], (int) $r2->id, DueEmails::STALE_SENDING + 1);
        Queue::fake();

        $this->assertSame(0, DueEmails::sweep());
        $this->assertSame(0, DueEmails::sweep());

        $this->assertSame(Message::EMAIL_FAILED, $this->emailStatus($row1));
        $this->assertSame(Message::EMAIL_FAILED, $this->emailStatus($row2));
        $items = $this->failureItems($conversation);
        $this->assertCount(1, $items);
        $this->assertSame('Email to the visitor failed: sending was interrupted', $items[0]->getActionText('', false));
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) Conversation::find($conversation->id)->status);
        Queue::assertNotPushed(SendChatEmail::class);
    }

    public function test_recent_sending_batch_is_left_alone()
    {
        list(, , $conversation) = $this->chat();
        list($thread, $row) = $this->reply($conversation);
        $this->sending([$row], (int) $thread->id, DueEmails::STALE_SENDING - 60);
        Queue::fake();

        $this->assertSame(0, DueEmails::sweep());

        $this->assertSame(Message::EMAIL_SENDING, $this->emailStatus($row));
        $this->assertCount(0, $this->failureItems($conversation));
        Queue::assertNotPushed(SendChatEmail::class);
    }

    /** A batch is resolved only once every row of it is stale (a "seen" stamps updated_at too). */
    public function test_partly_recent_sending_batch_waits()
    {
        list(, , $conversation) = $this->chat();
        list(, $row1) = $this->reply($conversation, 'First');
        list($r2, $row2) = $this->reply($conversation, 'Second');
        $this->sending([$row1, $row2], (int) $r2->id, DueEmails::STALE_SENDING + 1);
        Message::where('id', $row2->id)->update(['seen_at' => now()]);
        Queue::fake();

        DueEmails::sweep();

        $this->assertSame(Message::EMAIL_SENDING, $this->emailStatus($row1));
        $this->assertSame(Message::EMAIL_SENDING, $this->emailStatus($row2));
        $this->assertCount(0, $this->failureItems($conversation));
    }

    public function test_failed_hook_fails_a_queued_batch_once()
    {
        list(, , $conversation) = $this->chat();
        list(, $row1) = $this->reply($conversation, 'First');
        list($r2, $row2) = $this->reply($conversation, 'Second');
        $this->close($conversation);
        $this->queue([$row1, $row2], (int) $r2->id);
        Queue::fake();

        $job = new SendChatEmail((int) $conversation->id, (int) $r2->id);
        $job->failed(new \Exception('Worker gave up'));
        $job->failed(new \Exception('Worker gave up'));

        $this->assertSame(Message::EMAIL_FAILED, $this->emailStatus($row1));
        $this->assertSame(Message::EMAIL_FAILED, $this->emailStatus($row2));
        $items = $this->failureItems($conversation);
        $this->assertCount(1, $items);
        $this->assertSame('Email to the visitor failed: Worker gave up', $items[0]->getActionText('', false));
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) Conversation::find($conversation->id)->status);
    }

    public function test_failed_hook_without_a_message_says_sending_failed()
    {
        list(, , $conversation) = $this->chat();
        list($thread, $row) = $this->reply($conversation);
        $this->queue([$row], (int) $thread->id);
        Queue::fake();

        (new SendChatEmail((int) $conversation->id, (int) $thread->id))->failed(null);

        $this->assertSame(Message::EMAIL_FAILED, $this->emailStatus($row));
        $items = $this->failureItems($conversation);
        $this->assertCount(1, $items);
        $this->assertSame('Email to the visitor failed: sending failed', $items[0]->getActionText('', false));
    }

    /** Sending rows are the stale check's: only core's send status can tell whether they went out. */
    public function test_failed_hook_leaves_a_sending_batch_alone()
    {
        list(, , $conversation) = $this->chat();
        list($thread, $row) = $this->reply($conversation);
        $this->sending([$row], (int) $thread->id, 30);
        Queue::fake();

        (new SendChatEmail((int) $conversation->id, (int) $thread->id))->failed(new \Exception('Timed out'));

        $this->assertSame(Message::EMAIL_SENDING, $this->emailStatus($row));
        $this->assertCount(0, $this->failureItems($conversation));
    }

    public function test_command_is_scheduled()
    {
        $this->assertArrayHasKey('wordpresschat:send-due-emails', \Artisan::all());

        // A fresh schedule: the container's was already filtered once when
        // the console kernel booted in this process.
        $schedule = \Eventy::filter('schedule', new Schedule());

        $events = array_values(array_filter($schedule->events(), function ($event) {
            return substr((string) $event->command, -strlen('wordpresschat:send-due-emails')) === 'wordpresschat:send-due-emails';
        }));

        $this->assertCount(1, $events);
        // Every minute. Laravel 5.5's expressions have six fields (the
        // sixth is the year), so everyMinute() gives six stars.
        $this->assertSame('* * * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        // A sweep killed mid-run must not stop fallback emails for a day.
        $this->assertSame(5, $events[0]->expiresAt);
    }

    public static function failureReasons(): array
    {
        return [
            'login 535'     => ['Expected response code 250 but got code "535", with message "535 Authentication failed"', 'the mail server refused the login (SMTP 535)'],
            'login 534'     => ['Expected response code 250 but got code "534", with message "x"', 'the mail server refused the login (SMTP 534)'],
            'login 530'     => ['Expected response code 250 but got code "530", with message "x"', 'the mail server refused the login (SMTP 530)'],
            'rejected 550'  => ['Expected response code 250 but got code "550", with message "550 No such user"', "the visitor's mail server rejected the address (SMTP 550)"],
            'rejected 554'  => ['Expected response code 250 but got code "554", with message "x"', "the visitor's mail server rejected the address (SMTP 554)"],
            'transient 421' => ['Expected response code 250 but got code "421", with message "x"', 'the mail server is not accepting email right now (SMTP 421)'],
            'transient 452' => ['Expected response code 250 but got code "452", with message "x"', 'the mail server is not accepting email right now (SMTP 452)'],
            'unreachable'   => ['Connection could not be established with host smtp.example.test [Connection refused #111]', 'the mail server could not be reached'],
            'other code'    => ['Expected response code 250 but got code "500", with message "x"', 'Expected response code 250 but got code "500", with message "x"'],
            'anything else' => ['Something odd happened', 'Something odd happened'],
        ];
    }

    /**
     * @dataProvider failureReasons
     */
    public function test_failure_reason_is_readable(string $raw, string $reason)
    {
        $this->assertSame($reason, SendChatEmail::friendly($raw));

        list(, , $conversation) = $this->chat();
        $this->reply($conversation);
        Queue::fake();
        $this->assertSame(1, DueEmails::sweep());
        $this->fake($raw);
        \Log::spy();

        $this->runJob($this->pushedJob());

        $items = $this->failureItems($conversation);
        $this->assertCount(1, $items);
        $this->assertSame('Email to the visitor failed: '.$reason, $items[0]->getActionText('', false));

        if ($reason !== $raw) {
            \Log::shouldHaveReceived('error')->withArgs(function ($message) use ($raw) {
                return strpos($message, $raw) !== false;
            })->once();
        }
    }

    public function test_long_unmatched_reason_is_cut()
    {
        $this->assertSame(str_repeat('x', 200), SendChatEmail::friendly(str_repeat('x', 500)));
    }

    public function test_failure_reason_is_translated()
    {
        $locale = app()->getLocale();
        try {
            \Helper::setLocale('de');
            $this->assertSame('der Mailserver hat die Anmeldung abgelehnt (SMTP 535)', SendChatEmail::friendly('but got code "535"'));
        } finally {
            app()->setLocale($locale);
        }
    }

    /** An anonymous chat is excluded by the sweep's own query, with no customer or email lookup. */
    public function test_anonymous_conversation_is_not_reloaded_each_minute()
    {
        list(, , $conversation) = $this->chat('');
        $this->reply($conversation);
        Queue::fake();

        $lookups = [];
        \DB::listen(function ($query) use (&$lookups) {
            if (preg_match('/^select [^()]*? from [`"]?(customers|emails)[`"]?[\s]/i', $query->sql)) {
                $lookups[] = $query->sql;
            }
        });

        $this->assertSame(0, DueEmails::sweep());

        $this->assertSame([], $lookups);
        Queue::assertNotPushed(SendChatEmail::class);
    }

    /** A customer with an email row but an empty conversation address still gets the email. */
    public function test_customer_email_row_qualifies_an_empty_conversation_address()
    {
        list(, , $conversation) = $this->chat('');
        $this->reply($conversation);
        \App\Email::create('anna@example.test', $conversation->customer_id);
        Queue::fake();

        $this->assertSame(1, DueEmails::sweep());
    }

    public function test_sweep_skips_unpublished_threads()
    {
        list(, , $conversation) = $this->chat();
        list($thread, $row) = $this->reply($conversation);
        Thread::where('id', $thread->id)->update(['state' => Thread::STATE_DRAFT]);
        Queue::fake();

        $this->assertSame(0, DueEmails::sweep());

        $this->assertNull($this->emailStatus($row));
        Queue::assertNotPushed(SendChatEmail::class);
    }
}
