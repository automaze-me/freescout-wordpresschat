<?php

namespace Modules\WordPressChat\Services;

use App\Conversation;
use App\Thread;
use Carbon\Carbon;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\WordPressChat;
use Modules\WordPressChat\Jobs\SendChatEmail;

/**
 * The fallback timer (Ruling D1): a one-minute sweep that hands unseen chat
 * replies whose delay has passed to SendChatEmail, one job per conversation.
 *
 * The sweep's "queued" mark only stops a second dispatch (Ruling D2). The
 * job decides what is still sent, and its claim is the point of no return.
 */
class DueEmails
{
    /** Due rows older than this are ignored. */
    const WINDOW_DAYS = 7;

    /** Seconds after a reply's creation before a missing row means it is lost (Ruling D8). */
    const LOST_GRACE = 3600;

    /**
     * Seconds after which a batch still in "sending" belongs to a dead
     * worker. Far above the job's 120 s timeout, so no live job is cut.
     */
    const STALE_SENDING = 600;

    /**
     * Seconds after which a batch still "queued" has lost its job (a
     * flushed or rebuilt queue, a worker that died before the claim and
     * never reached failed()). Far above the longest wait of a live job:
     * the undo delay of an email choice, and the 3600 s backoff before a
     * retry (SendChatEmail::BACKOFF).
     */
    const STALE_QUEUED = 10800;

    /** Seconds a runnable job may wait before the queue counts as backed up (markLost()). */
    const BACKLOG_AGE = 600;

    /** Most lost replies marked per markLost() run, oldest first. */
    const MARK_BATCH = 200;

    /**
     * Where the conversation's email goes: its customer_email when that is
     * a valid address, otherwise the customer's main email when that is,
     * otherwise null.
     *
     * The main email is read like Customer::getMainEmail() (the first email
     * row) but without its query cache: that caches the customer's email
     * list for the life of the process, and the job runs in a long-lived
     * queue worker while a visitor may give an email at any time.
     */
    public static function address(Conversation $conversation): ?string
    {
        $email = (string) $conversation->customer_email;

        if (self::valid($email)) {
            return $email;
        }

        $customer = $conversation->customer;

        if ($customer) {
            $main = (string) $customer->emails()->orderBy('id')->value('email');

            if (self::valid($main)) {
                return $main;
            }
        }

        return null;
    }

