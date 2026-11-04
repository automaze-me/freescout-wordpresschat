<?php

namespace Modules\WordPressChat\Services;

use App\Conversation;
use App\Customer;
use App\CustomerChannel;
use Illuminate\Support\Facades\DB;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Exceptions\AlreadyIdentifiedException;
use Modules\WordPressChat\Level;
use Modules\WordPressChat\WordPressChat;

/**
 * The identity flows. Each runs in one transaction whose first statement
 * locks the session row (Ruling C10), and never catches a failed SQL
 * statement inside it. Payloads come from IdentityPayload, already opened
 * and checked outside any transaction.
 */
class VisitorIdentity
{
    /** Seconds an account login lasts after the last login token (Ruling P4). */
    const ACCOUNT_TTL = 7200;

    /**
     * The visitor typed an email (already sanitised by the caller). Only a
     * browser still at ANONYMOUS on its latest conversation may do this;
     * without a visible conversation, only the session is linked.
     *
     * @throws AlreadyIdentifiedException
     */
    public static function typedEmail(Session $session, string $email): void
    {
        DB::transaction(function () use ($session, $email) {
            $locked = Session::where('id', $session->id)->lockForUpdate()->first();

            $conversation = self::anonymousTarget($locked);
            $customer = CustomerLink::resolve($locked, $email);

            if ($conversation) {
                CustomerLink::attach($conversation, $customer, $locked, $email);
                IdentityLineItem::create($conversation, __('Visitor entered email :email', ['email' => $email]));
            } else {
                $locked->customer_id = $customer->id;
            }

            $locked->identity = Session::IDENTITY_EMAIL;
            $locked->save();
        });
    }

    /**
     * An order number, as resolved by WordPress (IdentityPayload::order()).
     * A found order with a billing email links its customer at level ORDER;
     * anything else only writes "not found" and leaves the identity as it
     * is. Like a typed email, only from ANONYMOUS, and session-level only
     * without a visible conversation.
     *
     * @throws AlreadyIdentifiedException
     */
    public static function order(Session $session, array $payload): void
    {
        DB::transaction(function () use ($session, $payload) {
            $locked = Session::where('id', $session->id)->lockForUpdate()->first();

            $conversation = self::anonymousTarget($locked);
            $number = (string) $payload['num'];
            $email = (string) ($payload['email'] ?? '');

            if (empty($payload['found']) || $email === '') {
                if ($conversation) {
                    IdentityLineItem::create($conversation, __('Order number :number not found', ['number' => $number]));
                }

                return;
            }

            $name = (string) ($payload['name'] ?? '');
            $customer = CustomerLink::resolve($locked, $email, $name);

            if ($conversation) {
                CustomerLink::attach($conversation, $customer, $locked, $email);
                IdentityLineItem::create($conversation, __('Visitor entered order number :number → linked to :name', [
                    'number' => $number,
                    'name'   => $name !== '' ? $name : $email,
                ]));
            } else {
                $locked->customer_id = $customer->id;
            }

            $locked->identity = Session::IDENTITY_ORDER;
            $locked->save();
        });
    }

    /**
     * A WordPress login (IdentityPayload::login()), on the first token and
     * on every refresh:
     * 1. the session holds the account for ACCOUNT_TTL seconds; a session
     *    that held another account first forgets it (forgetAccount());
     * 2. a session without a customer, or with an email-less placeholder,
     *    gets the account's customer (Ruling P9: a placeholder is upgraded
     *    in place with the email and name);
     * 3. the session gets an Access row on every chat of this account on
     *    this site, so every logged-in device sees them (spec §5.5);
     * 4. Ruling P12: the session's open conversation is bound to the
     *    account if it is still anonymous (no account, placeholder
     *    customer). That happens once, so the line item is written once.
     */
    public static function login(Session $session, array $payload): void
    {
        DB::transaction(function () use ($session, $payload) {
            $locked = Session::where('id', $session->id)->lockForUpdate()->first();
            $uid = (int) $payload['uid'];
            $email = (string) $payload['email'];

            if ((int) $locked->wp_user_id > 0 && (int) $locked->wp_user_id !== $uid) {
                self::forgetAccount($locked);
            }

            // Decided before anything changes: the conversation this browser
            // is in right now, not one another device of the account opened.
            $current = $locked->customer_id ? Customer::find($locked->customer_id) : null;
            $bind = null;
            $open = InboundMessage::continuable($locked);

            if ($open
                && $current
                && (int) $open->customer_id === (int) $current->id
                && self::isPlaceholder($current)
            ) {
                $state = ConversationState::of($open);

                if ($state->wp_user_id === null) {
                    $bind = [$open, $state];
                }
            }

            $locked->wp_user_id = $uid;
            $locked->account_until = now()->addSeconds(self::ACCOUNT_TTL);

            $customer = $current;
            if (!$current || self::isPlaceholder($current)) {
                $customer = CustomerLink::resolve($locked, $email, (string) $payload['name']);
            }

            $have = Access::where('session_id', $locked->id)->pluck('conversation_id')->map(function ($id) {
                return (int) $id;
            })->all();
            $chats = ConversationState::where('site_id', $locked->site_id)
                ->where('wp_user_id', $uid)
                ->pluck('conversation_id');

            foreach ($chats as $conversationId) {
                if (!in_array((int) $conversationId, $have, true)) {
                    Access::create([
                        'session_id'      => $locked->id,
                        'conversation_id' => (int) $conversationId,
                    ]);
                }
            }

            if ($bind) {
                list($conversation, $state) = $bind;
                $state->wp_user_id = $uid;
                $state->save();

                // Moves this browser's threads off the placeholder and sets
                // the session's customer.
                CustomerLink::attach($conversation, $customer, $locked, $email);
                IdentityLineItem::create($conversation, __('Logged in as account :email', ['email' => $email]));
            } else {
                $locked->customer_id = $customer->id;
            }

            $locked->save();
        });
    }

