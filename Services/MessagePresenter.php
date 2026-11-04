<?php

namespace Modules\WordPressChat\Services;

use Modules\WordPressChat\Entities\Message;

class MessagePresenter
{
    /**
     * The widget's view of one chat message. Only plain text leaves the
     * server; the widget renders it with textContent. `via` is the channel
     * the thread travelled by: "chat", or "email" for rows only verified
     * browsers receive.
     */
    public static function present(Message $m): array
    {
        $thread  = $m->thread;
        $isAgent = (int) $m->direction === Message::DIRECTION_OUT;
        $author  = null;
        $avatar  = null;

        if ($isAgent && $thread && $thread->created_by_user) {
            $author = $thread->created_by_user->first_name;
            $avatar = self::avatarUrl($thread->created_by_user);
        }

        $createdAt = $thread ? $thread->created_at : $m->created_at;

        return [
            'id'         => (int) $m->id,
            'role'       => $isAgent ? 'agent' : 'visitor',
            'text'       => $thread ? self::text($thread->body) : '',
            'author'     => $author,
            'avatar'     => $avatar,
            'created_at' => $createdAt ? $createdAt->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z') : null,
            'via'        => (int) $m->via === Message::VIA_EMAIL ? 'email' : 'chat',
        ];
    }

    /**
     * The agent's own photo, or null (never the default avatar). The widget
     * runs on another origin, so a relative URL is made absolute against
     * the FreeScout base URL.
     */
    private static function avatarUrl($user)
    {
        $url = (string) $user->getPhotoUrl(false);
        if ($url === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = url($url);
        }

        return $url;
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
