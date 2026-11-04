<?php

namespace Modules\WordPressChat\Services;

use App\Conversation;
use App\Customer;
use App\Events\CustomerCreatedConversation;
use App\Events\CustomerReplied;
use App\Thread;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Exceptions\MessageConflictException;
use Modules\WordPressChat\WordPressChat;

class InboundMessage
{
    /**
     * Store a visitor's chat message as a customer thread of a Website chat
     * conversation. $text must already be trimmed and validated.
     *
     * Idempotent per (session, client_message_id): a retry with the same
     * text returns the stored Message (its wasRecentlyCreated is false) and
     * fires nothing; a different text throws MessageConflictException.
     */
    public static function receive(Site $site, Session $session, string $text, string $clientMessageId): Message
    {
        $body = self::bodyFor($text);

        $existing = self::find($session, $clientMessageId);

        if ($existing) {
            return self::replay($existing, $body);
        }

        $customer     = null;
        $message      = null;
        $conversation = null;
        $thread       = null;
        $isNew        = false;

        // The catch wraps the whole transaction rather than sitting inside
        // it: on PostgreSQL a failed statement aborts the transaction, so
        // nothing may run in it after the unique index fires. Here the
        // transaction is rolled back first, then the winner is re-read.
        try {
            DB::transaction(function () use (
                $site, $session, $text, $body, $clientMessageId,
                &$customer, &$message, &$conversation, &$thread, &$isNew
            ) {
                // First statement: serialises this session's messages, so
                // two concurrent first sends cannot both create a customer
                // or a conversation. The locked row is the one used below.
                $session = Session::where('id', $session->id)->lockForUpdate()->first();

                // A lapsed login lets go of the account's customer, so a new
                // chat by whoever now sits at this browser is never filed
                // under the account holder (as on logout).
                if ((int) $session->identity === Session::IDENTITY_NONE
                    && (int) $session->wp_user_id > 0
                    && !$session->accountFresh()
                ) {
                    VisitorIdentity::forgetAccount($session);
                }

                // The conversation this browser is in; a closed one is
                // followed by a new conversation.
                $latest = Visibility::latestConversation($session);
                $conversation = self::continuableFrom($session, $latest);
                $isNew = $conversation === null;

                // A browser verified on the target conversation writes as
                // that conversation's customer; any other as the session's.
                $customer = $isNew ? null : self::verifiedCustomer($session, $conversation);
                if (!$customer) {
                    $customer = VisitorCustomer::forSession($session);
                }

                if ($isNew) {
                    $conversation = self::createConversation(
                        $site, $customer, $text, self::customerEmail($session, $customer, $latest)
                    );

                    Access::create([
                        'session_id'      => $session->id,
                        'conversation_id' => $conversation->id,
                    ]);

                    // A plain insert, never ConversationState::of(): the
                    // conversation is new, so nothing can race it, and a
                    // catch inside this transaction would be useless on
                    // PostgreSQL (Ruling C4). A fresh account login binds
                    // the conversation to that account.
                    ConversationState::create([
                        'conversation_id' => $conversation->id,
                        'site_id'         => $site->id,
                        'public_id'       => bin2hex(random_bytes(16)),
                        'wp_user_id'      => $session->accountFresh() ? (int) $session->wp_user_id : null,
                    ]);
                } else {
                    self::reactivate($conversation);
                }

                // The browser's current conversation is where its last
                // message went (Visibility::latestConversation()).
                if ((int) $session->current_conversation_id !== (int) $conversation->id) {
                    $session->current_conversation_id = $conversation->id;
                    $session->save();
                }

                $thread = new Thread();
                $thread->conversation_id        = $conversation->id;
                $thread->user_id                = $conversation->user_id;
                $thread->type                   = Thread::TYPE_CUSTOMER;
                $thread->status                 = $conversation->status;
                $thread->state                  = Thread::STATE_PUBLISHED;
                $thread->body                   = $body;
                $thread->source_via             = Thread::PERSON_CUSTOMER;
                $thread->source_type            = Thread::SOURCE_TYPE_WEB;
                $thread->customer_id            = $customer->id;
                $thread->created_by_customer_id = $customer->id;
                if ($isNew) {
                    $thread->first = true;
                }
                $thread->save();

                // Core's order: last reply and preview are set before the
                // filter runs. setPreview() gets the raw text, because the
                // preview strips tags but never decodes entities.
                $conversation->last_reply_at   = now();
                $conversation->last_reply_from = Conversation::PERSON_CUSTOMER;
                $conversation->setPreview($text);

                $conversation = \Eventy::filter(
                    $isNew ? 'conversation.created_by_customer' : 'conversation.customer_replied',
                    $conversation, $thread, $customer
                );

                $conversation->save();

                // The last write before commit, so the message id is
                // allocated as late as possible. That narrows the window in
                // which an agent reply published by the queue takes a
                // higher id but commits first: a poll in that window moves
                // its `after` cursor past this message, and the visitor's
                // other tabs never show it. Ids are still not strictly
                // commit-ordered.
                //
                // The unique (session_id, client_message_id) index is the
                // real duplicate guard: a concurrent request with the same
                // id that committed after our lookup makes this throw.
                $message = Message::create([
                    'conversation_id'   => $conversation->id,
                    'thread_id'         => $thread->id,
                    'session_id'        => $session->id,
                    'direction'         => Message::DIRECTION_IN,
                    'via'               => Message::VIA_CHAT,
                    'client_message_id' => $clientMessageId,
                    'published_at'      => now(),
                ]);
            });
        } catch (QueryException $e) {
            $raced = self::find($session, $clientMessageId);

            if (!$raced) {
                throw $e;
            }

            // The concurrent request stored the message and fires its events.
            return self::replay($raced, $body);
        }

        self::fireEvents($conversation, $thread, $customer, $isNew, $message);

        return $message;
    }

