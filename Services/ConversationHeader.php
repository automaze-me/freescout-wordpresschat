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
 * that arrived by email. Website chats only (WordPressChat::isWebsiteChat()):
 * core's forward keeps the channel on its email copy.
 */
class ConversationHeader
{
    /**
     * conversation_id => ['email' => thread ids that arrived by email,
     * 'chat' => whether it is a website chat]: two reads per conversation
     * per request, not per thread.
     *
     * @var array<int, array{email: int[], chat: bool}>
     */
    private static $chips = [];

    /** Tests only. */
    public static function clearCache(): void
    {
        self::$chips = [];
    }

    public static function render(Conversation $conversation): string
    {
        if (!WordPressChat::isWebsiteChat($conversation)) {
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

        if (!isset(self::$chips[$thread->conversation_id])) {
            self::$chips[$thread->conversation_id] = ['email' => null, 'chat' => null];
        }

        $known = &self::$chips[$thread->conversation_id];

        if ($known['email'] === null) {
            $known['email'] = Message::where('conversation_id', $thread->conversation_id)
                ->where('via', Message::VIA_EMAIL)
                ->whereNotNull('thread_id')
                ->pluck('thread_id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->all();
        }

        // The conversation is read only for a thread that has the chip: an
        // email thread merged into an email conversation needs none.
        if (!in_array((int) $thread->id, $known['email'], true)) {
            return '';
        }

        if ($known['chat'] === null) {
            $conversation = Conversation::find($thread->conversation_id);
            $known['chat'] = $conversation && WordPressChat::isWebsiteChat($conversation);
        }

        if (!$known['chat']) {
            return '';
        }

        return '<span class="label label-default wpc-via-email">'.e(__('via email')).'</span>';
    }
}
