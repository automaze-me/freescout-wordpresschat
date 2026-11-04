<?php

namespace Modules\WordPressChat\Services;

use App\Conversation;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Level;

/**
 * Identity levels (spec §6.1): of one browser on one conversation, and the
 * badge agents see for the whole conversation.
 */
class AccessLevel
{
    /**
     * The level of the browser behind $session on the conversation of
     * $access. A redeemed link wins; then a fresh login of the account the
     * conversation is bound to; then what the visitor typed.
     */
    public static function forAccess(Access $access, Session $session, ConversationState $state): int
    {
        if ($access->verified_at !== null) {
            return Level::VERIFIED;
        }

        if ((int) $state->wp_user_id > 0
            && (int) $session->wp_user_id === (int) $state->wp_user_id
            && $session->accountFresh()
        ) {
            return Level::ACCOUNT;
        }

        return self::typed($session);
    }

    /**
     * The level of a session that sees no conversation (Ruling C5): a fresh
     * login, else what the visitor typed.
     */
    public static function forSession(Session $session): int
    {
        return $session->accountFresh() ? Level::ACCOUNT : self::typed($session);
    }

    /**
     * Rule 10: the lowest level among the browsers that still have access,
     * that is, a grant that is not cut off. When none has, the lowest among
     * all of them, where a browser on an account-bound conversation counts
     * as ACCOUNT even after its login lapsed. Without any browser, ANONYMOUS.
     */
    public static function badge(Conversation $conversation): int
    {
        $rows = Access::where('conversation_id', $conversation->id)->get();

        if ($rows->isEmpty()) {
            return Level::ANONYMOUS;
        }

        $state = ConversationState::of($conversation);
        $sessions = Session::whereIn('id', $rows->pluck('session_id')->all())->get()->keyBy('id');
        $withAccess = [];
        $all = [];

        foreach ($rows as $access) {
            $session = $sessions->get($access->session_id);

            if (!$session) {
                continue;
            }

            $grant = Visibility::grantFor($access, $session, $state);

            if ($grant !== null && ($grant['full'] || $grant['cutoff'] === null)) {
                $withAccess[] = $grant['level'];
            }

            $level = self::forAccess($access, $session, $state);

            if ($level !== Level::VERIFIED && (int) $state->wp_user_id > 0) {
                $level = Level::ACCOUNT;
            }

            $all[] = $level;
        }

        if ($withAccess) {
            return min($withAccess);
        }

        return $all ? min($all) : Level::ANONYMOUS;
    }

    private static function typed(Session $session): int
    {
        switch ((int) $session->identity) {
            case Session::IDENTITY_EMAIL:
                return Level::EMAIL;
            case Session::IDENTITY_ORDER:
                return Level::ORDER;
            default:
                return Level::ANONYMOUS;
        }
    }
}
