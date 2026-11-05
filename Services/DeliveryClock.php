<?php

namespace Modules\WordPressChat\Services;

use App\Conversation;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Site;

/**
 * The fallback clock: when may an unseen chat reply be emailed.
 */
class DeliveryClock
{
    /** Seconds, when there is no site or the field was left empty. */
    const DEFAULT_DELAY = 120;

    /**
     * The conversation's site delay, in one query. Reads the state without
     * creating one (ConversationState::of() would).
     */
    public static function delayFor(Conversation $conversation): int
    {
        $delay = (int) Site::join((new ConversationState())->getTable().' as cs', 'cs.site_id', '=', (new Site())->getTable().'.id')
            ->where('cs.conversation_id', $conversation->id)
            ->value('fallback_delay');

        if ($delay > 0) {
            return $delay;
        }

        return self::DEFAULT_DELAY;
    }

    /**
     * Unseen, unemailed chat replies become due no earlier than now plus
     * the delay (Ruling D3). A null due time stays null (Ruling D10).
     * Returns the number of rows changed.
     */
    public static function restart(Conversation $conversation): int
    {
        $due = now()->addSeconds(self::delayFor($conversation));

        return Message::where('conversation_id', $conversation->id)
            ->where('direction', Message::DIRECTION_OUT)
            ->where('via', Message::VIA_CHAT)
            ->whereNotNull('fallback_due_at')
            ->whereNull('seen_at')
            ->whereNull('email_status')
            ->whereNull('lost_at')
            ->where('fallback_due_at', '<', $due)
            ->update(['fallback_due_at' => $due]);
    }
}
