<?php

namespace Modules\WordPressChat\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Session;

/**
 * The single gate for what a visitor's browser may receive. Every widget
 * read goes through query(); identity rules are added here later.
 */
class Visibility
{
    /**
     * Messages of the session's conversations that are published: visitor
     * messages, and agent replies once published. Ordered by id ascending.
     */
    public static function query(Session $session): Builder
    {
        return Message::query()
            ->whereIn('wordpresschat_messages.conversation_id', function ($q) use ($session) {
                $q->select('conversation_id')
                    ->from((new Access())->getTable())
                    ->where('session_id', $session->id);
            })
            ->whereHas('thread', function ($q) {
                $q->where('state', \App\Thread::STATE_PUBLISHED);
            })
            ->where(function ($q) {
                $q->where('wordpresschat_messages.direction', Message::DIRECTION_IN)
                    ->orWhere(function ($q) {
                        $q->where('wordpresschat_messages.direction', Message::DIRECTION_OUT)
                            ->whereNotNull('wordpresschat_messages.published_at');
                    });
            })
            ->orderBy('wordpresschat_messages.id');
    }
}
