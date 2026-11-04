<?php

namespace Modules\WordPressChat\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\WordPressChat\Services\VisitorAuth;

class ChatController extends Controller
{
    public function send(Request $request)
    {
        return response()->json(['error' => 'not_implemented'], 501);
    }

    public function index(Request $request)
    {
        return $this->authorized($request) ?: response()->json(['error' => 'not_implemented'], 501);
    }

    public function seen(Request $request)
    {
        return $this->authorized($request) ?: response()->json(['error' => 'not_implemented'], 501);
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
