<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Thread;
use Illuminate\Http\UploadedFile;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Services\ChatAttachment;
use Modules\WordPressChat\Services\InboundMessage;
use Modules\WordPressChat\Tests\TestCase;

/**
 * Spec §5.3 (attachments) and §6.4 (the download only through Visibility).
 *
 * The fixtures are tiny real files (1x1 images made with GD, a minimal PDF)
 * so the type decision runs on real bytes, as finfo sees them.
 */
class AttachmentTest extends TestCase
{
    const JPEG = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2NjIpLCBkZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSwwMTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy/8AAEQgAAQABAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A+f6KKKAP/9k=';
    const PNG  = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC';
    const GIF  = 'R0lGODdhAQABAIAAAAQCBAAAACwAAAAAAQABAAACAkQBADs=';
    const WEBP = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAUAmJaQAA3AA/v02aAA=';
    const PDF  = 'JVBERi0xLjQKMSAwIG9iajw8L1R5cGUvQ2F0YWxvZy9QYWdlcyAyIDAgUj4+ZW5kb2JqCjIgMCBvYmo8PC9UeXBlL1BhZ2VzL0tpZHNbXS9Db3VudCAwPj5lbmRvYmoKdHJhaWxlcjw8L1Jvb3QgMSAwIFI+PgolJUVPRgo=';

    /** A DOS/PE executable stub ("MZ" ... "PE\0\0"). */
    const PE   = 'TVqQAAMAAAAEAAAA//8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQAAAAFBFAABMAQ==';
    /** <?xml?><svg ...><script>alert(1)</script></svg> */
    const SVG  = 'PD94bWwgdmVyc2lvbj0iMS4wIj8+PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxzY3JpcHQ+YWxlcnQoMSk8L3NjcmlwdD48L3N2Zz4=';
    /** <!DOCTYPE html><html><body><script>alert(1)</script></body></html> */
    const HTML = 'PCFET0NUWVBFIGh0bWw+CjxodG1sPjxib2R5PjxzY3JpcHQ+YWxlcnQoMSk8L3NjcmlwdD48L2JvZHk+PC9odG1sPgo=';

    /** Temp files this test wrote; removed in tearDown(). */
    private $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    /** An uploaded file with real bytes, as PHP hands one to the request. */
    private function upload(string $bytes, string $name, string $clientMime = 'application/octet-stream'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wpc-up-');
        file_put_contents($path, $bytes);
        $this->tmpFiles[] = $path;

        return new UploadedFile($path, $name, $clientMime, strlen($bytes), null, true);
    }

    /** An upload PHP itself dropped: no temp file, only the error code. */
    private function failedUpload(int $error): UploadedFile
    {
        return new UploadedFile('', 'photo.png', 'image/png', 0, $error, true);
    }

    /**
     * POST …/messages as multipart. Without a token the form gets a fresh
     * new_token (unless $fields has one), and always a client_message_id
     * (unless $fields has one).
     */
    private function sendFile(Site $site, ?UploadedFile $file, array $fields = [], ?string $token = null)
    {
        if ($token === null && !array_key_exists('new_token', $fields)) {
            $fields['new_token'] = $this->newChatToken();
        }
        if (!array_key_exists('client_message_id', $fields)) {
            $fields['client_message_id'] = 'att-'.bin2hex(random_bytes(6));
        }

        return $this->apiMultipart('POST', $site, '/messages', $token, $fields, $file ? ['file' => $file] : []);
    }

    private function download(Site $site, $id, ?string $token)
    {
        return $this->api('GET', $site, '/attachments/'.$id, $token);
    }

    /** The bytes of a streamed download. */
    private function body($response): string
    {
        ob_start();
        $response->baseResponse->sendContent();

        return (string) ob_get_clean();
    }

    private function assertNotFound($response, string $case): void
    {
        $this->assertSame(404, $response->status(), $case);
        $this->assertSame(['error' => 'not_found'], $response->json(), $case);
    }

