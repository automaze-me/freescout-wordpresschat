<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Thread;
use Modules\WordPressChat\Entities\Access;
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
