<?php

namespace Modules\WordPressChat;

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
}
