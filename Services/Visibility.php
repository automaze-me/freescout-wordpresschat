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
            ->where(function ($q) {
                // Each direction is paired with the only thread type it may
                // carry, so a stray row on a note can never leak.
                $q->where(function ($q) {
                    $q->where('wordpresschat_messages.direction', Message::DIRECTION_IN)
                        ->whereHas('thread', function ($t) {
                            $t->where('state', \App\Thread::STATE_PUBLISHED)
                                ->where('type', \App\Thread::TYPE_CUSTOMER);
                        });
                })->orWhere(function ($q) {
                    $q->where('wordpresschat_messages.direction', Message::DIRECTION_OUT)
                        ->whereNotNull('wordpresschat_messages.published_at')
                        ->whereHas('thread', function ($t) {
                            $t->where('state', \App\Thread::STATE_PUBLISHED)
                                ->where('type', \App\Thread::TYPE_MESSAGE);
                        });
                });
            })
            ->orderBy('wordpresschat_messages.id');
    }
}
