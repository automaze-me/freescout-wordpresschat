<?php

namespace Modules\WordPressChat\Http\Controllers\Api;

use App\Conversation;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\WordPressChat\Entities\Access;
use Modules\WordPressChat\Entities\Message;
use Modules\WordPressChat\Exceptions\MessageConflictException;
use Modules\WordPressChat\Services\InboundMessage;
use Modules\WordPressChat\Services\MessagePresenter;
use Modules\WordPressChat\Services\Visibility;
use Modules\WordPressChat\Services\VisitorAuth;

class ChatController extends Controller
{
    const MAX_TEXT_LENGTH = 5000;
    const MAX_POLL = 100;

    /**
     * POST …/messages {text, client_message_id}. Without an X-Chat-Token
     * header a new visitor session is issued and its token returned once.
     *
     * Validation is done by hand: this route group is not in the `web`
     * group and the widget may not send Accept: application/json, so a
     * ValidationException would render as a redirect. Every answer is JSON.
     */
    public function send(Request $request)
    {
        $site  = $request->attributes->get('wordpresschat.site');
        $token = $request->header('X-Chat-Token');

        $session = null;
        if ($token !== null && $token !== '') {
            $session = VisitorAuth::resolve($site, $token);

            if (!$session) {
                return response()->json(['error' => 'unauthorized'], 401);
            }
        }

        $text = $request->input('text');
        $text = is_string($text) ? trim($text) : '';
        $length = mb_strlen($text, 'UTF-8');

        if ($length < 1 || $length > self::MAX_TEXT_LENGTH) {
            return $this->invalid('text');
        }

        $clientMessageId = $request->input('client_message_id');

        if (!is_string($clientMessageId) || !preg_match('/\A[A-Za-z0-9-]{8,64}\z/', $clientMessageId)) {
            return $this->invalid('client_message_id');
        }

        $issuedToken = null;
        if (!$session) {
            $issued      = VisitorAuth::issue($site);
            $session     = $issued['session'];
            $issuedToken = $issued['token'];
        }

        try {
            $message = InboundMessage::receive($site, $session, $text, $clientMessageId);
        } catch (MessageConflictException $e) {
            return response()->json(['error' => 'conflict'], 409);
        }

        $payload = [];
        if ($issuedToken !== null) {
            $payload['token'] = $issuedToken;
        }
        $payload['message'] = MessagePresenter::present($message);

        return response()->json($payload, $message->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * GET …/messages?after=<id>: visible messages newer than the cursor.
     */
    public function index(Request $request)
    {
        $session = $this->sessionOrNull($request);
        if (!$session) {
            return $this->unauthorized();
        }

        $after = $request->query('after', '0');
        if (!is_string($after) || !ctype_digit($after) || strlen($after) > 18) {
            return $this->invalid('after');
        }

        $messages = Visibility::query($session)
            ->where('wordpresschat_messages.id', '>', (int) $after)
            ->with('thread.created_by_user')
            ->limit(self::MAX_POLL)
            ->get();

        $latestId = Access::where('session_id', $session->id)->max('conversation_id');
        $conversation = null;
        if ($latestId) {
            $conv = Conversation::find($latestId);
            if ($conv) {
                $conversation = [
                    'status' => (int) $conv->status === Conversation::STATUS_CLOSED ? 'closed' : 'open',
                ];
            }
        }

        return response()->json([
            'messages'     => $messages->map(function ($m) {
                return MessagePresenter::present($m);
            })->values()->all(),
            'conversation' => $conversation,
        ]);
    }

    /**
     * POST …/seen {message_ids}: marks visible agent messages as seen, once.
     */
    public function seen(Request $request)
    {
        $session = $this->sessionOrNull($request);
        if (!$session) {
            return $this->unauthorized();
        }

        $ids = $request->input('message_ids');
        if (!is_array($ids) || count($ids) > self::MAX_POLL) {
            return $this->invalid('message_ids');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 0) {
                return $this->invalid('message_ids');
            }
        }

        if ($ids) {
            $visible = Visibility::query($session)
                ->where('wordpresschat_messages.direction', Message::DIRECTION_OUT)
                ->whereNull('wordpresschat_messages.seen_at')
                ->whereIn('wordpresschat_messages.id', $ids)
                ->pluck('wordpresschat_messages.id')
                ->all();

            if ($visible) {
                Message::whereIn('id', $visible)->whereNull('seen_at')->update(['seen_at' => now()]);
            }
        }

        return response('', 204);
    }

    private function invalid($field)
    {
        return response()->json(['error' => 'validation', 'field' => $field], 422);
    }

    private function unauthorized()
    {
        return response()->json(['error' => 'unauthorized'], 401);
    }

    /**
     * The session the X-Chat-Token header resolves to, or null.
     */
    private function sessionOrNull(Request $request)
    {
        $site  = $request->attributes->get('wordpresschat.site');
        $token = $request->header('X-Chat-Token');

        if ($token === null || $token === '') {
            return null;
        }

        return VisitorAuth::resolve($site, $token);
    }
}
