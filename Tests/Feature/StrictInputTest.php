<?php

namespace Modules\WordPressChat\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Tests\TestCase;

class StrictInputTest extends TestCase
{
    const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC';

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

    /** A valid PNG, padded with trailing zeros to $padTo bytes (finfo still sees a PNG). */
    private function png(int $padTo = 0): UploadedFile
    {
        $bytes = base64_decode(self::PNG);
        $bytes .= str_repeat("\0", max(0, $padTo - strlen($bytes)));
        $path = tempnam(sys_get_temp_dir(), 'wpc-up-');
        file_put_contents($path, $bytes);
        $this->tmpFiles[] = $path;

        return new UploadedFile($path, 'photo.png', 'image/png', strlen($bytes), null, true);
    }

    private function body(array $over = []): array
    {
        return array_merge([
            'text'              => 'Hello',
            'client_message_id' => bin2hex(random_bytes(8)),
            'new_token'         => $this->newChatToken(),
        ], $over);
    }

    public function test_honeypot_is_422_and_stores_nothing()
    {
        $site = $this->makeSite();
        $sessions = Session::count();

        $r = $this->api('POST', $site, '/messages', null, $this->body(['hp' => 'x']));
        $this->assertSame($sessions, Session::count());
        $r->assertStatus(422);
        $this->assertSame('validation', $r->json()['error']);
        $this->assertSame('hp', $r->json()['field']);

        $chat = $this->startChat($site);
        $messages = Message::count();
        $sessions = Session::count();
        $r = $this->api('POST', $site, '/messages', $chat['token'], $this->body(['hp' => 'x']));
        $r->assertStatus(422);
        $this->assertSame('hp', $r->json()['field']);
        $this->assertSame($messages, Message::count());
        $this->assertSame($sessions, Session::count());

        $before = Session::count();
        $this->api('POST', $site, '/messages', null, $this->body(['hp' => ['x']]))->assertStatus(422);
        $this->assertSame($before, Session::count());
    }

    public function test_empty_honeypot_is_accepted()
    {
        $site = $this->makeSite();

        $this->api('POST', $site, '/messages', null, $this->body(['hp' => '']))->assertStatus(201);
        $this->api('POST', $site, '/messages', null, $this->body(['hp' => '  ']))->assertStatus(201);
    }

    public function test_5001_characters_is_422()
    {
        $site = $this->makeSite();

        $this->api('POST', $site, '/messages', null, $this->body(['text' => str_repeat('a', 5000)]))->assertStatus(201);

        $r = $this->api('POST', $site, '/messages', null, $this->body(['text' => str_repeat('a', 5001)]));
        $r->assertStatus(422);
        $this->assertSame('text', $r->json()['field']);
    }

    public function test_non_string_text_with_a_file_is_422()
    {
        $site = $this->makeSite();
        $sessions = Session::count();

        $r = $this->apiMultipart('POST', $site, '/messages', null, $this->body(['text' => ['a']]), ['file' => $this->png()]);

        $r->assertStatus(422);
        $this->assertSame('text', $r->json()['field']);
        $this->assertSame($sessions, Session::count());
    }

    public function test_retry_of_a_stored_file_message_replays_before_validation()
    {
        $site = $this->makeSite();
        $fields = $this->body(['text' => 'Photo']);

        $site->max_attachment_mb = 5;
        $site->save();
        $big = 1300000;

        $first = $this->apiMultipart('POST', $site, '/messages', null, $fields, ['file' => $this->png($big)]);
        $first->assertStatus(201);
        $id = $first->json()['message']['id'];

        // The limit drops below the stored file.
        $site->max_attachment_mb = 1;
        $site->save();
        $this->assertSame(1, \Modules\WordPressChat\Services\ChatAttachment::effectiveLimitMb($site->fresh()));

        // A first-message retry (new_token) ...
        $retry = $this->apiMultipart('POST', $site, '/messages', null, $fields, ['file' => $this->png($big)]);
        $retry->assertStatus(200);
        $this->assertSame($id, $retry->json()['message']['id']);

        // ... and one with the chat token.
        $token = $first->json()['token'];
        $retry = $this->apiMultipart('POST', $site, '/messages', $token, array_diff_key($fields, ['new_token' => 1]), ['file' => $this->png($big)]);
        $retry->assertStatus(200);
        $this->assertSame($id, $retry->json()['message']['id']);

        // A different text under the same id is still a conflict.
        $this->apiMultipart('POST', $site, '/messages', $token, ['text' => 'Other'] + $fields, ['file' => $this->png($big)])->assertStatus(409);

        // A new message with that file is refused under the lowered limit.
        $new = $this->apiMultipart('POST', $site, '/messages', $token, ['client_message_id' => 'other-id-0001', 'text' => 'x'], ['file' => $this->png($big)]);
        $new->assertStatus(422);
        $this->assertSame('file', $new->json()['field']);
    }

    public function test_every_422_carries_limits()
    {
        $site = $this->makeSite();
        $expected = \Modules\WordPressChat\Services\ChatAttachment::effectiveLimitMb($site);
        $this->forgetRateLimits();

        $cases = [
            'text'              => $this->body(['text' => '']),
            'client_message_id' => $this->body(['client_message_id' => 'x']),
            'hp'                => $this->body(['hp' => 'x']),
            'new_token'         => $this->body(['new_token' => 'short']),
        ];
        foreach ($cases as $field => $body) {
            $r = $this->api('POST', $site, '/messages', null, $body);
            $r->assertStatus(422);
            $this->assertSame($field, $r->json()['field']);
            $this->assertSame($expected, $r->json()['limits']['attachment_mb'], $field);
        }

        $r = $this->api('POST', $site, '/messages', null, $this->body(['file' => 'nonsense']));
        $r->assertStatus(422);
        $this->assertSame('file', $r->json()['field']);
        $this->assertSame($expected, $r->json()['limits']['attachment_mb']);
    }

    public function test_identity_unlinked_answer_carries_limits()
    {
        $site = $this->makeSite();
        $new = $this->newChatToken();
        $login = $this->loginToken($site, $new, ['uid' => 987654]);

        $r = $this->api('POST', $site, '/identity', null, ['login_token' => $login, 'new_token' => $new]);

        $r->assertStatus(200);
        $this->assertFalse($r->json()['linked']);
        $this->assertArrayHasKey('attachment_mb', $r->json()['limits']);
    }

    public function test_status_carries_features()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $token = $chat['token'];

        $this->assertSame(['page'], $chat['response']->json()['features']);
        $this->assertSame(['page'], $this->api('GET', $site, '/messages', $token)->json()['features']);

        $r = $this->api('POST', $site, '/identity', $token, ['login_token' => $this->loginToken($site, $token)]);
        $r->assertStatus(200);
        $this->assertSame(['page'], $r->json()['features']);

        $url = \Modules\WordPressChat\Services\ContinueLink::mint($chat['conversation']);
        preg_match('/#lcff-continue=([A-Za-z0-9_-]{43})$/', $url, $m);
        $r = $this->api('POST', $site, '/continue', null, ['link' => $m[1], 'new_token' => $this->newChatToken()]);
        $r->assertStatus(200);
        $this->assertSame(['page'], $r->json()['features']);
    }
}
