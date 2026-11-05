<?php

namespace Modules\WordPressChat\Services;

use App\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Level;

/**
 * The single gate for what a visitor's browser may receive (spec §6.4).
 * Every widget read goes through this class: the messages through query(),
 * the conversation and identity summary through status().
 *
 * A session sees a conversation only through a grant (grants()), derived
 * from its Access row there, its own identity and the conversation's
 * state:
 * - a verified browser (a redeemed link) sees every customer-facing row,
 *   from both channels;
 * - any other browser sees chat rows only, and once the conversation has a
 *   cut-off, only rows below it plus its own messages (rules 3, 6, 8);
 * - on a conversation bound to a WordPress account, an unverified browser
 *   needs a fresh login of that account, or it sees nothing at all.
 *
 * Cursor contract: the widget polls with `after` set to the highest id it
 * has received and gets the visible rows with a greater id, in id order.
 * Ids are allocated per row on insert, not in commit order: a row can
 * become visible after a row with a higher id was already returned (an
 * agent reply published by the queue while a visitor message is being
 * stored). A poll in that window moves the cursor past it and that browser
 * never receives it, so `after` is a best-effort cursor. InboundMessage
 * narrows the window by allocating its id as the last write before commit.
 * An email row is published long after its id was allocated (an email
 * choice once its email went out): a poll also returns the email rows it
 * may see that were published in the last EMAIL_POLL_WINDOW seconds, below
 * the cursor too; the widget skips ids it already shows.
 * Cut-offs compare ids the same way: a row allocated below the cut-off but
 * committed after it is still shown to cut-off browsers.
 */
class Visibility
{
    /**
     * Capabilities this server offers, for a widget newer or older than it.
     * Every answer that carries `identity` carries them too.
     */
    const FEATURES = ['page'];

    /**
     * Seconds a closed conversation keeps the widget polling. "Reply &
     * Close" flips the status at once, but the closing reply is published
     * only after core's undo window (about 15 s) has passed; stopping on the
     * first `closed` would lose that reply.
     */
    const CLOSED_POLL_GRACE = 120;

    /**
     * Seconds a published email row stays in every poll, whatever its
     * cursor (see the class comment).
     */
    const EMAIL_POLL_WINDOW = 120;

    /**
     * The session's grants, keyed by conversation id (ascending). Each is
     * ['level' => Level::*, 'full' => bool, 'cutoff' => ?int,
     * 'notice' => ?string]; a conversation without a grant is absent.
     */
    public static function grants(Session $session): array
    {
        return self::resolve($session)['grants'];
    }

    /**
     * The grant of one Access row, or null when it grants nothing. `full`
     * means every customer-facing row; otherwise chat rows only, and with a
     * `cutoff` only rows below it plus the session's own messages. `notice`
     * tells a cut-off browser why it sees nothing new.
     */
    public static function grantFor(Access $access, Session $session, ConversationState $state): ?array
    {
        $level = AccessLevel::forAccess($access, $session, $state);
        $full = $level === Level::VERIFIED;

        // An account-bound chat is shown to unverified browsers only while
        // they hold a fresh login of that account: never after a logout, a
        // lapsed login, or a different account on the same browser.
        if (!$full && (int) $state->wp_user_id > 0 && $level !== Level::ACCOUNT) {
            return null;
        }

        $cutoff = null;
        $notice = null;

        if (!$full && $state->cutoff_message_id !== null) {
            $cutoff = (int) $state->cutoff_message_id;
            $notice = (int) $state->cutoff_reason === ConversationState::CUTOFF_VERIFIED
                ? 'continues_elsewhere'
                : 'continues_by_email';
        }

        return [
            'level'  => $level,
            'full'   => $full,
            'cutoff' => $cutoff,
            'notice' => $notice,
        ];
    }

    /**
     * The messages the session may receive, ordered by id ascending. Each
     * direction is paired with the only thread type it may carry, and only
     * published threads pass (agent rows also need published_at), so a
     * stray row on a note, draft or line item can never leak.
     *
     * $after is the poll's cursor: rows above it, plus the email rows
     * published within EMAIL_POLL_WINDOW (see the class comment). Other
     * callers pass none.
     */
    public static function query(Session $session, ?int $after = null): Builder
    {
        $grants = self::grants($session);
        $query = Message::query();

        if (!$grants) {
            return $query->whereRaw('1 = 0')->orderBy('wordpresschat_messages.id');
        }

        if ($after !== null) {
            $query->where(function ($q) use ($after) {
                $q->where('wordpresschat_messages.id', '>', $after)
                    ->orWhere(function ($q) {
                        $q->where('wordpresschat_messages.via', Message::VIA_EMAIL)
                            ->where('wordpresschat_messages.published_at', '>=', now()->subSeconds(self::EMAIL_POLL_WINDOW));
                    });
            });
        }

        return $query
            ->where(function ($q) use ($grants, $session) {
                foreach ($grants as $conversationId => $grant) {
                    $q->orWhere(function ($q) use ($conversationId, $grant, $session) {
                        $q->where('wordpresschat_messages.conversation_id', $conversationId);

                        if ($grant['full']) {
                            return;
                        }

                        $q->where('wordpresschat_messages.via', Message::VIA_CHAT);

                        if ($grant['cutoff'] === null) {
                            return;
                        }

                        $q->where(function ($q) use ($grant, $session) {
                            $q->where('wordpresschat_messages.id', '<', $grant['cutoff'])
                                ->orWhere(function ($q) use ($session) {
                                    $q->where('wordpresschat_messages.direction', Message::DIRECTION_IN)
                                        ->where('wordpresschat_messages.session_id', $session->id);
                                });
                        });
                    });
                }
            })
            ->where(function ($q) {
                $q->where(function ($q) {
                    $q->where('wordpresschat_messages.direction', Message::DIRECTION_IN)
                        ->whereHas('thread', function ($t) {
                            $t->where('state', \App\Thread::STATE_PUBLISHED)
                                ->where('type', \App\Thread::TYPE_CUSTOMER);
                        });
                })->orWhere(function ($q) {
                    $q->where('wordpresschat_messages.direction', Message::DIRECTION_OUT)
                        ->whereNotNull('wordpresschat_messages.published_at')
                        ->whereHas('thread', function ($t) {
                            $t->where('state', \App\Thread::STATE_PUBLISHED)
                                ->where('type', \App\Thread::TYPE_MESSAGE);
                        });
                });
            })
            ->orderBy('wordpresschat_messages.id');
    }

