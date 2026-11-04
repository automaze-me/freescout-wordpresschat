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

    private function site()
    {
        $site = new Site([
            'name'       => 'Shop',
            'url'        => self::ORIGIN,
            'origin'     => self::ORIGIN,
            'mailbox_id' => $this->testMailbox()->id,
            'public_id'  => str_repeat('a', 32),
        ]);
        $site->setSecret('s');
        $site->save();

        return $site;
    }

    private function call_api($method, Site $site, $path, $token = null, $data = null)
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => self::ORIGIN];
        if ($token !== null) {
            $server['HTTP_X_CHAT_TOKEN'] = $token;
        }

        return $this->call($method, '/wordpress-chat/api/v1/sites/'.$site->public_id.$path, [], [], [], $server, $data === null ? null : json_encode($data));
    }

    /** Sends a visitor message via the real endpoint; returns [token, message id, conversation id]. */
    private function visitorSays(Site $site, $text, $token = null)
    {
        $r = $this->call_api('POST', $site, '/messages', $token, [
            'text' => $text, 'client_message_id' => 'cmid-'.str_pad(++$this->n, 6, '0', STR_PAD_LEFT),
        ]);
        $json = $r->json();
        $id = $json['message']['id'];

        return [$token ?: $json['token'], $id, Message::find($id)->conversation_id];
    }

    private function agentThread($conversationId, $type = null, $withRow = true, $published = true)
    {
        $agent = $this->adminUser();
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
        return $this->call_api('GET', $site, '/messages'.($after === null ? '' : '?after='.$after), $token);
    }

    public function test_poll_returns_visitor_messages_and_published_replies_in_order()
    {
        $site = $this->site();
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
        $this->assertSame(['status' => 'open'], $json['conversation']);
    }

    public function test_unpublished_reply_and_notes_are_not_returned()
    {
        $site = $this->site();
        list($token, $m1, $conv) = $this->visitorSays($site, 'first');
        $this->agentThread($conv, null, false);
        list(, $noteRow) = $this->agentThread($conv, Thread::TYPE_NOTE);
        list(, $pending) = $this->agentThread($conv, null, true, false);

        $ids = array_column($this->poll($site, $token)->json()['messages'], 'id');

        $this->assertSame([$m1], $ids);
        $this->assertNotContains($noteRow->id, $ids);
        $this->call_api('POST', $site, '/seen', $token, ['message_ids' => [$noteRow->id]])->assertStatus(204);
        $this->assertNull(Message::find($noteRow->id)->seen_at);
        $this->assertNotContains($pending->id, $ids);
    }

    public function test_after_cursor_limits_results()
    {
        $site = $this->site();
        list($token, $m1, $conv) = $this->visitorSays($site, 'first');
        list(, $reply) = $this->agentThread($conv);

        $ids = array_column($this->poll($site, $token, $m1)->json()['messages'], 'id');
        $this->assertSame([$reply->id], $ids);

        $this->assertSame([], $this->poll($site, $token, $reply->id)->json()['messages']);
        $this->poll($site, $token, 'abc')->assertStatus(422);
        $this->assertSame(['error' => 'validation', 'field' => 'after'], $this->poll($site, $token, 'abc')->json());
        $this->poll($site, $token, -1)->assertStatus(422);
    }

    public function test_poll_without_conversation_returns_null_conversation()
    {
        $site = $this->site();
        list($token) = $this->visitorSays($site, 'x');
        \Modules\WordPressChat\Entities\Access::query()->delete();

        $json = $this->poll($site, $token)->json();
        $this->assertSame([], $json['messages']);
        $this->assertNull($json['conversation']);
    }

    public function test_other_sessions_conversation_is_invisible()
    {
        $site = $this->site();
        list($tokenA, $a1, $conv) = $this->visitorSays($site, 'secret A');
        list(, $reply) = $this->agentThread($conv);
        list($tokenB) = $this->visitorSays($site, 'hello B');

        $textsB = array_column($this->poll($site, $tokenB)->json()['messages'], 'text');
        $this->assertSame(['hello B'], $textsB);

        $this->call_api('POST', $site, '/seen', $tokenB, ['message_ids' => [$reply->id, $a1]])->assertStatus(204);
        $this->assertNull(Message::find($reply->id)->seen_at);
    }

    public function test_seen_marks_only_visible_agent_messages_once()
    {
        $site = $this->site();
        list($token, $m1, $conv) = $this->visitorSays($site, 'first');
        list(, $reply) = $this->agentThread($conv);
        list(, $hidden) = $this->agentThread($conv, null, true, false);

        $this->call_api('POST', $site, '/seen', $token, ['message_ids' => [$m1, $reply->id, $hidden->id, 999999]])->assertStatus(204);

        $this->assertNull(Message::find($m1)->seen_at);
        $this->assertNull(Message::find($hidden->id)->seen_at);
        $first = Message::find($reply->id)->seen_at;
        $this->assertNotNull($first);

        Message::where('id', $reply->id)->update(['seen_at' => '2000-01-01 00:00:00']);
        $this->call_api('POST', $site, '/seen', $token, ['message_ids' => [$reply->id]])->assertStatus(204);
        $this->assertSame('2000-01-01 00:00:00', Message::find($reply->id)->seen_at->format('Y-m-d H:i:s'));
    }

    public function test_seen_validates_input()
    {
        $site = $this->site();
        list($token) = $this->visitorSays($site, 'first');

        $this->call_api('POST', $site, '/seen', $token, ['message_ids' => 'x'])->assertStatus(422);
        $this->call_api('POST', $site, '/seen', $token, ['message_ids' => ['a']])->assertStatus(422);
        $this->call_api('POST', $site, '/seen', $token, ['message_ids' => range(1, 101)])->assertStatus(422);
        $this->call_api('POST', $site, '/seen', null, ['message_ids' => []])->assertStatus(401);
    }

    public function test_poll_without_token_is_401()
    {
        $site = $this->site();

        $this->poll($site, null)->assertStatus(401);
        $this->assertSame(['error' => 'unauthorized'], $this->poll($site, null)->json());
        $this->poll($site, 'bogus')->assertStatus(401);
    }
}
