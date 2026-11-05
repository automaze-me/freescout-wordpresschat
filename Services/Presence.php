<?php

namespace Modules\WordPressChat\Services;

use App\Conversation;
use Illuminate\Support\Facades\DB;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\ConversationState;
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
     * The line for agents, or null when no browser with a live grant on the
     * conversation ever polled. One query: the polled sessions with their
     * Access row and the conversation's state, newest poll first; the first
     * that Visibility::grantFor() still grants counts (a browser that logged
     * out of an account chat no longer does).
     */
    public static function line(Conversation $conversation): ?string
    {
        $rows = DB::table((new Access())->getTable().' as a')
            ->join((new Session())->getTable().' as s', 's.id', '=', 'a.session_id')
            ->leftJoin((new ConversationState())->getTable().' as c', 'c.conversation_id', '=', 'a.conversation_id')
            ->where('a.conversation_id', $conversation->id)
            ->whereNotNull('s.last_seen_at')
            ->orderBy('s.last_seen_at', 'desc')
            ->orderBy('s.id', 'desc')
            ->get([
                'a.id as a_id', 'a.session_id as a_session_id', 'a.conversation_id as a_conversation_id',
                'a.verified_at as a_verified_at',
                's.id as s_id', 's.site_id as s_site_id', 's.wp_user_id as s_wp_user_id',
                's.account_until as s_account_until', 's.identity as s_identity',
                's.last_seen_at as s_last_seen_at', 's.page_path as s_page_path',
                'c.wp_user_id as c_wp_user_id', 'c.site_id as c_site_id',
                'c.cutoff_message_id as c_cutoff_message_id', 'c.cutoff_reason as c_cutoff_reason',
            ]);

        foreach ($rows as $row) {
            $access = (new Access())->newFromBuilder([
                'id'              => $row->a_id,
                'session_id'      => $row->a_session_id,
                'conversation_id' => $row->a_conversation_id,
                'verified_at'     => $row->a_verified_at,
            ]);
            $session = (new Session())->newFromBuilder([
                'id'            => $row->s_id,
                'site_id'       => $row->s_site_id,
                'wp_user_id'    => $row->s_wp_user_id,
                'account_until' => $row->s_account_until,
                'identity'      => $row->s_identity,
                'last_seen_at'  => $row->s_last_seen_at,
                'page_path'     => $row->s_page_path,
            ]);
            // A conversation without a state row yet: an unsaved blank one,
            // so a plain chat is granted and nothing is written.
            $state = (new ConversationState())->newFromBuilder([
                'conversation_id'   => $conversation->id,
                'wp_user_id'        => $row->c_wp_user_id,
                'site_id'           => $row->c_site_id,
                'cutoff_message_id' => $row->c_cutoff_message_id,
                'cutoff_reason'     => $row->c_cutoff_reason,
            ]);

            if (Visibility::grantFor($access, $session, $state) === null) {
                continue;
            }

            if ($session->last_seen_at->diffInSeconds(now(), false) <= self::ONLINE_SECONDS) {
                return __('On the site now · :path', ['path' => $session->page_path]);
            }

            return __('Left :when', ['when' => $session->last_seen_at->diffForHumans()]);
        }

        return null;
    }
}
