<?php

namespace Modules\WordPressChat\Tests;

use App\Attachment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
