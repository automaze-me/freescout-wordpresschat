<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\Thread;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Tests\TestCase;

class PollAndSeenTest extends TestCase
{
    const ORIGIN = 'https://shop.test';

    private $n = 0;

    /** Sends a visitor message via the real endpoint; returns [token, message id, conversation id]. */
    private function visitorSays(Site $site, $text, $token = null)
    {
        $data = ['text' => $text, 'client_message_id' => 'cmid-'.str_pad(++$this->n, 6, '0', STR_PAD_LEFT)];
        if ($token === null) {
            // The widget's own token for its first message.
            $data['new_token'] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        }
        $r = $this->api('POST', $site, '/messages', $token, $data);
        $json = $r->json();
        $id = $json['message']['id'];

        return [$token ?: $json['token'], $id, Message::find($id)->conversation_id];
    }

    private function agentThread($conversationId, $type = null, $withRow = true, $published = true, $agent = null)
    {
        $agent = $agent ?: $this->adminUser();
        $thread = new Thread();
        $thread->conversation_id = $conversationId;
        $thread->type = $type === null ? Thread::TYPE_MESSAGE : $type;
        $thread->status = Thread::STATUS_ACTIVE;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->body = '<p>Agent reply</p>';
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->user_id = $agent->id;
        $thread->created_by_user_id = $agent->id;
        $thread->source_via = Thread::PERSON_USER;
        $thread->save();

        if (!$withRow) {
            return [$thread, null];
        }

        $row = Message::create([
            'conversation_id' => $conversationId,
            'thread_id'       => $thread->id,
            'direction'       => Message::DIRECTION_OUT,
            'published_at'    => $published ? now() : null,
        ]);

        return [$thread, $row];
    }

    private function poll(Site $site, $token, $after = null)
    {
        return $this->api('GET', $site, '/messages'.($after === null ? '' : '?after='.$after), $token);
    }

    public function test_a_poll_resolves_grants_once()
    {
        $site = $this->makeSite();
        list($token) = $this->visitorSays($site, 'hello');
        $table = (new \Modules\WordPressChat\Entities\Access())->getTable();

        $selects = $this->selectsOn($table, function () use ($site, $token) {
            $this->poll($site, $token, 0)->assertStatus(200);
        });

        // The auth lookup reads sessions, not access: one grants read.
        $this->assertCount(1, $selects, implode("\n", $selects));
    }

