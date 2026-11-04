<?php

namespace Modules\WordPressChat\Listeners;

use App\Conversation;
use App\Thread;
use Illuminate\Database\QueryException;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Services\IdentityLineItem;
use Modules\WordPressChat\WordPressChat;

/**
 * Hooks core's `conversation.customer_replied` (fired after commit): a
 * customer's email reply in a chat conversation becomes an email-channel
 * row, which verified browsers may see, and cuts off every unverified
 * browser (spec §6.4 rule 3). Our own chat messages fire the same action but
 * already have a Message row, so they are skipped.
 *
 * Runs outside any transaction, so the unique-violation catch on the insert
 * is safe on PostgreSQL (Ruling R4).
 */
class RecordCustomerEmail
{
    public function handle($conversation, $thread, $customer = null)
    {
        try {
            if (!$conversation instanceof Conversation
                || (int) $conversation->channel !== WordPressChat::CHANNEL
                || !$thread instanceof Thread
                || (int) $thread->type !== Thread::TYPE_CUSTOMER
                || (int) $thread->state !== Thread::STATE_PUBLISHED
                || Message::where('thread_id', $thread->id)->exists()) {
                return;
            }

            try {
                $row = Message::create([
                    'conversation_id' => $conversation->id,
                    'thread_id'       => $thread->id,
                    'session_id'      => null,
                    'direction'       => Message::DIRECTION_IN,
                    'via'             => Message::VIA_EMAIL,
                    'published_at'    => now(),
                ]);
            } catch (QueryException $e) {
                // A concurrent run inserted the row first: it does the rest.
                if (Message::where('thread_id', $thread->id)->exists()) {
                    return;
                }

                throw $e;
            }

            $state = ConversationState::of($conversation);

            // Conditional update: only the run that sets the first cut-off
            // writes the line item.
            $set = ConversationState::where('id', $state->id)
                ->whereNull('cutoff_message_id')
                ->update([
                    'cutoff_message_id' => $row->id,
                    'cutoff_reason'     => ConversationState::CUTOFF_EMAIL,
                ]);

            if ($set) {
                IdentityLineItem::create($conversation, __('Conversation continues by email'));
            }
        } catch (\Throwable $e) {
            \Log::error('[WordPressChat] RecordCustomerEmail: listener failed', [
                'conversation_id' => is_object($conversation) ? ($conversation->id ?? null) : null,
                'error'           => $e->getMessage(),
            ]);
        }
    }
}
