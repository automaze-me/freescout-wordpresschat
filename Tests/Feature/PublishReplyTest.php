<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Thread;
use Modules\WordPressChat\Entities\Message;
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
}
