<?php

namespace Modules\WordPressChat\Services;

use App\Conversation;
use App\Thread;

/**
 * Agent-side line items that record identity events ("Conversation continues
 * by email", ...). Same contract as Kapso's delivery-failure line item
 * (Ruling P13): a TYPE_LINEITEM with a NULL action_type, an escaped body and
 * the LINEITEM_META flag; core renders a line item only through
 * Thread::getActionText(), which falls through to the `thread.action_text`
 * filter (registered in the service provider) for a NULL action_type.
 * These are information, not failures: no status change, no styling.
 */
class IdentityLineItem
{
    const LINEITEM_META = 'wordpresschat.lineitem';

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
        $lineItem->setMeta(self::LINEITEM_META, true);
        $lineItem->save();

        return $lineItem;
    }
}
