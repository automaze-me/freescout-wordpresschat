<?php

namespace Modules\WordPressChat\Http\Middleware;

use Closure;
use Modules\WordPressChat\Services\Origin;

class Cors
{
    public function handle($request, Closure $next)
    {
        $header = $request->headers->get('Origin');

        // No Origin header: server-to-server call, no CORS involved.
        if ($header === null || $header === '') {
            return $next($request);
        }

        $site = $request->attributes->get('wordpresschat.site');
        $origin = Origin::normalize($header);

        if (!$site || $origin === null || $origin !== $site->origin) {
            return response()->json(['error' => 'origin'], 403);
        }

        $response = $request->isMethod('OPTIONS')
            ? response('', 204)
            : $next($request);

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Chat-Token, X-Chat-Page');
        $response->headers->set('Access-Control-Max-Age', '600');

        return $response;
    }
}
