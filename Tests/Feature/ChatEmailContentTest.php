<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Thread;
use Illuminate\Support\Collection;
use Modules\WordPressChat\Services\ChatEmail;
use Modules\WordPressChat\Tests\TestCase;

class ChatEmailContentTest extends TestCase
{
    const LINK = 'https://shop.test/#lcff-continue=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    protected function tearDown(): void
    {
        ChatEmail::end();

        parent::tearDown();
    }

    private function chat(): \App\Conversation
    {
        return $this->startChat($this->makeSite(), "I'm here")['conversation'];
    }

    private function reply(\App\Conversation $conversation, string $body): Thread
    {
        $agent = $this->adminUser();

        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->user_id            = $agent->id;
        $thread->created_by_user_id = $agent->id;
        $thread->type               = Thread::TYPE_MESSAGE;
        $thread->status             = Thread::STATUS_ACTIVE;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->body               = '<p>'.$body.'</p>';
        $thread->source_via         = Thread::PERSON_USER;
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id        = $conversation->customer_id;
        $thread->save();

        return $thread;
    }

    /** Core's real mailable, rendered: html and text parts. */
    private function renderEmail(\App\Conversation $c, Collection $threads): array
    {
        $mail = new \App\Mail\ReplyToCustomer(
            $c, $threads, ['Message-ID' => 'wpc-test@example.test'], $c->mailbox, 'Re: Chat', count($threads)
        );

        $html = $mail->render();
        $text = view(
            \Eventy::filter('email.reply_to_customer.template_name_text', 'emails/customer/reply_fancy_text'),
            $mail->buildViewData()
        )->render();

        return ['html' => $html, 'text' => $text];
    }

    public function test_every_batch_reply_is_in_the_email_without_history()
    {
        config(['app.email_conv_history' => 'none']);
        $c  = $this->chat();
        $r1 = $this->reply($c, 'Reply one');
        $r2 = $this->reply($c, 'Reply two');
        $r3 = $this->reply($c, 'Reply three');

        ChatEmail::begin($c->id, [$r1->id, $r2->id, $r3->id], null);

        $threads = \Eventy::filter('email.reply_to_customer.threads', collect([$r3]), $c, $c->mailbox);
        $this->assertSame([$r3->id, $r2->id, $r1->id], $threads->pluck('id')->all());

        $html = $this->renderEmail($c, $threads)['html'];
        foreach (['Reply one', 'Reply two', 'Reply three'] as $body) {
            $this->assertStringContainsString($body, $html);
        }
    }

    public function test_link_is_in_html_once_and_in_the_text_part()
    {
        $c  = $this->chat();
        $r1 = $this->reply($c, 'Reply one');
        $r2 = $this->reply($c, 'Reply two');
        ChatEmail::begin($c->id, [$r1->id, $r2->id], self::LINK);

        $threads = \Eventy::filter('email.reply_to_customer.threads', collect([$r2]), $c, $c->mailbox);
        $out = $this->renderEmail($c, $threads);

        $this->assertSame(1, substr_count($out['html'], 'href="'.self::LINK.'"'));
        $this->assertStringContainsString('Continue this chat on the website</a>', $out['html']);
        $this->assertStringContainsString("Continue this chat on the website:\n".self::LINK, str_replace("\r\n", "\n", $out['text']));
        $this->assertStringNotContainsString('&amp;', $out['text']);
    }

    public function test_without_context_core_emails_are_untouched()
    {
        $c  = $this->chat();
        $r1 = $this->reply($c, 'Reply one');
        $in = collect([$r1]);

        $this->assertSame($in, \Eventy::filter('email.reply_to_customer.threads', $in, $c, $c->mailbox));
        $this->assertSame('emails/customer/reply_fancy_text', \Eventy::filter('email.reply_to_customer.template_name_text', 'emails/customer/reply_fancy_text'));
        $this->assertStringNotContainsString('lcff-continue', $this->renderEmail($c, $in)['html']);
    }

    public function test_another_conversations_context_is_ignored()
    {
        $c     = $this->chat();
        $other = $this->chat();
        $r1    = $this->reply($c, 'Reply one');
        $r2    = $this->reply($c, 'Reply two');
        ChatEmail::begin($other->id, [$r1->id, $r2->id], self::LINK);

        $in = collect([$r2]);
        $this->assertSame($in, \Eventy::filter('email.reply_to_customer.threads', $in, $c, $c->mailbox));
        $this->assertStringNotContainsString('lcff-continue', $this->renderEmail($c, $in)['html']);
    }

    public function test_text_part_keeps_apostrophes()
    {
        config(['app.email_conv_history' => 'full']);
        $c = $this->chat();
        $visitor = Thread::where('conversation_id', $c->id)->first();
        $this->assertStringContainsString("I'm", $visitor->body);
        $this->assertStringNotContainsString('&#039;', $visitor->body);

        $r1 = $this->reply($c, 'Welcome');
        $threads = Thread::sortThreads(Thread::where('conversation_id', $c->id)->get())->values();

        $this->assertStringContainsString("I'm here", $this->renderEmail($c, $threads)['text']);
    }
}
