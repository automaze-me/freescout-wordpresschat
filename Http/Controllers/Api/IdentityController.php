<?php

namespace Modules\WordPressChat\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\WordPressChat\Entities\Session;
use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Exceptions\AlreadyIdentifiedException;
use Modules\WordPressChat\Services\ContinueLink;
use Modules\WordPressChat\Services\IdentityPayload;
use Modules\WordPressChat\Services\Visibility;
use Modules\WordPressChat\Services\VisitorAuth;
use Modules\WordPressChat\Services\VisitorIdentity;

class IdentityController extends Controller
{
    const FIELDS = ['email', 'order_token', 'login_token'];

    /**
     * POST …/identity with exactly one of `email`, `order_token`,
     * `login_token` (a key counts as given even when its value is empty).
     * Validation is by hand (see ChatController::send()).
     *
     * With X-Chat-Token every field works and the answer is 200
     * {identity, conversation}. Without it only a lone `login_token` does,
     * together with `new_token`: account discovery on a new device
     * (Ruling P6).
     *
     * Errors: 401 unknown or missing chat token, 403 identity_rejected for
     * every token failure, 409 already_identified, 422 validation.
     */
    public function identify(Request $request)
    {
        $site  = $request->attributes->get('wordpresschat.site');
        $token = $request->header('X-Chat-Token');

        $given = array_values(array_filter(self::FIELDS, function ($key) use ($request) {
            return $request->has($key);
        }));

        if ($token === null || $token === '') {
            if ($given === ['login_token']) {
                return $this->discover($request, $site);
            }

            return $this->unauthorized();
        }

        $session = VisitorAuth::resolve($site, $token);
        if (!$session) {
            return $this->unauthorized();
        }

        if (count($given) !== 1) {
            return $this->invalid('identity');
        }

        switch ($given[0]) {
            case 'order_token':
                return $this->order($request, $site, $session);
            case 'login_token':
                return $this->login($request, $site, $session);
            default:
                return $this->typedEmail($request, $session);
        }
    }

    /**
     * POST …/logout: the visitor logged out of WordPress (Ruling P5). The
     * session forgets the account; the widget then forgets the session.
     */
    public function logout(Request $request)
    {
        $site  = $request->attributes->get('wordpresschat.site');
        $token = $request->header('X-Chat-Token');

        $session = ($token !== null && $token !== '') ? VisitorAuth::resolve($site, $token) : null;
        if (!$session) {
            return $this->unauthorized();
        }

        VisitorIdentity::logout($session);

        return response('', 204);
    }

    /**
     * POST …/continue {link}: the visitor clicked "Continue chat" for the
     * continue-link token `link` (spec §6.2). With X-Chat-Token that
     * browser is verified; without it the body carries `new_token`, as on a
     * first send, and the session is created for it and echoed back. There
     * is no GET route: a link scanner prefetching URLs can never redeem.
     *
     * 200 {token?, identity, conversation}; 410 link_invalid for every
     * unknown, used, expired or other-site link, checked before a session
     * is created; 401 unknown chat token; 422 validation (`link`,
     * `new_token`); 409 conflict when `new_token` is taken on another site.
     */
    public function continueChat(Request $request)
    {
        $site  = $request->attributes->get('wordpresschat.site');
        $token = $request->header('X-Chat-Token');

        $session = null;
        if ($token !== null && $token !== '') {
            $session = VisitorAuth::resolve($site, $token);

            if (!$session) {
                return $this->unauthorized();
            }
        }

        $link = $request->input('link');

        if (!is_string($link) || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $link)) {
            return $this->invalid('link');
        }

