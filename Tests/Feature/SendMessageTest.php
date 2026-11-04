<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\Thread;
use Illuminate\Support\Facades\DB;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Exceptions\MessageConflictException;
use Modules\WordPressChat\Services\InboundMessage;
use Modules\WordPressChat\Services\VisitorAuth;
use Modules\WordPressChat\Tests\TestCase;
use Modules\WordPressChat\WordPressChat;

class SendMessageTest extends TestCase
{
    const ORIGIN = 'https://shop.test';

    /** A visitor session, created the way a first message creates it. */
    private function newSession(Site $site)
    {
        return VisitorAuth::claim($site, $this->newChatToken());
    }

    /**
     * POST a JSON body the way the widget does. $accept = false leaves out
     * the Accept: application/json header, which the widget may not send.
     *
     * Without a $token, the body gets a fresh `new_token` as the widget
     * sends it, unless $data sets one; `'new_token' => null` leaves the key
     * out entirely.
     */
    private function send(Site $site, array $data, $token = null, $accept = true)
    {
        if ($token === null && !array_key_exists('new_token', $data)) {
            $data['new_token'] = $this->newChatToken();
        }
        if (array_key_exists('new_token', $data) && $data['new_token'] === null) {
            unset($data['new_token']);
        }

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN'  => self::ORIGIN,
        ];
        if ($accept) {
            $server['HTTP_ACCEPT'] = 'application/json';
        }
        if ($token !== null) {
            $server['HTTP_X_CHAT_TOKEN'] = $token;
        }