    /**
     * The conversation this browser is in, or null when it sees none: the
     * session's current conversation (current_conversation_id, set by its
     * last message, a redeemed link, a login that bound its chat, or account
     * discovery) while it still has a grant there; otherwise the granted
     * conversation with the highest id.
     */
    public static function latestConversation(Session $session): ?Conversation
    {
        $resolved = self::resolve($session);
        $id = self::currentId($session, $resolved);

        return $id === null ? null : $resolved['conversations'][$id];
    }

    /**
     * The widget's summary for the session, from the conversation it is in
     * (latestConversation()): `identity` {level, account} and `conversation`
     * {status, poll, key, notice}, or null when it sees none. Then the level
     * comes from the session alone (Ruling C5). `account` says whether the
     * browser holds a fresh account login, which the widget ends on logout.
     * `limits` {attachment_mb} is the largest file the widget may send
     * (ChatAttachment::effectiveLimitMb(), Ruling E3).
     */
    public static function status(Session $session): array
    {
        $resolved = self::resolve($session);
        $conversation = null;
        $level = null;
        $id = self::currentId($session, $resolved);

        if ($id !== null) {
            $conv = $resolved['conversations'][$id];
            $grant = $resolved['grants'][$id];
            $level = $grant['level'];

            $conversation = [
                'status' => (int) $conv->status === Conversation::STATUS_CLOSED ? 'closed' : 'open',
                'poll'   => self::keepPolling($conv),
                'key'    => (string) $resolved['states'][$id]->public_id,
                'notice' => $grant['notice'],
            ];
        }

        if ($level === null) {
            $level = AccessLevel::forSession($session);
        }

        return [
            'identity'     => [
                'level'   => Level::name($level),
                'account' => $session->accountFresh(),
            ],
            'conversation' => $conversation,
            'limits'       => [
                'attachment_mb' => ChatAttachment::effectiveLimitMb($session->site ?: new Site()),
            ],
            'features'     => self::FEATURES,
        ];
    }

    /**
     * The id latestConversation() returns, from resolve()'s result.
     */
    private static function currentId(Session $session, array $resolved): ?int
    {
        if (!$resolved['grants']) {
            return null;
        }

        $current = (int) $session->current_conversation_id;

        if ($current > 0 && isset($resolved['grants'][$current])) {
            return $current;
        }

        return max(array_keys($resolved['grants']));
    }

    /**
     * Grants with the conversations and states they were derived from, all
     * keyed by conversation id. A conversation missing from the database
     * grants nothing. A conversation without a state gets one (of()).
     */
    private static function resolve(Session $session): array
    {
        $result = ['grants' => [], 'conversations' => [], 'states' => []];
        $rows = Access::where('session_id', $session->id)->get();

        if ($rows->isEmpty()) {
            return $result;
        }

        $ids = $rows->pluck('conversation_id')->all();
        $conversations = Conversation::whereIn('id', $ids)->get()->keyBy('id');
        $states = ConversationState::whereIn('conversation_id', $ids)->get()->keyBy('conversation_id');

        foreach ($rows as $access) {
            $id = (int) $access->conversation_id;
            $conversation = $conversations->get($id);

            if (!$conversation) {
                continue;
            }

            $state = $states->get($id) ?: ConversationState::of($conversation);
            $grant = self::grantFor($access, $session, $state);

            if ($grant === null) {
                continue;
            }

            $result['grants'][$id] = $grant;
            $result['conversations'][$id] = $conversation;
            $result['states'][$id] = $state;
        }

        ksort($result['grants']);

        return $result;
    }

    /**
     * Whether the widget should keep polling this conversation: while it is
     * active or pending, and for CLOSED_POLL_GRACE seconds after it was
     * closed (see the constant). Not for spam, a conversation closed earlier
     * or without a closed_at, or one that is no longer published (deleted).
     */
    private static function keepPolling(Conversation $conv): bool
    {
        if ((int) $conv->state !== Conversation::STATE_PUBLISHED) {
            return false;
        }

        $status = Conversation::toMainStatus($conv->status);

        if ($status === Conversation::STATUS_ACTIVE || $status === Conversation::STATUS_PENDING) {
            return true;
        }

        if ($status === Conversation::STATUS_CLOSED && $conv->closed_at) {
            return $conv->closed_at->gt(now()->subSeconds(self::CLOSED_POLL_GRACE));
        }

        return false;
    }
}
