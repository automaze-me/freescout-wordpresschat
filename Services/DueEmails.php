<?php

namespace Modules\WordPressChat\Services;

use App\Conversation;
use App\Thread;
use Carbon\Carbon;
use Illuminate\Contracts\Bus\Dispatcher;
use Modules\WordPressChat\Entities\Message;
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

    /**
     * Seconds after which a batch still in "sending" belongs to a dead
     * worker. Far above the job's 120 s timeout, so no live job is cut.
     */
    const STALE_SENDING = 600;

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
            ->where('wordpresschat_messages.direction', Message::DIRECTION_OUT)
            ->where('wordpresschat_messages.via', Message::VIA_CHAT)
            ->whereNotNull('wordpresschat_messages.published_at')
            ->whereNull('wordpresschat_messages.seen_at')
            ->whereNull('wordpresschat_messages.email_status')
            ->whereNull('wordpresschat_messages.lost_at')
            ->whereBetween('wordpresschat_messages.fallback_due_at', [$now->copy()->subDays(self::WINDOW_DAYS), $now])
            ->where('threads.state', Thread::STATE_PUBLISHED)
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
     * Batches a dead worker left in "sending" (the job's timeout, an
     * out-of-memory fatal in core's IMAP copy, a restart). Core's send
     * status on the batch's newest thread, its last_thread, tells whether
     * the email went out: then the rows are sent; otherwise the batch
     * fails with one red line item. Never queued again: a kill after the
     * transport handoff would send the email twice.
     *
     * The claim stamps updated_at (Eloquent's update() does). A batch is
     * handled once every row of it is stale: a "seen" stamps a row too,
     * and handling part of a batch would give it two line items.
     *
     * Returns the number of batches resolved.
     */
    public static function resolveStale(?Carbon $now = null): int
    {
        $cutoff = ($now ? $now->copy() : now())->subSeconds(self::STALE_SENDING);

        $keys = Message::where('email_status', Message::EMAIL_SENDING)
            ->whereNotNull('email_thread_id')
            ->where('updated_at', '<', $cutoff)
            ->distinct()
            ->pluck('email_thread_id')
            ->all();

        $resolved = 0;

        foreach ($keys as $key) {
            $rows = Message::where('email_thread_id', $key)
                ->where('email_status', Message::EMAIL_SENDING)
                ->get(['id', 'conversation_id', 'updated_at']);

            $recent = $rows->contains(function ($row) use ($cutoff) {
                return $row->updated_at && $row->updated_at->gte($cutoff);
            });

            if ($rows->isEmpty() || $recent) {
                continue;
            }

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
}