    public static function valid(string $email): bool
    {
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Queues every due batch and returns the number of batches dispatched.
     *
     * Two sweeps (or a sweep racing a "seen") cannot dispatch the same row
     * twice: the mark is a conditional update, and only rows it changed are
     * in the batch. A batch is keyed by the highest thread id among its
     * rows; the job claims exactly the rows carrying that key.
     *
     * $now is for tests only: Carbon::setTestNow() breaks now() itself in
     * this app (core's Carbon override).
     */
    public static function sweep(?Carbon $now = null): int
    {
        $now = $now ? $now->copy() : now();

        self::resolveStale($now);

        // Rows whose thread is gone or no longer published could never be
        // sent; without the join the job would drop them and the next
        // sweep would queue them again, every minute for the whole window.
        $rows = Message::query()
            ->join('threads', 'threads.id', '=', 'wordpresschat_messages.thread_id')
            ->join('conversations', 'conversations.id', '=', 'wordpresschat_messages.conversation_id')
            ->where('wordpresschat_messages.direction', Message::DIRECTION_OUT)
            ->where('wordpresschat_messages.via', Message::VIA_CHAT)
            ->whereNotNull('wordpresschat_messages.published_at')
            ->whereNull('wordpresschat_messages.seen_at')
            ->whereNull('wordpresschat_messages.email_status')
            ->whereNull('wordpresschat_messages.lost_at')
            ->whereBetween('wordpresschat_messages.fallback_due_at', [$now->copy()->subDays(self::WINDOW_DAYS), $now])
            ->where('threads.state', Thread::STATE_PUBLISHED)
            // A coarse address filter, so an anonymous chat is not loaded
            // every minute; address() stays the final gate.
            ->where(function ($q) {
                $q->where('conversations.customer_email', 'like', '%@%')
                    ->orWhereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('emails')
                            ->whereColumn('emails.customer_id', 'conversations.customer_id');
                    });
            })
            ->orderBy('wordpresschat_messages.id')
            ->get([
                'wordpresschat_messages.id',
                'wordpresschat_messages.conversation_id',
                'wordpresschat_messages.thread_id',
            ]);

        if ($rows->isEmpty()) {
            return 0;
        }

        $conversations = Conversation::whereIn('id', $rows->pluck('conversation_id')->unique()->values()->all())
            ->get()
            ->keyBy('id');

        $dispatched = 0;

        foreach ($rows->groupBy('conversation_id') as $conversationId => $group) {
            $conversation = $conversations->get($conversationId);

            // An anonymous conversation waits: its replies go out once the
            // visitor gives an email (within the window).
            if (!$conversation
                || (int) $conversation->state !== Conversation::STATE_PUBLISHED
                || self::address($conversation) === null
            ) {
                continue;
            }

            $ids = array_map('intval', $group->pluck('id')->all());
            $threadId = (int) $group->max('thread_id');

            $changed = Message::whereIn('id', $ids)
                ->whereNull('email_status')
                ->whereNull('seen_at')
                ->update([
                    'email_status'    => Message::EMAIL_QUEUED,
                    'email_thread_id' => $threadId,
                ]);

            if (!$changed) {
                continue;
            }

            try {
                app(Dispatcher::class)->dispatch(
                    (new SendChatEmail((int) $conversationId, $threadId))->onQueue('emails')
                );
            } catch (\Throwable $e) {
                // Unmarked, the next sweep tries again; marked, no job
                // would ever send these rows.
                Message::whereIn('id', $ids)
                    ->where('email_status', Message::EMAIL_QUEUED)
                    ->where('email_thread_id', $threadId)
                    ->update(['email_status' => null, 'email_thread_id' => null]);

                \Log::error('[WordPressChat] Could not queue the fallback email for conversation #'.$conversationId, [
                    'exception' => $e,
                ]);

                continue;
            }

            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Batches whose worker or job is gone. Returns the number of batches
     * resolved.
     *
     * "sending" for STALE_SENDING: a dead worker (the job's timeout, an
     * out-of-memory fatal in core's IMAP copy, a restart). Core's send
     * status on the batch's newest thread, its last_thread, tells whether
     * the email went out: then the rows are sent; otherwise the batch
     * fails with one red line item. Never queued again: a kill after the
     * transport handoff would send the email twice.
     *
     * "queued" for STALE_QUEUED: the job was lost before its claim. As in
     * the job's failed() hook, rows that need no email leave the batch
     * first; the rest fail with one red line item. A late job then claims
     * nothing, so the email is never sent after the agent was told it
     * failed.
     *
     * The claim and every retry stamp updated_at (Eloquent's update()
     * does). A batch is handled once every row of it is stale: a "seen"
     * stamps a row too, and handling part of a batch would give it two
     * line items.
     */
    public static function resolveStale(?Carbon $now = null): int
    {
        $now = $now ? $now->copy() : now();

        return self::resolveSending($now->copy()->subSeconds(self::STALE_SENDING))
            + self::resolveQueued($now);
    }

    private static function resolveSending(Carbon $cutoff): int
    {
        $resolved = 0;

        foreach (self::staleBatches(Message::EMAIL_SENDING, $cutoff) as $rows) {
            $ids = array_map('intval', $rows->pluck('id')->all());
            $newest = SendChatEmail::newestThread(SendChatEmail::threadIdsOf($ids));

            if ($newest && SendChatEmail::accepted($newest)) {
                if (SendChatEmail::markSent($ids)) {
                    $resolved++;
                }

                continue;
            }

            $conversation = $newest ? Conversation::find($newest->conversation_id) : null;

            if (SendChatEmail::failBatch(
                $ids,
                Message::EMAIL_SENDING,
                $conversation,
                (int) $rows->first()->conversation_id,
                __('sending was interrupted')
            )) {
                $resolved++;
            }
        }

        return $resolved;
    }

    /**
     * Queued batches whose job is gone. While the queue is backed up on any
     * queue the worker consumes, `emails` included, a slow job is no lost
     * one: nothing is failed.
     */
    private static function resolveQueued(Carbon $now): int
    {
        if (self::backlog($now, true)) {
            return 0;
        }

        $cutoff = $now->copy()->subSeconds(self::STALE_QUEUED);
        $resolved = 0;

        foreach (self::staleBatches(Message::EMAIL_QUEUED, $cutoff) as $key => $rows) {
            SendChatEmail::drop($key);

            // Still queued and still stale: a row re-queued since the scan
            // (a retry stamps updated_at) belongs to a live job.
            $ids = array_map('intval', Message::whereIn('id', $rows->pluck('id')->all())
                ->where('email_status', Message::EMAIL_QUEUED)
                ->where('updated_at', '<', $cutoff)
                ->pluck('id')
                ->all());

            if (!$ids) {
                continue;
            }

            $newest = SendChatEmail::newestThread(SendChatEmail::threadIdsOf($ids));
            $conversation = $newest ? Conversation::find($newest->conversation_id) : null;

            if (SendChatEmail::failBatch(
                $ids,
                Message::EMAIL_QUEUED,
                $conversation,
                (int) $rows->first()->conversation_id,
                __('the email job was lost')
            )) {
                $resolved++;
            }
        }

        return $resolved;
    }

    /**
     * The batches in $status whose rows in that status were all last
     * touched before $cutoff: batch key (email_thread_id) => those rows.
     */
    private static function staleBatches(int $status, Carbon $cutoff): array
    {
        $keys = Message::where('email_status', $status)
            ->whereNotNull('email_thread_id')
            ->where('updated_at', '<', $cutoff)
            ->distinct()
            ->pluck('email_thread_id')
            ->all();

        $batches = [];

        foreach ($keys as $key) {
            $rows = Message::where('email_thread_id', $key)
                ->where('email_status', $status)
                ->get(['id', 'conversation_id', 'updated_at']);

            $recent = $rows->contains(function ($row) use ($cutoff) {
                return $row->updated_at && $row->updated_at->gte($cutoff);
            });

            if ($rows->isNotEmpty() && !$recent) {
                $batches[(int) $key] = $rows;
            }
        }

        return $batches;
    }

    /**
     * Ruling D8: a published agent reply in a website-chat conversation that
     * still has no row an hour after it was written never reached the chat
     * (module inactive, listener failed). It gets a lost row, which is never
     * published, and one red line item. Replies core emailed (send_status
     * set) and imported ones are not lost.
     *
     * thread_id is unique: a concurrent run's insert loses with a unique
     * violation, caught here outside any transaction (PostgreSQL aborts one
     * on a failed statement); only the run that inserted writes the item.
     *
     * Only website-chat conversations: core's forward keeps the channel on
     * its email copy (Conversation::TYPE_EMAIL), which has no chat.
     *
     * While the queue is backed up, nothing is marked (backlog()): a reply's
     * row is written by core's 15 s publish job on the `default` queue, and
     * this sweep runs from the scheduler before a restarted worker drains
     * it. A reply marked lost then would be skipped by PublishReplyToChat
     * forever.
     *
     * Returns the number of replies marked. $now is for tests only.
     */
    public static function markLost(?Carbon $now = null): int
    {
        $now = $now ? $now->copy() : now();

        if (self::backlog($now)) {
            \Log::info('[WordPressChat] Lost replies not checked: the queue has jobs waiting more than '.self::BACKLOG_AGE.' s');

            return 0;
        }

        $threads = Thread::query()
            ->join('conversations', 'conversations.id', '=', 'threads.conversation_id')
            ->where('conversations.channel', WordPressChat::CHANNEL)
            ->where('conversations.type', Conversation::TYPE_CHAT)
            ->where('threads.type', Thread::TYPE_MESSAGE)
            ->where('threads.state', Thread::STATE_PUBLISHED)
            ->where(function ($q) {
                $q->where('threads.imported', false)->orWhereNull('threads.imported');
            })
            ->whereNull('threads.send_status')
            ->whereBetween('threads.created_at', [$now->copy()->subDays(self::WINDOW_DAYS), $now->copy()->subSeconds(self::LOST_GRACE)])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('wordpresschat_messages')
                    ->whereRaw('wordpresschat_messages.thread_id = threads.id');
            })
            ->orderBy('threads.id')
            ->limit(self::MARK_BATCH)
            ->get(['threads.id', 'threads.conversation_id']);

        $marked = 0;

        foreach ($threads as $thread) {
            // Only the insert runs in the transaction: on PostgreSQL a failed
            // statement aborts it, so it is rolled back (a savepoint inside a
            // caller's transaction) before the catch re-reads.
            try {
                DB::transaction(function () use ($thread, $now) {
                    Message::create([
                        'conversation_id' => $thread->conversation_id,
                        'thread_id'       => $thread->id,
                        'session_id'      => null,
                        'direction'       => Message::DIRECTION_OUT,
                        'via'             => Message::VIA_CHAT,
                        'published_at'    => null,
                        'lost_at'         => $now,
                    ]);
                });
            } catch (QueryException $e) {
                // A concurrent run inserted it first: it writes the item.
                if (Message::where('thread_id', $thread->id)->exists()) {
                    continue;
                }

                throw $e;
            }

            $conversation = Conversation::find($thread->conversation_id);

            if ($conversation) {
                FailureLineItem::create($conversation, __('A reply did not reach the website chat. Send it again if it is still needed.'));
            }

            $marked++;
        }

        return $marked;
    }

    /**
     * Whether the database queue holds a job on a queue the worker consumes
     * that is overdue by more than BACKLOG_AGE seconds: unreserved and
     * available that long ago, or reserved longer ago than the queue's
     * retry_after plus BACKLOG_AGE (a crashed worker). Jobs on queues no
     * worker consumes (a third-party `sms`) are ignored. `emails` counts
     * only with $withEmails: dev never consumes it, and a slow mail server
     * must not stop the lost-reply check. False for every other queue driver.
     */
    public static function backlog(\DateTimeInterface $now, bool $withEmails = false): bool
    {
        $config = config('queue.connections.'.config('queue.default'));

        if (!is_array($config) || ($config['driver'] ?? null) !== 'database') {
            return false;
        }

        $queues = array_values(array_filter(array_map('trim', explode(',', (string) (config('app.queue_work_params')['--queue'] ?? 'emails,default')))));

        if (!$withEmails) {
            $queues = array_values(array_diff($queues, ['emails']));
        }

        if (!$queues) {
            return false;
        }

        $at = $now->getTimestamp();
        $reservedBefore = $at - (int) config('queue.connections.database.retry_after', 90) - self::BACKLOG_AGE;

        return DB::connection($config['connection'] ?? null)
            ->table($config['table'] ?? 'jobs')
            ->whereIn('queue', $queues)
            ->where(function ($q) use ($at, $reservedBefore) {
                $q->where(function ($q) use ($at) {
                    $q->whereNull('reserved_at')->where('available_at', '<', $at - self::BACKLOG_AGE);
                })->orWhere('reserved_at', '<', $reservedBefore);
            })
            ->exists();
    }
}