    /**
     * After a send that carried a valid login token (Ruling P7): when that
     * send's message started a conversation, which InboundMessage bound to
     * the account, the conversation gets the account email as its
     * customer_email and the "Logged in as account" line item. login()
     * could not do this, as it ran before the conversation existed.
     */
    public static function accountConversationStarted(Session $session, Message $message, array $payload): void
    {
        if (!$message->wasRecentlyCreated || !$message->thread || !$message->thread->first) {
            return;
        }

        DB::transaction(function () use ($session, $message, $payload) {
            $locked = Session::where('id', $session->id)->lockForUpdate()->first();
            $conversation = Conversation::find($message->conversation_id);
            $state = $conversation ? ConversationState::where('conversation_id', $conversation->id)->first() : null;

            if (!$state || (int) $state->wp_user_id !== (int) $payload['uid']) {
                return;
            }

            $customer = Customer::find($conversation->customer_id);
            if ($customer) {
                CustomerLink::attach($conversation, $customer, $locked, (string) $payload['email']);
            }

            IdentityLineItem::create($conversation, __('Logged in as account :email', ['email' => $payload['email']]));
        });
    }

    /**
     * The visitor logged out of WordPress (Ruling P5): see forgetAccount().
     */
    public static function logout(Session $session): void
    {
        DB::transaction(function () use ($session) {
            $locked = Session::where('id', $session->id)->lockForUpdate()->first();

            self::forgetAccount($locked);
        });
    }

    /**
     * Whether the WordPress user $uid has chats on the site (discovery,
     * Ruling P6).
     */
    public static function hasAccountChats(int $siteId, int $uid): bool
    {
        return ConversationState::where('site_id', $siteId)->where('wp_user_id', $uid)->exists();
    }

    /**
     * Ends the session's account login. A session that never gave an
     * identity of its own (identity NONE) also lets go of its customer,
     * which came from the account, and of its Website chat channel row:
     * VisitorCustomer::forSession() would adopt that row's owner again,
     * which is the account customer when the login upgraded the
     * placeholder in place, or the anonymous placeholder of whoever sat
     * here before. So whoever writes next starts under a new placeholder.
     * The caller holds the session lock and passes the locked row.
     */
    public static function forgetAccount(Session $locked): void
    {
        $locked->wp_user_id = null;
        $locked->account_until = null;

        if ((int) $locked->identity === Session::IDENTITY_NONE) {
            CustomerChannel::where('channel', WordPressChat::CHANNEL)
                ->where('channel_id', $locked->public_id)
                ->delete();

            $locked->customer_id = null;
        }

        $locked->save();
    }

    /**
     * The conversation an identity attaches to: the session's latest visible
     * one, or null when it sees none (then only the session is linked). The
     * browser's level there, or the session's own level without one, must
     * still be ANONYMOUS.
     *
     * @throws AlreadyIdentifiedException
     */
    private static function anonymousTarget(Session $locked): ?Conversation
    {
        $conversation = Visibility::latestConversation($locked);

        if ($conversation) {
            $grants = Visibility::grants($locked);
            $level = isset($grants[$conversation->id]) ? $grants[$conversation->id]['level'] : null;
        } else {
            $level = AccessLevel::forSession($locked);
        }

        if ($level !== Level::ANONYMOUS) {
            throw new AlreadyIdentifiedException();
        }

        return $conversation;
    }

    /** Still an anonymous placeholder: a customer without any email (Ruling P9). */
    private static function isPlaceholder(Customer $customer): bool
    {
        return !$customer->emails()->exists();
    }
}
