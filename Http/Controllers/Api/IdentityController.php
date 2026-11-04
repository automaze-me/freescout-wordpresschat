<?php

namespace Modules\WordPressChat\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\WordPressChat\Exceptions\AlreadyIdentifiedException;
use Modules\WordPressChat\Services\Visibility;
use Modules\WordPressChat\Services\VisitorAuth;
use Modules\WordPressChat\Services\VisitorIdentity;

class IdentityController extends Controller
{
    const MAX_EMAIL_LENGTH = 191;

    /**
     * POST …/identity with exactly one of `email`, `order_token`,
     * `login_token`. Validation is by hand (see ChatController::send()).
     * Until Task 6 only `email` is handled; a lone token answers 422
     * `identity`.
     */
    public function identify(Request $request)
    {
        $site  = $request->attributes->get('wordpresschat.site');
        $token = $request->header('X-Chat-Token');

        $session = ($token !== null && $token !== '') ? VisitorAuth::resolve($site, $token) : null;
        if (!$session) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $given = 0;
        foreach (['email', 'order_token', 'login_token'] as $key) {
            if ($request->input($key) !== null) {
                $given++;
            }
        }

        if ($given !== 1 || $request->input('email') === null) {
            return $this->invalid('identity');
        }

        $raw = $request->input('email');
        $email = null;
        if (is_string($raw) && mb_check_encoding($raw, 'UTF-8') && mb_strlen(trim($raw), 'UTF-8') <= self::MAX_EMAIL_LENGTH) {
            $email = \App\Email::sanitizeEmail(trim($raw));
        }
        if (!$email || mb_strlen($email, 'UTF-8') > self::MAX_EMAIL_LENGTH) {
            return $this->invalid('email');
        }

        try {
            VisitorIdentity::typedEmail($session, $email);
        } catch (AlreadyIdentifiedException $e) {
            return response()->json(['error' => 'already_identified'], 409);
        }

        return response()->json(Visibility::status($session->fresh() ?: $session));
    }

    private function invalid($field)
    {
        return response()->json(['error' => 'validation', 'field' => $field], 422);
    }
}
