<?php

namespace Modules\WordPressChat\Listeners;

use App\Conversation;
use App\SendLog;
use App\Thread;
use Illuminate\Support\Facades\DB;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\WordPressChat;

/**
 * Core's own bounce path (Ruling D9). FetchEmails never threads a bounce it
 * detected into the conversation it bounced from: it skips the reply lookup
 * for bounces, so the bounce opens a new email conversation, and
 * `conversation.customer_replied` never fires in the chat conversation.
 * What reaches the chat conversation is saveBounceData(): it finds the
 * bounced reply by the Message-ID core gave its email, sets its send_status
 * to STATUS_DELIVERY_ERROR and logs that status to the send log, once per
 * bounce. This listener hangs on that send log row's `created` model event
 * and writes the red line item in the bounced reply's conversation.
 *
 * Only website-chat conversations (channel and chat type: core's forward
 * keeps the channel on its email copy). Core writes STATUS_DELIVERY_ERROR
 * for bounces only.
 *
 * A bounce that another module did route into the chat conversation was
 * already noted by RecordCustomerEmail (meta on the bounce thread, which
 * saveBounceData() names in the bounced reply's send status data): no
 * second item for it. The other order, a bounce routed in after this
 * listener wrote the item, is checked there (BOUNCE_META on the reply).
 *
 * One item per reply: the meta check, the meta and the item are read and
 * written in one transaction, under the reply's row lock. Two DSNs
 * processed at once write one item, and an item that fails to write leaves
 * the meta unset for the next DSN. Nothing in that transaction is expected
 * to fail (Ruling R4).
 */
class RecordBounce
{
    /**
     * Meta on the bounced reply thread: its bounce item was written. Not
     * RecordCustomerEmail::BOUNCE_NOTED_META, which sits on the bounce.
     */
    const BOUNCE_META = 'wordpresschat.bounce_item';

    public static function handle($log): void
    {
        try {
            if (!$log instanceof SendLog
                || (int) $log->status !== SendLog::STATUS_DELIVERY_ERROR
                || !$log->thread_id) {
                return;
            }

            $thread = Thread::find($log->thread_id);
            $conversation = $thread ? Conversation::find($thread->conversation_id) : null;

            if (!$conversation
                || (int) $conversation->channel !== WordPressChat::CHANNEL
                || !$conversation->isChat()) {
                return;
            }

            $data = $thread->getSendStatusData();

            if (!empty($data['bounced_by_thread'])) {
                $bounce = Thread::find($data['bounced_by_thread']);

                if ($bounce && $bounce->getMeta(RecordCustomerEmail::BOUNCE_NOTED_META)) {
                    return;
                }
            }

            // Only a reply that was sent by email can bounce. Anything else
            // is a forged or stray DSN. A fast DSN can arrive while the job
            // still has the row "sending": the email went out all the same.
            $sent = Message::where('email_thread_id', $thread->id)
                ->whereIn('email_status', [Message::EMAIL_SENDING, Message::EMAIL_SENT])
                ->exists();

            if (!$sent) {
                return;
            }

            DB::transaction(function () use ($thread, $conversation) {
                // Re-read under the lock: a competing DSN may have written
                // the item since $thread was read.
                $locked = Thread::lockForUpdate()->find($thread->id);

                if (!$locked || $locked->getMeta(self::BOUNCE_META)) {
                    return;
                }

                $locked->setMeta(self::BOUNCE_META, true);
                $locked->save();

                RecordCustomerEmail::noteBounce($conversation, self::recipients($locked));
            });
        } catch (\Throwable $e) {
            \Log::error('[WordPressChat] RecordBounce: listener failed', [
                'thread_id' => is_object($log) ? ($log->thread_id ?? null) : null,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /** Every address the transport accepted for $thread, once each. */
    private static function recipients(Thread $thread): array
    {
        return SendLog::where('thread_id', $thread->id)
            ->where('status', SendLog::STATUS_ACCEPTED)
            ->pluck('email')
            ->map(function ($email) {
                return trim((string) $email);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
