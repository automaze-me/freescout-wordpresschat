<?php

namespace Modules\WordPressChat\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\WordPressChat\Services\IdentityPayload;
use Modules\WordPressChat\Services\Origin;

class PingController extends Controller
{
    /**
     * POST …/ping {token?}: the WordPress plugin's "Test connection". The
     * site is known (ResolveSite 404s otherwise). The answer says whether
     * the secret opens the token and whether the website's address matches
     * the one stored here:
     *
     * {ok: true, secret: ok|invalid|missing, origin: ok|mismatch|null}
     *
     * and, for a secret that is ok but an address that is not,
     * expected_origin. Nothing is revealed about a wrong secret.
     */
    public function ping(Request $request)
    {
        $site = $request->attributes->get('wordpresschat.site');
        $token = $request->input('token');

        if ($token === null || $token === '') {
            return response()->json(['ok' => true, 'secret' => 'missing', 'origin' => null]);
        }

        $payload = IdentityPayload::ping($site, $token);

        if ($payload === null) {
            return response()->json(['ok' => true, 'secret' => 'invalid', 'origin' => null]);
        }

        if (Origin::normalize($payload['origin']) === $site->origin) {
            return response()->json(['ok' => true, 'secret' => 'ok', 'origin' => 'ok']);
        }

        return response()->json([
            'ok'              => true,
            'secret'          => 'ok',
            'origin'          => 'mismatch',
            'expected_origin' => $site->origin,
        ]);
    }
}