    private function rowCounts(): array
    {
        return [
            'sessions'     => Session::count(),
            'threads'      => Thread::count(),
            'messages'     => Message::count(),
            'attachments'  => Attachment::count(),
        ];
    }

    /** An agent thread of $type in $conversation; with a published row unless $row is false. */
    private function agentThread(Conversation $conversation, int $type = Thread::TYPE_MESSAGE, bool $row = true): Thread
    {
        $agent = $this->adminUser();
        $thread = new Thread();
        $thread->conversation_id    = $conversation->id;
        $thread->type               = $type;
        $thread->status             = Thread::STATUS_ACTIVE;
        $thread->state              = Thread::STATE_PUBLISHED;
        $thread->body               = '<p>Agent reply</p>';
        $thread->source_type        = Thread::SOURCE_TYPE_WEB;
        $thread->source_via         = Thread::PERSON_USER;
        $thread->user_id            = $agent->id;
        $thread->created_by_user_id = $agent->id;
        $thread->save();

        if ($row) {
            Message::create([
                'conversation_id' => $conversation->id,
                'thread_id'       => $thread->id,
                'direction'       => Message::DIRECTION_OUT,
                'published_at'    => now(),
            ]);
        }

        return $thread;
    }

    /** A file on $thread as core stores an agent's or an email's attachment. */
    private function attach(?Thread $thread, string $name, string $mime, string $bytes, bool $embedded = false): Attachment
    {
        $attachment = Attachment::create($name, $mime, null, $bytes, null, $embedded, $thread ? $thread->id : null, null);

        if ($thread) {
            $thread->has_attachments = true;
            $thread->save();
        }

        return $attachment;
    }

    // ---------------------------------------------------------------- uploads

    public function test_visitor_sends_an_image_with_text()
    {
        $site = $this->makeSite();
        $png = base64_decode(self::PNG);

        $r = $this->sendFile($site, $this->upload($png, 'photo.png', 'image/png'), ['text' => 'Here is the photo']);

        $r->assertStatus(201);
        $message = Message::find($r->json()['message']['id']);
        $thread = Thread::find($message->thread_id);
        $attachments = Attachment::where('thread_id', $thread->id)->get();
        $this->assertCount(1, $attachments);
        $this->assertSame('image/png', $attachments[0]->mime_type);
        $this->assertSame('photo.png', $attachments[0]->file_name);
        $this->assertFalse((bool) $attachments[0]->embedded);
        $this->assertSame($png, $attachments[0]->getFileContents());
        $this->assertTrue((bool) $thread->has_attachments);
        $this->assertTrue((bool) Conversation::find($message->conversation_id)->has_attachments);
        $this->assertSame('Here is the photo', $thread->body);

        $expected = [[
            'id'   => (int) $attachments[0]->id,
            'name' => 'photo.png',
            'mime' => 'image/png',
            'size' => strlen($png),
        ]];
        $this->assertSame('Here is the photo', $r->json()['message']['text']);
        $this->assertSame($expected, $r->json()['message']['attachments']);

        $poll = $this->api('GET', $site, '/messages', $r->json()['token'])->json();
        $this->assertSame($expected, $poll['messages'][0]['attachments']);
    }

    public function test_visitor_sends_a_file_without_text()
    {
        $site = $this->makeSite();

        $r = $this->sendFile($site, $this->upload(base64_decode(self::PDF), 'invoice.pdf', 'application/pdf'));

        $r->assertStatus(201);
        $message = Message::find($r->json()['message']['id']);
        $thread = Thread::find($message->thread_id);
        $this->assertSame('', (string) $thread->body);
        $this->assertSame('', $r->json()['message']['text']);
        $this->assertCount(1, $r->json()['message']['attachments']);
        $this->assertSame('application/pdf', $r->json()['message']['attachments'][0]['mime']);
        // The agent's list shows the file name, not "(no subject)".
        $conversation = Conversation::find($message->conversation_id);
        $this->assertSame('invoice.pdf', $conversation->subject);
        $this->assertSame('invoice.pdf', $conversation->preview);

        // Blank text counts as none; text without a file still needs text.
        $token = $r->json()['token'];
        $this->sendFile($site, $this->upload(base64_decode(self::PNG), 'b.png'), ['text' => "  \n "], $token)->assertStatus(201);
        $this->sendFile($site, null, ['text' => ''], $token)
            ->assertStatus(422)->assertExactJson(['error' => 'validation', 'field' => 'text']);
    }

