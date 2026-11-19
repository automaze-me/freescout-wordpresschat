<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\Thread;
use Illuminate\Support\Facades\Queue;
use Modules\WordPressChat\Jobs\SendChatEmail;
use Modules\WordPressChat\Services\ReplyChannel;
use Modules\WordPressChat\Tests\TestCase;

/**
 * FreeScout 1.8.251 (#5799) adds "Also send reply to customer by email" to
 * the reply form of chat conversations, ticked by default. On our
 * conversations the "Send via" picker decides alone, so the checkbox must not
 * add a second delivery by email.
 *
 * These go through core's real send_reply request, so core's own handling of
 * the checkbox runs before our hook, exactly as in production. The checkbox
 * is posted ticked, as from a page where the module JS did not hide it.
 */
class CoreChatEmailCheckboxTest extends TestCase
{
    private function chatWithCustomerEmail(): Conversation
    {
        $chat = $this->startChat($this->makeSite());
        $conversation = Conversation::find($chat['conversation']->id);
        $conversation->customer->addEmail('anna@example.test');

        return Conversation::find($conversation->id);
    }

    private function sendReply(Conversation $conversation, string $choice): void
    {
        $response = $this->actingAs($this->adminUser())->post('/conversation/ajax', [
            'action'          => 'send_reply',
            'mailbox_id'      => $conversation->mailbox_id,
            'conversation_id' => $conversation->id,
            'body'            => '<p>Your parcel left today.</p>',
            'chat_send_email' => 1,
            'wpc_channel'     => $choice,
        ], ['X-Requested-With' => 'XMLHttpRequest']);

        $response->assertStatus(200);
        $this->assertSame('success', $response->json()['status'] ?? null, json_encode($response->json()));
    }

    private function lastReply(Conversation $conversation): Thread
    {
        return Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_MESSAGE)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    private function requireCoreCheckbox(): void
    {
        if (!defined(Thread::class.'::META_CHAT_SEND_EMAIL')) {
            $this->markTestSkipped('This core has no "Also send reply to customer by email" checkbox (before 1.8.251).');
        }
    }

    public function test_a_chat_reply_is_not_also_emailed_by_core()
    {
        $this->requireCoreCheckbox();
        $conversation = $this->chatWithCustomerEmail();

        Queue::fake();
        $this->sendReply($conversation, ReplyChannel::CHAT);

        $reply = $this->lastReply($conversation);
        // Core's checkbox branch ran: it addressed the reply to the customer.
        $this->assertSame(['anna@example.test'], $reply->getToArray());
        // Its flag is gone, so core delivers the reply through the chat only.
        $this->assertNull($reply->getMeta(Thread::META_CHAT_SEND_EMAIL));
        $this->assertNull($reply->getMeta(ReplyChannel::META));

        Queue::assertPushed(\App\Jobs\TriggerAction::class, function ($job) {
            return $job->action === 'chat_conversation.send_reply';
        });
        Queue::assertNotPushed(\App\Jobs\SendReplyToCustomer::class);
        Queue::assertNotPushed(SendChatEmail::class);
    }

    public function test_an_email_choice_is_sent_once_by_the_module()
    {
        $this->requireCoreCheckbox();
        $conversation = $this->chatWithCustomerEmail();

        Queue::fake();
        $this->sendReply($conversation, ReplyChannel::EMAIL);

        $reply = $this->lastReply($conversation);
        $this->assertNull($reply->getMeta(Thread::META_CHAT_SEND_EMAIL));
        $this->assertSame(ReplyChannel::EMAIL, $reply->getMeta(ReplyChannel::META));

        Queue::assertPushed(SendChatEmail::class, 1);
        Queue::assertNotPushed(\App\Jobs\SendReplyToCustomer::class);
        Queue::assertNotPushed(\App\Jobs\TriggerAction::class, function ($job) {
            return $job->action === 'chat_conversation.send_reply';
        });
    }

    /**
     * Tripwire: the module drops core's flag by its constant and skips that
     * on cores without it. If a newer core renamed the constant, the skip
     * would hide the double delivery again, so fail loudly instead.
     */
    public function test_cores_from_1_8_251_define_the_flag_the_module_drops()
    {
        if (version_compare(\Config::get('app.version'), '1.8.251', '<')) {
            $this->markTestSkipped('Core before 1.8.251.');
        }

        $this->assertTrue(
            defined(Thread::class.'::META_CHAT_SEND_EMAIL'),
            'Core no longer defines Thread::META_CHAT_SEND_EMAIL: check how it now flags "Also send reply to customer by email".'
        );
    }
}
