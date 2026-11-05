<?php

namespace Modules\WordPressChat\Tests;

use App\Attachment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Services\IdentityToken;
use Tests\TestCase as CoreTestCase;

abstract class TestCase extends CoreTestCase
{
    use DatabaseTransactions;

    /**
     * The highest Attachment id that already existed before this test ran.
     * See deleteAttachmentFilesWrittenDuringThisTest() below for why this
     * matters: it is what keeps that method from touching a single row it
     * did not create itself.
     *
     * Null (not 0) until setUp() completes: null means "never recorded",
     * and the cleanup refuses to run in that state. This distinction is
     * load-bearing — see the incident note on the cleanup method.
     */
    private $attachmentIdWatermark = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'testing') {
            $this->fail(
                'Tests must run on the "testing" DB connection but "'.config('database.default').'" is active. '
                .'Run "make artisan c=config:clear" and try again.'
            );
        }

        if (!\Module::isActive('wordpresschat')) {
            $this->markTestSkipped('The wordpresschat module must be active in the testing database.');
        }

        $this->attachmentIdWatermark = (int) Attachment::max('id');
    }

    protected function tearDown(): void
    {
        $this->deleteAttachmentFilesWrittenDuringThisTest();

        // Option::$cache is a static that survives the DB rollback, so without
        // this a key written by one test is still readable by the next.
        \App\Option::$cache = [];

        parent::tearDown();
    }

    /**
     * Attachment::create() writes the file to disk *and* inserts a DB row.
     * DatabaseTransactions wraps this whole test in one connection
     * transaction and rolls it back after tearDown() — which discards the DB
     * row but cannot touch the disk half, since a filesystem write is not
     * part of the SQL transaction. Without this, every test that creates a
     * real attachment (e.g. via ProcessInboundMessage's media handling)
     * leaves a stray file behind on every single run, forever. Deleting the
     * files here (via the connection that is still open — parent::tearDown()
     * is what actually rolls it back) is the only place that can see them.
     *
     * Scoped to attachmentIdWatermark: this is a PUBLIC module whose suite
     * may run against a testing database seeded from a production dump (a
     * normal practice). Attachment::deleteForever() unlinks from the storage
     * disk, so unscoped `Attachment::all()` would permanently delete every
     * production attachment file in the shared storage/app/attachment tree
     * on every test run -- the method's name would describe the intent, not
     * the behaviour. Only rows created during this test (id above the
     * watermark recorded in setUp()) are ever touched.
     */
    private function deleteAttachmentFilesWrittenDuringThisTest(): void
    {
        // INCIDENT GUARDS (2026-07-30, real production data loss on a dev
        // install): with a stale config cache the suite ran on the LIVE
        // mysql connection. setUp()'s DB guard fail()ed BEFORE the
        // watermark was recorded, PHPUnit still ran tearDown(), and this
        // method's query — then `where('id', '>', 0)` — matched every
        // production attachment. deleteForever() removed the real files
        // permanently while its DB deletes were undone by the
        // test-transaction rollback (disk writes are not transactional):
        // files gone, rows intact, nothing in any log. Hence two refusals,
        // each sufficient on its own:
        //
        // 1. A null watermark means setUp() never completed — this method
        //    then has no idea what it owns, so it owns nothing.
        // 2. Whatever the watermark says, never run against anything but
        //    the `testing` connection. Checked here and not only in setUp()
        //    because setUp()'s check is precisely what the incident proved
        //    can be bypassed.
        if ($this->attachmentIdWatermark === null) {
            return;
        }

        if (config('database.default') !== 'testing') {
            return;
        }

        $attachments = Attachment::where('id', '>', $this->attachmentIdWatermark)->get();

        if ($attachments->isNotEmpty()) {
            Attachment::deleteForever($attachments);
        }
    }

    /**
     * This is a public module: its suite must pass against a freshly migrated
     * database belonging to whoever installs it, so fixtures are created via
     * factories rather than assumed to pre-exist. Note the User factory does
     * not set `role`, so it must be passed explicitly.
     */
    protected function adminUser(): \App\User
    {
        return factory(\App\User::class)->create(['role' => \App\User::ROLE_ADMIN]);
    }

    protected function regularUser(): \App\User
    {
        return factory(\App\User::class)->create(['role' => \App\User::ROLE_USER]);
    }

    protected function testMailbox(): \App\Mailbox
    {
        return factory(\App\Mailbox::class)->create();
    }

    /** A site with a random public id, a secret and a fresh mailbox. $attrs override any column. */
    protected function makeSite(array $attrs = []): Site
    {
        $site = new Site(array_merge([
            'name'       => 'Shop',
            'url'        => 'https://shop.test/',
            'origin'     => 'https://shop.test',
            'mailbox_id' => $this->testMailbox()->id,
            'public_id'  => bin2hex(random_bytes(16)),
        ], $attrs));
        $site->setSecret('s');
        $site->save();

        return $site;
    }

    /**
     * Calls a public chat API route of $site as the widget would: with the
     * site's Origin, JSON accepted, and the visitor's token when given.
     * $server overrides any server variable, e.g. ['REMOTE_ADDR' => …] for
     * a test that needs its own client address under the rate limits.
     * A string $data is sent as the raw body (e.g. malformed JSON).
     */
    protected function api(string $method, Site $site, string $path, ?string $token = null, $data = null, array $headers = [], array $server = []): \Illuminate\Foundation\Testing\TestResponse
    {
        $server = array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN'  => $site->origin,
            'HTTP_ACCEPT'  => 'application/json',
        ], $server);
        if ($token !== null) {
            $server['HTTP_X_CHAT_TOKEN'] = $token;
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call(
            $method,
            '/wordpress-chat/api/v1/sites/'.$site->public_id.$path,
            [], [], [], $server,
            $data === null ? null : (is_string($data) ? $data : json_encode($data))
        );
    }

    /**
     * Like api(), but as a multipart form post: $fields go into the form
     * body, $files (field name => UploadedFile) into the upload bag.
     */
    protected function apiMultipart(string $method, Site $site, string $path, ?string $token, array $fields, array $files = [], array $headers = [], array $server = []): \Illuminate\Foundation\Testing\TestResponse
    {
        $server = array_merge([
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----wpc-test',
            'HTTP_ORIGIN'  => $site->origin,
            'HTTP_ACCEPT'  => 'application/json',
        ], $server);
        if ($token !== null) {
            $server['HTTP_X_CHAT_TOKEN'] = $token;
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call(
            $method,
            '/wordpress-chat/api/v1/sites/'.$site->public_id.$path,
            $fields, [], $files, $server
        );
    }

    /**
     * Forgets every rate-limit counter (rows of this test's transaction).
     * For a test that sends one visitor's attempts faster than the limits
     * allow, to check the controller's answer to each: Throttle and its
     * RULES stay as they are. A test over an address limit takes its own
     * client addresses instead (the $server of api()).
     */
    protected function forgetRateLimits(): void
    {
        \DB::table('wordpresschat_rate_limits')->delete();
    }

    /**
     * Waits for the next window when fewer than $margin seconds of the
     * current one are left, so that every request of the test lands in one
     * window: a boundary in between would reset the counts and let a
     * request through that the test expects refused.
     */
    protected function freshWindow(int $window, int $margin = 5): void
    {
        $left = $window - time() % $window;

        if ($left < $margin) {
            sleep($left);
        }
    }

    /** A chat token the way the widget makes one: 32 random bytes, base64url, no padding. */
    protected function newChatToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * A login identity token as the WordPress plugin seals it for a visitor
     * holding $chatToken: valid now, random jti. $over replaces any key.
     */
    protected function loginToken(Site $site, string $chatToken, array $over = []): string
    {
        // One clock read: two could straddle a second and make exp - iat 301.
        $now = time();

        return IdentityToken::seal(array_merge([
            'typ'   => 'login',
            'sid'   => $site->public_id,
            'bind'  => hash('sha256', $chatToken),
            'iat'   => $now,
            'exp'   => $now + IdentityToken::MAX_AGE,
            'jti'   => bin2hex(random_bytes(16)),
            'uid'   => 42,
            'email' => 'anna@example.test',
            'name'  => 'Anna Example',
        ], $over), $site->getSecret(), $site->public_id);
    }

    /**
     * An order identity token as the WordPress plugin seals it for a visitor
     * holding $chatToken: valid now, random jti, order found. $over replaces
     * any key.
     */
    protected function orderToken(Site $site, string $chatToken, array $over = []): string
    {
        // One clock read: two could straddle a second and make exp - iat 301.
        $now = time();

        return IdentityToken::seal(array_merge([
            'typ'   => 'order',
            'sid'   => $site->public_id,
            'bind'  => hash('sha256', $chatToken),
            'iat'   => $now,
            'exp'   => $now + IdentityToken::MAX_AGE,
            'jti'   => bin2hex(random_bytes(16)),
            'num'   => '10482',
            'found' => true,
            'email' => 'anna@example.test',
            'name'  => 'Anna Example',
        ], $over), $site->getSecret(), $site->public_id);
    }

    /**
     * Counts the transaction rollbacks (savepoints included) from now on.
     * Returns a closure that gives the count so far: a race test asserts
     * exactly 1 to prove it took the catch path.
     */
    protected function countRollbacks(): callable
    {
        $count = 0;
        \Event::listen(\Illuminate\Database\Events\TransactionRolledBack::class, function () use (&$count) {
            $count++;
        });

        return function () use (&$count) {
            return $count;
        };
    }

    /**
     * The SELECT statements $callback runs against $table, grammar-neutral
     * (MariaDB backticks, PostgreSQL double quotes).
     */
    protected function selectsOn(string $table, callable $callback): array
    {
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $callback();
        $log = \DB::getQueryLog();
        \DB::disableQueryLog();

        return array_values(array_filter(array_column($log, 'query'), function ($sql) use ($table) {
            $sql = str_replace(['`', '"'], '', $sql);

            return stripos($sql, 'select') === 0 && preg_match('/\bfrom '.preg_quote($table, '/').'\b/i', $sql);
        }));
    }

    /**
     * Starts a chat with a tokenless first send. Returns token, session,
     * conversation and the raw response. $server goes to api().
     */
    protected function startChat(Site $site, string $text = 'Hello', array $body = [], array $server = []): array
    {
        $new = $this->newChatToken();
        $response = $this->api('POST', $site, '/messages', null, array_merge([
            'text'              => $text,
            'client_message_id' => bin2hex(random_bytes(8)),
            'new_token'         => $new,
        ], $body), [], $server);
        $response->assertStatus(201);

        $token = $response->json()['token'];
        $message = \Modules\WordPressChat\Entities\Message::find($response->json()['message']['id']);

        return [
            'token'        => $token,
            'session'      => \Modules\WordPressChat\Entities\Session::find($message->session_id),
            'conversation' => \App\Conversation::find($message->conversation_id),
            'response'     => $response,
        ];
    }
}