    public static function allowedTypes(): array
    {
        return [
            'jpeg' => [self::JPEG, 'image/jpeg', 'jpg'],
            'png'  => [self::PNG, 'image/png', 'png'],
            'gif'  => [self::GIF, 'image/gif', 'gif'],
            'webp' => [self::WEBP, 'image/webp', 'webp'],
            'pdf'  => [self::PDF, 'application/pdf', 'pdf'],
        ];
    }

    /**
     * @dataProvider allowedTypes
     */
    public function test_each_allowed_type_is_accepted(string $base64, string $mime, string $extension)
    {
        $site = $this->makeSite();
        $bytes = base64_decode($base64);

        // The client's name and type say nothing useful: the bytes decide.
        $r = $this->sendFile($site, $this->upload($bytes, 'upload.bin', 'application/octet-stream'));

        $r->assertStatus(201);
        $attachment = Attachment::find($r->json()['message']['attachments'][0]['id']);
        $this->assertSame($mime, $attachment->mime_type);
        $this->assertSame('upload.'.$extension, $attachment->file_name);
        $this->assertSame(strlen($bytes), (int) $attachment->size);
    }

    public function test_disguised_files_are_rejected()
    {
        $site = $this->makeSite();
        $before = $this->rowCounts();

        $cases = [
            'PE named a.jpg'      => [self::PE, 'a.jpg', 'image/jpeg'],
            'SVG with a script'   => [self::SVG, 'logo.svg', 'image/svg+xml'],
            'SVG named .png'      => [self::SVG, 'logo.png', 'image/png'],
            'HTML file'           => [self::HTML, 'page.html', 'text/html'],
            'HTML named .jpg'     => [self::HTML, 'page.jpg', 'image/jpeg'],
        ];
        foreach ($cases as $case => list($base64, $name, $clientMime)) {
            $r = $this->sendFile($site, $this->upload(base64_decode($base64), $name, $clientMime), ['text' => 'look']);

            $this->assertSame(422, $r->status(), $case);
            $this->assertSame(['error' => 'validation', 'field' => 'file', 'reason' => 'type'], $r->json(), $case);
        }

        // Not even the session of a first message was written.
        $this->assertSame($before, $this->rowCounts());

        // A real PNG that claims to be a PDF is stored as what it is.
        $r = $this->sendFile($site, $this->upload(base64_decode(self::PNG), 'scan.pdf', 'application/pdf'));
        $r->assertStatus(201);
        $attachment = Attachment::find($r->json()['message']['attachments'][0]['id']);
        $this->assertSame('image/png', $attachment->mime_type);
        $this->assertSame('scan.png', $attachment->file_name);
    }

    public function test_stored_name_is_sanitized_and_matches_the_sniffed_type()
    {
        $site = $this->makeSite();
        $token = $this->startChat($site)['token'];
        $png = base64_decode(self::PNG);

        $cases = [
            'shell.php'                          => 'shell.png',
            'shell.php.jpg'                      => 'shell_php.png',
            'page.html'                          => 'page.png',
            '.htaccess'                          => 'file.png',
            ''                                   => 'file.png',
            "bad\x01name\x7F\u{202E}gnp.exe"     => 'badnamegnp.png',
            'a<b>c:d"e|f?g*h;i.png'              => 'a_b_c_d_e_f_g_h_i.png',
        ];
        foreach ($cases as $clientName => $stored) {
            $r = $this->sendFile($site, $this->upload($png, $clientName, 'image/png'), [], $token);
            $r->assertStatus(201);
            $attachment = Attachment::find($r->json()['message']['attachments'][0]['id']);
            $this->assertSame($stored, $attachment->file_name, 'client name '.json_encode($clientName));
        }

        // At most 255 bytes, the extension kept.
        $r = $this->sendFile($site, $this->upload($png, str_repeat('ü', 300).'.png'), [], $token);
        $name = Attachment::find($r->json()['message']['attachments'][0]['id'])->file_name;
        $this->assertLessThanOrEqual(255, strlen($name));
        $this->assertTrue(mb_check_encoding($name, 'UTF-8'));
        $this->assertStringEndsWith('ü.png', $name);
    }

