<?php

namespace Modules\WordPressChat\Listeners;

use App\Conversation;
use App\Thread;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Jobs\SendChatEmail;
use Modules\WordPressChat\Services\ReplyChannel;
use Modules\WordPressChat\WordPressChat;

/**
 * The agent's "send by email" choice (spec §8.3): a capture/intercept pair.
 * Website-chat conversations only (channel and chat type): core's forward
 * keeps the channel on its email copy, which core emails itself.
 *
 * capture() runs while the agent's request is saved and writes the choice
 * onto the thread's meta; intercept() runs later, in core's
 * `conversation.skip_send_reply_to_customer` filter (before core's chat
 * branch), and routes the reply to our email job instead.
 */
class RouteReplyChannel
{
    /**
     * Hooked at `thread.before_save_from_request`; the thread is saved right
     * after, so no save() here.
     *
     * Only agent messages of this module's conversations are touched. The
     * meta is set to "email" when the form said so and an address exists, or
     * when the conversation is locked to email (Ruling D7) and an address
     * exists. In every other case it is CLEARED, not left alone: an agent
     * re-sending after Undo must not inherit the earlier choice, and core's
     * multi-recipient copy replicates the thread (meta included) before
     * this hook fires on the copy.
     */
    public static function capture(Thread $thread, $request)
    {
        if ((int) $thread->type !== Thread::TYPE_MESSAGE) {
            return;
        }

        $conversation = Conversation::find($thread->conversation_id);

        if (!$conversation
            || (int) $conversation->channel !== WordPressChat::CHANNEL
            || !$conversation->isChat()) {
            return;
        }

        $email = false;

        if (ReplyChannel::emailAvailable($conversation)) {
            $email = $request->input('wpc_channel') === ReplyChannel::EMAIL
                || ReplyChannel::locked($conversation);
        }

        $thread->setMeta(ReplyChannel::META, $email ? ReplyChannel::EMAIL : null);
    }

    /**
     * Hooked at `conversation.skip_send_reply_to_customer`. $replies is
     * core's whole published history, newest first: only first() is the
     * triggering reply.
     *
     * An undone reply is dropped by SendChatEmail (its thread is a draft by
     * then); a re-send is captured afresh.
     */
    public static function intercept($skip, Conversation $conversation, $replies)
    {
        if ($skip
            || (int) $conversation->channel !== WordPressChat::CHANNEL
            || !$conversation->isChat()) {
            return $skip;
        }

        $thread = $replies->first();

        if (!$thread || $thread->getMeta(ReplyChannel::META) !== ReplyChannel::EMAIL) {
            return $skip;
        }

        $message = Message::where('thread_id', $thread->id)->first();

        if (!$message) {
            $message = new Message();
            $message->thread_id = $thread->id;
        }

        // A repeated firing (a retry of core's listener, a duplicate event)
        // must not re-queue a reply that is being or has been sent. A failed
        // one is not re-sent either: the agent saw the red line item.
        if (in_array((int) $message->email_status, [
            Message::EMAIL_SENDING,
            Message::EMAIL_SENT,
            Message::EMAIL_FAILED,
        ], true)) {
            return true;
        }

        $message->conversation_id = $conversation->id;
        $message->direction = Message::DIRECTION_OUT;
        $message->via = Message::VIA_EMAIL;
        $message->session_id = null;
        $message->published_at = null;
        $message->fallback_due_at = null;
        $message->seen_at = null;
        $message->email_status = Message::EMAIL_QUEUED;
        $message->email_thread_id = $thread->id;
        $message->save();

        // Runs inline with the agent's request, so the undo window is
        // applied here, as core does for its own mail.
        $delay = \Eventy::filter(
            'conversation.send_reply_to_customer_delay',
            now()->addSeconds(Conversation::UNDO_TIMOUT),
            $conversation,
            $replies
        );

        SendChatEmail::dispatch($conversation->id, $thread->id)
            ->delay($delay)
            ->onQueue('emails');

        return true;
    }
}
