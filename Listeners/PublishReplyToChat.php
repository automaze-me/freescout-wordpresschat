<?php

namespace Modules\WordPressChat\Listeners;

use App\Conversation;
use App\Thread;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Services\DeliveryClock;
use Modules\WordPressChat\Services\FailureLineItem;
use Modules\WordPressChat\Services\Visibility;
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
 *
 * Late reply after a merge (Ruling P5): a reply written to chat S whose
 * thread now sits in conversation T (S was merged into T inside core's undo
 * window, or before a repeated publish) goes into T only when some browser
 * holds Access to both, i.e. both chats are one visitor's. Otherwise T's
 * browsers belong to someone else: the reply fails like one whose chat was
 * merged into an email conversation (mergedAway()), and is never re-pointed.
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

            $merged = (int) $current->id !== (int) $conversation->id;

            if (!WordPressChat::isWebsiteChat($current)) {
                if ($merged) {
                    self::mergedAway($conversation, $current, $fresh);
                }

                return;
            }

            if ($merged && !Visibility::oneVisitor((int) $conversation->id, (int) $current->id)) {
                self::mergedAway($conversation, $current, $fresh);

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
            // again. Only the conversation id still follows a merge between
            // one visitor's chats (checked above).
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
     * conversation), or into another visitor's chat, before this publish
     * ran: the reply reaches the visitor neither by chat nor by email, and
     * the merge found no row to fail (MergeChat). It gets a failed row,
     * never published nor swept, under the snapshot's id like MergeChat's
     * rows, and $current one red line item.
     *
     * thread_id is unique: an existing row means another run (or the
     * merge) already handled the reply. A repeated run stops at the read;
     * a concurrent one loses the insert with a unique violation, caught
     * outside the insert's own transaction (Ruling R4). Only the run that inserted
     * writes the item.
     */
    private static function mergedAway(Conversation $snapshot, Conversation $current, Thread $thread): void
    {
        if (Message::where('thread_id', $thread->id)->exists()) {
            return;
        }

        // Only the insert runs in the transaction: on PostgreSQL a failed
        // statement aborts it, so it is rolled back (a savepoint inside a
        // caller's transaction) before the catch runs.
        try {
            DB::transaction(function () use ($snapshot, $thread) {
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
            });
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