    public function test_too_large_file_is_rejected()
    {
        $site = $this->makeSite(['max_attachment_mb' => 1]);
        $before = $this->rowCounts();
        // A real PNG header padded to 1.5 MB.
        $big = base64_decode(self::PNG).str_repeat("\0", 1572864);

        $r = $this->sendFile($site, $this->upload($big, 'big.png', 'image/png'), ['text' => 'big one']);

        $r->assertStatus(422)->assertExactJson(['error' => 'validation', 'field' => 'file', 'reason' => 'too_large']);
        $this->assertSame($before, $this->rowCounts());
    }

    /** PHP's own upload errors are answers, never a 500. */
    public function test_php_upload_errors_map_to_too_large_or_missing()
    {
        $site = $this->makeSite();
        $before = $this->rowCounts();

        $cases = [
            UPLOAD_ERR_INI_SIZE   => 'too_large',
            UPLOAD_ERR_FORM_SIZE  => 'too_large',
            UPLOAD_ERR_PARTIAL    => 'missing',
            UPLOAD_ERR_NO_TMP_DIR => 'missing',
            UPLOAD_ERR_CANT_WRITE => 'missing',
            UPLOAD_ERR_EXTENSION  => 'missing',
        ];
        foreach ($cases as $error => $reason) {
            $r = $this->sendFile($site, $this->failedUpload($error), ['text' => 'with a file']);

            $this->assertSame(422, $r->status(), 'error '.$error);
            $this->assertSame(['error' => 'validation', 'field' => 'file', 'reason' => $reason], $r->json(), 'error '.$error);
        }

        // An empty file, and a `file` field that is no upload at all.
        $r = $this->sendFile($site, $this->upload('', 'empty.png', 'image/png'), ['text' => 'x']);
        $r->assertStatus(422)->assertExactJson(['error' => 'validation', 'field' => 'file', 'reason' => 'missing']);
        $r = $this->sendFile($site, null, ['text' => 'x', 'file' => 'not-a-file']);
        $r->assertStatus(422)->assertExactJson(['error' => 'validation', 'field' => 'file', 'reason' => 'missing']);

        $this->assertSame($before, $this->rowCounts());
    }

    public function test_retry_stores_the_file_once()
    {
        $site = $this->makeSite();
        $png = base64_decode(self::PNG);
        $fields = ['text' => 'photo', 'client_message_id' => 'att-retry-0001', 'new_token' => $this->newChatToken()];

        // A first message whose answer was lost, sent again as it was.
        $first = $this->sendFile($site, $this->upload($png, 'photo.png'), $fields);
        $second = $this->sendFile($site, $this->upload($png, 'photo.png'), $fields);

        $first->assertStatus(201);
        $second->assertStatus(200);
        $this->assertSame($first->json()['message']['id'], $second->json()['message']['id']);
        $message = Message::find($first->json()['message']['id']);
        $this->assertSame(1, Attachment::where('thread_id', $message->thread_id)->count());
        $this->assertSame(1, Message::where('session_id', $message->session_id)->count());

        // With the token, and even with another file: still a replay.
        $third = $this->sendFile($site, $this->upload(base64_decode(self::GIF), 'other.gif'), ['text' => 'photo', 'client_message_id' => 'att-retry-0001'], $first->json()['token']);
        $third->assertStatus(200);
        $this->assertSame(1, Attachment::where('thread_id', $message->thread_id)->count());
    }

