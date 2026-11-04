<?php

namespace Modules\WordPressChat\Jobs;

use App\Conversation;
use App\SendLog;
use App\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Services\ChatEmail;
use Modules\WordPressChat\Services\ContinueLink;
use Modules\WordPressChat\Services\CoreReplyMailer;
use Modules\WordPressChat\Services\DueEmails;
use Modules\WordPressChat\Services\FailureLineItem;

/**
 * Sends one batch of replies as one email: the rows whose email_thread_id
 * is $threadId (the sweep's fallback batch, or one agent's-choice reply).
 *
 * Send-once (Ruling D2):
 * 1. queued rows that can no longer go are dropped back to null: the
 *    thread is gone or not published (an undone email choice), or a chat
 *    reply was seen meanwhile;
 * 2. the claim (queued -> sending) is the point of no return. Only one
 *    job can claim a row, so a second job for the batch, or a retry that
 *    finds the rows sent, sends nothing; a "seen" after it no longer
 *    cancels;
 * 3. the outcome moves the claimed rows on: sent, failed (with one red
 *    line item), or back to queued for a retry.
 *
 * Core's mail job runs inline and its retries are ours (Ruling D6): a
 * throw is retried after BACKOFF while attempts() < TRIES, then the batch
 * fails. A retry after core already handed the email to the transport
 * sends nothing: core returns early for a thread whose send_status says
 * accepted, and CoreReplyMailer reads that as sent.
 */
class SendChatEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const TRIES = 5;

    /** Seconds before attempt 2, 3, 4 and 5. */
    const BACKOFF = [300, 900, 3600, 3600];

    /** Characters of a failure reason shown in the line item. */
    const REASON_LENGTH = 200;

    public $conversationId;

    /** The batch key. */
    public $threadId;

    public $tries = self::TRIES;

    public $timeout = 120;

    public function __construct(int $conversationId, int $threadId)
    {
        $this->conversationId = $conversationId;
        $this->threadId = $threadId;
    }

    public function handle(CoreReplyMailer $mailer): void
    {
        $this->drop();

        $ids = $this->claim();

        if (!$ids) {
            return;
        }

        // Anything that throws from here on leaves the rows claimed, so it
        // is handled as a failed attempt, never left in "sending".
        $conversation = null;
        $newest = null;

        try {
            // An email choice reaches the (verified) chat once it is sent.
            Message::whereIn('id', $ids)
                ->where('via', Message::VIA_EMAIL)
                ->whereNull('published_at')
                ->update(['published_at' => now()]);

            $threadIds = array_map('intval', Message::whereIn('id', $ids)->pluck('thread_id')->all());
            sort($threadIds);

            $newest = Thread::getLastThread(Thread::whereIn('id', $threadIds)->get());
            $conversation = $newest ? Conversation::find($newest->conversation_id) : null;

            $reason = $conversation
                ? $this->send($mailer, $conversation, $newest, $threadIds)
                : __('not sent');
        } catch (\Throwable $e) {
            // Core accepted the email and threw afterwards (its send log,
            // say). A retry would not be stopped by core's own "accepted"
            // check if a reply seen meanwhile changed the newest thread.
            if ($newest && self::accepted($newest)) {
                \Log::error('[WordPressChat] Fallback email for conversation #'.$this->conversationId.' was accepted, then core threw', [
                    'exception' => $e,
                ]);
                $this->markSent($ids);

                return;
            }

            $this->retryOrFail($ids, $conversation, $e);

            return;
        }

        if ($reason === null) {
            $this->markSent($ids);

            return;
        }

        $this->failBatch($ids, $conversation, $reason);
    }

    /** Whether core recorded the email of $newest (its last_thread) as accepted. */
    private static function accepted(Thread $newest): bool
    {
        $thread = Thread::find($newest->id);

        return $thread
            && ($thread->send_status == SendLog::STATUS_ACCEPTED || $thread->isSendStatusSuccess());
    }

    private function markSent(array $ids): void
    {
        Message::whereIn('id', $ids)
            ->where('email_status', Message::EMAIL_SENDING)
            ->update(['email_status' => Message::EMAIL_SENT]);
    }

    /**
     * Step 1: queued rows that can no longer be sent leave the batch.
     * Conditional on still being queued, so a row another job claimed
     * meanwhile is never touched.
     */
    private function drop(): void
    {
        $queued = Message::where('email_thread_id', $this->threadId)
            ->where('email_status', Message::EMAIL_QUEUED)
            ->get(['id', 'thread_id', 'via', 'seen_at']);

        if ($queued->isEmpty()) {
            return;
        }

        $published = array_map('intval', Thread::whereIn('id', $queued->pluck('thread_id')->all())
            ->where('state', Thread::STATE_PUBLISHED)
            ->pluck('id')
            ->all());

        $drop = $queued->filter(function ($row) use ($published) {
            return !in_array((int) $row->thread_id, $published, true)
                || ((int) $row->via === Message::VIA_CHAT && $row->seen_at !== null);
        })->pluck('id')->all();

        if ($drop) {
            Message::whereIn('id', $drop)
                ->where('email_status', Message::EMAIL_QUEUED)
                ->update(['email_status' => null, 'email_thread_id' => null]);
        }
    }

    /**
     * Step 2: queued -> sending. Returns the ids this job claimed, or []
     * when another job (or an earlier run of this one) already has them.
     * The rows are read under a lock, so the ids returned are exactly the
     * rows this update moved.
     */
    private function claim(): array
    {
        return DB::transaction(function () {
            $ids = array_map('intval', Message::where('email_thread_id', $this->threadId)
                ->where('email_status', Message::EMAIL_QUEUED)
                ->lockForUpdate()
                ->pluck('id')
                ->all());

            if (!$ids) {
                return [];
            }

            $changed = Message::whereIn('id', $ids)
                ->where('email_status', Message::EMAIL_QUEUED)
                ->update(['email_status' => Message::EMAIL_SENDING]);

            return $changed ? $ids : [];
        });
    }

    /**
     * Steps 4 to 7: address, continue link, core's send. Null when sent,
     * otherwise the reason it was not.
     */
    private function send(CoreReplyMailer $mailer, Conversation $conversation, Thread $newest, array $threadIds): ?string
    {
        $address = DueEmails::address($conversation);
        $customer = $conversation->customer;

        if ($address === null || !$customer) {
            return __('no usable email address');
        }

        // Core sends to customer_email (Kapso F2): a chat conversation's
        // column may still be empty while its customer has an email.
        if (!DueEmails::valid((string) $conversation->customer_email)) {
            $conversation->customer_email = $address;
            $conversation->save();
        }

        try {
            $link = ContinueLink::mint($conversation);
        } catch (\RuntimeException $e) {
            \Log::error('[WordPressChat] Fallback email for conversation #'.$conversation->id.' goes without a continue link', [
                'exception' => $e,
            ]);
            $link = null;
        }

        ChatEmail::begin((int) $conversation->id, $threadIds, $link);

        try {
            return $mailer->send($conversation, $this->replies($conversation, $newest), $customer);
        } finally {
            ChatEmail::end();
        }
    }

    /**
     * The conversation's published customer and agent threads, newest
     * first, without those newer than the newest reply in the batch: as
     * core's SendReplyToCustomer listener builds them.
     */
    private function replies(Conversation $conversation, Thread $newest)
    {
        $replies = Thread::sortThreads(
            $conversation->getThreads(null, null, [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
        )->values();

        foreach ($replies as $i => $reply) {
            if ((int) $reply->id === (int) $newest->id) {
                break;
            }
            $replies->forget($i);
        }

        return $replies->values();
    }

    private function retryOrFail(array $ids, ?Conversation $conversation, \Throwable $e): void
    {
        $attempt = max(1, (int) $this->attempts());

        if ($attempt < self::TRIES) {
            Message::whereIn('id', $ids)
                ->where('email_status', Message::EMAIL_SENDING)
                ->update(['email_status' => Message::EMAIL_QUEUED]);

            $this->release(self::BACKOFF[min($attempt, count(self::BACKOFF)) - 1]);

            return;
        }

        \Log::error('[WordPressChat] Fallback email for conversation #'.$this->conversationId.' failed after '.$attempt.' attempts', [
            'exception' => $e,
        ]);

        $message = trim($e->getMessage());
        $this->failBatch($ids, $conversation, $message !== '' ? $message : get_class($e));
    }

    /**
     * sending -> failed, and one red line item from whichever job moved
     * the rows.
     */
    private function failBatch(array $ids, ?Conversation $conversation, string $reason): void
    {
        $changed = Message::whereIn('id', $ids)
            ->where('email_status', Message::EMAIL_SENDING)
            ->update(['email_status' => Message::EMAIL_FAILED]);

        if (!$changed) {
            return;
        }

        $conversation = $conversation ?: Conversation::find($this->conversationId);

        if (!$conversation) {
            return;
        }

        FailureLineItem::create($conversation, __('Email to the visitor failed: :reason', [
            'reason' => mb_substr($reason, 0, self::REASON_LENGTH),
        ]));
    }
}
