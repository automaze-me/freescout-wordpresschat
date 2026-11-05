<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\Thread;
use Carbon\Carbon;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Tests\TestCase;

class DeliveryClockTest extends TestCase
{
    private function agentThread(Conversation $conversation): Thread
    {
        $agent = $this->adminUser();

        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->type               = Thread::TYPE_MESSAGE;
        $thread->status             = Thread::STATUS_ACTIVE;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->body               = '<p>Hi</p>';
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id        = $conversation->customer_id;
        $thread->user_id            = $agent->id;
        $thread->created_by_user_id = $agent->id;
        $thread->source_via         = Thread::PERSON_USER;
        $thread->save();

        return $thread;
    }

    private function publish(Conversation $conversation, Thread $thread): void
    {
        \Eventy::action('chat_conversation.send_reply', $conversation, collect([$thread]), $conversation->customer);
    }

    /** Asserts $actual lies within [$from + $seconds, $to + $seconds]. */
    private function assertDueIn($actual, int $seconds, Carbon $from, Carbon $to): void
    {
        $this->assertNotNull($actual);
        $this->assertGreaterThanOrEqual($from->timestamp + $seconds, $actual->timestamp);
        $this->assertLessThanOrEqual($to->timestamp + $seconds, $actual->timestamp);
    }

    // Time is measured with a tolerance instead of Carbon::setTestNow():
    // a frozen clock breaks the module's model timestamps in this suite.

    public function test_delay_for_runs_one_query()
    {
        $site = $this->makeSite(['fallback_delay' => 300]);
        $conversation = $this->startChat($site)['conversation'];
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $delay = \Modules\WordPressChat\Services\DeliveryClock::delayFor($conversation);
        $count = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertSame(300, $delay);
        $this->assertSame(1, $count);
    }

    public function test_published_reply_is_due_after_the_site_delay()
    {
        $site = $this->makeSite(['fallback_delay' => 300]);
        $chat = $this->startChat($site);
        $thread = $this->agentThread($chat['conversation']);

        $from = Carbon::now();
        $this->publish($chat['conversation'], $thread);
        $to = Carbon::now();

        $row = Message::where('thread_id', $thread->id)->first();
        $this->assertDueIn($row->fallback_due_at, 300, $from, $to);
    }

    public function test_conversation_without_a_state_uses_the_default()
    {
        $site = $this->makeSite(['fallback_delay' => 300]);
        $chat = $this->startChat($site);
        $thread = $this->agentThread($chat['conversation']);
        ConversationState::where('conversation_id', $chat['conversation']->id)->delete();

        $from = Carbon::now();
        $this->publish($chat['conversation'], $thread);
        $to = Carbon::now();

        $row = Message::where('thread_id', $thread->id)->first();
        $this->assertDueIn($row->fallback_due_at, 120, $from, $to);
        $this->assertSame(0, ConversationState::where('conversation_id', $chat['conversation']->id)->count());
    }

    public function test_email_given_later_restarts_the_clock()
    {
        $site = $this->makeSite(['fallback_delay' => 120]);
        $chat = $this->startChat($site);
        $conv = $chat['conversation'];

        $threads = [];
        for ($i = 0; $i < 5; $i++) {
            $threads[] = $this->agentThread($conv);
        }

        // The reply was published 600 s ago, so it was due 480 s ago.
        $this->publish($conv, $threads[0]);
        $past = Carbon::now()->subSeconds(480)->startOfSecond();
        $waiting = Message::where('thread_id', $threads[0]->id)->first();
        $waiting->fallback_due_at = $past;
        $waiting->save();

        $make = function ($thread, array $attrs) use ($conv, $past) {
            return Message::create(array_merge([
                'conversation_id' => $conv->id,
                'thread_id'       => $thread->id,
                'direction'       => Message::DIRECTION_OUT,
                'via'             => Message::VIA_CHAT,
                'published_at'    => $past,
                'fallback_due_at' => $past,
            ], $attrs));
        };
        $seen   = $make($threads[1], ['seen_at' => $past]);
        $queued = $make($threads[2], ['email_status' => Message::EMAIL_QUEUED]);
        $email  = $make($threads[3], ['via' => Message::VIA_EMAIL]);
        $old    = $make($threads[4], ['fallback_due_at' => null]);

        $from = Carbon::now();
        $this->api('POST', $site, '/identity', $chat['token'], ['email' => 'later@example.test'])->assertStatus(200);
        $to = Carbon::now();

        $this->assertDueIn($waiting->fresh()->fallback_due_at, 120, $from, $to);
        foreach ([$seen, $queued, $email] as $row) {
            $this->assertSame($past->timestamp, $row->fresh()->fallback_due_at->timestamp);
        }
        $this->assertNull($old->fresh()->fallback_due_at);
    }
}
