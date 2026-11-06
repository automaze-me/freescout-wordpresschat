<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Thread;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Services\VisitorAuth;
use Modules\WordPressChat\Tests\TestCase;
use Modules\WordPressChat\WordPressChat;

class PublishReplyTest extends TestCase
{
    private function conversation($channel = null)
    {
        $mailbox  = $this->testMailbox();
        $customer = Customer::createWithoutEmail(['first_name' => 'Vis', 'last_name' => 'Itor']);
        $folder   = $mailbox->folders()->first();

        $conversation = new Conversation();
        $conversation->type        = Conversation::TYPE_CHAT;
        $conversation->channel     = $channel === null ? WordPressChat::CHANNEL : $channel;
        $conversation->mailbox_id  = $mailbox->id;
        $conversation->folder_id   = $folder->id;
        $conversation->customer_id = $customer->id;
        $conversation->status      = Conversation::STATUS_ACTIVE;
        $conversation->state       = Conversation::STATE_PUBLISHED;
        $conversation->source_via  = Conversation::PERSON_CUSTOMER;
        $conversation->source_type = Conversation::SOURCE_TYPE_API;
        $conversation->subject     = 'Chat';
        $conversation->preview     = '';
        $conversation->save();

        return $conversation;
    }

    private function thread(Conversation $conversation, $type, $state = null)
    {
        $agent = $this->adminUser();

        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type            = $type;
        $thread->status          = Thread::STATUS_ACTIVE;
        $thread->state           = $state === null ? Thread::STATE_PUBLISHED : $state;
        $thread->body            = '<p>Hello</p>';
        $thread->source_type     = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id     = $conversation->customer_id;
        if ($type == Thread::TYPE_MESSAGE) {
            $thread->user_id            = $agent->id;
            $thread->created_by_user_id = $agent->id;
            $thread->source_via         = Thread::PERSON_USER;
        } else {
            $thread->source_via = Thread::PERSON_CUSTOMER;
        }
        $thread->save();

        return $thread;
    }

    private function fire($conversation, array $threads)
    {
        \Eventy::action('chat_conversation.send_reply', $conversation, collect($threads), $conversation->customer);
    }

    private function rows($conversation)
    {
        return Message::where('conversation_id', $conversation->id)->get();
    }

