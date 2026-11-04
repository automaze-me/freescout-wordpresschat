<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\Thread;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Jobs\SendChatEmail;
use Modules\WordPressChat\Listeners\RouteReplyChannel;
use Modules\WordPressChat\Services\CoreReplyMailer;
use Modules\WordPressChat\Services\ReplyChannel;
use Modules\WordPressChat\Tests\FakeCoreReplyMailer;
use Modules\WordPressChat\Tests\TestCase;

/**
 * Spec §8.3: an agent may send a reply by email instead of the chat. The
 * choice is captured at request time and routed when core sends the reply
 * (Rulings D5, D7).
 */
class AgentEmailChoiceTest extends TestCase
{
    private function chat(?string $email = 'anna@example.test'): array
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $conversation = Conversation::find($chat['conversation']->id);
        $conversation->customer_email = (string) $email;
        $conversation->save();

        return [$site, $chat['token'], $conversation, $chat['session']];
    }

    private function agentThread(Conversation $conversation, int $type = Thread::TYPE_MESSAGE): Thread
    {
        $agent = $this->adminUser();

        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->type               = $type;
        $thread->status             = Thread::STATUS_ACTIVE;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->body               = '<p>Sensitive</p>';
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->source_via         = Thread::PERSON_USER;
        $thread->customer_id        = $conversation->customer_id;
        $thread->user_id            = $agent->id;
        $thread->created_by_user_id = $agent->id;
        $thread->save();

        return $thread;
    }

    private function capture(Thread $thread, string $channel): Thread
    {
        RouteReplyChannel::capture($thread, new Request(['wpc_channel' => $channel]));
        $thread->save();

        return Thread::find($thread->id);
    }

    private function skip(Conversation $conversation, Thread $thread, $skip = false)
    {
        return \Eventy::filter('conversation.skip_send_reply_to_customer', $skip, $conversation, collect([$thread]));
    }

    private function fake($outcome = null): FakeCoreReplyMailer
    {
        $mailer = new FakeCoreReplyMailer($outcome);
        app()->instance(CoreReplyMailer::class, $mailer);

        return $mailer;
    }

    private function poll($site, string $token): array
    {
        $response = $this->api('GET', $site, '/messages', $token);
        $response->assertStatus(200);

        return $response->json();
    }

    private function cutOff(Conversation $conversation, int $reason): void
    {
        $state = ConversationState::where('conversation_id', $conversation->id)->firstOrFail();
        $state->cutoff_message_id = (int) Message::max('id') + 1;
        $state->cutoff_reason = $reason;
        $state->save();
    }

    private function metaOf(Thread $thread): ?string
    {
        return Thread::find($thread->id)->getMeta(ReplyChannel::META);
    }

    public function test_email_choice_queues_an_email_and_no_chat_publish()
    {
        list($site, $token, $conversation) = $this->chat();
        $thread = $this->capture($this->agentThread($conversation), 'email');
        $this->assertSame('email', $this->metaOf($thread));

        Queue::fake();
        $this->assertTrue($this->skip($conversation, $thread));

        Queue::assertPushedOn('emails', SendChatEmail::class, function ($job) use ($conversation, $thread) {
            $seconds = $job->delay->diffInSeconds(now(), false) * -1;

            return $job->conversationId === (int) $conversation->id
                && $job->threadId === (int) $thread->id
                && abs($seconds - Conversation::UNDO_TIMOUT) <= 2;
        });

        $row = Message::where('thread_id', $thread->id)->firstOrFail();
        $this->assertSame(Message::VIA_EMAIL, (int) $row->via);
        $this->assertSame(Message::DIRECTION_OUT, (int) $row->direction);
        $this->assertSame(Message::EMAIL_QUEUED, (int) $row->email_status);
        $this->assertSame((int) $thread->id, (int) $row->email_thread_id);
        $this->assertNull($row->published_at);
        $this->assertNull($row->fallback_due_at);
        $this->assertNull($row->session_id);

        $this->assertNotContains((int) $row->id, array_column($this->poll($site, $token)['messages'], 'id'));
    }

    public function test_email_choice_is_sent_and_shown_to_verified_browsers_only()
    {
        list($site, $token, $conversation, $session) = $this->chat();
        $thread = $this->capture($this->agentThread($conversation), 'email');
        $mailer = $this->fake();

        Queue::fake();
        $this->skip($conversation, $thread);
        $job = Queue::pushed(SendChatEmail::class)->first();
        app()->call([$job, 'handle']);

        $this->assertCount(1, $mailer->calls);
        $this->assertSame((int) $thread->id, $mailer->calls[0]['first_reply_id']);

        $row = Message::where('thread_id', $thread->id)->firstOrFail();
        $this->assertSame(Message::EMAIL_SENT, (int) $row->email_status);
        $this->assertNotNull($row->published_at);

        $this->assertNotContains((int) $row->id, array_column($this->poll($site, $token)['messages'], 'id'));

        Access::where('session_id', $session->id)->update(['verified_at' => now()]);
        $messages = $this->poll($site, $token)['messages'];
        $ids = array_column($messages, 'id');
        $this->assertContains((int) $row->id, $ids);
        $this->assertSame('email', $messages[array_search((int) $row->id, $ids)]['via']);
    }

    public function test_undone_email_choice_sends_nothing_and_can_go_as_chat()
    {
        list(, , $conversation) = $this->chat();
        $thread = $this->capture($this->agentThread($conversation), 'email');
        $mailer = $this->fake();

        Queue::fake();
        $this->skip($conversation, $thread);
        $job = Queue::pushed(SendChatEmail::class)->first();

        $thread->state = Thread::STATE_DRAFT;
        $thread->save();
        app()->call([$job, 'handle']);

        $this->assertCount(0, $mailer->calls);
        $row = Message::where('thread_id', $thread->id)->firstOrFail();
        $this->assertNull($row->email_status);

        $thread->state = Thread::STATE_PUBLISHED;
        $thread->save();
        $thread = $this->capture(Thread::find($thread->id), 'chat');
        $this->assertNull($this->metaOf($thread));
        $this->assertFalse($this->skip($conversation, $thread));

        \Eventy::action('chat_conversation.send_reply', $conversation, collect([$thread]), $conversation->customer);
        $row = Message::where('thread_id', $thread->id)->firstOrFail();
        $this->assertSame(Message::VIA_CHAT, (int) $row->via);
        $this->assertNotNull($row->published_at);
        $this->assertNull($row->email_status);
    }

    public function test_email_choice_without_an_address_stays_chat()
    {
        list(, , $conversation) = $this->chat(null);
        $this->assertFalse(ReplyChannel::emailAvailable($conversation));

        $thread = $this->capture($this->agentThread($conversation), 'email');

        $this->assertNull($this->metaOf($thread));
        Queue::fake();
        $this->assertFalse($this->skip($conversation, $thread));
        Queue::assertNothingPushed();
    }

    public function test_locked_conversation_forces_email()
    {
        list(, , $conversation) = $this->chat();
        $this->cutOff($conversation, ConversationState::CUTOFF_EMAIL);

        $this->assertTrue(ReplyChannel::locked($conversation));
        $this->assertSame(ReplyChannel::EMAIL, ReplyChannel::defaultChoice($conversation));

        $thread = $this->capture($this->agentThread($conversation), 'chat');
        $this->assertSame('email', $this->metaOf($thread));
    }

    public function test_locked_without_an_address_cannot_force_email()
    {
        list(, , $conversation) = $this->chat(null);
        $this->cutOff($conversation, ConversationState::CUTOFF_EMAIL);

        $thread = $this->capture($this->agentThread($conversation), 'chat');
        $this->assertNull($this->metaOf($thread));
    }

    public function test_a_verified_browser_unlocks_chat()
    {
        list(, , $conversation, $session) = $this->chat();
        $this->cutOff($conversation, ConversationState::CUTOFF_EMAIL);
        Access::where('session_id', $session->id)->update(['verified_at' => now()]);

        $this->assertFalse(ReplyChannel::locked($conversation));
        $this->assertSame(ReplyChannel::CHAT, ReplyChannel::defaultChoice($conversation));

        $thread = $this->capture($this->agentThread($conversation), 'chat');
        $this->assertNull($this->metaOf($thread));
    }

    public function test_other_channels_and_notes_are_untouched()
    {
        list(, , $conversation) = $this->chat();

        $note = $this->capture($this->agentThread($conversation, Thread::TYPE_NOTE), 'email');
        $this->assertNull($this->metaOf($note));

        $other = $this->agentThread($conversation);
        $other->setMeta(ReplyChannel::META, 'email');
        $other->save();
        Conversation::where('id', $conversation->id)->update(['channel' => 1]);
        $other = Thread::find($other->id);
        $conversation = Conversation::find($conversation->id);

        RouteReplyChannel::capture($other, new Request(['wpc_channel' => 'chat']));
        $this->assertSame('email', $other->getMeta(ReplyChannel::META));

        Queue::fake();
        $this->assertFalse($this->skip($conversation, $other));
        Queue::assertNothingPushed();
    }

    public function test_skip_already_true_is_kept()
    {
        list(, , $conversation) = $this->chat();
        $thread = $this->capture($this->agentThread($conversation), 'email');

        Queue::fake();
        $this->assertTrue($this->skip($conversation, $thread, true));
        Queue::assertNothingPushed();
        $this->assertNull(Message::where('thread_id', $thread->id)->first());
    }

    public function test_a_reply_without_the_choice_is_left_to_core()
    {
        list(, , $conversation) = $this->chat();
        $thread = $this->capture($this->agentThread($conversation), 'chat');

        Queue::fake();
        $this->assertFalse($this->skip($conversation, $thread));
        Queue::assertNothingPushed();
    }
}
