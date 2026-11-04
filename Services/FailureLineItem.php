<?php

namespace Modules\WordPressChat\Services;

use App\Conversation;
use App\Thread;

/**
 * Red line items for delivery failures ("Email to the visitor failed: …").
 * Same contract as IdentityLineItem (a TYPE_LINEITEM with a NULL
 * action_type, an escaped body, LINEITEM_META), plus FAILURE_META, which
 * makes the provider's `thread.action_text` filter wrap the body in
 * `text-danger` for escaped callers. A failure must not sit unnoticed in a
 * Pending or Closed conversation: it goes back to Active, as a visitor
 * message would put it (Kapso's DeliveryFailureLineItem).
 */
class FailureLineItem
{
    const FAILURE_META = 'wordpresschat.failure';

    /** $text is plain text; it is escaped here. */
    public static function create(Conversation $conversation, string $text): Thread
    {
        $lineItem = new Thread();
        $lineItem->conversation_id = $conversation->id;
        $lineItem->user_id         = null;
        $lineItem->type            = Thread::TYPE_LINEITEM;
        $lineItem->status          = Thread::STATUS_NOCHANGE;
        $lineItem->state           = Thread::STATE_PUBLISHED;
        $lineItem->body            = e($text);
        // Core has no PERSON_SYSTEM: a system line item is attributed to the user side.
        $lineItem->source_via      = Thread::PERSON_USER;
        $lineItem->source_type     = Thread::SOURCE_TYPE_API;
        $lineItem->customer_id     = $conversation->customer_id;
        $lineItem->setMeta(IdentityLineItem::LINEITEM_META, true);
        $lineItem->setMeta(self::FAILURE_META, true);
        $lineItem->save();

        if (InboundMessage::reactivate($conversation)) {
            $conversation->save();

            if ($conversation->mailbox) {
                $conversation->mailbox->updateFoldersCounters();
            }
        }

        return $lineItem;
    }
}