    /**
     * Two requests with the same client_message_id: the competitor commits
     * between our duplicate pre-check and our insert. Ours rolls back, and
     * the file it had already written must not stay behind on disk.
     */
    public function test_a_lost_race_leaves_no_file_behind()
    {
        $site = $this->makeSite();
        $session = \Modules\WordPressChat\Services\VisitorAuth::claim($site, $this->newChatToken());
        $png = base64_decode(self::PNG);
        $competitorFile = $this->upload($png, 'winner.png');
        $ourFile = $this->upload($png, 'loser.png');

        $paths = [];
        Attachment::saved(function ($attachment) use (&$paths) {
            if ($attachment->file_dir) {
                $paths[$attachment->file_name] = $attachment->getLocalFilePath();
            }
        });

        $competitor = null;
        \DB::listen(function ($query) use ($site, $session, $competitorFile, &$competitor) {
            if ($competitor !== null
                || stripos($query->sql, 'select') !== 0
                || strpos($query->sql, 'wordpresschat_messages') === false
            ) {
                return;
            }
            $competitor = false;
            $competitor = InboundMessage::receive($site, $session, 'photo', 'race-file-0001', $competitorFile);
        });

        try {
            $message = InboundMessage::receive($site, $session, 'photo', 'race-file-0001', $ourFile);

            $this->assertInstanceOf(Message::class, $competitor);
            $this->assertSame((int) $competitor->id, (int) $message->id);
            $this->assertSame(1, Attachment::where('thread_id', $message->thread_id)->count());
            $this->assertArrayHasKey('loser.png', $paths);
            $this->assertFileExists($paths['winner.png']);
            $this->assertFileDoesNotExist($paths['loser.png']);
        } finally {
            if (isset($paths['loser.png']) && is_file($paths['loser.png'])) {
                unlink($paths['loser.png']);
            }
        }
    }

    // -------------------------------------------------------------- downloads

