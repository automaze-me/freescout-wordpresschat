<?php

namespace Modules\WordPressChat\Services;

use App\Conversation;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\ConversationState;

/**
 * The agent's per-reply choice between the website chat and email (spec
 * §8.3), and when that choice is not free (Ruling D7).
 */
class ReplyChannel
{
    const CHAT  = 'chat';
    const EMAIL = 'email';

    /** Thread meta holding the choice; absent means chat. */
    const META = 'wordpresschat.channel';

    public static function emailAvailable(Conversation $conversation): bool
    {
        return DueEmails::address($conversation) !== null;
    }

    /**
     * A customer's email has cut off unverified browsers and no browser has
     * verified yet: nobody is left who could read a chat reply.
     */
    public static function locked(Conversation $conversation): bool
    {
        $state = ConversationState::where('conversation_id', $conversation->id)->first();

        if (!$state || (int) $state->cutoff_reason !== ConversationState::CUTOFF_EMAIL) {
            return false;
        }

        return !Access::where('conversation_id', $conversation->id)->whereNotNull('verified_at')->exists();
    }

    public static function defaultChoice(Conversation $conversation): string
    {
        return self::locked($conversation) ? self::EMAIL : self::CHAT;
    }
}
