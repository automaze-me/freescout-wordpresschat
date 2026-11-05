<?php

namespace Modules\WordPressChat;

use App\Conversation;

class WordPressChat
{
    const ALIAS = 'wordpresschat';

    /**
     * Provisional channel code (community modules use 100+). Confirm with the
     * FreeScout team before the first public release.
     */
    const CHANNEL = 106;

    public static function channelName(): string
    {
        return __('Website chat');
    }

    /**
     * Whether $conversation is a website chat: this module's channel and
     * core's chat type. Core's forward keeps the channel on its copy, an
     * email conversation without a chat, which core emails itself.
     */
    public static function isWebsiteChat(Conversation $conversation): bool
    {
        return (int) $conversation->channel === self::CHANNEL && $conversation->isChat();
    }
}
