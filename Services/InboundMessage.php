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
        $body = nl2br(e($text, true));

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

                $customer = VisitorCustomer::forSession($session);

                $conversation = self::continuable($session);
                $isNew = $conversation === null;

                if ($isNew) {
                    $conversation = self::createConversation($site, $customer, $text);

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
     * The session's latest visible conversation, unless it was closed or is
     * no longer published (deleted, merged away): then the next message
     * starts a new conversation. Active, Pending and Spam ones continue,
     * also after a cut-off (spec §6.4 rule 6). A conversation the session
     * no longer sees (an account chat after the login lapsed) is never
     * continued.
     */
    public static function continuable(Session $session): ?Conversation
    {
        $conversation = Visibility::latestConversation($session);

        if (!$conversation
            || $conversation->status == Conversation::STATUS_CLOSED
            || $conversation->state != Conversation::STATE_PUBLISHED
        ) {
            return null;
        }

        return $conversation;
    }

    private static function createConversation(Site $site, Customer $customer, string $text): Conversation
    {
        $conversation = new Conversation();
        $conversation->type                   = Conversation::TYPE_CHAT;
        $conversation->subject                = Conversation::subjectFromText($text);
        $conversation->mailbox_id             = $site->mailbox_id;
        $conversation->customer_id            = $customer->id;
        $conversation->customer_email         = '';
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
     * caller together with the new thread.
     */
    private static function reactivate(Conversation $conversation)
    {
        if ($conversation->status == Conversation::STATUS_ACTIVE || $conversation->status == Conversation::STATUS_SPAM) {
            return;
        }

        $oldStatus = $conversation->status;
        $conversation->status = \Eventy::filter('conversation.status_changing', Conversation::STATUS_ACTIVE, $conversation);

        if ($conversation->status != $oldStatus) {
            $conversation->updateFolder();
        }
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
