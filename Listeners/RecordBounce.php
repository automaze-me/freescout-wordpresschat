<?php

namespace Modules\WordPressChat\Listeners;

use App\Conversation;
use App\SendLog;
use App\Thread;
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
 * second item for it.
 */
class RecordBounce
{
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

            RecordCustomerEmail::noteBounce($conversation);
        } catch (\Throwable $e) {
            \Log::error('[WordPressChat] RecordBounce: listener failed', [
                'thread_id' => is_object($log) ? ($log->thread_id ?? null) : null,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