        return $this->call('POST', '/wordpress-chat/api/v1/sites/'.$site->public_id.'/messages', [], [], [], $server, json_encode($data));
    }

    private function conversationOf(Message $message)
    {
        return Conversation::find($message->conversation_id);
    }

    private function messageFrom($response)
    {
        return Message::find($response->json()['message']['id']);
    }

    public function test_first_message_creates_session_customer_and_chat_conversation()
    {
        $site = $this->makeSite();

        $response = $this->send($site, ['text' => "Hi & welcome\nsecond line", 'client_message_id' => 'abcdefgh-0001']);

        $response->assertStatus(201);
        $json = $response->json();
        $this->assertIsString($json['token']);

        $session = VisitorAuth::resolve($site, $json['token']);
        $this->assertNotNull($session);
        $this->assertNotNull($session->customer_id);

        $message = $this->messageFrom($response);
        $this->assertSame(Message::DIRECTION_IN, (int) $message->direction);
        $this->assertSame($session->id, (int) $message->session_id);
        $this->assertSame('abcdefgh-0001', $message->client_message_id);

        $conversation = $this->conversationOf($message);
        $this->assertSame(Conversation::TYPE_CHAT, (int) $conversation->type);
        $this->assertSame(3, (int) $conversation->type);
        $this->assertSame(WordPressChat::CHANNEL, (int) $conversation->channel);
        $this->assertSame((int) $site->mailbox_id, (int) $conversation->mailbox_id);
        $this->assertSame((int) $session->customer_id, (int) $conversation->customer_id);
        $this->assertSame(Conversation::SOURCE_TYPE_WEB, (int) $conversation->source_type);
        $this->assertSame(Conversation::PERSON_CUSTOMER, (int) $conversation->source_via);
        $this->assertSame(Conversation::STATE_PUBLISHED, (int) $conversation->state);
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->status);
        $this->assertSame('', (string) $conversation->customer_email);
        $this->assertSame('Hi & welcome second line', $conversation->subject);
        $this->assertSame(Conversation::PERSON_CUSTOMER, (int) $conversation->last_reply_from);
        $this->assertNotNull($conversation->last_reply_at);
        $this->assertStringContainsString('Hi & welcome', $conversation->preview);
        $this->assertNotNull($conversation->folder_id);

        $this->assertTrue(DB::table('customer_channel')
            ->where('customer_id', $session->customer_id)
            ->where('channel', WordPressChat::CHANNEL)
            ->where('channel_id', $session->public_id)
            ->exists());

        $this->assertSame(1, Access::where('session_id', $session->id)->where('conversation_id', $conversation->id)->count());

        $thread = Thread::find($message->thread_id);
        $this->assertSame((int) $conversation->id, (int) $thread->conversation_id);
        $this->assertSame(Thread::TYPE_CUSTOMER, (int) $thread->type);
        $this->assertSame(Thread::STATE_PUBLISHED, (int) $thread->state);
        $this->assertSame(Thread::SOURCE_TYPE_WEB, (int) $thread->source_type);
        $this->assertSame((int) $session->customer_id, (int) $thread->customer_id);
        $this->assertSame((int) $session->customer_id, (int) $thread->created_by_customer_id);
        $this->assertTrue((bool) $thread->first);
        $this->assertSame("Hi &amp; welcome<br />\nsecond line", $thread->body);

        $this->assertSame([
            'id'         => (int) $message->id,
            'role'       => 'visitor',
            'text'       => "Hi & welcome\nsecond line",
            'author'     => null,
            'created_at' => $thread->created_at->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
            'via'        => 'chat',
        ], $json['message']);
    }

    public function test_hostile_text_is_stored_escaped()
    {
        $site = $this->makeSite();
        $text = "<script>alert(1)</script> & 😀 שלום";

        $response = $this->send($site, ['text' => "  ".$text."  ", 'client_message_id' => 'hostile-0001']);

        $response->assertStatus(201);
        $thread = Thread::find($this->messageFrom($response)->thread_id);
        $this->assertStringContainsString('&lt;script&gt;', $thread->body);
        $this->assertStringNotContainsString('<script', $thread->body);
        $this->assertSame($text, $response->json()['message']['text']);
    }

    public function test_apostrophes_and_quotes_survive_the_round_trip()
    {
        $site = $this->makeSite();
        // html2text alone would turn "I'm" into "Im". Entities the visitor
        // typed literally must come back literally.
        $text = "I'm \"fine\", it's <ok> &#039; &amp; &apos;";

        $response = $this->send($site, ['text' => $text, 'client_message_id' => 'quotes-0001']);

        $response->assertStatus(201);
        $this->assertSame($text, $response->json()['message']['text']);
    }

    public function test_apostrophes_are_stored_literally_and_html_stays_escaped()
    {
        $site = $this->makeSite();
        $data = ['text' => '<b>I\'m</b> "x"', 'client_message_id' => 'apos-0001', 'new_token' => $this->newChatToken()];

        $response = $this->send($site, $data);

        $response->assertStatus(201);
        $thread = Thread::find($this->messageFrom($response)->thread_id);
        $this->assertSame('&lt;b&gt;I\'m&lt;/b&gt; &quot;x&quot;', $thread->body);

        $this->send($site, $data, $response->json()['token'])->assertStatus(200);
    }

    public function test_text_over_5000_chars_is_422()
    {
        $site = $this->makeSite();
        $sessions = Session::count();

        $this->send($site, ['text' => str_repeat('ä', 5001), 'client_message_id' => 'long-0001'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'validation', 'field' => 'text']);

        $this->assertSame($sessions, Session::count(), 'A rejected message must not issue a session.');
    }

    public function test_text_of_5000_multibyte_chars_is_accepted()
    {
        $site = $this->makeSite();

        $this->send($site, ['text' => str_repeat('ä', 5000), 'client_message_id' => 'long-0002'])
            ->assertStatus(201);
    }

    public function test_blank_text_is_422()
    {
        $site = $this->makeSite();

        $this->send($site, ['text' => " \n\t ", 'client_message_id' => 'blank-0001'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'validation', 'field' => 'text']);

        $this->send($site, ['client_message_id' => 'blank-0002'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'validation', 'field' => 'text']);

        $this->send($site, ['text' => ['array'], 'client_message_id' => 'blank-0003'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'validation', 'field' => 'text']);
    }

    public function test_bad_client_message_id_is_422()
    {
        $site = $this->makeSite();

        foreach ([null, 'short', 'has spaces in it', 'under_score_id', str_repeat('a', 65), 12345678] as $id) {
            $data = ['text' => 'Hello'];
            if ($id !== null) {
                $data['client_message_id'] = $id;
            }

            $this->send($site, $data)
                ->assertStatus(422)
                ->assertExactJson(['error' => 'validation', 'field' => 'client_message_id']);
        }

        $this->send($site, ['text' => 'Hello', 'client_message_id' => str_repeat('A', 64)])->assertStatus(201);
    }

    public function test_validation_error_is_json_without_accept_header()
    {
        $site = $this->makeSite();

        $response = $this->send($site, ['text' => '', 'client_message_id' => 'noaccept-0001'], null, false);

        $response->assertStatus(422)->assertExactJson(['error' => 'validation', 'field' => 'text']);
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
        $response->assertHeader('Access-Control-Allow-Origin', self::ORIGIN);
    }

    public function test_second_message_with_token_continues_conversation()
    {
        $site = $this->makeSite();

        $first = $this->send($site, ['text' => 'First', 'client_message_id' => 'continue-0001']);
        $token = $first->json()['token'];

        $second = $this->send($site, ['text' => 'Second', 'client_message_id' => 'continue-0002'], $token);

        $second->assertStatus(201);
        $this->assertArrayNotHasKey('token', $second->json());

        $a = $this->messageFrom($first);
        $b = $this->messageFrom($second);
        $this->assertSame((int) $a->conversation_id, (int) $b->conversation_id);
        $this->assertSame(2, Thread::where('conversation_id', $a->conversation_id)->count());
        $this->assertFalse((bool) Thread::find($b->thread_id)->first);
        $this->assertSame('First', $this->conversationOf($b)->subject);
        $this->assertSame('Second', $this->conversationOf($b)->preview);
    }

    public function test_retry_with_same_client_message_id_returns_original()
    {
        $site = $this->makeSite();

        $first = $this->send($site, ['text' => 'Hello there', 'client_message_id' => 'retry-0001']);
        $token = $first->json()['token'];

        $retry = $this->send($site, ['text' => '  Hello there ', 'client_message_id' => 'retry-0001'], $token);

        $retry->assertStatus(200);
        $this->assertArrayNotHasKey('token', $retry->json());
        $this->assertSame($first->json()['message'], $retry->json()['message']);

        $message = $this->messageFrom($first);
        $this->assertSame(1, Thread::where('conversation_id', $message->conversation_id)->count());
        $this->assertSame(1, Message::where('session_id', $message->session_id)->count());
    }

    public function test_same_client_message_id_different_text_is_409()
    {
        $site = $this->makeSite();

        $first = $this->send($site, ['text' => 'Hello there', 'client_message_id' => 'conflict-0001']);
        $token = $first->json()['token'];

        $this->send($site, ['text' => 'Something else', 'client_message_id' => 'conflict-0001'], $token)
            ->assertStatus(409)
            ->assertExactJson(['error' => 'conflict']);

        $message = $this->messageFrom($first);
        $this->assertSame(1, Thread::where('conversation_id', $message->conversation_id)->count());
    }

    public function test_closed_conversation_starts_a_new_one()
    {
        $site = $this->makeSite();

        $first = $this->send($site, ['text' => 'Old question', 'client_message_id' => 'closed-0001']);
        $token = $first->json()['token'];
        $old = $this->conversationOf($this->messageFrom($first));
        $old->status = Conversation::STATUS_CLOSED;
        $old->save();

        $second = $this->send($site, ['text' => 'New question', 'client_message_id' => 'closed-0002'], $token);

        $second->assertStatus(201);
        $message = $this->messageFrom($second);
        $new = $this->conversationOf($message);
        $this->assertNotEquals($old->id, $new->id);
        $this->assertTrue((bool) Thread::find($message->thread_id)->first);
        $this->assertSame((int) $old->customer_id, (int) $new->customer_id);
        $this->assertSame('New question', $new->subject);
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $old->fresh()->status);
        $this->assertSame(2, Access::where('session_id', $message->session_id)->count());

        // The next message continues the new conversation, not the closed one.
        $third = $this->send($site, ['text' => 'Follow-up', 'client_message_id' => 'closed-0003'], $token);
        $this->assertSame((int) $new->id, (int) $this->messageFrom($third)->conversation_id);
    }

    public function test_deleted_conversation_starts_a_new_one()
    {
        $site = $this->makeSite();

        $first = $this->send($site, ['text' => 'Old question', 'client_message_id' => 'deleted-0001']);
        $token = $first->json()['token'];
        $old = $this->conversationOf($this->messageFrom($first));
        $old->state = Conversation::STATE_DELETED;
        $old->save();

        $second = $this->send($site, ['text' => 'New question', 'client_message_id' => 'deleted-0002'], $token);

        $second->assertStatus(201);
        $this->assertNotEquals((int) $old->id, (int) $this->messageFrom($second)->conversation_id);
    }

    public function test_pending_conversation_is_reactivated_and_continued()
    {
        $site = $this->makeSite();

        $first = $this->send($site, ['text' => 'Question', 'client_message_id' => 'pending-0001']);
        $token = $first->json()['token'];
        $conversation = $this->conversationOf($this->messageFrom($first));
        $conversation->status = Conversation::STATUS_PENDING;
        $conversation->updateFolder();
        $conversation->save();

        $second = $this->send($site, ['text' => 'Any news?', 'client_message_id' => 'pending-0002'], $token);

        $second->assertStatus(201);
        $message = $this->messageFrom($second);
        $this->assertSame((int) $conversation->id, (int) $message->conversation_id);
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status);
        $this->assertSame(Thread::STATUS_ACTIVE, (int) Thread::find($message->thread_id)->status);
    }

    public function test_customer_events_fire_once()
    {
        $site = $this->makeSite();
        $created = 0;
        $replied = 0;
        \Eventy::addAction('conversation.created_by_customer', function () use (&$created) {
            $created++;
        }, 20, 3);
        \Eventy::addAction('conversation.customer_replied', function () use (&$replied) {
            $replied++;
        }, 20, 3);

        $first = $this->send($site, ['text' => 'One', 'client_message_id' => 'events-0001']);
        $token = $first->json()['token'];
        $this->send($site, ['text' => 'Two', 'client_message_id' => 'events-0002'], $token)->assertStatus(201);

        $this->assertSame(1, $created);
        $this->assertSame(1, $replied);

        // An idempotent replay creates nothing, so it fires nothing.
        $this->send($site, ['text' => 'Two', 'client_message_id' => 'events-0002'], $token)->assertStatus(200);

        $this->assertSame(1, $created);
        $this->assertSame(1, $replied);
    }

    public function test_invalid_token_is_401()
    {
        $site = $this->makeSite();
        $messages = Message::count();

        $this->send($site, ['text' => 'Hello', 'client_message_id' => 'token-0001'], 'not-a-real-token')
            ->assertStatus(401)
            ->assertExactJson(['error' => 'unauthorized']);

        $this->assertSame($messages, Message::count());
    }

    /** Row counts of everything a send may write. */
    private function rowCounts()
    {
        return [
            'sessions'      => Session::count(),
            'customers'     => \App\Customer::count(),
            'conversations' => Conversation::count(),
            'threads'       => Thread::count(),
            'messages'      => Message::count(),
            'access'        => Access::count(),
        ];
    }

    public function test_lost_first_response_retry_lands_in_the_same_session()
    {
        $site = $this->makeSite();
        $newToken = $this->newChatToken();
        $data = ['text' => 'Hello', 'client_message_id' => 'lost-0001', 'new_token' => $newToken];

        $first = $this->send($site, $data);
        $retry = $this->send($site, $data);

        $first->assertStatus(201);
        $retry->assertStatus(200);
        $this->assertSame($newToken, $first->json()['token']);
        $this->assertSame($newToken, $retry->json()['token']);
        $this->assertSame($first->json()['message']['id'], $retry->json()['message']['id']);

        $session = VisitorAuth::resolve($site, $newToken);
        $this->assertSame(1, Session::where('token_hash', hash('sha256', $newToken))->count());
        $this->assertSame(1, Access::where('session_id', $session->id)->count());
        $message = $this->messageFrom($first);
        $this->assertSame((int) $session->id, (int) $message->session_id);
        $this->assertSame(1, Thread::where('conversation_id', $message->conversation_id)->count());
        $this->assertSame(1, Message::where('session_id', $session->id)->count());
    }

    public function test_messages_queued_behind_a_lost_first_one_share_its_session()
    {
        $site = $this->makeSite();
        $newToken = $this->newChatToken();

        $a = $this->send($site, ['text' => 'One', 'client_message_id' => 'queued-0001', 'new_token' => $newToken]);
        $b = $this->send($site, ['text' => 'Two', 'client_message_id' => 'queued-0002', 'new_token' => $newToken]);

        $a->assertStatus(201);
        $b->assertStatus(201);
        $this->assertSame($newToken, $b->json()['token']);

        $session = VisitorAuth::resolve($site, $newToken);
        $this->assertSame(1, Session::where('token_hash', hash('sha256', $newToken))->count());
        $this->assertSame(1, Access::where('session_id', $session->id)->count());
        $conversationId = $this->messageFrom($a)->conversation_id;
        $this->assertSame((int) $conversationId, (int) $this->messageFrom($b)->conversation_id);
        $this->assertSame(2, Thread::where('conversation_id', $conversationId)->where('type', Thread::TYPE_CUSTOMER)->count());
    }

    public function test_malformed_new_token_is_422_and_writes_nothing()
    {
        $site = $this->makeSite();
        $before = $this->rowCounts();

        $bad = [
            '42 chars'   => str_repeat('a', 42),
            '44 chars'   => str_repeat('a', 44),
            'a plus'     => str_repeat('a', 42).'+',
            'an array'   => [str_repeat('a', 43)],
            'missing'    => null,
        ];
        foreach ($bad as $case => $newToken) {
            $this->send($site, ['text' => 'Hello', 'client_message_id' => 'badtoken-0001', 'new_token' => $newToken])
                ->assertStatus(422)
                ->assertExactJson(['error' => 'validation', 'field' => 'new_token']);

            $this->assertSame($before, $this->rowCounts(), "new_token $case wrote rows.");
        }
    }

    public function test_new_token_is_ignored_when_a_session_token_is_sent()
    {
        $site = $this->makeSite();
        $first = $this->send($site, ['text' => 'One', 'client_message_id' => 'ignored-0001']);
        $token = $first->json()['token'];
        $sessions = Session::count();

        $second = $this->send($site, ['text' => 'Two', 'client_message_id' => 'ignored-0002', 'new_token' => 'not valid'], $token);

        $second->assertStatus(201);
        $this->assertArrayNotHasKey('token', $second->json());
        $this->assertSame($sessions, Session::count());
    }

    public function test_new_token_of_another_site_is_409_and_writes_nothing()
    {
        $a = $this->makeSite(['public_id' => str_repeat('a', 32)]);
        $b = $this->makeSite(['public_id' => str_repeat('b', 32)]);
        $newToken = $this->newChatToken();

        $this->send($a, ['text' => 'Hello', 'client_message_id' => 'other-0001', 'new_token' => $newToken])->assertStatus(201);
        $before = $this->rowCounts();

        $this->send($b, ['text' => 'Hello', 'client_message_id' => 'other-0001', 'new_token' => $newToken])
            ->assertStatus(409)
            ->assertExactJson(['error' => 'conflict']);

        $this->assertSame($before, $this->rowCounts());
        $this->assertNull(VisitorAuth::resolve($b, $newToken));
    }

    public function test_control_characters_are_stripped_from_the_text()
    {
        $site = $this->makeSite();

        $response = $this->send($site, ['text' => "Hel\u{0000}lo\u{0007}\u{007F}\tthere\r\nfriend", 'client_message_id' => 'control-0001']);

        $response->assertStatus(201);
        $thread = Thread::find($this->messageFrom($response)->thread_id);
        $this->assertStringNotContainsString("\0", $thread->body);
        $this->assertSame("Hello\tthere<br />\r\nfriend", $thread->body);
    }

    public function test_text_of_only_control_characters_is_422_and_writes_nothing()
    {
        $site = $this->makeSite();
        $before = $this->rowCounts();

        $this->send($site, ['text' => "\u{0000}\u{0001}\u{001F}\u{007F}", 'client_message_id' => 'control-0002'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'validation', 'field' => 'text']);

        $this->assertSame($before, $this->rowCounts());
    }

    /**
     * Simulates two concurrent requests with the same client_message_id: the
     * competing request commits between our duplicate pre-check and our
     * transaction. The pre-check's own query (the first SELECT on the
     * messages table) is the one point that runs in that window, so the
     * competitor is run from a query listener on it. Our insert then hits
     * the unique index and the original must be returned instead of a 500.
     */
    private function raceAgainst(Site $site, Session $session, $competitorText)
    {
        $competitor = null;
        \DB::listen(function ($query) use ($site, $session, $competitorText, &$competitor) {
            if ($competitor !== null
                || stripos($query->sql, 'select') !== 0
                || strpos($query->sql, 'wordpresschat_messages') === false
            ) {
                return;
            }
            $competitor = false;
            $competitor = InboundMessage::receive($site, $session, $competitorText, 'race-0001');
        });

        return function () use (&$competitor) {
            return $competitor;
        };
    }

    public function test_concurrent_duplicate_returns_the_winner()
    {
        $site = $this->makeSite();
        $session = $this->newSession($site);
        $winner = $this->raceAgainst($site, $session, 'Hello');

        $message = InboundMessage::receive($site, $session, 'Hello', 'race-0001');

        $this->assertInstanceOf(Message::class, $winner());
        $this->assertSame((int) $winner()->id, (int) $message->id);
        $this->assertFalse($message->wasRecentlyCreated);
        $this->assertSame(1, Message::where('session_id', $session->id)->count());
        $this->assertSame(1, Thread::where('conversation_id', $message->conversation_id)->count());
    }

    public function test_race_fires_events_once()
    {
        $site = $this->makeSite();
        $session = $this->newSession($site);
        $created = 0;
        $replied = 0;
        \Eventy::addAction('conversation.created_by_customer', function () use (&$created) {
            $created++;
        }, 20, 3);
        \Eventy::addAction('conversation.customer_replied', function () use (&$replied) {
            $replied++;
        }, 20, 3);
        $this->raceAgainst($site, $session, 'Hello');

        InboundMessage::receive($site, $session, 'Hello', 'race-0001');

        $this->assertSame(1, $created);
        $this->assertSame(0, $replied);
    }

    public function test_a_throwing_event_listener_does_not_skip_the_eventy_action()
    {
        $site = $this->makeSite();
        \Event::listen(\App\Events\CustomerCreatedConversation::class, function () {
            throw new \RuntimeException('boom');
        });
        $fired = 0;
        \Eventy::addAction('conversation.created_by_customer', function () use (&$fired) {
            $fired++;
        }, 20, 3);

        $this->send($site, ['text' => 'Hello', 'client_message_id' => 'boom-0001'])->assertStatus(201);

        $this->assertSame(1, $fired);
    }

    public function test_session_whose_customer_was_deleted_gets_a_fresh_customer()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $oldCustomerId = (int) $chat['conversation']->customer_id;
        $chat['conversation']->status = Conversation::STATUS_CLOSED;
        $chat['conversation']->save();
        DB::table('customers')->where('id', $oldCustomerId)->delete();

        $response = $this->api('POST', $site, '/messages', $chat['token'], [
            'text' => 'Again', 'client_message_id' => 'again-0001',
        ]);

        $response->assertStatus(201);
        $conversation = $this->conversationOf($this->messageFrom($response));
        $this->assertNotSame($oldCustomerId, (int) $conversation->customer_id);
        $this->assertSame((int) $conversation->customer_id, (int) Session::find($chat['session']->id)->customer_id);
    }

    public function test_invalid_utf8_text_is_422()
    {
        $site = $this->makeSite();
        $sessions = Session::count();
        $threads = Thread::count();

        $this->call('POST', '/wordpress-chat/api/v1/sites/'.$site->public_id.'/messages', [
            'text'              => "\xC3\x28",
            'client_message_id' => 'utf8-0001',
            'new_token'         => $this->newChatToken(),
        ], [], [], ['HTTP_ORIGIN' => self::ORIGIN, 'HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'validation', 'field' => 'text']);

        $this->assertSame($sessions, Session::count());
        $this->assertSame($threads, Thread::count());
    }

    public function test_concurrent_duplicate_with_different_text_conflicts()
    {
        $site = $this->makeSite();
        $session = $this->newSession($site);
        $this->raceAgainst($site, $session, 'Hello');

        $this->expectException(MessageConflictException::class);

        InboundMessage::receive($site, $session, 'Something else', 'race-0001');
    }
}
