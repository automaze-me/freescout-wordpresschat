<?php

namespace Modules\WordPressChat\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Tests\TestCase;

class DataModelTest extends TestCase
{
    private function message(array $attrs = [])
    {
        return Message::create($attrs + [
            'conversation_id' => 1,
            'thread_id'       => 1,
            'session_id'      => 1,
            'direction'       => Message::DIRECTION_IN,
        ]);
    }

    public function test_site_secret_is_encrypted_at_rest()
    {
        $site = new Site([
            'name'       => 'Shop',
            'url'        => 'https://example.com',
            'origin'     => 'https://example.com',
            'mailbox_id' => $this->testMailbox()->id,
            'public_id'  => str_repeat('a', 32),
        ]);
        $site->setSecret('abc');
        $site->save();

        $raw = DB::table('wordpresschat_sites')->where('id', $site->id)->value('secret');

        $this->assertNotSame('abc', $raw);
        $this->assertSame('abc', Site::find($site->id)->getSecret());
    }

    public function test_message_thread_id_is_unique()
    {
        $this->message(['thread_id' => 77]);

        $this->expectException(QueryException::class);
        // A savepoint around the violating insert: on PostgreSQL a failed
        // statement would otherwise abort the test's own transaction.
        DB::transaction(function () {
            $this->message(['thread_id' => 77, 'client_message_id' => 'x']);
        });
    }

    public function test_client_message_id_unique_per_session()
    {
        $this->message(['thread_id' => 1, 'session_id' => 1, 'client_message_id' => 'c1']);
        // Same client id in a different session is fine.
        $this->message(['thread_id' => 2, 'session_id' => 2, 'client_message_id' => 'c1']);
        $this->assertSame(2, Message::where('client_message_id', 'c1')->count());

        $this->expectException(QueryException::class);
        // A savepoint, as above.
        DB::transaction(function () {
            $this->message(['thread_id' => 3, 'session_id' => 1, 'client_message_id' => 'c1']);
        });
    }
}
