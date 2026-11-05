<?php

namespace Modules\WordPressChat\Listeners;

use App\Conversation;
use App\Thread;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Services\DueEmails;
use Modules\WordPressChat\Services\FailureLineItem;
use Modules\WordPressChat\Services\IdentityLineItem;
use Modules\WordPressChat\WordPressChat;

/**
 * Hooks core's `conversation.customer_replied` (fired after commit): a
 * customer's email reply in a chat conversation becomes an email-channel
 * row, which verified browsers may see, and cuts off every unverified
 * browser (spec §6.4 rule 3). Our own chat messages fire the same action but
 * already have a Message row, so they are skipped.
 *
 * Bounces and auto-replies (an out-of-office answer) are not the customer
 * writing: core files them as customer threads too, but they get no row and
 * no cut-off, so a mistyped address or an absent visitor never locks the
 * chat. A bounce gets one red failure line item (Ruling D9); an auto-reply
 * is ignored entirely. The bounce check comes first: a real bounce carries
 * `Auto-Submitted: auto-replied` (Postfix, Exim, Gmail), which makes core's
 * isAutoResponder() true for it as well.
 *
 * Core itself never threads a bounce it detected into the conversation it
 * bounced from (FetchEmails skips its reply lookup for bounces), so in core
 * the bounce branch here only runs when another module routes a bounce in.
 * Core's own path is RecordBounce, on the send log saveBounceData() writes.
 *
 * The row and the cut-off are written in one transaction, so an email row
 * never exists without its cut-off. Its unique-violation catch sits outside
 * that transaction (on PostgreSQL a failed statement aborts the transaction
 * it sits in; Ruling R4). The line item follows after commit, written only
 * by the run that set the cut-off.
 */
class RecordCustomerEmail
{
    const BOUNCE_NOTED_META = 'wordpresschat.bounce_noted';

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

            if ($thread->isBounce()) {
                // Not the customer writing: no row, no cut-off, one red
                // line item (Ruling D9), written once per bounce.
                if (!$thread->getMeta(self::BOUNCE_NOTED_META)) {
                    $thread->setMeta(self::BOUNCE_NOTED_META, true);
                    $thread->save();

                    self::noteBounce($conversation);
                }

                return;
            }

            if ($thread->isAutoResponder()) {
                return;
            }

            // Outside the transaction: of() may insert, and it only
            // recovers from a concurrent insert outside one (Ruling C4).
            $state = ConversationState::of($conversation);
            $set = false;

            try {
                DB::transaction(function () use ($conversation, $thread, $state, &$set) {
                    $row = Message::create([
                        'conversation_id' => $conversation->id,
                        'thread_id'       => $thread->id,
                        'session_id'      => null,
                        'direction'       => Message::DIRECTION_IN,
                        'via'             => Message::VIA_EMAIL,
                        'published_at'    => now(),
                    ]);

                    // Conditional update: the first cut-off wins, and only
                    // the run that set it writes the line item.
                    $set = ConversationState::where('id', $state->id)
                        ->whereNull('cutoff_message_id')
                        ->update([
                            'cutoff_message_id' => $row->id,
                            'cutoff_reason'     => ConversationState::CUTOFF_EMAIL,
                        ]) > 0;
                });
            } catch (QueryException $e) {
                // A concurrent run inserted the row first: it does the rest.
                if (Message::where('thread_id', $thread->id)->exists()) {
                    return;
                }

                throw $e;
            }

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

    /**
     * The bounce line item (Ruling D9). It names the address the email went
     * to: customer_email, or the customer's main email when that column is
     * empty (DueEmails::address(), the job's own choice). With no address at
     * all it says so without one, never "The email to  bounced".
     */
    public static function noteBounce(Conversation $conversation, array $recipients = []): void
    {
        if (count($recipients) > 1) {
            FailureLineItem::create($conversation, __(
                'The email to :emails bounced. At least one of these addresses may not have received the reply.',
                ['emails' => implode(', ', $recipients)]
            ));

            return;
        }

        $email = count($recipients) === 1 ? $recipients[0] : DueEmails::address($conversation);

        if ($email === null) {
            $email = trim((string) $conversation->customer_email);
        }

        FailureLineItem::create($conversation, $email !== ''
            ? __('The email to :email bounced. The visitor may not have received the reply.', ['email' => $email])
            : __('An email to the visitor bounced. The visitor may not have received the reply.'));
    }
}
