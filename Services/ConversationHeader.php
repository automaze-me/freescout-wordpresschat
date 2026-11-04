<?php

namespace Modules\WordPressChat\Services;

use App\Conversation;
use App\Thread;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Level;
use Modules\WordPressChat\WordPressChat;

/**
 * What agents see on a website-chat conversation, beside core's own channel
 * tag: the site name (several sites only), the identity badge and, for
 * order-only links, a warning; plus a "via email" chip on customer threads
 * that arrived by email.
 */
class ConversationHeader
{
    public static function render(Conversation $conversation): string
    {
        if ((int) $conversation->channel !== WordPressChat::CHANNEL) {
            return '';
        }

        $siteName = null;

        if (Site::count() > 1) {
            $site = Site::find(ConversationState::of($conversation)->site_id);
            $siteName = $site ? (string) $site->name : null;
        }

        $level = AccessLevel::badge($conversation);

        return view('wordpresschat::conversation.header', [
            'siteName'  => $siteName,
            'label'     => Level::label($level),
            'showWarn'  => $level === Level::ORDER,
            'presence'  => Presence::line($conversation),
        ])->render();
    }

    public static function threadChip(Thread $thread): string
    {
        if ((int) $thread->type !== Thread::TYPE_CUSTOMER) {
            return '';
        }

        $viaEmail = Message::where('thread_id', $thread->id)
            ->where('via', Message::VIA_EMAIL)
            ->exists();

        return $viaEmail
            ? '<span class="label label-default wpc-via-email">'.e(__('via email')).'</span>'
            : '';
    }
}
