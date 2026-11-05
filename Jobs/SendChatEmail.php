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
use Modules\WordPressChat\Services\ReplyChannel;
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
 *
 * A dead worker: rows still queued are failed by failed(), or by
 * DueEmails::resolveStale() once they sat untouched for STALE_QUEUED (the
 * job itself was lost); rows left in "sending" are resolved by
 * resolveStale() from core's send status, never sent again. The
 * batch-outcome helpers below are shared with both, so every path moves
 * rows with the same conditional update and a batch gets at most one red
 * line item. A job that runs after its rows failed claims nothing.
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
        self::drop($this->threadId);

        $ids = $this->claim();

        if (!$ids) {
            return;
        }

        // Anything that throws from here on leaves the rows claimed, so it
        // is handled as a failed attempt, never left in "sending".
        $conversation = null;
        $newest = null;

        try {
            $threadIds = self::threadIdsOf($ids);
            $newest = self::newestThread($threadIds);
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
                self::markSent($ids);

                return;
            }

            $this->retryOrFail($ids, $conversation, $e);

            return;
        }

        if ($reason === null) {
            self::markSent($ids);

            return;
        }

        self::failBatch($ids, Message::EMAIL_SENDING, $conversation, $this->conversationId, $reason);
    }

    /**
     * Laravel calls this once the worker gives up on the job: its tries
     * used up (a throw before the claim, a timeout) or a manual fail().
     * $e is an Exception, or null. No type hint: PHP 7.1, and Laravel 5.5
     * passes no Throwable for every path.
     *
     * Rows still queued would never be sent by anyone: they fail, with one
     * red line item, after dropping those that need no email. Rows in
     * "sending" are left to DueEmails::resolveStale(), which can tell from
     * core's send status whether the email went out.
     */
    public function failed($e = null): void
    {
        self::drop($this->threadId);

        $ids = array_map('intval', Message::where('email_thread_id', $this->threadId)
            ->where('email_status', Message::EMAIL_QUEUED)
            ->pluck('id')
            ->all());

        if (!$ids) {
            return;
        }

        $newest = self::newestThread(self::threadIdsOf($ids));
        $conversation = $newest ? Conversation::find($newest->conversation_id) : null;
        $message = $e instanceof \Throwable ? trim($e->getMessage()) : '';

        self::failBatch(
            $ids,
            Message::EMAIL_QUEUED,
            $conversation,
            $this->conversationId,
            $message !== '' ? $message : __('sending failed')
        );
    }

    /** The thread ids of the rows $ids, ascending. */
    public static function threadIdsOf(array $ids): array
    {
        $threadIds = array_map('intval', Message::whereIn('id', $ids)->pluck('thread_id')->all());
        sort($threadIds);

        return $threadIds;
    }

    /**
     * The batch's newest thread, as core picks its last_thread (created_at,
     * then id, descending). Null when none of the threads exists.
     */
    public static function newestThread(array $threadIds): ?Thread
    {
        if (!$threadIds) {
            return null;
        }

        return Thread::getLastThread(Thread::whereIn('id', $threadIds)->get());
    }

    /** Whether core recorded the email of $newest (its last_thread) as accepted. */
    public static function accepted(Thread $newest): bool
    {
        $thread = Thread::find($newest->id);

        return $thread
            && ($thread->send_status == SendLog::STATUS_ACCEPTED || $thread->isSendStatusSuccess());
    }

    /**
     * sending -> sent. Returns the number of rows moved.
     *
     * An email choice reaches the (verified) chat only now, once its email
     * went out (spec §6.4 rule 7): its published_at is set in the same
     * statement that marks it sent, so it is never visible as sent before
     * that, nor after a failure. Rows that already have one keep it.
     */
    public static function markSent(array $ids): int
    {
        $published = Message::whereIn('id', $ids)
            ->where('email_status', Message::EMAIL_SENDING)
            ->where('via', Message::VIA_EMAIL)
            ->whereNull('published_at')
            ->update(['email_status' => Message::EMAIL_SENT, 'published_at' => now()]);

        return $published + Message::whereIn('id', $ids)
            ->where('email_status', Message::EMAIL_SENDING)
            ->update(['email_status' => Message::EMAIL_SENT]);
    }

    /**
     * Step 1: queued rows of the batch $threadId that can no longer be sent
     * leave it, back to null. Conditional on still being queued, so a row
     * another job claimed meanwhile is never touched. Also run before a
     * queued batch is failed (failed(), DueEmails::resolveStale()), so a
     * reply that needs no email is never reported as a failed one.
     */
    public static function drop(int $threadId): void
    {
        $queued = Message::where('email_thread_id', $threadId)
            ->where('email_status', Message::EMAIL_QUEUED)
            ->get(['id', 'thread_id', 'via', 'seen_at']);

        if ($queued->isEmpty()) {
            return;
        }

        // Read as they are now: an undone email choice re-sent as chat has
        // its meta cleared, and must not also go out by email.
        $threads = Thread::whereIn('id', $queued->pluck('thread_id')->all())
            ->where('state', Thread::STATE_PUBLISHED)
            ->get()
            ->keyBy('id');

        $drop = $queued->filter(function ($row) use ($threads) {
            $thread = $threads->get((int) $row->thread_id);

            if (!$thread) {
                return true;
            }

            if ((int) $row->via === Message::VIA_EMAIL) {
                return $thread->getMeta(ReplyChannel::META) !== ReplyChannel::EMAIL;
            }

            return $row->seen_at !== null;
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
        $ids = DB::transaction(function () {
            // A chat row seen since drop() ran is left out (it goes back
            // through drop() below); an email-choice row is sent regardless.
            $ids = array_map('intval', Message::where('email_thread_id', $this->threadId)
                ->where('email_status', Message::EMAIL_QUEUED)
                ->where(function ($q) {
                    $q->where('via', Message::VIA_EMAIL)->orWhereNull('seen_at');
                })
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

        // Rows the locked read left out are still queued: release them.
        self::drop($this->threadId);

        return $ids;
    }

    /**
     * Steps 4 to 7: address, continue link, core's send. Null when sent,
     * otherwise the reason it was not. No link when the email also goes to
     * anyone else (Ruling D5).
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

        $link = null;

        if (self::extraRecipients($conversation, $newest, $address)) {
            // Ruling D5: a link only ever goes to the conversation's own
            // address. The email still goes, to everyone core sends it to.
            \Log::info('[WordPressChat] Email for conversation #'.$conversation->id.' goes without a continue link: it also goes to other recipients (To, Cc, Bcc or the mailbox\'s auto Bcc)');
        } else {
            try {
                $link = ContinueLink::mint($conversation);
            } catch (\RuntimeException $e) {
                \Log::error('[WordPressChat] Fallback email for conversation #'.$conversation->id.' goes without a continue link', [
                    'exception' => $e,
                ]);
            }
        }

        ChatEmail::begin((int) $conversation->id, $threadIds, $link);

        try {
            return $mailer->send($conversation, $this->replies($conversation, $newest), $customer);
        } finally {
            ChatEmail::end();
        }
    }

    /**
     * Whether core's job would send this email to anyone besides $address,
     * computed as core's SendReplyToCustomer::handle() builds its recipients
     * from $newest (its last_thread) and the mailbox:
     * - To: the thread's To list when it holds more than one address;
     * - Cc and Bcc: the thread's lists without the mailbox's own addresses
     *   and without the customer's address;
     * - the mailbox's auto Bcc.
     */
    private static function extraRecipients(Conversation $conversation, Thread $newest, string $address): bool
    {
        $mailbox = $conversation->mailbox;

        if (!$mailbox) {
            return false;
        }

        $to = $mailbox->removeMailboxEmailsFromList($newest->getToArray());
        $cc = \App\Misc\Mail::removeEmailFromArray($mailbox->removeMailboxEmailsFromList($newest->getCcArray()), $address);
        $bcc = \App\Misc\Mail::removeEmailFromArray($mailbox->removeMailboxEmailsFromList($newest->getBccArray()), $address);
        $autoBcc = $mailbox->auto_bcc ? \MailHelper::sanitizeEmails($mailbox->auto_bcc) : [];
        $extraTo = count($to) > 1 ? \App\Misc\Mail::removeEmailFromArray($to, $address) : [];

        return (bool) ($extraTo || $cc || $bcc || $autoBcc);
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
        self::failBatch(
            $ids,
            Message::EMAIL_SENDING,
            $conversation,
            $this->conversationId,
            $message !== '' ? $message : get_class($e)
        );
    }

    /**
     * $from -> failed, and one red line item from whichever caller moved
     * the rows (this job, its failed() hook, or the stale check), so a
     * batch never gets two. $conversationId is the fallback when the
     * batch's conversation is not known. Returns the number of rows moved.
     */
    public static function failBatch(array $ids, int $from, ?Conversation $conversation, int $conversationId, string $reason): int
    {
        $changed = Message::whereIn('id', $ids)
            ->where('email_status', $from)
            ->update(['email_status' => Message::EMAIL_FAILED]);

        if (!$changed) {
            return 0;
        }

        $conversation = $conversation ?: Conversation::find($conversationId);

        if ($conversation) {
            FailureLineItem::create($conversation, __('Email to the visitor failed: :reason', [
                'reason' => mb_substr($reason, 0, self::REASON_LENGTH),
            ]));
        }

        return $changed;
    }
}
