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
        $lineItem = IdentityLineItem::create($conversation, $text);
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