    public function test_poll_returns_visitor_messages_and_published_replies_in_order()
    {
        $site = $this->makeSite();
        list($token, $m1, $conv) = $this->visitorSays($site, 'first');
        list($t, $reply) = $this->agentThread($conv);
        list(, $m3) = $this->visitorSays($site, 'third', $token);

        $r = $this->poll($site, $token);
        $r->assertStatus(200);
        $json = $r->json();

        $this->assertSame([$m1, $reply->id, $m3], array_column($json['messages'], 'id'));
        $this->assertSame(['visitor', 'agent', 'visitor'], array_column($json['messages'], 'role'));
        $this->assertSame('first', $json['messages'][0]['text']);
        $this->assertSame('Agent reply', trim($json['messages'][1]['text']));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $json['conversation']['key']);
        $this->assertSame(
            ['status' => 'open', 'poll' => true, 'key' => $json['conversation']['key'], 'notice' => null],
            $json['conversation']
        );
        $this->assertSame(['level' => 'anonymous', 'account' => false], $json['identity']);
    }

    public function test_agent_reply_carries_the_agent_photo()
    {
        $site = $this->makeSite();
        list($token, , $conv) = $this->visitorSays($site, 'first');
        $agent = $this->adminUser();
        $agent->photo_url = 'agent-photo.jpg';
        $agent->save();
        $this->agentThread($conv, null, true, true, $agent);

        $msgs = $this->poll($site, $token)->json()['messages'];

        $this->assertSame($agent->getPhotoUrl(false), $msgs[1]['avatar']);
        $this->assertStringStartsWith('http', $msgs[1]['avatar']);
        $this->assertStringContainsString('agent-photo.jpg', $msgs[1]['avatar']);
    }

    public function test_agent_without_photo_and_visitor_rows_have_no_avatar()
    {
        $site = $this->makeSite();
        list($token, , $conv) = $this->visitorSays($site, 'first');
        $this->agentThread($conv);

        $msgs = $this->poll($site, $token)->json()['messages'];

        $this->assertArrayHasKey('avatar', $msgs[0]);
        $this->assertNull($msgs[0]['avatar']);
        $this->assertSame('agent', $msgs[1]['role']);
        $this->assertArrayHasKey('avatar', $msgs[1]);
        $this->assertNull($msgs[1]['avatar']);
    }

    public function test_unpublished_reply_and_notes_are_not_returned()
    {
        $site = $this->makeSite();
        list($token, $m1, $conv) = $this->visitorSays($site, 'first');
        $this->agentThread($conv, null, false);
        list(, $noteRow) = $this->agentThread($conv, Thread::TYPE_NOTE);
        list(, $pending) = $this->agentThread($conv, null, true, false);

        $ids = array_column($this->poll($site, $token)->json()['messages'], 'id');

        $this->assertSame([$m1], $ids);
        $this->assertNotContains($noteRow->id, $ids);
        $this->api('POST', $site, '/seen', $token, ['message_ids' => [$noteRow->id]])->assertStatus(204);
        $this->assertNull(Message::find($noteRow->id)->seen_at);
        $this->assertNotContains($pending->id, $ids);
    }

    public function test_after_cursor_limits_results()
    {
        $site = $this->makeSite();
        list($token, $m1, $conv) = $this->visitorSays($site, 'first');
        list(, $reply) = $this->agentThread($conv);

        $ids = array_column($this->poll($site, $token, $m1)->json()['messages'], 'id');
        $this->assertSame([$reply->id], $ids);

        $this->assertSame([], $this->poll($site, $token, $reply->id)->json()['messages']);
        $this->poll($site, $token, 'abc')->assertStatus(422);
        $this->assertSame(['error' => 'validation', 'field' => 'after'], $this->poll($site, $token, 'abc')->json());
        $this->poll($site, $token, -1)->assertStatus(422);
    }

    /**
     * Sets the conversation's status (and closed_at) and returns the poll's
     * `conversation` object without its key and notice, which are checked
     * here: the status never changes them.
     */
    private function conversationAfter(Site $site, $token, $conversationId, $status, $closedAt = null)
    {
        $conversation = Conversation::find($conversationId);
        $conversation->status = $status;
        $conversation->closed_at = $closedAt;
        $conversation->save();

        $r = $this->poll($site, $token);
        $r->assertStatus(200);
        $result = $r->json()['conversation'];

        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $result['key']);
        $this->assertArrayHasKey('notice', $result);
        $this->assertNull($result['notice']);
        unset($result['key'], $result['notice']);

        return $result;
    }

    public function test_poll_flag_follows_the_conversation_status()
    {
        $site = $this->makeSite();
        list($token, , $conv) = $this->visitorSays($site, 'first');

        $this->assertSame(['status' => 'open', 'poll' => true], $this->conversationAfter($site, $token, $conv, Conversation::STATUS_ACTIVE));
        $this->assertSame(['status' => 'open', 'poll' => true], $this->conversationAfter($site, $token, $conv, Conversation::STATUS_PENDING));
        $this->assertSame(['status' => 'open', 'poll' => false], $this->conversationAfter($site, $token, $conv, Conversation::STATUS_SPAM));
    }

    public function test_closed_conversation_polls_only_during_the_grace_period()
    {
        $site = $this->makeSite();
        list($token, , $conv) = $this->visitorSays($site, 'first');

        $this->assertSame(
            ['status' => 'closed', 'poll' => true],
            $this->conversationAfter($site, $token, $conv, Conversation::STATUS_CLOSED, now()->subSeconds(30))
        );
        $this->assertSame(
            ['status' => 'closed', 'poll' => false],
            $this->conversationAfter($site, $token, $conv, Conversation::STATUS_CLOSED, now()->subMinutes(10))
        );
        $this->assertSame(
            ['status' => 'closed', 'poll' => false],
            $this->conversationAfter($site, $token, $conv, Conversation::STATUS_CLOSED, null)
        );
    }

    public function test_deleted_conversation_does_not_poll()
    {
        $site = $this->makeSite();
        list($token, , $conv) = $this->visitorSays($site, 'first');
        $conversation = Conversation::find($conv);
        $conversation->state = Conversation::STATE_DELETED;
        $conversation->save();

        $this->assertFalse($this->poll($site, $token)->json()['conversation']['poll']);
    }

    public function test_ids_above_the_integer_range_are_422()
    {
        $site = $this->makeSite();
        list($token, $m1) = $this->visitorSays($site, 'first');

        $this->poll($site, $token, 2147483647)->assertStatus(200);
        $this->poll($site, $token, '2147483648')
            ->assertStatus(422)
            ->assertExactJson(['error' => 'validation', 'field' => 'after']);
        $this->poll($site, $token, '99999999999999')->assertStatus(422);

        $this->api('POST', $site, '/seen', $token, ['message_ids' => [2147483647]])->assertStatus(204);
        $this->api('POST', $site, '/seen', $token, ['message_ids' => [$m1, 2147483648]])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'validation', 'field' => 'message_ids']);
    }

    public function test_poll_without_conversation_returns_null_conversation()
    {
        $site = $this->makeSite();
        list($token) = $this->visitorSays($site, 'x');
        \Modules\WordPressChat\Entities\Access::query()->delete();

        $json = $this->poll($site, $token)->json();
        $this->assertSame([], $json['messages']);
        $this->assertNull($json['conversation']);
    }

    public function test_other_sessions_conversation_is_invisible()
    {
        $site = $this->makeSite();
        list($tokenA, $a1, $conv) = $this->visitorSays($site, 'secret A');
        list(, $reply) = $this->agentThread($conv);
        list($tokenB) = $this->visitorSays($site, 'hello B');

        $textsB = array_column($this->poll($site, $tokenB)->json()['messages'], 'text');
        $this->assertSame(['hello B'], $textsB);

        $this->api('POST', $site, '/seen', $tokenB, ['message_ids' => [$reply->id, $a1]])->assertStatus(204);
        $this->assertNull(Message::find($reply->id)->seen_at);
    }

    public function test_seen_marks_only_visible_agent_messages_once()
    {
        $site = $this->makeSite();
        list($token, $m1, $conv) = $this->visitorSays($site, 'first');
        list(, $reply) = $this->agentThread($conv);
        list(, $hidden) = $this->agentThread($conv, null, true, false);

        $this->api('POST', $site, '/seen', $token, ['message_ids' => [$m1, $reply->id, $hidden->id, 999999]])->assertStatus(204);

        $this->assertNull(Message::find($m1)->seen_at);
        $this->assertNull(Message::find($hidden->id)->seen_at);
        $first = Message::find($reply->id)->seen_at;
        $this->assertNotNull($first);

        Message::where('id', $reply->id)->update(['seen_at' => '2000-01-01 00:00:00']);
        $this->api('POST', $site, '/seen', $token, ['message_ids' => [$reply->id]])->assertStatus(204);
        $this->assertSame('2000-01-01 00:00:00', Message::find($reply->id)->seen_at->format('Y-m-d H:i:s'));
    }

    public function test_seen_validates_input()
    {
        $site = $this->makeSite();
        list($token) = $this->visitorSays($site, 'first');

        $this->api('POST', $site, '/seen', $token, ['message_ids' => 'x'])->assertStatus(422);
        $this->api('POST', $site, '/seen', $token, ['message_ids' => ['a']])->assertStatus(422);
        $this->api('POST', $site, '/seen', $token, ['message_ids' => range(1, 101)])->assertStatus(422);
        $this->api('POST', $site, '/seen', null, ['message_ids' => []])->assertStatus(401);
    }

    public function test_poll_without_token_is_401()
    {
        $site = $this->makeSite();

        $this->poll($site, null)->assertStatus(401);
        $this->assertSame(['error' => 'unauthorized'], $this->poll($site, null)->json());
        $this->poll($site, 'bogus')->assertStatus(401);
    }

    public function test_poll_returns_at_most_100_rows()
    {
        $site = $this->makeSite();
        list($token, $first, $conv) = $this->visitorSays($site, 'hello');
        $ids = [];
        for ($i = 0; $i < 105; $i++) {
            list(, $row) = $this->agentThread($conv);
            $ids[] = (int) $row->id;
        }

        $page = $this->poll($site, $token, $first)->assertStatus(200)->json()['messages'];

        $this->assertSame(array_slice($ids, 0, 100), array_column($page, 'id'));

        $rest = $this->poll($site, $token, $ids[99])->assertStatus(200)->json()['messages'];

        $this->assertSame(array_slice($ids, 100), array_column($rest, 'id'));
    }
}
