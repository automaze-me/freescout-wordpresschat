<?php

namespace Modules\WordPressChat\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
     * Upper bound for ids the client sends (`after`, `message_ids`): the
     * columns are 4-byte integers, and PostgreSQL rejects a larger bound
     * parameter with an error (22003) where MySQL simply matches nothing.
     */
    const MAX_ID = 2147483647;

    /**
     * POST …/messages {text, client_message_id, new_token}. Without an
     * X-Chat-Token header the body must carry `new_token`, a token the widget
     * generated for its first message: the session is created for it (or
     * found again, when this is a retry of a first message whose response
     * was lost) and the token is echoed back. With the header, new_token is
     * ignored.
     *
     * Validation is done by hand: this route group is not in the `web`
     * group and the widget may not send Accept: application/json, so a
     * ValidationException would render as a redirect. Every answer is JSON.
     *
     * The answer is {token?, message, identity, conversation}; the last two
     * have the poll's shape (Ruling C6), so the widget knows the
     * conversation key right after its first message.
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

        if (is_string($text) && !mb_check_encoding($text, 'UTF-8')) {
            return $this->invalid('text');
        }

        $text = is_string($text) ? trim(self::stripControlChars($text)) : '';
        $length = mb_strlen($text, 'UTF-8');

        if ($length < 1 || $length > self::MAX_TEXT_LENGTH) {
            return $this->invalid('text');
        }

        $clientMessageId = $request->input('client_message_id');

        if (!is_string($clientMessageId) || !preg_match('/\A[A-Za-z0-9-]{8,64}\z/', $clientMessageId)) {
            return $this->invalid('client_message_id');
        }

        // Validated last of the fields: nothing is written before every
        // field has passed.
        $newToken = null;
        if (!$session) {
            $newToken = $request->input('new_token');

            if (!is_string($newToken) || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $newToken)) {
                return $this->invalid('new_token');
            }

            $session = VisitorAuth::claim($site, $newToken);

            if (!$session) {
                return response()->json(['error' => 'conflict'], 409);
            }
        }

        try {
            $message = InboundMessage::receive($site, $session, $text, $clientMessageId);
        } catch (MessageConflictException $e) {
            return response()->json(['error' => 'conflict'], 409);
        }

        $payload = [];
        if ($newToken !== null) {
            $payload['token'] = $newToken;
        }
        $payload['message'] = MessagePresenter::present($message);
        $payload += Visibility::status($session->fresh() ?: $session);

        return response()->json($payload, $message->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * GET …/messages?after=<id>: visible messages newer than the cursor (a
     * best-effort cursor, see Visibility), plus the identity and
     * conversation summary from Visibility::status().
     */
    public function index(Request $request)
    {
        $session = $this->sessionOrNull($request);
        if (!$session) {
            return $this->unauthorized();
        }

        $after = $request->query('after', '0');
        if (!is_string($after) || !ctype_digit($after) || strlen($after) > 18 || (int) $after > self::MAX_ID) {
            return $this->invalid('after');
        }

        $messages = Visibility::query($session)
            ->where('wordpresschat_messages.id', '>', (int) $after)
            ->with('thread.created_by_user')
            ->limit(self::MAX_POLL)
            ->get();

        $status = Visibility::status($session);

        return response()->json([
            'messages'     => $messages->map(function ($m) {
                return MessagePresenter::present($m);
            })->values()->all(),
            'identity'     => $status['identity'],
            'conversation' => $status['conversation'],
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
            if (!is_int($id) || $id < 0 || $id > self::MAX_ID) {
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

    /**
     * Removes C0 control characters other than tab, line feed and carriage
     * return, and DEL. PostgreSQL text columns reject a NUL byte (22021).
     * The bytes 0x00-0x1F and 0x7F never occur inside a multi-byte UTF-8
     * sequence, so a byte-wise replace is safe.
     */
    private static function stripControlChars(string $text): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
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
