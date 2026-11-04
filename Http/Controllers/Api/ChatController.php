<?php

namespace Modules\WordPressChat\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\WordPressChat\Exceptions\MessageConflictException;
use Modules\WordPressChat\Services\InboundMessage;
use Modules\WordPressChat\Services\MessagePresenter;
use Modules\WordPressChat\Services\VisitorAuth;

class ChatController extends Controller
{
    const MAX_TEXT_LENGTH = 5000;

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

    public function index(Request $request)
    {
        return $this->authorized($request) ?: response()->json(['error' => 'not_implemented'], 501);
    }

    public function seen(Request $request)
    {
        return $this->authorized($request) ?: response()->json(['error' => 'not_implemented'], 501);
    }

    private function invalid($field)
    {
        return response()->json(['error' => 'validation', 'field' => $field], 422);
    }

    /**
     * Returns a 401 response when the visitor token does not resolve, null otherwise.
     */
    private function authorized(Request $request)
    {
        $site = $request->attributes->get('wordpresschat.site');

        if (!VisitorAuth::resolve($site, $request->header('X-Chat-Token'))) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return null;
    }
}
