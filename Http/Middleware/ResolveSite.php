<?php

namespace Modules\WordPressChat\Http\Middleware;

use Closure;
use Modules\WordPressChat\Entities\Site;

class ResolveSite
{
    public function handle($request, Closure $next)
    {
        $route = $request->route();
        $publicId = $route ? $route->parameter('site') : null;

        $site = $publicId ? Site::where('public_id', $publicId)->first() : null;

        if (!$site) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $request->attributes->set('wordpresschat.site', $site);

        return $next($request);
    }
}
