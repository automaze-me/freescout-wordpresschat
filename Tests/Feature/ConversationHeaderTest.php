<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Thread;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Services\ConversationHeader;
use Modules\WordPressChat\Tests\TestCase;

/**
 * Agent view: channel pill, identity badge, order-only warning, "via email".
 * Calls the renderers directly: GET conversations.view 500s in tests.
 */
class ConversationHeaderTest extends TestCase
{
    const WARNING = "Linked by order number only. Don't share personal details in the chat.";

    private function setIdentity(array $chat, int $identity): void
    {
        $chat['session']->identity = $identity;
        $chat['session']->save();
    }

    public function test_thread_chips_cost_one_query()
    {
        $chat = $this->startChat($this->makeSite());
        $conversation = $chat['conversation'];
        ConversationHeader::clearCache();

        $threads = [];
        for ($i = 0; $i < 5; $i++) {
            $thread = new Thread();
            $thread->conversation_id = $conversation->id;
            $thread->type = Thread::TYPE_CUSTOMER;
            $thread->status = Thread::STATUS_ACTIVE;
            $thread->state = Thread::STATE_PUBLISHED;
            $thread->body = 'x';
            $thread->source_type = Thread::SOURCE_TYPE_WEB;
            $thread->customer_id = $conversation->customer_id;
            $thread->created_by_customer_id = $conversation->customer_id;
            $thread->source_via = Thread::PERSON_CUSTOMER;
            $thread->save();
            $threads[] = $thread;
        }
        Message::create([
            'conversation_id' => $conversation->id,
            'thread_id'       => $threads[2]->id,
            'direction'       => Message::DIRECTION_IN,
            'via'             => Message::VIA_EMAIL,
        ]);

        $chips = [];
        $table = (new Message())->getTable();
        $selects = $this->selectsOn($table, function () use ($threads, &$chips) {
            foreach ($threads as $thread) {
                $chips[] = ConversationHeader::threadChip($thread);
            }
        });

        $this->assertCount(1, $selects, implode("\n", $selects));
        $this->assertSame(['', '', 'via email', '', ''], array_map(function ($chip) {
            return trim(strip_tags($chip));
        }, $chips));
    }

    public function test_non_chat_conversation_renders_nothing()
    {
        $conversation = $this->startChat($this->makeSite())['conversation'];
        $conversation->channel = 1; // email
        $conversation->save();

        $this->assertSame('', ConversationHeader::render($conversation));
    }

    public function test_site_name_shown_only_with_several_sites_and_never_the_pill_text()
    {
        $site = $this->makeSite(['name' => 'Shop One']);
        $chat = $this->startChat($site);

        $html = ConversationHeader::render($chat['conversation']);
        $this->assertStringNotContainsString('Website chat', $html);
        $this->assertStringNotContainsString('Shop One', $html);

        $this->makeSite(['name' => 'Shop Two']);

        $html = ConversationHeader::render($chat['conversation']);
        $this->assertStringNotContainsString('Website chat', $html);
        $this->assertStringContainsString('Shop One', $html);
        $this->assertStringNotContainsString('Shop Two', $html);
    }

    public function test_badge_follows_the_lowest_level()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $conversation = $chat['conversation'];

        $cases = [
            [Session::IDENTITY_NONE, 'Anonymous', false],
            [Session::IDENTITY_EMAIL, 'Email not verified', false],
            [Session::IDENTITY_ORDER, 'Order number only', true],
        ];

        foreach ($cases as list($identity, $text, $warns)) {
            $this->setIdentity($chat, $identity);
            $html = ConversationHeader::render($conversation);
            $this->assertStringContainsString($text, $html);
            $this->assertSame($warns, strpos($html, e(self::WARNING)) !== false, $text);
        }

        Access::where('conversation_id', $conversation->id)->update(['verified_at' => now()]);
        $html = ConversationHeader::render($conversation);
        $this->assertStringContainsString('Email verified', $html);
        $this->assertStringNotContainsString(e(self::WARNING), $html);

        // A fresh login of the account the chat is bound to: the account badge.
        $account = $this->startChat($site);
        $this->api('POST', $site, '/identity', $account['token'], ['login_token' => $this->loginToken($site, $account['token'], ['uid' => 42])])
            ->assertStatus(200);
        $this->assertSame(42, (int) ConversationState::of($account['conversation']->fresh())->wp_user_id);
        $html = ConversationHeader::render($account['conversation']->fresh());
        $this->assertStringContainsString('Logged-in account', $html);
        $this->assertStringNotContainsString(e(self::WARNING), $html);
    }

    public function test_mode_hooks_echo_once_per_mode()
    {
        $chat = $this->startChat($this->makeSite());
        $conversation = $chat['conversation'];
        $mailbox = $conversation->mailbox;
        $header = ConversationHeader::render($conversation);
        $this->assertNotSame('', $header);

        // Core's isInChatMode() also asks for the conversation view route.
        $router = app('router');
        $current = new \ReflectionProperty($router, 'current');
        $current->setAccessible(true);
        $before = $current->getValue($router);
        $route = new \Illuminate\Routing\Route('GET', 'conversation/{id}', []);
        $route->name('conversations.view');
        $current->setValue($router, $route);

        $run = function ($hook) use ($conversation, $mailbox, $header) {
            ob_start();
            try {
                \Eventy::action($hook, $conversation, $mailbox);
            } finally {
                $out = ob_get_clean();
            }

            return substr_count($out, $header);
        };

        try {
            \Helper::setChatMode(true);
            $this->assertSame(1, $run('conversation.after_subject'), 'chat mode: after_subject');
            $this->assertSame(0, $run('conversation.after_subject_block'), 'chat mode: after_subject_block');

            \Helper::setChatMode(false);
            $this->assertSame(0, $run('conversation.after_subject'), 'email mode: after_subject');
            $this->assertSame(1, $run('conversation.after_subject_block'), 'email mode: after_subject_block');
        } finally {
            \Helper::setChatMode(false);
            $current->setValue($router, $before);
        }
    }

    public function test_site_name_is_escaped()
    {
        $site = $this->makeSite(['name' => '<b>x</b>']);
        $chat = $this->startChat($site);
        $this->makeSite(['name' => 'Other']);

        $html = ConversationHeader::render($chat['conversation']);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function test_email_thread_gets_the_chip_and_chat_thread_does_not()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $conversation = $chat['conversation'];

        $chatThread = Thread::find(Message::where('conversation_id', $conversation->id)->firstOrFail()->thread_id);

        $email = new Thread();
        $email->conversation_id = $conversation->id;
        $email->type = Thread::TYPE_CUSTOMER;
        $email->status = Thread::STATUS_ACTIVE;
        $email->state = Thread::STATE_PUBLISHED;
        $email->body = '<p>Hi</p>';
        $email->source_type = Thread::SOURCE_TYPE_EMAIL;
        $email->source_via = Thread::PERSON_CUSTOMER;
        $email->customer_id = $conversation->customer_id;
        $email->created_by_customer_id = $conversation->customer_id;
        $email->save();
        Message::create([
            'conversation_id' => $conversation->id,
            'thread_id'       => $email->id,
            'direction'       => Message::DIRECTION_IN,
            'via'             => Message::VIA_EMAIL,
            'published_at'    => now(),
        ]);

        $this->assertStringContainsString('via email', ConversationHeader::threadChip($email));
        $this->assertSame('', ConversationHeader::threadChip($chatThread));
    }
}
