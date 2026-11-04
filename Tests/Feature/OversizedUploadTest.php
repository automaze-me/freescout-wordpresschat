<?php

namespace Modules\WordPressChat\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Http\Middleware\OversizedUpload;
use Modules\WordPressChat\Services\ChatAttachment;
use Modules\WordPressChat\Tests\TestCase;

/**
 * An upload larger than PHP's post_max_size (spec §4: a message never
 * disappears silently).
 *
 * Laravel's global ValidatePostSize answers such a request with 413 before
 * any route runs, so the route's Cors never adds its headers and the
 * widget's cross-origin fetch sees a network error, which it offers to
 * retry forever. OversizedUpload answers it first, as the controller
 * answers a file over the limit, with the CORS headers of the site.
 */
class OversizedUploadTest extends TestCase
{
    /** One byte more than PHP accepts in a request body. */
    private function overLimit(): int
    {
        $max = ChatAttachment::iniBytes((string) ini_get('post_max_size'));
        if ($max <= 0) {
            $this->markTestSkipped('post_max_size is unlimited here, so no request can exceed it.');
        }

        return $max + 1;
    }

    /**
     * A POST to $path whose Content-Length says $length bytes. The body
     * itself is empty: PHP drops an oversized body before any code runs,
     * which is exactly what the app sees.
     */
    private function postWithLength(string $path, int $length, array $server = [])
    {
        return $this->call('POST', $path, [], [], [], array_merge([
            'CONTENT_TYPE'   => 'multipart/form-data; boundary=----wpc-test',
            'CONTENT_LENGTH' => (string) $length,
            'HTTP_ACCEPT'    => 'application/json',
        ], $server));
    }

    private function messagesPath(Site $site): string
    {
        return '/wordpress-chat/api/v1/sites/'.$site->public_id.'/messages';
    }

    private function rowCounts(): array
    {
        return ['sessions' => Session::count(), 'messages' => Message::count()];
    }

    public function test_the_provider_puts_it_in_front_of_the_global_stack()
    {
        $kernel = $this->app[\Illuminate\Contracts\Http\Kernel::class];

        $this->assertTrue($kernel->hasMiddleware(OversizedUpload::class));

        $stack = (function () {
            return $this->middleware;
        })->call($kernel);
        // Ahead of ValidatePostSize, which would answer first otherwise.
        $this->assertLessThan(
            array_search(\Illuminate\Foundation\Http\Middleware\ValidatePostSize::class, $stack, true),
            array_search(OversizedUpload::class, $stack, true)
        );
    }

