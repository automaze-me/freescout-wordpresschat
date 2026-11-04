<?php

namespace Modules\WordPressChat\Tests\Feature;

use App\Conversation;
use App\Thread;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\ConversationState;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Level;
use Modules\WordPressChat\Services\AccessLevel;
use Modules\WordPressChat\Services\VisitorAuth;
use Modules\WordPressChat\Tests\TestCase;

/**
 * Spec §6.4: what each browser on a conversation may receive. The state is
 * built directly (rows and columns), since the flows that produce it
 * (customer emails, typed emails, logins, links) arrive in later tasks.
 */
class VisibilityRulesTest extends TestCase
{
    private $n = 0;

    private function poll(Site $site, string $token): array
    {
        $response = $this->api('GET', $site, '/messages', $token);
        $response->assertStatus(200);

        return $response->json();
    }

    private function texts(array $json): array
    {
        return array_map('trim', array_column($json['messages'], 'text'));
    }

    private function say(Site $site, string $token, string $text)
    {
        $response = $this->api('POST', $site, '/messages', $token, [
            'text'              => $text,
            'client_message_id' => 'vis-'.str_pad(++$this->n, 6, '0', STR_PAD_LEFT),
        ]);
        $response->assertStatus(201);

        return $response;
    }

    private function stateOf(Conversation $conversation): ConversationState
    {
        return ConversationState::where('conversation_id', $conversation->id)->firstOrFail();
    }

    /** Every row created from now on gets an id at or above the returned value. */
    private function nextMessageId(): int
    {
        return (int) Message::max('id') + 1;
    }

    private function cutOff(Conversation $conversation, int $reason): void
    {
        $state = $this->stateOf($conversation);
        $state->cutoff_message_id = $this->nextMessageId();
        $state->cutoff_reason = $reason;
        $state->save();
    }

    /** A published agent reply in the chat, with its OUT row. */
    private function agentReply(Conversation $conversation, string $text): Message
    {
        $agent = $this->adminUser();
        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type = Thread::TYPE_MESSAGE;
        $thread->status = Thread::STATUS_ACTIVE;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->body = '<p>'.$text.'</p>';
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->source_via = Thread::PERSON_USER;
        $thread->user_id = $agent->id;
        $thread->created_by_user_id = $agent->id;
        $thread->save();

        return Message::create([
            'conversation_id' => $conversation->id,
            'thread_id'       => $thread->id,
            'direction'       => Message::DIRECTION_OUT,
            'published_at'    => now(),
        ]);
    }

    /** An email from the customer in the conversation, with its via-email IN row. */
    private function customerEmail(Conversation $conversation, string $text): Message
    {
        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type = Thread::TYPE_CUSTOMER;
        $thread->status = Thread::STATUS_ACTIVE;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->body = '<p>'.$text.'</p>';
        $thread->source_type = Thread::SOURCE_TYPE_EMAIL;
        $thread->source_via = Thread::PERSON_CUSTOMER;
        $thread->customer_id = $conversation->customer_id;
        $thread->created_by_customer_id = $conversation->customer_id;
        $thread->save();

        return Message::create([
            'conversation_id' => $conversation->id,
            'thread_id'       => $thread->id,
            'session_id'      => null,
            'direction'       => Message::DIRECTION_IN,
            'via'             => Message::VIA_EMAIL,
            'published_at'    => now(),
        ]);
    }

    /** Another browser holding an Access row on $conversation. Returns [token, session]. */
    private function otherBrowser(Site $site, Conversation $conversation, array $session = [], array $access = []): array
    {
        $token = $this->newChatToken();
        $row = VisitorAuth::claim($site, $token);
        if ($session) {
            $row->fill($session)->save();
        }
        Access::create(array_merge([
            'session_id'      => $row->id,
            'conversation_id' => $conversation->id,
        ], $access));

        return [$token, $row];
    }

    public function test_unverified_never_sees_email_rows_and_verified_does()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Hello');
        $email = $this->customerEmail($chat['conversation'], 'Secret from the inbox');

