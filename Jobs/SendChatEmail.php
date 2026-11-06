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
use Modules\WordPressChat\Listeners\MergeChat;
use Modules\WordPressChat\Services\ChatEmail;
use Modules\WordPressChat\Services\ContinueLink;
use Modules\WordPressChat\Services\CoreReplyMailer;
use Modules\WordPressChat\Services\ReplyChannel;
use Modules\WordPressChat\Services\DueEmails;
use Modules\WordPressChat\Services\FailureLineItem;
use Modules\WordPressChat\Services\Visibility;
use Modules\WordPressChat\WordPressChat;

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
 *
 * A merge (Ruling P5): core moves the threads before it fires
 * `conversation.merged`, where MergeChat fails queued rows, and MergeChat
 * leaves "sending" rows to the job. A job that claims in that window, or
 * reads the conversation after the move, finds the replies in the merge
 * target. It emails them there only when one browser has a full or uncut grant
 * on both chats (Visibility::oneVisitor());
 * otherwise the batch fails, with MergeChat's red item on the target.
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

            if ($conversation && self::mergedIntoAnotherVisitor($ids, $conversation)) {
                self::failMerged($ids, $conversation);

                return;
            }

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
        $leftOut = false;

        $ids = DB::transaction(function () use (&$leftOut) {
            // Every queued row, read under the lock. A chat row seen since
            // drop() ran is left out (released below); an email-choice row
            // is sent regardless.
            $rows = Message::where('email_thread_id', $this->threadId)
                ->where('email_status', Message::EMAIL_QUEUED)
                ->lockForUpdate()
                ->get(['id', 'via', 'seen_at']);

            $ids = [];

            foreach ($rows as $row) {
                if ((int) $row->via === Message::VIA_EMAIL || $row->seen_at === null) {
                    $ids[] = (int) $row->id;
                } else {
                    $leftOut = true;
                }
            }

            if (!$ids) {
                return [];
            }

            $changed = Message::whereIn('id', $ids)
                ->where('email_status', Message::EMAIL_QUEUED)
                ->update(['email_status' => Message::EMAIL_SENDING]);

            return $changed ? $ids : [];
        });

        // Rows the split left out are still queued: release them.
        if ($leftOut) {
            self::drop($this->threadId);
        }

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
            $reason = $mailer->send($conversation, $this->replies($conversation, $newest), $customer);
        } finally {
            ChatEmail::end();
        }

        if ($reason === null) {
            self::logAccepted($newest, $conversation, $customer, $address);
        }

        return $reason;
    }

    /**
     * Core's send log lists the thread's To, Cc and Bcc addresses, and a
     * chat reply has no To (Ruling P6): without a row here the agent UI
     * shows no delivery status. The thread's To stays empty. Never throws:
     * SendLog::log() catches its own save errors.
     */
    private static function logAccepted(Thread $newest, Conversation $conversation, $customer, string $address): void
    {
        if ($newest->getToArray() || !$conversation->mailbox) {
            return;
        }

        SendLog::log(
            $newest->id,
            $newest->getMessageId($conversation->mailbox),
            $address,
            SendLog::MAIL_TYPE_EMAIL_TO_CUSTOMER,
            SendLog::STATUS_ACCEPTED,
            $customer->id
        );
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
            $delay = self::BACKOFF[min($attempt, count(self::BACKOFF)) - 1];

            \Log::warning('[WordPressChat] Fallback email for conversation #'.$this->conversationId
                .': fallback email attempt '.$attempt.' of '.self::TRIES.' failed, retrying in '.$delay.' s: '.$e->getMessage());

            Message::whereIn('id', $ids)
                ->where('email_status', Message::EMAIL_SENDING)
                ->update(['email_status' => Message::EMAIL_QUEUED]);

            $this->release($delay);

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
            $message !== '' ? $message : get_class($e),
            true
        );
    }

    /**
     * $from -> failed, and one red line item from whichever caller moved
     * the rows (this job, its failed() hook, or the stale check), so a
     * batch never gets two. $conversationId is the fallback when the
     * batch's conversation is not known. Returns the number of rows moved.
     *
     * The log keeps the full raw error whenever the agent sees something
     * else (a mapped reason or a cut one). $logged is true when the caller
     * already logged this failure (retryOrFail()'s final-attempt line).
     */
    public static function failBatch(array $ids, int $from, ?Conversation $conversation, int $conversationId, string $reason, bool $logged = false): int
    {
        $changed = self::moveToFailed($ids, $from);

        if (!$changed) {
            return 0;
        }

        $conversation = $conversation ?: Conversation::find($conversationId);
        $friendly = self::friendly($reason);

        if (!$logged && $friendly !== $reason) {
            // The agent sees the short reason; the log keeps the full error.
            \Log::error('[WordPressChat] Fallback email for conversation #'.$conversationId.' failed: '.$reason);
        }

        if ($conversation) {
            FailureLineItem::create($conversation, __('Email to the visitor failed: :reason', [
                'reason' => $friendly,
            ]));
        }

        return $changed;
    }

    /**
     * The reason an agent reads for a raw failure message: the SMTP code or
     * the connection failure in plain words, otherwise the message itself,
     * cut to REASON_LENGTH characters. Every failure path goes through
     * failBatch(), which applies it.
     */
    public static function friendly(string $raw): string
    {
        if (preg_match('/but got code "(\d{3})"/', $raw, $m)) {
            $code = $m[1];

            if (in_array($code, ['535', '534', '530'], true)) {
                return __('the mail server refused the login (SMTP :code)', ['code' => $code]);
            }

            if ($code >= '550' && $code <= '554') {
                return __('the mail server rejected the email (SMTP :code)', ['code' => $code]);
            }

            if ($code[0] === '4') {
                return __('the mail server is not accepting email right now (SMTP :code)', ['code' => $code]);
            }
        }

        if (strpos($raw, 'Connection could not be established') !== false) {
            return __('the mail server could not be reached');
        }

        return mb_substr($raw, 0, self::REASON_LENGTH);
    }

    /**
     * Whether some row of $ids belongs to a chat that was merged into
     * $current, the thread's conversation now, and no browser has a full or
     * uncut grant on both (Ruling P5, R7). A target that is no website chat never gets them, as in
     * PublishReplyToChat.
     */
    private static function mergedIntoAnotherVisitor(array $ids, Conversation $current): bool
    {
        $sources = Message::whereIn('id', $ids)->pluck('conversation_id')->unique();

        foreach ($sources as $sourceId) {
            if ((int) $sourceId === (int) $current->id) {
                continue;
            }

            if (!WordPressChat::isWebsiteChat($current)
                || !Visibility::oneVisitor((int) $sourceId, (int) $current->id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * sending -> failed without an email, and $target gets the red item
     * MergeChat writes for the rows a merge fails.
     */
    private static function failMerged(array $ids, Conversation $target): void
    {
        $changed = self::moveToFailed($ids, Message::EMAIL_SENDING);

        if ($changed) {
            FailureLineItem::create($target, MergeChat::failureText($changed));
        }
    }

    /** $from -> failed, conditional on still being in $from. Returns the number of rows moved. */
    private static function moveToFailed(array $ids, int $from): int
    {
        return Message::whereIn('id', $ids)
            ->where('email_status', $from)
            ->update(['email_status' => Message::EMAIL_FAILED]);
    }
}
