<?php

namespace Modules\WordPressChat\Services;

use Modules\WordPressChat\Entities\Message;

class MessagePresenter
{
    /**
     * The widget's view of one chat message. Only plain text leaves the
     * server; the widget renders it with textContent.
     */
    public static function present(Message $m): array
    {
        $thread  = $m->thread;
        $isAgent = (int) $m->direction === Message::DIRECTION_OUT;
        $author  = null;

        if ($isAgent && $thread && $thread->created_by_user) {
            $author = $thread->created_by_user->first_name;
        }

        $createdAt = $thread ? $thread->created_at : $m->created_at;

        return [
            'id'         => (int) $m->id,
            'role'       => $isAgent ? 'agent' : 'visitor',
            'text'       => $thread ? self::text($thread->body) : '',
            'author'     => $author,
            'created_at' => $createdAt ? $createdAt->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z') : null,
        ];
    }

    /**
     * Helper::htmlToText() drops apostrophes: its html2text decodes with
     * ENT_COMPAT and then deletes every entity it didn't decode, so the
     * &#039; that e() writes for a visitor's "I'm" would come back as "Im".
     * An apostrophe needs no escaping in HTML text, so it is restored first.
     * A visitor who typed "&#039;" literally is stored as "&amp;#039;",
     * which this pattern doesn't touch.
     */
    private static function text($body): string
    {
        $body = preg_replace('/&(#0*39|#x0*27|apos);/i', "'", (string) $body);

        return \Helper::htmlToText($body);
    }
}