    public function test_published_agent_reply_is_recorded()
    {
        $conv     = $this->conversation();
        $customer = $this->thread($conv, Thread::TYPE_CUSTOMER);
        $agent    = $this->thread($conv, Thread::TYPE_MESSAGE);

        $this->fire($conv, [$agent, $customer]);

        $row = Message::where('thread_id', $agent->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(Message::DIRECTION_OUT, (int) $row->direction);
        $this->assertSame((int) $conv->id, (int) $row->conversation_id);
        $this->assertNull($row->session_id);
        $this->assertNotNull($row->published_at);
        $this->assertSame(1, $this->rows($conv)->count());
    }

    public function test_only_first_reply_is_published()
    {
        $conv  = $this->conversation();
        $older = $this->thread($conv, Thread::TYPE_MESSAGE);
        $newer = $this->thread($conv, Thread::TYPE_MESSAGE);

        $this->fire($conv, [$newer, $older]);

        $this->assertNotNull(Message::where('thread_id', $newer->id)->first());
        $this->assertNull(Message::where('thread_id', $older->id)->first());
    }

    public function test_undone_reply_is_not_published()
    {
        $conv  = $this->conversation();
        $agent = $this->thread($conv, Thread::TYPE_MESSAGE);
        $agent->state = Thread::STATE_DRAFT;
        $agent->save();

        $this->fire($conv, [$agent]);

        $this->assertSame(0, $this->rows($conv)->count());
    }

    public function test_other_channels_are_ignored()
    {
        $conv  = $this->conversation(105);
        $agent = $this->thread($conv, Thread::TYPE_MESSAGE);

        $this->fire($conv, [$agent]);

        $this->assertSame(0, $this->rows($conv)->count());
    }

    public function test_repeat_fire_does_not_change_published_at()
    {
        $conv  = $this->conversation();
        $agent = $this->thread($conv, Thread::TYPE_MESSAGE);

        $this->fire($conv, [$agent]);
        $row = Message::where('thread_id', $agent->id)->first();
        $original = '2020-01-02 03:04:05';
        $row->published_at = $original;
        $row->save();

        $this->fire($conv, [$agent]);

        $this->assertSame(1, $this->rows($conv)->count());
        $this->assertSame($original, Message::find($row->id)->published_at->format('Y-m-d H:i:s'));
    }

    public function test_customer_thread_first_never_touches_its_in_row()
    {
        $conv     = $this->conversation();
        $customer = $this->thread($conv, Thread::TYPE_CUSTOMER);
        $in = Message::create([
            'conversation_id' => $conv->id,
            'thread_id'       => $customer->id,
            'direction'       => Message::DIRECTION_IN,
            'published_at'    => '2020-01-02 03:04:05',
        ]);

        $this->fire($conv, [$customer]);

        $this->assertSame(1, $this->rows($conv)->count());
        $fresh = Message::find($in->id);
        $this->assertSame(Message::DIRECTION_IN, (int) $fresh->direction);
        $this->assertSame('2020-01-02 03:04:05', $fresh->published_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, Message::where('direction', Message::DIRECTION_OUT)->where('conversation_id', $conv->id)->count());
    }

    public function test_republishing_an_email_choice_row_as_chat_resets_it()
    {
        $conv  = $this->conversation();
        $agent = $this->thread($conv, Thread::TYPE_MESSAGE);
        Message::create([
            'conversation_id' => $conv->id,
            'thread_id'       => $agent->id,
            'direction'       => Message::DIRECTION_OUT,
            'via'             => Message::VIA_EMAIL,
            'email_status'    => Message::EMAIL_QUEUED,
            'email_thread_id' => $agent->id,
            'published_at'    => now(),
        ]);

        $this->fire($conv, [$agent]);

        $row = Message::where('thread_id', $agent->id)->first();
        $this->assertSame(Message::VIA_CHAT, (int) $row->via);
        $this->assertNull($row->email_status);
        $this->assertNull($row->email_thread_id);
        $this->assertNotNull($row->fallback_due_at);
    }

    public function test_a_lost_row_is_not_published()
    {
        $conv  = $this->conversation();
        $agent = $this->thread($conv, Thread::TYPE_MESSAGE);
        Message::create([
            'conversation_id' => $conv->id,
            'thread_id'       => $agent->id,
            'direction'       => Message::DIRECTION_OUT,
            'lost_at'         => now(),
        ]);

        $this->fire($conv, [$agent]);

        $row = Message::where('thread_id', $agent->id)->first();
        $this->assertNull($row->published_at);
        $this->assertNull($row->fallback_due_at);
    }

    /**
     * Only between one browser's chats (Ruling P5); a merge into another
     * visitor's chat fails the reply (DeliveryHardeningTest).
     */
    public function test_row_follows_a_thread_merged_between_one_browsers_chats()
    {
        $a = $this->conversation();
        $b = $this->conversation();
        $session = VisitorAuth::claim($this->makeSite(), $this->newChatToken());
        Access::create(['session_id' => $session->id, 'conversation_id' => $a->id]);
        Access::create(['session_id' => $session->id, 'conversation_id' => $b->id]);
        $agent = $this->thread($a, Thread::TYPE_MESSAGE);
        $agent->conversation_id = $b->id;
        $agent->save();

        $this->fire($a, [$agent]);

        $row = Message::where('thread_id', $agent->id)->first();
        $this->assertSame((int) $b->id, (int) $row->conversation_id);
    }

    public function test_a_throwing_save_is_caught()
    {
        $conv  = $this->conversation();
        $agent = $this->thread($conv, Thread::TYPE_MESSAGE);

        Message::saving(function () {
            throw new \RuntimeException('boom');
        });

        try {
            $this->fire($conv, [$agent]);
        } finally {
            Message::flushEventListeners();
        }

        $this->assertSame(0, $this->rows($conv)->count());
    }

    public function test_refiring_an_emailed_chat_reply_changes_nothing()
    {
        $conv  = $this->conversation();
        $agent = $this->thread($conv, Thread::TYPE_MESSAGE);
        $past  = now()->subHour()->startOfSecond();
        $row = Message::create([
            'conversation_id' => $conv->id,
            'thread_id'       => $agent->id,
            'direction'       => Message::DIRECTION_OUT,
            'via'             => Message::VIA_CHAT,
            'published_at'    => $past,
            'fallback_due_at' => $past,
            'email_status'    => Message::EMAIL_SENT,
            'email_thread_id' => $agent->id,
        ]);

        $this->fire($conv, [$agent]);

        $fresh = $row->fresh();
        $this->assertSame(Message::EMAIL_SENT, (int) $fresh->email_status);
        $this->assertSame((int) $agent->id, (int) $fresh->email_thread_id);
        $this->assertSame($past->timestamp, $fresh->fallback_due_at->timestamp);
        $this->assertSame($past->timestamp, $fresh->published_at->timestamp);
    }

    private function mergeRedItems(Conversation $conversation)
    {
        return Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_LINEITEM)
            ->get()
            ->filter(function ($thread) {
                return (bool) $thread->getMeta(\Modules\WordPressChat\Services\FailureLineItem::FAILURE_META);
            })
            ->values();
    }

