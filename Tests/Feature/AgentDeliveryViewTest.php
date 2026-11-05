<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\Thread;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Services\DeliveryMarker;
use Modules\WordPressChat\Tests\TestCase;

/**
 * Agent view: the "Send via" picker (spec §8.3) and the delivery marker under
 * every agent reply (spec §8.2, Ruling D11). Renders the hooks directly: GET
 * conversations.view 500s in tests.
 */
class AgentDeliveryViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DeliveryMarker::clearCache();
    }

    private function chat(?string $email = 'anna@example.test'): Conversation
    {
        $chat = $this->startChat($this->makeSite());
        $conversation = Conversation::find($chat['conversation']->id);
        $conversation->customer_email = (string) $email;
        $conversation->save();

        return $conversation;
    }

    private function picker(Conversation $conversation): string
    {
        ob_start();
        \Eventy::action('reply_form.after', $conversation);

        return ob_get_clean();
    }

    private function agentThread(Conversation $conversation, int $type = Thread::TYPE_MESSAGE): Thread
    {
        $agent = $this->adminUser();

        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->type               = $type;
        $thread->status             = Thread::STATUS_ACTIVE;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->body               = '<p>Reply</p>';
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->source_via         = Thread::PERSON_USER;
        $thread->user_id            = $agent->id;
        $thread->created_by_user_id = $agent->id;
        $thread->save();

        return $thread;
    }

    private function marker(Conversation $conversation, Thread $thread): string
    {
        ob_start();
        \Eventy::action('thread.meta', $thread, 0, collect([$thread]), $conversation, $conversation->mailbox);

        return ob_get_clean();
    }

    // ---- Picker ----

    public function test_picker_defaults_to_website_chat()
    {
        $html = $this->picker($this->chat());

        $this->assertStringContainsString('data-wpc-picker="1"', $html);
        $this->assertStringContainsString('Send via:', $html);
        $this->assertMatchesRegularExpression('/<input type="radio" name="wpc_channel_choice" value="chat" checked>/', $html);
        $this->assertMatchesRegularExpression('/<input type="radio" name="wpc_channel_choice" value="email">/', $html);
        $this->assertStringNotContainsString('disabled', $html);
        $this->assertStringNotContainsString('No email address is known', $html);
        $this->assertStringNotContainsString('The visitor continues by email.', $html);
    }

    public function test_picker_disables_email_without_an_address()
    {
        $html = $this->picker($this->chat(null));

        $this->assertMatchesRegularExpression('/value="email" disabled>/', $html);
        $this->assertMatchesRegularExpression('/value="chat" checked>/', $html);
        $this->assertStringContainsString('No email address is known for this visitor.', $html);
    }

    public function test_picker_locks_to_email()
    {
        $conversation = $this->chat();
        $state = ConversationState::where('conversation_id', $conversation->id)->firstOrFail();
        $state->cutoff_message_id = (int) Message::max('id') + 1;
        $state->cutoff_reason = ConversationState::CUTOFF_EMAIL;
        $state->save();

        $html = $this->picker($conversation);

        $this->assertMatchesRegularExpression('/value="chat" disabled>/', $html);
        $this->assertStringContainsString('wpc-disabled', $html);
        $this->assertMatchesRegularExpression('/value="email" checked>/', $html);
        $this->assertStringContainsString('The visitor continues by email.', $html);
    }

    public function test_no_picker_on_other_channels()
    {
        $conversation = $this->chat();
        $conversation->channel = 1;
        $conversation->save();

        $this->assertSame('', trim($this->picker($conversation)));
    }

    /** Final review M1: core's forward keeps channel 106 on its email copy. */
    public function test_no_picker_on_a_forwarded_copy()
    {
        $copy = $this->chat()->replicate();
        $copy->type = Conversation::TYPE_EMAIL;
        $copy->threads_count = 0;
        $copy->save();
        $copy = Conversation::find($copy->id);
        $this->assertSame(\Modules\WordPressChat\WordPressChat::CHANNEL, (int) $copy->channel);

        $this->assertSame('', trim($this->picker($copy)));
    }

    // ---- Markers ----

    public static function markerCases(): array
    {
        $past = 'PAST'; // resolved to a timestamp in the test: the app is not booted here
        $B = Message::VIA_CHAT;
        $E = Message::VIA_EMAIL;

        return [
            'waiting'                     => [['via' => $B, 'published_at' => $past], 'Waiting to be seen', 'glyphicon-time'],
            'seen'                        => [['via' => $B, 'published_at' => $past, 'seen_at' => $past], 'Seen in chat', 'glyphicon-ok'],
            'fallback queued'             => [['via' => $B, 'published_at' => $past, 'email_status' => Message::EMAIL_QUEUED], 'Sending by email (visitor had left the page)', 'glyphicon-envelope'],
            'fallback sending'            => [['via' => $B, 'published_at' => $past, 'email_status' => Message::EMAIL_SENDING], 'Sending by email (visitor had left the page)', 'glyphicon-envelope'],
            'fallback sent'               => [['via' => $B, 'published_at' => $past, 'email_status' => Message::EMAIL_SENT], 'Sent by email (visitor had left the page)', 'glyphicon-envelope'],
            'fallback sent then seen'     => [['via' => $B, 'published_at' => $past, 'seen_at' => $past, 'email_status' => Message::EMAIL_SENT], 'Sent by email (visitor had left the page)', 'glyphicon-envelope'],
            'choice queued'               => [['via' => $E, 'email_status' => Message::EMAIL_QUEUED], "Sending by email (agent's choice)", 'glyphicon-envelope'],
            'choice sending'              => [['via' => $E, 'email_status' => Message::EMAIL_SENDING], "Sending by email (agent's choice)", 'glyphicon-envelope'],
            'choice sent'                 => [['via' => $E, 'email_status' => Message::EMAIL_SENT], "Sent by email (agent's choice)", 'glyphicon-envelope'],
            'failed fallback'             => [['via' => $B, 'published_at' => $past, 'email_status' => Message::EMAIL_FAILED], 'Email failed', 'glyphicon-exclamation-sign text-danger'],
            'failed choice'               => [['via' => $E, 'email_status' => Message::EMAIL_FAILED], 'Email failed', 'glyphicon-exclamation-sign text-danger'],
            'lost'                        => [['via' => $B, 'lost_at' => $past], 'Not delivered to the chat', 'glyphicon-exclamation-sign text-danger'],
            'lost beats failed'           => [['via' => $B, 'lost_at' => $past, 'email_status' => Message::EMAIL_FAILED], 'Not delivered to the chat', 'glyphicon-exclamation-sign text-danger'],
            'sent beats seen'             => [['via' => $B, 'seen_at' => $past, 'email_status' => Message::EMAIL_SENT], 'Sent by email (visitor had left the page)', 'glyphicon-envelope'],
            'seen beats sending'          => [['via' => $B, 'published_at' => $past, 'seen_at' => $past, 'email_status' => Message::EMAIL_SENDING], 'Seen in chat', 'glyphicon-ok'],
        ];
    }

    /**
     * @dataProvider markerCases
     */
    public function test_markers(array $attrs, string $text, string $icon)
    {
        $conversation = $this->chat();
        $thread = $this->agentThread($conversation);
        foreach ($attrs as $k => $v) {
            $attrs[$k] = $v === 'PAST' ? now()->subMinute() : $v;
        }
        Message::create(array_merge([
            'conversation_id' => $conversation->id,
            'thread_id'       => $thread->id,
            'direction'       => Message::DIRECTION_OUT,
        ], $attrs));

        $html = $this->marker($conversation, $thread);

        $this->assertSame(
            '<div class="thread-meta"><i class="glyphicon '.$icon.'"></i> '.e($text).'</div>',
            $html
        );
    }

    public static function nothingToReport(): array
    {
        return [
            'undone email choice' => [['via' => Message::VIA_EMAIL]],
            'unpublished reply'   => [['via' => Message::VIA_CHAT]],
        ];
    }

    /**
     * @dataProvider nothingToReport
     */
    public function test_a_row_with_nothing_to_report_has_no_marker(array $attrs)
    {
        $conversation = $this->chat();
        $thread = $this->agentThread($conversation);
        Message::create(array_merge([
            'conversation_id' => $conversation->id,
            'thread_id'       => $thread->id,
            'direction'       => Message::DIRECTION_OUT,
        ], $attrs));

        $this->assertSame('', $this->marker($conversation, $thread));
    }

    public function test_thread_without_a_row_has_no_marker()
    {
        $conversation = $this->chat();

        $this->assertSame('', $this->marker($conversation, $this->agentThread($conversation)));
    }

    public function test_notes_and_other_channels_have_no_marker()
    {
        $conversation = $this->chat();
        $note = $this->agentThread($conversation, Thread::TYPE_NOTE);
        Message::create(['conversation_id' => $conversation->id, 'thread_id' => $note->id,
            'direction' => Message::DIRECTION_OUT, 'via' => Message::VIA_CHAT, 'published_at' => now()]);
        $this->assertSame('', $this->marker($conversation, $note));

        $other = $this->chat();
        $thread = $this->agentThread($other);
        Message::create(['conversation_id' => $other->id, 'thread_id' => $thread->id,
            'direction' => Message::DIRECTION_OUT, 'via' => Message::VIA_CHAT, 'published_at' => now()]);
        $other->channel = 1;
        $other->save();
        DeliveryMarker::clearCache();
        $this->assertSame('', $this->marker($other, $thread));
    }

    // ---- Assets ----

    public function test_assets_are_registered()
    {
        $js = \Eventy::filter('javascripts', []);
        $css = \Eventy::filter('stylesheets', []);

        $this->assertNotEmpty(array_filter($js, function ($p) {
            return substr($p, -strlen('modules/wordpresschat/js/wordpresschat.js')) === 'modules/wordpresschat/js/wordpresschat.js';
        }));
        $this->assertNotEmpty(array_filter($css, function ($p) {
            return substr($p, -strlen('modules/wordpresschat/css/wordpresschat.css')) === 'modules/wordpresschat/css/wordpresschat.css';
        }));
    }
}