    /**
     * The stored form of a visitor's text: escaped HTML with a literal
     * apostrophe, because core's plain-text email part drops "&#039;"
     * (upstream #5793, Ruling D13).
     */
    public static function bodyFor(string $text): string
    {
        return nl2br(str_replace('&#039;', "'", e($text, true)));
    }

    private static function find(Session $session, string $clientMessageId)
    {
        return Message::where('session_id', $session->id)
            ->where('client_message_id', $clientMessageId)
            ->first();
    }

    /**
     * The same client_message_id again: a retry when the text matches, a
     * conflict otherwise. Bodies are compared because the text is stored
     * only as its escaped body, and escaping is deterministic.
     */
    private static function replay(Message $existing, string $body): Message
    {
        $thread = $existing->thread;

        if (!$thread || (string) $thread->body !== $body) {
            throw new MessageConflictException();
        }

        return $existing;
    }

    /**
     * The conversation the browser is in (Visibility::latestConversation()),
     * unless it was closed or is no longer published (deleted, merged away):
     * then the next message starts a new conversation. Active, Pending and
     * Spam ones continue, also after a cut-off (spec §6.4 rule 6). A
     * conversation the session no longer sees (an account chat after the
     * login lapsed) is never continued.
     */
    public static function continuable(Session $session): ?Conversation
    {
        return self::continuableFrom($session, Visibility::latestConversation($session));
    }

    /**
     * openOrNull() plus the declined-stale-chat rule: a fresh account
     * session does not continue an anonymous chat that login declined to
     * bind (VisitorIdentity::login() released its placeholder customer).
     * The chat counts as such when it is not bound to an account, belongs
     * to another customer than the session's, and the session is not
     * verified on it. The next message then starts a new conversation.
     */
    private static function continuableFrom(Session $session, ?Conversation $latest): ?Conversation
    {
        $conversation = self::openOrNull($latest);

        if (!$conversation || !$session->accountFresh()) {
            return $conversation;
        }

        // A plain read: ConversationState::of() would create a row.
        $state = ConversationState::where('conversation_id', $conversation->id)->first();

        if ($state
            && $state->wp_user_id === null
            && (int) $conversation->customer_id !== (int) $session->customer_id
            && !self::isVerified($session, $conversation)
        ) {
            return null;
        }

        return $conversation;
    }

    private static function openOrNull(?Conversation $conversation): ?Conversation
    {
        if (!$conversation
            || $conversation->status == Conversation::STATUS_CLOSED
            || $conversation->state != Conversation::STATE_PUBLISHED
        ) {
            return null;
        }

        return $conversation;
    }

    /**
     * The customer a browser with verified access (a redeemed continue
     * link) writes as in $conversation: the conversation's own, whatever
     * the session's customer_id says. A lapsed login or a logout clears
     * that (VisitorIdentity::forgetAccount()), and the verified browser's
     * messages must not move to a fresh placeholder. Null for any other
     * browser, or when the conversation's customer is gone.
     */
    private static function verifiedCustomer(Session $session, Conversation $conversation): ?Customer
    {
        if (!$conversation->customer_id || !self::isVerified($session, $conversation)) {
            return null;
        }

        return Customer::find($conversation->customer_id);
    }

