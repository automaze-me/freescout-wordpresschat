<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\Mailbox;
use App\SendLog;
use App\Thread;
use Illuminate\Support\Facades\Queue;
use Modules\WordPressChat\Entities\Link;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Jobs\SendChatEmail;
use Modules\WordPressChat\Services\CoreReplyMailer;
use Modules\WordPressChat\Services\DeliveryClock;
use Modules\WordPressChat\Services\DueEmails;
use Modules\WordPressChat\Tests\TestCase;

/**
 * Final review I3: the real send path, with no network. SendChatEmail runs
 * the real CoreReplyMailer, which runs core's SendReplyToCustomer::handle()
 * inline. The mailbox sends through "sendmail", and `mail.sendmail` points
 * at a capture script in -t mode that writes the raw message to a file.
 *
 * The config survives core's driver switch: MailHelper::setMailDriver()
 * sets mail.driver ("sendmail" for OUT_METHOD_SENDMAIL) and mail.from only,
 * then reapplyMailConfig() rebuilds the mailer from config('mail'), and
 * the TransportManager builds Swift_SendmailTransport from mail.sendmail.
 * Swift accepts "-bs" and "-t" commands and appends -f<sender> in -t mode.
 */
class RealSendTest extends TestCase
{
    /** The capture directory under the system temp dir. */
    private $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/wpc-sendmail-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);

        $script = $this->dir.'/sendmail';
        file_put_contents($script, "#!/bin/sh\n"
            ."printf '%s\\n' \"\$@\" > '".$this->dir."/args'\n"
            ."cat > '".$this->dir."/message.eml'\n");
        chmod($script, 0700);

        config(['mail.sendmail' => $script.' -t -i']);
    }

    protected function tearDown(): void
    {
        foreach (['sendmail', 'args', 'message.eml'] as $file) {
            if (is_file($this->dir.'/'.$file)) {
                unlink($this->dir.'/'.$file);
            }
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    /** A chat with customer_email anna@example.test whose mailbox sends through the capture script. */
    private function chat(): array
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Do you ship to Austria?');
        $conversation = Conversation::find($chat['conversation']->id);
        $conversation->customer_email = 'anna@example.test';
        $conversation->save();

        $mailbox = Mailbox::find($site->mailbox_id);
        $mailbox->email = 'support-'.bin2hex(random_bytes(4)).'@example.test';
        $mailbox->aliases = '';
        $mailbox->out_method = Mailbox::OUT_METHOD_SENDMAIL;
        $mailbox->save();

        return [$site, $conversation, $mailbox];
    }

    /**
     * The visitor's email reply, as FetchEmails stores one: with its
     * Message-ID, 30 minutes ago, after the visitor's chat message (moved
     * back an hour). Core answers In-Reply-To the newest earlier customer
     * thread, and only when that thread has a Message-ID: a chat message
     * has none.
     */
    private function customerEmail(Conversation $conversation, string $messageId): Thread
    {
        Thread::where('conversation_id', $conversation->id)
            ->where('type', Thread::TYPE_CUSTOMER)
            ->update(['created_at' => now()->subHour()]);

        $thread = new Thread();
        $thread->conversation_id        = $conversation->id;
        $thread->type                   = Thread::TYPE_CUSTOMER;
        $thread->status                 = Thread::STATUS_ACTIVE;
        $thread->state                  = Thread::STATE_PUBLISHED;
        $thread->body                   = '<p>And to Switzerland?</p>';
        $thread->from                   = 'anna@example.test';
        $thread->message_id             = $messageId;
        $thread->source_type            = Thread::SOURCE_TYPE_EMAIL;
        $thread->source_via             = Thread::PERSON_CUSTOMER;
        $thread->customer_id            = $conversation->customer_id;
        $thread->created_by_customer_id = $conversation->customer_id;
        $thread->save();
        Thread::where('id', $thread->id)->update(['created_at' => now()->subMinutes(30)]);

        return $thread;
    }

    /**
     * An agent reply published to the chat by the real listener, due 200 s
     * ago. To is set as core's reply form sets it (the conversation's
     * customer_email); core's send log lists the To, Cc and Bcc recipients.
     */
    private function reply(Conversation $conversation, string $text): array
    {
        $agent = $this->adminUser();

        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->type               = Thread::TYPE_MESSAGE;
        $thread->status             = Thread::STATUS_ACTIVE;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->body               = '<p>'.$text.'</p>';
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->source_via         = Thread::PERSON_USER;
        $thread->customer_id        = $conversation->customer_id;
        $thread->user_id            = $agent->id;
        $thread->created_by_user_id = $agent->id;
        $thread->setTo($conversation->customer_email);
        $thread->save();

        \Eventy::action('chat_conversation.send_reply', $conversation, collect([$thread]), $conversation->customer);

        $row = Message::where('thread_id', $thread->id)->firstOrFail();
        $publishedAt = now()->subSeconds(200)->startOfSecond();
        Message::where('id', $row->id)->update([
            'published_at'    => $publishedAt,
            'fallback_due_at' => $publishedAt->copy()->addSeconds(DeliveryClock::delayFor($conversation)),
        ]);

        return [Thread::find($thread->id), $row->fresh()];
    }

    /**
     * The captured message: headers (unfolded, lower-case names) and the
     * decoded text/plain and text/html parts.
     */
    private function captured(): array
    {
        $file = $this->dir.'/message.eml';
        $this->assertFileExists($file, 'core handed no message to the sendmail command');

        $raw = str_replace("\r\n", "\n", file_get_contents($file));
        $parts = [];
        $headers = $this->parsePart($raw, $parts);

        return [$headers, $parts, $raw];
    }

    /**
     * Parses one MIME entity; leaf text parts are decoded into $parts by
     * subtype, file parts into $parts['attachments'].
     */
    private function parsePart(string $entity, array &$parts): array
    {
        $split = strpos($entity, "\n\n");
        $head = $split === false ? $entity : substr($entity, 0, $split);
        $body = $split === false ? '' : substr($entity, $split + 2);

        $headers = [];
        foreach (explode("\n", preg_replace("/\n[ \t]+/", ' ', $head)) as $line) {
            if (strpos($line, ':') !== false) {
                list($name, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($name))][] = trim($value);
            }
        }

        $type = $headers['content-type'][0] ?? 'text/plain';

        if (preg_match('#^multipart/#i', $type) && preg_match('/boundary="?([^";]+)"?/i', $type, $m)) {
            $chunks = explode('--'.$m[1], $body);
            // Preamble first, the closing "--" chunk last.
            foreach (array_slice($chunks, 1) as $chunk) {
                if (strpos($chunk, '--') === 0) {
                    break;
                }
                $this->parsePart(ltrim($chunk, "\n"), $parts);
            }
        } elseif (preg_match('/^attachment/i', $headers['content-disposition'][0] ?? '')) {
            // A file part: its name and its decoded bytes.
            $disposition = $headers['content-disposition'][0];
            $name = preg_match('/filename="?([^";]+)"?/i', $disposition, $f) ? $f[1] : '';
            $encoding = strtolower($headers['content-transfer-encoding'][0] ?? '7bit');
            $parts['attachments'][] = [
                'name' => $name,
                'type' => strtolower(trim(explode(';', $type)[0])),
                'body' => $encoding === 'base64' ? base64_decode($body) : $body,
            ];
        } elseif (preg_match('#^text/(plain|html)#i', $type, $m)) {
            $encoding = strtolower($headers['content-transfer-encoding'][0] ?? '7bit');
            if ($encoding === 'quoted-printable') {
                $body = quoted_printable_decode($body);
            } elseif ($encoding === 'base64') {
                $body = base64_decode($body);
            }
            $parts[strtolower($m[1])] = $body;
        }

        return $headers;
    }

    /** The bare addresses in an address header. */
    private function addresses(string $value): array
    {
        preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+/', $value, $m);

        return array_values(array_unique(array_map('strtolower', $m[0])));
    }

    public function test_fallback_batch_goes_out_through_cores_real_job()
    {
        list($site, $conversation, $mailbox) = $this->chat();
        $email = $this->customerEmail($conversation, 'anna-1.20261104@mail.example.test');
        list($r1, $row1) = $this->reply($conversation, 'Yes, we ship to Austria.');
        list($r2, $row2) = $this->reply($conversation, 'Delivery takes three days.');

        Queue::fake();
        $this->assertSame(1, DueEmails::sweep());
        $jobs = Queue::pushed(SendChatEmail::class);
        $this->assertCount(1, $jobs);
        // The real mailer: nothing in this test binds a fake.
        $this->assertSame(CoreReplyMailer::class, get_class(app(CoreReplyMailer::class)));

        app()->call([$jobs->first(), 'handle']);

        list($headers, $parts, $raw) = $this->captured();

        // To is the conversation's customer address and nothing else.
        $this->assertSame(['anna@example.test'], $this->addresses(implode(', ', $headers['to'])));
        $this->assertArrayNotHasKey('cc', $headers);
        $this->assertArrayNotHasKey('bcc', $headers);
        $this->assertSame([$mailbox->email], $this->addresses(implode(', ', $headers['from'])));
        // The sendmail command got -t (recipients from the headers above).
        $this->assertContains('-t', file($this->dir.'/args', FILE_IGNORE_NEW_LINES));

        // Both replies of the batch, in both parts.
        $this->assertArrayHasKey('html', $parts);
        $this->assertArrayHasKey('plain', $parts);
        foreach (['Yes, we ship to Austria.', 'Delivery takes three days.'] as $text) {
            $this->assertStringContainsString($text, $parts['html']);
            $this->assertStringContainsString($text, $parts['plain']);
        }

        // The continue link, the same one in both parts, for the one Link row.
        $pattern = '#'.preg_quote(rtrim($site->url, '/'), '#').'/\#lcff-continue=([A-Za-z0-9_\-]{43})#';
        $this->assertSame(1, preg_match($pattern, $parts['plain'], $plain));
        $this->assertSame(1, preg_match($pattern, $parts['html'], $html));
        $this->assertSame($plain[0], $html[0]);
        $this->assertStringContainsString('href="'.$plain[0].'"', $parts['html']);
        $this->assertStringContainsString("Continue this chat on the website:\n".$plain[0], $parts['plain']);
        $this->assertSame(1, Link::where('conversation_id', $conversation->id)->count());
        $this->assertSame(1, Link::where('token_hash', hash('sha256', $plain[1]))->count());

        // Core's threading headers.
        $this->assertSame('<'.$r2->getMessageId($mailbox).'>', $headers['message-id'][0]);
        $this->assertSame('<anna-1.20261104@mail.example.test>', $headers['in-reply-to'][0]);
        $this->assertStringContainsString('<anna-1.20261104@mail.example.test>', $headers['references'][0]);

        // Core's send status on the newest reply, and the rows sent.
        $this->assertSame(SendLog::STATUS_ACCEPTED, (int) Thread::find($r2->id)->send_status);
        $this->assertSame(Message::EMAIL_SENT, (int) $row1->fresh()->email_status);
        $this->assertSame(Message::EMAIL_SENT, (int) $row2->fresh()->email_status);
        $log = SendLog::where('thread_id', $r2->id)->get();
        $this->assertSame(['anna@example.test'], $log->pluck('email')->all());
        $this->assertSame(SendLog::STATUS_ACCEPTED, (int) $log[0]->status);
    }

    /** With a Cc on the newest reply the email goes to both, without a link (Ruling D5). */
    public function test_cc_recipient_gets_the_real_email_without_a_link()
    {
        list($site, $conversation, $mailbox) = $this->chat();
        list($r1, $row1) = $this->reply($conversation, 'Your order is on its way.');
        $r1->setCc(['boss@example.test']);
        $r1->save();

        Queue::fake();
        $this->assertSame(1, DueEmails::sweep());
        app()->call([Queue::pushed(SendChatEmail::class)->first(), 'handle']);

        list($headers, $parts) = $this->captured();

        $this->assertSame(['anna@example.test'], $this->addresses(implode(', ', $headers['to'])));
        $this->assertSame(['boss@example.test'], $this->addresses(implode(', ', $headers['cc'])));
        $this->assertStringContainsString('Your order is on its way.', $parts['plain']);
        $this->assertStringNotContainsString('lcff-continue', $parts['plain']);
        $this->assertStringNotContainsString('lcff-continue', $parts['html']);
        $this->assertSame(0, Link::where('conversation_id', $conversation->id)->count());
        $this->assertSame(Message::EMAIL_SENT, (int) $row1->fresh()->email_status);
    }

    /** A PDF on an agent reply, as core stores one the agent attached. */
    private function attachPdf(Thread $thread, string $name, string $marker): array
    {
        $bytes = "%PDF-1.4\n% ".$marker."\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
        $attachment = \App\Attachment::create($name, 'application/pdf', null, $bytes, null, false, $thread->id, $thread->created_by_user_id);
        $thread->has_attachments = true;
        $thread->save();

        return [$attachment, $bytes];
    }

    /**
     * Ruling E10: core attaches only its last_thread's files; the batch
     * email carries the files of every reply in it.
     */
    public function test_batch_email_carries_every_replys_attachments()
    {
        list($site, $conversation, $mailbox) = $this->chat();
        list($r1, $row1) = $this->reply($conversation, 'Here is the invoice.');
        list(, $invoice) = $this->attachPdf($r1, 'invoice-1.pdf', 'first');
        list($r2, $row2) = $this->reply($conversation, 'And the delivery note.');
        list(, $note) = $this->attachPdf($r2, 'delivery-2.pdf', 'second');

        Queue::fake();
        $this->assertSame(1, DueEmails::sweep());
        app()->call([Queue::pushed(SendChatEmail::class)->first(), 'handle']);

        list($headers, $parts) = $this->captured();

        $this->assertStringContainsString('Here is the invoice.', $parts['plain']);
        $this->assertStringContainsString('And the delivery note.', $parts['plain']);
        $this->assertArrayHasKey('attachments', $parts);
        $files = [];
        foreach ($parts['attachments'] as $file) {
            $files[$file['name']] = $file;
        }
        ksort($files);
        $this->assertSame(['delivery-2.pdf', 'invoice-1.pdf'], array_keys($files));
        $this->assertSame($note, $files['delivery-2.pdf']['body']);
        $this->assertSame($invoice, $files['invoice-1.pdf']['body']);
        $this->assertSame('application/pdf', $files['invoice-1.pdf']['type']);
        $this->assertSame(Message::EMAIL_SENT, (int) $row1->fresh()->email_status);
        $this->assertSame(Message::EMAIL_SENT, (int) $row2->fresh()->email_status);
    }

    /** A batch reply's file that is gone from disk is skipped; the email still goes out. */
    public function test_a_missing_batch_file_is_skipped()
    {
        list($site, $conversation, $mailbox) = $this->chat();
        list($r1) = $this->reply($conversation, 'Here is the invoice.');
        list($gone) = $this->attachPdf($r1, 'invoice-1.pdf', 'first');
        list($r2, $row2) = $this->reply($conversation, 'And the delivery note.');
        $this->attachPdf($r2, 'delivery-2.pdf', 'second');
        unlink($gone->getLocalFilePath());

        Queue::fake();
        $this->assertSame(1, DueEmails::sweep());
        app()->call([Queue::pushed(SendChatEmail::class)->first(), 'handle']);

        list($headers, $parts) = $this->captured();

        $this->assertSame(['delivery-2.pdf'], array_column($parts['attachments'], 'name'));
        $this->assertSame(Message::EMAIL_SENT, (int) $row2->fresh()->email_status);
    }

    /** Outside a chat email (no ChatEmail context) the hook adds nothing. */
    public function test_the_hook_does_nothing_outside_a_chat_email()
    {
        list($site, $conversation) = $this->chat();
        list($r1) = $this->reply($conversation, 'One');
        $this->attachPdf($r1, 'invoice-1.pdf', 'first');
        list($r2) = $this->reply($conversation, 'Two');

        $swift = new \Swift_Message('Test');
        \Eventy::action('email.reply_to_customer.swiftmessage', $swift, '', $r2, null);
        $this->assertCount(0, $swift->getChildren());

        // Another conversation's context: nothing either.
        \Modules\WordPressChat\Services\ChatEmail::begin((int) $conversation->id + 100000, [$r1->id, $r2->id], null);
        try {
            \Eventy::action('email.reply_to_customer.swiftmessage', $swift, '', $r2, null);
        } finally {
            \Modules\WordPressChat\Services\ChatEmail::end();
        }
        $this->assertCount(0, $swift->getChildren());

        // The matching context attaches the other batch reply's file.
        \Modules\WordPressChat\Services\ChatEmail::begin((int) $conversation->id, [$r1->id, $r2->id], null);
        try {
            \Eventy::action('email.reply_to_customer.swiftmessage', $swift, '', $r2, null);
        } finally {
            \Modules\WordPressChat\Services\ChatEmail::end();
        }
        $this->assertCount(1, $swift->getChildren());
        $this->assertSame('invoice-1.pdf', $swift->getChildren()[0]->getFilename());
    }
}