    public function test_download_streams_with_safe_headers()
    {
        $site = $this->makeSite();
        $png = base64_decode(self::PNG);
        $pdf = base64_decode(self::PDF);
        $r = $this->sendFile($site, $this->upload($png, 'photo.png', 'image/png'));
        $token = $r->json()['token'];

        $d = $this->download($site, $r->json()['message']['attachments'][0]['id'], $token);

        $d->assertStatus(200);
        $this->assertSame('image/png', $d->headers->get('Content-Type'));
        $this->assertSame('nosniff', $d->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $d->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $d->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', $d->headers->get('Cache-Control'));
        $this->assertSame('inline; filename="photo.png"', $d->headers->get('Content-Disposition'));
        $this->assertSame($site->origin, $d->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame($png, $this->body($d));

        $r = $this->sendFile($site, $this->upload($pdf, 'Rechnung März.pdf', 'application/pdf'), [], $token);
        $d = $this->download($site, $r->json()['message']['attachments'][0]['id'], $token);

        $d->assertStatus(200);
        $this->assertSame('application/pdf', $d->headers->get('Content-Type'));
        $this->assertSame('nosniff', $d->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $d->headers->get('Cache-Control'));
        $this->assertSame(
            "attachment; filename=\"Rechnung_Marz.pdf\"; filename*=UTF-8''Rechnung_M%C3%A4rz.pdf",
            $d->headers->get('Content-Disposition')
        );
        $this->assertSame($pdf, $this->body($d));
    }

    public function test_foreign_attachments_are_404()
    {
        $site = $this->makeSite();
        $png = base64_decode(self::PNG);
        $r = $this->sendFile($site, $this->upload($png, 'photo.png'), ['text' => 'mine']);
        $token = $r->json()['token'];
        $mine = $r->json()['message']['attachments'][0]['id'];
        $conversation = Conversation::find(Message::find($r->json()['message']['id'])->conversation_id);

        // Never without the header.
        $this->download($site, $mine, null)->assertStatus(401);
        $this->download($site, $mine, $token)->assertStatus(200);

        // Another session on the same site.
        $other = $this->startChat($site, 'Someone else');
        $this->assertNotFound($this->download($site, $mine, $other['token']), 'other session');

        // An internal note's attachment, even with a message row; an
        // unpublished reply's; one on no thread at all.
        $note = $this->agentThread($conversation, Thread::TYPE_NOTE);
        $noteFile = $this->attach($note, 'internal.pdf', 'application/pdf', base64_decode(self::PDF));
        $this->assertNotFound($this->download($site, $noteFile->id, $token), 'note');
        $unpublished = $this->agentThread($conversation, Thread::TYPE_MESSAGE, false);
        $draftFile = $this->attach($unpublished, 'draft.pdf', 'application/pdf', base64_decode(self::PDF));
        $this->assertNotFound($this->download($site, $draftFile->id, $token), 'reply without a row');
        $loose = $this->attach(null, 'loose.pdf', 'application/pdf', base64_decode(self::PDF));
        $this->assertNotFound($this->download($site, $loose->id, $token), 'no thread');

        // Another site's visitor, on that site's endpoint; on this site's
        // endpoint the other site's token is no token at all.
        $site2 = $this->makeSite(['origin' => 'https://other.test', 'url' => 'https://other.test/']);
        $stranger = $this->startChat($site2, 'Hi');
        $this->assertNotFound($this->download($site2, $mine, $stranger['token']), 'other site');
        $this->download($site, $mine, $stranger['token'])->assertStatus(401);

        // Unknown ids, also beyond the integer range.
        foreach ([0, 2147483647, 2147483648, 9999999999] as $id) {
            $this->assertNotFound($this->download($site, $id, $token), 'unknown id '.$id);
        }
        // Outside the route's 1-10 digits only the preflight wildcard
        // matches the path: refused by the router, whatever the id.
        foreach (['12345678901', 'abc', '-1'] as $id) {
            $this->assertContains($this->download($site, $id, $token)->status(), [404, 405], 'path id '.$id);
        }

        // A customer email with a file cuts this unverified browser off: the
        // email row's file is not for it; its own file still is.
        $email = new Thread();
        $email->conversation_id        = $conversation->id;
        $email->type                   = Thread::TYPE_CUSTOMER;
        $email->status                 = Thread::STATUS_ACTIVE;
        $email->state                  = Thread::STATE_PUBLISHED;
        $email->body                   = '<p>From the inbox</p>';
        $email->source_type            = Thread::SOURCE_TYPE_EMAIL;
        $email->source_via             = Thread::PERSON_CUSTOMER;
        $email->customer_id            = $conversation->customer_id;
        $email->created_by_customer_id = $conversation->customer_id;
        $email->save();
        $emailFile = $this->attach($email, 'contract.pdf', 'application/pdf', base64_decode(self::PDF));
        \Eventy::action('conversation.customer_replied', $conversation, $email, $conversation->customer);
        $this->assertSame(Message::VIA_EMAIL, (int) Message::where('thread_id', $email->id)->value('via'));

        $this->assertNotFound($this->download($site, $emailFile->id, $token), 'email row after cut-off');
        $this->download($site, $mine, $token)->assertStatus(200);
    }

    public function test_agent_reply_attachment_is_downloadable_by_the_visitor()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Can I have the invoice?');
        $pdf = base64_decode(self::PDF);
        $png = base64_decode(self::PNG);
        $reply = $this->agentThread($chat['conversation']);
        $file = $this->attach($reply, 'Invoice 42.pdf', 'application/pdf', $pdf);
        $inline = $this->attach($reply, 'logo.png', 'image/png', $png, true);

        $poll = $this->api('GET', $site, '/messages', $chat['token'])->json();

        $this->assertSame('agent', $poll['messages'][1]['role']);
        $this->assertSame([
            ['id' => (int) $file->id, 'name' => 'Invoice_42.pdf', 'mime' => 'application/pdf', 'size' => strlen($pdf)],
            ['id' => (int) $inline->id, 'name' => 'logo.png', 'mime' => 'image/png', 'size' => strlen($png)],
        ], $poll['messages'][1]['attachments']);
        $this->assertSame([], $poll['messages'][0]['attachments']);

        $d = $this->download($site, $file->id, $chat['token']);
        $d->assertStatus(200);
        $this->assertSame('application/pdf', $d->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="Invoice_42.pdf"', $d->headers->get('Content-Disposition'));
        $this->assertSame($pdf, $this->body($d));

        $d = $this->download($site, $inline->id, $chat['token']);
        $d->assertStatus(200);
        $this->assertSame('inline; filename="logo.png"', $d->headers->get('Content-Disposition'));
    }

    /**
     * An agent may attach any file. Anything outside the five types reaches
     * the browser as an opaque download: a blob URL made from text/html or
     * image/svg+xml would run in the shop's own origin.
     */
    public function test_agent_files_of_other_types_download_as_octet_stream()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Hello');
        $reply = $this->agentThread($chat['conversation']);
        $html = $this->attach($reply, 'notes.html', 'text/html', base64_decode(self::HTML));
        $svg = $this->attach($reply, 'logo.svg', 'image/svg+xml', base64_decode(self::SVG));

        $listed = $this->api('GET', $site, '/messages', $chat['token'])->json()['messages'][1]['attachments'];
        $this->assertSame(['application/octet-stream', 'application/octet-stream'], array_column($listed, 'mime'));

        foreach ([$html, $svg] as $attachment) {
            $d = $this->download($site, $attachment->id, $chat['token']);
            $d->assertStatus(200);
            $this->assertSame('application/octet-stream', $d->headers->get('Content-Type'));
            $this->assertStringStartsWith('attachment;', $d->headers->get('Content-Disposition'));
            $this->assertSame('nosniff', $d->headers->get('X-Content-Type-Options'));
        }
    }

    // ----------------------------------------------------------------- limits

    public function test_status_reports_the_effective_limit()
    {
        $site = $this->makeSite(['max_attachment_mb' => 7]);
        $post = ChatAttachment::iniBytes((string) ini_get('post_max_size'));
        $php = min(
            ChatAttachment::iniBytes((string) ini_get('upload_max_filesize')) ?: PHP_INT_MAX,
            $post > 0 ? $post - ChatAttachment::POST_HEADROOM : PHP_INT_MAX
        );
        $expected = ['attachment_mb' => max(1, min(7, intdiv($php, 1048576)))];

        $chat = $this->startChat($site);
        $this->assertSame($expected, $chat['response']->json()['limits']);
        $this->assertSame($expected, ChatAttachment::effectiveLimitMb($site) === $expected['attachment_mb'] ? $expected : null);

        $poll = $this->api('GET', $site, '/messages', $chat['token'])->json();
        $this->assertSame($expected, $poll['limits']);

        $identity = $this->api('POST', $site, '/identity', $chat['token'], ['email' => 'anna@example.test'])->json();
        $this->assertSame($expected, $identity['limits']);

        // The PHP caps, which the container's ini can't show.
        $this->assertSame(10, ChatAttachment::limitFor(10, '64M', '0'));
        // post_max_size less POST_HEADROOM (OversizedUploadTest has more).
        $this->assertSame(7, ChatAttachment::limitFor(10, '8M', '8M'));
        $this->assertSame(2, ChatAttachment::limitFor(10, '2M', '8M'));
        $this->assertSame(2, ChatAttachment::limitFor(5, '64M', '3M'));
        $this->assertSame(1, ChatAttachment::limitFor(10, '1500K', '8M'));
        $this->assertSame(1, ChatAttachment::limitFor(10, '512K', '8M'));
        $this->assertSame(2, ChatAttachment::limitFor(10, '2097152', '1G'));
        $this->assertSame(10, ChatAttachment::limitFor(0, '', ''));
        $this->assertSame(8388608, ChatAttachment::iniBytes('8M'));
        $this->assertSame(8388608, ChatAttachment::iniBytes(' 8m '));
        $this->assertSame(1073741824, ChatAttachment::iniBytes('1G'));
        $this->assertSame(524288, ChatAttachment::iniBytes('512K'));
        $this->assertSame(0, ChatAttachment::iniBytes('0'));
    }
}
