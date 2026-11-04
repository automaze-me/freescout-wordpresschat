<?php

namespace Modules\WordPressChat\Entities;

use App\Conversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Per-conversation chat state: the stable public key the widget sees, the
 * WordPress account the conversation is bound to (null: bound to the
 * browsers that hold Access rows), and the conversation-wide cut-off for
 * unverified browsers (Ruling P2).
 */
class ConversationState extends Model
{
    /** A customer email arrived (spec §6.4 rule 3). */
    const CUTOFF_EMAIL = 1;

    /** A browser redeemed a continue link (spec §6.4 rule 8). */
    const CUTOFF_VERIFIED = 2;

    protected $table = 'wordpresschat_conversation_states';

    protected $guarded = ['id'];

    protected $dates = ['created_at', 'updated_at'];

    /**
     * The state of $conversation, created on a miss. That is the lazy path
     * for conversations from before this table existed; a new conversation
     * gets its state from InboundMessage. The site is the one of the
     * conversation's first Access session; without one, 0, which matches no
     * site, so the state can never be found by account discovery.
     *
     * A unique violation from a concurrent creator is caught only outside a
     * transaction (Ruling C4). Inside one it propagates: on PostgreSQL the
     * failed INSERT has already aborted the transaction, so no re-read could
     * run in it (Ruling R4).
     */
    public static function of(Conversation $conversation): self
    {
        $state = self::where('conversation_id', $conversation->id)->first();

        if ($state) {
            return $state;
        }

        $first = Access::where('conversation_id', $conversation->id)->orderBy('id')->first();
        $session = $first ? Session::find($first->session_id) : null;

        try {
            return self::create([
                'conversation_id' => $conversation->id,
                'site_id'         => $session ? (int) $session->site_id : 0,
                'public_id'       => bin2hex(random_bytes(16)),
            ]);
        } catch (QueryException $e) {
            if (DB::transactionLevel() > 0) {
                throw $e;
            }

            $winner = self::where('conversation_id', $conversation->id)->first();

            if (!$winner) {
                throw $e;
            }

            return $winner;
        }
    }
}
