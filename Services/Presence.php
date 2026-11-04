<?php

namespace Modules\WordPressChat\Services;

use App\Conversation;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\Session;

/**
 * Presence of the visitor for agents (Ruling E8): a poll of the widget
 * records when and from which page path it came; the conversation header
 * shows "On the site now · /path" or "Left 10 minutes ago".
 */
class Presence
{
    /** A session that polled within this many seconds is on the site. */
    const ONLINE_SECONDS = 90;

    /** A poll writes only when the stored value is older than this, or the path changed. */
    const WRITE_SECONDS = 20;

    const MAX_PATH = 255;

    /**
     * Records a poll. $path is the raw X-Chat-Page value: only a path that
     * starts with "/" is kept, cut at "?" or "#", without control
     * characters, at most 255 characters.
     */
    public static function record(Session $session, ?string $path): void
    {
        $path = self::clean($path);
        if ($path === null) {
            return;
        }

        $seen = $session->last_seen_at;
        if ($seen !== null && $path === $session->page_path
            && $seen->diffInSeconds(now(), false) <= self::WRITE_SECONDS) {
            return;
        }

        $now = now();
        Session::where('id', $session->id)->update(['last_seen_at' => $now, 'page_path' => $path]);
        $session->last_seen_at = $now;
        $session->page_path = $path;
    }

    public static function clean(?string $path): ?string
    {
        if ($path === null || !mb_check_encoding($path, 'UTF-8')) {
            return null;
        }

        $path = preg_split('/[?#]/', $path, 2)[0];
        $path = preg_replace('/[\x00-\x1F\x7F]/', '', $path);

        if ($path === '' || $path[0] !== '/') {
            return null;
        }

        return mb_substr($path, 0, self::MAX_PATH, 'UTF-8');
    }

    /**
     * The line for agents, or null when no browser attached to the
     * conversation ever polled.
     */
    public static function line(Conversation $conversation): ?string
    {
        $ids = Access::where('conversation_id', $conversation->id)->pluck('session_id')->all();
        if (!$ids) {
            return null;
        }

        $session = Session::whereIn('id', $ids)
            ->whereNotNull('last_seen_at')
            ->orderBy('last_seen_at', 'desc')
            ->first();

        if (!$session) {
            return null;
        }

        if ($session->last_seen_at->diffInSeconds(now(), false) <= self::ONLINE_SECONDS) {
            return $session->page_path !== null && $session->page_path !== ''
                ? __('On the site now · :path', ['path' => $session->page_path])
                : __('On the site now');
        }

        return __('Left :when', ['when' => $session->last_seen_at->diffForHumans()]);
    }
}
