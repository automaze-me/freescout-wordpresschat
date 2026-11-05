<?php

namespace Modules\WordPressChat\Listeners;

use App\Conversation;
use App\Thread;
use Illuminate\Database\QueryException;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Services\DeliveryClock;
use Modules\WordPressChat\Services\FailureLineItem;
use Modules\WordPressChat\WordPressChat;

/**
 * Hooks core's `chat_conversation.send_reply` action: for chat-type
 * conversations core never emails agent replies; after the undo window it
 * fires this action instead. Recording the reply as published is what lets
 * the widget's poll show it to the visitor.
 *
 * $replies is the WHOLE published history (customer + agent threads, newest
 * first), not "the replies to send". Only first() is the triggering reply, so
 * this never iterates it, or old replies would be replayed.
 *
 * The upsert is keyed by thread_id and only ever runs for TYPE_MESSAGE
 * (agent) threads, so it can never touch a visitor's DIRECTION_IN row.
 */
class PublishReplyToChat
{
    public function handle($conversation, $replies, $customer)
    {
        try {
            if (!$conversation instanceof Conversation
                || !WordPressChat::isWebsiteChat($conversation)) {
                return;
            }

            $first = $replies->first();

            if (!$first || empty($first->id)) {
                // Core builds $replies in the same request that created the
                // reply, so this can't happen in any normal flow. If it
                // does, a reply was lost: log it at error level (the default
                // log_level would drop anything lower).
                \Log::error('[WordPressChat] PublishReplyToChat: chat_conversation.send_reply fired with no deliverable reply', [
                    'conversation_id' => $conversation->id,
                ]);

                return;
            }

            // $first is a snapshot from before the undo window; re-read it.
            $fresh = Thread::find($first->id);

            if (!$fresh
                || (int) $fresh->type !== Thread::TYPE_MESSAGE
                || (int) $fresh->state !== Thread::STATE_PUBLISHED) {
                return;
            }

            // The conversation may have been merged meanwhile: use the
            // thread's current conversation, not the snapshot.
            $current = Conversation::find($fresh->conversation_id);

            if (!$current) {
                return;
            }

            if (!WordPressChat::isWebsiteChat($current)) {
                if ((int) $current->id !== (int) $conversation->id) {
                    self::mergedAway($conversation, $current, $fresh);
                }

                return;
            }

            $message = Message::where('thread_id', $fresh->id)->first();

            if (!$message) {
                $message = new Message();
                $message->thread_id = $fresh->id;
            }

            // A reply that never reached the chat is never published late.
            if ($message->lost_at) {
                return;
            }

            // Already published as chat (a queue retry or duplicate event):
            // keep the delivery state, or an emailed reply would be due
            // again. Only the merge path's conversation id still follows.
            $alreadyPublished = $message->exists
                && (int) $message->via === Message::VIA_CHAT
                && $message->published_at;

            $message->conversation_id = $current->id;

            if ($alreadyPublished) {
                if ($message->isDirty()) {
                    $message->save();
                }

                return;
            }

            $message->direction = Message::DIRECTION_OUT;
            $message->session_id = null;

            if (!$message->published_at) {
                $message->published_at = now();
            }

            $message->via = Message::VIA_CHAT;
            $message->email_status = null;
            $message->email_thread_id = null;
            $message->fallback_due_at = $message->published_at->copy()->addSeconds(DeliveryClock::delayFor($current));

            $message->save();
        } catch (\Throwable $e) {
            \Log::error('[WordPressChat] PublishReplyToChat: listener failed', [
                'conversation_id' => is_object($conversation) ? ($conversation->id ?? null) : null,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    /**
     * The chat was merged into a conversation without a chat (an email
     * conversation) inside core's undo window, before this publish ran: the
     * reply reaches the visitor neither by chat nor by email, and the merge
     * found no row to fail (MergeChat). It gets a failed row, never
     * published nor swept, under the snapshot's id like MergeChat's rows,
     * and $current one red line item.
     *
     * thread_id is unique: an existing row means another run (or the
     * merge) already handled the reply. A repeated run stops at the read;
     * a concurrent one loses the insert with a unique violation, caught
     * outside any transaction (Ruling R4). Only the run that inserted
     * writes the item.
     */
    private static function mergedAway(Conversation $snapshot, Conversation $current, Thread $thread): void
    {
        if (Message::where('thread_id', $thread->id)->exists()) {
            return;
        }

        try {
            Message::create([
                'conversation_id' => $snapshot->id,
                'thread_id'       => $thread->id,
                'session_id'      => null,
                'direction'       => Message::DIRECTION_OUT,
                'via'             => Message::VIA_CHAT,
                'published_at'    => null,
                'fallback_due_at' => null,
                'email_status'    => Message::EMAIL_FAILED,
            ]);
        } catch (QueryException $e) {
            // SQLSTATE class 23: a concurrent run inserted the row first.
            if (strpos((string) $e->getCode(), '23') === 0) {
                return;
            }

            throw $e;
        }

        FailureLineItem::create($current, MergeChat::failureText(1));
    }
}