    public function test_an_oversized_send_gets_422_too_large_with_the_sites_cors_headers()
    {
        $site = $this->makeSite();
        $before = $this->rowCounts();

        $r = $this->postWithLength($this->messagesPath($site), $this->overLimit(), ['HTTP_ORIGIN' => $site->origin]);

        $r->assertStatus(422)->assertExactJson(['error' => 'validation', 'field' => 'file', 'reason' => 'too_large']);
        // Exactly what Cors sets on every answer of the route.
        $this->assertSame($site->origin, $r->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('Origin', $r->headers->get('Vary'));
        $this->assertSame('GET, POST, OPTIONS', $r->headers->get('Access-Control-Allow-Methods'));
        $this->assertSame('Content-Type, X-Chat-Token, X-Chat-Page', $r->headers->get('Access-Control-Allow-Headers'));
        $this->assertSame('600', $r->headers->get('Access-Control-Max-Age'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertSame($before, $this->rowCounts());
    }

    public function test_an_oversized_send_with_a_token_is_answered_the_same()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);

        $r = $this->postWithLength($this->messagesPath($site), $this->overLimit(), [
            'HTTP_ORIGIN'       => $site->origin,
            'HTTP_X_CHAT_TOKEN' => $chat['token'],
        ]);

        $r->assertStatus(422)->assertExactJson(['error' => 'validation', 'field' => 'file', 'reason' => 'too_large']);
        $this->assertSame($site->origin, $r->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_another_origin_gets_no_cors_headers()
    {
        $site = $this->makeSite();

        $r = $this->postWithLength($this->messagesPath($site), $this->overLimit(), ['HTTP_ORIGIN' => 'https://evil.test']);

        // As Cors answers any request from an origin that isn't the site's.
        $r->assertStatus(403)->assertExactJson(['error' => 'origin']);
        $this->assertNull($r->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_without_an_origin_it_answers_without_cors_headers()
    {
        $site = $this->makeSite();

        $r = $this->postWithLength($this->messagesPath($site), $this->overLimit());

        $r->assertStatus(422)->assertExactJson(['error' => 'validation', 'field' => 'file', 'reason' => 'too_large']);
        $this->assertNull($r->headers->get('Access-Control-Allow-Origin'));
    }

    /** ValidatePostSize still answers everything else (413), as before. */
    public function test_other_requests_over_the_limit_are_not_intercepted()
    {
        $site = $this->makeSite();
        $over = $this->overLimit();
        $origin = ['HTTP_ORIGIN' => $site->origin];
        $other = '/wordpress-chat/api/v1/sites/'.$site->public_id;

        $cases = [
            'another module endpoint' => $this->postWithLength($other.'/seen', $over, $origin),
            'identity endpoint'       => $this->postWithLength($other.'/identity', $over, $origin),
            'a core path'             => $this->postWithLength('/conversation/ajax', $over, $origin),
            'an unknown site'         => $this->postWithLength('/wordpress-chat/api/v1/sites/'.str_repeat('0', 32).'/messages', $over, $origin),
            'a longer path'           => $this->postWithLength($this->messagesPath($site).'/x', $over, $origin),
            'a path prefix'           => $this->postWithLength('/x'.$this->messagesPath($site), $over, $origin),
        ];

        foreach ($cases as $case => $r) {
            $this->assertSame(413, $r->status(), $case);
            $this->assertNull($r->headers->get('Access-Control-Allow-Origin'), $case);
        }

        // Not a POST: the middleware leaves it alone, whatever the length says.
        $get = $this->call('GET', $this->messagesPath($site), [], [], [], array_merge($origin, [
            'CONTENT_LENGTH' => (string) $over,
            'HTTP_ACCEPT'    => 'application/json',
        ]));
        $this->assertSame(413, $get->status(), 'a GET');
    }

    public function test_requests_within_the_limit_pass_through()
    {
        $site = $this->makeSite();

        // A JSON send whose Content-Length is within post_max_size.
        $body = json_encode(['text' => 'Hi', 'client_message_id' => 'oversize-ok-1', 'new_token' => $this->newChatToken()]);
        $r = $this->call('POST', $this->messagesPath($site), [], [], [], [
            'CONTENT_TYPE'   => 'application/json',
            'CONTENT_LENGTH' => (string) strlen($body),
            'HTTP_ORIGIN'    => $site->origin,
            'HTTP_ACCEPT'    => 'application/json',
        ], $body);
        $r->assertStatus(201);
        $this->assertSame($site->origin, $r->headers->get('Access-Control-Allow-Origin'));

        // A multipart send with a real file.
        $path = tempnam(sys_get_temp_dir(), 'wpc-up-');
        file_put_contents($path, base64_decode(AttachmentTest::PNG));
        try {
            $r = $this->apiMultipart('POST', $site, '/messages', null, [
                'client_message_id' => 'oversize-ok-2',
                'new_token'         => $this->newChatToken(),
            ], ['file' => new UploadedFile($path, 'pic.png', 'image/png', null, null, true)]);
            $r->assertStatus(201);
            $this->assertCount(1, $r->json()['message']['attachments']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * When post_max_size is what limits the file, the multipart body around
     * it (boundaries, the text, the tokens) must still fit: the announced
     * limit stays POST_HEADROOM under it.
     */
    public function test_the_limit_leaves_headroom_under_post_max_size()
    {
        $this->assertSame(65536, ChatAttachment::POST_HEADROOM);

        // post_max_size = upload_max_filesize = the site's limit.
        $this->assertSame(7, ChatAttachment::limitFor(8, '8M', '8M'));
        $this->assertSame(63, ChatAttachment::limitFor(64, '64M', '64M'));
        $this->assertSame(9, ChatAttachment::limitFor(10, '10M', '10M'));
        foreach ([['8M', '8M'], ['10M', '10M'], ['20M', '20M'], ['64M', '64M'], ['20M', '64M']] as $ini) {
            $mb = ChatAttachment::limitFor(100, $ini[0], $ini[1]);
            $this->assertLessThanOrEqual(
                ChatAttachment::iniBytes($ini[1]),
                $mb * ChatAttachment::MB + ChatAttachment::POST_HEADROOM,
                implode('/', $ini)
            );
        }

        // Room to spare already: nothing changes.
        $this->assertSame(10, ChatAttachment::limitFor(10, '64M', '64M'));
        $this->assertSame(8, ChatAttachment::limitFor(10, '8M', '9M'));
        $this->assertSame(2, ChatAttachment::limitFor(10, '2M', '8M'));
        $this->assertSame(10, ChatAttachment::limitFor(10, '64M', '0'));
        $this->assertSame(10, ChatAttachment::limitFor(0, '', ''));
        // Never below 1 MB.
        $this->assertSame(1, ChatAttachment::limitFor(10, '1M', '1M'));
    }
}