        $json = $this->poll($site, $chat['token']);
        $this->assertSame(['Hello'], $this->texts($json));
        $this->assertNotContains((int) $email->id, array_column($json['messages'], 'id'));

        Access::where('session_id', $chat['session']->id)->update(['verified_at' => now()]);

        $json = $this->poll($site, $chat['token']);
        $this->assertSame(['Hello', 'Secret from the inbox'], $this->texts($json));
        $this->assertSame((int) $email->id, $json['messages'][1]['id']);
        $this->assertSame(['chat', 'email'], array_column($json['messages'], 'via'));
        $this->assertSame('verified', $json['identity']['level']);
    }

    public function test_cutoff_hides_new_content_but_keeps_own_messages()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Before');
        $conversation = $chat['conversation'];
        $this->cutOff($conversation, ConversationState::CUTOFF_EMAIL);

        $hidden = $this->agentReply($conversation, 'Reply after the cut-off');
        $this->say($site, $chat['token'], 'Own message after');
        list($tokenB, $sessionB) = $this->otherBrowser($site, $conversation);
        $this->say($site, $tokenB, 'Other browser after');

        $json = $this->poll($site, $chat['token']);
        $this->assertSame(['Before', 'Own message after'], $this->texts($json));
        $this->assertNotContains((int) $hidden->id, array_column($json['messages'], 'id'));
        $this->assertSame('continues_by_email', $json['conversation']['notice']);

        // The second browser is cut off as well: its own text, not the originator's.
        $this->assertSame(['Before', 'Other browser after'], $this->texts($this->poll($site, $tokenB)));

        $state = $this->stateOf($conversation);
        $state->cutoff_reason = ConversationState::CUTOFF_VERIFIED;
        $state->save();
        $this->assertSame('continues_elsewhere', $this->poll($site, $chat['token'])['conversation']['notice']);

        list($tokenC) = $this->otherBrowser($site, $conversation, [], ['verified_at' => now()]);
        $json = $this->poll($site, $tokenC);
        $this->assertSame(
            ['Before', 'Reply after the cut-off', 'Own message after', 'Other browser after'],
            $this->texts($json)
        );
        $this->assertNull($json['conversation']['notice']);
    }

    public function test_account_chat_requires_a_fresh_matching_login()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site, 'Account chat');
        $state = $this->stateOf($chat['conversation']);
        $state->wp_user_id = 7;
        $state->save();
        $session = $chat['session'];

        $session->wp_user_id = 7;
        $session->account_until = now()->addHour();
        $session->save();
        $json = $this->poll($site, $chat['token']);
        $this->assertSame(['Account chat'], $this->texts($json));
        $this->assertSame(['level' => 'account', 'account' => true], $json['identity']);
        $this->assertNotNull($json['conversation']);

        $session->account_until = now()->subMinute();
        $session->save();
        $json = $this->poll($site, $chat['token']);
        $this->assertSame([], $json['messages']);
        $this->assertNull($json['conversation']);
        $this->assertSame(['level' => 'anonymous', 'account' => false], $json['identity']);

        $session->wp_user_id = 8;
        $session->account_until = now()->addHour();
        $session->save();
        $json = $this->poll($site, $chat['token']);
        $this->assertSame([], $json['messages']);
        $this->assertNull($json['conversation']);
    }

    public function test_seen_cannot_mark_replies_hidden_by_the_cutoff()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $before = $this->agentReply($chat['conversation'], 'Before the cut-off');
        $this->cutOff($chat['conversation'], ConversationState::CUTOFF_EMAIL);
        $after = $this->agentReply($chat['conversation'], 'After the cut-off');

        $this->api('POST', $site, '/seen', $chat['token'], ['message_ids' => [(int) $before->id, (int) $after->id]])
            ->assertStatus(204);

        $this->assertNotNull(Message::find($before->id)->seen_at);
        $this->assertNull(Message::find($after->id)->seen_at);
    }

    public function test_badge_is_the_lowest_level_still_with_access()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $conversation = $chat['conversation'];
        $chat['session']->identity = Session::IDENTITY_EMAIL;
        $chat['session']->save();
        list(, $verified) = $this->otherBrowser($site, $conversation, [], ['verified_at' => now()]);

        $this->assertSame(Level::EMAIL, AccessLevel::badge($conversation));

        $this->cutOff($conversation, ConversationState::CUTOFF_VERIFIED);
        $this->assertSame(Level::VERIFIED, AccessLevel::badge($conversation));

        Access::where('session_id', $verified->id)->delete();
        $this->assertSame(Level::EMAIL, AccessLevel::badge($conversation));
    }

    public function test_order_ranks_below_email()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $chat['session']->identity = Session::IDENTITY_ORDER;
        $chat['session']->save();
        $this->otherBrowser($site, $chat['conversation'], ['identity' => Session::IDENTITY_EMAIL]);

        $this->assertSame(Level::ORDER, AccessLevel::badge($chat['conversation']));
    }

    public function test_index_reports_identity_key_and_via()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        $this->agentReply($chat['conversation'], 'Reply');

        $first = $this->poll($site, $chat['token']);
        $second = $this->poll($site, $chat['token']);

        $this->assertSame(['level' => 'anonymous', 'account' => false], $first['identity']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $first['conversation']['key']);
        $this->assertSame($first['conversation']['key'], $second['conversation']['key']);
        $this->assertNull($first['conversation']['notice']);
        $this->assertSame(['chat', 'chat'], array_column($first['messages'], 'via'));

        // The send response carries the same identity and conversation (Ruling C6).
        $sent = $chat['response']->json();
        $this->assertSame(['level' => 'anonymous', 'account' => false], $sent['identity']);
        $this->assertSame($first['conversation'], $sent['conversation']);
        $this->assertSame('chat', $sent['message']['via']);
    }

    public function test_fresh_account_session_starts_an_account_bound_conversation()
    {
        $site = $this->makeSite();
        $token = $this->newChatToken();
        $session = VisitorAuth::claim($site, $token);
        $session->wp_user_id = 7;
        $session->account_until = now()->addHour();
        $session->save();

        $response = $this->say($site, $token, 'Hi');

        $conversationId = Message::find($response->json()['message']['id'])->conversation_id;
        $state = ConversationState::where('conversation_id', $conversationId)->first();
        $this->assertNotNull($state);
        $this->assertSame(7, (int) $state->wp_user_id);
        $this->assertSame((int) $site->id, (int) $state->site_id);
        $this->assertSame(['level' => 'account', 'account' => true], $response->json()['identity']);

        // A lapsed login starts a conversation bound to the browser only.
        $tokenB = $this->newChatToken();
        $lapsed = VisitorAuth::claim($site, $tokenB);
        $lapsed->wp_user_id = 7;
        $lapsed->account_until = now()->subMinute();
        $lapsed->save();

        $response = $this->say($site, $tokenB, 'Hi');

        $conversationId = Message::find($response->json()['message']['id'])->conversation_id;
        $this->assertNull(ConversationState::where('conversation_id', $conversationId)->first()->wp_user_id);
    }

    public function test_conversation_without_state_gets_one_on_first_read()
    {
        $site = $this->makeSite();
        $chat = $this->startChat($site);
        // A conversation from before Plan 2 has no state row.
        ConversationState::where('conversation_id', $chat['conversation']->id)->delete();

        $first = $this->poll($site, $chat['token']);
        $second = $this->poll($site, $chat['token']);

        $state = $this->stateOf($chat['conversation']);
        $this->assertSame((int) $site->id, (int) $state->site_id);
        $this->assertNull($state->wp_user_id);
        $this->assertSame($state->public_id, $first['conversation']['key']);
        $this->assertSame($first['conversation']['key'], $second['conversation']['key']);
        $this->assertSame(['Hello'], $this->texts($first));
    }
}