    private static function isVerified(Session $session, Conversation $conversation): bool
    {
        return Access::where('session_id', $session->id)
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('verified_at')
            ->exists();
    }

    /**
     * The customer_email of a conversation this session starts for
     * $customer. $previous is the conversation the browser was in (closed
     * by now, or null). The first that applies:
     * 1. the session is verified on $previous: its customer_email;
     * 2. the address the session was linked by (sessions.email);
     * 3. the customer's main email;
     * 4. '' (an anonymous placeholder).
     * 1 and 2 only when the address belongs to $customer: a fresh account
     * session that redeemed another customer's link writes as the account.
     *
     * 3 reads what Customer::getMainEmail() returns (the first email row)
     * without its query cache: that caches the customer's email list for
     * the rest of the process, and the session's customer may still get an
     * email later in it (a placeholder upgraded in place).
     */
    private static function customerEmail(Session $session, Customer $customer, ?Conversation $previous): string
    {
        $candidates = [];

        if ($previous && self::isVerified($session, $previous)) {
            $candidates[] = (string) $previous->customer_email;
        }

        $candidates[] = (string) $session->email;

        foreach ($candidates as $email) {
            if ($email !== '' && $customer->emails()->where('email', $email)->exists()) {
                return $email;
            }
        }

        return (string) $customer->emails()->orderBy('id')->value('email');
    }

    private static function createConversation(Site $site, Customer $customer, string $text, string $email): Conversation
    {
        $conversation = new Conversation();
        $conversation->type                   = Conversation::TYPE_CHAT;
        $conversation->subject                = Conversation::subjectFromText($text);
        $conversation->mailbox_id             = $site->mailbox_id;
        $conversation->customer_id            = $customer->id;
        $conversation->customer_email         = $email;
        $conversation->created_by_customer_id = $customer->id;
        $conversation->channel                = WordPressChat::CHANNEL;
        $conversation->source_via             = Conversation::PERSON_CUSTOMER;
        $conversation->source_type            = Conversation::SOURCE_TYPE_WEB;
        $conversation->state                  = Conversation::STATE_PUBLISHED;
        $conversation->status                 = Conversation::STATUS_ACTIVE;
        $conversation->preview                = '';
        $conversation->updateFolder();
        $conversation->save();

        return $conversation;
    }

    /**
     * A visitor message puts a Pending conversation back to Active, so it
     * counts in the folder again. Spam stays Spam. The change goes through
     * core's `conversation.status_changing` filter and is saved by the
     * caller (with the new thread here; FailureLineItem saves it too).
     *
     * Returns whether the status changed.
     */
    public static function reactivate(Conversation $conversation): bool
    {
        if ($conversation->status == Conversation::STATUS_ACTIVE || $conversation->status == Conversation::STATUS_SPAM) {
            return false;
        }

        $oldStatus = $conversation->status;
        $conversation->status = \Eventy::filter('conversation.status_changing', Conversation::STATUS_ACTIVE, $conversation);

        if ($conversation->status == $oldStatus) {
            return false;
        }

        $conversation->updateFolder();

        return true;
    }

    /**
     * After commit: these events drive FreeScout's notifications, auto-reply
     * and workflows. Core drains the notification queue at the end of the
     * HTTP request. A throwing listener must not turn a stored message into
     * an error response, which would make the widget retry.
     */
    private static function fireEvents(Conversation $conversation, Thread $thread, Customer $customer, bool $isNew, Message $message)
    {
        // Two separate tries: a throwing core listener must not skip the
        // Eventy action that modules (and enrichment) hook into.
        try {
            event($isNew
                ? new CustomerCreatedConversation($conversation, $thread)
                : new CustomerReplied($conversation, $thread));
        } catch (\Throwable $e) {
            \Log::error('[WordPressChat] A listener threw on the core event for message id '.$message->id, [
                'exception' => $e,
            ]);
        }

        try {
            \Eventy::action(
                $isNew ? 'conversation.created_by_customer' : 'conversation.customer_replied',
                $conversation, $thread, $customer
            );
        } catch (\Throwable $e) {
            \Log::error('[WordPressChat] A listener threw on the Eventy action for message id '.$message->id, [
                'exception' => $e,
            ]);
        }
    }
}