    /**
     * Review Focus 1: one shared browser, two accounts. X holds both chats
     * but is logged in as V, so it has no grant on U's chat S. A reply
     * written on S and published after S merged into T must not reach V's
     * other devices.
     */
    public function test_late_reply_after_an_account_switch_on_a_shared_browser_fails_safe()
    {
        list(, $source, $target) = $this->sharedBrowserChats(7, 8, 8);
        $reply = $this->thread($source, Thread::TYPE_MESSAGE);

        $this->assertTrue($target->mergeConversations(Conversation::find($source->id), $this->adminUser()));
        $this->fire($source, [$reply]);

        $rows = Message::where('thread_id', $reply->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(Message::EMAIL_FAILED, (int) $rows[0]->email_status);
        $this->assertNull($rows[0]->published_at);
        $this->assertSame((int) $source->id, (int) $rows[0]->conversation_id);
        $this->assertCount(0, $this->rows($target)->where('thread_id', $reply->id));
        $this->assertCount(1, $this->mergeRedItems($target));
    }

    /** The positive control: the same account on both chats, login live. */
    public function test_late_reply_for_the_same_account_with_a_live_login_publishes()
    {
        list(, $source, $target) = $this->sharedBrowserChats(7, 7, 7);
        $reply = $this->thread($source, Thread::TYPE_MESSAGE);

        $this->assertTrue($target->mergeConversations(Conversation::find($source->id), $this->adminUser()));
        $this->fire($source, [$reply]);

        $row = Message::where('thread_id', $reply->id)->firstOrFail();
        $this->assertSame((int) $target->id, (int) $row->conversation_id);
        $this->assertNotNull($row->published_at);
        $this->assertNull($row->email_status);
        $this->assertCount(0, $this->mergeRedItems($target));
    }

    /** The reverse direction: the live login is S's account, T has no grant. */
    public function test_late_reply_when_the_login_belongs_to_the_source_account_fails_safe()
    {
        list(, $source, $target) = $this->sharedBrowserChats(7, 8, 7);
        $reply = $this->thread($source, Thread::TYPE_MESSAGE);

        $this->assertTrue($target->mergeConversations(Conversation::find($source->id), $this->adminUser()));
        $this->fire($source, [$reply]);

        $this->assertFailsSafe($source, $target, $reply);
    }

    /** Both chats are one account's, but the browser's login of that account has lapsed. */
    public function test_late_reply_for_the_same_account_after_the_login_lapsed_fails_safe()
    {
        list(, $source, $target, $session) = $this->sharedBrowserChats(7, 7, 7);
        $session->account_until = now()->subMinute();
        $session->save();
        $reply = $this->thread($source, Thread::TYPE_MESSAGE);

        $this->assertTrue($target->mergeConversations(Conversation::find($source->id), $this->adminUser()));
        $this->fire($source, [$reply]);

        $this->assertFailsSafe($source, $target, $reply);
    }

    private function assertFailsSafe(Conversation $source, Conversation $target, Thread $reply): void
    {
        $rows = Message::where('thread_id', $reply->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(Message::EMAIL_FAILED, (int) $rows[0]->email_status);
        $this->assertNull($rows[0]->published_at);
        $this->assertSame((int) $source->id, (int) $rows[0]->conversation_id);
        $this->assertCount(0, $this->rows($target)->where('thread_id', $reply->id));
        $this->assertCount(1, $this->mergeRedItems($target));
    }
}
