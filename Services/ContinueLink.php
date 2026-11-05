<?php

namespace Modules\WordPressChat\Services;

use App\Conversation;
use Illuminate\Support\Facades\DB;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Link;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Exceptions\SessionGoneException;

/**
 * "Continue this chat" links (spec §6.2). The fallback emails carry one;
 * the widget redeems it only on an explicit click, through POST continue
 * (the token sits in a URL fragment, Ruling P11, so it never reaches
 * WordPress, proxies or page caches). Redeeming verifies that browser for
 * the conversation and cuts off every other unverified one (§6.4 rule 8).
 */
class ContinueLink
{
    /** Days a link stays redeemable. */
    const TTL_DAYS = 30;

    /**
     * Mints a fresh link for $conversation and returns its URL on the
     * conversation's website. Only the token's SHA-256 is stored.
     *
     * @throws \RuntimeException when the conversation has no website (a
     *                           conversation from before Plan 2 that no
     *                           browser ever wrote in, or a deleted site)
     */
    public static function mint(Conversation $conversation): string
    {
        // A plain read, never of(): a failed mint must write nothing.
        $state = ConversationState::where('conversation_id', $conversation->id)->first();
        $site = $state ? Site::find($state->site_id) : null;

        if (!$site) {
            throw new \RuntimeException('WordPressChat: conversation #'.$conversation->id.' has no website for a continue link.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        Link::create([
            'site_id'         => $site->id,
            'conversation_id' => $conversation->id,
            'token_hash'      => hash('sha256', $token),
            'expires_at'      => now()->addDays(self::TTL_DAYS),
        ]);

        return rtrim($site->url, '/').'/#lcff-continue='.$token;
    }

    /**
     * The link $token names on $site while it can still be redeemed:
     * unused, not expired, and its conversation still exists outside the
     * trash (an agent deleted it, or core trashed it when merging it into
     * another). Read only; the API checks it before it creates a session
     * for a new browser.
     */
    public static function find(Site $site, string $token): ?Link
    {
        $link = Link::where('token_hash', hash('sha256', $token))
            ->where('site_id', $site->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$link || !Conversation::where('id', $link->conversation_id)
            ->where('state', Conversation::STATE_PUBLISHED)
            ->exists()
        ) {
            return null;
        }

        return $link;
    }

    /**
     * Whether $token names a link of $site that $session already redeemed
     * (a retry after a lost answer) and that has not expired since, and whose
     * conversation is not trashed: either answers 410 like any other. Read only.
     */
    public static function usedBy(Site $site, string $token, Session $session): bool
    {
        return Link::where('token_hash', hash('sha256', $token))
            ->where('site_id', $site->id)
            ->where('used_by_session_id', $session->id)
            ->where('expires_at', '>', now())
            ->whereIn('conversation_id', function ($q) {
                $q->select('id')
                    ->from((new Conversation())->getTable())
                    ->where('state', Conversation::STATE_PUBLISHED);
            })
            ->exists();
    }

    /**
     * Redeems $token for the browser behind $session. Returns the
     * conversation, or null when the link is unknown, used, expired, of
     * another site or of a trashed conversation (find()). Then nothing is
     * written.
     *
     * Everything runs in one transaction whose first statement locks the
     * session row (Ruling C10), with no SQL try/catch inside it:
     * 1. the single-use claim, a conditional update, so of two concurrent
     *    redemptions exactly one goes on;
     * 2. the session's Access row on the conversation gets verified_at. The
     *    row is looked up first rather than inserted and caught: the lock
     *    serialises this with every other writer of the session's Access
     *    rows (InboundMessage, VisitorIdentity::login);
     * 3. rule 8: the conversation's cut-off, unless it already has one
     *    (the first cut-off wins, as in RecordCustomerEmail);
     * 4. the conversation becomes the session's current one, so the next
     *    message continues it (Visibility::latestConversation());
     * 5. the session's customer becomes the conversation's customer, and
     *    its email the conversation's customer_email, unless the session
     *    holds a fresh account login: it keeps the account's customer;
     * 6. the line item "Email verified via link".
     *
     * @throws SessionGoneException when the session row is gone (the orphan
     *                              cleanup, or deleted with its site);
     *                              nothing is written
     */
    public static function redeem(Site $site, Session $session, string $token): ?Conversation
    {
        $link = self::find($site, $token);
        $conversation = $link ? Conversation::find($link->conversation_id) : null;

        if (!$conversation) {
            return null;
        }

        // Outside the transaction: of() may insert, and it only recovers
        // from a concurrent insert outside one (Ruling C4).
        $state = ConversationState::of($conversation);

        $redeemed = DB::transaction(function () use ($session, $link, $conversation, $state) {
            $locked = Session::where('id', $session->id)->lockForUpdate()->first();

            if (!$locked) {
                throw new SessionGoneException();
            }

            $now = now();

            $claimed = Link::where('id', $link->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', $now)
                ->update([
                    'used_at'            => $now,
                    'used_by_session_id' => $locked->id,
                ]) === 1;

            if (!$claimed) {
                return false;
            }

            $access = Access::where('session_id', $locked->id)
                ->where('conversation_id', $conversation->id)
                ->first();

            if (!$access) {
                Access::create([
                    'session_id'      => $locked->id,
                    'conversation_id' => $conversation->id,
                    'verified_at'     => $now,
                ]);
            } elseif ($access->verified_at === null) {
                $access->verified_at = $now;
                $access->save();
            }

            // Rule 8: every row from now on is hidden from the unverified
            // browsers (each keeps seeing its own messages, Ruling P3).
            ConversationState::where('id', $state->id)
                ->whereNull('cutoff_message_id')
                ->update([
                    'cutoff_message_id' => (int) Message::where('conversation_id', $conversation->id)->max('id') + 1,
                    'cutoff_reason'     => ConversationState::CUTOFF_VERIFIED,
                ]);

            $locked->current_conversation_id = $conversation->id;

            if ((int) $conversation->customer_id > 0 && !$locked->accountFresh()) {
                $email = (string) $conversation->customer_email;
                $locked->customer_id = $conversation->customer_id;
                $locked->email = $email !== '' ? $email : null;
            }

            $locked->save();

            IdentityLineItem::create($conversation, __('Email verified via link'));

            return true;
        });

        return $redeemed ? $conversation : null;
    }
}