        $newToken = null;
        if (!$session) {
            $newToken = $request->input('new_token');

            if (!is_string($newToken) || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $newToken)) {
                return $this->invalid('new_token');
            }
        }

        if (!ContinueLink::find($site, $link)) {
            // A retry after a lost answer: the link is used, by this very
            // browser. Answer as the first redemption did, write nothing.
            // A new browser is recognised by its new_token (read only: no
            // session is created for a token the server has never seen).
            $retrying = $session;
            if (!$retrying && $newToken !== null) {
                $retrying = Session::where('token_hash', hash('sha256', $newToken))
                    ->where('site_id', $site->id)->first();
            }

            if ($retrying && ContinueLink::usedBy($site, $link, $retrying)) {
                $payload = $newToken !== null ? ['token' => $newToken] : [];

                return response()->json($payload + Visibility::status($retrying->fresh() ?: $retrying));
            }

            return $this->linkInvalid();
        }

        // Outside any transaction (Ruling R4); redeem() opens its own.
        if (!$session) {
            $session = VisitorAuth::claim($site, $newToken);

            if (!$session) {
                return response()->json(['error' => 'conflict'], 409);
            }
        }

        if (!ContinueLink::redeem($site, $session, $link)) {
            return $this->linkInvalid();
        }

        $payload = $newToken !== null ? ['token' => $newToken] : [];

        return response()->json($payload + Visibility::status($session->fresh() ?: $session));
    }

    private function typedEmail(Request $request, Session $session)
    {
        $raw = $request->input('email');
        $email = is_string($raw) ? IdentityPayload::cleanEmail(trim($raw)) : null;

        if ($email === null) {
            return $this->invalid('email');
        }

        try {
            VisitorIdentity::typedEmail($session, $email);
        } catch (AlreadyIdentifiedException $e) {
            return $this->alreadyIdentified();
        }

        return $this->status($session);
    }

    private function order(Request $request, Site $site, Session $session)
    {
        // Opened before the identity transaction (Ruling R4).
        $payload = IdentityPayload::order($site, $request->input('order_token'), (string) $session->token_hash);
        if ($payload === null) {
            return $this->rejected();
        }

        try {
            VisitorIdentity::order($session, $payload);
        } catch (AlreadyIdentifiedException $e) {
            return $this->alreadyIdentified();
        }

        return $this->status($session);
    }

    private function login(Request $request, Site $site, Session $session)
    {
        $payload = IdentityPayload::login($site, $request->input('login_token'), (string) $session->token_hash);
        if ($payload === null) {
            return $this->rejected();
        }

        VisitorIdentity::login($session, $payload);

        return $this->status($session);
    }

    /**
     * A logged-in visitor on a device without a chat token. The login token
     * is bound to `new_token`, the token the widget generated. A session is
     * created only when the account has chats on this site; otherwise
     * nothing is stored and the answer is {"linked": false} (spec §10).
     */
    private function discover(Request $request, Site $site)
    {
        $newToken = $request->input('new_token');

        if (!is_string($newToken) || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $newToken)) {
            return $this->invalid('new_token');
        }

        $payload = IdentityPayload::login($site, $request->input('login_token'), hash('sha256', $newToken));
        if ($payload === null) {
            return $this->rejected();
        }

        if (!VisitorIdentity::hasAccountChats((int) $site->id, $payload['uid'])) {
            return response()->json(['linked' => false, 'limits' => ChatController::limits($site)]);
        }

        $session = VisitorAuth::claim($site, $newToken);
        if (!$session) {
            return response()->json(['error' => 'conflict'], 409);
        }

        VisitorIdentity::login($session, $payload);

        return response()->json(['token' => $newToken] + Visibility::status($session->fresh() ?: $session));
    }

    private function status(Session $session)
    {
        return response()->json(Visibility::status($session->fresh() ?: $session));
    }

    private function invalid($field)
    {
        return response()->json(['error' => 'validation', 'field' => $field], 422);
    }

    private function unauthorized()
    {
        return response()->json(['error' => 'unauthorized'], 401);
    }

    private function rejected()
    {
        return response()->json(['error' => 'identity_rejected'], 403);
    }

    private function alreadyIdentified()
    {
        return response()->json(['error' => 'already_identified'], 409);
    }

    /** One answer for unknown, used, expired and other-site links. */
    private function linkInvalid()
    {
        return response()->json(['error' => 'link_invalid'], 410);
    }
}
